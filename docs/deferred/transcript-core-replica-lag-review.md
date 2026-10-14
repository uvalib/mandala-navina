# Review replica lag for the transcript Solr core

**Category:** solr-search
**Raised during:** Spike 11, write-path discussion 2026-10-02
**Issue:** none
**Jira:** (add when available)
**Priority:** Low to Medium. Some lag is expected and accepted; review once the core exists.
**Owner:** unassigned

## What is deferred

Transcript unit edits are written to the Solr **master** and committed within a second or two,
but readers (the React client, cross-transcript search) read from a **replica**, so an edit
reaches them only after replication. Yuji decided on 2026-10-02 that **some replica lag is
expected and acceptable**. The actual lag has **not been measured**: the replication
interval is not visible in the Solr config available to us (it is set in the environment's
replica configuration).

Staff using the Drupal editor are not affected. The Drupal editor and the in-Drupal transcript
view read the database, so they see their own edits immediately.

## Measured 2026-10-14 (staging, #327)

The core now exists on the staging Solr master and replica (shanti-uva/solr-shanti-configsets#33).
dev-0 reads and writes the staging Solr, so this is the lag dev-0 sees.

- **Method:** write one throwaway unit to the master with a commit, poll the replica until it
  serves it, then delete it (`scripts/load-transcript-test-units.py --replica`, uvalib/mandala-navina#330).
- **Results, four samples:** 1.1 s, 23.2 s, 22.2 s, 23.2 s. The steady figure is **about 22-23 s**.
  The 1.1 s sample landed just before a poll.
- **Replication interval: found, no need to ask Dave.** The replica polls the master every
  **30 s** (`pollInterval 00:00:30`, the same as the other `mandala-*` cores; visible on the
  replica with `replication?command=details`). Worst-case lag is therefore the poll interval
  plus the fetch, roughly 30 s or a little more. The replica is set up by the environment's
  `-Denable.slave` / `-Dmaster.url` options, not by anything in the core's own config.
- **Not measured:** lag under a bulk load (a migration or reindex), or while the replica is
  fetching a large segment set. The test index had 8 units.

## What closes it

1. ~~Measure the master-to-replica lag~~ Done, see above.
2. ~~Find the replication interval~~ Done: 30 s.
3. Decide whether a lag of up to about 30 s matters for the React client after a staff edit
   (Sprint 4 T6: editor saves must reach the index), and if so whether to shorten the interval
   or tell staff. Staff in the Drupal editor are not affected, since they read the database.
4. Re-measure under a bulk load once the migration (T5) exists.

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, write path
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T4, T6
