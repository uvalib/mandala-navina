<?php

require_once 'check.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';
require_once 'Searcher.php';

$searcher = new Searcher(3600, false);
$searcher->results = json_encode(array('loggedIn' => $searcher->isLoggedIn));
header('Access-Control-Allow-Origin: *');
$searcher->json();
