# AV production dump corrupted — content-model audit has no data profile (RESOLVED 2026-09-01)

**Area:** migration / source data / AV
**Raised during:** Sprint 2 Workstream C1 (AV content-model audit), Session 2026-09-01
**Jira:** (add when available)
**Priority:** RESOLVED — was Medium/High before a fresh dump landed

## Resolution (2026-09-01, same day)

Than re-exported a fresh dump, `mandala-prod-av-db_2026-09-01.sql.gz` (186MB — much
smaller than the corrupted 1.7GB file, consistent with that file being a bad/inflated
export rather than a mid-stream-truncated copy of a correctly-sized one). `gzip -t`
passed; loaded into DDEV's `d7_av` database and the audit's data-profile section is now
complete — see `docs/planning/av-content-model-audit.md`. Confirmed live: 11,583
audio/video nodes, required fields 100%/>99.7% clean, and the same `og_membership`-vs-
`field_data_field_og_collection_ref` bug independently found on the Sources audit also
affects AV. One new item surfaced during profiling: 68 nodes carry a corrupted literal
`MISSING_TYPE` bundle value — filed as its own open question in the audit doc, not a
dump-integrity problem.

Item #4 below (parameterize `load-d7-source.sh`, consider folding into the canonical-dump
effort) is still outstanding — leaving this note open for that, everything else here is
closed.

## Original problem (2026-06-11 dump, now superseded)

Both local copies of the AV production dump are gzip-corrupted and unusable:

- `~/Sandbox/Mandala/data/mandala-prod-av-db_2026-06-11.sql.gz` (1.7GB) — fails
  `gzip -t` (`unexpected end of file`). Confirmed by attempting a real load into DDEV: `gzcat`
  aborted mid-stream and fed a truncated line to MySQL (`ERROR 1064 ... at line 28909`,
  inside a serialized-PHP blob — consistent with the file being cut off, not corrupted mid-file).
- `~/Sandbox/Mandala/mandala11/data/mandala-prod-av-db_2026-06-11.sql.gz` — despite the
  identical filename, this is a **different, much smaller file** (24.8MB vs. 1.7GB), also
  gzip-corrupt. Not actually a duplicate/backup of the first.

Neither the `av-content-model-audit.md` doc (this session) nor any other AV work in this
repo has ever validated field structure against real AV rows — the [Images
audit](../planning/images-content-model-audit.md) and [Texts
audit](texts-content-model-audit.md if/when it exists) both used a real dump to confirm
their structural findings (e.g. Images' Paragraphs decision was confirmed against real
agent-reuse numbers); AV's audit could not do the equivalent step.

## Impact

- AV's field-collection→Paragraphs recommendation (the audit's central structural
  finding) is unvalidated against real data.
- Whether the historical corrupted-field columns (`field_extended_cataloging`,
  `field_translation_lang_1/2`, see `mb_structure_update_7003`/`7004`) still exist in
  current data is unknown.
- No real `audio`/`video` node counts, field fill-rates, or transcript-coverage numbers
  exist anywhere in this repo's docs.

## What's needed (status as of resolution)

1. ~~Obtain a fresh AV production dump out-of-band.~~ **DONE** (2026-09-01).
2. ~~Verify with `gzip -t` before relying on it.~~ **DONE** — passed, and this is now
   confirmed generally good practice: Sources' dump the same session was verified this
   way before anything relied on it.
3. ~~Load into DDEV and re-run the data-profile section.~~ **DONE.**
4. **Still open:** parameterize `scripts/load-d7-source.sh` (currently hardcodes
   `SOURCE_DB=d7_images`) so `d7_av`/`d7_sources`/etc. don't need ad hoc one-off
   commands each time, and consider whether dump-integrity verification belongs under
   the broader [canonical D7 dev source dump](canonical-d7-dev-source-dump.md) effort.
