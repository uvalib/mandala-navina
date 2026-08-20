<?php

namespace Drupal\mandala_saml_oauth;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\mandala_saml_oauth\EventSubscriber\OauthAwareSimplesamlSubscriber;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps simplesamlphp_auth's request subscriber for one that skips OAuth2.
 *
 * @see \Drupal\mandala_saml_oauth\EventSubscriber\OauthAwareSimplesamlSubscriber
 */
class MandalaSamlOauthServiceProvider extends ServiceProviderBase {

  /**
   * The simplesamlphp_auth subscriber that forces the logout.
   */
  protected const SUBSCRIBER_SERVICE = 'simplesamlphp_auth_event_subscriber';

  /**
   * The simple_oauth request policy that recognises a Bearer request.
   */
  protected const OAUTH_POLICY_SERVICE = 'simple_oauth.page_cache_request_policy.disallow_oauth2_token_requests';

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // Both modules are hard dependencies, but a container can legitimately be
    // built while one of them is being installed or uninstalled. Skipping is
    // always safe: without the swap we simply get stock behaviour.
    if (!$container->hasDefinition(static::SUBSCRIBER_SERVICE) || !$container->hasDefinition(static::OAUTH_POLICY_SERVICE)) {
      return;
    }

    $definition = $container->getDefinition(static::SUBSCRIBER_SERVICE);
    $definition->setClass(OauthAwareSimplesamlSubscriber::class);
    // Injected by setter, not by appending a constructor argument, so that an
    // upstream change to SimplesamlSubscriber::__construct() cannot silently
    // shift our argument onto the wrong parameter.
    $definition->addMethodCall('setOauthRequestPolicy', [new Reference(static::OAUTH_POLICY_SERVICE)]);
  }

}
