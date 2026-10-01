# Hand AV staff the 18 media-less AV nodes for cleanup

**Area:** migration / AV / content cleanup / cutover
**Raised during:** Sprint 3 AV14 (2026-09-08); deferred at Sprint 3 close-out, 2026-10-01
**Jira:** (add when available)
**Priority:** Medium now; must be done before production cutover, not before

## What is deferred

AV14 decided to **migrate all 18 media-less AV nodes as-is** (17 `video`, 1 `audio` — no Kaltura
entry ID) and to **hand AV staff the list** as a pre-cutover cleanup item. The migration half is
done and verified. The handoff half was never recorded as delivered, and on 2026-10-01 the team
decided to **defer it** rather than hold Sprint 3 open for it.

The list itself, with the reasoning for migrating rather than excluding, is in
[av-anomalous-node-dispositions.md](../planning/av-anomalous-node-dispositions.md) (section
"AV14"). It is keyed on D7 nid and collection nid, with titles deliberately omitted because the
repository is public. This note does not repeat it.

## Why it can wait

- The nodes are published and render metadata with no player, **exactly as on the live D7 site**,
  so nothing is worse than today (ADR 008's faithful floor).
- Nothing in D11 depends on staff acting. The decision is *which of the 18 to attach media to,
  unpublish or delete*, and that is a content call that belongs to AV staff.
- 7 of the 18 sit in obvious scratch collections, so some of the cleanup may be trivial.

## What closes it

1. Someone gives AV staff the AV14 list (the table in the disposition note) and asks for a
   disposition per node: attach media, unpublish, or delete.
2. Staff's answers are recorded here (nid → outcome), by D7 nid.
3. Anything staff decide to unpublish or remove is applied before the **production** migration,
   or after it by an editor.

**Owner:** unassigned. The open question is who at AV staff receives it, and whether it goes
through Than or directly.

## Related

- [Sprint 3](../sprints/sprint-03-av-core-implementation.md), AV14 row and the acceptance criterion
  that tracks this handoff
- [orphaned-content-temp-group-on-migration.md](orphaned-content-temp-group-on-migration.md) —
  a different population (18 *published video nodes outside any collection*), easy to confuse
  with this one because the count is the same
- [production-migration-planning.md](production-migration-planning.md)
