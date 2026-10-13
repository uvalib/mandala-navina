# Production Solr `kmassets` is not receiving D7 writes (S3 sync listener not running)

**Area:** legacy D7 to Solr ingest / production Solr
**Raised during:** Spike 11 (kmassets `trid_i` docValues deploy, [#321](https://github.com/uvalib/mandala-navina/issues/321))
**Issue:** [shanti-uva/mandala_drupal_docker#26](https://github.com/shanti-uva/mandala_drupal_docker/issues/26) (legacy repo, assigned to Yuji; needs input from Dave and Than)
**Priority:** Medium (legacy production search is going stale; matters most if cutover is far off)

## Why this is here

Found 2026-10-13 while verifying the `trid_i` schema deploy. Full context in the
[session log](../session-logs/2026-10-13-kmassets-trid-docvalues-deploy-and-stale-production-index.md).
The tracking issue lives in the legacy repo because that is where the code is; this note holds
the why.

## Finding

- The newest document in the production `kmassets` master is dated **2025-08-11**.
- D7 still writes Solr JSON files on the legacy host (fresh files exist for AV and images), but
  the `clsync` / `synchandler` / `rclone` listener that copies them to the S3 ingest path is
  **not running** in the production hostmaster container.
- `mandala-entrypoint.sh` in `shanti-uva/mandala_drupal_docker` has the start line commented
  out, since commit `51f81ae` (2024-01-11, "Add MySQL8 client"). No separate sync container
  exists on the host; `shanti-uva/mandala_s3_synch` (the spin-off) is archived.
- A manual copy as the service user was denied by the destination. Whether that is a real
  write denial or an artifact of the old `rclone` is not determined.
- The downstream ingest queue received no messages after about 2025-08-18; the downstream
  services are healthy but idle. Something else must have done the copying between Jan 2024 and
  Aug 2025. Unknown what.

## Consequences

- Legacy production search and the React viewer do not see content created or edited since
  August 2025.
- A "resave a node in D7" test does not reach Solr. The `trid_i` deploy had to use a direct
  throwaway-document write to move the index.
- Restarting the listener could push on the order of 120k existing files at once. Plan the
  catch-up (rate, order, whether those documents are wanted) first.

## Open decisions

1. Revive the legacy sync before cutover, or accept staleness until the D11 writer replaces it.
2. Who restores it, and who fixes the destination permission (needs input from Dave).
3. Whoever ran the copy in 2024-25 (needs input from Than or Xiaoming) may explain why it stopped.
