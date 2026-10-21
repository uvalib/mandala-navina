# Transcript orphans: admin screen, re-attach to another node, and editor access

**Category:** migration-av, access-control
**Raised during:** T2 review, 2026-10-20 (parent-node-delete discussion)
**Issue:** #357
**Priority:** Low. Nothing blocks on it; administrators can list, export, restore and purge orphans with drush commands.
**Status:** The drush tooling is built and verified (PRs #361, #363; probe run on dev-0 2026-10-21). What remains deferred is the screen, re-attach and editor access below.
**Owner:** unassigned

## What is deferred

When an AV node is deleted, its transcript and units are soft-deleted
([T2 plan](../planning/t2-transcript-entities-plan.md), "Parent-node delete and orphan management"). The first
version, **now built**, is an orphan manager service (`OrphanManager`) with administrator-only drush commands:
`av-transcripts:orphans`, `:export`, `:restore` and `:purge` (see the plan's "Orphan manager as built").
Three things were left out on purpose:

0. **The admin screen** at `/admin/content/av-transcripts`, a thin layer over the same service. The entities have
   no UI handlers today, so it is a day or more of work; build it if administrators want something other than
   the command line.
1. **Re-attaching an orphan to a different node.** Useful when a node was recreated, or deleted by mistake. A new
   service method and drush command first; the screen would come after.
2. **Any access for content editors.** Decided 2026-10-20 (Yuji): administrators deal with the orphans. Editors
   delete the nodes that create orphans, but a node-delete orphan cannot be restored without re-attach, and
   purge is destructive, so there was nothing safe to give them yet.

## Why it was left out

Re-attach adds the most validation: one node per transcript, matching a recreated node by legacy key, and what
happens to the units' indexed state. Editor access raises a question with no obvious answer: if editor A tidies a
collection and creates orphans, should editor B be able to act on them?

## What is already in place for it

The first version stores the changeable node reference and a snapshot at delete time (`deleted_by`,
`deleted_at`, node title, legacy key, collection ids), and keeps all logic in one service. Re-attach is then a
new service method and command, and the screen a layer on top, with no schema change.

## Options for editor access (not decided)

- **Same collection:** an editor sees orphans whose former node was in a collection they edit. Needs the
  collection ids snapshotted at delete time. Leaning this way: housekeeping is usually team work.
- **Own deletions only:** needs `deleted_by`. Simpler, but stops a colleague finishing the cleanup.
- Purge stays administrator-only under either.

## Start trigger

An administrator finds orphan handling a bottleneck, or someone needs to re-attach a transcript.

## Sequence

Still deferred, with no date. Ordering: re-attach (service method and command) is likely first, since it is the
only item with a concrete need; the screen follows whichever is built; editor access waits on a decision about
scoping (see the options) and is not started without it. The T5 migration may add a reason, because a rollback
leaves soft-deleted transcripts that T5 revives by id rather than through re-attach.
