<?php
require_once 'check.php';
require_once '/usr/local/etc/settings/creds.php';
require_once '/usr/local/etc/settings/paths.php';
$me="https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
print "<ul>\n";
print "<li><a href=\"/auth?returl=".$me."\">authenticate</a></li>\n";
foreach ($SOLR_URLS as $p => $url) {
	$path = $p . "/select";
	print "<li><a href=\"".$path."?echoParams=explicit&q=*:*\">".$path."</a></li>\n";
}
print"</ul>\n";

phpinfo();
?>
