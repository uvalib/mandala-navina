<?php

namespace Drupal\mandala_saml_oauth\EventSubscriber;

use Drupal\simple_oauth\PageCache\SimpleOauthRequestPolicyInterface;
use Drupal\simplesamlphp_auth\EventSubscriber\SimplesamlSubscriber;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Keeps simplesamlphp_auth's SAML-liveness check off OAuth2 Bearer requests.
 *
 * SimplesamlSubscriber::checkAuthStatus() runs on every request where the
 * current account is not anonymous. If SimpleSAMLphp's own session store holds
 * no live session for that browser it calls user_logout() and redirects to '/',
 * exempting only uid 1 and the administrator role via allow.default_login.
 *
 * A simple_oauth Bearer request is stateless by design and never carries a
 * SimpleSAMLphp session, so it trips that check on every hop: the proxy resends
 * the token, authenticates, is logged out again, and the exchange dies with a
 * TooManyRedirectsException. Because the exemption upstream offers is keyed by
 * *who the user is* rather than *how the request authenticated*, no combination
 * of allow.default_login_users / allow.default_login_roles can express this
 * without also exempting that same person's real browser sessions — which is
 * what the check exists to catch. Hence the override.
 *
 * Detection uses simple_oauth's own request policy, the same service its
 * authentication provider consults in ::applies(), rather than testing the
 * resolved account against TokenAuthUserInterface: that interface is marked
 * @internal, and the current_user service is an AccountProxy wrapping the real
 * account, so a naive instanceof against it never matches.
 *
 * @see \Drupal\simple_oauth\Authentication\Provider\SimpleOauthAuthenticationProvider::applies()
 * @see docs/deferred/simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md
 */
class OauthAwareSimplesamlSubscriber extends SimplesamlSubscriber {

  /**
   * The simple_oauth request policy, or NULL if it was never injected.
   *
   * @var \Drupal\simple_oauth\PageCache\SimpleOauthRequestPolicyInterface|null
   */
  protected ?SimpleOauthRequestPolicyInterface $oauthRequestPolicy = NULL;

  /**
   * Injects the simple_oauth request policy.
   *
   * @param \Drupal\simple_oauth\PageCache\SimpleOauthRequestPolicyInterface $policy
   *   The policy that recognises a request carrying an OAuth2 Bearer token.
   */
  public function setOauthRequestPolicy(SimpleOauthRequestPolicyInterface $policy): void {
    $this->oauthRequestPolicy = $policy;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAuthStatus(RequestEvent $event) {
    if ($this->oauthRequestPolicy && $this->oauthRequestPolicy->isOauth2Request($event->getRequest())) {
      return;
    }

    parent::checkAuthStatus($event);
  }

}
