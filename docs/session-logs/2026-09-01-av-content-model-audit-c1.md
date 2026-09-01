# Session Log: AV content-model audit (Sprint 2 Workstream C1)

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** `docs/planning/av-content-model-audit.md` written and committed. Structural findings complete and code-verified; the planned data-profile section could not be completed because both local copies of the AV production dump are corrupted.

---

## 1. Why C1 first

Following the theme install on dev-0 (see the earlier 2026-09-01 log), Sprint 2's
Workstream A is closed and B/C/D are all unblocked. C1 (the AV audit) was picked to
start with since it's group-owned and has no single source module to assign to one
person — following the sprint doc's own note that it should "take longer to scope than
C2/C3," it made sense to start it in this live group session.

## 2. Correcting a stale prior note

A prior team note (carried in Claude's own project memory) assumed `mb_metadata` and
`mb_structure` owned custom database tables analogous to Images' `shanti_images` sidecar
table. Static analysis of both modules found **no `hook_schema()` or `hook_node_info()`
in either** — AV is just two plain node bundles (`audio`, `video`), defined by a Features
export sub-component of `mediabase` (`mediabase/features/audio_video`). This correction
is recorded in the audit doc itself, not just here.

## 3. Structural findings (code-verified)

- Full field inventory for both bundles (near-identical, differ only in
  `field_audio`/`field_video`) — PBCore descriptive/technical metadata and cataloging
  workflow state are all `field_collection` items, a materially different (and likely
  lower-risk) starting shape than Images' referenced-node satellites.
- The Kaltura integration boundary: the node holds only a scalar Kaltura entry ID;
  `mb_kaltura` is a customized import/sync layer (the stock contrib import UI is
  deliberately disabled — "their import mechanism is broken," per its own code comment).
  Ties into Spike 7 (○ Pending), not resolved by this audit.
- Transcripts attach to the same node (file field + a separate timing table keyed by
  node ID), not a separate entity.
- AV's access model is OG plus **two additional custom grant realms**
  (`group_access_uva_member`, `mb_collection_admin`) not present in Images' model —
  flagged so a future OG→D11-Group mapping doesn't silently drop them by copying
  Images' mapping.

Full detail, field-by-field, in the audit doc.

## 4. Data profile blocked — dump corrupted

Attempted to load the real AV production dump into DDEV (`d7_av` database) to validate
the structural findings against real rows, the same way the Images audit validated its
Paragraphs decision against real agent-reuse numbers. Both local copies failed:

- `~/Sandbox/Mandala/data/mandala-prod-av-db_2026-06-11.sql.gz` (1.7GB) — `gzip -t`
  fails (`unexpected end of file`); confirmed live by attempting the load, which aborted
  mid-decompression and fed a truncated line into MySQL.
- `~/Sandbox/Mandala/mandala11/data/mandala-prod-av-db_2026-06-11.sql.gz` — same
  filename but actually a **different, much smaller (24.8MB) file**, not a real
  duplicate/backup; also gzip-corrupt.

Cleaned up the ~16GB partial `d7_av` database created during the failed import before
it could cause confusion for anyone else on the shared DDEV instance. Filed
[`av-dump-corrupted-no-data-profile.md`](../deferred/av-dump-corrupted-no-data-profile.md),
cross-referenced against the existing
[canonical D7 dev source dump](../deferred/canonical-d7-dev-source-dump.md) note since
both point at the same underlying gap (dump provenance/integrity isn't tracked
anywhere).

## 5. Doc status

`docs/planning/av-content-model-audit.md` is explicit throughout that its findings are
static-code-derived only, not row-verified — every section that would normally cite
real numbers (data profile, corrupted-legacy-field check) instead states clearly that
verification is pending a fresh dump. Sprint 2 backlog row C1 marked ☑ with a caveat
link to the deferred note; the sprint's own acceptance criterion for the audits (real
dump, not placeholders) stays unchecked until a working dump is available.

## Next-session starting point

- Obtain and `gzip -t`-verify a fresh AV production dump (out-of-band, per the deferred
  note), then re-run the audit's data-profile section.
- C2 (Sources, Xiaoming) and C3 (Texts, Than) are unblocked and can proceed in parallel
  — same audit template, and Sources already has a head start from Spike 5's bibcite
  profiling.
- Workstream B (Images interactive UI, Than) remains the largest unstarted piece of
  Sprint 2 and has no dependency on C.
