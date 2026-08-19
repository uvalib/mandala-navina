<?php

namespace mandala\oauth;

use League\OAuth2\Client\Provider\GenericProvider;

/**
 * league/oauth2-client's AbstractProvider::getAuthorizationHeaders() has no
 * default implementation -- it returns [] unless a provider subclass
 * overrides it (Google/GitHub/etc. do; GenericProvider does not). Every
 * authenticated request built via getAuthenticatedRequest() -- notably
 * getResourceOwner()'s call to /oauth/userinfo -- was therefore sent with no
 * Authorization header at all, so Drupal's simple_oauth treated it as
 * anonymous and redirected to /user/login instead of returning JSON.
 * See docs/deferred/solr-proxy-genericprovider-no-bearer-header-on-userinfo.md.
 */
class BearerGenericProvider extends GenericProvider
{
    protected function getAuthorizationHeaders($token = null)
    {
        return $token ? ['Authorization' => 'Bearer ' . (string) $token] : [];
    }
}
