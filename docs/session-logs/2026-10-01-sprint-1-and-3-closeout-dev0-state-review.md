# Session Log: Sprint 1 And 3 Closeout Dev0 State Review

**Date:** 2026-10-01  
**Participants:** Xiaoming Wang, Claude Code (Yuji Shinozaki and Than Grove joined late in the session and confirmed the Sprint 3 criterion #12 reword)  
**Outcome:** Project-wide status review, then closed out [Sprint 3](../sprints/sprint-03-av-core-implementation.md) (all 14 acceptance criteria ticked; criteria #4, #12, #14 reworded; the AV14 staff handoff deferred to [a new deferred note](../deferred/av14-media-less-nodes-staff-handoff.md)) and [Sprint 1](../sprints/sprint-01-images-implementation.md) (the two-D11-environment promotion rehearsal deferred until a staging server exists; there is none, only dev-0). Verified dev-0 is fully deployed. Local config drift cleared with `config:import`. [PR #268](https://github.com/uvalib/mandala-navina/pull/268).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-01 14:40

what's the status of the project

---

## Claude — 2026-10-01 14:40

I'll start with the session-start check, then read the docs that track status.

---

## Claude — 2026-10-01 14:42

## Mandala D11 rebuild: status as of 2026-10-01

`main` is at PR #267 and there are no open PRs. My saved memory snapshot is from 09-24, so I rebuilt this from the git log, the latest session log (09-29) and the deferred index.

### Recent progress (09-24 to 09-29)
- **AV7 (access) is done.** Private and UVA-only AV content was viewable anonymously (2,027 nodes) and is now enforced in Drupal. Dev-0 is verified. Than decided and implemented PR #259 (listings enforcement) and PR #261 (a Solr visibility fix) on 09-28.
- **AV11/AV12 (Kaltura upload) is built and merged.**
  - PR #264 added Kaltura session minting and a browser-direct chunked upload widget.
  - PR #265 fixed three bugs found in review.
  - Spike 7 is closed.
  - Sprint 3's solo and upload tracks are essentially finished.
- **Orphaned content:** Than and Yuji confirmed orphans will migrate into a temporary review group. 18 published video nodes are affected.
- **Deploy tooling:** PRs #266 and #267 added `deploy-status.sh` and documented the CodePipeline path filters.
- **Earlier fixes:**
  - The subcollection-access overwrite is fixed and dev-0 is repaired.
  - A DDEV Solr guardrail is merged and the shared Solr master is cleaned.

### Session-start check: needs attention before any work
- **Config drift:** `config:status` shows `core.entity_form_display.node.audio.default` and `...node.video.default` as *Different*. This is likely the AV12 upload widget config from PR #264 not yet imported locally. `ddev drush config:import` should clear it, as it has before.
- **Content count:** local has 7,396 videos and dev-0 has 7,397. Dev-0 may have gained one node, for example from upload testing.

I haven't fixed either. `update-db-from-remote.sh` is destructive, so I'd run the config import first and only rebase the DB if you want to.

### Open items
- **Assigned to Yuji:** local Solr in DDEV and the reusable cleanup command. Also reindeer_x always-on review plus the ALB defect, and the kmassets audit against the search reader.
- **Needs a team decision:** local DDEV `sites/default/files` provisioning, and how to validate hand-edited config YAML.
- **High priority:**
  - OAuth2 `openid` scope grants no permissions, which blocks the authenticated path.
  - Contributor CRUD isn't wired up in D11.
  - The `kmassets-uid` identity items.
  - Staging needs the access and re-index sequence repeated.
  - The SAML SP cert is expired and must be renewed before production.
- **Questions for Than:** the AV4 data-fidelity notes, ISO 639 language conversion (a possible live search gap for about 3,600 Tibetan and Chinese items), and whether to resume Texts/Sources or start Spike 11 (AV transcripts).
- **ADR 016** (public URL structure) is still Proposed.

Should I run `ddev drush config:import` to clear the local drift?

---

## User — 2026-10-01 14:58

What's exact change in the config

---

## Claude — 2026-10-01 14:59

Both diffs are the AV12 upload widget from PR #264. The sync directory has the new config and the local DB doesn't. In each diff, `<` is the local DB and `>` is the committed config.

**`node.audio.default` and `node.video.default`** (the video one uses `field_video` in place of `field_audio`):

| | Local DB (old) | Committed config (new) |
|---|---|---|
| Module dependency | not listed | adds `mandala_kaltura` |
| `field_audio` / `field_video` | in `hidden:` (`field_audio: true`) | in `content:` with `type: kaltura_upload`, `weight: 100`, `region: content`, `settings: {}` |

So the Kaltura upload widget is hidden on the node edit form locally. After import it shows up as the last item on the form.

The diff also shows a stray blank line at the end of the live export. It's an artifact of the export and not a real change.

All of this is additive and nothing is removed, so `ddev drush config:import` should be safe. I haven't run it. Do you want me to?

---

## User — 2026-10-01 14:59

yes, run the config import

---

## Claude — 2026-10-01 15:00

The config import worked. It updated `node.video.default` and `node.audio.default`, and `config:status` now reports "No differences between DB and sync directory."

The video count is still off. Local has 7,396 and dev-0 has 7,397. I haven't investigated it, and it isn't config drift. If you want local fully matched to dev-0, `./scripts/update-db-from-remote.sh dev` would do it, but it's destructive. Run `ddev snapshot` first if you have local-only content you want to keep. A one-node gap is probably dev-0 upload testing, so I'd leave it unless you need exact parity.

---

## User — 2026-10-01 15:00

that extra video doesn't matter. Than uploaded a test video on dev-0

---

## Claude — 2026-10-01 15:01

Got it. That accounts for the extra node, so the local DB is fine. Config is in sync and the only remaining difference from dev-0 is Than's test upload, so I won't rebase the DB.

What would you like to work on?

---

## User — 2026-10-01 15:03

Please describe 1,3,4 item in the high priority list

---

## Claude — 2026-10-01 15:03

One correction first: item 1 is stale. I listed it as blocking the OAuth2 path because the deferred index row says so, but the note itself says it's fixed. I only read the first 120 of that note's 167 lines.

## 1. OAuth2 `openid` scope grants no permissions (fixed)

- **What it was:** `simple_oauth` 6.1.1 intersects a user's real permissions with what the token's scopes grant. The `openid` scope was an identity-only "umbrella" scope with no permission granularity. So every Bearer-token request resolved to zero permissions, and `/oauth/userinfo` redirected instead of returning JSON. The code is behaving as designed, and the gap dated from Spike 10.
- **Fix (Yuji, 2026-08-19):** `simple_oauth.oauth2_scope.openid.yml` now has `umbrella: false`, `granularity_id: permission` and `permission: 'access content'`. `umbrella: false` is needed because an umbrella scope ignores its own granularity.
- **Status:** confirmed on dev-0. A second, separate bug (SimpleSAMLphp's `checkAuthStatus()` forcing a logout on Bearer requests) was fixed on 2026-08-20, and the full SAML→OAuth2→JSON chain now works. The index row's "High — blocks the path" is out of date.

## 3. `kmassets-uid` identity (three notes, all still open)

- **`kmassets-uid-identity-across-migration`:** the Solr document key is `{service}-{nid}`, for example `images-1028396`. The D11 migration assigns new node ids, and node ids can't be preserved because five sites share one node table. The decision (2026-07-01) is to use `images-11-{d11-nid}` for D11 docs. A new `uid_legacy_s` field keeps the old D7 uid, and the proxy gets a shim that logs and rewrites old-format uids.
  - The cutover is a seven-step sequence, ending in a full reindex, a wipe of the D7-era docs and a redirect hookup.
  - The `uid_legacy_s` schema change still has to be coordinated with Dave Goldstein before the staging reindex.
- **`kmassets-uid-consumer-analysis`:** nobody has yet audited the clients that store old uids. These include the React KMaps app, reindeer_x, D7 content links and the external services emu, fox and cicada. If this isn't done before cutover, they break silently. The React app and reindeer_x come first, and Than and Andres are the people to ask.
- **`kmassets-kmapid-ancestor-id-resolution`:** the migrated KMaps `path` holds breadcrumb names such as `{{Earth}}{{Asia}}...`, not numeric ancestor ids. So `kmapid` and `kmapid_is`, the ancestor-expanded facets, come out as garbage. Only `kmapid_strict` is correct. The note prefers resolving ids at migration time through the existing `KmapsPathResolver`. That decision is still open, and field ordering also differs from D7.

## 4. Staging needs the access fixes repeated

Staging has received none of the 09-25 to 09-28 access work. Dev-0 got the following, and staging and any future environment need the same sequence:
1. The subcollection-access backfill (`drush group:backfill-subcollection-access`).
2. A spot-check of private and UVA nodes across audio, video and image as anonymous and as an authenticated non-member.
3. A scoped Solr re-index through `KmassetDirectSink::indexNode()`, not `kmassets:index-all`. On dev-0 that was 511 UVA-collection nodes, then 255 nodes for the node-override fix.
4. A listings check. This is the PR #259 query filtering, which ships with the code deploy.

The 18 orphaned video nodes, which are anonymously viewable today, also wait on the temporary-review-group migration. That isn't built yet.

---

## User — 2026-10-01 15:07

We only have dev-0, there's no staging server now

---

## Claude — 2026-10-01 15:07

That makes item 4 not applicable today. It's a checklist for when a staging or production environment exists, not outstanding work. I'll save that so future sessions stop treating it as pending.

---

## Claude — 2026-10-01 15:07

I saved a memory note that only dev-0 exists. Future sessions will treat "staging still needs X" as a future checklist and not as pending work.

Item 4 drops off the high-priority list. What's left as real work:

- **Item 3, the `kmassets-uid` identity items.** All three notes are still open. The consumer audit, the ancestor-id decision and the `uid_legacy_s` schema coordination with Dave all come before cutover.
- **Contributor CRUD.** D11 doesn't yet let authenticated users create content within their groups.
- **The 18 orphaned videos.** They're anonymously viewable today and wait on the temporary review group migration.
- **The SAML SP cert.** It's expired and has to be renewed before production.

The item 1 OAuth2 problem is already fixed.

---

## User — 2026-10-01 15:10

Let's check the whole project and see what is still open

---

## Claude — 2026-10-01 15:11

This is a docs-based review of the roadmap, sprints, spikes, ADRs and deferred index. I haven't re-verified any of it against live systems. The deferred index has been stale before (the OAuth2 row, and "staging"), so treat it as a map and not a source of truth.

## Where each track stands

| Track | State |
|---|---|
| **Sprint 1 (Images pilot)** | All tasks done, but the sprint is still marked in progress. No acceptance criteria are ticked. The 1a.9 "staging acceptance run" is gated on a staging server that doesn't exist (see below). |
| **Sprint 2 (theme, Images UI)** | Done 2026-09-04. |
| **Sprint 3 (AV core)** | Almost done. AV10 (Kaltura config layer) is only "scaffolded and verified", and the acceptance criteria (a dozen boxes) are unticked. This is the sprint to close out and tick off. |
| **Sprint 4 (AV transcripts)** | Not started (T1–T6). It needs Spike 11 first, and Spike 11 touches an open scope question that may involve David Germano. |
| **Texts and Sources** | Paused since 2026-09-04 for capacity. Than is back, so whether to resume is undecided. |
| **Mandala Home** | Carousel built. Editable-page mechanism, role-based management and multi-instance config are bookmarked and not designed. |
| **Cutover gate** | Spike 6 is closed. ADR 016 (public URL structure) is still only Proposed. |

## Spikes
- **Open:** Spike 5 (bibcite, partial: credentials and output comparison not started), Spike 8 (reindeer_x, partial), Spike 9 (docs hosting, low priority) and Spike 11 (AV transcripts, pending).
- **Closed:** 1, 2, 3, 4a, 4b, 6, 7, 10 and 12.

## Open work, by area

**Access and identity**
- Contributor CRUD isn't wired in D11, so migrated users can author nothing. It should be done as Group member-role permissions.
- Editor permissions came from D7's group-scoped OG roles. D11's `content_editor` doesn't map onto the real content types, so a sitewide role fix can't be faithful.
- The 18 orphaned videos are anonymously viewable. The temporary review group is decided but not built, and it applies to every site's migration.
- Smaller access gaps:
  - Value-less collections have no default.
  - UVA-only `field_group_access` handling in kmassets.
  - Authenticated fetches through the JSON proxy still arrive anonymous (deferred, Than).
- The SAML SP cert is expired. SimpleSAMLphp sessions expire at 8 hours while Drupal's last 23 days. The IdP logout doesn't end the NetBadge session.

**Solr and kmassets**
- The three `kmassets-uid` items from before (identity strategy, consumer audit, KMaps ancestor ids).
- The search reader is missing 70 docs, and the audit only checks the master. That's Yuji's.
- Production kmassets has taken no writes since 2025-08. That changes what "parity" means at cutover.
- Local DDEV Solr (container, ADR, cleanup command) is assigned to Yuji.
- `kmassets-audit-hardening` and `images-title-sort-s` truncation are smaller items.

**reindeer_x** (all Yuji's):
- It has no ECR repo or pipeline.
- Its ALB target is unhealthy (port 9001 vs 9000).
- It needs an AWS credential strategy.
- The always-on question is unresolved.

**Migration and data fidelity**
- KMaps `raw` format rebuild (High).
- Images authorship is all Anonymous.
- Large migrations run out of memory at the 128M CLI limit.
- Only `shanti_image` URL aliases are covered, which is 32% of the D7 aliases, and the aliases for the other content types need a recorded decision.
- Six AV data-fidelity notes await Than: ISO 639 language conversion (possibly a live search gap for about 3,600 items), PBCore vs Kaltura duration, ordering ties, und/en differences, "Tags Old", and avinfo fields.
- Production migration planning is not done. That covers a fresh extract, the freeze and delta strategy, and the missing-file audit gate.

**Infrastructure and deploy**
- The pre-deploy RDS snapshot gate (Medium now, High before production).
- The drupal image has no mysql client, and the fix isn't merged.
- Config-sync deploy drift: `simplesamlphp_auth.settings` differs on dev-0 and is already known.
- There is no production D11 pipeline yet.

**Team decisions pending** (these need a meeting, not implementation):
- How to validate hand-edited config YAML.
- How to provision local `sites/default/files` for DDEV.

**Process and hygiene**
- Jira integration is deferred, and it's the one that matters for tracking.
- The SearchStax config is defunct but still enabled on D7, and its credential should be treated as burned.
- Cross-environment Solr write targets on legacy D7: production Visuals→staging is still open.

## Things that look stale or need a call
1. **No staging.** Notes referencing staging (the 1a.9 acceptance run, staging prerequisites, the access sequence repeat) should be re-scoped as dev-0 or later-environment items. Sprint 1 can't close on a staging run, so someone needs to decide whether dev-0 serves as the acceptance run.
2. **Status labels lag reality.** Sprint 1 and Sprint 3 headers, the OAuth2 index row, and my own memory snapshot were all behind.
3. **Redundant notes.** Several RESOLVED notes still sit in the Open table, and the docs are heavy to read.

## What I'd suggest picking up next
- **Close Sprint 1 and Sprint 3 formally:** tick the acceptance criteria that are already proven, decide the staging question, and finish AV10.
- **Ask Than** about Texts/Sources vs Spike 11, and the AV4 data-fidelity batch.
- **Build the orphan review group**, since it's decided and leaves content viewable today.
- **Contributor CRUD** is the biggest functional gap for real users.

Do you want me to tidy the stale status labels and move the resolved notes, or write an audit of just the acceptance criteria against evidence?

---

## User — 2026-10-01 15:13

Is Contributor CRUD any part of sprint now?

---

## Claude — 2026-10-01 15:13

No. Contributor CRUD isn't a task in any sprint. I searched the sprint docs and the roadmap, and it appears only as a dependency.

- **Sprint 1 and Sprint 3:** no row covers it. AV7 is access mapping and visibility enforcement, which is about who can *see* content, not who can create or edit it.
- **Sprint 2:** it comes up in two places, both times as something to wait for. The "edit" link on the single-image page and the add-content buttons (Add Existing/New Image, Add Subcollection) are permission-gated on the contributor tier. Sprint 2 chose not to build them without it, and it called the contributor tier a cutover gate.
- **Roadmap:** it isn't a phase item either.

It lives only in `docs/deferred/authenticated-contributor-crud-not-wired-in-d11.md`, marked High. The 09-28 session log lists it as "queued" and pre-cutover, with no sprint or owner. The same session log lists the orphan review group (not built) and the editor-permissions note (`d7-editor-permissions-og-group-scoped-not-migrated`) as queued pre-cutover items too.

So there are two things to decide:

1. **Where it goes.** It isn't attached to Sprint 3, which is nearly closed, or Sprint 4, which is transcripts. It probably needs its own sprint, or a scoped slot in whatever comes next. I'd group it with the orphan review group and the editor-permissions note, since all three are D11 authoring-and-ownership work.
2. **Who owns it.** Than clarified the D7 contributor model, and the note says create has to be group-scoped (Group member-role permissions, not core site-wide `create`). Than and Yuji should confirm that scope before anyone builds it, to avoid another round of rework like the AV7 one.

I can draft a Sprint 5 stub (goal, scope, task rows) for the team to react to. Or I can leave the docs alone and put it on the next meeting agenda. Which do you prefer?

---

## User — 2026-10-01 15:16

Could you list the remaining sprint 3 items?

---

## Claude — 2026-10-01 15:16

**Sprint 3 has no unbuilt task rows left.** What remains is stale status markers and 14 unticked acceptance boxes. This comes from the sprint doc. I haven't checked any of it against code or dev-0.

## Task rows

AV1–AV6, AV8, AV9, AV11–AV16 are marked ✅. Two rows still show ◐, and both read stale:

| Row | Marker | Reality per the doc |
|---|---|---|
| **AV7** (Group access) | ◐ | Built 2026-09-25: UVA tier enforced, 2,027 exposed nodes fixed, 21 access combinations verified, listings fixed in PR #259. It only needs a ✅. |
| **AV10** (Kaltura config layer) | ◐ | The row still says "nothing yet consumes the resolver" and "only one preset seeded". AV9, AV13 and AV12 have since consumed it, and AV13 reconciled the other `uiconf_id`s. It needs a ✅ plus an evidence check. |

## Acceptance criteria (all 14 unticked)

I've marked which ones the docs suggest are met. "Check" means the doc doesn't show the evidence I'd want.

| # | Criterion | Status |
|---|---|---|
| 1 | Exact node count match (11,583: 7,396 video, 4,187 audio) | Probably met: AV4 verified 24/24 on dev-0, and the counts match what I saw locally. Needs ticking. |
| 2 | Kaltura playback live for a real migrated node | Probably met (AV9, AV13). Needs ticking. |
| 3 | Collection membership matches D7 exactly | Probably met (AV4 verification). Check. |
| 4 | Both custom OG access realms mapped and enforced, with real UVA-member and collection-admin tests | Needs rewording. `mb_collection_admin` was dropped as dead on 2026-09-24, and the UVA tier was verified. |
| 5 | KMaps fields wired and indexed in kmassets for AV | Probably met (AV6, AV8: 122,921 nodes in sync). |
| 6 | Corrupted fields excluded and the 68 `MISSING_TYPE` nodes triaged | Disposition decided (exclude all 68). The text already says the criterion needs a reword. |
| 7 | The 18 media-less nodes migrate as-is **and the cleanup list was delivered to AV staff** | Migration done. Delivery to staff is unconfirmed. |
| 8 | Collection and gallery UI uses the shared `shanti-thumbnail` component | Probably met (AV9, AV16). |
| 9 | `field_transcript` downloadable but unprocessed | Probably met (AV4). Check for broken links. |
| 10 | Upload from the node form lands in Kaltura, **verified end to end with a real file large enough to chunk** | Built in PRs #264 and #265. Whether a large-file test was done is unconfirmed, and Than uploaded a test video on dev-0, which is one data point. |
| 11 | Upload is browser-direct, and the browser only gets a short-TTL upload-scoped KS | Built that way, and PR #265 narrowed the KS scope. Check. |
| 12 | All known players render per view mode (the three `uiconf_id`s, plus a fourth added as config only) | The three are probably met. The **fourth, config-only player isn't confirmed**. |
| 13 | Other config elements carried as config, with a recorded decision on `delivery` (no blind RTMP port) | Unconfirmed. I haven't seen a `delivery` decision recorded. |
| 14 | No Kaltura credential in `config/sync` or any committed file | Unconfirmed. This one is a quick `grep`. |

## Likely real leftovers
- **#7:** hand the AV staff list to them, if it hasn't been done.
- **#10:** a large-file chunked upload test, if Than's test video wasn't one.
- **#12:** the fourth-player config-only proof.
- **#13:** a recorded `delivery` decision.
- **#14:** a credential grep.
- **Related, not Sprint 3 rows:** the 18 orphaned videos and the six AV data-fidelity notes for Than.

I can run the checks for #14, #12 and #13 now, since they're quick and read-only. #10 and #7 need the team. Want me to start with those three?

---

## User — 2026-10-01 15:20

Is everything deployed on dev-0?

---

## Claude — 2026-10-01 15:20

The pipeline says yes. I'll also check dev-0's live state directly with a read-only call.

---

## Claude — 2026-10-01 15:21

Yes, everything is deployed on dev-0. I checked the pipeline and then read dev-0 directly.

- **Drupal app:** The last deploy was pipeline run `c8423700` on 2026-09-29 for PR #265, and it succeeded. That's the last commit that touched `drupal/**`, `package/**` or `pipeline/**`. The container is running image `build-20260929154154` and has been up 2 days.
- **Merges after that:** PRs #266 and #267 only touched docs and scripts, so they correctly didn't trigger a deploy.
- **Config:** The only drift is `simplesamlphp_auth.settings`, which is the known gap. The AV12 form displays are live. `field_video` on the video form is now `kaltura_upload`, the same change I imported locally.
- **Database updates:** none pending.
- **Solr proxy:** Its image is from 2026-08-19, and nothing under `solr-proxy/` has changed since. It's a build-only pipeline that needs a manual drupal trigger, but there's nothing to ship.

Two things I didn't check. I didn't confirm the deployed container's git SHA directly. I matched the image build time (15:41 UTC) to the pipeline start (11:40 EDT) instead. I also haven't confirmed that the data work from the access fixes (the backfill and the scoped re-index) is still intact. The notes say both were done and verified on dev-0 on 09-28.

---

## User — 2026-10-01 15:22

Let's try to close out AV3

---

## Claude — 2026-10-01 15:22

AV3 is already closed. Its row is marked ✅ Done 2026-09-08. It built 15 paragraph types (84 field storages, 87 instances, 186 config files), with nesting verified live and the model documented in `planning/av-paragraph-model.md`. The follow-on work (AV4's migration, AV15's display, and two paragraph bugs fixed corpus-wide on 09-17) all landed on top of it. I didn't find anything open in the note except the general hand-edited-YAML drift caveat, which has its own deferred item.

You may have meant one of the two rows that still show ◐:
- **AV7:** it needs the status flipped to ✅ and the "Remaining" text trimmed. The work itself is done and verified.
- **AV10:** the row text is stale, with "nothing yet consumes the resolver" and "only one preset seeded". Closing it means checking that the fourth, config-only player (#12) and the `delivery` decision (#13) actually exist.

Which one did you mean? If it's AV10, I'd start with the read-only checks for #12, #13 and #14.

---

## User — 2026-10-01 15:22

Sorry it's sprint3 , not AV3

---

## Claude — 2026-10-01 15:23

I'll start with the read-only checks on the unconfirmed criteria.

---

## Claude — 2026-10-01 15:23

The checks turned up four real gaps and a few wording problems. I'll apply the edits the evidence supports and leave the rest unticked with notes. First I need the exact text.

---

## Claude — 2026-10-01 15:23

I overstated a few specifics in that block, so I'll correct them before moving on.

---

## Claude — 2026-10-01 15:24

The counts I wrote are off. It's 11 ticked and 3 open, not 10 and 4.

---

## Claude — 2026-10-01 15:24

I've edited `docs/sprints/sprint-03-av-core-implementation.md` on a new branch, `docs/sprint-03-closeout`. Nothing is committed or pushed yet.

## What changed
- **AV7 and AV10 are now ✅.** AV10 keeps its original note, labelled as historical.
- **11 of 14 acceptance criteria are ticked.** Each tick names its evidence, such as AV4's 24/24 dev-0 verification or the committed config.
- **Two criteria are reworded, not just ticked:**
  - **#4:** `mb_collection_admin` was dropped as dead on 2026-09-24, so only the UVA tier is left.
  - **#14:** secrets live in Drupal State via the admin form, not the container environment. That was Than's call during AV11.
- **The sprint stays ◐ in progress.** The header and README still say that, and the header now records what's left.

## Checks that turned up something
- **#11, browser-direct upload:** confirmed in the code. `kaltura-upload.js` posts straight to Kaltura's `api_v3` with a minted session token. The admin secret never leaves the server.
- **#13, the `delivery` decision:** recorded. `delivery: HTTP` is in `mandala_kaltura.settings.yml` with the reasoning.
- **#14, credentials in the repo:** my grep found only code and labels, no secret values.
- **#14, side effect:** State lives in the database, so DB dumps and snapshots contain the Kaltura secret. That affects the "ship a dev dump" workflow.

## Three still open (they need people)
1. **#7:** nothing records the 18-node cleanup list reaching AV staff.
2. **#10:** nothing records a deliberate chunking-sized upload. The only end-to-end success was an accidental junk one, plus Than's test video on dev-0 whose size isn't recorded. If Than's video was large enough, this can be ticked.
3. **#12:** the criterion expects three players plus a fourth added as config. `config/sync` has only the one correct `default` player. The question for Yuji or Than is whether to reword it to "one correct player, more addable as config", or to add the other presets. I'd reword it, because AV13 found `24762821` isn't a playback player at all.

## Next steps
Do you want me to commit and open a PR for this? Or first send questions #7, #10 and #12 to Than and Yuji, so the sprint can close in the same PR?

---

## User — 2026-10-01 15:26

How large the video need to be in #10?

---

## Claude — 2026-10-01 15:26

A file over 5 MB splits into at least two chunks. For the test to count, I'd use about **15–25 MB**. That is a recommendation, not something the docs state.

`js/kaltura-upload.js:37` sets `CHUNK_SIZE = 5 * 1024 * 1024`, so the file is sliced into 5 MB pieces. The count is `ceil(size / 5 MB)`, so:

| File size | Chunks | What it exercises |
|---|---|---|
| Under 5 MB | 1 | Not chunking. The first chunk is also the last. |
| 5–10 MB | 2 | First and last chunk only. |
| Over 10 MB | 3 or more | First, at least one middle chunk, and last. |

**Why at least 3 chunks.** `uploadChunk` takes `resume` and `finalChunk` flags and a `resumeAt` offset. They differ for the first chunk, the middle chunks (`resume=true`, `finalChunk=0`, `resumeAt=start`) and the last chunk (`finalChunk=1`). Only a run with a middle chunk exercises the offset logic. A 25 MB file gives 5 chunks, which is plenty.

**Upper bound.** The minted Kaltura session lasts 3,600 seconds (`SESSION_TTL_SECONDS`). A 25 MB file finishes well inside that. A very large file on a slow link is a separate risk, and the criterion's wording ("large enough to exercise chunking") doesn't require it.

**Two more things for the test:**
- **Playable afterwards.** The criterion also needs the `entryId` saved on the node and the media playing, and Kaltura has to finish transcoding first. A small real clip, such as a 20 MB MP4, covers both.
- **Test content.** Use a throwaway node and delete the Kaltura entry afterwards. The accidental upload during PR #265 testing was junk data that Than had to clean up.

If Than's dev-0 test video was over about 10 MB, #10 may already be satisfied. His file size is what decides it.

---

## User — 2026-10-01 15:27

Than tested a 10MB and he is going to test a 27MB video.

---

## Claude — 2026-10-01 15:28

A 10 MB file is two chunks, so it didn't cover the middle-chunk path. `ceil(10,000,000 / 5,242,880)` is 2, and even exactly 10 MiB (10,485,760 bytes) is 2. It checked the first and final chunk handling but never the `resume=true`, `finalChunk=0` offset case. The 27 MB test will cover it. At 5 MiB per chunk that's 6 chunks, with four of them middle chunks.

To make the result usable as evidence for #10, ask Than to record:
- the file size and the chunk count shown in the progress UI ("Uploading… n/6");
- the resulting `entryId`, and that it's saved on the node after Save;
- that the media plays back after Kaltura finishes transcoding;
- that the Kaltura entry is cleaned up afterwards, if it's test data.

If that passes, I can tick #10 and cite it. For the 10 MB run, I'd add a note saying it only covered the two-chunk case.

I'll leave the branch uncommitted until Than's result and the answers on #7 and #12 are in, so the sprint can close in one PR. If you'd rather have the reconciliation merged now, I can open the PR with those three boxes still open.

---

## User — 2026-10-01 15:29

Can you explain #12: the criterion expects three players plus a fourth added as config.

---

## Claude — 2026-10-01 15:29

The criterion (#12) was written on a premise that AV13 later disproved.

## Where the criterion came from
When Spike 7 and the sprint were scoped, the D7 data showed three different player ids (`uiconf_id`s), and the sprint assumed three players would need to coexist:

| `uiconf_id` | Where it showed up in D7 |
|---|---|
| `31832371` | The video field's default display setting, and hardcoded in the React app |
| `48501` | A second formatter / instance-widget setting on both fields, and the contrib module's own default |
| `24762821` | The live node-view embed that Spike 6 captured |

The sprint concluded that a single site-wide default wasn't enough, so it needed named presets selectable per view mode. The "fourth added as config only" clause was an extensibility test: add a new player as a pure config entry, with no code change, and see it render.

## What AV13 found
- **`31832371` is the real playback player.** The React app and D7's own field display independently agree. Both audio and video use it, and there's no per-bundle split.
- **`24762821` isn't playback.** It's `MB_MAIN_PLAYER_ID`, used only by a share/embed-redirect endpoint. AV4's migration borrowed it by mistake, and it was live on dev-0 until AV13 corrected it on 2026-09-17.
- **`48501` was never traced.** It's the contrib module's default in the widget settings, and nothing says it plays anything on the live site.

So the criterion's "three confirmed players, each verified against a real entry" doesn't hold. Only one is real playback. One of the three would be a wrong player to ship.

## What's in the repo
`mandala_kaltura.settings.yml` has one preset, `default` (`31832371`). The registry is a keyed `presets` map, so adding more is a config-only change. That has never been exercised, so the "new players need no code change" claim is untested end to end.

## Options
1. **Reword and tick.** "The correct primary player renders, and further presets can be added as config." The extensibility claim stays unproven, or I prove it with a throwaway preset.
2. **Keep it and make it true.** Add `48501` as a named preset and verify it against a real entry. Add a fourth as config, and check it renders. This only makes sense if something needs those players, such as a distinct view mode or the upload-preview player.
3. **Reword plus a cheap proof.** I add a second preset locally in DDEV and render a node with it, which confirms the "config only" claim, and then tick the reworded criterion.

I'd go with option 3. It closes the criterion honestly and costs little. Option 2 means adding players to the site that the site doesn't actually use. The decision belongs to Yuji, who owns the AV10/AV13 design, and Than, who confirmed the D7 behaviour.

Should I do the local proof for option 3?

---

## User — 2026-10-01 15:33

Than's test of uploading 27MB video is succeed. also do the local proof for option 3

---

## Claude — 2026-10-01 15:33

I'll start with how a preset gets selected, so the proof exercises the real path.

---

## Claude — 2026-10-01 15:34

The script was printed as text because it had no `<?php` tag, so nothing ran and the config is untouched. I'll add the tag and rerun.

---

## Claude — 2026-10-01 15:34

The proof worked. Now I'll revert the DB-only change and confirm nothing is left behind.

---

## Claude — 2026-10-01 15:34

Both results are in. I'll record them in the sprint doc.

---

## Claude — 2026-10-01 15:34

Both are done, and 13 of 14 Sprint 3 acceptance criteria are now ticked. Nothing is committed yet.

## #10, upload (ticked)
Than's 27 MB upload succeeded. It's about 6 chunks, so the middle-chunk path ran. His earlier 10 MB test covered only the first and final chunks, and I recorded both in the doc.

## #12, local proof (done, reworded and ticked)
I ran the proof in DDEV on migrated video node 115528 with no code change:

| Preset | Result |
|---|---|
| `default` | `uiconf_id/31832371`, player width 880 px |
| `proof_alt`, added to the active config only (`uiconf_id` 48501, 410×364) | `uiconf_id/48501`, player width 410 px |
| unknown preset id | Logged an error and rendered nothing (fails closed) |

- **Cleanup:** I reverted it with `config:import`. `config:status` is clean, no `proof_alt` remains, and nothing was exported or committed.
- **Stale value on the node:** the node's stored `uiconf_id` is still `24762821`, the old AV4 constant. The formatter ignores it and uses the preset, so rendering is correct. Anything that read the stored field directly would see the wrong player.
- **Rewording:** I reworded #12 to "a correct primary player renders, and new players are added as config only", with the proof as its evidence. The decision to reword is Yuji's and Than's to confirm.

## What's left
Only **#7**: the 18-node cleanup list reaching AV staff. Do you know whether it was ever handed over? If it was, I can tick it and note who and when, or it can wait for Than. Once #7 is settled I can flip Sprint 3 to ● Done, update the README row, and open the PR. I haven't flipped the status yet.

---

## User — 2026-10-01 15:36

For #7, defer 18-node cleanup to later

---

## Claude — 2026-10-01 15:36

The `sed` failed because macOS needs a different `-i` form, so I'll do the README row another way.

---

## Claude — 2026-10-01 15:37

Sprint 3 is marked ● Done (2026-10-01) in the docs, with the 18-node cleanup deferred. The changes are on branch `docs/sprint-03-closeout` and nothing is committed yet. The session-close check's index checks pass: the new deferred note is listed in both the README and `.pages`. Its only failure is that the changes are uncommitted.

## What changed
- **New deferred note:** `docs/deferred/av14-media-less-nodes-staff-handoff.md`. It records:
  - the decision to defer;
  - why it can wait (the nodes behave as they do on D7, and nothing in D11 depends on staff acting);
  - what closes it, which is staff giving a disposition per node;
  - that the owner is unassigned.

  It doesn't repeat the nid list. That stays in the existing disposition note, because the repo is public.
- **Criterion #7:** ticked. The migration half is done, and the staff handoff is deferred and tracked in the new note. I worded it so the tick can't be misread as "staff have the list."
- **Sprint 3:** the status header and the `sprints/README.md` row now say ● Done (2026-10-01). All 14 acceptance criteria are ticked.
- **Deferred README and `.pages`:** the new note is indexed in both.

## Other things
- **#12 reword:** the reworded #12 (one correct primary player, extra players as config) is a recorded deviation from the original criterion. Yuji and Than should confirm it.
- **Memory refresh:** `project-mandala-state` hasn't been refreshed this session, which the close ritual calls for.
- **Session log:** I haven't run `save-session-log.py`.
- **Sprint 1:** it still shows in progress. It can't fully close on the 1a.9 staging run, because there's no staging server.

Do you want me to commit this, push, and open the PR, and then refresh the memory snapshot?

---

## User — 2026-10-01 15:38

Yuji and Than are here and confirmed #12 reword

---

## Claude — 2026-10-01 15:38

I've recorded in the criterion #12 line of the sprint doc that Yuji and Than confirmed the reword on 2026-10-01. That settles the deviation.

Nothing is committed yet. Do you want me to commit the changes on `docs/sprint-03-closeout`, push, and open the PR? I'd write the PR description to cover the Sprint 3 close-out, the #12 reword confirmation, and the new deferred note.

---

## User — 2026-10-01 15:39

close out sprint 1, because there's no staging server yet. it should be deferred until there's staging server

---

## Claude — 2026-10-01 15:40

I've closed Sprint 1 in the docs, on the same `docs/sprint-03-closeout` branch. Nothing is committed yet.

## What changed
- **Sprint 1 header and README row:** now ● Done (2026-10-01). The old text saying "none of the criteria are ticked" was stale, and I kept it as struck-through history. All 8 acceptance criteria were already ticked, evidenced on dev-0 under the 2026-08-25 scope decision.
- **`staging-migration-execution-prerequisites.md`:** priority changed from High to "Deferred until a staging server exists". I added a banner saying what Sprint 1 did and didn't prove, and its row in the deferred README now says the same.
- **`1a9-staging-acceptance-checklist.md`:** a banner says its unticked items aren't Sprint 1 blockers.

## What was deferred
- **Promotion rehearsal:** a rehearsal between two D11 environments.
- **Config overrides:** the per-environment config-override mechanism.
- **Production rollout:** both should also be revisited before production rollout.

## Sprint 1 closing action
Sprint 1's "On close" step says to start the [Jira issue-tracking integration](docs/deferred/jira-issue-tracking-integration.md) and backfill the open deferred notes as tickets. I haven't started that. It's now unblocked, and it's a team decision.

## Where the branch stands
The branch holds both sprint closes, plus the new AV14 deferred note. The session-close check's index checks pass, and the only failure is that the changes are uncommitted.

Do you want me to commit, push, and open the PR now? I'd write it as "Close Sprints 1 and 3", and then refresh the memory snapshot.

---

## User — 2026-10-01 15:41

commit, push, and open the PR

---

## Claude — 2026-10-01 15:41

The PR is open: https://github.com/uvalib/mandala-navina/pull/268 ("docs: close out Sprints 1 and 3"). It's one commit on `docs/sprint-03-closeout`, and it only touches `docs/`, so merging won't trigger a deploy.

I asked for review on the wording of Sprint 3 criteria #4 and #14 and of the Sprint 1 deferral. Yuji and Than have already confirmed #12.

I haven't refreshed the `project-mandala-state` memory snapshot, which the session-close checklist calls for. It still describes 2026-09-24. Do you want me to update it now? It would cover:
- Sprints 1 and 3 closed.
- The AV11/AV12 uploads done.
- The deferred AV14 handoff and the staging run.
- PR #268 open.

---

## User — 2026-10-01 15:42

yes, update the memory snapshot and write the session log. we are ready to close this session

---
