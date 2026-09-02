# Session Log: Group Decisions Outstanding

**Date:** 2026-09-02
**Participants:** Than Grove (driving), Yuji Shinozaki, Xiaoming Wang, Carla Arton, Claude Code
**Status:** Agenda drafted pre-session; fill in Outcome/decisions below as the session proceeds.

---

## Agenda

A sweep of the deferred backlog to find what actually needs group input — a decision,
infra visibility, or a scheduling call — versus what just needs a driver. Three items
that looked like they belonged here got resolved solo this session instead (see
"Resolved before this meeting" below); what's left are the ones that genuinely need more
than one person in the room.

### 1. Group-role migration: contributor + group-editor tiers, one effort — schedule it

[`d7-editor-permissions-og-group-scoped-not-migrated.md`](../deferred/d7-editor-permissions-og-group-scoped-not-migrated.md),
[`authenticated-contributor-crud-not-wired-in-d11.md`](../deferred/authenticated-contributor-crud-not-wired-in-d11.md) — **High, cutover gate**

- **Not an open design question for either tier** — both models are already decided.
  [ADR 015](../adr/015-editorial-access-model-global-content-editor.md) (Accepted
  2026-08-06) settled the editor side: **global `content_editor`** (create/edit/delete
  any content, any group — "Phase A", already built and live for Images) plus a
  **group editor** (per-group Group role, `scope: individual`, CRUD only within that
  one group — reconstructing D7's OG `editor` role, "Phase B", deferred, not yet
  built). Q2 (2026-08-07) settled the contributor side the same way: Group
  member-role permissions (create-within-group), not core sitewide create.
- **Folded into one agenda item because they're mechanically the same migration** —
  both are Group-role reconstructions from D7's per-group role data
  (`og_users_roles`/`og_role_permission`), just with different permission sets
  (contributor = own-content-only within a group; group-editor = any-content within a
  group). Building one naturally builds the scaffolding for the other.
- D7's real `editor` role (142 active users) was never in core `role_permission` at
  all — it lived entirely in OG's `og_role_permission`, per-collection, which is
  exactly why neither tier can be a sitewide role.
- **What's actually needed:** a single scheduling/resourcing call — when does this
  combined Group-role migration (`og_users_roles` → Group `individual`-scope roles,
  covering both contributor and group-editor permission sets) get built? Contributor
  is a **hard cutover gate** (migrated users can currently author nothing); the 142
  D7 editors currently migrated as plain `authenticated` have **no group-scoped
  editorial access today** either. Connects to the still-open 1b.3 (Solr-proxy
  visibility coherence) / 1b.4 (paragraph access inheritance) tasks, which may share
  the same underlying mechanism.

### 2. `realname` field decision — last unassigned loose end

[`d7-shared-user-database.md`](../deferred/d7-shared-user-database.md) — **Low**

- D11 has no `realname` module by default (D7 had it). Bring it in as a dependency to
  match D7's display-name behavior, or fold display-name handling into core user
  fields (`field_first_name`/`field_last_name`, already migrated) instead?
- Still unassigned — smallest, lowest-priority item left on the whole agenda.

---

## Resolved before this meeting (for awareness, not discussion)

Three items that would have been on this agenda got resolved solo earlier this
session — noted here so nobody re-opens them without new information:

- **D7 multi-image sequence viewer** (`sdviewer.php`) — checked the real D7 source;
  confirmed unfinished prototype, never reachable in production. Decided: not needed for
  D11. See [`images-missing-interactive-viewing-surfaces.md`](../deferred/images-missing-interactive-viewing-surfaces.md).
- **kmassets audit master/reader gap** — no longer blocked on a group conversation;
  **assigned to Yuji** to root-cause on his own timeline. See
  [`kmassets-audit-checks-master-not-search-reader.md`](../deferred/kmassets-audit-checks-master-not-search-reader.md).
- **`deployspec.yml` full-clone of `terraform-infrastructure`** — group-approved fix
  (`--depth 1 --single-branch`) implemented, landing in
  [PR #176](https://github.com/uvalib/mandala-navina/pull/176).

## Resolved during this meeting

- **`field_legacy_nid` is not unique across sites** — this one turned out to already be
  designed and built, just never formally signed off: **[ADR 017](../adr/017-legacy-identity-composite-key.md)**
  (`field_legacy_site` companion field, kmassets service vocabulary, discriminator is the
  D7 *site* not the asset type) was proposed 2026-08-25 and the field itself shipped the
  same day (PR #152), but the ADR sat at `Status: Proposed` pending sign-off. **Ratified
  2026-09-02** by Yuji, Than, and Xiaoming — status updated to `Accepted`. No new work:
  Texts/Sources/AV migrations just need to populate `field_legacy_site` per the existing
  checklist in [`migration-legacy-nid-required-convention.md`](../deferred/migration-legacy-nid-required-convention.md)
  when they're built.
- **Canonical D7 dev-source dump: frozen or re-cut?** — **Decided (Yuji): re-cut**, not
  frozen, as the team approaches staging/production, trading baseline stability for
  fidelity with real production data. **Condition attached: every re-cut must alert the
  whole team**, so people know to resync their local environment and re-baseline, rather
  than silently drifting the way dev-0 and DDEV did here (7 of 8 `EXPECT_LIST` keys
  diverged with nobody aware). Alert mechanism itself not yet chosen — tracked as a
  practical follow-up in [`canonical-d7-dev-source-dump.md`](../deferred/canonical-d7-dev-source-dump.md).
- **Staging Solr writes land on production** — **FIXED live during the meeting.**
  `mandala-sources-staging`'s `solr` search_api server disabled
  (`search-api-server-disable`); `mandala-av-staging`'s `mandala_library_rw` apachesolr
  environment repointed off production (`solr-set-env-url`, since a full module disable
  would have cascaded through 6+ dependent modules on that site). Both verified live on
  `dev-1`. **Production Visuals → staging left open, assigned to Yuji** to review —
  lower urgency since it's the opposite (safer) direction, and Visuals' other search
  backend is already confirmed dead. See
  [`solr-cross-environment-write-targets.md`](../deferred/solr-cross-environment-write-targets.md)
  for full detail.
- **rdx (reindeer_x) ALB target unhealthy in production** — **assigned to Yuji**, bundled
  with the still-open "does reindeer_x need to be always-on"
  ([`reindeer-x-has-no-ecr-repo-or-pipeline.md`](../deferred/reindeer-x-has-no-ecr-repo-or-pipeline.md))
  review — same service, same gate. The port fix and the other two respects (move to
  dev-1, get the deployment into GitHub) stay blocked until that review lands; one owner
  now covers both notes. See
  [`rdx-alb-target-unhealthy-in-production.md`](../deferred/rdx-alb-target-unhealthy-in-production.md).
- **Historical group ownership — FIXED live during the meeting.** Checked the D7 source
  first rather than assuming: of 171 real collection/subcollection nodes, **zero** have
  `uid: 0`, so the Group insert-bug workaround that forced every migrated group to
  `uid: 1` was never protecting real data — it just discarded 137 legitimate creator
  assignments. All 18 distinct D7 creator uids resolved cleanly to live D11 accounts,
  1:1 identity-mapped. Fixed the migration process pipeline (`uid: uid`, both
  `d7_images_collections.yml` and `d7_images_subcollections.yml`, `config/install` and
  `config/sync`) and corrected all 171 already-migrated groups on dev-0 directly
  (dry-run verified, then applied: 171/171 correct). See
  [`d7-shared-user-database.md`](../deferred/d7-shared-user-database.md).
- **SAML/NetBadge account mapping — assigned to Yuji** for a decision (`name`/`mail`
  match vs. a stored NetBadge identifier field). Relevant to ADR 013/014's SAML+OAuth2
  coexistence work, which assumes a Drupal account already exists to key `sub` off
  of — this decision determines which account that lookup resolves to.
- **Solr pipeline cost/architecture conversation with Dave Goldstein** — **correction
  during this meeting**: `docs/roadmap.md`'s framing ("hasn't been opened yet") is stale.
  The conversation already happened (2026-06-26 update in the deferred note) and reframed
  the picture — there's no always-on ECS to right-size, so the original cost worry
  receded, and a direct-to-master sink came out of it and **already shipped** (1a.8).
  **Assigned to Yuji** to close the loop with Dave on the still-open items from that
  conversation (`kmasset-solr-doc-contract.md` §3: direct-to-master credentials,
  second-writer acceptability, batch cadence, regen-directory support, failure-log
  reliability, timestamp keying) — worth following up on since the sink shipped ahead of
  Dave confirming those. See
  [`solr-pipeline-cost-discussion.md`](../deferred/solr-pipeline-cost-discussion.md).

## Outcome

_(fill in as the session proceeds)_
