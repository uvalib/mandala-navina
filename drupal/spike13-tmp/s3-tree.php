<?php
$bm=\Drupal::service('book.manager');
foreach (['s3_editor','s3_member','s3_other',null] as $u){
  $acct = $u ? user_load_by_name($u) : \Drupal\user\Entity\User::getAnonymousUser();
  \Drupal::service('account_switcher')->switchTo($acct);
  try { $t=$bm->bookTreeAllData(123746); $c=0; $f=function($t) use (&$f,&$c){foreach($t as $i){$c++; if($i['below']) $f($i['below']);}}; $f($t); echo ($u??'anon')," tree=$c\n"; }
  catch (\Throwable $e) { echo ($u??'anon')," ERR ", substr($e->getMessage(),0,90),"\n"; }
  \Drupal::service('account_switcher')->switchBack();
}
echo "node_access rows for book: ", \Drupal::database()->query("select count(*) from node_access na join node n on n.nid=na.nid where n.type='book'")->fetchField(), "\n";
echo "realms: ", implode(',', \Drupal::database()->query("select distinct realm from node_access")->fetchCol()), "\n";
