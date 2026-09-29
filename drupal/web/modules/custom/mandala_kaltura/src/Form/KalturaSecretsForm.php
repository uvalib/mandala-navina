<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for the Kaltura partner secrets AV11 uses to mint upload sessions.
 *
 * Deliberately backed by the State API, not the Config API: State is
 * excluded from config:export, so a value saved here never lands in
 * config/sync -- and this repo is public (see CLAUDE.md). Values are
 * write-only in this form (never re-rendered), matching how a password
 * field behaves -- leaving a field blank on submit keeps the existing
 * stored value rather than clearing it, so admins don't have to re-enter
 * both secrets every time they rotate one.
 */
class KalturaSecretsForm extends FormBase {

  const STATE_ADMIN_SECRET = 'mandala_kaltura.admin_secret';
  const STATE_SECRET = 'mandala_kaltura.secret';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mandala_kaltura_secrets';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('These credentials are used server-side only, to mint short-lived Kaltura upload sessions (AV11). They are never exposed to the browser and never appear in exported configuration. Leave a field blank to keep its current stored value unchanged.') . '</p>',
    ];

    $form['admin_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Kaltura partner admin secret'),
      '#description' => $this->currentlySetDescription(self::STATE_ADMIN_SECRET),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    $form['secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Kaltura partner secret'),
      '#description' => $this->currentlySetDescription(self::STATE_SECRET),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * A "currently set"/"not set" status description for a stored secret.
   */
  protected function currentlySetDescription(string $stateKey): \Stringable|string {
    return $this->state->get($stateKey) !== NULL
      ? $this->t('Currently set. Enter a new value to replace it.')
      : $this->t('Not currently set.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $adminSecret = (string) $form_state->getValue('admin_secret');
    if ($adminSecret !== '') {
      $this->state->set(self::STATE_ADMIN_SECRET, $adminSecret);
    }

    $secret = (string) $form_state->getValue('secret');
    if ($secret !== '') {
      $this->state->set(self::STATE_SECRET, $secret);
    }

    $this->messenger()->addStatus($this->t('Kaltura secrets saved.'));
  }

}
