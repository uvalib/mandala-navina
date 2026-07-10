# Group membership removal is broken — `mandala_group_inheritance` reads a non-existent `data` field

**Area:** Group collections / mandala_group_inheritance / access control
**Raised during:** Session 2026-07-10 (1b.1 — testing the Redis visibility-token membership-change hook)
**Jira:** (add when available)
**Priority:** High — affects already-merged 1b.2 functionality (PR #27), not just 1b.1

## What we found

`Group::removeMember($account)` — and therefore any code path that removes a
user from a `collection`/`subcollection` group — currently **throws** on any
collection whose membership-cascade hook runs:

```
InvalidArgumentException: Field data is unknown.
  in ContentEntityBase->getTranslatedField() (ContentEntityBase.php:630)
  in SqlContentEntityStorage->delete()
```

Confirmed the module is the cause by disabling `mandala_group_inheritance`
and retrying the identical delete — it succeeds cleanly with the module off.
Re-enabling reproduces the failure every time.

## Root cause

`mandala_group_inheritance_group_relationship_delete()` →
`_mandala_group_inheritance_cascade_member_remove()` reads
`$relationship->get('data')` to check the `_inherited` flag (the mechanism
meant to retain sub-only members when cascading a removal from the parent
collection — see the module's own docblock). But `group_relationship` has
**no `data` field at all** in this codebase's Group module version:

```
getFieldStorageDefinitions('group_relationship')  // no 'data' key, at any bundle
```

Confirmed at both the entity-type-storage level (no `data` field exists for
`group_relationship` full stop) and the bundle level (`collection-group_membership`).
This means:

1. **The `_inherited` flag has never actually been stored anywhere.** The
   corresponding write path — `addMember($account, ['_inherited' => TRUE])`
   in `_mandala_group_inheritance_cascade_member_add()` and
   `mandala_group_inheritance_group_insert()` — silently drops the
   `_inherited` key on entity creation rather than erroring (Drupal doesn't
   strictly validate unknown array keys passed to `create()`), so the
   *sub-only retention* feature described in the module's docblock has
   never functioned, in either direction.
2. **Any membership removal that reaches this code throws**, because unlike
   the create path, `->get('data')` is a strict field lookup that errors on
   an undefined field name. `Group::removeMember()` on a `collection` is
   therefore currently unusable for any collection with subcollections.

## Why this wasn't caught in 1b.2

The 1b.2 session's verification (per the sprint doc) exercised counts,
private-collection access denial, and the membership *cascade-add* path —
not a real removal. The add path masks the bug (silently drops the flag
instead of erroring), so nothing failed until a `removeMember()` call was
actually made.

## What needs to happen

Either:
- Add a real `data` field to the `group_relationship` entity/bundles (base
  field or per-bundle config field) so the `_inherited` flag can actually be
  stored and read, or
- Replace the flag mechanism entirely — e.g. track "direct vs. cascaded"
  membership via a dedicated boolean field on the relationship, or by
  diffing group membership rosters instead of a stored flag.

Either way, `_mandala_group_inheritance_cascade_member_remove()` needs a
real fix before any collection membership can be safely removed, and the
"sub-only retention" behavior needs actual test coverage exercising
*removal*, not just addition.

## Cross-references

- [ADR 011](../adr/011-group-collections-inheritance.md) — Option D,
  visibility + membership inheritance (the feature this bug breaks)
- `mandala_group_inheritance.module` — `_mandala_group_inheritance_cascade_member_remove()`
