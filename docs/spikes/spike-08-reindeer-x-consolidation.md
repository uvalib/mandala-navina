# Spike 8: reindeer_x Consolidation as Managed Sync Subsystem
**Status:** Pending
**Date:** —
**Branch/commit:** —

## Theory

The `synch`/`synchandler` shell+Perl pipeline (clsync + rclone) can be replaced
with Node.js equivalents inside the existing `reindeer_x` process, and `reindeer_x`
can subscribe to AWS SQS events directly to eliminate the UDP trigger and the
kmterms race condition — making it a fully self-managed sync subsystem.

## Background

Currently the kmassets sync pipeline has three separate runtime components:
1. **`reindeer_x`** (Node.js) — kmterms → kmassets transform and write
2. **`synch`** (shell) + **`synchandler`** (Perl) — watches Drupal's
   `private/files/solrdocs/` directories and uploads JSON to S3 via `rclone`
3. A UDP ping from KMaps Rails triggers reindeer_x — but fires before the ECS
   kmterms Solr update completes, causing a race condition and silent stale writes

This spike proves that all three responsibilities can be unified inside reindeer_x
with no shell or Perl dependencies, and that the race condition can be eliminated
by subscribing to an SQS queue rather than waiting for a UDP ping.

See [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md)
for the full architectural context.

## Work

### Part A — Fold synchandler into reindeer_x

1. Add `chokidar` to `kmaps-solr-sync/package.json`
2. Add `@aws-sdk/client-s3` to `kmaps-solr-sync/package.json`
3. Implement a `sync/fileWatcher.js` module that:
   - Accepts a list of watch directories (equivalent to the six site paths in `synch`)
   - Uses chokidar to watch for new/changed `.json` and `.ids` files
   - Uploads `.json` files to `s3://mandala-ingest-{env}-inbound/kmassets-inbound/`
   - Uploads `.ids` files to `s3://mandala-ingest-{env}-inbound/kmassets-delete/`
   - Uses AWS SDK (not rclone) for all S3 operations
4. Wire `fileWatcher.js` into `server/index.js` startup
5. Verify that `synch` and `synchandler` can be retired from the Docker image

### Part B — SQS subscription for kmterms completion event

1. Add `@aws-sdk/client-sqs` to `kmaps-solr-sync/package.json`
2. Implement a `queue/sqsConsumer.js` module that:
   - Long-polls a configurable SQS queue (`KMTERMS_COMPLETE_QUEUE` env var)
   - On receipt of a completion message, calls the same `processRequest` path
     as the UDP handler (so the trigger logic is shared)
   - Acknowledges (deletes) the SQS message on successful job creation
   - Reports errors without crashing the consumer loop
3. Wire `sqsConsumer.js` into `server/index.js` startup (behind an env var flag
   so it can be disabled if the SQS queue doesn't exist yet)
4. Coordinate with Dave Goldstein to add a completion notification to the ECS
   kmterms Solr update task (out of scope for this spike — just verify reindeer_x's
   side works against a manually published test message)

### Part C — SNS completion reporting (stretch)

1. Add `@aws-sdk/client-sns` to `kmaps-solr-sync/package.json`
2. After each sync batch completes, publish a summary to a configurable SNS topic
   (`SYNC_COMPLETE_TOPIC` env var): count written, count skipped, count failed,
   duration
3. On sync failure, publish to a separate error topic (`SYNC_ERROR_TOPIC` env var)

## Demo

```bash
# Start reindeer_x locally with file watcher enabled
cd kmaps-solr-sync
cp .env.dist .env   # configure S3 bucket, watch dirs
npm run reindeer_x:dev

# Part A: drop a test JSON file in a watched directory
echo '{"uid":"subjects-99999"}' > /tmp/test-solrdocs/test.json
# → reindeer_x logs: "Uploaded test.json to s3://..."
# → verify file appears in S3 bucket

# Part B: publish a test SQS message
aws sqs send-message \
  --queue-url $KMTERMS_COMPLETE_QUEUE_URL \
  --message-body '{"event":"kmterms-update-complete"}'
# → reindeer_x logs: "SQS message received, triggering sync"
# → sync job appears in Arena UI at http://localhost:4567
```

## Pass Criteria

**Part A:**
- chokidar detects new JSON files in watched directories reliably
- AWS SDK uploads files to S3 with correct path structure
- No rclone or Perl dependency needed for file upload
- `synch` and `synchandler` scripts are no longer needed at runtime

**Part B:**
- reindeer_x polls SQS and receives a test message
- Receipt of the message triggers a sync job (visible in Arena UI)
- Message is deleted from SQS after successful job creation
- SQS consumer loop recovers from errors without crashing

**Part C (stretch):**
- Sync completion publishes a summary message to SNS
- Message contains count fields and duration

## Fail Criteria and Response

| Finding | Response |
|---|---|
| chokidar misses file events in Docker volume mounts | Evaluate polling mode (`usePolling: true`) or switch to a dedicated S3 upload endpoint instead of file watching |
| AWS SDK auth doesn't work in container context | Verify IAM role / instance profile; fall back to explicit credential env vars |
| SQS queue doesn't exist yet for testing | Use LocalStack or mock SQS for Part B; defer real integration to post-spike |
| ECS kmterms task can't be modified to publish completion event | Keep UDP trigger as interim; document as remaining gap |

## What this does NOT establish

- That the D11 Drupal → reindeer_x HTTP POST path works (separate spike needed)
- That the ECS kmterms Solr update task publishes completion events (requires
  Dave Goldstein coordination — out of scope)
- That reindeer_x is production-ready for D11 deployment (this spike proves the
  Node.js consolidation, not the full ECS integration)
- Cost impact of SQS polling vs. current UDP model

## Deferred notes

- [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md)
- [docs/deferred/solr-pipeline-cost-discussion.md](../deferred/solr-pipeline-cost-discussion.md)
