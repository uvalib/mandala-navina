# D7 users live in a separate shared database, not any per-site dump

**Area:** migration / users / infrastructure
**Raised during:** Session 2026-07-10 (1b.1 — planning the user migration)
**Jira:** (add when available)
**Priority:** **Low — user migration DONE (2026-08-12) and historical group ownership FIXED
(2026-09-02).** 1,543 users migrated from `mandala_shared` on dev-0; all 22 private groups
now have real members; `d7_images_collection_memberships` went from 36/246 (stuck since
2026-07-19) to **246/246**. The 171 collection/subcollection groups forced to `uid: 1` are
now correctly re-owned to their real D7 creators — see below. What's left is just the
still-open SAML/NetBadge mapping and `realname` design questions.

## What we found

D7 Mandala's five sites (AV, Images, Sources, Texts, Home) don't each have
their own user base — they share one, via a MySQL cross-database table-prefix
kludge. Confirmed in the legacy repo,
`mandala-drupal/docroot/sites/all/platform.settings.php`:

```php
$shared = 'mandala_shared_dev.';
$databases['default']['default']['prefix'] = array(
  'default' => '',
  'users' => $shared,
  'users_roles' => $shared,
  'role' => $shared,
  'authmap' => $shared,
  'sessions' => $shared,
  'field_data_field_first_name' => $shared,
  'field_revisions_field_first_name' => $shared,
  'field_data_field_last_name' => $shared,
  'field_revisions_field_last_name' => $shared,
  'realname' => $shared,
);
```

(Dev shown; production uses `mandala_shared.` per `mandala-drupal/CLAUDE.md`.)

Each site's own local `users`/`users_roles`/`role`/`authmap`/`sessions`
tables exist but are **entirely shadowed and unused** — every one of these
table names resolves to the shared database instead. The real, authoritative
D7 user data — for all five sites at once — lives only in `mandala_shared`
(prod) / `mandala_shared_dev` (dev).

`mandala-drupal/CLAUDE.md` also confirms production authentication is
**SimpleSAMLphp/Shibboleth (UVA NetBadge)** layered on top of this shared
base, and that `shanti_general` handles cross-site `/user` redirects (a
D7-multisite-specific behavior, not migration-relevant on its own).

## What this means for the migration

- **Wrong source, silently wrong data.** Any D7 source dump/connection scoped
  to one site (e.g. the `d7_images` dump this session's Images work has used
  throughout) does **not** contain real user data — its local `users` table
  is the shadowed, unused one. Pointing a user migration at it would produce
  garbage or empty results without erroring.
- **The user migration needs its own source DB connection**, pointed at
  `mandala_shared` — separate from the per-site dumps (`d7_images`, and
  whatever the eventual Sources/Texts/AV/Home dumps are named).
- **One user migration serves all five site tracks.** Since the D7 source is
  already unified, the D11 side should be too — this is naturally a
  cross-cutting migration, not a per-site one like Images' `d7_images_*`
  migrations, and probably belongs outside any single site's migration group.
- **SAML/NetBadge account mapping is a separate, real question**, not solved
  by migrating the `users` table alone — how migrated D11 accounts link to
  UVA Shibboleth-authenticated sessions needs its own mapping strategy
  (`name`/`mail` match? a stored NetBadge identifier field?).
  **ASSIGNED to Yuji for a decision, 2026-09-02 (group decision).** Relevant to
  [ADR 013](../adr/013-drupal-source-of-truth-solr-client-compatibility.md)/[014](../adr/014-hybrid-solr-proxy-design.md)'s
  SAML+OAuth2 coexistence work (Spike 10, 1b.1 part 4) — that work assumes a
  Drupal account already exists to key `sub` off of; user migration is the
  thing that has to create it.

## What's already gated on this

- ~~`d7_images_collection_memberships` (1b.2): 38/249 rows created, 211 skipped
  — those 211 reference D7 users that don't exist in D11 yet. Re-run once
  users are migrated.~~ **DONE 2026-08-12** — re-run after the user migration
  produced 210 created, 36 updated, 0 failed → **246/246**.
- ~~The 174 collection/subcollection groups that got `uid: 1` forced during
  1b.2... this correction (historical group ownership → actual D7 creator)
  is still open~~ **FIXED 2026-09-02.** Checked the D7 source first: of 171
  real collection/subcollection nodes (not 174 — the earlier count was
  approximate), **zero have `uid: 0`**, so the Group insert-bug workaround
  was never actually protecting real data here — it just discarded 137
  legitimate creator assignments (34 of 171 were already correctly
  `uid: 1`/ShantiAdmin). All 18 distinct D7 creator uids resolved cleanly
  in `migrate_map_d7_users` to live, active D11 accounts, 1:1 identity-mapped
  (D7 uid *N* → D11 uid *N* in every case). Fixed both the migration process
  pipeline (`uid: uid` replacing the hardcoded `default_value: 1`, in
  `d7_images_collections.yml` and `d7_images_subcollections.yml`, both
  `config/install` and `config/sync` per the PR #28 drift lesson) and the
  171 already-migrated groups on dev-0 directly (dry-run verified 137
  corrections / 34 already-correct / 0 errors, then applied and
  re-verified: 171/171 correct).

## Open questions for the actual migration design

- Cardinality/scope: does `mandala_shared` contain users for sites *outside*
  Mandala entirely (a truly shared UVA-wide user base), or is it scoped to
  just these five? Affects whether the D11 migration needs to filter.
  Fill in once the shared DB schema/dump is actually in hand.
- Password hashes: D7 uses phpass; D11/current Drupal core uses phpass-
  compatible hashing too (core still ships `PhpassHashedPassword` era logic
  for legacy migrations), so password migration is likely straightforward —
  confirm during implementation rather than assuming.
- `realname` field migration: D11 has no `realname` module by default: decide
  whether to bring it in as a dependency or fold the name into core user
  fields.

## Cross-references

- [Critical Path §7](../planning/critical-path.md#7-content-migration-strategy) —
  Migration Sequencing already lists "Users (no dependencies)" first;
  this note is why that's not as simple as it sounds
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — SAML+OAuth2 coexistence,
  which needs migrated D11 accounts to key tokens off of
