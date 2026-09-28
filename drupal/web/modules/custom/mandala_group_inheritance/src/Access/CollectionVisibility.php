<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\Access;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves Mandala's collection visibility model.
 *
 * This is the single source of truth for "what visibility does this entity
 * effectively have, and may this account see it". Every consumer — the
 * hook_entity_access() implementation in mandala_group_inheritance.module, the
 * query alters that enforce the same rule in Views listings, and the kmassets
 * sync contributor that labels Solr documents — must resolve through here
 * rather than reimplementing the rule.
 *
 * That constraint is not stylistic. The same access logic has been duplicated
 * and gone stale four separate times in this codebase: PR #201 (group role
 * grants), PR #199 (groupKmassetUid()), SiblingCarouselService, and PR #255
 * (this hook's own bundle check, which let 2,027 published AV nodes leak).
 *
 * Static rather than a service: the procedural hook implementations that call
 * it already locate services through \Drupal::, so adding constructor injection
 * here would buy nothing and complicate the call sites.
 */
final class CollectionVisibility {

  /**
   * Visible to everyone, subject to Group's own permissions.
   */
  public const VISIBILITY_PUBLIC = 'public';

  /**
   * Visible only to members of the owning collection.
   */
  public const VISIBILITY_PRIVATE = 'private';

  /**
   * Visible only to authenticated users. See forNode() on what "UVA" means.
   */
  public const VISIBILITY_UVA = 'uva';

  /**
   * Group bundles that participate in the collection visibility model.
   */
  public const GROUP_BUNDLES = ['collection', 'subcollection'];

  /**
   * Resolves a node's effective visibility.
   *
   * Resolution rules, decided with Than 2026-09-24 against D7's own
   * mb_access.module, which is the only authority on what these values meant:
   *
   *  - The node's own field_group_content_access WINS when it carries a real
   *    value: 1 Public, 2 Private (group members), 3 UVA only.
   *  - Value 0 ("use group defaults"), or no such field at all, resolves from
   *    the owning collection's field_group_access: 0 Public, 1 Private, 2 UVA.
   *    This is ~78% of AV content. D7 reached the node scale by adding 1 to the
   *    collection value; mapping the two scales directly says the same thing
   *    without inheriting the off-by-one.
   *  - UVA means any authenticated user, copying D7 exactly: its grant went to
   *    DRUPAL_AUTHENTICATED_RID and never checked for NetBadge. Anonymous users
   *    never see UVA content.
   *
   * Resolved live rather than denormalised onto each node, because a
   * collection's visibility can be edited and ~9,000 nodes would silently go
   * stale.
   *
   * Deliberately NOT copied from D7: for a "use group defaults" node, D7's
   * public grant took the *collection's* published status instead of the node's,
   * so an unpublished node in a published public collection could be publicly
   * visible. Confirmed with Than as a bug to fix rather than replicate —
   * publication status is core's business, and this function only resolves
   * visibility.
   */
  public static function forNode(EntityInterface $node, GroupInterface $group): string {
    // field_group_content_access exists on audio/video (AV4) but never on
    // shanti_image, whose visibility comes entirely from its collection.
    if ($node->hasField('field_group_content_access') && !$node->get('field_group_content_access')->isEmpty()) {
      switch ((int) $node->get('field_group_content_access')->value) {
        case 1:
          return self::VISIBILITY_PUBLIC;

        case 2:
          return self::VISIBILITY_PRIVATE;

        case 3:
          return self::VISIBILITY_UVA;
      }
      // Value 0 is "use group defaults" — fall through to the collection.
    }

    return self::forGroup($group);
  }

  /**
   * Resolves a collection's own visibility.
   *
   * Anything unrecognised — including an empty field — is public, matching what
   * a value-less D7 collection migrates to. See
   * docs/deferred/av-collections-without-d7-group-access.md: whether that
   * "default loosens" behaviour is right is an open question for the production
   * migration, but it is the behaviour today and this method reproduces it.
   */
  public static function forGroup(GroupInterface $group): string {
    return match ((int) $group->get('field_group_access')->value) {
      1 => self::VISIBILITY_PRIVATE,
      2 => self::VISIBILITY_UVA,
      default => self::VISIBILITY_PUBLIC,
    };
  }

  /**
   * Whether an account may view content at the given effective visibility.
   *
   * @param string $visibility
   *   One of the VISIBILITY_* constants.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to test.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The owning collection, required to test membership for private content.
   *   When NULL, private content is treated as not viewable.
   */
  public static function allows(string $visibility, AccountInterface $account, ?GroupInterface $group): bool {
    return match ($visibility) {
      // Truthiness deliberately, not a strict comparison: Group's getMember()
      // is documented as returning FALSE for a non-member but returns NULL in
      // some paths, and both existing call sites tested it loosely.
      self::VISIBILITY_PRIVATE => $group !== NULL && (bool) $group->getMember($account),
      self::VISIBILITY_UVA => $account->isAuthenticated(),
      default => TRUE,
    };
  }

  /**
   * Whether an account bypasses visibility enforcement on nodes.
   *
   * The global content_editor role (ADR 015) holds 'bypass mandala group
   * access' so it can reach content in private collections without per-group
   * membership.
   *
   * Note the asymmetry with hasGroupBypass(): the node path honours core's
   * 'bypass node access' and the group path does not. That is existing
   * behaviour, preserved here verbatim rather than unified, because unifying it
   * would change who can see what. Flagged for Yuji 2026-09-28 — if it is
   * deliberate (it is a *node* permission, so arguably it should not grant
   * access to group entities) the two methods should stay separate and say so;
   * if not, they collapse into one.
   */
  public static function hasNodeBypass(AccountInterface $account): bool {
    return $account->hasPermission('bypass group access')
      || $account->hasPermission('bypass node access')
      || $account->hasPermission('bypass mandala group access');
  }

  /**
   * Whether an account bypasses visibility enforcement on group entities.
   *
   * See hasNodeBypass() on why this set is deliberately one permission smaller.
   */
  public static function hasGroupBypass(AccountInterface $account): bool {
    return $account->hasPermission('bypass group access')
      || $account->hasPermission('bypass mandala group access');
  }

  /**
   * Node bundles that have a group_node plugin installed.
   *
   * Read from group_relationship_type config rather than hardcoded, and
   * statically cached because callers run this for every node on gallery and
   * listing pages.
   *
   * Discovery, not a list: the hook_entity_access() check was hardcoded to
   * `shanti_image` until 2026-09-25, so audio/video never reached it at all.
   * Texts and Sources get enforcement the moment their bundles are enabled,
   * with no further code change here.
   */
  public static function groupNodeBundles(): array {
    $bundles = &drupal_static(__METHOD__);

    if ($bundles === NULL) {
      $bundles = [];
      $storage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
      foreach ($storage->loadMultiple() as $relationship_type) {
        $plugin_id = $relationship_type->getPluginId();
        if (str_starts_with($plugin_id, 'group_node:')) {
          $bundles[] = substr($plugin_id, strlen('group_node:'));
        }
      }
      $bundles = array_values(array_unique($bundles));
    }

    return $bundles;
  }

  /**
   * The group_node relation plugin ids currently installed.
   *
   * The plugin-id form of groupNodeBundles(), for callers that match against
   * group_relationship rows rather than node bundles.
   */
  public static function groupNodePluginIds(): array {
    return array_map(
      static fn (string $bundle): string => 'group_node:' . $bundle,
      self::groupNodeBundles(),
    );
  }

  /**
   * The node's owning collection or subcollection, if any.
   *
   * Takes the first collection/subcollection relationship, matching
   * CollectionFieldContributor::getOwningGroup() deliberately: that class
   * decides what search sees, this one decides what Drupal sees, and ADR 013/014
   * exist to keep those two answers identical. A node belongs to exactly one
   * collection in practice — confirmed for every grouped node (122,821 have
   * exactly one, none have more), and D7 modelled cross-collection presence as
   * asset links rather than multiple memberships.
   */
  public static function owningGroup(EntityInterface $node): ?GroupInterface {
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface $rel_storage */
    $rel_storage = \Drupal::entityTypeManager()->getStorage('group_relationship');

    foreach ($rel_storage->loadByEntity($node) as $relationship) {
      $group = $relationship->getGroup();
      if (in_array($group->bundle(), self::GROUP_BUNDLES, TRUE)) {
        return $group;
      }
    }

    return NULL;
  }

}
