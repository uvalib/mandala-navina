<?php

// Require the searcher class and credentials for configs
require_once 'check.php';
require_once 'Searcher.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';

$path= dirname(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),1);
// error_log("search path = " . $path);

$STATUS_URL=null;
if (array_key_exists($path, $STATUS_URLS)) {
    $STATUS_URL=$STATUS_URLS[$path];
}

function getHttpCode($http_response_header) {
   if(is_array($http_response_header)) {
       $parts=explode(' ',$http_response_header[0]);
       if(count($parts)>1) {
           return intval($parts[1]); //Get code
       }
   } else {
       return 400;
   }
}
// handle unknown path with a 404
if ($STATUS_URL) {
        $results = @file_get_contents($STATUS_URL, false, stream_context_create(
            array('ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            ))
        ));
	$retcode=getHttpCode($http_response_header);
	if (!$results) {
            $error = error_get_last();
            $results = $error['message'];
        }
	http_response_code($retcode);
	header("Content-Type: application/json");
	print($results);
}
else {
    http_response_code(404);
    include('404.php'); // provide your own HTML for the error page
    exit();
}
