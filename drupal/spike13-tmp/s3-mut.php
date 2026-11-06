<?php
// usage: drush php:script s3-mut.php -- <op> [args]
use Drupal\node\Entity\Node;
$op = $extra[0] ?? ''; $a = $extra[1] ?? NULL; $b = $extra[2] ?? NULL;
$g = \Drupal\group\Entity\Group::load(415);
switch ($op) {
  case 'title': $n = Node::load((int) $a); $n->setTitle($b)->save(); echo "title $a\n"; break;
  case 'ovr': $n = Node::load((int) $a); $n->set('field_group_content_access', (int) $b)->save(); echo "ovr $a=$b\n"; break;
  case 'grp': $g->set('field_group_access', (int) $a)->save(); echo "group access=$a\n"; break;
  case 'mem':
    $m = user_load_by_name('s3_member');
    if ($a === 'rm') { $g->removeMember($m); } else { if (!$g->getMember($m)) $g->addMember($m); }
    echo "member $a\n"; break;
}
