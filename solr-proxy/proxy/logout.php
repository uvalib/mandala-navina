<?php

require_once 'check.php';
// Require the searcher class
require_once 'Searcher.php';

// Instantiate the Searcher class (Checks and loads session etc.)
$searcher = new Searcher();

$returl = $searcher->getReturnUrl();

// error_log("Logging out session: " . $searcher->sid);
// error_log("Return url is: $returl");

// Performs the actual search and handles the response
$searcher->endSession();

header("Location: $returl?logout=true");
