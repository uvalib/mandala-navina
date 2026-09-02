# Session Log: PR #171 Deploy, Solr Cross-Environment Fix, and Group Decisions Agenda

**Date:** 2026-09-02  
**Participants:** Than Grove (driving), Yuji Shinozaki, Xiaoming Wang, Carla Arton, Claude Code  
**Outcome:** PR #171 (Sprint 2 B3) merged and verified live on dev-0. Group-meeting agenda
([`2026-09-02-agenda-group-decisions-outstanding.md`](2026-09-02-agenda-group-decisions-outstanding.md))
drafted and worked through live — 7 of 8 items resolved, landing in
[PR #176](https://github.com/uvalib/mandala-navina/pull/176): staging→production Solr
writes disabled on `dev-1`, 171 collection/subcollection groups re-owned to their real D7
creators, ADR 017 ratified, `deployspec.yml` shallow-clone fix, and three items assigned to
Yuji. Only the contributor/group-editor Group-role migration scheduling call remains open.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-09-02 12:46

ok pull down the latest code and tell me where we are.

---

## Claude — 2026-09-02 12:46

Everything's current and matches memory — no surprises. Here's where things stand:

**Current branch:** `feat/sprint2-b3-masonry-gallery-grid`, clean, up to date with origin.

**PR #171** (Sprint 2 B3: masonry gallery grid + click-to-open info panel) — **still open, still unreviewed**. It bundles:
- Homepage now points to `/gallery`
- Result summary/sort/search via Better Exposed Filters
- New `ShantiPager` (real D7 pagerer port)
- Masonry grid fixes: white tile background instead of pig.js's grey placeholder, and removal of a blurred duplicate image that was causing a shadow artifact

Most recent commit (`1b66574`) is a session-log entry documenting the B3 gallery chrome + Shanti pager work.

**Outstanding items from memory:**
- PR #171 needs manual dev-0 deploy (composer install + config:import) once merged — CI/CD doesn't do this automatically
- Next unstarted Workstream B item is **B2 (sibling carousel)**
- Standing lesson: `hook_theme()` suggestion auto-discovery has been unreliable in this codebase twice now — register explicitly rather than relying on it

Want me to look at PR #171 in more detail (diff/review status), or start scoping B2?

---

## User — 2026-09-02 12:47

check PR #171 review status

---

## Claude — 2026-09-02 12:47

PR #171 status:

- **Reviews:** none — no reviewers have weighed in, no `reviewDecision` set
- **Comments:** none
- **Checks:** GitGuardian security check passed (only CI check configured)
- **Mergeable:** yes, `CLEAN` merge state

So it's technically ready to merge (mergeable, checks pass) but genuinely unreviewed by any teammate — nobody's looked at it yet. Want me to ping the team, request reviewers, or do anything else with it?

---

## User — 2026-09-02 12:48

It doesn't need review. Let's merge it and work on deploying it to dev.

---

## Claude — 2026-09-02 12:48

PR #171 merged. Before touching dev-0, memory flags a real risk: **check for active detached jobs (migration, kmassets reindex) on dev-0 before deploying** — a deploy restarts the container and kills any running job. Let me check that first.

---

## Claude — 2026-09-02 12:48

Now, before deploying, I need to check for active long-running jobs on dev-0 (migration/reindex) — a deploy restarts the container and would kill them. Let me find the deploy/access docs and check dev-0 status.

---

## Claude — 2026-09-02 12:49

PR #171 is merged into `main` (commit `c7a8541`). I'm now looking up the dev-0 access/deploy procedure and how to check for active jobs there before we deploy — I'll follow up once that's back.

---

## User — 2026-09-02 12:49

<task-notification>
<task-id>a315160e31cd266a7</task-id>
<tool-use-id>toolu_01Kg6DCMZoNhemwmadyP8jys</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/45f6d698-9836-470e-822d-216ccedad17b/tasks/a315160e31cd266a7.output</output-file>
<status>completed</status>
<summary>Agent "Find dev-0 access and deploy docs" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>I have enough to report now.

## Findings

**1. SSH access to dev-0** — `docs/dev-notes/howto-access-mandala-nodes.md`
- Host: `mandala-drupal-dev-0.internal.lib.virginia.edu` (all hosts under `*.internal.lib.virginia.edu`)
- Requires UVA VPN + your own personal key/computing-id (not the terraform `.pem`, not `centos`):
  ```
  ssh -i ~/.ssh/id_rsa &lt;your-computing-id&gt;@mandala-drupal-dev-0.internal.lib.virginia.edu
  ```
- Passwordless `sudo`; Docker requires `sudo` (`sudo docker ps`, `sudo docker exec &lt;container&gt; &lt;cmd&gt;`). App container is `mandala-drupal-0`.
- No ansible inventory/bastion doc found — this is direct SSH, no bastion hop.

**2. Manual deploy procedure** — no doc frames it exactly as "composer install + drush config:import"; the closest is `docs/deferred/dev-0-code-config-delivery-rebuild-or-pipeline.md` (Option A, an interim manual runbook, superseded — RESOLVED 2026-08-11, real CI pipeline built instead), listing: `docker build -f package/Dockerfile ... uvalib/mandala-drupal`, push to ECR, `terraform apply --target=...`, `ansible-playbook deploy_backend.yml`. Related: `docs/planning/1b1-part4-d11-backend-deploy-scope.md` (composer vendoring details) and `docs/deferred/staging-migration-execution-prerequisites.md` §2, which shows the actual deploy runs `docker exec mandala-drupal-0 vendor/bin/drush cr` and only a **partial** `drush cim -y --partial --source=/var/simplesamlphp/drupal-config` (no full `cim`/`updb` on deploy — see `docs/deferred/deploy-never-imports-config-sync.md` for that gap and the recommended targeted `cim` workaround).

**3. Checking for active long jobs before deploying**
- `docs/deferred/migrate-group-import-aborts-on-partial-failure.md` — "To check on / resume a run" section: SSH in and `tail -40` the log inside the container, e.g. `sudo docker exec mandala-drupal-0 sh -c "tail -40 /tmp/migrate_import2.log"`; `ALL_DONE` marker in log = finished, no marker = still running (jobs are launched as `sh -c '... &gt;&gt; logfile 2&gt;&amp;1'` background scripts, parented by `containerd-shim-runc-v2`, surviving SSH disconnects).
- `docs/dev-notes/howto-long-running-jobs-on-dev-staging.md` — nightly shutdown window (11pm–6am) kills any `docker exec`'d process (migrations included); check/plan around this before starting or deploying over a long job.
- No screen/tmux session mechanism is used — it's plain log-file tailing.</result>
<usage><subagent_tokens>37483</subagent_tokens><tool_uses>12</tool_uses><duration_ms>47959</duration_ms></usage>
</task-notification>

---

## Claude — 2026-09-02 12:50

Good, connected fine and the earlier exit 1 was just the empty grep (no matching processes) — no active drush/migrate/reindex/composer jobs running. Safe to deploy.

---

## Claude — 2026-09-02 12:50

Confirmed clean — only apache workers running, nothing that would get killed by a deploy. Now let's check whether the current running code already reflects the merge (CI pipeline may already have deployed the image), then run the manual composer install + config import step.

---

## Claude — 2026-09-02 12:50

`shanti_grid_view` isn't present yet on dev-0 — the running container has the old image, so CI hasn't (or hasn't yet) pushed the new code. Let's check whether the CI pipeline picked up the merge to `main` and if a new image is on its way, versus us needing the manual composer install path.

---

## Claude — 2026-09-02 12:51

Found something worth flagging before going further: the running container isn't a live code mount — it's baked from an ECR image (`build-20260901161743`, corresponding to the B1 merge). Deploying B3 means a **new image build → ECR push → Terraform/Ansible apply**, i.e., the AWS CodePipeline described in `pipeline/buildspec.yml`/`deployspec.yml` — not just a `composer install` inside the running container.

I don't have AWS CLI access from here to check pipeline status or trigger it.

---

## Claude — 2026-09-02 12:52

Still on the old image (`build-20260901161743`, same timestamp as before). CodePipeline typically takes several minutes to build/push/deploy — I'll poll periodically rather than sit idle.

---

## Claude — 2026-09-02 12:52

PR #171 is merged to `main`. That should have kicked off the AWS CodePipeline build/deploy automatically, but dev-0 is still running the old image as of now — image builds typically take a few minutes. I'll check back shortly and let you know once the new image lands and I've confirmed the config import.

---

## User — 2026-09-02 12:53

In the meanwhile, tell me how we could divide the work so that we each worked on separate branches on different apps without creating merge conflicts and other difficulties.

---

## Claude — 2026-09-02 12:53

The natural fault line here isn't "different branches" so much as **different subtrees that don't share config**: Drupal custom modules (`drupal/web/modules/custom/*`) vs. the React app (`Standalone/mandala-om`) are already conflict-free by directory, so parallel work there is safe by default. The real conflict risk inside Drupal is `drupal/config/sync/*.yml` — Drupal's config export is a single shared state, so two people exporting overlapping config (e.g. both touching `views.view.image_gallery.yml` or `core.extension.yml`) will clobber each other even on separate branches/modules; the fix is to assign config-owning entities per person for the sprint, not just per module, and require whoever merges second to re-export config against latest `main` before opening their PR rather than trusting git's line-level merge.

The main tradeoff: strict entity ownership avoids conflicts but slows down anyone who needs a small config tweak in someone else's area — worth it here given how failure-prone Drupal config merges are, but I'd scope it to just the config/sync files, not the module PHP itself, since those merge fine normally. Want me to sketch a concrete per-person split for the current Workstream B items (B2 sibling carousel, etc.)?

---

## User — 2026-09-02 12:54

I am talking only about Drupal at this point.

---

## Claude — 2026-09-02 12:54

Within Drupal, the safest split is by custom module directory (`drupal/web/modules/custom/<module>/`) — those are conflict-free across branches since each module is its own subtree, and it maps naturally to how work's already organized (Than owns `shanti_grid_view`/`shanti_iiif`, someone else owns `mandala_group_inheritance`, etc.).

The real risk is `drupal/config/sync/*.yml` — Drupal's config export is one shared state per site, so two people each running `drush config:export` on overlapping entities (views, field displays, `core.extension.yml` when either enables a module) will silently overwrite each other even with clean git merges, because config YAML diffs don't reliably three-way merge the way code does. The fix: whoever's about to export config checks `main` first, and only one person exports/commits a given config entity per cycle — not a hard rule for the whole file tree, just the specific `.yml` files each branch's module touches (views, view displays, `core.extension.yml`).

Want me to check which config entities each of the still-open Workstream B items would touch, so you can assign non-overlapping ones?

---

## User — 2026-09-02 12:55

Yes, check which entities B2 would touch

---

## Claude — 2026-09-02 12:55

Checking the B2 spec now to pin down exact config entities it'd touch and whether they overlap with what B3 just added.

---

## User — 2026-09-02 12:55

also check dev-0 deploy status

---

## Claude — 2026-09-02 12:56

The deploy is actively in progress — `mandala-drupal-0` container was just torn down (the other three containers recreated ~12:55 UTC, consistent with Ansible mid-redeploy) and hasn't come back up yet. Let me check again in a moment.

---

## Claude — 2026-09-02 12:56

**B2 config check (via research agent):**

B2 (sibling carousel) is controller/JS-driven, not Views-based — it resolves siblings via Group module relationships (`group_relationship` entity queries), not a taxonomy field or a Views display. Concrete config touch:

- **`core.extension.yml`** — adds `shanti_images_carousel: 0` (new module enable) — the only shared file with B3, and it's additive, not conflicting.
- No `views.view.*.yml`, no `core.entity_view_display.*`/`core.entity_view_mode.*`, no field/formatter config — everything else is plain code (`CarouselController.php`, routing, JS library).

**Overlap with B3:** none at the config-entity level. B3 owns `views.view.image_gallery.yml` + the `grid_details` view mode/display (all under `shanti_grid_view`); B2 touches neither. The only shared surface is the `node--shanti_image.html.twig` template, where both B1 and B2 attach JS behaviors — a normal code-merge, not a config conflict. So B2 and B3 (or B2 and whatever's next) can run in parallel with essentially zero config risk — just coordinate the `core.extension.yml` line and the twig template if two people edit both at once.

**Dev-0 deploy status:** mid-flight. `mandala-drupal-0` container was torn down (~12:55 UTC, along with solr-proxy and netbadge recreating) and hasn't come back up yet — not even in the exited-container list — so Ansible is still between steps (likely pulling/starting the new image). I'll keep checking.

---

## Claude — 2026-09-02 12:57

I'll check back on the deploy shortly. In the meantime — for splitting Drupal work going forward: B2 and B3 (or B2 and whatever comes next) are safe to run in parallel on separate branches with essentially no config conflict risk, since B2 is entity-query/JS driven rather than Views-based. The only two shared touchpoints to coordinate verbally are the `core.extension.yml` module-enable line and the `node--shanti_image.html.twig` template (both B1 and B2 attach JS behaviors there).

---

## User — 2026-09-02 13:01

Check dev-0 deploy status: ssh -i ~/.ssh/id_rsa ndg8f@mandala-drupal-dev-0.internal.lib.virginia.edu "sudo docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}'" and look for mandala-drupal-0. Last check it was torn down (not even in exited list) mid-Ansible-deploy following PR #171 merge to main. If it's back up: check the image tag (was build-20260901161743, want a newer build-YYYYMMDDHHMMSS tag), then exec into it and confirm shanti_grid_view module is present: sudo docker exec mandala-drupal-0 ls /opt/drupal/app/drupal/web/modules/custom/ | grep grid. If present, also check drush config:status inside the container for pending config (drush config:status) since deploy only does a partial cim per project memory (docs/deferred/deploy-never-imports-config-sync.md) — report whether views.view.image_gallery.yml and related B3 config entities show as imported or still pending, and if pending, run the targeted drush config:import for just those entities per the workaround documented there. Report final status: is B3 live on dev-0, is config fully imported, any errors seen. If container still isn't up, just note deploy is still in progress, no further action needed yet.

---

## Claude — 2026-09-02 13:01

New image is up: `build-20260902124945` (matches the 12:48 merge time). Now checking the module and config state.

---

## Claude — 2026-09-02 13:01

Module is present. Now checking config import status.

---

## Claude — 2026-09-02 13:01

**B3 is fully live on dev-0.** Deploy completed cleanly:

- New image `build-20260902124945` is up and running (matches the merge)
- `shanti_grid_view` module present and **Enabled**
- `views.view.image_gallery` config imported and active (`status: true`)
- `drush config:status` shows only `simplesamlphp_auth.settings` as different — that's an expected environment-specific diff, not a B3-related gap; no B3 config entities are pending

No manual `config:import` intervention needed this time — the deploy's partial cim picked up all of B3's config cleanly. PR #171 is merged, deployed, and verified on dev-0.

---

## User — 2026-09-02 13:20

Sketch a branch assignment for B2 and the next Workstream B item

---

## Claude — 2026-09-02 13:20

Quick flag before I sketch anything: Workstream B only has three items total — B1 (done), B3 (just merged), and B2 (not started). B2 is the last one; there's no further Workstream B item after it. Did you mean:

---

## Claude — 2026-09-02 13:21

Since B2 is the only item left, this is a single-branch assignment, not really a "division of work" — but here's the concrete shape:

**Branch:** `feat/sprint2-b2-sibling-carousel`
**Owner:** whoever's free (no Workstream B conflict since it's solo now)
**Scope** (per `docs/planning/sprint-02-planning.md`):
- New module `shanti_images_carousel`
- `src/Controller/CarouselController.php` — resolves siblings via `group_relationship` entity queries (same pattern as `NodeJsonController::buildCollection()`), windowed ±15, cached per-collection
- `routing.yml` for `/shanti-images/carousel/{node}`
- `js/shanti-images-carousel.js` — AJAX-loads into a placeholder
- Twig: `node--shanti_image.html.twig` — attach the new placeholder/behavior

**Config touched:** only `core.extension.yml` (adds `shanti_images_carousel: 0`) — additive, no overlap with anything B1/B3 already committed.

**One thing to watch, not a branch-division issue:** `node--shanti_image.html.twig` was already touched by B1 (deep-zoom viewer) and is now on `main` post-B3. B2's branch should be cut from current `main` (post-#171 merge) rather than from an older point, so that edit lands on top of B1's, not against a stale copy.

Given there's no second parallel branch to coordinate against, want me to just open the branch and scaffold the module skeleton, or hold until someone's ready to start on it?

---

## User — 2026-09-02 13:31

What other shared aspects of Mandala that need to be worked on besides images?

---

## Claude — 2026-09-02 13:31

Good, this confirms the full picture. Here's the state of Sprint 2 work beyond Images:

**Workstream A — Base theme (`shanti_sarvaka`)**: done, merged (PR #169). Site default theme.

**Workstream C — Content-model audits (AV, Sources, Texts)**: done, all three closed (C1/C2/C3). Audit-only — no migration code or scaffolding was in scope this sprint.

**Workstream D — Uniform asset-endpoint access** (owned by Than, per docs): **not started yet**, two sub-items still open:
- **D1** — write up the `_entity_access: 'node.view'` enforcement pattern as the copyable convention every future site endpoint (AV/Sources/Texts) must follow. Documentation only, no code.
- **D2** — design spike for the authenticated-fetch identity-forwarding gap (`sid`→uid resolution through solr-proxy for the external `mandala-wp-proxy` plugin). Spike/recommendation doc only, explicitly not implementation.

Beyond this sprint's scope entirely, two deferred items sit behind D2/AV-Sources-Texts:
- **Editorial access model** — the contributor tier (site-wide CRUD-own) is verified in the Images data dump but still unwired in D11 (`authenticated-contributor-crud-not-wired-in-d11.md`) — a cutover gate, not yet scheduled.
- **AV/Sources/Texts full migration** (content, KMaps, Solr sync, module scaffolding) is explicitly **out** of Sprint 2 — audits only, real migration work is a future sprint, with AV called out in ADR 009 as "hardest, last."

Given D1/D2 are unstarted and independent of Images entirely, that's the next open work outside Images. Since D1/D2 are "owned by Than" per the doc — is that you, and do you want to pick those up next, or should I sketch how to split D1/D2 across two people?

---

## User — 2026-09-02 13:33

Yes that is me but that can be done by me alone. What is there in or out of sprint 2 that needs group attention?

---

## Claude — 2026-09-02 13:34

Here's the group-attention landscape, split by why it needs a group rather than one driver:

**Open question inside Sprint 2 itself**
- **Multi-image sequence viewer** (`sdviewer.php` equivalent) — the planning doc explicitly left this "not silently in or out" of Sprint 2's Images UI work. Needs a scope call before anyone starts or skips it.

**Formally flagged "awaiting a team decision" (blocked on group conversation, not implementation)**
- **kmassets audit checks the write master, not the search reader** — a real 70-document gap between `mandala-index-dev` hosts slipped through a "clean" audit; needs Yuji to confirm infra facts before anyone acts.

**Already scheduled for next meeting**
- Terraform full-repo-clone-on-every-deploy fix, alongside #162 and the `memory_limit` fix — Than + Xiaoming.

**High-priority, and newly relevant now that C1–C3 audits are closed** (these gate the *next* natural sprint — AV/Sources/Texts migration — so worth raising before that work gets scoped, not after):
- **`field_legacy_nid` not unique across sites** — D7 nids are per-domain; latent today because only Images is migrated, becomes a live defect the moment Texts/Sources/AV migration starts. Needs a bundle-scoped-lookup vs. `field_legacy_site`-companion decision *before* that migration begins.
- **D7 shared user database** — High, blocks user migration and everything gated on it.
- **D7 editor permissions are OG group-scoped, not migrated** — D11's `content_editor` role has zero overlap with real Mandala content types; a sitewide role fix can't be faithful to D7's actual per-collection model.
- **Contributor CRUD tier unwired in D11** — cutover gate (you already own the fix direction per Q2, but the "when" is a group scheduling call).
- **Canonical D7 dev-source dump** — open question whether dev-0's D7 baseline stays frozen or gets re-cut as staging/cutover approaches; Medium now, High later — better decided while it's still cheap.

**Live production risks, independent of D11 but real**
- **Staging Solr writes hit production** — `dev-1`'s Sources/AV staging both target the prod Solr master; a stray reindex there writes to prod.
- **rdx (reindeer_x) ALB target unhealthy in production** — live defect, unrelated to the rebuild, still unresolved.
- **Solr pipeline cost/architecture conversation with Dave Goldstein** — a roadmap driving-decision, still not opened.

Want me to draft this as a group-meeting agenda doc, or just flag the Sprint-2 scope question (multi-image viewer) for now since that's the one actually blocking active work?

---

## User — 2026-09-02 13:37

Explain the Terraform full-repo-clone-on-every-deploy fix

---

## Claude — 2026-09-02 13:37

The fix itself is tiny — one line in `pipeline/deployspec.yml:42`:

```diff
- git clone https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/uvalib/terraform-infrastructure.git
+ git clone --depth 1 --single-branch https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/uvalib/terraform-infrastructure.git
```

**The problem:** `terraform-infrastructure` is 11,785 commits / 831MB of `.git` history, and `deployspec.yml` only ever reads the *current* tree to run Terraform — it never touches history, tags, or other branches. Every single deploy clones the whole thing anyway, paying that cost for no benefit. `--depth 1 --single-branch` gets just the current commit on the current branch, which is all the deploy actually uses.

**Why it's flagged Low-Medium and not just done solo** (per Yuji, 2026-08-27):
- It's a real cost, not a correctness bug — deploys complete fine today, just slower than necessary.
- `pipeline/**` is a deploy-trigger path — landing this change fires a real deploy, so it's not risk-free even though the change itself looks safe.
- The note itself says "verify against the full deployspec before landing, since this note is a finding, not a reviewed patch" — nothing downstream reads history/tags across branches, but that should get eyes before merging.
- Scheduled to land bundled with the next Than + Xiaoming meeting, alongside reviewing #162 and picking up `dev0-from-scratch-rebuild-runbook.md` steps 7–10 — batching pipeline/infra changes together rather than as solo drive-bys.

Want me to check whether anything downstream in `deployspec.yml` does rely on clone depth/history (to save that verification step for the meeting), or leave it as-is until then?

---

## User — 2026-09-02 13:38

We all agree and go ahead an implement the proposed fix.

---

## Claude — 2026-09-02 13:38

Verified: nothing else in `deployspec.yml` reads git history, tags, or other branches — `terraform init`/`apply` and the Ansible playbooks only touch the current working tree. Edit made.

Since this is a `pipeline/**` change and merging to `main` triggers a real staging deploy, let me confirm no active jobs on dev-0 first, then commit/push/PR.

---

## Claude — 2026-09-02 13:39

Clean — no active jobs. Now committing and opening a PR.

---

## Claude — 2026-09-02 13:39

PR #172 opened: https://github.com/uvalib/mandala-navina/pull/172

One thing to flag before it merges: **merging this triggers a real staging deploy** (it touches `pipeline/**`). I checked dev-0 first and confirmed no active jobs, so it's currently safe — but if you want to wait for a specific moment rather than merging right now, let me know. Otherwise, since the group already agreed, I can merge it whenever you say go.

---

## User — 2026-09-02 13:40

what it the Multi-image sequence viewer issue?

---

## Claude — 2026-09-02 13:40

This is the mechanism behind the "multi-image sequence viewer" open question:

**What it is:** In D7, `shanti_images` had a *second*, related viewer beyond the single-image deep-zoom (which B1 already ported): `sdviewer.php` + `shanti_images_sdinit.js`. Instead of opening one IIIF `info.json`, it takes a `data-iiifurls` attribute containing a `|$|`-delimited **series of images** and opens OpenSeadragon against the whole ordered sequence — used for sorting/classifying workflows, not just viewing a single detail page.

**Why it's an open question, not just deferred work:** the note found something odd digging into D7's own source — D7's code **hardcoded `is_series = false`** and never actually wired up a real sequence source. In other words, this feature may have existed in the codebase but was **effectively dead/unused in production D7** — nobody was actually feeding it a real multi-image series. That's why the planning doc couldn't just say "in scope" or "out of scope": it's not clear the feature does anything real to port.

**Current status:** explicitly **not ported** as part of B1 (deep-zoom viewer, done), and flagged in the B1 completion note as "still flagged for team confirmation before B1 is considered fully closed... this open question may be moot" — i.e., it needs someone to confirm whether D7's `is_series` path was ever actually exercised (e.g., grep D7 prod DB/templates for real multi-image callers) before deciding whether it's worth building at all in D11.

**The actual group decision needed:** either (a) someone verifies the D7 feature was genuinely dead and the team formally drops it, or (b) if it turns out something did use it, scope it as a proper Workstream-B-style item. Want me to check the legacy D7 repo/DB for any real caller of `sdviewer.php`/`is_series` to help settle which side of that it's on?

---

## User — 2026-09-02 13:41

Can you give me an example D7 url for thie second related viewer?

---

## User — 2026-09-02 13:42

[Request interrupted by user for tool use]

---

## User — 2026-09-02 13:45

It was the beginning of a new function in the old D7 site. We don't want to develop it now so it doesn't need to be included in the D11 site but make a note in the documentation about where that code is in the old D7 site.

---

## Claude — 2026-09-02 13:45

Found it, confined to the exact path.

---

## Claude — 2026-09-02 13:45

Now let's resolve the corresponding open question in the sprint doc and planning doc.

---

## Claude — 2026-09-02 13:45

Now updating this to record the decision with the exact D7 location.

---

## Claude — 2026-09-02 13:46

Now the planning doc's scope table and its "Open scope question" note.

---

## Claude — 2026-09-02 13:46

This is a planning doc that's a historical record of the pre-sprint decision — I'll leave that specific paragraph as-is (it correctly captured "out of scope unless the team says otherwise" at the time) and just update the scope-boundary table's status line, since the sprint doc now carries the actual resolution.

---

## Claude — 2026-09-02 13:46

Now let's commit these docs. This is documentation-only, no `pipeline/**` risk, so no deploy trigger concern.

---

## Claude — 2026-09-02 13:46

These changes are unrelated to the deployspec PR — let me put them on their own branch off `main` rather than bundling into #172.

---

## Claude — 2026-09-02 13:46

That's expected — the deployspec fix lives only on PR #172's branch (not yet merged), so switching to `main` correctly shows it unmerged. My stash pop restored just the sdviewer doc edits. Continuing.

---

## Claude — 2026-09-02 13:46

PR #173 opened: https://github.com/uvalib/mandala-navina/pull/173

**Summary of `sdviewer.php`:** it's an unfinished D7 prototype at `docroot/sites/all/modules/custom/shanti_images/sdviewer.php` — literally titled "Test of SeaDragon," never linked from any real page, and its multi-image sequence mode (`shanti_images_sdinit.js`, `data-iiifurls` split on `|$|`) was never triggerable since nothing ever set that attribute. Confirmed dead, documented, decided out of scope for D11 — no code needed.

Two PRs now open, docs-only (#173) is safe to merge anytime; the deployspec fix (#172) still triggers a real staging deploy on merge — say the word when you want that one to go.

---

## User — 2026-09-02 13:46

We have decided that Yuji can work on the item: kmassets audit checks the write master

---

## Claude — 2026-09-02 13:46

Moving this out of "Awaiting a team decision" into the Open items table, now that it's assigned to Yuji.

---

## Claude — 2026-09-02 13:47

Now updating the note itself to reflect the group's assignment decision.

---

## Claude — 2026-09-02 13:47

Now committing this on its own branch, docs-only.

---

## Claude — 2026-09-02 13:47

PR #174 opened: https://github.com/uvalib/mandala-navina/pull/174

Marked the kmassets audit master/reader gap as assigned to Yuji and moved it from "awaiting a team decision" into the regular open-items backlog, since it's now actionable rather than blocked on group conversation.

Three PRs open now: #172 (deployspec shallow-clone, triggers a staging deploy on merge), #173 (sdviewer resolution, docs-only), #174 (Yuji assignment, docs-only). Want me to merge the two docs-only ones now, and hold #172 until you say go?

---

## User — 2026-09-02 13:51

What about D7 shared user database — High, blocks user migration and everything gated on it.? Didn't we already import the users?

---

## Claude — 2026-09-02 13:51

Confirmed — yes, this is done, and you were right to question it. The deferred note is stale.

**Status:** User migration ran on dev-0 on 2026-08-12: **1,543 users** imported (from the shared `mandala_shared` DB, exactly the design this note called for), and `d7_images_collection_memberships` went from 36/246 stuck since 2026-07-19 to **246/246** immediately after. All 22 private groups now have real members.

There was a real wrinkle along the way (not about this note's content, but worth knowing): the migration had actually been sitting **merged but never deployed** — `deploy_backend.yml` only runs a partial SimpleSAMLphp `cim`, never a full `cim`/`updb`, so the config had been on `main` for a while without reaching dev-0. That's the same deploy-import gap tracked separately in `deploy-never-imports-config-sync.md` (already flagged High in the backlog you saw earlier) — running the user migration is actually what surfaced it.

This deferred note's own content (the shared-DB architecture, the SAML/NetBadge mapping question, the `realname` field question) is still accurate background, but its "blocks" framing is outdated. Let me mark it resolved and check whether the "everything gated on it" downstream items actually cleared too.

---

## Claude — 2026-09-02 13:52

Now the README table entry.

---

## Claude — 2026-09-02 13:52

Now committing on its own branch.

---

## Claude — 2026-09-02 13:52

Good, clean. Now let's rebuild this branch properly off `main` instead of stacking on the kmassets-assignment branch.

---

## Claude — 2026-09-02 13:52

That cherry-pick landed directly on local `main` since the branch-create failed silently. Let me undo that and fix properly — no push happened, so `origin/main` is untouched.

---

## Claude — 2026-09-02 13:52

Local `main` is back to matching `origin/main` — clean, nothing lost since it was never pushed. Now let's properly recreate the branch off `main`.

---

## Claude — 2026-09-02 13:52

Good — clean, single commit, based on `main`. Let's verify the diff is correct before pushing.

---

## Claude — 2026-09-02 13:52

Correct and clean. Pushing and opening the PR.

---

## Claude — 2026-09-02 13:52

The earlier stacked branch was already pushed under that name. Rather than force-push, I'll push this as its own branch and delete the abandoned one (no PR was ever opened against it).

---

## Claude — 2026-09-02 13:53

PR #175 opened: https://github.com/uvalib/mandala-navina/pull/175

**Answer to your question:** yes, you were right — the D7 user migration is done. It ran on dev-0 on 2026-08-12 (1,543 users from `mandala_shared`), and `d7_images_collection_memberships` cleared its long-stuck 36/246 to a full **246/246** the same day. I'd been reciting an earlier, stale "High — blocks everything" priority from the deferred backlog that predated that run. Downgraded to Medium; the only real open pieces left are the historical group-ownership correction (174 groups still owned by `uid: 1` from a 1b.2 workaround) and the SAML/NetBadge + `realname` design questions.

Four PRs open now: #172 (deployspec fix, triggers a staging deploy), #173/#174/#175 (all docs-only). Want me to merge the three docs-only ones now?

---

## User — 2026-09-02 13:53

Can you make just a single PR for this session

---

## Claude — 2026-09-02 13:54

Each branch has exactly one commit off `main`, no overlapping files except the shared `docs/deferred/README.md` edits (3 of them touch it). Let's build one consolidated branch.

---

## Claude — 2026-09-02 13:54

All four cherry-picked cleanly, no conflicts. Let's verify the final state is sane before pushing.

---

## Claude — 2026-09-02 13:54

Found a duplication bug — there was already a separate table row for `kmassets-audit-checks-master-not-search-reader.md` further down in the README that I didn't see when I added a new one. Let me fix that before pushing.

---

## Claude — 2026-09-02 13:54

I'll delete the row I newly added and instead update the pre-existing row in place, since that's the canonical entry.

---

## Claude — 2026-09-02 13:54

Now let's double-check there's no other duplication (deployspec/user-database entries) and confirm the file is well-formed.

---

## Claude — 2026-09-02 13:54

No duplicates, exactly one row each. Now let's verify no active jobs on dev-0 (this will trigger a real deploy), then push and open the single consolidated PR, closing the four originals.

---

## Claude — 2026-09-02 13:54

Clean. Pushing and opening the consolidated PR.

---

## Claude — 2026-09-02 13:55

Now closing the four originals and cleaning up their branches.

---

## Claude — 2026-09-02 13:55

Done. Single PR now covers everything from this session: **#176** — https://github.com/uvalib/mandala-navina/pull/176

The four originals (#172–#175) are closed with a pointer to #176, and their branches are cleaned up. Note it still touches `pipeline/**`, so merging it will trigger a real staging deploy — I confirmed no active jobs on dev-0 right before pushing, but say the word when you want it merged.

---

## User — 2026-09-02 13:56

Add any further changes in this session to that PR and we will merge at the end. What are the remaining open questions for the group?

---

## Claude — 2026-09-02 13:56

Good — anything else this session goes onto `session/2026-09-02-deploy-and-docs-cleanup` (PR #176) rather than a new branch.

On remaining group-attention items — three from the earlier list just got resolved this session (sdviewer decided, kmassets gap assigned to Yuji, user migration confirmed done), so here's what's actually still open:

**Needs a group decision or infra visibility, not just a driver:**
- **`field_legacy_nid` not unique across sites** — D7 nids are per-domain; latent today since only Images is migrated, becomes a live defect the moment Texts/Sources/AV migration starts. Needs a bundle-scoped-lookup vs. `field_legacy_site`-companion call *before* that migration begins.
- **D7 editor permissions are OG group-scoped, not migrated** — D11's `content_editor` role has zero overlap with real Mandala content types; a sitewide role can't be faithful to D7's real per-collection model. Needs a design decision, not just implementation.
- **Contributor CRUD tier unwired in D11** — cutover gate; direction is already decided (Q2, Group member-role perms) but the *when* is a scheduling call.
- **Canonical D7 dev-source dump** — stays frozen or gets re-cut as staging/cutover approaches? Cheap to decide now, expensive to leave open till cutover.
- **Staging Solr writes hit production** — `dev-1`'s Sources/AV staging both target the prod Solr master. Real infra risk, needs whoever owns that environment to weigh in.
- **rdx (reindeer_x) ALB target unhealthy in production** — live defect, independent of the rebuild, still open ("under review" since 2026-08-11).
- **Solr pipeline cost/architecture conversation with Dave Goldstein** — a roadmap driving-decision that's never actually been opened.

**Loose ends from this session's user-migration finding** (smaller, but worth a mention rather than silently carrying forward):
- Historical group-ownership correction — 174 collection/subcollection groups still owned by `uid: 1` from a 1b.2 workaround, now that real users exist to reassign to.
- SAML/NetBadge account-mapping strategy and the `realname` field decision — both still open design questions from the original migration note.

Want this turned into an actual agenda doc, or is the list itself enough for now?

---

## User — 2026-09-02 13:56

Draft that as a group-meeting agenda doc

---

## Claude — 2026-09-02 13:57

Good news: this one's already mostly solved, not actually open at the design level.

**What's already done:** `field_legacy_site` — the companion field to `field_legacy_nid`, using the kmassets service vocabulary (`images`, `texts`, `sources`, `audio-video`, `visuals`, `mandala`) — was built and merged 2026-08-25 (PR #152). It's a `list_string` field on both `node` and `group`, validated against a fixed vocabulary (typos rejected), verified live in DDEV (`group 1 → images-41`, matching the kmassets uid format exactly), and Images already gets it populated per-migration with `default_value`.

**What's actually still open — just one thing:** [ADR 017](../adr/017-legacy-identity-composite-key.md) itself is still marked **`Status: Proposed`**, not `Accepted`. It was explicitly filed that way — "for discussion by Yuji, Than, Xiaoming, 2026-08-26" — and that sign-off conversation never happened (no follow-up commit ratifying it). The code shipped ahead of the formal decision.

So the group doesn't need to *design* anything here — the design is done and code-verified. The only decision is a **rubber-stamp ratification**: does the team accept ADR 017 as written (composite key, site-not-asset-type discriminator, kmassets vocabulary), or does anyone want to reopen the bundle-scoped-lookup alternative that was already rejected in the ADR's "Why an explicit field" section? If accepted, the checklist item just needs Texts/Sources/AV migrations to actually populate the field when they're built — which the migration checklist in `migration-legacy-nid-required-convention.md` already lists as a required step.

Want me to soften the agenda item accordingly — from "decide the approach" to "ratify ADR 017 (already implemented, awaiting sign-off)" — and update it in the file I just drafted?

---

## User — 2026-09-02 13:58

What is ADR 017?

---

## Claude — 2026-09-02 13:58

ADR = **Architectural Decision Record** — this project's format for recording a significant, hard-to-reverse technical decision along with its context and rationale (stored under `docs/adr/`, one per decision, treated as immutable once accepted — a later change gets a *new* ADR that supersedes it, never an edit).

**ADR 017: "Legacy identity is a composite key — `field_legacy_site` + `field_legacy_nid`"**

**The problem it solves:** When D7 content migrates into D11, each migrated node/group records where it came from via `field_legacy_nid` (its old D7 node id). But D7 wasn't one database — it was five separate site databases (Images, AV, Sources, Texts, Home), each with its *own* nid sequence starting from 1. So `field_legacy_nid = 1631632` is ambiguous on its own: it could be a completely different asset depending on which legacy site it came from. That's fine today only because Images is the only site migrated so far — it becomes a real bug the instant a second site (Texts, Sources, or AV) migrates and nid values start colliding.

**The decision:** Legacy identity is the *pair* `(field_legacy_site, field_legacy_nid)`, never the nid alone. `field_legacy_site` is a new companion field — a `list_string` restricted to the kmassets service vocabulary (`images`, `texts`, `sources`, `audio-video`, `visuals`, `mandala`) — added to both `node` and `group`, set as a constant per migration (since one migration only ever reads one D7 database).

**The one subtlety it calls out explicitly:** the discriminator is the D7 **site** (i.e., which database), not the **asset type** — AV's audio and video content types share *one* nid sequence (one database), so they both get the same token `audio-video`, not separate `audio`/`video` tokens. Splitting by type would divide a space that was never divided in D7.

**Why a real field instead of just inferring the site from the D11 bundle in code:** mainly because the kmassets legacy uid format is `{service}-{nid}` (e.g. `images-41`), so with this field the compatibility shim is just string concatenation — no separate host→bundle mapping table to maintain forever, and it matches a vocabulary the kmassets system already committed to.

**Current status:** the field itself is already built, merged, and verified (PR #152, 2026-08-25) — Images already populates it correctly. What's *not* done is the formal team sign-off: the ADR document itself is still marked `Status: Proposed`, waiting on Yuji/Than/Xiaoming to actually ratify it, which is the "decision" left on the group's plate.

---

## User — 2026-09-02 14:00

We are all here and it is ratified.

---

## Claude — 2026-09-02 14:00

Good, already on the right branch. Updating ADR 017's status.

---

## Claude — 2026-09-02 14:00

Now the ADR README index.

---

## Claude — 2026-09-02 14:00

Now updating the deferred convention note and the agenda doc to reflect ratification.

---

## Claude — 2026-09-02 14:00

Now the agenda doc — item 1 becomes ratified rather than open, and moves to the resolved section.

---

## Claude — 2026-09-02 14:00

Now updating the resolved section to include the ADR 017 ratification, and renaming it to cover both pre-meeting and during-meeting resolutions.

---

## Claude — 2026-09-02 14:00

Now committing this all onto the session branch, on top of the existing PR #176 commits.

---

## Claude — 2026-09-02 14:01

PR #176 updated with these changes — ADR 017 is now `Accepted`, and the agenda doc reflects that it was resolved live rather than left open. The agenda now has 7 remaining items instead of 8 (editor permissions, contributor CRUD scheduling, canonical dev dump, staging→prod Solr writes, rdx ALB health, the Dave Goldstein Solr conversation, and the user-migration loose ends).

---

## User — 2026-09-02 14:01

What's next on the agenda?

---

## Claude — 2026-09-02 14:01

Item 1 on the agenda doc: **D7 editor permissions are OG-group-scoped, not migrated.**

**The problem:** D7's real editor permissions were granted per-collection via `og_role_permission`, not Drupal core's `role_permission` table (which is empty for editor/workflow-editor/shanti-editor roles — confirmed, not just assumed). D11's committed `content_editor` role (ADR 015) is a *sitewide* role, and it has zero overlap with the actual Mandala content types — it only covers `article`/`page`. So a former D7 editor who could edit within their assigned collection gets a role in D11 that can't touch any real Mandala content at all.

**What needs deciding, not just building:** how to model per-collection editorial grants in D11. The likely direction is Group member-role permissions — the same mechanism already chosen for the contributor tier (item 2 on the agenda, Q2 2026-08-07: "wire as Group member-role perms, not core sitewide create") — but that's not yet been explicitly extended to editors, and it's a real design call: do editors get a distinct Group role from contributors, with a different permission set (edit-any-within-group vs. create/edit-own), or does this fold into the same mechanism with a role variant?

Want me to sketch what a Group-role-based editor model would concretely look like (comparing it to how contributor is already planned), so the group has something concrete to react to rather than designing from scratch in the meeting?

---

## User — 2026-09-02 14:04

So there are two types of editor, the global content editor should be able to create/edit/delete any content in any group, while a group editor can create/edit/delete any content within that group.

---

## Claude — 2026-09-02 14:05

This exactly matches ADR 015's already-accepted model — you're not proposing something new here, you're restating what's already decided:

- **Global `content_editor`** — sitewide role, full create/update-any/delete-any on Mandala content across every group (implemented via synchronized Group roles with `scope: outsider`/`insider`, plus a private-collection bypass). This is "Phase A," already built for Images.
- **Group editor** — a per-group Group role (`scope: individual`), scoped to CRUD within that one group only, reconstructed from D7's OG `editor` role. This is explicitly "Phase B," deferred but not dropped.

So the agenda item isn't really "design this from scratch" — the model is already ADR-approved. What's actually undone is:

1. **Phase B hasn't been built** — the group-membership/group-role migration that would give former D7 `editor` (rid 4) users their per-group Group role never ran. Right now those users migrated as plain `authenticated` (per ADR 015 item 4), so no one currently has group-scoped editorial access at all.
2. **The deferred note's real finding** (`d7-editor-permissions-og-group-scoped-not-migrated.md`) is that this gap was rediscovered independently, without connecting it back to ADR 015's own Phase B — worth flagging so the group doesn't treat it as a new problem needing a new design.

Given ADR 015 already covers this, should I update the agenda item to reflect that this is "confirm we're doing Phase B now, not designing a new model" rather than an open design question — and point it at what Phase B actually requires (migrate `og_users_roles` → Group roles, add the `editor` Group-role config)?

---

## User — 2026-09-02 14:06

Yes, update the agenda item accordingly.

---

## Claude — 2026-09-02 14:06

Now committing this to the same session branch/PR.

---

## Claude — 2026-09-02 14:06

Updated in PR #176. Item 1 now correctly frames this as a scheduling question, not a design one: the two-tier model (global `content_editor` any-group, group-scoped editor per-group) was already decided in ADR 015 — what's missing is that Phase B (the Group-role migration for the 142 D7 editors) was never actually built, so they currently have no group-scoped editorial access at all.

---

## User — 2026-09-02 14:06

next item on the agenda

---

## Claude — 2026-09-02 14:07

Item 2: **Contributor CRUD tier still unwired in D11.**

**The problem:** D7's `authenticated` role is the real contributor tier — every logged-in user gets CRUD on their *own* content across all asset types, site-wide. D11's `authenticated` role currently grants none of it — view-only. That makes the ADR 015 decision to migrate 142 D7 editors down to plain `authenticated` non-destructive *in principle*, but only if this tier actually gets wired up — right now those users (and every other authenticated user) can author nothing.

**Direction is already decided** (Q2, 2026-08-07, per memory): wire it as **Group member-role permissions** — create-within-groups — not a core sitewide create grant, since D11 forbids collection-less content. So this isn't a design question either, similar to item 1.

**What's actually open:** purely scheduling. It's flagged as a **cutover gate** — something that must land before go-live — but has no date attached yet, and it's mechanically related to item 1's Phase B work (both are Group-role migrations reconstructing D7's role structure: contributor = own-content-only, group-editor = any-content-in-group). Worth asking the group whether these two should be built together as one Group-role migration effort rather than sequenced separately, since they share the same `og_users_roles`-style migration mechanism.

Want me to fold items 1 and 2 into a single combined agenda item (since they're really one Phase-B-shaped migration effort), or keep them separate since they gate different things (item 1 = editorial correctness, item 2 = a hard cutover blocker)?

---

## User — 2026-09-02 14:07

Yes fold them together

---

## Claude — 2026-09-02 14:08

Now renumbering the remaining items (3→2 was just done, need 4→3, 5→4, 6→5, 7→6).

---

## Claude — 2026-09-02 14:08

Renumbering looks correct and consistent. Committing to PR #176.

---

## Claude — 2026-09-02 14:08

Folded and pushed to PR #176. Agenda is now 6 items — item 1 is the combined contributor+group-editor Group-role migration scheduling call.

Next up is item 2: **Canonical D7 dev-source dump — frozen or re-cut?** Want me to walk through that one next?

---

## User — 2026-09-02 14:08

Next agenda item?

---

## Claude — 2026-09-02 14:08

Item 2: **Canonical D7 dev-source dump — frozen or re-cut?**

**The finding:** dev-0 and local DDEV currently run against *different* D7 dumps. Comparing baselines found 7 of the 8 `EXPECT_LIST` acceptance keys differ between them. To be clear — this isn't data corruption; both dumps are internally consistent, just not the same snapshot in time. That's fine today: nobody's been relying on dev-0 and a laptop's DDEV agreeing byte-for-byte.

**Why it needs a decision now rather than later:** as the team gets closer to staging/production cutover rehearsals, having dev-0 hold an actual **canonical** source that everyone starts from (rather than "whatever dump happened to be loaded when") becomes important — cutover rehearsals need a fixed, known baseline to validate against, not a moving target. Left undecided, the drift between dev-0 and individual laptops will only widen as more people pull fresh dumps at different times.

**The actual open question:** does dev-0's dump get **frozen** as the canonical source everyone syncs to, or does it get **periodically re-cut** from the live D7 systems as staging/production approach (to stay closer to real current data)? Both are defensible — frozen gives reproducibility, re-cut gives freshness — but nobody's picked one, and it's currently flagged as Yuji's area ("separate baselines are fine for now, Yuji" per the note).

Since this is Yuji's infrastructure call more than a group design question, want me to note in the agenda that this one might just need Yuji's decision rather than a full group discussion, or leave it as-is for the meeting?

---

## User — 2026-09-02 14:10

Yuji is here and he says it should be recut but everyone should be alerted to resync when it is.

---

## Claude — 2026-09-02 14:10

Let's update the deferred note itself with the decision, then the README row, then the agenda doc.

---

## Claude — 2026-09-02 14:10

Now the top priority line and the README row.

---

## Claude — 2026-09-02 14:10

Now moving this item from the agenda's open list to "Resolved during this meeting," and renumbering what remains (3→2, 4→3, 5→4, 6→5).

---

## Claude — 2026-09-02 14:11

Now adding the canonical dump decision to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:11

Committing all three files to PR #176.

---

## Claude — 2026-09-02 14:11

Recorded and pushed to PR #176. Decision: **re-cut** the canonical dump as staging/production approach, conditional on alerting the whole team on every re-cut so environments don't silently drift (the alert mechanism itself is left as a follow-up, not yet picked).

Agenda is now 5 remaining items — next up is item 2: **Staging Solr writes land on production**. Want me to walk through that one?

---

## User — 2026-09-02 14:11

Yes, walk through that one

---

## Claude — 2026-09-02 14:11

**Item 2: Staging Solr writes land on production.**

**What was found (2026-08-13, Solr index inventory):** D7's "staging" install on `dev-1` is a straight configuration clone of production — its Solr routing was copied along with the databases and never repointed. That created write paths crossing environment boundaries in both directions:

- **Staging → production (the dangerous one):** `mandala-sources-staging` and `mandala-av-staging` both have their Solr backends live and pointed at the **production** Solr master. A routine `search_api` reindex, a cron flush, or even a content edit on the staging site can write into — or delete from — the real production `mandala-sources`/`mandala-av` cores.
- **Production → staging (the confusing one):** production Visuals writes into the *staging* Solr master instead of production's — which also explains a data oddity found earlier: Visuals has 1 document on staging, 0 on production, because production Visuals has literally never written to its own core.

**Why it's flagged Medium-High, not just Medium:** low probability day-to-day (both sites are quiet, nobody's actively working them), but high consequence — anyone doing migration rehearsal work on `dev-1` (which is exactly what staging is for) could unknowingly corrupt live production search data.

**Three concrete decisions for the group:**
1. **Disable or repoint** the two staging→production write servers. The note recommends disabling outright, since these are clone artifacts, not a real staging tier anyone actually tests search on.
2. **What to do about production Visuals** — its other search backend is separately confirmed dead (SearchStax, long decommissioned), so Visuals search on D7 is probably already non-functional. Honest fix may be turning it off rather than repointing it — is that worth spending effort on for a site that's [already decided out of D11 migration scope](Visuals retired)?
3. **Broader audit scope** — Solr is just what got checked; the same clone-never-repointed pattern likely affects other `dev-1` config (file systems, external APIs, mail, IIIF, KMaps servers). Does someone own auditing the rest, or is this deferred until it actually causes a problem?

One more thing worth surfacing to the room: the same inventory pass turned up a fourth, related finding that's tracked privately rather than in this repo — worth asking Yuji about before anyone works on production Solr endpoints directly.

Who has infra ownership to act on #1 here — is that Yuji, or does it need Dave Goldstein's team?

---

## User — 2026-09-02 14:15

Go ahead and disable the staging->production posts. Visuals can be ignored, and assign it to Yuji to review.

---

## Claude — 2026-09-02 14:16

Found the sites via Aegir's drush aliases. Let's inspect the current Solr config for each before touching anything.

---

## Claude — 2026-09-02 14:17

There's a proper drush command for this: `search-api-server-disable`. Let's confirm the server machine name and run it.

---

## Claude — 2026-09-02 14:17

Confirmed machine name is `solr`. Disabling it now.

---

## Claude — 2026-09-02 14:18

That write action got blocked by the auto-mode safety classifier — makes sense, since it's a mutating SSH command against legacy infrastructure with a write path toward production Solr, and this session hasn't done anything like it before.

I confirmed everything is exactly as the deferred note described: `mandala-sources-staging`'s `solr` search server is enabled and pointed at `mandala-solr-master-production-private...`. The fix is a single, cleanly reversible drush command:

```
drush @mandala-sources-staging.internal.lib.virginia.edu search-api-server-disable solr -y
```

(and the equivalent for `mandala-av-staging`'s `apachesolr` env variable once I check its exact command).

Since this needs your explicit go-ahead past the safety gate, can you confirm you want me to proceed via this SSH/drush path? If you'd rather run it yourself, I can hand you the exact commands instead.

---

## User — 2026-09-02 14:19

This is ok. We all say it is ok.

---

## Claude — 2026-09-02 14:21

Still enabled — the previous attempt timed out before completing. Retrying with more time.

---

## Claude — 2026-09-02 14:22

A stale lock from the earlier timed-out attempt is blocking this one. Let's check for a lingering process and clear it before retrying.

---

## Claude — 2026-09-02 14:22

That's just Aegir's normal background queue daemon, not a stuck process — the lock was likely transient contention. Retrying.

---

## Claude — 2026-09-02 14:23

Sources staging is disabled. Verifying and moving to the AV staging site.

---

## Claude — 2026-09-02 14:23

Confirmed disabled. Now the AV staging site — it uses `apachesolr`, not `search_api`, per the deferred note, so the mechanism differs. Let's inspect its config first.

---

## Claude — 2026-09-02 14:24

Disabling the whole `apachesolr` module cascade (6+ dependent modules) would be a much bigger blast radius than the Sources fix. Let me check for a more surgical option first.

---

## Claude — 2026-09-02 14:25

No per-environment disable flag exists for `apachesolr` (only `env_id`/`name`/`url`/`service_class`) — the module-level disable would cascade through 6+ dependent modules, too big a blast radius for this fix. The surgical equivalent is repointing the environment's URL to something inert, using the module's own `solr-set-env-url` command — fully reversible, no module state touched.

---

## Claude — 2026-09-02 14:26

Verifying the change took effect.

---

## Claude — 2026-09-02 14:26

The argument order was wrong — it set the URL to the literal string `mandala_library_rw` (still on the correct environment by luck, since that's the default), but that's a messy placeholder. Let me fix it properly.

---

## Claude — 2026-09-02 14:28

Confirmed clean. Both fixes are in place:

- **`mandala-sources-staging`**: `solr` search_api server disabled (verified via `search-api-server-list`).
- **`mandala-av-staging`**: `mandala_library_rw` apachesolr environment repointed from `mandala-solr-master-production-private...` to an inert local placeholder (`http://127.0.0.1:8983/solr/disabled-was-production`), verified in the DB. No write path to production remains.

Now updating the docs — the deferred note, README, and agenda doc — and recording Visuals as assigned to Yuji.

---

## Claude — 2026-09-02 14:28

Now updating the "Suggested actions" section to reflect what's done vs. what's assigned.

---

## Claude — 2026-09-02 14:28

Now the README row.

---

## Claude — 2026-09-02 14:28

Now the agenda doc — move this item to resolved, and renumber remaining.

---

## Claude — 2026-09-02 14:28

Now adding this fix to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:28

Committing everything to PR #176.

---

## Claude — 2026-09-02 14:29

Done. Both live fixes are in place and documented in PR #176:

- **`mandala-sources-staging`**: `solr` server disabled — verified.
- **`mandala-av-staging`**: `mandala_library_rw` environment repointed off production — verified.
- **Production Visuals → staging**: left open, assigned to Yuji.

Note: this PR isn't purely docs anymore — it now includes a real infrastructure change made directly against the legacy D7 `dev-1` box (outside this repo's own deploy pipeline), alongside the `deployspec.yml` fix that triggers a staging deploy on merge. Worth flagging since merging this PR won't itself apply the Solr fix (that's already live on `dev-1`), only the docs recording it.

Agenda is down to 3 items — next up is **rdx (reindeer_x) ALB target unhealthy in production**. Want to continue?

---

## User — 2026-09-02 14:29

Next agenda item

---

## Claude — 2026-09-02 14:29

**Item: rdx (reindeer_x) ALB target unhealthy in production.**

**The defect (measured 2026-07-14, not inferred):** Both dev and production have zero healthy targets behind their rdx (port 9000/9001) ALB target group — `Target.FailedHealthChecks` in both environments. The Solr-facing `index` target (8765) is healthy in both, so this is specific to the reindeer_x service, not a broader ALB problem.

**Root cause of the port mismatch:** Terraform declares `rdx_service_port = 9001` in both staging and production `variables.tf`, but the live service actually listens on **9000**. Simple config drift — except nobody knows yet whether Terraform or the running service is the one that should change, because the deployment config currently exists *only on the box*, not in any repo.

**Why nobody noticed:** rdx exists to propagate KMaps changes. KMaps development has slowed enough that nobody's expecting updates anymore — the outage is real but has had zero felt impact, since there's nothing to propagate right now.

**The gate — this is the actual thing to resolve today, not the port fix itself:** the note is explicit — *"Do not fix the port, move it, or build it a pipeline until [Yuji's] review [of whether rdx needs to be always-on at all] lands — all three respects below may be moot."* That review is tracked separately (`reindeer-x-has-no-ecr-repo-or-pipeline.md`), and per the agenda's earlier framing, it's "under review by Yuji" with no resolution yet.

**The three gated fixes, once unblocked:**
1. Fix the 9000/9001 port mismatch (direction depends on #3).
2. Move rdx off dev-0 (being replaced in place by D11) onto dev-1/staging, which currently has zero rdx target wired at all.
3. Get the deployment into GitHub — right now it's a hand-built box with no buildspec/deployspec/ECR repo/Ansible playbook anywhere, and even config drift is suspected (other legacy stacks on the same box were found running off uncommitted branches).

So the real agenda question, now that Yuji's in the room: **does the always-on-rdx review resolve today?** If rdx is being retired (batch re-sync when KMaps changes, rather than a continuously-running push service — a legitimate answer per the note, since the *ability* to re-sync just needs to survive, not a live daemon), none of the three fixes are needed at all. If it's staying, the fixes get scheduled. Since KMaps updates have effectively stopped, is there a reason to keep it always-on versus batch-triggered?

---

## User — 2026-09-02 14:30

Assign it to Yuji to review.

---

## Claude — 2026-09-02 14:30

Now updating both linked deferred notes to reflect the explicit assignment.

---

## Claude — 2026-09-02 14:30

Now the README rows.

---

## Claude — 2026-09-02 14:30

Now moving this item in the agenda doc to resolved, and renumbering.

---

## Claude — 2026-09-02 14:30

Now adding this to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:30

Committing to PR #176.

---

## Claude — 2026-09-02 14:31

Assigned and pushed to PR #176. Agenda is down to 2 remaining items:

1. **Solr pipeline cost/architecture conversation with Dave Goldstein** — just needs scheduling.
2. **Loose ends from the 2026-08-12 user migration** — historical group ownership, SAML/NetBadge mapping, `realname` field.

Want to continue to the next one?

---

## User — 2026-09-02 14:31

Yes, continue to the next one

---

## Claude — 2026-09-02 14:31

**Item: Solr pipeline cost/architecture conversation with Dave Goldstein.**

---

## Claude — 2026-09-02 14:31

Correcting something from my earlier survey — this conversation actually **has** already started and progressed further than the roadmap doc (which is older) suggested:

**Original worry (2026-06-12):** the ECS ingest pipeline (S3 → SQS → ECS transform/update, built by Dave Goldstein's team) was sized for high-throughput indexes, but Drupal saves are low-frequency — cost might be disproportionate, and it was fire-and-forget with zero visibility (a content editor saves a node, it doesn't show up in search, no way to tell what happened).

**What actually happened (2026-06-26 update, discussion already underway with Dave):** the picture got reframed. There's no ECS "transform" step at all — it's a small-batch (~32-doc) S3 poster that Dave's mechanism POSTs unchanged to the master. **The original cost worry receded** — there's no always-on ECS to right-size in the first place. Working direction: keep bulk/authoritative writes on the S3 batch path (Dave owns it), add a **direct-to-master sink** for incremental day-to-day updates and as a fast diagnostic loop.

**That direct-to-master sink is already built** — per my memory of this project, it shipped in 1a.8 (IP-gated, no-creds, `uid: {service}-11-{nid}`). So this isn't really "a conversation that hasn't started," it's a conversation that started, produced a working architecture, and got implemented.

**What's genuinely still open with Dave** (per `kmasset-solr-doc-contract.md` §3): direct-to-master access/credentials and whether a second writer is acceptable to his side; batch cadence; regen-directory support; failure-log reliability; which timestamp he keys on.

Given the implementation already shipped ahead of Dave's sign-off on those points, is there a live risk — did the direct-to-master sink go into production without Dave formally confirming a second writer is acceptable on his infrastructure? That seems like the real thing worth asking about today, rather than the original cost conversation which is largely moot now.

---

## User — 2026-09-02 14:31

Assign this one directly to Yuji

---

## Claude — 2026-09-02 14:31

Now the README row and the roadmap reference, plus the agenda doc.

---

## Claude — 2026-09-02 14:32

Adding it to "Resolved during this meeting" with the correction noted.

---

## Claude — 2026-09-02 14:32

Committing to PR #176.

---

## Claude — 2026-09-02 14:32

Assigned and pushed to PR #176.

One item left on the agenda: **Loose ends from the 2026-08-12 user migration** (historical group ownership for 174 groups, SAML/NetBadge account mapping, `realname` field decision). Want to continue to that one?

---

## User — 2026-09-02 14:32

Yes, continue to that one

---

## Claude — 2026-09-02 14:32

**Item: Loose ends from the 2026-08-12 user migration.** Three smaller, unrelated items bundled together since they're all follow-ups from the same migration event:

1. **Historical group ownership.** 174 collection/subcollection groups are still owned by `uid: 1` (the admin account) — a side effect of a 1b.2 workaround for a Group-module insert bug (since fixed by PR #28). Now that 1,543 real users exist, the group needs to decide whether/how to correct ownership back to whoever actually created each group in D7.

2. **SAML/NetBadge account mapping.** Migrated D11 accounts don't yet have a defined strategy for linking to UVA Shibboleth-authenticated sessions — is it a `name`/`mail` match, or does D11 need a stored NetBadge identifier field? This is a real design gap: ADR 014's SAML+OAuth2 coexistence work assumes a Drupal account already exists to key `sub` off of, but nobody's picked the matching mechanism.

3. **`realname` field.** D11 has no `realname` module by default. Decide: bring it in as a dependency (matching D7's behavior), or fold display-name handling into core user fields instead?

None of these are urgent blockers the way the contributor/group-editor item was — they're smaller loose ends nobody's picked up. Given the pattern from the rest of this agenda (most things ending up assigned to Yuji or scheduled), how do you want to handle these three — assign them, defer them further, or does the group want to actually decide right now (e.g., pick the SAML mapping strategy)?

---

## User — 2026-09-02 14:33

Let's talk about 1. What is the issue exactly?

---

## Claude — 2026-09-02 14:34

Here's exactly what's going on, confirmed by reading the actual migration source:

**The bug that caused it:** During 1b.2, inserting a Group entity (collection/subcollection) with certain uid values triggered a bug in the Group module — it auto-creates a membership row at insert time, and doing so with `uid: 0` produced broken state (this surfaced later as "174 stale `uid=0` group memberships making anonymous a member of every group," per the `d7-alias-preservation` note). The workaround chosen at the time was blunt: hardcode every migrated group's owner to `uid: 1` (the admin account), unconditionally — not "map the real D7 creator and only special-case the problem cases."

**What that means concretely, verified in the actual migration config** (`d7_images_collections.yml`):
```yaml
uid:
  plugin: default_value
  default_value: 1
```
This is a flat constant, not a field mapping. Compare to `created`/`changed` right above it, which *do* map real source fields (`created: created`). So every one of the 174 migrated collection/subcollection groups is owned by the site admin account in D11, regardless of who actually created it in D7.

**The good news — this is recoverable, not lost data.** The migration source plugin is core's standard `d7_node`, which always exposes the real D7 `uid` field. The actual D7 creator for every one of these 174 groups is sitting right there in the source database; nobody threw it away, the migration process pipeline just chose not to read it.

**The genuinely open question, and why it wasn't just fixed alongside PR #28:** whether the underlying Group-module insert bug still requires *some* uid-based workaround, or whether it's safe now to just map `uid: uid` directly like `created`/`changed` do. PR #28 fixed the *config-drift* problem (the workaround not reaching `config/sync`), not necessarily the Group module bug itself — I don't have confirmation the root insert bug was ever patched, only that its *symptom* (174 stale `uid=0` memberships) was cleaned up once. So the fix here isn't obviously "just map the real field" without first confirming the insert bug won't recur for whichever of those 174 groups had a real D7 `uid: 0` (anonymous-authored) — that's the case the original workaround was built for.

Want me to check the D7 source data for how many of those 174 groups actually have `uid: 0` at the source (i.e., how big the genuinely-risky subset is), before the group decides on a fix approach?

---

## User — 2026-09-02 14:36

Yes, check the D7 source data for that and assess whether it has all the correct user assignments and data in D7 that it can remap to in the d11

---

## Claude — 2026-09-02 14:37

Excellent — zero of the 171 real D7 collection/subcollection nodes have `uid: 0`. The whole "uid=0 bug" workaround was never actually needed for real ownership data — every single one has a legitimate nonzero creator. Now checking whether those D7 uids resolved cleanly into the 1,543 migrated D11 users.

---

## Claude — 2026-09-02 14:37

All 18 distinct D7 creator uids map cleanly, and — good find — the D11 destination uid equals the D7 source uid 1:1 in every case (e.g., D7 uid 105 → D11 uid 105). Let me confirm these are real, live D11 user accounts, not just leftover map rows.

---

## Claude — 2026-09-02 14:38

All 18 accounts exist and are active in D11 — including `ndg8f`, which is you (74 of the 171 groups). Full assessment:

**Everything needed to fix this correctly is present and clean:**

1. **Zero uid=0 cases.** Of 171 real D7 collection/subcollection nodes, none were created by anonymous. The original bug workaround (blanket `uid: 1`) was never actually protecting against a real case in this data — it just discarded 137 real, legitimate creator assignments as a side effect (171 total minus the 34 that were already legitimately `uid: 1`, ShantiAdmin).

2. **All 18 distinct D7 creator uids are cleanly mapped** in `migrate_map_d7_users` — 100% resolved, no orphans or missing lookups.

3. **The mapping is 1:1 identity** — D7 uid *N* → D11 uid *N* for every single case checked. The user migration preserved uid numbering directly, so the fix doesn't even need a lookup migration process plugin; it can be as simple as `uid: uid` (copying the source field straight through), exactly like `created`/`changed` already do.

4. **All 18 destination accounts are real, active D11 users** — not stale/deleted map rows.

**Distribution, for context:** 74 of 171 groups belong to `ndg8f` (you), 34 to `ShantiAdmin` (legitimately admin-owned, no change needed), and the remaining 63 are spread across 16 other real users in chunks from 1 to 13.

**Bottom line for the group:** this is a clean, low-risk fix — not a "decide whether to attempt recovery" question, since the data fully supports it. It's really just: re-run (or patch) the `d7_images_collections`/`d7_images_subcollections` migration process pipeline to map `uid: uid` instead of the hardcoded `default_value: 1`, then re-save the 171 existing groups to apply the corrected values (a migration rollback+reimport, or a one-off script, either works since Group's uid=0 insert bug isn't actually in play here). Want me to draft that fix?

---

## User — 2026-09-02 14:39

Go ahead and fix it now.

---

## Claude — 2026-09-02 14:39

Both migrations have the same pattern. Let's check the two config/sync files too (need to fix both install and sync, per the PR #28 lesson).

---

## Claude — 2026-09-02 14:39

Confirmed identical in `config/sync`. Now fixing all four files (2 modules × config/install + config/sync each).

---

## Claude — 2026-09-02 14:40

Now the two `config/install` copies in the module.

---

## Claude — 2026-09-02 14:40

Now applying the fix to the live data on dev-0 — the actual 171 already-migrated groups. Let me check the Group entity's owner field name first.

---

## Claude — 2026-09-02 14:41

Running as a dry-run first to verify before applying.

---

## Claude — 2026-09-02 14:41

Dry run is clean — 137 to fix, 34 already correct, zero errors of any kind. Applying now.

---

## Claude — 2026-09-02 14:41

Need the `--` separator, and args land in `$extra`, not `$argv`. Let me fix the script's arg detection.

---

## Claude — 2026-09-02 14:42

That worked — `$extra` picked up `--apply` correctly. Let's verify the change landed by re-running the dry-run check.

---

## Claude — 2026-09-02 14:42

All 171 groups now show correct ownership — 0 remaining discrepancies. Let's clean up the temp scripts on the box and confirm no unexpected side effects (per the PR #28 precedent that a uid-only update doesn't cascade `hook_group_update`).

---

## Claude — 2026-09-02 14:42

Now updating the docs to reflect the fix, and committing to PR #176.

---

## Claude — 2026-09-02 14:42

Now the priority line at the top and README row.

---

## Claude — 2026-09-02 14:42

Now updating the agenda doc's final open item and moving this piece to resolved.

---

## Claude — 2026-09-02 14:42

Now adding the group-ownership fix to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:42

Committing to PR #176.

---

## Claude — 2026-09-02 14:43

Fixed and pushed to PR #176. Summary of the fix:

- **D7 source checked first**: 0 of 171 real collection/subcollection nodes have `uid: 0` — the insert-bug workaround was protecting against a case that doesn't exist in this data.
- **Migration config fixed**: `uid: uid` replacing the hardcoded `default_value: 1`, in both migrations, both `config/install` and `config/sync`.
- **Live data corrected on dev-0**: dry-run → 137 fixes needed, 34 already correct, 0 errors → applied → re-verified 171/171 correct.

Agenda is now down to 1 item: **SAML/NetBadge account mapping and the `realname` field decision** — the two genuinely open design questions left from the user-migration loose ends. Want to continue?

---

## User — 2026-09-02 14:43

So how difficult would it be to rewrite the migration scripts so that users would be migrated first and then the collection data so that user ids already existed in the db.

---

## Claude — 2026-09-02 14:44

Pretty easy — Drupal's Migrate API has a built-in mechanism for exactly this, so it wouldn't have needed custom sequencing logic:

**The mechanism: `migration_dependencies`.** Adding a couple lines of YAML to each collection/subcollection migration:
```yaml
migration_dependencies:
  required:
    - d7_users
```
This makes `drush migrate:import --group` (or any group-scoped run) topologically order the users migration before collections automatically — Drupal's migrate runner already respects declared dependencies when deciding execution order. No custom orchestration script needed, just a declaration.

**Combined with the uid fix itself:** once users genuinely run first, `uid: uid` (the straight field copy I just wired in) works cleanly — no lookup plugin needed, since we confirmed the D7→D11 uid numbering is preserved 1:1 by the user migration (source uid *N* becomes destination uid *N* directly, not a remapped sequential ID). So the two changes together — a `migration_dependencies` declaration plus swapping `default_value: 1` for `uid: uid` — would have been maybe a 15-minute fix, not a redesign.

**One honest caveat:** I verified *what* was true of the data (zero D7 collection/subcollection nodes have `uid: 0`), not the *exact internal trigger* of the original Group-module bug the workaround was guarding against. If that bug is purely "the referenced uid doesn't exist yet" (which sequencing fixes), this closes it fully. If it's actually about Group's insert hook running against `\Drupal::currentUser()` (the *migration process's* acting user, which could be anonymous during a batch run regardless of what the destination `uid` field is set to), then correct sequencing wouldn't have been sufficient on its own — that would need a different fix (e.g., temporarily impersonating a real user during the migration batch). I didn't dig into which of these it actually is, since it turned out to be moot for this specific data.

Want me to check the Group module's actual insert-hook code to nail down which mechanism it really was, so this is documented precisely rather than left as a caveat — useful before Texts/Sources/AV hit the same migration pattern?

---

## User — 2026-09-02 14:44

Yes, check the Group module's insert hook code

---

## Claude — 2026-09-02 14:45

Found it — `Group::postSave()` in `drupal/web/modules/contrib/group/src/Entity/Group.php:359-373`:

```php
public function postSave(EntityStorageInterface $storage, $update = TRUE) {
  parent::postSave($storage, $update);

  // If a new group is created and the group type is configured to grant group
  // creators a membership by default, add the creator as a member unless it
  // is being created using the wizard.
  $group_type = $this->getGroupType();
  if ($update === FALSE && $group_type->creatorGetsMembership() && !$group_type->creatorMustCompleteMembership()) {
    $values = ['group_roles' => $group_type->getCreatorRoleIds()];
    $this->addMember($this->getOwner(), $values);
  }
}
```

**This settles the caveat cleanly — it's exactly what sequencing would fix, nothing subtler.**

- `$this->getOwner()` reads the **entity's own `uid` field value** at save time — not `\Drupal::currentUser()`, not the migration process's acting session. If `uid` resolves to `0` (anonymous) at the moment the group is first inserted, `getOwner()` returns the anonymous user account, and `addMember(anonymous, ...)` creates the bogus `uid=0` group membership that caused the "174 stale uid=0 group memberships" symptom found later.
- The block only fires on **`$update === FALSE`** — i.e., only on the *original insert*, never on a subsequent `save()` of an already-existing group. This is also exactly why my live fix earlier today (correcting the 171 groups' `uid` field via `$g->save()` on already-existing entities) was safe and didn't re-trigger any membership creation — it only runs the `postSave` insert branch once, at creation.

**So the retrospective answer is now fully confirmed, no hedging needed:** if the D7 source's real `uid` had been available and correctly mapped *before* the group entity's first save — which just needs (a) `migration_dependencies: required: [d7_users]` so users migrate first, and (b) `uid: uid` instead of the hardcoded default — `getOwner()` would have returned the real creator on that first insert, `addMember()` would have added the real creator as a member (which is actually the *correct*, intended behavior per this code's own comment), and there would have been no bogus `uid=0` membership and no need for the `uid: 1` workaround at all.

Worth folding into the migration checklist for Texts/Sources/AV — same Group-based pattern, same trap. Want me to add a note to `migration-legacy-nid-required-convention.md`'s checklist calling this out explicitly?

---

## User — 2026-09-02 14:45

Yes, add that note to the checklist.

---

## Claude — 2026-09-02 14:45

Adding a new checklist item right after the legacy-nid/site items, since it's the same category of "get real ownership right at first insert."

---

## Claude — 2026-09-02 14:45

Now committing to PR #176.

---

## Claude — 2026-09-02 14:46

Added and pushed to PR #176. The checklist now tells whoever builds the Texts/Sources/AV Group migrations exactly why to sequence users first and map real `uid`, with the confirmed root cause (`Group::postSave()`'s `getOwner()` call on first insert) so nobody has to rediscover this the hard way.

One item left on the agenda: **SAML/NetBadge account mapping and the `realname` field decision.** Want to continue to that one?

---

## User — 2026-09-02 14:46

what's next on the agenda

---

## Claude — 2026-09-02 14:46

**Last item: Loose ends from the user migration — SAML/NetBadge account mapping and the `realname` field.**

**1. SAML/NetBadge account mapping.** Migrated D11 accounts don't yet have a defined strategy for linking to UVA Shibboleth-authenticated sessions on login. The options:
- **Match on `name`/`mail`** — simplest, no schema change, but fragile if either ever diverges between the SAML assertion and the migrated account (renames, email changes).
- **Stored NetBadge identifier field** — a dedicated field holding the authoritative Shibboleth/NetBadge computing-id, matched explicitly on login. More robust, but needs a new field and a value to populate it from (does D7's shared user data even carry a NetBadge id distinct from `name`, or was `name` always the computing-id already?).

This is a real gap: ADR 014's SAML+OAuth2 coexistence work assumes a Drupal account already exists to key `sub` off of — this decision is what determines *which* account that lookup resolves to.

**2. `realname` field.** D11 has no `realname` contrib module installed by default (D7 had it). Decide: install it as a dependency to match D7's display-name behav6or, or fold display-name handling into core user fields (`field_first_name`/`field_last_name`, which the shared-user-database note already documented as migrated fields) instead of adding another module dependency.

Neither of these has a clear "already decided, just needs scheduling" answer like most of today's items did — these are genuinely open design questions. Want to work through either one now, or hold both for the actual meeting since they need more than a quick call?

---

## User — 2026-09-02 14:47

Defer the SAML/NetBadge account mapping to Yuji for a decision

---

## Claude — 2026-09-02 14:47

Now the README row (still needs the `realname` question flagged as remaining, plus the assignment noted) and the agenda doc.

---

## Claude — 2026-09-02 14:47

Now the agenda doc — update the remaining item and move the SAML piece to resolved.

---

## Claude — 2026-09-02 14:47

Now adding the SAML assignment to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:47

Committing to PR #176.

---

## Claude — 2026-09-02 14:48

Assigned and pushed to PR #176. The agenda is down to one item: **the `realname` field decision** — the only thing left unassigned on the whole list. Want to handle that now too?

---

## User — 2026-09-02 14:48

For the realname item fold display-name handling into core user fields

---

## Claude — 2026-09-02 14:48

Good — checked the actual migration (`d7_users.yml`): `field_first_name`/`field_last_name` were never mapped or even added to D11's user entity at all; it's still just a comment saying "decide later." So this decision has a concrete implementation shape. Recording it in the deferred note first.

---

## Claude — 2026-09-02 14:48

Now updating the migration YAML's comment to point at the decision rather than "decide during laptop migration-dev."

---

## Claude — 2026-09-02 14:49

That's expected — `drush cex` strips comments on export, so `config/sync` never carried them; only the raw `config/install` source file does. Now the README row and closing out the agenda doc.

---

## Claude — 2026-09-02 14:49

Now closing out the agenda doc — this was the last open item.

---

## Claude — 2026-09-02 14:49

Wait — I need to correct that. Item 1 (the combined contributor + group-editor Group-role migration) never actually got a scheduling decision in this session — it was folded into one item, but nobody gave it a date or owner. Let me fix that.

---

## Claude — 2026-09-02 14:49

Now adding the `realname` decision to "Resolved during this meeting."

---

## Claude — 2026-09-02 14:49

Now updating the "Outcome" section since the meeting is effectively wrapped, with one item still needing a scheduling call.

---

## Claude — 2026-09-02 14:49

Also updating the doc's top-level `Status` line to reflect where things landed.

---

## Claude — 2026-09-02 14:49

Committing everything to PR #176.

---

## Claude — 2026-09-02 14:50

Decided and pushed to PR #176: **fold `realname` handling into core user fields, no `realname` module.** Not yet implemented (fields need adding + mapping + backfilling the 1,543 already-migrated users), but the decision itself is settled.

The group meeting agenda is now closed out. Final tally: **7 of 8 items resolved** this session (3 real fixes shipped, 1 ADR ratified, 3 items assigned to Yuji), all landed in PR #176. The one item still open is **item 1 — the combined contributor + group-editor Group-role migration** — both permission models are already decided, but nobody gave it a date or owner in this session; that's the one thing left for an actual follow-up.

---

## User — 2026-09-02 14:50

Write the session log, any documentation, and your memory and push everything to the PR first.

---
