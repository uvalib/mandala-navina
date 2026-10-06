<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\mandala_kaltura\Form\KalturaSecretsForm;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Enum\SessionType;
use Psr\Log\LoggerInterface;

/**
 * Mints short-lived, upload-scoped Kaltura Sessions (AV11).
 *
 * This is the only place in the codebase the partner admin secret is ever
 * read. The secret itself never leaves this service -- callers (AV12's
 * upload-session controller) only ever see the minted KS string, never the
 * secret used to produce it. Secrets come from the State API (see
 * KalturaSecretsForm), not settings.php/env vars -- a deliberate deviation
 * from this project's usual deploy-time-secret convention (Sprint 3), made
 * so the value can be entered/rotated through an admin form without ever
 * being exported into the public config/sync tree.
 *
 * Scoping a KS to "upload only" has two real controls, in order of
 * strength: SESSION_TYPE_USER (never ADMIN -- a USER-type session cannot
 * perform partner-administration actions) and a short expiry. The Kaltura
 * API's `privileges` string does not support restricting a session to a
 * specific action set (e.g. "uploadToken.add only") -- it controls things
 * like entitlement checks and IP restriction, not a capability allowlist --
 * so it is not a substitute for the two controls above. Verify this
 * against a real Kaltura sandbox account before relying on it in
 * production (tracked as an open verification item, not assumed here).
 */
class KalturaSessionService {

  /**
   * How long a minted upload session stays valid, in seconds.
   *
   * Long enough for a large chunked upload to complete, bounded so a
   * leaked KS has a small blast radius.
   */
  protected const SESSION_TTL_SECONDS = 3600;

  /**
   * How long a delete-scoped admin session stays valid, in seconds.
   *
   * One server-side API call, immediately discarded -- short on purpose,
   * unlike the browser-facing upload session above.
   */
  protected const DELETE_SESSION_TTL_SECONDS = 60;

  public function __construct(
    protected readonly StateInterface $state,
    protected readonly KalturaConfigResolver $resolver,
    protected readonly LoggerInterface $logger,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Mints a new upload-scoped Kaltura Session.
   *
   * @return array{ks: string, partner_id: string, server_url: string, expires: int}|null
   *   The minted session, or NULL if secrets aren't configured or minting
   *   failed -- callers must treat NULL as "upload unavailable right now"
   *   and never fall back to any other credential.
   */
  public function mintUploadSession(): ?array {
    $adminSecret = $this->state->get(KalturaSecretsForm::STATE_ADMIN_SECRET);
    if (empty($adminSecret)) {
      $this->logger->error('Kaltura upload session requested but no admin secret is configured. Set one at /admin/config/media/mandala-kaltura/secrets.');
      return NULL;
    }

    $resolved = $this->resolver->resolve('default');
    if ($resolved === NULL) {
      $this->logger->error('Kaltura upload session requested but the "default" preset is not configured.');
      return NULL;
    }

    // mandala_kaltura.settings stores server_url protocol-relative
    // ("//www.kaltura.com", matching D7's stored value and every other
    // caller of this preset e.g. KalturaConfiguredFormatter) -- fine for a
    // browser context, but the PHP SDK's own cURL client needs a scheme.
    $serverUrl = (string) $resolved['server_url'];
    if (str_starts_with($serverUrl, '//')) {
      $serverUrl = 'https:' . $serverUrl;
    }

    $config = new KalturaConfiguration();
    $config->setServiceUrl($serverUrl);

    $client = new KalturaClient($config);
    $client->setPartnerId((int) $resolved['partner_id']);

    try {
      $ks = $client->getSessionService()->start(
        $adminSecret,
        '',
        SessionType::USER,
        (int) $resolved['partner_id'],
        self::SESSION_TTL_SECONDS,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Kaltura session minting failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    return [
      'ks' => $ks,
      'partner_id' => (string) $resolved['partner_id'],
      // Deliberately the original protocol-relative form, not the
      // https-normalized $serverUrl above -- the browser resolves that
      // against its own current protocol (see kaltura-upload.js's
      // normalizeServiceUrl()), same as every other consumer of this
      // preset value.
      'server_url' => (string) $resolved['server_url'],
      'expires' => $this->time->getRequestTime() + self::SESSION_TTL_SECONDS,
    ];
  }

  /**
   * Deletes a Kaltura media entry.
   *
   * Server-side only -- unlike {@see self::mintUploadSession()}'s KS, this
   * method's session string is never sent to the browser, so the
   * browser-exposure risk that motivates that method's SESSION_TYPE_USER
   * restriction does not apply here. `media.delete` is a partner-admin-type
   * action (it can delete any entry under the partner, not just one the
   * caller uploaded), so this mints a SESSION_TYPE_ADMIN session instead,
   * scoped by the shortest practical TTL (one call, then discarded).
   *
   * **Open item, flagged not assumed**: whether ADMIN is actually required
   * here (vs. USER with sufficient entitlement) has not been verified
   * against a real Kaltura sandbox account -- confirm before relying on
   * this in production, same caveat this class's own docblock already
   * carries for upload sessions.
   *
   * @param string $entryId
   *   The Kaltura entry id to delete (a node's `field_audio`/`field_video`
   *   `entry_id` property).
   *
   * @return bool
   *   TRUE if the delete call succeeded, FALSE if secrets/config are
   *   missing or the API call failed -- callers must not block the
   *   Drupal-side node deletion on a FALSE return; log and move on.
   */
  public function deleteEntry(string $entryId): bool {
    $adminSecret = $this->state->get(KalturaSecretsForm::STATE_ADMIN_SECRET);
    if (empty($adminSecret)) {
      $this->logger->error('Kaltura entry delete requested (entry @entry_id) but no admin secret is configured. Set one at /admin/config/media/mandala-kaltura/secrets.', ['@entry_id' => $entryId]);
      return FALSE;
    }

    $resolved = $this->resolver->resolve('default');
    if ($resolved === NULL) {
      $this->logger->error('Kaltura entry delete requested (entry @entry_id) but the "default" preset is not configured.', ['@entry_id' => $entryId]);
      return FALSE;
    }

    $serverUrl = (string) $resolved['server_url'];
    if (str_starts_with($serverUrl, '//')) {
      $serverUrl = 'https:' . $serverUrl;
    }

    $config = new KalturaConfiguration();
    $config->setServiceUrl($serverUrl);

    $client = new KalturaClient($config);
    $client->setPartnerId((int) $resolved['partner_id']);

    try {
      $ks = $client->getSessionService()->start(
        $adminSecret,
        '',
        SessionType::ADMIN,
        (int) $resolved['partner_id'],
        self::DELETE_SESSION_TTL_SECONDS,
      );
      $client->setKs($ks);
      $client->getMediaService()->delete($entryId);
    }
    catch (\Throwable $e) {
      $this->logger->error('Kaltura entry delete failed (entry @entry_id): @message', [
        '@entry_id' => $entryId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $this->logger->info('Deleted Kaltura entry @entry_id.', ['@entry_id' => $entryId]);
    return TRUE;
  }

}
