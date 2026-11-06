<?php
$n=\Drupal\node\Entity\Node::load(123746);
echo "root status=",$n->isPublished()," bundle=",$n->bundle(),"\n";
foreach (['s3_editor','s3_member','s3_other'] as $u){ $a=user_load_by_name($u); echo $u," roles=",implode(',',$a->getRoles())," node.view=",var_export($n->access('view',$a),true)," access content=",var_export($a->hasPermission('access content'),true)," bypass=",var_export(\Drupal\mandala_group_inheritance\Access\CollectionVisibility::hasNodeBypass($a),true),"\n"; }
echo "anon access content=", var_export(\Drupal\user\Entity\User::getAnonymousUser()->hasPermission('access content'),true), " view=", var_export($n->access('view', \Drupal\user\Entity\User::getAnonymousUser()),true),"\n";
echo "group visibility=",\Drupal\mandala_group_inheritance\Access\CollectionVisibility::owningGroup($n)?->id(),"\n";
