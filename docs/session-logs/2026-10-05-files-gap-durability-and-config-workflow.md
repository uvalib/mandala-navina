# Session Log: files gap, dev-0 durability, SAML playbook review, config workflow

**Date:** 2026-10-05
**Participants:** Yuji Shinozaki, Claude Code (Xiaoming Wang and Than Grove joined for the group session; Than was working the orphaned-content review group in parallel)
**Outcome:** [PR #274](https://github.com/uvalib/mandala-navina/pull/274) (docs plus one script, no deploy). Nothing was merged or deployed during the session.

**This is an abridged, hand-written log, not a transcript.** The session ran read-only checks against the dev-0 and legacy production hosts, so `save-session-log.py` was deliberately not run (the repo is public). Host-specific detail is left out; ask Yuji.

---

## What happened

1. **Start-of-session check.** Fast-forwarded `main`; config clean and pushed; one local-vs-dev-0 count difference (7,396 vs 7,398 videos), not resolved. Surveyed candidates for the next work; Than took the orphaned-content review group (`feat/orphaned-content-review-group`, unmerged).
2. **Local-files gap.** Looked for D7 file-sync scripts (none; D7 used `stage_file_proxy` for non-production copies). Compared every `file_managed` row (8,447, all `public://`) against dev-0 and legacy production: **all present on dev-0; 8,417 on production, identical sizes; the other 30 are dev-0-only carousel demo thumbnails.** The earlier "5,413 gone from D7 production" figure was an audit blind spot (root-only fetch). One NFC/NFD filename mismatch found. I had to retract a "reachability is unverified" concern: the existing deferred note already settled dev-0 as the source and the audit tool already pulls from it (see memory: read the whole note first).
3. **`stage_file_proxy`.** 4.0.0 (2026-06-25) supports core `^11.3 || ^12`; a candidate, untested.
4. **dev-0 durability.** Uploads, SAML directories, OAuth keys and DB checkpoints sit under `/mnt/data` on the root volume, with no backup; the persistent EBS volume holds Docker storage only. Impact judged low (uploads exist on production, SAML material redeploys from source, OAuth keys regenerate).
5. **`deploy_netbadge.yml` review.** Main finding: a missing decrypted SAML key is a warning, not a failure. Deferred by the group.
6. **Config workflow.** Direction stated: change config in a live Drupal and export, no hand-edits; no comments in `config/sync`. Inventory: 16 YAML files / 296 real comment lines, plus 4 Solr field-type files that only look like comments (`#` inside string data) and `.htaccess`. Verified that diffing against a real export identifies exactly what an export strips.
7. **`scripts/config-check.sh` built and tested** (drift failure path, strict mode, cleanup). Not wired into CI.

## Decisions and assignments

- Files mechanism: **deferred to next session** (report-only audit step in the session-start check; short `stage_file_proxy` trial on Xiaoming's DDEV).
- Carousel demo churn: **deferred until closer to production deployment.**
- Review-flag inheritance and the Spike 11 analyzer / transcript accounting / stemming questions: **on Than's plate.**
- Merge order: **PR #274 first, then Than merges his branch** (a small conflict is possible in the orphaned-content note).
- Config practice written as "stated by Yuji"; CLAUDE.md not changed pending Xiaoming's and Than's agreement.

## Still open

- The CI-check question for config (Option 2).
- Relocating the 296 comment lines out of `config/sync` (destination undecided).
- Where the originals live after production is retired at cutover.
- Whether to fix dev-0's unbacked `/mnt/data`; the SAML playbook findings.
- Not exercised: the "unexported config" path in `config-check.sh`; production's backup state in AWS; the 29 non-comment export-diff lines (look like cosmetic reformatting).
