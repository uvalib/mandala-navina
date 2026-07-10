<?php

declare(strict_types=1);

namespace Drupal\mandala_solr_visibility;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\GroupMembershipLoader;

/**
 * Builds the Solr `fq` visibility filter for a user (ADR 014).
 *
 * Reproduces the D7 proxy's Searcher::setVisibility() OR-clause shape
 * exactly (see ADR 014), but computed here from Drupal's authoritative
 * Group membership data instead of a Solr membership query -- this is what
 * eliminates the proxy's circular dependency on kmassets for its own access
 * decisions (ADR 013). The %20/OR encoding matches Searcher::getQueryStr(),
 * which concatenates fq values into the query string with no further
 * encoding.
 */
class VisibilityTokenBuilder {

  protected GroupMembershipLoader $membershipLoader;

  public function __construct(GroupMembershipLoader $membership_loader) {
    $this->membershipLoader = $membership_loader;
  }

  /**
   * Builds the fq string for a user, or NULL if no filter should apply.
   *
   * uid=1 (Drupal admin) and anonymous both get NULL -- for uid=1 this
   * matches the D7 proxy's "view everything" behaviour (Searcher.php
   * explicitly special-cases uid 1); anonymous users are never written a
   * token in the first place (see hook implementations).
   */
  public function build(AccountInterface $account): ?string {
    $uid = (int) $account->id();
    if ($uid === 1 || $account->isAnonymous()) {
      return NULL;
    }

    $conditions = [
      '(visibility_i:(1%203))',
      '(asset_type:(places%20subjects%20terms))',
      "(node_user_i:{$uid})",
      "(members_uid_ss:user-{$uid})",
    ];

    $collection_uids = $this->restrictedCollectionUids($account);
    if (!empty($collection_uids)) {
      $conditions[] = '(collection_uid_s:(' . implode('%20', $collection_uids) . '))';
    }

    return '(' . implode('%20OR%20', $conditions) . ')';
  }

  /**
   * Kmasset uids of private/subscribable collections this user belongs to.
   *
   * Public collections are omitted -- their items already pass the base
   * visibility_i:(1 3) clause, so listing them would be redundant (and,
   * more importantly, wrong to skip: omitting them here does NOT hide their
   * content, since the base clause already admits it).
   *
   * Cascaded subcollection membership (mandala_group_inheritance) is real
   * group_relationship data by the time this runs, not something this class
   * needs to resolve itself -- GroupMembershipLoader::loadByUser() already
   * returns it.
   *
   * Uid format mirrors CollectionFieldContributor::groupKmassetUid() exactly
   * (images-11-{d11-group-id}) -- these two must never drift apart, since a
   * mismatch here silently breaks the entire private-collection access path.
   */
  protected function restrictedCollectionUids(AccountInterface $account): array {
    $memberships = $this->membershipLoader->loadByUser($account);

    $uids = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!in_array($group->bundle(), ['collection', 'subcollection'], TRUE)) {
        continue;
      }
      if ((int) $group->get('field_group_access')->value === 0) {
        continue;
      }
      $uids[] = 'images-11-' . $group->id();
    }
    return $uids;
  }

}
