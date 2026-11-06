<?php
$ids=\Drupal::database()->query("select nid from book where bid=123746 order by depth,nid limit 6")->fetchCol();
foreach (['s3_editor','s3_member'] as $u){ $a=user_load_by_name($u);
 foreach (\Drupal\node\Entity\Node::loadMultiple($ids) as $n) echo $u," ",$n->id()," st=",$n->isPublished()," view=",var_export($n->access('view',$a),true), " grp=", \Drupal\mandala_group_inheritance\Access\CollectionVisibility::owningGroup($n)?->id(), "\n"; }
$acct=user_load_by_name('s3_editor'); \Drupal::service('account_switcher')->switchTo($acct);
$bm=\Drupal::service('book.manager'); $l=$bm->loadBookLink(123746,FALSE); echo "link depth ",$l['depth']," has_children=",$l['has_children'],"\n";
$t=$bm->bookTreeAllData(123746); echo "tree top=",count($t)," below=",count(reset($t)['below']),"\n";
