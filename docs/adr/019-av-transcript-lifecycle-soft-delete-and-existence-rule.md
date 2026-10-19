# ADR 019: AV transcript lifecycle: soft-delete, and a transcript exists when D7 has a tracking row

**Status:** Accepted, 2026-10-19
**Date:** 2026-10-19
**Deciders:** Yuji Shinozaki (soft-delete), Than Grove (existence rule), at the T2 review meeting with Xiaoming Wang
**Relates to:** [Spike 11](../spikes/spike-11-av-transcript-replication.md) (units keep their history),
[T2 plan](../planning/t2-transcript-entities-plan.md) ("Outcome of the review, 2026-10-19"),
[ADR 017](017-legacy-identity-composite-key.md)

## Context

T2 introduces two custom, non-fieldable content entities: `av_transcript` (one per AV node) and
`av_tcu` (the time-coded units, about 245,000 in D7). Spike 11 decided that units keep their edit
history so an editor can revert (Sprint 4 T7). Two gaps were left open:

1. **Drupal deletes an entity's revision rows when the entity is deleted.** A deleted unit therefore
   loses its history, and "revert" cannot bring it back. Deleting a transcript, as built in PR #350,
   also leaves its units behind as orphans (denied to everyone but administrators).
2. **Which D7 nodes get an `av_transcript`.** 41 nodes have a transcript file but were never
   processed (no tracking row, no units); 4 tracked transcripts have no current file; 19 transcript
   ids have units but no tracking row and no node.

## Decision

1. **Soft-delete, not hard delete, for both entities.** `av_transcript` and `av_tcu` each get a
   soft-delete flag. Deleting a unit sets its flag; deleting a transcript sets its own flag and its
   units' flags together. Revisions stay attached, so a deleted unit keeps its history and can be
   restored. We did not choose an archive table: it would copy rows on every delete and keep a second
   copy in sync.
2. **Existence rule.** An `av_transcript` exists exactly when D7 has a tracking row for it.
   - The 41 never-processed nodes get none (they have none in D7).
   - The 4 tracked nodes with no current file get one, with no source file. They already carry
     `trid_i` today because they have a tracking row, and this keeps that.
   - The 19 orphan trids are not migrated into `av_transcript`, but their units are kept somewhere
     for later review and are not dropped.

## Consequences

- A base field on each entity, added by an update hook. It is needed before T7 (revert), not before
  the migration.
- Everything that reads transcripts or units must respect the flag: the read service
  (`mandala_av_transcripts.reader`), the access handlers, and the kmassets reindex. A deleted
  transcript must not appear in search or in the viewer.
- The "orphan unit" access rule (denied to non-administrators) stays as a backstop but should no
  longer be reachable by a normal delete.
- Not decided here: what happens to a transcript and its units when the parent node is deleted. That
  is the more common case and needs checking.
- Not decided here: where the 19 orphan trids' units are kept (see the
  [deferred note](../deferred/transcript-orphan-trids-keep-for-review.md)).
