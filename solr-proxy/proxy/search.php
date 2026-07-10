<?php

// Require the searcher class and credentials for configs
require_once 'check.php';
require_once 'Searcher.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';

$path= dirname(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),1);
// error_log("search path = " . $path);
$SOLR_URL=null;
if (array_key_exists($path,$SOLR_URLS)) {
    $SOLR_URL=$SOLR_URLS[$path];
}

// error_log("path =". $path ."   STATUS_URL=".$STATUS_URL);

// handle unknown path with a 404
if ($SOLR_URL) {
    // Instantiate the Searcher class (Checks and loads session etc.)
    $searcher = new Searcher(3600, false, $SOLR_URL);

    // Performs the actual search and handles the response
    $searcher->searchAPI();
}
else {
    http_response_code(404);
    include('404.php'); // provide your own HTML for the error page
    exit();
}
