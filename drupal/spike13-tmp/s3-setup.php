<?php
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\User;

// 1. group_node:book plugin on the collection group type.
$gt = GroupType::load('collection');
$rts = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
if (!$rts->loadByPluginId('group_node:book')) {
  $rts->createFromPlugin($gt, 'group_node:book')->save();
  echo "installed group_node:book\n";
}
// 2. field_group_content_access on the book bundle, cloned from audio.
if (!FieldConfig::load('node.book.field_group_content_access')) {
  $src = FieldConfig::load('node.audio.field_group_content_access') ?: FieldConfig::load('node.video.field_group_content_access');
  $f = FieldConfig::create(['field_name'=>'field_group_content_access','entity_type'=>'node','bundle'=>'book','label'=>$src->label(),'settings'=>$src->getSettings()]);
  $f->save(); echo "added field\n";
}
// 3. collection + membership of book 123746's pages.
$groups = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['label'=>'S3 spike collection']);
$g = $groups ? reset($groups) : Group::create(['type'=>'collection','label'=>'S3 spike collection','uid'=>1,'field_group_access'=>0]);
if ($g->isNew()) { $g->save(); echo "group ", $g->id(), "\n"; }
$nids = \Drupal::database()->query("select nid from book where bid=123746 order by nid")->fetchCol();
foreach (\Drupal\node\Entity\Node::loadMultiple($nids) as $n) {
  if (!\Drupal::entityTypeManager()->getStorage('group_relationship')->loadByEntity($n)) { $g->addRelationship($n, 'group_node:book'); }
}
// 4. mixed overrides: 3 private(2), 2 uva(3), 2 explicit public(1) on non-root pages.
$pages = array_slice($nids, 1);
$plan = [2=>[3,9,17], 3=>[5,21], 1=>[7,30]];
foreach ($plan as $val=>$idx) foreach ($idx as $i) { $n=\Drupal\node\Entity\Node::load($pages[$i]); $n->set('field_group_content_access',$val)->save(); echo "$val => ", $n->id(), " ", $n->label(), "\n"; }
// 5. users.
foreach (['s3_member','s3_other','s3_editor'] as $u) {
  if (!user_load_by_name($u)) { $x=User::create(['name'=>$u,'mail'=>"$u@example.test",'pass'=>'s3pass','status'=>1]); if ($u==='s3_editor') $x->addRole('content_editor'); $x->save(); }
}
$m = user_load_by_name('s3_member'); if (!$g->getMember($m)) $g->addMember($m);
echo "root nid ", $nids[0], " group ", $g->id(), "\n";
