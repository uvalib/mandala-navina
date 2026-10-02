# Review replica lag for the transcript Solr core

**Area:** Solr / transcripts / search freshness
**Raised during:** Spike 11, write-path discussion 2026-10-02
**Jira:** (add when available)
**Priority:** Low to Medium. Some lag is expected and accepted; review once the core exists.

## What is deferred

Transcript unit edits are written to the Solr **master** and committed within a second or two,
but readers (the React client, cross-transcript search) read from a **replica**, so an edit
reaches them only after replication. Yuji decided on 2026-10-02 that **some replica lag is
expected and acceptable**. The actual lag has **not been measured**: the replication
interval is not visible in the Solr config available to us (it is set in the environment's
replica configuration).

Staff using the Drupal editor are not affected. The Drupal editor and the in-Drupal transcript
view read the database, so they see their own edits immediately.

## What closes it

1. Measure the master-to-replica lag for the transcript core on dev-0 (write a unit, poll the
   replica until it appears), as part of the access-join prototype.
2. Find the replication interval for the transcript core's replica (ask Dave Goldstein, who
   runs the Solr infrastructure).
3. Decide whether the measured lag matters for the React client after a staff edit, and if so
   whether to shorten the interval or document it for staff.

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, write path
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T4, T6
