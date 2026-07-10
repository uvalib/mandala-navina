<?php
$fail=false;
$msg=[];
if ( getenv("SOLR_BASEURL") == false ) {
    error_log("Environment variable SOLR_BASEURL must be set!");
    $fail=true;
    $msg[]="Environment variable SOLR_BASEURL must be set!";   
}
if ( getenv("DEFAULT_RETURL") == false ) {
    error_log("Environment variable DEFAULT_RETURL must be set!");
    $fail=true;
    $msg[]="Environment variable DEFAULT_RETURL must be set!";
}
if ($fail) {
	throw new Exception (join(" ",$msg));
}
