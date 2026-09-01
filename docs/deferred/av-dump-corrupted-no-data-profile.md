# AV production dump corrupted — content-model audit has no data profile

**Area:** migration / source data / AV
**Raised during:** Sprint 2 Workstream C1 (AV content-model audit), Session 2026-09-01
**Jira:** (add when available)
**Priority:** Medium now, **High before any AV modeling decision is finalized**

## The problem

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

## What's needed

1. Obtain a fresh AV production dump out-of-band (same path as the Images/Texts dumps —
   S3/shared drive, per each dump's own header note).
2. **Verify with `gzip -t` before relying on it** — this incident suggests file-transfer
   corruption is a real risk for large dumps, not just a one-off.
3. Load into DDEV (`d7_av` database, same pattern as `scripts/load-d7-source.sh` but
   parameterized — that script currently hardcodes `SOURCE_DB=d7_images`) and re-run the
   data-profile section of `docs/planning/av-content-model-audit.md`.
4. Consider whether this belongs under the broader
   [canonical D7 dev source dump](canonical-d7-dev-source-dump.md) effort rather than a
   one-off AV fix — that note already flags dump provenance/integrity as a team-wide gap.
