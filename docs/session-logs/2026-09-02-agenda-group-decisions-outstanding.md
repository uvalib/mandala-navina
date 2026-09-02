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

### 1. D7 editor permissions are OG-group-scoped, not migrated

[`d7-editor-permissions-og-group-scoped-not-migrated.md`](../deferred/d7-editor-permissions-og-group-scoped-not-migrated.md) — **High**

- D7's real editor permissions are granted per-collection via `og_role_permission`, not
  core `role_permission` (which is empty for editor/workflow-editor/shanti-editor).
- D11's committed `content_editor` role (ADR 015) is sitewide and has zero overlap with
  the actual Mandala content types (article/page only) — a sitewide role fix alone can't
  be faithful to what D7 editors could actually do.
- **Decision needed:** how to model per-collection editorial grants in D11 (Group
  member-role permissions, most likely, matching the contributor-tier direction below) —
  this is a design call, not just an implementation task.

### 2. Contributor CRUD tier still unwired in D11

[`authenticated-contributor-crud-not-wired-in-d11.md`](../deferred/authenticated-contributor-crud-not-wired-in-d11.md) — **High, cutover gate**

- D7's `authenticated` role is the real contributor tier (CRUD-own on all asset types).
  D11's `authenticated` role currently grants none of it — view-only.
- Direction is already decided (Q2, 2026-08-07): wire as Group member-role permissions
  (create within groups), not core sitewide create.
- **Not a design question anymore — a scheduling one.** When does this land relative to
  the rest of Sprint 2/3, given it's a cutover gate?

### 3. Canonical D7 dev-source dump: frozen or re-cut?

[`canonical-d7-dev-source-dump.md`](../deferred/canonical-d7-dev-source-dump.md) — **Medium now, High before cutover rehearsals**

- dev-0 and local DDEV currently run different D7 dumps (7 of 8 `EXPECT_LIST` keys
  differ) — not wrong, just different baselines, which is fine for now.
- **Decision needed:** does dev-0's dump stay frozen as the team's canonical dev source,
  or get re-cut as staging/production cutover approaches? Cheap to decide now; expensive
  to leave open until rehearsals are underway and baselines have drifted further.

### 4. Staging Solr writes land on production

[`solr-cross-environment-write-targets.md`](../deferred/solr-cross-environment-write-targets.md) — **Medium–High, live risk**

- D7 staging on `dev-1` was never repointed: `mandala-sources-staging` and
  `mandala-av-staging` both target the **production** Solr master. A routine reindex or
  cron flush on staging writes to prod.
- Inversely, production Visuals writes into the staging master.
- Three crossings found in six sites — assume `dev-1` has more un-audited production
  references beyond Solr.
- **Needs infra ownership**, not just a Drupal-side fix: who repoints these, and when,
  without risking a staging action corrupting prod data in the meantime.

### 5. rdx (reindeer_x) ALB target unhealthy in production

[`rdx-alb-target-unhealthy-in-production.md`](../deferred/rdx-alb-target-unhealthy-in-production.md) — **High, live production defect**

- Independent of the D11 rebuild — a real defect in a service that's currently live.
  Re-verified still unhealthy as of 2026-08-11.
- Tangled with the still-open "does reindeer_x need to be always-on" question
  ([`reindeer-x-has-no-ecr-repo-or-pipeline.md`](../deferred/reindeer-x-has-no-ecr-repo-or-pipeline.md),
  under review by Yuji).
- **Decision needed:** fix the unhealthy target regardless of the always-on question, or
  bundle both into one conversation since they're the same service?

### 6. Solr pipeline cost/architecture conversation with Dave Goldstein

[`solr-pipeline-cost-discussion.md`](../deferred/solr-pipeline-cost-discussion.md) — **High, roadmap driving decision**

- Flagged in `docs/roadmap.md` as a conversation that needs to happen and hasn't been
  opened yet. Gates downstream Solr architecture spikes.
- **Action needed:** just scheduling this — it's not a Drupal-side task, it's a
  conversation that hasn't started.

### 7. Loose ends from the 2026-08-12 user migration (smaller, worth a mention)

[`d7-shared-user-database.md`](../deferred/d7-shared-user-database.md) — **Medium** (the migration itself is done; these are what's left)

- **Historical group ownership:** 174 collection/subcollection groups still owned by
  `uid: 1` (forced during 1b.2 to work around a Group insert bug, since fixed by PR #28).
  Now that 1,543 real users exist, decide whether/how to correct ownership to the actual
  D7 creator.
- **SAML/NetBadge account mapping:** how migrated D11 accounts link to UVA
  Shibboleth-authenticated sessions still needs a strategy (`name`/`mail` match vs. a
  stored NetBadge identifier field).
- **`realname` field:** D11 has no `realname` module by default — bring it in as a
  dependency, or fold the name into core user fields?

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

## Outcome

_(fill in as the session proceeds)_
