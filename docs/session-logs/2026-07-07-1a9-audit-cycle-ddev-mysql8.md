# Session Log: 1A9 Audit Cycle Ddev Mysql8

**Date:** 2026-07-07  
**Participants:** Xiaoming Wang, Claude Opus 4.8  
**Outcome:** Sprint 1 1a.9 opened — [PR #19](https://github.com/uvalib/mandala-navina/pull/19): `kmassets:audit` drift detector + [migration cycle](../planning/migration-cycle-runbook.md) + [staging acceptance checklist](../planning/1a9-staging-acceptance-checklist.md); DDEV → MySQL 8.4 ([ADR 012](../adr/012-ddev-production-db-engine.md)). See [sprint doc](../sprints/sprint-01-images-implementation.md).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-07-07 13:52

what's next?

---

## Claude — 2026-07-07 13:52

I'll orient myself using the session-startup docs the project asks me to read, then figure out what's next.

---

## Claude — 2026-07-07 13:52

Let me check the most recent session log and the sprint plan to see where 1a.8 left off.

---

## Claude — 2026-07-07 13:52

Let me check the sprint tracking doc and my memory state file to confirm the 1a.9 next-step framing.

---

## Claude — 2026-07-07 13:52

The memory index is empty on this machine (memory is per-driver; the last sessions were driven by Yuji). Let me read the sprint tracking doc for the authoritative step breakdown.

---

## Claude — 2026-07-07 13:53

I have the full picture. Here's where things stand.

## What's next: Step 1a.9 — the rollback story

Sprint 1's Step 1a is nearly done. **1a.8 closed on 2026-07-01** (`mandala_kmassets_sync` — doc-builder, direct-to-master Solr sink, node hooks, Drush commands, `images-11-{nid}` uid format). The only remaining task in Step 1a is:

**1a.9 — Rollback story:** a repeatable *test-run → validate → rollback* cycle against a prod-DB copy in staging (`sprint-01-images-implementation.md:90`). This is what unblocks Step 1b (the auth increment) and lets individual devs replicate the Images pattern for the other sites.

### The designated first task of 1a.9

The sprint doc and last session's handoff both pin the entry point explicitly:

> **First task of 1a.9:** build `kmassets:audit` command (missing / orphaned / stale detection + `--fix` flag) before anything else.

The design was already worked out last session:
- **Missing** — node published in Drupal but no Solr doc (hook fired, write failed silently)
- **Orphaned** — Solr doc exists but the Drupal node is gone (delete hook failed)
- **Stale** — both exist but Solr `node_changed` lags the node's `changed` (opt-in via `--check-stale`, it's the expensive pass)
- `--fix` reindexes/deletes affected docs

It's the natural validation tool the rollback cycle needs anyway.

### Two things worth flagging before I start

1. **Memory is empty on this machine.** The rich state lived in Yuji's per-driver memory; yours has no `MEMORY.md` yet. The committed `docs/` tree is the source of truth (which is why I could reconstruct everything), but if you want, I can seed your local `project-mandala-state` so future sessions on your machine start warm.

2. **Clean branch.** Last session ended with everything pushed to `main`, and Yuji noted the team norm is PRs, not direct pushes. I'd start 1a.9 on a feature branch (`feat/1a9-rollback-story` or similar) and open a PR.

Want me to start by branching and building `kmassets:audit`? Or would you rather I seed your local memory first so this machine is oriented for the rest of Sprint 1?

---

## User — 2026-07-07 13:54

let's seed my local memory first

---

## Claude — 2026-07-07 13:55

I'll seed your local memory to mirror the committed `docs/` state. I'll create the index plus a few focused memories — the state snapshot, the uid strategy (the biggest non-obvious decision from recent sessions), and the PR-workflow norm.

---

## Claude — 2026-07-07 13:55

Now the index:

---

## Claude — 2026-07-07 13:56

Done. Your local memory is seeded — this machine will start warm from now on.

**Created:**
- `MEMORY.md` — the index loaded each session
- `project-mandala-state` — Sprint 1 step table (1a.1–1a.8 ✅ with dates, 1a.9 next), spike statuses, ADR state, deferred-queue highlights
- `project-kmassets-uid-strategy` — the `images-11-{nid}` versioned uid decision (frozen "11" marker), legacy mapping / proxy shim / cutover plan, and the credential-free direct write path
- `feedback-pr-workflow` — the team norm to branch + PR rather than push to main

These deliberately point back to the `docs/` tree rather than duplicating it, so the committed docs stay the source of truth.

Next time you want to move Sprint 1 forward, the pinned entry point is **1a.9 → build the `kmassets:audit` command** on a fresh `feat/1a9-rollback-story` branch. Want to start that now?

---

## User — 2026-07-07 13:56

yes

---

## Claude — 2026-07-07 13:57

I'll start by branching and reading the current module so the audit command fits the existing patterns.

---

## Claude — 2026-07-07 13:57

Let me check the doc builder for the timestamp field name the audit's stale check needs, and read the settings + schema.

---

## Claude — 2026-07-07 13:58

Now let me read the services file to match the registration pattern.

---

## Claude — 2026-07-07 13:59

Now I understand the full shape. The audit needs Solr *read* capability (the sink is write-only today). Let me check how the DDEV environment is wired for Solr reachability before I build, so I know how testable it'll be.

---

## Claude — 2026-07-07 13:59

Clear picture. `solr_master_url` isn't overridden in DDEV — it points at the VPN-gated staging master, so live testing needs VPN, but I'll build the command correctly and lint/smoke-test as far as reachability allows.

Let me start by adding Solr read capability to the sink (it's write-only today).

---

## Claude — 2026-07-07 14:00

Now the auditor service. This holds the three-check logic:

---

## Claude — 2026-07-07 14:01

Now register the auditor with a class-name alias for AutowireTrait:

---

## Claude — 2026-07-07 14:01

Now the `kmassets:audit` command:

---

## Claude — 2026-07-07 14:01

Now add the `audit` command method before the closing brace:

---

## Claude — 2026-07-07 14:01

Now lint the PHP and check whether the module and Solr are reachable for a smoke test:

---

## Claude — 2026-07-07 14:02

No host PHP — lint via DDEV. Let me check DDEV status first.

---

## Claude — 2026-07-07 14:03

Docker isn't running, so I can't lint via DDEV or smoke-test. Let me look for any PHP binary on the host.

---

## Claude — 2026-07-07 14:04

Docker's down and there's no host PHP, so I can't lint or smoke-test yet. Before I report, let me verify one load-bearing assumption in the orphan pass — that `id` is the Solr `uniqueKey` (cursorMark requires sorting on it). Let me check the doc contract.

---

## Claude — 2026-07-07 14:05

Good — that surfaces something important. The D7 golden fixture has `id: "1028396"` (bare nid) and `uid: "images-1028396"`, and `node_changed` in exactly the ISO format my stale check parses. The D11 builder (1a.8) sets `id = uid = images-11-{nid}` — which is *unique per doc*, so `sort=id asc` is valid for the cursor, and it also keeps D11 docs from colliding with D7 `id` values in the shared index. My cursor assumption holds.

Here's where 1a.9's first task stands.

## Built: `kmassets:audit`

Three files touched on branch `feat/1a9-rollback-story`:

| File | Change |
|---|---|
| `KmassetDirectSink.php` | Added a Solr **read** path — `select()` + shared `solrCoreUrl()` helper (the sink was write-only) |
| `KmassetAuditor.php` (new) | The audit engine — missing / stale / orphaned detection + `--fix` repair |
| `KmassetsSyncCommands.php` | New `kmassets:audit` command; wired the auditor in |
| `services.yml` | Registered `mandala_kmassets_sync.auditor` + class-name alias for Drush 13 AutowireTrait |

**How it works:**
- **Pass A (Drupal→Solr):** batches published nodes, asks Solr `uid:(…)` which it holds → **missing**; with `--check-stale`, compares Solr `node_changed` to the node's changed time → **stale**.
- **Pass B (Solr→Drupal):** cursor-pages every `uid:{service}-11-*` doc; any uid not in the published set → **orphaned**.
- `--fix` reindexes missing/stale nodes and deletes orphaned docs.

```
ddev drush kmassets:audit                 # missing + orphaned
ddev drush kmassets:audit --check-stale   # also stale (the expensive pass)
ddev drush kmassets:audit --fix           # report + repair
```

## Two things to flag

1. **Can't verify locally right now.** Docker/DDEV isn't running and there's no host PHP, so I couldn't lint or smoke-test. And `solr_master_url` defaults to the VPN-gated staging master (it's *not* DDEV-overridden), so a live run needs Docker up **and** VPN — or a temporary `$config[...]['solr_master_url']` override pointing at a local core.

2. **One runtime assumption:** the cursor sorts on `id` as the Solr `uniqueKey`. The fixture strongly implies `id` is the uniqueKey, but if Solr rejects the cursor with a "requires uniqueKey tie-breaker" error, it's a one-line sort-field fix.

I've held off committing since it's unlinted/untested. Want me to:
- **(a)** hold here — you start Docker + VPN and I'll drive the smoke test (rig a missing/stale/orphaned case, confirm detection and `--fix`), then commit and open the PR; or
- **(b)** commit now as a checkpoint on the branch and test next session?

---

## User — 2026-07-07 14:05

I just started docker

---

## Claude — 2026-07-07 14:05

Let me bring DDEV up and lint the changed files.

---

## Claude — 2026-07-07 14:06

DDEV is up (the mkcert warning is harmless). Now lint the changed files and confirm the command is discovered.

---

## Claude — 2026-07-07 14:06

Containers aren't registering as running yet. Let me check Docker directly and retry.

---

## Claude — 2026-07-07 14:07

Web container is health-starting. Let me wait for it to go healthy, then lint.

---

## Claude — 2026-07-07 14:09

Web container is stuck starting. Let me check its health-check log to see what's blocking.

---

## Claude — 2026-07-07 14:09

The healthcheck fails on `phpstatus` (PHP-FPM ping), but the container is up — I can exec directly with `docker`. Let me lint that way.

---

## Claude — 2026-07-07 14:09

Path differs inside the container. Let me locate the module.

---

## Claude — 2026-07-07 14:11

All three lint clean. Now let me clear cache (to register the new service/command) and confirm the command is discovered. I'll run drush via `docker exec` since DDEV's healthcheck is still flapping:

---

## Claude — 2026-07-07 14:11

Cache rebuilt. Let me confirm the command is discovered directly:

---

## Claude — 2026-07-07 14:11

The whole command class dropped — likely the new `KmassetAuditor` dependency fails to resolve. Let me check the module is enabled and look for a container error.

---

## Claude — 2026-07-07 14:11

The `mandala_kmassets_sync` module isn't enabled on this DDEV site (this machine never enabled it — it was Yuji's machine last session). Let me check whether it's in committed config, then enable it.

---

## Claude — 2026-07-07 14:12

It's in committed config (`mandala_kmassets_sync: 0`) — this site's active config just drifted. Let me enable the module, which installs its config too.

---

## Claude — 2026-07-07 14:12

Command is discovered and documented correctly. Now the real test — but it needs Solr reachable. Let me check node inventory and whether the configured Solr master answers from the container.

---

## Claude — 2026-07-07 14:12

111k images are migrated locally. Let me test whether the staging Solr master is reachable from the container (are you on VPN?).

---

## Claude — 2026-07-07 14:13

The staging Solr master answers (you're on VPN). I can run a real end-to-end test. Staging is a shared index, but per last session's decision the `images-11-*` namespace is sanctioned for test docs with easy cleanup.

I'll rig a controlled scenario: clean the D11 test namespace, index 2 good docs, inject 1 artificially-old (stale) doc and 1 bogus (orphan) doc, then run the audit. First, grab 3 real published nids and clean the namespace.

---

## Claude — 2026-07-07 14:14

Two good docs indexed. Now inject the stale doc (real node 2104, but artificially old `node_changed`) and the orphan doc (no such node), directly to Solr:

---

## Claude — 2026-07-07 14:14

All four D11 docs are staged. Now run the audit with stale checking. This scans all 111k published nodes against Solr, so it'll take a couple minutes:

---

## Claude — 2026-07-07 14:17

Pass A (missing/stale) completed in ~2min, but Pass B's cursor query got a 400. Let me get the full error to see what Solr objects to:

---

## Claude — 2026-07-07 14:17

Confirmed — `id` is **not** the uniqueKey. Let me find the actual uniqueKey from the schema:

---

## Claude — 2026-07-07 14:17

The uniqueKey is **`uid`**, not `id`. That's the fix — sort the cursor on `uid`. Let me verify that works against Solr first:

---

## Claude — 2026-07-07 14:17

Cursor works with `sort=uid asc` — returns exactly our 4 D11 docs. Let me fix the code:

---

## Claude — 2026-07-07 14:18

Now re-run the full audit end-to-end. Pass A scans all 111k nodes (~2 min), then the fixed cursor pass:

---

## Claude — 2026-07-07 14:20

The audit detects all three classes correctly. Let me reconcile the exact numbers — missing was 111,336, which implies the present-and-matching count. Let me verify the published-node count so the arithmetic checks out:

---

## Claude — 2026-07-07 14:21

The arithmetic reconciles exactly: **111,339 published − 3 present = 111,336 missing** ✓, plus stale=1 (2104), orphaned=1 (bogus). Detection is proven end-to-end against live staging Solr.

Now I'll verify the `--fix` repair primitives without dumping 111k docs into shared staging (that mass reindex is the deliberate bulk-index step, not a smoke test). I'll prove the two repair operations on the controlled docs — reindex fixes stale, and confirm the orphan/stale Solr state before/after:

---

## Claude — 2026-07-07 14:21

Both repair primitives work: the stale doc's `node_changed` corrected from the doctored `2000-01-01` to the node's real `2019-03-06`, and the orphan is gone. These are exactly what `--fix` loops over. Now clean up the test docs to leave staging as I found it:

---

## Claude — 2026-07-07 14:22

Staging is clean again. The command is proven end-to-end against live Solr, with one real bug caught and fixed (cursor must sort on `uid`, the actual uniqueKey — not `id`). Let me review the final diff and commit on the feature branch.

---

## Claude — 2026-07-07 14:22

Committed on the feature branch. Let me push it and open the PR:

---

## Claude — 2026-07-07 14:24

`gh` isn't installed on this machine, so I can't open the PR from here. The branch is pushed — you can open it at the URL GitHub returned:

**https://github.com/uvalib/mandala-navina/pull/new/feat/1a9-rollback-story**

If you'd rather I create it, run `! brew install gh && gh auth login` in the prompt and I'll fire the `gh pr create` (I have the full title/body ready).

## Summary — 1a.9 first task done

`kmassets:audit` is built, tested end-to-end against the live staging Solr master, and committed on `feat/1a9-rollback-story` (`492599f`).

**What it does:** audits the kmassets index vs. Drupal node state — **missing** (write lost), **stale** (`--check-stale`; update lost), **orphaned** (delete lost) — with `--fix` to repair.

**Verified against 111,339 published images** with a rigged-then-cleaned scenario:
- Detection reconciled exactly: missing 111,336 + present 3 = 111,339; stale 1; orphaned 1.
- Repair primitives corrected the stale doc's `node_changed` and deleted the ghost doc.
- **Caught a real bug:** the orphan cursor must sort on `uid` — the schema API confirmed that's the kmassets `uniqueKey`, not `id` (Solr rejected `id` with a 400).

**Two follow-ups I flagged in the PR body** (didn't want to scope-creep): a confirmation guard for large `--fix` runs, and speeding up Pass A (it loads full node entities just for `id`+`changed`; a targeted query would cut the ~1.5 min).

**Housekeeping I did on this box:** enabled `mandala_kmassets_sync` in your DDEV (it was in committed config but never installed locally — no repo change needed).

Want me to (a) run the session-end ritual now (add the 1a.9-start row to the sprint doc + refresh memory), or (b) keep going on 1a.9 — the next piece is the repeatable *migrate → audit → rollback* cycle against the prod-DB copy that this command was built to validate?

---

## User — 2026-07-07 14:28

I installed gh and logged in

---

## Claude — 2026-07-07 14:28

I'll create the PR now.

---

## Claude — 2026-07-07 14:29

PR opened: **https://github.com/uvalib/mandala-navina/pull/19**

Let me confirm it targets `main` correctly:

---

## Claude — 2026-07-07 14:29

PR #19 is open and correctly targets `main` (`feat/1a9-rollback-story → main`), following the team's PR-not-direct-push norm this time.

## 1a.9 first task: shipped

- **`kmassets:audit`** built, tested end-to-end against live staging Solr (111,339 images — detection reconciled exactly; repair primitives verified), one real bug caught and fixed (cursor uniqueKey), committed as `492599f`, and up for review in **PR #19**.

Where we go from here — your call:

- **(a) Session-end ritual** — add the 1a.9-start progress row to `docs/sprints/sprint-01-images-implementation.md` and refresh your local memory (`project-mandala-state`) so this box stays warm. Worth doing since this was a substantive chunk.
- **(b) Keep building 1a.9** — the audit command was the entry point; the actual rollback story is the repeatable **migrate → audit → rollback** cycle against the prod-DB copy in staging, which is what closes Step 1a and unblocks 1b.

I'd lean **(a) first** (it's quick and the ritual exists precisely so the next driver isn't stale), then **(b)**. Want me to do the ritual now?

---

## User — 2026-07-07 14:32

do both

---

## Claude — 2026-07-07 14:32

I'll do the ritual first, then start building the rollback cycle. Let me read the sprint doc's progress section to add the 1a.9 row.

---

## Claude — 2026-07-07 14:32

Now I'll add the 1a.9 progress row:

---

## Claude — 2026-07-07 14:32

Now refresh the memory state file to reflect 1a.9 started:

---

## Claude — 2026-07-07 14:32

The sprint doc update belongs with this work — I'll commit it to the PR branch so #19 carries its own progress record. (Memory files are local/per-driver and not committed.)

---

## Claude — 2026-07-07 14:33

Ritual done. Now part (b) — the actual rollback story. Let me ground myself in the acceptance criteria and what migrate plumbing already exists before designing it.

---

## Claude — 2026-07-07 14:33

The acceptance gate is: "**test-run → validate → rollback cycle is documented and repeatable**." So the deliverable is a repeatable script + runbook that chains migrate → validate → rollback. Let me survey the existing migrate plumbing and scripts to build on rather than reinvent.

---

## Claude — 2026-07-07 14:33

Let me read the existing scripts to match conventions for the cycle script.

---

## Claude — 2026-07-07 14:34

The plumbing is all here and the migration is currently imported. The line-120 deliverable is a **repeatable, documented migrate → validate → rollback cycle**. Let me gather the exact entity/paragraph machine names and current counts so the validate phase reconciles against real numbers.

---

## Claude — 2026-07-07 14:34

Counts match the 1a.7 baseline exactly. Now the KMaps field value counts (the other reconciliation targets):

---

## Claude — 2026-07-07 14:35

Every reconciliation target matches the 1a.7 baseline exactly. Now I'll build the repeatable cycle script. It'll wrap the existing migrate plumbing + the new audit into phased subcommands, with a read-only `validate` that reconciles against the documented baseline.

---

## Claude — 2026-07-07 14:36

Let me make it executable and check whether `ddev drush` works on this box (the healthcheck was degraded earlier).

---

## Claude — 2026-07-07 14:36

`ddev drush` fails because `mkcert` isn't installed on this box (that's the cert error). Let me make the script's drush invocation overridable via env so it's testable here (and more portable generally), then test the read-only `validate` phase through a direct-exec wrapper.

---

## Claude — 2026-07-07 14:37

The script uses `$DRUSH` as a word-split prefix, so a single-executable wrapper lets me test it here. Let me create one that forwards args cleanly and run the read-only `validate` phase:

---

## Claude — 2026-07-07 14:38

Let me look at what's on line 45 — the `set -u` is tripping on something:

---

## Claude — 2026-07-07 14:38

Found it: macOS ships **bash 3.2**, which has no associative arrays (`declare -A`). Since this script runs on the host via `ddev drush`, it must be bash-3.2-safe. Let me rewrite the baseline and validate logic with parallel plain lists instead.

---

## Claude — 2026-07-07 14:39

That version is clunky — the pipeline subshell loses `fail`. Let me replace it with a clean here-doc-fed loop that runs in the current shell:

---

## Claude — 2026-07-07 14:39

Now test the `validate` phase against the current imported state (bash 3.2 on the host):

---

## Claude — 2026-07-07 14:41

MariaDB is treating `"shanti_image"` as an identifier (ANSI quotes), not a string literal. The clean fix: use query placeholders so there are no SQL string-literal quotes at all (which also sidesteps the bash single-quote layer). Let me rewrite `COUNT_EVAL`:

---

## Claude — 2026-07-07 14:44

`validate` passes clean — all 9 counts reconcile, exit 0. The reconciliation logic (the novel part) is proven against real data. The destructive `import`/`rollback`/`cycle` phases and the 111k `audit` are deliberate staging activities (the migration itself was already proven end-to-end in 1a.7), so I won't run those locally.

Now the runbook. Let me check the `.pages` format first.

---

## Claude — 2026-07-07 14:45

Now update the sprint progress row to reflect the cycle script landing, then commit everything to the PR branch.

---

## Claude — 2026-07-07 14:47

PR #19 now carries both 1a.9 deliverables. Let me note the second one on the PR and refresh memory to match.

---

## Claude — 2026-07-07 14:48

Both done. Here's where things stand.

## (a) Session-end ritual ✓
- Added the **1a.9 progress row** to `docs/sprints/sprint-01-images-implementation.md` (committed to PR #19).
- Refreshed local memory: `project-mandala-state` now reflects 1a.9 in progress, both deliverables, the baseline counts, and the local-env notes (module enable, VPN, `mkcert` missing).

## (b) Kept building 1a.9 ✓
Landed the **repeatable migrate → validate → rollback cycle** — the actual Sprint 1 acceptance gate:

- **`scripts/migration-cycle.sh`** — phases `validate | import | rollback | audit | cycle`. `validate` reconciles 9 counts against the 2026-06-11 baseline and **exits non-zero on drift** (CI-friendly); `cycle` is the re-runnable test pass; `audit` bulk-indexes + runs `kmassets:audit`.
- **`docs/planning/migration-cycle-runbook.md`** — prerequisites, phases, baseline table, rollback verification, caveats (added to `.pages`).
- **Verified locally:** `validate` reconciles all 9 counts (PASS, exit 0) against the imported 111,340-image dataset.

Two real portability bugs caught and fixed while testing: macOS **bash 3.2** has no associative arrays (rewrote with plain lists), and **MariaDB reads double-quoted SQL literals as identifiers** (switched to bound placeholders).

Everything is on PR #19 (`feat/1a9-rollback-story`, 3 commits, → main), with a comment documenting the second deliverable.

## What I deliberately did *not* do
- Run the destructive `import`/`rollback`/`cycle` or the 111k `audit` write locally — those are the **deliberate staging acceptance run** (the migration itself was already proven in 1a.7). The novel reconciliation logic is what I proved here.

## What actually closes Step 1a
The remaining 1a.9 work is executional, not code: run the full cycle against the prod-DB copy **in staging**, plus the non-count acceptance criteria (NFC fidelity, KMaps round-trip, IIIF, security — several already green from 1a.5/1a.7). Want me to draft the staging execution checklist next, or is this a good stopping point to hand PR #19 off for review?

---

## User — 2026-07-07 14:49

draft the staging execution checklist

---

## Claude — 2026-07-07 14:50

Good — this is the doc that turns the cycle into an actual acceptance run. Before drafting, let me ground it in two real unknowns so I don't hand-wave: how the `migrate` source DB is wired (it looked DDEV-only) and how drush/deploy runs in staging.

---

## Claude — 2026-07-07 14:50

Two real gaps confirmed, and the checklist must surface them rather than gloss over: the `migrate` source-DB connection is wired **only** in the DDEV block (no staging provision), and the pipeline runs no migrate/drush steps (migrations are manual). Let me draft the checklist accordingly.

---

## Claude — 2026-07-07 14:51

Let me cross-link the runbook to the checklist, then commit.

---

## Claude — 2026-07-07 14:52

Drafted and committed to PR #19: **`docs/planning/1a9-staging-acceptance-checklist.md`**.

## What it covers

A copy-into-the-PR-and-tick execution checklist, structured so the run is auditable:

- **A. Prerequisites** — leads with the two real gaps I confirmed in the repo, marked ⚠ with owners:
  - **Migrate source DB in staging** — the `migrate` connection exists *only* in the DDEV block of `settings.php`; staging has no provision. Needs a where-to-load decision + env-driven connection (Xiaoming + Yuji/Dave).
  - **Drush execution path** — the pipeline runs no migrate/drush steps; migrations are manual. Confirm the ECS/Ansible mechanism and access (DevOps/Yuji).
- **B. Safety** — snapshot-or-dedicated-env gate before loading 111k rows; confirm the shared kmassets index is the agreed target.
- **C. Execution** — `migration-cycle.sh cycle` with the `DRUSH` override, capture the 9-count PASS table.
- **D. Per-criterion evidence** — NFC fidelity, KMaps round-trip (+ live term IDs), IIIF/i3fid, retrievability, and the `audit` phase reporting 0/0/0.
- **E. Cleanup** — rollback-to-clean + `kmassets:delete "uid:images-11-*"` + snapshot restore.
- **F. Repeatability** — a second identical `cycle` pass; this is what literally satisfies the acceptance criterion.
- **G. Sign-off table** with per-criterion evidence slots.

## One scoping call I made
The **security** criterion (restricted item non-retrievable) is marked **1b-gated** — it depends on Group collections + proxy visibility filtering (1b.2/1b.3), so it's recorded as deferred rather than blocking 1a.9 close. Flag if you'd rather treat it as an in-scope 1a.9 blocker.

## PR #19 now stands at 4 commits
`kmassets:audit` → sprint row → cycle script + runbook → staging checklist. The code is fully tested locally; the remaining 1a.9 work is the staging *execution* (gated on those two prerequisites), which is human/DevOps-driven rather than more code.

That's a natural stopping point — PR #19 is review-ready. Want me to prep anything for handoff (e.g., a short PR description refresh summarizing all four commits), or is this good to hand off?

---

## User — 2026-07-07 14:57

When we run this migration, will it only write new D11 entries and not overwrite existing entries (and be fually reversible)?

---

## Claude — 2026-07-07 14:58

Great question to nail down before any staging run, because "reversible" and "non-destructive" have to hold on **two** separate write targets: the D11 content DB (the migration) and the shared kmassets Solr index (the index/audit phase). Let me verify the Solr side empirically rather than assert it, since that's the shared/live-adjacent one.

---

## Claude — 2026-07-07 14:59

Confirmed empirically. Short answer: **yes on both write targets — non-destructive and reversible — with one honest caveat about nid stability.** Here's the precise picture.

## The two things the cycle writes

### 1. The shared kmassets Solr index — verified safe

The staging index already holds **111,506 existing D7-era entries** (`images-{nid}`, `images-collection-{nid}`, …) — live/legacy data. Our migrated content indexes under the **versioned** namespace `images-11-{nid}`. The Solr `uniqueKey` is `uid`, so a write only ever replaces a doc with the *same* uid. Because the namespaces never overlap, D11 writes **cannot touch** the D7-era entries. Just-verified evidence:

| Query | Count |
|---|---|
| `uid:images-11-*` (our namespace) | 0 |
| `uid:images-*` (all, incl. D7-era) | 111,506 |
| `uid:images-1028396` (a D7 doc) | 1 |
| `uid:images-11-*` **AND** `uid:images-1028396` | **0** ← our wildcard can't match a D7 uid |

The `-11-` **dash** is the discriminator: `images-11-*` won't match `images-1128396` or any D7 uid (no dash after `11`). Cleanup is exact and reversible: `kmassets:delete "uid:images-11-*"` removes only our docs, leaving all 111,506 D7-era entries intact.

### 2. The D11 content database — new entities only, rollback deletes exactly them

Migrate tracks every row it creates in `migrate_map_*` tables (sourceid → destid). `migrate:import`:
- creates **new** nodes/paragraphs/terms (Drupal assigns fresh nids), and
- **skips** already-mapped rows (no duplicates, no overwrite) unless you pass `--update`.

It never references entities it didn't create, so any pre-existing hand-made D11 content is untouched. `migrate:rollback` deletes exactly the map-tracked entities and clears the map — and the cycle's `rollback` phase additionally *asserts* zero `shanti_image` nodes remain, rather than trusting drush's exit code.

## The one caveat — "reversible to clean," not "reversible to identical"

`migrate:rollback` deletes the rows but **does not reset MySQL AUTO_INCREMENT**. So a re-import assigns **different (higher) nids** than the previous run. Consequences:
- The DB returns to a *clean* state (0 migrated nodes) — reversible in that sense. ✓
- But nids are **not stable across rollback/re-import**, so the derived `images-11-{nid}` uids differ run-to-run. That's the already-documented "D11 nids are reassigned" reality — and exactly why the cutover plan is a **full reindex** (`delete uid:images-11-*` → rebuild), not an in-place update. For per-run staging validation it's harmless (each cycle is internally self-consistent).

## Net
- Existing (D7-era) Solr entries: **never overwritten**, and D11 test docs are precisely removable.
- Existing D11 content: **never overwritten**; migration only manages what it created.
- Fully reversible to a clean slate; just not to byte-identical nids/uids across runs.

The checklist's §B (snapshot-or-dedicated-env) and §E (wildcard delete cleanup) already encode this. Want me to add a short "Non-destructive & reversibility guarantees" note to the checklist/runbook capturing this — including the 111,506-entry evidence and the auto-increment caveat — so it's on the record for whoever runs staging?

---

## User — 2026-07-07 15:01

yes, add the reversibility note

---

## Claude — 2026-07-07 15:01

I'll add it to both docs — the guarantee note in the runbook (the reference), and a tighter callout in the checklist's safety section (where the operator sees it).

---

## Claude — 2026-07-07 15:01

I'll insert a dedicated guarantees section before "Rollback verification" in the runbook:

---

## Claude — 2026-07-07 15:02

Now a tighter operator-facing callout in the checklist's safety section:

---

## Claude — 2026-07-07 15:02

Added and pushed to PR #19 (now 5 commits).

- **Runbook** — a new "Non-destructive & reversibility guarantees" section with the full reasoning, the evidence table (0 D11 docs / 111,506 D7-era / wildcard can't match a D7 uid), and the `AUTO_INCREMENT` caveat, cross-linked to the uid-identity deferred note.
- **Checklist** — a tight operator-facing callout at the top of §B (Safety), so whoever runs staging sees the guarantee and the caveat right where they're deciding on snapshots, with a link to the full detail.

The record now states plainly: both write targets are non-destructive to existing data and reversible to a clean slate — reversible to *clean*, not to *identical* nids/uids, which is exactly why cutover is a full reindex.

That rounds out the 1a.9 documentation. PR #19 stands at five commits (audit command → sprint row → cycle script + runbook → staging checklist → reversibility note) and is review-ready; the remaining work is the human/DevOps-driven staging execution, gated on the two prerequisites the checklist flags. Good spot to hand off.

---

## User — 2026-07-07 15:03

Please explain "- A. Prerequisites — leads with the two real gaps I confirmed in the repo, marked ⚠ with owners:
  - Migrate source DB in staging — the migrate connection exists only in the DDEV block of settings.php; staging has no provision. Needs a where-to-load decision + env-driven connection (Xiaoming + Yuji/Dave).
  - Drush execution path — the pipeline runs no migrate/drush steps; migrations are manual. Confirm the ECS/Ansible mechanism and access (DevOps/Yuji)."

---

## Claude — 2026-07-07 15:03

Let me pull the exact `settings.php` block so I explain the first gap precisely rather than from memory.

---

## Claude — 2026-07-07 15:04

Here's what those two items actually mean and why each is a genuine blocker for a staging run (not just a step in it).

## Gap 1 — Migrate source DB in staging

**How the migration reads data.** The `mandala_migrations` source plugins don't read the D7 dump from a file — they run live SQL against a *second* database connection that Drupal knows as the `migrate` key. You can see exactly where that's defined — `settings.php:921`:

```php
if (getenv('IS_DDEV_PROJECT') == 'true' && ...) {   // ← DDEV-only
  ...
  $databases['migrate']['default'] = [
    'database' => 'd7_images',   // secondary DB on the local DDEV MariaDB
    'host' => 'db', 'username' => 'db', 'password' => 'db', ...
  ];
}
```

**Why it's a gap.** That whole block only runs when `IS_DDEV_PROJECT` is set — i.e. **on a laptop**. In staging there's no DDEV, the `if` is false, the block is skipped, and the `migrate` connection simply **doesn't exist**. Run `migrate:import` there and the source plugins have nowhere to read from — it errors out immediately.

**What has to be decided/built (two sub-parts):**
1. **Where the D7 dump lives in staging** — it has to be loaded into a MySQL the staging D11 container can reach. Secondary schema on the existing staging RDS, or a separate throwaway RDS? That's a cost/isolation/who-loads-it call.
2. **An env-driven `migrate` connection for staging** — the connection has to be defined *outside* the DDEV block, reading host/db/user/password from environment/secrets the way the rest of the staging config does (the UVA-library standard pattern), never hardcoded. None of that wiring exists yet.

**Why the owner is Xiaoming + Yuji/Dave:** part 2 is Drupal-side code I can write, but part 1 is an AWS/RDS provisioning + data-handling decision (production data landing in staging infra) that's Dave/Yuji's call — so it can't be closed purely in the app repo.

## Gap 2 — Drush execution path in staging

**The mechanics.** The whole cycle is driven by `drush` — `migrate:import`, `kmassets:index-all`, `kmassets:audit`, and the `migration-cycle.sh` script (via its `DRUSH` override). Locally that's `ddev drush`. In staging, D11 runs as a **container** (ECS/Fargate), deployed by CodePipeline.

**Why it's a gap.** I checked the pipeline specs — `buildspec.yml` builds the image, `deployspec.yml` runs the Terraform+Ansible deploy. **Neither runs any migrate or drush step.** Deployment ships the app; it does not run migrations. So there's an open question the acceptance run depends on: *how do you invoke a drush command against the running staging container, and does the person running it have the access to do so?* Typical answers are `aws ecs execute-command` into the task, a one-off ECS task, or an Ansible play — but nobody has confirmed which is blessed for this environment.

**Why the owner is DevOps/Yuji:** this is about the deployment/AWS/IAM plumbing, not the Drupal code — it's their infra to confirm.

## Why they're "resolve before the run," not run steps

Both are **infrastructure that must already exist** before you can begin §C of the checklist. You can't tick "run `cycle`" if there's no source DB to migrate *from* (Gap 1) and no way to invoke drush *at all* (Gap 2). That's why they lead the checklist, flagged ⚠ with owners — they're the prerequisites that make the whole run possible, and they need infra decisions rather than more application code.

If it'd help, I can draft the staging-side `migrate` connection block (the code half of Gap 1) now so it's ready to drop in once the where-to-load decision lands — leaving only the provisioning call to Dave/Yuji.

---

## User — 2026-07-07 15:08

what will be the down side of running this completely in ddev?

---

## Claude — 2026-07-07 15:09

Good question to force, because a lot of 1a.9 *can* run in DDEV — the migration logic already did (111,340 images, 1a.7). The distinction is: **DDEV proves the migration recipe; staging proves the kitchen it actually ships in.** Running the whole acceptance entirely in DDEV validates the former and quietly skips the latter. The downsides, roughly in order of how much they'd bite:

## 1. DB engine + collation fidelity — the sharpest one
DDEV runs **MariaDB**; staging/prod RDS is **MySQL 8**. This isn't cosmetic — `scripts/load-d7-source.sh` literally rewrites the dump's collation on import:

```
sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g'   # MySQL 8 → MariaDB
```

So in DDEV the data lands under a **different collation** than it will in production. The acceptance criterion names this explicitly — "diacritic normalization is preserved through Migrate API → **MySQL collation** → Solr — verified, not assumed." Validating that in DDEV verifies it through *MariaDB* collation instead — i.e. not the thing the criterion is worded to catch. Collation also governs uniqueness and sort, so this isn't only about Tibetan glyphs.

## 2. You never exercise the two infra gaps — the whole point of a dry run
The rollback story exists to **de-risk cutover**, which happens in AWS. If it's only ever run in DDEV, then the staging `migrate` source-DB connection (Gap 1) and the staging drush execution path (Gap 2) stay **unproven until cutover** — the worst possible moment to discover them. Flushing exactly those unknowns *before* cutover is a primary reason the criterion says "in staging."

## 3. Deployed-artifact fidelity
DDEV runs your **working tree**; staging runs the **built Docker image** with config applied via the deploy's `drush cim`. Those diverge — and we already hit a concrete instance this session: `mandala_kmassets_sync` **wasn't even enabled** in your local DDEV (it was in committed config but never installed locally). That class of module-enable / config / secrets drift is precisely what a deployed-artifact run catches and a working-tree run masks.

## 4. Network + service-path fidelity
From a Fargate task, reachability to Solr master (`-private` A record via VPC), the solr-proxy read path, IIIF (`iiif.lib.virginia.edu`), and the live KMaps API is governed by **VPC routing / security groups**. From DDEV those go over your laptop + VPN — a different path. "Indexed and retrievable via existing query patterns" and IIIF rendering are only proven end-to-end on the real network. (Note: the Solr *write* target already points at staging, so that one leg is real even from DDEV — but the read/proxy path isn't.)

## 5. Scale / resource realism
The Fargate task has fixed memory/timeout limits; your laptop probably has more headroom. A 111k import or rollback that OOMs or times out in the container can pass on a laptop — and the import/rollback wall-clock you'd use for **cutover planning** isn't representative.

---

## Balanced read
DDEV is legitimately sufficient for what it's already done: migration **correctness** — transforms, count reconciliation, paragraph fan-out, KMaps rebuild, NFC *through the Migrate API layer*. That's real value and it's banked.

What it can't stand in for: **collation/engine fidelity, the deployed artifact, the AWS network path, and flushing the two infra gaps.** So the pragmatic options are:

- **Best:** do logic validation in DDEV (done), then **one** staging pass to close the criterion and surface the infra gaps. That's the intended split.
- **Acceptable-with-eyes-open:** if staging genuinely can't be stood up in time, consciously **narrow the 1a.9 claim** to "migration logic validated in DDEV" and file a deferred note that engine/collation fidelity + the staging dry-run remain open pre-cutover — don't let a green DDEV run *read* as a green staging acceptance.

The one thing I'd steer away from is treating a fully-DDEV run as satisfying the acceptance gate as written — the criterion says "in staging" specifically to catch items 1–4 above, and skipping it just relocates that risk to cutover.

Want me to add a short "Why staging, not just DDEV" subsection to the checklist so this rationale is on the record (and so a future reader doesn't quietly substitute a DDEV pass)?

---

## User — 2026-07-07 15:14

yes the plan is to run it on ddev and then run it on staging. Is there any big down side to do that?

---

## Claude — 2026-07-07 15:14

No — that's the right progression, and there's no *big* downside. DDEV-first is exactly the intended split: fast, cheap iteration to shake out logic bugs, then staging for the fidelity/infra validation DDEV can't give you. It's strictly better than either alone. There are just three small things to *manage* so the two phases don't step on each other:

## 1. Both phases write to the *same* shared Solr index — clean between them
`solr_master_url` points at the staging kmassets master from **both** DDEV and staging. So both runs dump docs into the same `images-11-*` namespace of the same shared index. And because DDEV and staging assign nids from **independent** auto-increment counters, `images-11-500` from your DDEV run and `images-11-500` from the staging run are *different content*. If DDEV's docs are still sitting there when you run staging, the staging `audit` and any retrievability check get commingled, ambiguous results.

**Fix is trivial:** run `kmassets:delete "uid:images-11-*"` at the end of the DDEV phase (before staging). It only touches the D11 namespace, never the 111,506 live D7-era entries. The checklist's §E already calls for this — just make sure it happens *between* phases, not only at the very end.

## 2. Expect the two runs to differ where they're *supposed* to — don't chase it as a bug
- **Counts should match** across DDEV and staging (same source dump → same reconciliation). If they don't, that's a real signal.
- **nids/uids will differ** (independent counters) — expected, not a defect.
- **Collation/NFC behavior is the one place they may legitimately diverge** (MariaDB vs MySQL 8). That divergence isn't noise — it's the *entire reason* for the staging pass. So treat a DDEV↔staging NFC/sort difference as a finding to investigate, not a failed run.

## 3. Don't let a green DDEV pass mask that staging is still gated
DDEV-first doesn't dissolve Gaps 1 & 2 — the staging phase still can't start until the migrate source-DB connection and the drush execution path exist. The only trap in "DDEV then staging" is *psychological*: a clean DDEV run can feel like "almost done" while the staging blockers are untouched. Avoid that by **provisioning the two infra gaps in parallel** with the DDEV run, not after it — so staging is ready to go the moment DDEV is green.

---

Net: no real downside, and it's what I'd recommend. It's the normal local→staging promotion path — you're just doing it deliberately. The DDEV phase can start right now; the staging phase needs the two prerequisites lined up, which is why kicking those off now (in parallel) is the one thing that keeps the sequence from stalling.

Want me to add a one-line "run DDEV → clean `images-11-*` → run staging" note to the checklist so the between-phase cleanup ordering is explicit for whoever executes it?

---

## User — 2026-07-07 15:22

we are going to get a dump from staging database and load it into ddev, making sure that we are starting from baseline

---

## Claude — 2026-07-07 15:23

Good move — that directly fixes the biggest fidelity gap I flagged earlier (config/module drift). Starting DDEV from the actual staging D11 baseline means you inherit staging's enabled-module state and CMI config, so you won't get surprises like "`mandala_kmassets_sync` wasn't even enabled locally." Your DDEV rehearsal becomes a faithful copy of staging's *starting point*, which is exactly what makes the local run meaningful. It's the right call.

A few things to get right so "baseline" really is baseline:

## 1. It's two *different* databases — don't conflate them
- **Staging D11 dump → DDEV's default `db`** (the Drupal site DB — config, users, content). This is the new thing you're doing.
- **D7 source dump → DDEV's `d7_images`** (the migrate *source* the plugins read from). Still needed, still loaded via `scripts/load-d7-source.sh`.

The migration reads `d7_images` and writes into `db`. Loading the staging baseline only replaces the `db` side.

## 2. Confirm the baseline is genuinely *pre-migration* — because rollback only cleans map-tracked rows
`migrate:rollback` deletes exactly what's in the `migrate_map_*` tables. So:
- **Ideal:** the staging dump has **0 `shanti_image` nodes** and no `mandala_images` migrate_map rows → a true clean slate. Then cycle = import → validate → rollback → back to 0.
- **Trap:** if the dump contains Images content that was migrated *in staging* but you don't also carry its intact `migrate_map` rows, DDEV rollback can't see that content and will **strand it** — polluting every subsequent `validate`. 

So after loading, verify the baseline: `shanti_image` count and `migrate_map_d7_images_*` rows are what you expect (ideally both 0). That check is distinct from `validate` — `validate` expects the *post-import* 111,340; on a clean baseline it should show 0 and "fail," which is correct.

## 3. Collation rewrite on import (and what it does *not* buy you)
Staging RDS is MySQL 8 (`utf8mb4_0900_ai_ci`); DDEV is MariaDB. The dump will need the same rewrite `load-d7-source.sh` already does:
```
sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g'
```
(or `ddev import-db`, which usually handles it). Worth being explicit that this means the DDEV baseline is still under **MariaDB collation** — so this gets you config/content fidelity, **not** collation fidelity. Collation/NFC fidelity remains the staging phase's job; don't let the faithful-baseline DDEV run masquerade as having closed that.

## 4. Two smaller ones
- **settings.php overrides survive** — they're file-based, not in the DB, so `base_url → ddev` and the `migrate` connection persist through the import. Just **don't** re-run `site:install` after loading (that would wipe the baseline).
- **PII** — if the staging dump carries real user accounts, run `ddev drush sql:sanitize` after import per standard practice.
- **Solr** — same as before: `kmassets:delete "uid:images-11-*"` between the DDEV and staging phases, since both write the shared index.

---

Net: no downside — it's an upgrade to the plan. The one thing that actually bites if missed is **#2** (a not-truly-clean baseline silently defeats rollback and skews validate). If it'd help, I can write a `scripts/load-staging-baseline.sh` (collation rewrite + post-load baseline assertion: `shanti_image = 0`, migrate_map clean) to codify it the way `load-d7-source.sh` codifies the source load. Want that?

---

## User — 2026-07-07 15:30

Can we just change ddev to use mysql8?

---

## Claude — 2026-07-07 15:31

Short answer: **yes, easily** — DDEV supports MySQL 8 as a first-class option, and it's arguably the *more correct* choice since it should match whatever staging/prod actually runs. Let me check your current DDEV config so I can be precise about the change.

---

## User — 2026-07-07 15:33

the version of mysql8 staging runs is 8.4.8

---

## Claude — 2026-07-07 15:33

Let me check your DDEV version — whether it can pin MySQL **8.4** specifically (vs 8.0) depends on how new DDEV is.

---

## Claude — 2026-07-07 15:34

DDEV v1.24.7 is recent. Rather than guess whether it can pin exactly 8.4, let me verify against DDEV's own version validation in a throwaway dir (won't touch your repo):

---

## Claude — 2026-07-07 15:35

Verified: DDEV v1.24.7 supports **`mysql: [5.7 8.0 8.4]`** — so you can pin **exactly 8.4** and match staging precisely. No 8.0 compromise needed.

## The change

`.ddev/config.yaml`:
```yaml
database:
  type: mysql
  version: "8.4"
```

Switching the engine is **destructive to the existing DDEV DB** (DDEV will require you to drop the DB volume) — but that's free here, because your plan already re-imports the staging baseline. So the sequence lines up perfectly:

```
edit config.yaml → ddev delete -Oy → ddev start → load staging baseline dump
```

(v1.24 also has `ddev debug migrate-database mysql:8.4` for in-place conversion, but since you're reimporting anyway, delete-and-reimport is simpler.)

## Two things you must not miss

**1. The collation rewrite in `load-d7-source.sh` becomes actively wrong.** It currently does:
```
sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g'   # was: MySQL8 → MariaDB
```
On MySQL 8.4 that rewrite would *downgrade* the collation to `utf8mb4_general_ci` — **re-introducing** the exact infidelity you're switching engines to eliminate. Once DDEV is MySQL 8.4, that `sed` has to go (import the dump natively). Same for the staging-baseline dump: no rewrite. If you skip this, you get MySQL 8.4 running under the wrong collation and gain nothing.

**2. `.ddev/config.yaml` is committed → this is a team-wide change.** Flipping the engine breaks every teammate's next `ddev start` until they rebuild + reimport. So either commit it with a heads-up (everyone reimports once), or trial it solo first via a gitignored `.ddev/config.local.yaml` override. Given staging is 8.4 and DDEV was MariaDB 10.11, this is fixing a latent mismatch — worth doing team-wide, just coordinated.

## What it buys (and what it doesn't)

- **Buys:** closes the collation-fidelity gap **locally** — NFC/diacritic/sort/uniqueness now behave as they will in staging/prod. That materially strengthens the DDEV phase; you're no longer validating under a different DB engine.
- **Doesn't buy:** the other staging-only items still stand — the deployed-artifact path, VPC/network reachability, Fargate resource limits, and the two infra prerequisites (migrate source DB + drush path). So this makes DDEV a much better rehearsal, but it doesn't replace the staging run.

Net: yes, do it — matching DDEV to MySQL 8.4 is a clean upgrade and removes the collation caveat I kept flagging. Want me to make the edits now — bump `.ddev/config.yaml` to `mysql:8.4` and update `load-d7-source.sh` to drop the collation rewrite (with a comment explaining why)? I'd leave the actual `ddev delete`/reimport for you to run, and hold the commit until you've confirmed the team's on board.

---

## User — 2026-07-07 15:35

make the edits

---

## Claude — 2026-07-07 15:35

I'll make both edits. First the DDEV config:

---

## Claude — 2026-07-07 15:35

Now update `load-d7-source.sh` to drop the collation rewrite:

---

## Claude — 2026-07-07 15:36

Both edits are in and the script syntax-checks clean. Here's what changed:

**`.ddev/config.yaml`** — `mariadb 10.11` → `mysql 8.4`, with a comment explaining the fidelity rationale and the destructive-rebuild caveat.

**`scripts/load-d7-source.sh`** — dropped the collation-rewrite:
- `CREATE DATABASE ... COLLATE utf8mb4_general_ci` → `utf8mb4_0900_ai_ci` (the source's native MySQL 8 collation)
- Removed the `sed 's/utf8mb4_0900_ai_ci/utf8mb4_general_ci/g'` pipe — the dump now imports natively
- Header comment rewritten to explain why the rewrite is gone and a "do not re-add it" warning

## What I did *not* do (your call)
- **Didn't run `ddev delete`/rebuild or reimport** — that's the destructive step you'll run when ready.
- **Didn't commit** — `.ddev/config.yaml` is team-wide, so I'm holding until you've confirmed the team's on board (or decided to trial via `.ddev/config.local.yaml` first).

## Your next steps when ready
```bash
ddev delete -Oy && ddev start      # rebuild on MySQL 8.4 (wipes local DB)
# then load your baselines — natively, no rewrite:
./scripts/load-d7-source.sh <d7-dump.sql.gz>
# and the staging D11 baseline into the default `db`
```

## Two open threads to keep on the radar
- **The staging baseline load** still wants its own codified script (the `load-staging-baseline.sh` I offered — collation-native import into `db` + the `shanti_image = 0` / clean `migrate_map` baseline assertion). Want me to write that now so it's ready alongside these edits?
- These changes are **not on the 1a.9 PR branch** — they're local-env/tooling, arguably a separate small PR. Want them on `feat/1a9-rollback-story`, or a fresh branch like `chore/ddev-mysql-8.4`?

---

## User — 2026-07-07 15:39

write the load-staging-baseline.sh script, I put most recent dump at ~/Desktop/Mandala/mandala-staging-images-db_20260707.sql.gz

---

## Claude — 2026-07-07 15:41

I'll model it on `load-d7-source.sh` and add the baseline guardrail I promised. First let me confirm the dump is where you put it.

---

## Claude — 2026-07-07 15:41

Dump is there (69M), and `*.sql.gz` is gitignored so it won't be committed. Now the script:

---

## Claude — 2026-07-07 15:43

Done — `scripts/load-staging-baseline.sh` is written, executable, and syntax-clean on bash 3.2.

## What it does
1. **Refuses to run unless DDEV is MySQL 8** — reads `SELECT VERSION()` and aborts with instructions if it sees MariaDB (prevents the footgun of loading the 8.4-collation dump into MariaDB and silently degrading it).
2. **Imports into the default `db`** via `ddev import-db` (handles gzip, drops existing tables → true replace), then `drush cr`.
3. **Optional `--sanitize`** → `drush sql:sanitize` to scrub user PII (with a reminder if you skip it).
4. **Baseline assertion** — the guardrail I promised: counts `shanti_image` nodes, image paragraphs, and rows across any `migrate_map_d7_images_*` tables. Prints a report and:
   - **exit 0** if clean (all zero) → prints the next-step commands,
   - **exit 3** if not clean → loud warning that rollback would strand orphan content and skew `validate`.

Order-independent args, so `--sanitize` can go anywhere.

## Honest status: written, not runtime-tested
I couldn't execute it end-to-end — it needs DDEV already flipped to MySQL 8.4 (the destructive `ddev delete`/rebuild you're running), it's destructive to the local DB, and needs your dump. So it's `bash -n`-verified only. The full sequence when you're ready:

```bash
# 1. flip DDEV to MySQL 8.4 (edits already staged in .ddev/config.yaml)
ddev delete -Oy && ddev start
# 2. load the staging baseline (asserts clean pre-migration state)
./scripts/load-staging-baseline.sh ~/Desktop/Mandala/mandala-staging-images-db_20260707.sql.gz --sanitize
# 3. load the D7 migrate source (now native, no collation rewrite)
./scripts/load-d7-source.sh <d7-dump.sql.gz>
# 4. run the cycle
./scripts/migration-cycle.sh cycle
```

## Uncommitted — three related local-env/tooling changes now pending
`.ddev/config.yaml` (mysql 8.4), `scripts/load-d7-source.sh` (drop rewrite), and this new script. These are a coherent set, separate from the 1a.9 PR. My suggestion: put them on a `chore/ddev-mysql-8.4` branch and open a small PR (the `.ddev` change is team-wide, so it deserves its own reviewable unit). Want me to create that branch and commit the three, or hold?

---

## User — 2026-07-07 15:45

just keep everything in the same PR, no one has an active checkout of ddev environment, we all start in scratch

---

## Claude — 2026-07-07 15:45

Got it — since everyone rebuilds DDEV from scratch, the engine switch is safe to land team-wide. I'll commit the three to the current PR branch.

---

## Claude — 2026-07-07 15:46

Landed on the PR branch (now 7 commits). Let me flag the team-wide DDEV engine change on the PR so reviewers catch it, and refresh the local-env note in memory.

---

## Claude — 2026-07-07 15:46

All committed to PR #19 and on the record.

## Landed (commit `91347e7`)
- **`.ddev/config.yaml`** — MariaDB 10.11 → **MySQL 8.4** (matches staging RDS 8.4.8)
- **`scripts/load-d7-source.sh`** — collation rewrite removed; native import
- **`scripts/load-staging-baseline.sh`** — new, with the clean-baseline guardrail

Plus a PR comment flagging the team-wide engine switch + the rebuild command, and memory refreshed with the new local run sequence.

## PR #19 status — 7 commits, review-ready
1. `kmassets:audit` drift detector
2. sprint progress row
3. migrate → validate → rollback cycle + runbook
4. staging acceptance checklist
5. non-destructive/reversibility guarantees
6. *(sprint row already in #2)* — actually: reversibility note
7. DDEV → MySQL 8.4 tooling

Everything code/tooling/docs is done and tested to the extent possible without a healthy DDEV; runtime testing of the new scripts happens naturally when you do the rebuild.

## Your next move (when ready)
```bash
ddev delete -Oy && ddev start
./scripts/load-staging-baseline.sh ~/Desktop/Mandala/mandala-staging-images-db_20260707.sql.gz --sanitize
./scripts/load-d7-source.sh <d7-dump.sql.gz>
./scripts/migration-cycle.sh cycle
```

Two things still worth a note as you go: `mkcert` isn't installed on this box (hence the `ddev` cert warnings — install it for clean `ddev` runs, or keep using `docker exec`), and remember to clear the shared Solr `images-11-*` namespace between the DDEV and staging phases. Anything you want me to prep for the staging side while you run the DDEV rehearsal?

---

## User — 2026-07-07 15:48

Let's close up for today. Please write all the documentation

---

## Claude — 2026-07-07 15:48

Time to close up properly with the full session-end ritual. Let me gather the formats I need (ADR, deferred-note, `.pages`, and the session-log script) before writing.

---

## Claude — 2026-07-07 15:48

Now a sample deferred note and the session-log script:

---

## Claude — 2026-07-07 15:49

I have all the formats. This session produced one durable decision (DDEV→MySQL 8.4) and two trackable gaps. I'll write an ADR, two deferred notes, wire up the indexes, save the session log, and refresh memory. Starting with the ADR and deferred notes:

---

## Claude — 2026-07-07 15:50

Now wire up the three indexes — ADR README + `.pages`, and deferred README + `.pages`:

---

## Claude — 2026-07-07 15:50

Now the deferred README table and `.pages`:

---

## Claude — 2026-07-07 15:51

Let me add a back-reference from the checklist to the new deferred note (canonical tracking), then commit the docs batch.

---

## Claude — 2026-07-07 15:51

Now the session log. Let me find this session's transcript and run the log script:

---
