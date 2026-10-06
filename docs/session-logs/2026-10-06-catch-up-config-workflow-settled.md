# Session Log: Catch Up Config Workflow Settled

**Date:** 2026-10-06  
**Participants:** Yuji Shinozaki, Claude Code (Xiaoming Wang and Than Grove present for the discussion; Than handled PR #279 separately)  
**Outcome:** Local caught up to `main`; config workflow settled and merged ([PR #280](https://github.com/uvalib/mandala-navina/pull/280), [PR #281](https://github.com/uvalib/mandala-navina/pull/281), CLAUDE.md `96312e7`); **agenda item 1 of the project-status review closed, items 2 onward not yet discussed**. See [config-export-drift-hand-edited-yaml.md](../deferred/config-export-drift-hand-edited-yaml.md) and [migration-config-rationale.md](../dev-notes/migration-config-rationale.md).

## PICK UP HERE: group agenda item 2 (at the next joint meeting)

Next time Yuji, Xiaoming and Than are together, resume the status-review agenda at **item 2**.
Items are listed in the order we meant to take them; none has been discussed yet.

2. **Local files mechanism**
   ([local-dev-files-provisioning-mechanism.md](../deferred/local-dev-files-provisioning-mechanism.md)).
   Plan agreed 2026-10-05: a report-only audit step in the session-start check, plus a short
   `stage_file_proxy` trial on Xiaoming's DDEV (`stage_file_proxy` is now installed on every DDEV
   via #276). Decisions still needed: full parity vs the narrow set of fields real features
   render, and on-demand vs automatic.
3. **dev-0 durability and the SAML playbook.** `/mnt/data` (uploads, SAML directories, OAuth
   keys, DB checkpoints) is on the unbacked root volume (judged low impact); `deploy_netbadge.yml`
   treats a missing decrypted SAML key as a warning, not a failure. Fix now, or accept?
4. **Where legacy files live after production is retired** (cutover planning).
5. **Owner for the `mandala-av-transcripts` Solr core** (Spike 11): the design is done, but
   nobody has created the core on dev-0 or written `solrconfig.xml`.
6. **Meatier design sessions, decide whether each needs its own session:** D7 editor
   permissions (OG group-scoped, not migrated), and `solr-sync-architecture-d11.md` (High, open
   since June, no owner; check whether it is still live).

Not for the group, already owned: **Than** has PR #279 (Kaltura delete checkbox, unreviewed),
the orphan sweep on dev-0, the AV technical-metadata audit and the Spike 11 analyzer questions;
**Yuji** has `reindeer_x` always-on and its ALB defect.

Open at close: deploy `cdfb3ffa` (for #281) was still building; check
`./scripts/deploy-status.sh drupal` before assuming it passed. Local DDEV is on `main`, config
clean, DB matches dev-0.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-06 14:52

claude

---

## Claude — 2026-10-06 14:52

Starting the session with the startup check from CLAUDE.md.

---

## Claude — 2026-10-06 14:54

Startup check: git is current, but the local config has drifted from the committed config and I couldn't compare against dev-0.

- **Git:** `main` is up to date and the tree is clean. `drupal/config/sync` matches `origin/main`.
- **Config drift (fails):** `ddev drush config:status` shows differences.
  - Only in the sync directory: `admin_toolbar.settings`, `module_filter.settings`, and the `field_is_review_holding` field and storage on the `collection` and `subcollection` groups.
  - Different between DB and sync: the group collection and subcollection form and view displays, `core.extension`, the group member roles, and `user.role.authenticated`.
  - This fits the 2026-10-05 session log, whose title is "core update, admin toolbar, module filter and orphan sweep". Those changes landed in `main` but haven't been imported into this local DB.
- **dev-0 (skipped):** SSH to dev-0 timed out, so the content and identity comparison didn't run. It looks like the VPN is off.
- **Local counts:**

  | Item | Count |
  |---|---|
  | users | 1543 |
  | authmap | 1385 |
  | groups | 408 |
  | audio nodes | 4187 |
  | video nodes | 7396 |
  | image nodes | 111340 |

- **stage_file_proxy:** not installed locally.

The most recent session log is `2026-10-05-core-update-admin-toolbar-module-filter-and-orphan-sweep.md`. I haven't read it, the ADR, spike or deferred indexes yet, since the task isn't set.

Since the config drift is the committed config being ahead of the local DB, `ddev drush config:import` is the likely fix. It changes your local DB, so I haven't run it. Do you want me to run it, or would you rather connect the VPN and rebase from dev-0 with `./scripts/update-db-from-remote.sh dev`? What are you working on this session?

---

## User — 2026-10-06 14:55

git pull to get all the recent updates if you haven't already

---

## Claude — 2026-10-06 14:55

Already up to date after a fresh fetch. The earlier startup script had already pulled. `main` is at `8b8e2c3`, which includes PRs #276 (core update, admin toolbar and module filter), #277 (authenticated contributor tier for images) and #278 (SimpleSAML local logout doc fix).

The local DB config drift from earlier still stands. Do you want me to run `ddev drush config:import` to bring it in line with these changes?

---

## User — 2026-10-06 14:55

yes, run the config import

---

## Claude — 2026-10-06 14:57

The config import succeeded and `config:status` now reports "No differences between DB and sync directory."

The first attempt failed because `module_filter` wasn't installed locally. `ddev composer install` pulled it in, and the retry went through cleanly.

The dev-0 content comparison is still pending, since the VPN looked to be off.

---

## User — 2026-10-06 15:02

check the VPN npw

---

## Claude — 2026-10-06 15:03

The VPN is up and dev-0 is reachable. The comparison found one difference:

- **Video nodes:** local has 7,396 and dev-0 has 7,398, so local is 2 behind.
- **Everything else matches:** users, authmap, groups, group relationships, audio and image nodes are identical. The 25 sampled legacy-identity pairs have the same D11 nids in both environments.
- **Config:** clean, and `drupal/config/sync` matches `origin/main`.

Two video nodes is a small gap. The script's fix is `./scripts/update-db-from-remote.sh dev`, which replaces the whole local DB. Take a `ddev snapshot` first if you have local-only content. I'd skip the rebase unless you need those two nodes, since it's heavy for a 2-node difference and everything else lines up.

Do you want the rebase, or should we move on? What are you working on?

---

## User — 2026-10-06 15:05

skip the rebase, let's move on.   Really I'm just catching my local up, and scanning the project status.  What is your assessment of next steps?

---

## Claude — 2026-10-06 15:05

Here's my assessment, based on the deferred index, the most recent session log and the two PRs merged since. I only skimmed the spike and ADR indexes, and the deferred index output was truncated, so treat it as a first pass.

**Where things stand**
- **Sprints 1 and 3 are closed.** AV access enforcement and listings visibility are merged and verified on dev-0.
- **The orphaned-content review group (#275) is merged.** AV and Images are swept locally. Texts and Sources wait on their migrations. dev-0 has no review groups yet.
- **Core and contrib are updated (#276).** That PR also added Admin Toolbar and Module Filter, and a dev-only `stage_file_proxy`.
- **PR #277 is merged:** the authenticated contributor tier for Images.
- **Still on dev-0 only.** There's no staging or prod yet.

**Where I'd go next**
1. **Orphan sweep on dev-0.** It's the last step of an already-merged feature. Someone creates the review groups by hand on dev-0, then runs the sweep with `--gid`. Because it changes live data, do the `--dry-run` first.
2. **Local files gap and `stage_file_proxy`.** This is Medium-High, and the plan was agreed on 2026-10-05: add a report-only audit to the session-start check, plus a short trial of `stage_file_proxy`. #276 already did the groundwork. It needs a team decision on mechanism before more implementation.
3. **Config CI check (Option 2 in the deferred note).** Hand-edited config YAML has broken deploys twice, and my own `config:export --diff` mistakes in the 2026-10-05 session show the same hazard. A pre-merge `cim` and `config:status` check would catch it. This is waiting on a team decision.
4. **Spike 11, the AV transcripts Solr core.** The design is done and no core exists yet. Someone needs to own standing up `mandala-av-transcripts` on dev-0 and writing `solrconfig.xml`. It's assigned to Than.
5. **Contributor CRUD for authenticated users.** #277 covers Images. I'd check whether the other asset types follow.
6. **The meatier architecture gaps.** D7's editor permissions were scoped per OG group and haven't been migrated (High). There's also the High `solr-sync-architecture-d11.md` note, which has no owner. Both need design sessions rather than quick fixes.
7. **Cutover prerequisites.** These include `av14-media-less-nodes-staff-handoff.md`, the pre-deploy RDS snapshot gate, and `reindeer_x` and its unhealthy ALB target (assigned to you).

For your own catch-up, local is now clean and in sync apart from the 2 video nodes. I'd check what those 2 videos are, since they may be new migrations on dev-0 that you haven't pulled. I can look into them, or read the latest session log in full if you want.

---

## User — 2026-10-06 15:09

What's the situation with Sprint 2?

---

## Claude — 2026-10-06 15:09

Sprint 2 is closed: the sprint index marks it **Done on 2026-09-04**. The title is "D11 base theme, Images interactive UI, other-asset-type groundwork".

**What it delivered**
- **A (theme):** the D11 base theme is installed on dev-0.
- **B1:** OpenSeadragon deep-zoom viewer (PR #170).
- **B2:** the whole `shanti_image` page, not only the carousel. This covers the AJAX sibling carousel, the page layout, the action-icon row (edit, download-size dropdown) and a 12-field technical-metadata modal (PR #187).
- **B3:** masonry gallery grid.
- **D1 and D2:** an entity-access endpoint convention doc, and Spike 12 on authenticated asset fetch.
- **Content-model audits:** AV, Texts and Sources audits were done, which fed Sprint 3 and ADR 018.

**What it left open**
- **Spike 12:** it recommends a direction, a new `mandala_solr_sid:{sid}` Redis key in the shared ADR 014 instance, but it was design only. Building it is deferred as low priority.
- **`field_private_note`:** it's gated on `can_edit`, but D11 has no real equivalent of D7's field permission wired yet.
- **The sprint doc's own "real open questions / new work" section (around line 544):** I haven't read it. It may hold more.
- **Neither of these is a Sprint 2 loose end:** PhotoSwipe was dropped by Than on 2026-09-04, and the D7 multi-image sequence viewer was an unfinished prototype.

The sprint is closed, so the numbering that matters now is Sprint 4 (AV transcripts, still ○ Planned and gated on Spike 11). I can read that open-questions section if you want the full list.

---

## User — 2026-10-06 15:12

go ahead and update the docs to note that Sprint 2 is closed.

---

## Claude — 2026-10-06 15:12

The planning doc is the only place still stale, so I'll update its status line.

---

## Claude — 2026-10-06 15:12

Sprint 2 was already marked closed in two places, so I only had to fix one stale doc.

- **Already correct:** `docs/sprints/README.md` lists it as "Done (2026-09-04)". The sprint doc itself says "Complete (2026-09-04)".
- **Stale, now fixed:** `docs/planning/sprint-02-planning.md` still said "Planning draft — implementation has not started". It now reads as a historical planning record, with a link to the sprint doc.

I committed that one change on a new branch, `docs/sprint-2-planning-status-closed`, and pushed it. It's docs only and I didn't open a PR. Do you want me to open one, or merge it straight to `main` as the earlier docs-only status updates were?

---

## User — 2026-10-06 15:12

merge it to main

---

## Claude — 2026-10-06 15:12

The Sprint 2 planning-doc update is merged to `main` (`cddffac`) and pushed. It was a fast-forward, so there was no merge commit. I deleted the branch locally and on the remote.

The change is docs only, so it won't trigger a deploy.

---

## User — 2026-10-06 15:13

What are the 2 extra video nodes on dev-0?

---

## Claude — 2026-10-06 15:14

The two extra video nodes on dev-0 are **editor-created content, not migrated from D7**. Here's what the comparison found:

| dev-0 nid | Title | Created | Published |
|---|---|---|---|
| 122924 | Tibetan Doctor Discussing Allergies | 2026-09-29 11:16 | yes |
| 122925 | Tibetans Discussing Altitude Sickness | 2026-10-01 11:29 | yes |

- **No legacy identity.** Both have empty `field_legacy_site` and `field_legacy_nid`. That's why they're missing locally, since they were never part of a migration.
- **Created after the migration.** Both were made on dev-0 by hand, on 2026-09-29 and 2026-10-01.
- **Nothing is only local.** Every local video node exists on dev-0.
- **Probably deliberate.** The titles look like a series of health-related videos, possibly test or demo content someone made while trying out AV authoring on dev-0. I haven't checked who created them.

I only ran read-only queries, so nothing changed on dev-0 or locally. The 2-node drift is real content that exists only on dev-0, and a rebase would pull it in. I can look up who authored them on dev-0, if that matters.

---

## User — 2026-10-06 15:14

Than said they were just tests he ran on dec-0

---

## Claude — 2026-10-06 15:14

That explains the 2-node drift. Those were Than's test nodes on dev-0, so the local DB doesn't need them and the skipped rebase was the right call.

Because they have no legacy identity, the session-start count check will keep flagging them as a video-count mismatch until someone deletes them on dev-0. If you want, I can add a note about this to the deferred docs, or leave it. I wouldn't delete them from dev-0 without Than saying so.

---

## User — 2026-10-06 15:15

Than's here (as well and Xiaoming).  And he's deleting them from dev-0

---

## Claude — 2026-10-06 15:15

Good. Once they're deleted, dev-0 should be back to 7,396 video nodes and the start check's count comparison should pass. I can re-run `./scripts/session-start-check.sh` after Than's done to confirm. Nothing else is pending on my side.

---

## User — 2026-10-06 15:16

So re-assess what is available to work on.   Especially anything that needs group review.

---

## Claude — 2026-10-06 15:17

Dev-0 now matches local exactly. The video counts agree, so Than's test nodes are gone and everything else passes. Here's what's available, grouped by whether it needs the three of you.

## Needs group review or decision (all three of you are here)

1. **Config workflow, still open:**
   - **CI check (Option 2).** Should a pre-merge `cim` and `config:status` check exist? It would have caught both deploy failures.
   - **Folding the practice into CLAUDE.md.** Yuji stated "change in live Drupal, then export, no hand-edits" on 2026-10-05, but CLAUDE.md is unchanged until Xiaoming and Than agree. This one is quick.
   - **The 296 comment lines in `config/sync`.** Where do they go? No destination is chosen.
2. **Local files mechanism.** The plan was agreed on 2026-10-05: a report-only audit in the session-start check, plus a `stage_file_proxy` trial on Xiaoming's DDEV. #276 already put `stage_file_proxy` on everyone's DDEV. Xiaoming can run the trial now, and the audit step is still unbuilt. The remaining decisions are parity versus a narrow scope, and on-demand versus automatic.
3. **dev-0 `/mnt/data` durability.** Do you fix the unbacked root volume, or accept it? The impact was judged low. Together with the `deploy_netbadge.yml` finding (a missing SAML key only warns), it needs a group call on whether to act before there's a staging or prod.
4. **Where legacy files live after production is retired.** This is a cutover-planning question and needs a decision before the cutover.
5. **Carousel demo churn.** The group deferred it until closer to production deployment, so there's nothing to do now.

## Assigned to someone, not group work

- **Than:**
  - Spike 11 open items: the analyzer, stemming, and the transcript file accounting.
  - The AV technical-metadata field audit.
  - The review-flag inheritance question.
  - A dev-0 orphan sweep, once review groups exist there.
- **Yuji:** the `reindeer_x` always-on question and its ALB defect, and the Solr pipeline cost loop-closing with Dave.

## Meatier items needing their own design session

- **D7 editor permissions.** They were OG group-scoped and haven't been migrated (High).
- **`solr-sync-architecture-d11.md`.** It's High, open since June, and has no owner. It's worth deciding whether it's still live.
- **Spike 11's Solr core.** Nobody has created `mandala-av-transcripts` on dev-0 or written `solrconfig.xml`.

My suggestion for the group is to start with items 1 and 2. They're concrete, they've been decided in part, and they unblock work. After that, settle who owns the Solr core, and decide whether the editor-permissions design can wait for its own session. What are you leaning toward?

---

## User — 2026-10-06 15:18

Let's discuss these one by one.   Explain 1

---

## Claude — 2026-10-06 15:18

Item 1 is about keeping bad config YAML from reaching `main`. It splits into three questions.

## Background

Drupal config changes are meant to be made in a running Drupal, then exported with `drush config:export`. Hand-editing the YAML is where things have gone wrong.

- **PR #177 (2026-09-02).** A hand-edit left a view display's `content:` keys in the wrong order. Drupal sorts them alphabetically when it saves, so the file differed from what Drupal would write. The deploy pipeline's drift guard compares by exact value and failed the deploy.
- **PR #209 (2026-09-15).** The same failure, and this time it **failed dev-0's real deploy pipeline**. The bad commit had already merged, and the webhook deployed it.
- **Why it matters.** The guard does its job, but it fires after merge, on `main`, and blocks the pipeline for everyone. A PR check would catch it while the author still has context.
- **The norm didn't stop it.** The second incident came on the same day the sibling hazard, `config:export` being unscoped, was documented.

## The three decisions

**1a. Add a CI check on PRs that touch `config/sync` (Option 2).** CI would boot Drupal, run `cim`, then `config:status`, and fail the PR if anything differs.
- **For:** it would have caught both incidents before merge.
- **Against:** it needs a DB fixture or a throwaway Drupal boot, and it adds CI time and complexity.
- **Alternatives, cheaper and weaker:** wire the existing `scripts/config-check.sh` into a pre-commit hook, or accept the occasional blocked deploy (Option 4).
- **My view:** two real incidents plus my own `config:export --diff` slip-ups on 2026-10-05 make the case for CI. The cost is unknown, so it's worth checking how heavy the CI boot really is before deciding.

**1b. Write the practice into CLAUDE.md.** The practice is: change config in live Drupal, then export, with no hand-edits except a single scalar value. Yuji stated it on 2026-10-05, but CLAUDE.md is unchanged until Xiaoming and Than agree. This one is quick, and it matters because the CLAUDE.md rules are what each Claude session follows.

**1c. Where do the 296 comment lines in `config/sync` go?**
- **The problem:** 16 YAML files hold real `#` comments, and `config:export` silently strips them. The new rule is "no comments in config/sync".
- **The open part:** nobody has said where those comments should live. Candidates are the deferred docs, a README beside the config, or just deleting them.
- **The payoff:** once they're moved, `config-check.sh --strict` can fail on any comment an export would strip.

## Suggested order

Settle 1b first, since it's agreement on something already stated. Then 1c, a small decision plus a mechanical change. Then 1a, which is the only one that costs real effort.

Do you want to start with 1b? I can pull up the exact CLAUDE.md wording I'd propose.

---

## User — 2026-10-06 15:23

1b yes add that to Claude.md

---

## Claude — 2026-10-06 15:23

I added a new section to `CLAUDE.md`, "Config changes: export from live Drupal, never hand-edit". It's committed and pushed on the branch `docs/claude-md-config-export-practice` (`96312e7`), but not merged.

**What it says**
- Make config changes in live Drupal, then export.
- `git diff` after the export and revert anything unexplained.
- Require a clean `config:status` before pushing.
- The only allowed hand-edit is a single scalar value.
- No `#` comments in `config/sync`.

**Two things I added beyond Yuji's four points**
- **`--diff` warning:** `drush config:export --diff` still writes. For a safe preview, use `scripts/config-check.sh` or `--destination=<temp dir>`.
- **No-comments rule:** I put it in the section as rule 5. The no-comments rule was stated on 2026-10-05, but its inventory is unresolved (item 1c). If you'd rather not commit to it until 1c is settled, I'll drop that line.

Since this changes the rules every Claude session follows, and Xiaoming and Than haven't confirmed it yet, I haven't merged it. If they're happy with it, I'll merge it to `main` as before, or open a PR first if you'd prefer.

---

## User — 2026-10-06 15:24

merge it to main

---

## Claude — 2026-10-06 15:25

The `CLAUDE.md` change is merged to `main` (`96312e7`) and pushed. It was a fast-forward, and I deleted the branch locally and on the remote. The change is docs only, so it won't trigger a deploy.

The deferred note `config-export-drift-hand-edited-yaml.md` still says the practice is "not yet added to CLAUDE.md". I can update it with a dated line saying it now is, if you want.

That leaves 1c (where the 296 comment lines go) and 1a (the CI check). Which do you want next?

---

## User — 2026-10-06 15:25

update doc with the status.   And then let's discuss 1c

---

## Claude — 2026-10-06 15:26

The deferred note and its README row now record that the practice is in CLAUDE.md (`d2e78a8`, pushed to `main`).

## 1c: where the 296 comment lines go

**What's at stake**
- 16 files hold 296 real comments. Fourteen are migration definitions and two are settings files (`mandala_kaltura.settings` and `mandala_kmassets_sync.settings`). The comments are all ours.
- Most of them are audit counts and rationale. For example, `d7_av_audio` has 48 lines and `d7_av_video` has 46.
- An export strips them. `mandala_kmassets_sync.settings` has already lost its block once, and it was re-added by hand.
- Yuji's stated direction is that comments in `config/sync` are unsupported. Rationale goes where an export can't touch it: an entity's own text fields, a doc keyed by config name, an ADR, spike or deferred note, or the commit message.
- Until the comments move, `config-check.sh --strict` can't be turned on. It's a lint, so it can't wait forever, and it only tells you about comments, not where to put them.

**The destination options**
1. **One rationale doc per migration or settings group, under `docs/dev-notes/`.** For example, a `migration-config-rationale.md` with one section per config name.
   - **For:** it's easy to find by config name, and the content stays together.
   - **Against:** someone has to remember to update it.
2. **Fold each block into the existing audit, spike or deferred doc it came from.** The migration comments are mostly data-audit counts, so they likely duplicate content already in `docs/planning/av-*` or the sprint docs.
   - **For:** nothing new to maintain.
   - **Against:** you have to check each block against its source, and some may not have one.
3. **Use the entity's own text.** Migration definitions have `label` and `description`. That's the only place that survives an export and is visible in the Drupal UI.
   - **For:** the text travels with the config.
   - **Against:** it's a poor fit for 48 lines of counts, and for settings files that have no description field.
4. **Delete the ones that merely narrate the code.** I'd guess this applies to some of the shorter ones (4 to 9 lines), but I haven't read them.

**My recommendation:** a mix. First, go file by file and sort each block into "duplicates an existing doc", "genuine rationale" and "stale". Duplicates and stale ones get deleted. Genuine rationale goes to Option 1, with a one-line `description:` pointer where the config supports it. The 16 files are small enough to do in one sitting, and it's a docs-and-config change that needs an export to land correctly, so it should go through the live-Drupal path.

Two points to settle. Which option do you want as the default? And who does this? It touches the AV migration definitions, so Than probably should, and I can prepare the sorting for review. I haven't read the comment blocks, so I can't yet say how many are duplicates. Do you want me to read them and report?

---

## User — 2026-10-06 15:31

read the comment blocks and report, but I would say that 1. is the probably the option I would picl

---

## Claude — 2026-10-06 15:31

I read all 16 files. Almost none of it is noise, so this is a relocation job rather than a cleanup.

## What the 296 lines contain

| Kind | Where | Lines (approx.) | Verdict |
|---|---|---|---|
| **Data-audit counts and rationale** (the "why" behind each migration) | Header blocks in every migration file. Examples: 4,187 audio nodes, 1 audio and 17 video nodes with no Kaltura ID, 317 aliases beyond one per node, 1,235 membership pairs, uid 7471 dangling. | ~130 | Keep. This is the valuable part. |
| **Duplicated between sibling files** | `d7_av_audio` and `d7_av_video` share almost everything (Kaltura identity, uid note, scalars, KMaps, paragraphs). `d7_images_collections` and `d7_images_subcollections` share an identical 14-line block. | ~90 | Collapses to one copy each. |
| **Repeated boilerplate inside one file** | `d7_av_workflow` repeats the same 4-line "entity_reference_revisions needs BOTH ids" note three times. `d7_av_pbcore_instantiation` has it once. | ~16 | One entry, or drop. |
| **Operational and how-to notes** | `mandala_kaltura.settings`: "edit THIS file, not `config/install`". `d7_av_files`: "budget 30–90 minutes, latency-bound". | ~15 | Move to the runbook or dev-notes. |
| **History and corrections** | `d7_images_*`: "CORRECTED 2026-09-09... said `uid: uid`". `mandala_kaltura`: "24762821 was this preset's value before the fix". | ~25 | Mostly belongs in commit messages, so I'd drop it. |
| **Section markers** (`# --- Kaltura media ---`) | `d7_av_audio` / `d7_av_video` | ~10 | Drop. They're navigation aids only. |

I judged this from content. I haven't diffed it against the existing audit docs, so some "audit count" blocks may already be duplicated in `docs/planning/av-*`.

## Findings you may care about

- **A live fidelity gap is hiding in a comment.** `d7_av_audio` says `d7_images_shanti_image` doesn't map `uid`, so all 111,340 Images nodes are owned by Anonymous. It's labelled "a Sprint 1 fidelity gap". I haven't checked whether it's tracked in a deferred note, and it should be.
- **Two comments are warnings that matter to operators:**
  - The AV14 note that 18 nodes will be uneditable until someone supplies a Kaltura reference.
  - The `field_transcript` file field is inert.
- **`mandala_kmassets_sync.settings`** has a long comment justifying why `service` and `asset_type` are `audio-video`. It was already stripped once and re-added.
- **`config/install` copies may keep comments.** They aren't exported, so the stripping risk applies only to `config/sync`. That affects where the Kaltura "edit THIS one" note could live.

## With Option 1

A single doc, `docs/dev-notes/migration-config-rationale.md`, with one section per config name and sibling files merged. I'd estimate it comes out at roughly half the current line count.

The order I'd follow:
1. Write the doc and commit it, so nothing is lost.
2. Remove the comments from `config/sync` by running an export in live Drupal from a clean `config:status`. That follows the new rule and needs no hand-editing.
3. Check `git diff` shows only comment removals.
4. Turn `config-check.sh --strict` on.

Who takes it? It's mostly the AV migration files, so Than knows the content best, and I'd draft the doc for his review. Do you want me to draft it, or leave it for Than?

---

## User — 2026-10-06 15:35

I would check that all the comments that pertain to bugs or data problems should be recorded in a deferred note (if they haven't been already).  This certainly is the wrong place for them now (in the config sync files).   They aren't really comments on the config, but comments on the data.

---

## Claude — 2026-10-06 15:35

The data and bug comments are mostly covered already. Two real data losses are recorded only in a planning doc, not in a deferred note. I checked by grep and by reading the matching lines, not by reading every note in full.

## Already in a deferred note

| Comment says | Deferred note |
|---|---|
| Images nodes all owned by Anonymous, `uid` not mapped | `images-node-authorship-not-migrated.md` |
| `field_tags` is "Tags Old" and hidden on video, live on audio | `av2-field-tags-old-label-inconsistency.md` |
| 1 audio and 17 video nodes have no Kaltura ID, uneditable until fixed | `av14-media-less-nodes-staff-handoff.md` |
| `avrating` / fivestar stored raw on a 0–100 scale (2 rows) | `av15-avinfo-abandoned-fields-review-with-than.md` |
| `url_html` and `url_ajax` are known-wrong placeholders | ADR 016's open items (an ADR rather than a deferred note, which fits since it's a decision) |

## Recorded, but only in `planning/av-node-migration-notes.md`

These are real data findings, and that doc's own header says "decisions and findings", so none of them is an open item with an owner.
- **9 of 595 datetime rows lose their end date.** D7's range field is `value`/`value2`, D11's is a single datetime, and `value2` is dropped. This is silent data loss with no owner.
- **uid 7471's two AV memberships are skipped.** The account was deleted in D7 and the OG rows were left behind. Two memberships are lost: one in "University of Flourishing" and one in the "Enhanced Cognition" subcollection.
- **NULL text format on all 2,340 `field_pbcore_rights_summary` rows.** It's handled by a default, so I wouldn't call it a problem.
- **`field_rss_feed` is vestigial, with zero collections using it.** It's a deliberate exclusion, not a problem.

## Covered elsewhere, or not a defect

- **Wrong Kaltura player id.** 24762821 was used by mistake, and AV13 fixed it. The comment is history, so it's covered by the commit and `av10-kaltura-configuration-layer.md`.
- **The `uid: uid` bug in the Images collection migrations.** The comment says it was corrected on 2026-09-09. It's history, so I'd drop it.
- **65 AV nodes with no collection membership.** The orphan-review work covers this.
- **317 multi-alias nodes.** This is a deliberate decision recorded in ADR 016, not a problem.
- **`field_transcript` is inert.** It's Sprint 4 and Spike 11.

## Suggested gaps to fill

Two small deferred notes, one issue each as the README convention asks:
1. **Dropped `value2` end dates (9 rows).** The question for Than is whether any of those end dates matter.
2. **uid 7471's two dropped memberships.** The question is whether to restore them to a different owner, or accept the loss. It's the dangling-uid case, so I'd tag it with the AV14-style "staff handoff" idea.

Everything else can move to the rationale doc, or be dropped as history.

Do you want me to draft those two deferred notes? I haven't started the rationale doc and won't until the destination is settled.

---

## User — 2026-10-06 15:38

yes, draft those two deferred notes.  And let's move on with the rationale doc and cleaning up the sync files.

---

## Claude — 2026-10-06 15:39

Config is clean apart from the expected `stage_file_proxy`. Next I'll export to a temp directory, which doesn't touch `config/sync`, and compare.

---

## Claude — 2026-10-06 15:40

Most files differ only by comments. A few show non-comment changes, so I'll look at those before copying anything.

---

## Claude — 2026-10-06 15:40

Nothing was copied. zsh didn't split my file list, so `git status` shows 0 changes. I'll redo it in bash with a fresh export.

---

## Claude — 2026-10-06 15:41

PR #280 is open, and I haven't merged it. Merging touches `drupal/**`, so it triggers a dev-0 deploy.

**What it contains**
- **Two deferred notes**, both indexed in `README.md` and `.pages`:
  - `av-available-from-end-date-not-migrated.md`: 9 of 595 rows lose their end date, and the question for Than is whether those dates matter.
  - `av-collection-memberships-dropped-for-deleted-d7-user.md`: uid 7471's two memberships are skipped. This one is Low priority, and AV4 had recorded it as "no action needed".
- **`docs/dev-notes/migration-config-rationale.md`:** the comment content, keyed by config name, with sibling files merged and history-only remarks condensed. All its links resolve.
- **The 16 `config/sync` files** are now comment-free.
  - I made them by exporting from live Drupal to a temp directory and copying the 16 files across, with no hand-edits.
  - The only non-comment differences are quoting of `kaltura_domain` and `server_url`, and list layout in some `process` entries. The values are unchanged.
  - `config:status` shows only the local-only `stage_file_proxy` as different.
  - The four Solr field-type files are untouched.

**Caveats**
- **Exact line counts.** I didn't verify the 296 against the original files after the copy. The "comment-free" claim comes from a grep, which found no remaining `#` lines outside the Solr files.
- **Dropped comments.** I condensed history-only comments and section markers, so Than should skim the doc for anything I dropped or merged wrongly, especially in the AV migrations. The PR asks for that.
- **`config-check.sh`.** It still reports the local-only `stage_file_proxy` as a FAIL, and `--strict` isn't the default yet. Both are noted as remaining work.
- **Branch.** The PR is on `docs/migration-config-rationale-and-data-loss-notes`.

Do you want to review it now with Than, or move on to 1a, the CI check?

---

## User — 2026-10-06 15:45

Than reviewed it.  Let

---

## Claude — 2026-10-06 15:45

Your message cut off after "Than reviewed it. Let". What do you want to do next? For example, merge #280, or make changes Than asked for first.

---

## User — 2026-10-06 15:45

let's proceed

---

## Claude — 2026-10-06 15:45

There are no review comments on the PR, so Than's review was verbal and nothing is pending. I'm merging #280.

---

## Claude — 2026-10-06 15:46

The execution is `d9bde43e` on commit `4f66bfe`, triggered by the webhook, and the Build stage is running. I'll watch that specific execution in the background with a time limit.

---

## Claude — 2026-10-06 15:46

The merge is done, and the dev-0 deploy is running as execution `d9bde43e`. I'll report when the watcher finishes. It has a 30-minute limit.

Since the config change is serialization-only, I expect the deploy's `cim` and drift check to pass with nothing to change. If the post-`cim` drift check fails, I'll tell you what it reports.

Item 1c is done once the deploy passes. Item 1a, the CI check on PRs that touch `config/sync`, is still open, and so is turning `config-check.sh --strict` on by default. Do you want to start on 1a while the deploy runs?

---

## User — 2026-10-06 15:46

<task-notification>
<task-id>bmbxc5d9f</task-id>
<tool-use-id>toolu_01HxrwbtsPzcXLoU6ZyyNSYG</tool-use-id>
<output-file>/private/tmp/claude-504/-Users-ys2n-Code-uvalib-mandala-navina/095cf349-2b86-43ad-af25-8e754b7c28a6/tasks/bmbxc5d9f.output</output-file>
<status>completed</status>
<summary>Background command "Watch the specific deploy execution to completion" completed (exit code 0)</summary>
</task-notification>

---

## Claude — 2026-10-06 15:47

The watcher never ran. macOS has no `timeout` command, so the "completed, exit 0" notification is misleading. I'll check the deploy status directly.

---

## Claude — 2026-10-06 15:47

The deploy is still in the Build stage. I'll check whether `watch-deploy.sh` bounds itself, then rerun it without the `timeout` wrapper.

---

## Claude — 2026-10-06 15:47

The watcher has no time limit of its own. I'll run it in the background with a 30-minute kill watchdog, since a past unbounded watcher spun for hours on expired credentials.

---

## Claude — 2026-10-06 15:47

That earlier completion notice was a false alarm. The first watcher never ran because macOS has no `timeout`. The deploy is still in Build.

A new watcher is running on execution `d9bde43e`, with a 30-minute watchdog that kills it. I'll report when it finishes.

---

## User — 2026-10-06 15:47

let's discuss 1a, the CI check

---

## Claude — 2026-10-06 15:47

I'll check what CI exists today so the discussion rests on facts.

---

## Claude — 2026-10-06 15:48

Today the repo's only CI is `docs.yml`, which publishes the docs on a push to `main`. Nothing runs on pull requests, so this would be the first PR check.

## What the check would do

Boot a throwaway Drupal in GitHub Actions and ask whether the sync directory is self-consistent:
1. Check out the PR, install PHP 8.3, run `composer install` (cached), and start a MySQL service container.
2. Run `drush site:install --existing-config`, which imports `config/sync` onto an empty DB. No DB fixture is needed, because the config builds the site.
3. Run `drush config:status` and fail on anything other than "No differences".
4. Optionally run the export-to-temp diff from `config-check.sh`, which catches comments and formatting drift.

**It would catch both incidents.** Drupal re-serializes a config entity on import, so a hand-edit with the wrong key order comes out differing from the file. That is what failed #177 and #209. It would also catch config that imports but doesn't match its own export, and missing module or dependency problems.

**It wouldn't catch** config that is wrong in substance but internally consistent. It also wouldn't cover the migrated DB, which only dev-0 has.

## The options

| Option | Catches | Cost | Weakness |
|---|---|---|---|
| **A. PR check in Actions** (above) | Both incidents, before merge | Roughly 5-10 min per PR that touches `config/sync`; free runners, since the repo is public | We'd have to build and maintain it |
| **B. Local hook running `config-check.sh`** | Same class, if the author has DDEV up | Almost none | Skippable, and requires a running DDEV |
| **C. Run the check in the existing CodeBuild stage** | Stops a bad config before it reaches dev-0 | Small | Still post-merge, so `main` is already broken |
| **D. Rely on the deploy guard and the CLAUDE.md rule** | Eventually | None | Each failure costs a blocked deploy |

## Open questions

- **It depends on a fresh install from config working.** I haven't verified that this repo does so. Config that assumes migrated data, such as groups or migration-dependent config, could fail. That's the big unknown.
- **Cost and speed are unmeasured.** The 5-10 minutes is a guess.
- **Private composer dependencies:** I haven't checked whether `composer.json` needs any, which would complicate CI.

## Recommendation

Build Option A, but try it locally first. In DDEV, install a scratch database from `config/sync` and time it. That answers the "does it install from config" question in about half an hour. If it works, the workflow is mostly boilerplate, restricted by path to `drupal/config/sync/**` and `drupal/composer.lock`.

Two decisions for the group:
- **Required or advisory?** Should the check be a required status on `main`? I'd start advisory. Making it required is a separate step.
- **Scope:** I'd limit it to config for now and leave the 7 kernel tests for later.

Do you want me to run the scratch-install trial in DDEV now? It uses a second database and doesn't touch your local DB.

---

## User — 2026-10-06 15:50

This seems WAY TOO cumbersome just for config changes.

---

## Claude — 2026-10-06 15:50

Agreed. You'd be building and maintaining a CI pipeline, with a fresh-install job and its own failure modes, to guard against something that has happened twice in about a month. Both times a person hand-edited structural YAML. That's now ruled out in CLAUDE.md, and the deploy guard still backstops it.

I'd close 1a as "no CI check, for now" and make the cheap pieces carry it:
1. **The CLAUDE.md rule**, already merged. Every Claude session reads it, and hand-edited structure was the cause both times.
2. **`config-check.sh` in `session-close-check.sh`.** The close ritual already catches mechanical traps, and this would run `config-check.sh` when `config/sync` has uncommitted or unpushed changes. It needs a small fix first: it should discount the local-only `stage_file_proxy`, as `session-start-check.sh` does, instead of reporting a FAIL.
3. **The deploy guard**, unchanged, as the backstop. A miss costs one blocked deploy and a fix.
4. **Revisit CI only if it happens a third time** despite the rule.

I haven't touched `--strict` yet. Now that the comments are gone, making it the default is a one-line change, so a stray comment fails the check.

If you agree, I'll record the decision in the deferred note and move it out of "Awaiting a team decision". I'll make the `stage_file_proxy` fix, wire it into the close check, and flip `--strict` in one small PR. Is that right?

---

## User — 2026-10-06 15:50

have you consider drush config:validate?

---

## Claude — 2026-10-06 15:50

I haven't, so I'll check what it does here before answering.

---

## Claude — 2026-10-06 15:50

`config:validate` isn't a command in this install. Drush 13.7.6 lists only `delete`, `edit`, `export`, `get`, `import`, `pull`, `set` and `status` under `config:`. What I think you mean exists in two places:
- **Contrib `config_inspector` module.** It adds `drush config:inspect --only-error` for schema checks. We don't have it installed.
- **Core's typed-config validation.** Since Drupal 10.3, config entities have validation constraints that can be run through the TypedConfigManager.

**It wouldn't catch the failure we've had.** Both incidents (#177 and #209) were key-order and serialization mismatches. The YAML was schema-valid and semantically right, so schema validation would pass it. Only `config:status` or an export diff notices that class.

**It covers a different class.** It would catch typo'd keys, wrong types, or a missing required value. We haven't had that failure so far.

Locally it would be a cheap second check inside `config-check.sh`. It would run in DDEV against the live DB, with no CI, fresh install or fixture. The cost is installing `config_inspector` as a dev-only module, the same way `stage_file_proxy` is handled.

I'd still close 1a as proposed. I'd add the schema check as a "maybe later" only if a schema-type error actually bites. I haven't tried it, so I don't know how noisy `config:inspect` is on this codebase. I can try it in DDEV without committing anything, if you want to see the output first.

---

## User — 2026-10-06 15:51

So what situations would this CI check even address?

---

## Claude — 2026-10-06 15:51

It would address a narrow set of situations, and one of them dominates.

**What the CI check catches**
1. **Hand-edited structural YAML that Drupal would re-serialize differently.** Key order, an added component, quoting. This caused both incidents. The new CLAUDE.md rule and local `config-check.sh` address the same cause earlier and for free.
2. **Config that doesn't import cleanly on an empty site.** Examples are a missing module dependency, a config entity referencing something that isn't in sync, or a `core.extension` entry without its code. This is the only situation where CI adds something the other checks don't. It's also the weakest case, since the deploy's `cim` hits the same thing.
3. **Someone committing without running `config:status`.** This is just a way of enforcing the rule.

**What it wouldn't catch**
- **Wrong but self-consistent config.** For example, the wrong view display or a permission set to the wrong value.
- **Anything about the migrated DB.** A fresh install doesn't have migrated content, groups, or users. Only dev-0 does.
- **Code bugs.** Unless you add the kernel tests, which is scope creep.
- **Schema typos.** That's `config:inspect`'s job, not this check's.

In practice the check would catch situation 1 again, with human error as the cause. Anything beyond that is speculative. The measures we already have cover it: the CLAUDE.md rule, `config-check.sh`, and the deploy guard as backstop. That's why I'd close 1a without CI. The check only earns its cost if the rule gets ignored a third time.

Shall I go ahead with the lighter version: record the decision, fix `config-check.sh`'s `stage_file_proxy` FAIL, wire it into `session-close-check.sh`, and flip `--strict`?

---

## User — 2026-10-06 15:55

Yes, no CI.  The deploy will catch any problems, and we can remediate then.   I think using heavy CI just for that check would be wasteful.   Yes, fix config-check.sh so that it can be used at sessiion-close

---

## Claude — 2026-10-06 15:55

Decision recorded: no CI. I'll start by reading the three scripts.

---

## Claude — 2026-10-06 15:56

Now `config-check.sh`: source the helper, discount the expected drift in both steps, and make strict the default with a `--lenient` escape.

---

## Claude — 2026-10-06 15:56

`stage_file_proxy` is now discounted, and the comment check passes. Five files remain that an export would reformat. Strict would fail on them every time, so I'll look at what they are.

---

## Claude — 2026-10-06 15:57

The strict check passes on a clean tree. Next I'll test the failure path by adding a stray comment to a config file, running the check in strict and lenient modes, and reverting.

---

## Claude — 2026-10-06 15:58

The failure path works: strict exits 1, lenient exits 0, and the file is reverted. Next I'll wire the check into `session-close-check.sh` and update the matching CLAUDE.md ritual list.

---

## Claude — 2026-10-06 15:59

The close check works end to end. Now I'll confirm the `session-start-check.sh` refactor still behaves, then record the decision in the deferred notes.

---

## Claude — 2026-10-06 16:01

The PR is open at #281, and I haven't merged it. Merging touches `drupal/config/sync`, so it would trigger a dev-0 deploy, which should be a no-op.

**The earlier deploy passed.** Execution `d9bde43e` for #280 finished as **Succeeded** at 11:57, so the comment-free config imported cleanly on dev-0.

**What #281 changes**
- **Decision recorded.** There's no CI check, and the deploy guard is the backstop. It's in `config-export-drift-hand-edited-yaml.md` and the README, whose row moved out of "Awaiting a team decision".
- **Shared helper.** `scripts/lib/local-only-config.sh` holds the `stage_file_proxy` discount. `session-start-check.sh` and `config-check.sh` both use it, so they can't disagree.
- **`config-check.sh`:**
  - It no longer FAILs on `stage_file_proxy`, in either the `config:status` step or the export-diff step.
  - It's strict by default. `--lenient` downgrades comment and formatting findings to warnings, and `--strict` still works.
- **`session-close-check.sh`:** a new step 3 runs `config-check.sh` when `drupal/config/sync` changed, whether committed against `origin/main` or uncommitted. If DDEV is down it warns, and if nothing changed it skips. CLAUDE.md's session-end ritual is updated to match.
- **Five more files canonicalized**, all cosmetic. They are four Solr field types (empty `protwords`) and `views.view.collection_gallery` (description quoting). They were the only things keeping strict from passing on `main`.

**What I ran**
- On a clean tree, strict passes.
- With a stray `#` comment added to a config file, strict exited 1 and `--lenient` exited 0. I reverted the file afterwards.
- The `session-start-check.sh` 3a check still passes after the refactor.

**Not tested**
- I didn't test a real unrelated module being enabled locally, which should still FAIL.
- I didn't test the close check with DDEV stopped.

**Other open PR.** There's also PR #279, not mine: "Add Delete video on Kaltura checkbox to AV node delete confirmation".

That settles item 1. Do you want to take item 2 (the local files mechanism) next?

---

## User — 2026-10-06 16:03

what are the PR's to merge currently?

---

## Claude — 2026-10-06 16:03

Two PRs are open:

| PR | Title | Author | Status | Notes |
|---|---|---|---|---|
| **#281** | `config-check.sh`: strict by default, usable at session close; record no-CI decision | you (ys2n) | Mergeable, no reviews | 13 files. Touches `drupal/config/sync`, so merging triggers a dev-0 deploy, which should be a no-op. |
| **#279** | Add "Delete video on Kaltura" checkbox to AV node delete confirmation | Than Grove | Mergeable status still computing (`UNKNOWN`), no reviews | 5 files. It changes `mandala_kaltura.module` and `KalturaSessionService.php`, and adds a deferred note, `av-delete-kaltura-checkbox-admin-session-verification.md`. |

**#281** is mine, and it's ready if you and the group are happy with it. I'd merge it yourself.

**#279** is Than's and unreviewed. It's real code that deletes things on Kaltura, an external service, so it needs a reviewer other than the author. The deferred note's title suggests something about it still needs checking with an admin session. I haven't read the PR beyond its file list. I can review it if you want, but a destructive change like this should also get a human read, from Xiaoming or you.

Both are `drupal/**` changes, so each merge starts its own dev-0 deploy. Merge them one at a time and wait for the first to finish, so the pipeline doesn't queue or overlap.

Do you want me to merge #281 now, or review #279 first?

---

## User — 2026-10-06 16:03

merge #281

---

## Claude — 2026-10-06 16:04

Noted: Than has #279, so I'm leaving it alone. Closing out now. I'll start a bounded watch on deploy `cdfb3ffa` for #281, and run the close check while it runs.

---

## Claude — 2026-10-06 16:04

The mechanical close checks are clean. Now I'll refresh memory, starting with the config note, which is out of date.

---

## Claude — 2026-10-06 16:04

Session close is done, except the deploy for #281 is still running and I haven't written a session log.

**Mechanical checks:** all clean. The docs are indexed, the tree is clean, `main` is pushed, and `config/sync` was untouched since the last check. The only open PR is #279, which is Than's, so I left it alone.

**Merged this session**

| PR | Change |
|---|---|
| #280 | 296 comment lines moved out of `config/sync` into `docs/dev-notes/migration-config-rationale.md`; two new AV data-loss deferred notes |
| #281 | `config-check.sh` strict by default, shared `stage_file_proxy` helper, wired into `session-close-check.sh`; no-CI decision recorded |
| direct to `main` | Sprint 2 planning doc marked historical; the config practice added to CLAUDE.md |

**Deploys**
- **#280:** Succeeded on dev-0.
- **#281:** execution `cdfb3ffa` was still building at 12:04. A watcher with a 25-minute kill is running and will notify me when it finishes. Before relying on it, check with `./scripts/deploy-status.sh drupal`. If you close the session first, the watcher dies harmlessly.

**Memory refreshed.** I rewrote the config-workflow memory as decided (no CI, and don't re-propose it). I added the macOS lesson that `timeout` doesn't exist here, so a watcher wrapped in it never runs and reports a false "done". I also added a 2026-10-06 entry to `project-mandala-state` listing what's open: Than's #279 and the group agenda items we hadn't reached.

**Not done**
- **Session log:** I haven't run `scripts/save-session-log.py`. This session touched no privately tracked material, so it would be safe if you want a log saved, and I can run it.
- **Group agenda:** we only worked through item 1. Items 2 onward were not discussed: the local-files mechanism, dev-0 durability, the Solr core owner, and the editor-permissions design.

---

## User — 2026-10-06 16:06

write the session log and leave a note to pick up again at item 2, when we have a joint meeting.

---
