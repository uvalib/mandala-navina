# Session Log: kmassets `trid_i` docValues deployed to staging and production; production index found stale

**Date:** 2026-10-13
**Participants:** Yuji Shinozaki (driving); Claude Code. Hand-written summary, not a transcript. No credentials, bucket names or privately-tracked material are recorded here.
**Outcome:** #321 done and closed. The explicit `trid_i` `docValues="true"` field is live on staging and production Solr (master and replica), verified unchanged by baselines. Runbook merged (PR #325). While verifying, found that production `kmassets` has not received any D7 write since 2025-08-11; filed as [mandala_drupal_docker#26](https://github.com/shanti-uva/mandala_drupal_docker/issues/26).

## What happened

1. **Orientation.** Session-start check: git and config in sync; local DB had 2 fewer groups than dev-0 (not rebased; later made informational by the 2026-10-13 drift-policy decision, see [that log](2026-10-13-local-dev-drift-policy-and-pull-only-resync.md)). Handoff from [2026-10-12](2026-10-12-legacy-redirect-subscriber-and-trid-docvalues-handoff.md).
2. **Schema change.** `shanti-uva/solr-shanti-configsets` PR #32 (merged as `88da645`; pre-merge `18fd04d`) adds an explicit `trid_i` field with docValues to the four `kmassets` schemas. Trie stays on 7.x (`int` is `TrieIntField precisionStep=0`); 9.10.11 keeps `IntPointField`.
3. **Pre-deploy checks.** Live `conf/` (all 62 files) on staging and production, master and replica, was byte-identical to the repo before the change. A 30-day search of the production replica's request log found only six one-off `trid_i` queries; the only legacy client use is a stored-value read in the React viewer. Low risk.
4. **Deploy.** Run from the team's TeamCity job (staging, then production). Master cores reloaded and showed `docValues: true` immediately.
5. **The replica did not follow.** Replica `conf/` arrives by replication only together with an index fetch, and a config push does not change the master's index version. On staging a one-node `drush kmassets:index` fixed it. A `deleteByQuery` matching nothing does **not** move the generation. An **add plus delete of a throwaway document in one update request, one commit** does, and needs no real document or writer host. That is how production was done (generation 21229 to 21230; replica followed in about 15 s).
6. **Verification.** `scripts/kmassets-baseline.py` (new) took before/after baselines of both production nodes: counts, facets, the `uid,trid_i` list hash (5,337 pairs), 9 canary-query hashes, schema attributes, replication state. Only expected differences appeared. Replica `conf/` identical to master and repo. Replica CloudWatch log since the probe: no `kmassets` errors. Two existing `solrconfig.xml` warnings appear at every reload.
7. **Found: production `kmassets` is stale.** Newest document dated 2025-08-11. D7 still writes its Solr JSON, but the listener that copies it to the S3 ingest path is not running on the legacy host: the entrypoint line was commented out in commit `51f81ae` (2024-01-11), no separate sync container exists, and a manual copy as the service user was denied. The downstream queue has been silent since about 2025-08-18. Who ran the copy between Jan 2024 and Aug 2025 is unknown. See [the deferred note](../deferred/production-solr-not-receiving-d7-writes.md).
8. **Docs.** Runbook [`howto-deploy-kmassets-schema-change.md`](../dev-notes/howto-deploy-kmassets-schema-change.md) merged (PR #325) with the probe step, baseline script, log-noise note and production record of use. The Spike 11 doc now records the deploy. #321 comment updated in place; #321 closed.

## State at end of session

- **Done:** #321 closed; #325 merged; configsets PR #32 merged.
- **Open, new:** [mandala_drupal_docker#26](https://github.com/shanti-uva/mandala_drupal_docker/issues/26) (assigned to Yuji; needs input from Dave and Than).
- **Not filed:** recurring `ArrayIndexOutOfBoundsException: Index -100` errors on the production replica's `kmterms` core (297 since 2026-09-29, still occurring). Unrelated to this change; cause not investigated. Candidate low-priority issue.
- **Unchanged:** about 5,337 existing documents still have no docValues for `trid_i` until rewritten (belongs with the writer, #290a). PR #315 stays a draft, blocked on a transcript core on dev Solr.
- **Not done:** `./scripts/update-db-from-remote.sh dev` (local DB rebase); `simplesamlphp_auth.settings` showing Different on dev-0 (noted 2026-10-12, not investigated).

## Pick up next

1. Decide who owns mandala_drupal_docker#26 and whether the legacy sync should be revived before cutover or replaced by the D11 writer. Restarting it naively would push on the order of 120k files at once.
2. #290(a): build T2 (transcript entity) first, then the `trid_i` writer; create the transcript core on dev Solr and verify #315 end to end.
3. Decide whether to file the `kmterms` error issue.
4. #316 leftovers (group lookup test, cutover `Host` recheck); #300 unassigned pickup.
