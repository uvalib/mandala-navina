# Triage support for the transcript index retry queue

**Area:** Solr / transcripts / operations
**Raised during:** Spike 11, write-path discussion 2026-10-02
**Jira:** (add when available)
**Priority:** Medium. Needed before production, not before the core is built.

## What is deferred

Decided 2026-10-02 (Yuji): a transcript save always succeeds in the database, and the Solr
write is tried synchronously and, if Solr is unreachable or rejects it, **goes onto a retry
queue**. A queue needs someone to notice and handle jobs that keep failing. That triage
support is deferred; nothing is built yet.

## What closes it

1. A way to see the queue: depth, oldest job age, and failed jobs with the error, for example
   a Drush command and a status line on an admin page.
2. A retry policy: bounded attempts with backoff, then a visible "failed" state instead of
   silent infinite retry.
3. An alert when the queue is stuck or growing (route to whoever is on call for Mandala).
4. A manual re-queue and a reconcile command that compares the database against the index
   (modelled on `KmassetAuditor`) so a transcript that missed an index write can be repaired.
5. Document the runbook beside the other dev-notes.

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, write path
- [Replica lag review](transcript-core-replica-lag-review.md)
