# `_mandala_group_inheritance_cascade_member_remove()` crashes when the member account itself is being deleted

**Area:** mandala_group_inheritance / Group / user deletion
**Raised during:** Implementing `authenticated-contributor-crud-not-wired-in-d11.md`, 2026-10-06 — functional smoke test of the new contributor-tier group permissions
**Jira:** (add when available)
**Priority:** Medium — real, reproducible, but narrow trigger (deleting a user account that is still a collection member); not yet known to have hit dev-0 or production

## Repro

1. Add a user as a member of a `collection` group (`$group->addMember($user)`).
2. Delete the user entity directly (`$user->delete()`), **without** first removing
   their group membership.
3. Deleting the user cascades to deleting their `group_membership` relationship,
   which fires `mandala_group_inheritance_group_relationship_delete()` →
   `_mandala_group_inheritance_cascade_member_remove($group, $account)`.
4. By the time that hook runs, `$relationship->getEntity()` (the `$account` passed
   in) resolves to `NULL` — the referenced user entity is already gone from
   storage. `_mandala_group_inheritance_cascade_member_remove()` passes this
   straight to `$sub->getMember($account)`, which has a non-nullable
   `AccountInterface $account` typehint, so this is a hard `TypeError`, not a
   caught/handled case.

Confirmed live on local DDEV 2026-10-06: `User::delete()` on a test member threw
a `TypeError` from `Drupal\group\Entity\Group::getMember(): Argument #1
($account) must be of type Drupal\Core\Session\AccountInterface, null given`,
surfaced through `mandala_group_inheritance.module:442`. In that run the user
row and its membership *were* actually removed from the DB before the hook
threw (confirmed via direct SQL after), so the immediate data effect was
survivable — but the thrown exception means any request-level transaction/batch
around a real account cancellation would see a fatal error, not a clean
deletion.

## Why this matters

Every migrated contributor (the ~1,543 users already in the local DB; ~1,538
per the earlier per-site count) is a candidate group member once the
contributor tier (`authenticated-contributor-crud-not-wired-in-d11.md`) is
wired, since joining a collection *is* how a user gets authoring rights going
forward. Standard Drupal account cancellation (`user_cancel()` with the
"Delete the account and its content" option, or an admin bulk delete) will hit
this exact path for any such user. Today the function only has to worry about
a membership being removed while the account stays live (the normal "leave
group" / admin-removes-member case, which it already handles via the
`mandala_inherited` flag) — it does not defend against the account itself
being the thing going away mid-cascade.

## To resolve (open)

- Guard `_mandala_group_inheritance_cascade_member_remove()` (and any sibling
  hook that receives an entity from `$relationship->getEntity()` inside a
  `hook_group_relationship_delete()`) against a `NULL` account — e.g. read the
  uid off the relationship's raw `entity_id` field instead of resolving the
  entity, or skip cascade cleanup entirely when the account is already gone
  (the row is being deleted anyway, so there is likely nothing to cascade).
- Add a kernel test exercising `User::delete()` while the user is a collection
  member (the existing suite in
  `modules/custom/mandala_group_inheritance/tests/src/Kernel/` only exercises
  membership removal/visibility, not account deletion) — this is exactly the
  kind of silent, hard-to-notice failure the oracle-test rationale in the
  2026-09-28 session log argued for.
- Not blocking the contributor-tier permission wiring itself (that change is
  config-only and unrelated to this code path), but should land before any
  real account-cancellation flow is exercised against collection members.
