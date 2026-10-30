<?php

/**
 * Spike 5 throwaway (LOCAL DDEV ONLY): Group access for bibcite_reference.
 *
 *   ddev drush php:script docs/spikes/assets/spike-05-bibcite/entity-model-poc/access.php
 *
 * Needs the spike5_bibcite_poc module enabled. Creates scratch groups/users
 * named SPIKE5*; MODE=teardown removes them.
 */
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupType;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\Core\Session\AnonymousUserSession;

$etm = \Drupal::entityTypeManager();
$refs = $etm->getStorage('bibcite_reference');

if ((getenv('MODE') ?: '') === 'teardown') {
  foreach (['SPIKE5 private', 'SPIKE5 public', 'SPIKE5 uva'] as $t) {
    foreach ($etm->getStorage('group')->loadByProperties(['label' => $t]) as $g) {
      $g->delete();
    }
  }
  foreach (['spike5_member', 'spike5_outsider'] as $n) {
    foreach ($etm->getStorage('user')->loadByProperties(['name' => $n]) as $u) {
      $u->delete();
    }
  }
  echo "torn down\n";
  return;
}

// 1. Install the relation plugin on the collection group type.
$gt = GroupType::load('collection');
$rt_id = $etm->getStorage('group_relationship_type')->getRelationshipTypeId('collection', 'group_bibcite_reference');
if (!GroupRelationshipType::load($rt_id)) {
  $etm->getStorage('group_relationship_type')->createFromPlugin($gt, 'group_bibcite_reference')->save();
}
echo "relationship type: $rt_id\n";

// 2. Scratch collections (field_group_access: 0 public, 1 private, 2 UVA).
$mk = function ($label, $access) {
  $g = Group::create(['type' => 'collection', 'label' => $label, 'uid' => 1, 'field_group_access' => $access]);
  $g->save();
  return $g;
};
$priv = $mk('SPIKE5 private', 1);
$pub = $mk('SPIKE5 public', 0);
$uva = $mk('SPIKE5 uva', 2);

// 3. Users: a member of the private collection, and an outsider.
$mkuser = function ($n) {
  $u = User::create(['name' => $n, 'mail' => "$n@example.invalid", 'status' => 1]);
  $u->save();
  return $u;
};
$member = $mkuser('spike5_member');
$outsider = $mkuser('spike5_outsider');
$priv->addMember($member);

// 4. Put references in collections: 47 private, 46 public, 45 UVA, 48 none.
foreach ([47 => $priv, 46 => $pub, 45 => $uva] as $id => $g) {
  $g->addRelationship($refs->load($id), 'group_bibcite_reference');
}

// 5. Base permission: bibcite's own, granted to anon + authenticated, as the
// type-level outsider/anonymous grant does for nodes. The hook restricts it.
foreach (['anonymous', 'authenticated'] as $r) {
  $role = Role::load($r);
  $role->grantPermission('view bibcite_reference')->save();
}

// 5b. Group's own permission for the new plugin (installing a relation plugin
// adds 'view group_bibcite_reference entity'; no role has it until granted).
// Mirrors what the collection roles hold for 'view group_node:audio entity'.
foreach (['collection-anonymous', 'collection-outsider', 'collection-member'] as $rid) {
  $gr = \Drupal\group\Entity\GroupRole::load($rid);
  $gr->grantPermission('view group_bibcite_reference entity')->save();
}

// 6. Check.
$accounts = [
  'anonymous' => new AnonymousUserSession(),
  'outsider (authenticated)' => $outsider,
  'member of private' => $member,
  'admin (uid 1)' => User::load(1),
];
printf("%-26s %-12s %-12s %-12s %-12s\n", 'viewer', '47 private', '46 public', '45 uva', '48 no group');
foreach ($accounts as $label => $acct) {
  $row = [];
  foreach ([47, 46, 45, 48] as $id) {
    $row[] = $refs->load($id)->access('view', $acct) ? 'ALLOW' : 'deny';
  }
  printf("%-26s %-12s %-12s %-12s %-12s\n", $label, ...$row);
}
