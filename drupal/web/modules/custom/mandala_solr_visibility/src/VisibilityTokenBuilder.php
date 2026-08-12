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
 *
 * One deliberate divergence from D7: privileged accounts. D7 special-cased
 * uid 1 inside the proxy; this class decides it instead, so that EVERY access
 * decision lives in Drupal and the proxy only ever applies what it is given.
 * See build().
 */
class VisibilityTokenBuilder {

  protected GroupMembershipLoader $membershipLoader;

  public function __construct(GroupMembershipLoader $membership_loader) {
    $this->membershipLoader = $membership_loader;
  }

  /**
   * The fq granting access to everything.
   *
   * Written for accounts Drupal considers privileged (see build()). It must be
   * an explicit token rather than "no token": the proxy treats a missing token
   * as fail-closed and applies the anonymous filter, so absence cannot mean
   * "unrestricted" without making those two cases indistinguishable.
   */
  protected const FQ_ALL = '(*:*)';

  /**
   * Permissions that mean "can reach private-collection content".
   *
   * MUST stay identical to the predicate in
   * _mandala_group_inheritance_node_access() — that hook decides what a user can
   * see in Drupal, and this class decides what they can see in search. If the two
   * lists drift, Drupal and Solr disagree about the same user, which is exactly
   * the class of inconsistency ADR 013/014 exists to prevent.
   *
   *   'bypass group access'          - Group module's own bypass
   *   'bypass node access'           - Drupal core; also implied for uid 1 via
   *                                    SuperUserAccessPolicy and for is_admin roles
   *   'bypass mandala group access'  - Mandala's own (mandala_group_inheritance),
   *                                    held by the global content_editor per ADR 015
   *
   * The third one is the reason this is a list rather than a single check: ADR
   * 015's content_editor holds ONLY that permission, so keying on core's
   * `bypass node access` alone would let an editor open private content in Drupal
   * while search silently hid it.
   */
  protected const BYPASS_PERMISSIONS = [
    'bypass group access',
    'bypass node access',
    'bypass mandala group access',
  ];

  /**
   * Builds the fq string for a user, or NULL if no token should be written.
   *
   * Anonymous gets NULL -- anonymous users are never written a token (see the
   * hook implementations), and the proxy applies the public filter to anyone
   * without one.
   *
   * Everyone else, INCLUDING administrators, gets a real token. Drupal is the
   * sole authority on access (ADR 013/014), so "an administrator sees
   * everything" is expressed here, as a permissive token, rather than as a
   * special case inside the proxy.
   *
   * Keyed on permissions rather than on uid 1, so it follows Drupal's own answer.
   * The list (self::BYPASS_PERMISSIONS) mirrors
   * _mandala_group_inheritance_node_access() exactly, so search and Drupal agree:
   * uid 1 qualifies via SuperUserAccessPolicy, the `administrator` role via
   * `is_admin: true`, and the global `content_editor` via ADR 015's
   * `bypass mandala group access`. Plain authenticated users do not.
   *
   * NB this corrects a long-standing inversion. uid 1 previously returned NULL
   * here and was ALSO short-circuited in the proxy, so the admin fell through to
   * the anonymous filter and saw LESS than a normal user — while the code and
   * docs in four places claimed uid 1 "views everything".
   */
  public function build(AccountInterface $account): ?string {
    if ($account->isAnonymous()) {
      return NULL;
    }

    foreach (self::BYPASS_PERMISSIONS as $permission) {
      if ($account->hasPermission($permission)) {
        return self::FQ_ALL;
      }
    }

    $uid = (int) $account->id();

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
