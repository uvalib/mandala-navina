# Session Log: AV4 finished, verified end-to-end, and launched live on dev-0 — plus a corrected nightly-shutdown assumption

**Date:** 2026-09-09
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** AV4 (Sprint 3) completed, verified against the D7 source with 24/24
checks matching, and merged. `mandala_d7_av` loaded onto staging RDS and wired
into dev-0's container env via `terraform-infrastructure`. **The AV4 migration
is running live on dev-0 as of this log**, launched detached so it survives
session/SSH/VPN disconnects. Along the way: a standing project assumption
("dev-0 stops nightly") was found to be wrong and corrected with real evidence.

| PR | State | |
|---|---|---|
| [#193](https://github.com/uvalib/mandala-navina/pull/193) | **merged** | AV4 complete — nodes, collections, memberships, aliases |
| [#194](https://github.com/uvalib/mandala-navina/pull/194) | **open** | dev-0 nightly-shutdown correction + `refresh-d7-staging-source.sh` fixes — needs review |
| `terraform-infrastructure` | committed to master directly (no branches there) | `MIGRATE_AV_DATABASE` wiring |

> **This log is hand-written and abridged, not produced by `scripts/save-session-log.py`.**
> Tonight's session included a live AWS CloudTrail investigation (real instance
> IDs, IAM role names, account-internal infrastructure detail) to verify the
> nightly-shutdown finding. That detail doesn't belong verbatim in a public,
> permanent transcript, so specifics are generalized below and the raw session
> was not committed.

---

## 1. AV4 finished and verified (PR #193, merged)

Picked up where the 09-08 session left off (paragraphs built, nodes not yet).
Built the remaining 10 migrations — `audio`/`video` nodes, collections/
subcollections as Groups, node and user memberships, URL aliases — bringing
the `mandala_av` group to 27 migrations total.

**Four bugs found and fixed**, two of them silently losing data with a clean
exit code:

1. `uid: uid` in the node/collection source mappings resolves to nothing —
   core's `d7_node` source exposes the author only as `node_uid`. Fixed here
   and in the pre-existing `d7_images_collections`/`subcollections` (same bug,
   already on `main`) — this also root-causes a previously-open deferred note
   about `entity:group --update` failing on a NULL uid.
2. A fetch-mode bug (`->item_id` on an associative array, not an object) made
   every node's paragraph reference list come back silently empty.
3. A `sub_process` shape bug on the four **nested** paragraph references
   (workflow notes, PBCore format-id) created 6,814 paragraphs with **zero**
   of them actually referenced by their host — one visible error, from the
   one host in 4,562 whose shape happened to trip it.
4. The same nested mappings supplied only `target_id`, when a paragraph-typed
   `migration_lookup` returns `[id, revision_id]`.

**The `und`/`en` language duplication, resolved properly.** Confirmed it's a
data artifact (a stale D7 content-type language flag, not a schema problem) —
`und` rows are stranded pre-flip data on ~66% of nodes. For the one
cardinality-1 field with real conflicts (`field_pbcore_instantiation`, 667 of
5,297 hosts), measured that one item is *always* a strict superset of the
other (0 counterexamples) — so keeping the richer item loses nothing, unlike
either simple `en`/`und` preference rule. Checked directly, at the user's
request, whether the wider duplication was ever a genuine content copy: **it
never is** — every redundant case is the same item linked twice, never two
items with the same content. The genuine content differences (84 of them,
mostly real Tibetan/Chinese titles alongside English, and some creator/role
attribution questions) are recorded for AV staff review.

**Verification:** `scripts/verify-av-migration.sh` checks 24 counts against
the D7 source directly, not against migrate's own bookkeeping. All 24 match.
Full detail, including the runtime caveat below, in
[`docs/planning/av-node-migration-notes.md`](../planning/av-node-migration-notes.md).

**Local timings are DDEV-only and were explicitly NOT used to revise the
dev-0 runtime estimate** (still ~3h34m, band 3.5–5h, from the 09-08
projection) — the two environments differ in opposite directions (DB
round-trip latency vs. local filesystem-sync overhead), so no correction
factor between them is safe to apply. A real number needed an actual dev-0
run, which is now in progress (§4).

## 2. A standing assumption was wrong: dev-0 is NOT on the nightly shutdown

Long-standing project documentation (`CLAUDE.md`, a dev-notes howto, and
Claude's own memory) asserted that dev-0 and staging both stop nightly
11pm–6am. Asked to verify this properly rather than keep assuming it, and it
turned out to be **wrong for dev-0**, right for `staging` (dev-1).

Verified with five independent signals, all agreeing: dev-0's instance was
absent from a shared library-wide nightly stop/start scheduler's actual
target list (confirmed via the AWS activity log covering several consecutive
nights); its EC2 launch time and guest-OS uptime were both far older than one
overnight cycle would allow; and its Redis sidecar container (unaffected by
app deploys) showed 5 unbroken days of uptime. `staging` (dev-1), checked the
same way, showed the expected nightly cycle exactly.

**Root cause, per Yuji:** the team had asked Dave Goldstein to pull dev-0 from
the nightly shutdown at some point, and nobody ever asked for it back — and
nothing in this repo recorded that the change had happened, which is exactly
why the docs went stale silently.

**Corrected in PR #194** (open): the howto doc now states dev-0/staging's
status as a *dated snapshot*, not a permanent fact, and a new script
(`scripts/check-nightly-shutdown-status.sh`) gives a repeatable two-check way
to re-verify before relying on it again — the list of instances a scheduler
actually stops/starts, cross-checked against host uptime. Validated against
both a true positive (staging) and a true negative (dev-0) tonight.

**Practical payoff:** the AV4 migration can run overnight on dev-0 without
the 11pm cutoff killing it.

## 3. `mandala_d7_av` loaded onto staging RDS

AV's D7 production database had never been dumped to staging before tonight —
only Images and the shared user DB had been (2026-07-17). Used
`scripts/refresh-d7-staging-source.sh` for the first time against a real
site pair, which surfaced two real defects in that (previously untested)
script, both fixed in PR #194:

- `mysqldump` needs `--no-tablespaces` against this DB user (lacks the
  `PROCESS` privilege MySQL 8 wants for tablespace metadata) — without it,
  the dump fails before writing a single row.
- **25 `cache_*` tables account for 2.19 GB of the 3.45 GB source database —
  64%,** entirely disposable Drupal runtime cache never read by any migrate
  source plugin. The script now discovers and excludes them dynamically for
  every future site load (Sources, Texts, Visuals). Tonight's AV load had
  already passed the expensive `cache_form` table before this fix landed, so
  it ran with cache tables included — recorded as the baseline for comparison
  once a future load (without them) can be timed.

Load completed in 22m11s (with cache tables); verified afterward against the
local DDEV copy across all 531 tables and several specific row counts
(`node`, `field_collection_item`, `url_alias`, `og_membership`) — all exact
matches.

## 4. `MIGRATE_AV_DATABASE` wired in, and the migration launched

Added `MIGRATE_AV_DATABASE=mandala_d7_av` to dev-0's managed container
environment in `terraform-infrastructure` (committed directly to master,
following that repo's no-branches convention), matching the existing
`MIGRATE_SOURCE_DATABASE`/`MIGRATE_USERS_DATABASE` pattern exactly. Triggered
a fresh `mandala-drupal` CodePipeline execution (Source → Build → Deploy, all
succeeded) to actually apply it — this recreates the live container, which
`docs/dev-notes/howto-long-running-jobs-on-dev-staging.md` already documents
as an operation that briefly interrupts anything running inside it, so it was
done deliberately before starting anything long-running, not during.

Verified end to end afterward: the new env var is present in the running
container, and `drush migrate:status --group=mandala_av` on dev-0 shows all
27 migrations with totals matching the D7 source exactly — the same clean
state the local DDEV run reached, now reproduced against dev-0's real source.

**The migration is running now**, launched via a `setsid`+`nohup`-detached
script on dev-0 itself (not tied to this Claude Code session, an SSH
connection, or the VPN) — verified alive and progressing from a completely
fresh SSH connection after launch. Mirrors `scripts/run-av-migration.sh`'s
exact dependency order, with an added safety check that resets a migration's
status before importing if a prior attempt left it non-idle. See
[`docs/deferred/av4-dev0-live-migration-run.md`](../deferred/av4-dev0-live-migration-run.md)
for exactly how to check on it and what to do when it finishes or fails —
written specifically so the next session (whoever drives it) doesn't need
this transcript to pick the thread back up.

## Follow-ups

- **Check on the dev-0 migration** — see the deferred note above for the
  live status, log location, and what "done" and "failed" each look like.
- **PR #194** — open, needs review/merge (nightly-shutdown correction +
  `refresh-d7-staging-source.sh` fixes).
- **AV6/AV7/AV8** (KMaps wiring, Group access mapping, Solr/kmassets sync) —
  unblocked now that AV4 is complete; natural next Sprint 3 work once the
  dev-0 run is confirmed clean.
- **`field_pbcore_language` vocabulary** — recommended converting to ISO 639
  codes before AV8 needs to route Tibetan/Chinese content into the Solr
  schema's existing `*_bo`/`*_tibt` fields. Not started.
- **The 42 remaining paragraph-ordering ties**, **AV14's 18-nid cleanup
  list**, and **`field_tags` "Tags Old"** — all still need input from AV staff,
  not more analysis.
- **Re-verify the nightly-shutdown status periodically** — it's controlled
  entirely outside this repo and can change without warning; see
  `scripts/check-nightly-shutdown-status.sh`.
