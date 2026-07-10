<?php

require_once 'check.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';

/**
 * D11 hybrid proxy (ADR 014): visibility is read from a Drupal-written Redis
 * token (`mandala_solr_fq:{uid}`), never computed from a Solr membership
 * query. This eliminates the D7 proxy's circular dependency on kmassets for
 * its own access decisions (ADR 013).
 */
class Searcher {
    public ?int $session_length;
    public ?array $getvars;
    public ?string $sid;
    public ?int $uid;
    public bool $isLoggedIn = false;
    public string $solrurl = '';
    public ?string $wrapper;
    public ?string $results;
    public ?array $params;
    public ?string $visibilityFq = null;
    public bool $echoParams = false;
    public bool $debug = false;
    private ?\Redis $redis = null;

    function __construct($slen = 3600, $dbug = false, $solr_url = null) {
        $this->session_length = $slen;
        $this->debug = $dbug;
        $this->solrurl = $solr_url ?? 'https://fox.shanti.virginia.edu/cloud/solr/kmassets';
        $this->getvars = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
        if (empty($this->getvars)) { $this->getvars = array(); }
        $this->setSession();
    }

    public function setSession() {
        // Get the session id parameter from the GET array and load session if it exists
        if (array_key_exists('sid', $this->getvars)) {
            $sid = htmlspecialchars($this->getvars['sid']);
            unset($this->getvars['sid']);
            session_id($sid);
        }
        if (isset($_COOKIE[session_name()]) AND preg_match('/^[-,a-zA-Z0-9]{1,128}$/', $_COOKIE[session_name()])) {
            session_start();
        } elseif (isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
            session_start();
        } else {
            session_start();
        }
        $this->isLoggedIn = isset($_SESSION['muid']);

        // Timeout session if last ping is longer ago than the session length
        if (isset($_SESSION['last_ping']) && (time() - $_SESSION['last_ping'] > $this->session_length)) {
            $this->endSession();
            session_start();
        }
        $_SESSION['last_ping'] = time(); // update last activity time stamp
        $this->sid = session_id();   // Set the sid property to whatever the sid is (whether new session or not)

        $sess_keys = array_keys($_SESSION);
        $this->uid = (in_array('muid', $sess_keys) && !empty($_SESSION['muid'])) ? (int) $_SESSION['muid'] : null;
        $this->isLoggedIn = !empty($this->uid);
    }

    /**
     * Reads the precomputed visibility `fq` for the current uid from Redis.
     * Drupal owns the full derivation (Group membership -> fq string) and
     * writes it on login / Group membership change / logout (ADR 014). The
     * proxy makes no Solr call and no membership decision of its own.
     *
     * uid=1 (Drupal admin) intentionally has no token written -> null fq,
     * meaning no visibility filter is applied, matching prior behaviour.
     */
    private function getVisibilityToken(): ?string {
        if (!$this->isLoggedIn || $this->uid === 1) {
            return null;
        }
        $redis = $this->getRedis();
        if ($redis === null) {
            // Redis unreachable: fail closed to the anonymous filter rather
            // than granting unfiltered access (ADR 014 consequence).
            error_log("solr-proxy: Redis unavailable, falling back to anonymous visibility for uid {$this->uid}");
            return null;
        }
        $token = $redis->get("mandala_solr_fq:{$this->uid}");
        return $token !== false ? $token : null;
    }

    private function getRedis(): ?\Redis {
        global $REDIS_HOST, $REDIS_PORT;
        if ($this->redis !== null) {
            return $this->redis;
        }
        try {
            $redis = new \Redis();
            $redis->connect($REDIS_HOST, (int) $REDIS_PORT, 1.0); // 1s connect timeout
            $this->redis = $redis;
            return $this->redis;
        } catch (\RedisException $e) {
            error_log('solr-proxy: Redis connection failed: ' . $e->getMessage());
            return null;
        }
    }

    public function setParams($qstr, $filter_on) {
        $qpts = explode("?", $qstr);
        $qpts = (count($qpts) === 2) ? $qpts[1] : $qpts[0];
        $qpts = explode('&', $qpts);
        $this->params = array('fq'=> array());
        foreach($qpts as $qpt) {
            $ppts = explode('=', $qpt);
            if (count($ppts) === 2) {
                list($k, $v) = $ppts;
                if ($k === 'fq') {
                    $this->params['fq'][] = $v;
                } else {
                    $this->params[$k] = $v;
                }
            }
        }
        if ($filter_on) {
            $this->setVisibility();
        }
    }

    private function setVisibility() {
        // Sets visibility filter query for the query. This only works for asset searches.
        // Remove any visibility query facets sent in query string and set based on login status
        foreach($this->params['fq'] as $fqn => $fqv) {
            // Remove any attempt at setting visibility through query params
            if (strstr($fqv, 'visibility')) {
                unset($this->params['fq'][$fqn]);
            }
        }
        if ($this->isLoggedIn && !empty($this->uid) && $this->uid !== 1) {
            // if uid = 1, view everything (no filter) -- matches prior behaviour
            $fq = $this->getVisibilityToken();
            if ($fq !== null) {
                $this->visibilityFq = $fq;
                $this->params['fq'][] = $fq;
                return;
            }
            // No token in Redis (miss, or Redis down) -> fail closed to the
            // anonymous filter below rather than serving unfiltered results.
        }
        // Anonymous users, or a logged-in user with no Redis token yet:
        // visibility must be public (1) or else asset must be a kmap.
        $this->params['fq'][] = "(visibility_i:1%20OR%20asset_type:(places%20subjects%20terms))";
    }

    public function endSession() {
        session_unset();     // unset $_SESSION variable for the run-time
        unset($_SESSION['muid']);  // unset specific variables to be sure
        session_destroy();   // destroy session data in storage
        unset($_COOKIE[session_name()]);
        $this->isLoggedIn = false;
    }


    public function getHttpCode($http_response_header)
    {
        if(is_array($http_response_header))
        {
            $parts=explode(' ',$http_response_header[0]);
            if(count($parts)>1) //HTTP/1.0 <code> <text>
                return intval($parts[1]); //Get code
        }
        return 400;
    }

    public function search(string $qstr, $filter_on=true) {
        $this->setParams($qstr, $filter_on);
        $safe_qtr = $this->getQueryStr();
        $surl = "$this->solrurl/select?$safe_qtr";
        // error_log("Search url: $surl");
        $results = @file_get_contents($surl, false, stream_context_create(
            array('ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            ))
        ));
        if ($results === false) {
            // error_log("Results are false");
            $error = error_get_last();
            $results = array(
                "status" => "error",
                "message" => $error['message'],
                "query" => $safe_qtr,
            );

	    $retcode = $this->getHttpCode($http_response_header);
	    trigger_error("error: HTTP ".$retcode." : ".$error['message'],E_USER_WARNING);
            $results = json_encode($results);
	    http_response_code($retcode);
        }
        $this->results = mb_convert_encoding($results, 'UTF-8',
            mb_detect_encoding($results, 'UTF-8, ISO-8859-1', true));

        return $this->results;
    }

    /**
     * SearchAPI : Returns a JSON or JSONP response to the current $_GET query
     */
    public function searchAPI() {
        $this->search($_SERVER['QUERY_STRING']);
        // if json_wrf is in query string, then it is JSONP, return such
        if (array_key_exists('json_wrf', $this->getvars)) {
            $this->wrapper = $this->getvars['json_wrf'];
            $this->jsonp();
        } else {
            $this->json();
        }
    }

    public function getQueryStr() {
        if (empty($this->params)) { return ''; }
        $qstr = '';
        foreach($this->params as $k => $v) {
            if ($k === 'fq') {
                foreach($v as $fqv) {
                    $qstr .= "&fq=$fqv";
                }
            } else {
                $qstr .= "&$k=$v";
            }
        }
        if($this->echoParams && !strstr($qstr, 'echoParams')) {
            $qstr .= "&echoParams=explicit";
        }
        // See $this->setVisibility() for private node searching when logged in
        return ltrim($qstr, '&');
    }

    public function getReturnUrl() {
        return (empty($this->getvars['returl'])) ? $_SESSION['returl'] : $this->getvars['returl'];;
    }

    public function json() {
        header('Content-Type: application/json; charset=utf-8');
        if ($this->debug) {
            // error_log("results in json: " . $this->results);
            $out = json_decode($this->results, TRUE);
            if (empty($out['status']) || $out['status'] != "error") {
                $out['is_new'] = true;
                $out['is_logged_in'] = $this->isLoggedIn;
                if ($this->isLoggedIn) {
                    $out['uid'] = $this->uid;
                }
                $this->results = json_encode($out);
            }
        }
        echo $this->results;
    }

    public function jsonp() {
        header('Content-Type: application/javascript; charset=utf-8');
        echo "$this->wrapper($this->results)";
    }

}
