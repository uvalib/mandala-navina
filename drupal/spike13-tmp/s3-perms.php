<?php
foreach (\Drupal\group\Entity\GroupRole::loadMultiple() as $r) {
  if ($r->getGroupTypeId() !== 'collection') continue;
  $add = [];
  foreach ($r->getPermissions() as $x) if (str_contains($x,'group_node:audio')) $add[] = str_replace('group_node:audio','group_node:book',$x);
  if ($add) { $r->grantPermissions($add)->save(); echo $r->id(), " +", count($add), "\n"; }
}
