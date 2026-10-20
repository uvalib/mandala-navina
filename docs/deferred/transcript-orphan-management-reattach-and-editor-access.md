# Transcript orphans: re-attach to another node, and editor access

**Category:** migration-av, access-control
**Raised during:** T2 review, 2026-10-20 (parent-node-delete discussion)
**Issue:** #357
**Priority:** Low. Nothing blocks on it; administrators can already list, export and purge orphans.
**Owner:** unassigned

## What is deferred

When an AV node is deleted, its transcript and units are soft-deleted
([T2 plan](../planning/t2-transcript-entities-plan.md), "Parent-node delete and the orphan screen"). The first
version of the admin screen lets administrators list, inspect, restore, export and purge them. Two things were
left out on purpose:

1. **Re-attaching an orphan to a different node.** Useful when a node was recreated, or deleted by mistake.
2. **Any access for content editors.** Decided 2026-10-20 (Yuji): administrators deal with the orphans. Editors
   delete the nodes that create orphans, but a node-delete orphan cannot be restored without re-attach, and
   purge is destructive, so there was nothing safe to give them yet.

## Why it was left out

Re-attach adds the most validation: one node per transcript, matching a recreated node by legacy key, and what
happens to the units' indexed state. Editor access raises a question with no obvious answer: if editor A tidies a
collection and creates orphans, should editor B be able to act on them?

## What is already in place for it

The first version stores the changeable node reference, a snapshot at delete time (`deleted_by`, `deleted_at`,
node title, legacy key, collection ids), and registers operations as entity operations. Re-attach is then a new
operation and route, with no schema change.

## Options for editor access (not decided)

- **Same collection:** an editor sees orphans whose former node was in a collection they edit. Needs the
  collection ids snapshotted at delete time. Leaning this way: housekeeping is usually team work.
- **Own deletions only:** needs `deleted_by`. Simpler, but stops a colleague finishing the cleanup.
- Purge stays administrator-only under either.

## Start trigger

An administrator finds orphan handling a bottleneck, or someone needs to re-attach a transcript.
