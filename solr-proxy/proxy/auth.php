<?php
/**
 * OAuth2 authorization-code flow against D11's simple_oauth server, using
 * the Oauth2 client library (https://github.com/thephpleague/oauth2-client.git).
 *
 * D11 (ADR 013/014, Spike 10):
 *  - $OAUTH_ROOT in creds.php must point at D11's /oauth/* paths, not the D7
 *    proxy's /oauth2/* paths -- simple_oauth does not use the "2" segment.
 *  - simple_oauth's resource-owner payload still returns `sub` as the
 *    integer Drupal uid (Spike 10, proven live: sub:"2"), so the "muid"
 *    handling below is unchanged from the D7 proxy.
 *  - This script does NOT read or write any visibility/collection state.
 *    The Redis visibility token (`mandala_solr_fq:{uid}`) is written by
 *    Drupal itself on login/logout/Group-membership-change -- see the D11
 *    event-hook module (1b.1 part 3), not this proxy.
 **/

require_once 'check.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';
require_once 'Searcher.php';

function safeSession() {
    if (isset($_COOKIE[session_name()]) AND preg_match('/^[-,a-zA-Z0-9]{1,128}$/', $_COOKIE[session_name()])) {
        session_start();
    } elseif (isset($_COOKIE[session_name()])) {
        unset($_COOKIE[session_name()]);
        session_start();
    } else {
        session_start();
    }
}

$myget = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);

// Set up session
safeSession();

$currsid = session_id();
// error_log("Session ID in auth.php: $currsid");

$provider = new \League\OAuth2\Client\Provider\GenericProvider($CREDS);

// If we don't have an authorization code then get one using urlAuthorize from credentials
if (!isset($myget['code'])) {

    // Fetch the authorization URL from the provider; this returns the
    // urlAuthorize option and generates and applies any necessary parameters
    // (e.g. state).
    $authorizationUrl = $provider->getAuthorizationUrl();
    if (isset($myget['logintype'])) {
        $authorizationUrl .= '&logintype=' . $myget['logintype'];
    }

    // Get the state generated for you and store it to the session.
    $_SESSION['oauth2state'] = $provider->getState();
    $_SESSION['returl'] = empty($_GET['returl']) ? $DEFAULT_RETURL : htmlspecialchars($_GET['returl']);
    $_SESSION['userip'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['last_ping'] = time();

    // Redirect the user to the authorization URL.
    header('Location: ' . $authorizationUrl);
    exit;

// Check given state against previously stored one to mitigate CSRF attack
} elseif (empty($myget['state']) || (isset($_SESSION['oauth2state']) && $myget['state'] !== $_SESSION['oauth2state'])) {

    if (isset($_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
    }

    exit('Invalid state');

} else {
    // If there is a code parameter and the state parameter matches the session state, then exchange code for token
    // Using the token URL provided in the credentials when instantiating the provider.
    try {
        $scopes = $provider->getDefaultScopes(); // Only scope that works is default = "openid"
        // error_log("default scopes: " . json_encode($scopes));
        // Try to get an access token using the authorization code grant.
	// error_log("trying to get access token with code=".$myget['code']);
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $myget['code'],
            'scope' => 'openid',
        ]);
	// error_log("got accessToken=".$accessToken);

        // simple_oauth returns a resource-owner payload with `sub` = the
        // integer Drupal uid (proven in Spike 10; same contract as D7).
        $resourceOwner = $provider->getResourceOwner($accessToken);
        $info = $resourceOwner->toArray();
        // error_log('Login to Search Proxy. MUID: ' . $info['sub']);
        // error_log('Login to Search Proxy: ' . json_encode($info));
        $_SESSION['access_token'] = $accessToken->getToken();
        $_SESSION['muid'] = $info['sub'];
        $returl = empty($_SESSION['returl']) ? $DEFAULT_RETURL : $_SESSION['returl'];
        $_SESSION['last_ping'] = time();
        $sid = session_id();
        // error_log("Sid: " . $sid . ", returl: " . $returl);
        // error_log('Location: ' . $returl . '?sid='. $sid . '&uid=' . $_SESSION['muid']);
        header('Location: ' . $returl . '?sid='. $sid . '&uid=' . $_SESSION['muid']);

    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {

        // Failed to get the access token or user details.
        exit('Didn\'t work'); // $e->getMessage()

    } catch (UnexpectedValueException $uve) {
        echo "Unexpected Value ";
        echo $uve->getMessage();
        echo json_encode($_GET, JSON_PRETTY_PRINT);
    }

}
