# 2026-10-20: T2 soft-delete, node-delete cascade, orphan manager, deploy fix

Hand-written, abridged. Driver: Yuji Shinozaki. Follows the 2026-10-19 T2 review meeting.

## Deploy fix

- After #350 merged, the dev-0 deploy failed at `deploy_redis.yml`. Cause: `pipeline/deployspec.yml` had
  `runtime-versions: python: 3.x`, which resolved to the 3.15 alpha, and ansible-core crashed on import. A retry failed
  identically. The last good deploy (2026-10-16) ran Python 3.14.7.
- #355 pinned 3.12 with no evidence for that choice; #356 changed it to 3.14, the proven version. Both deployed.
  `pip3 install ansible` is still unpinned.
- dev-0 `drush sql:query` needs `--extra=--skip-ssl-verify-server-cert` (self-signed RDS certificate).

## Merged

- #351 (10-16 session log), #354 (Than's 10-19 log, with the link-shared artifact URL redacted and squash-merged;
  revoking that artifact's "anyone with the link" sharing is still to do).
- #358 docs: option B for parent-node delete, administrator-only orphan handling.
- #359 soft-delete flag on `av_transcript` and `av_tcu` (ADR 019), lifecycle service, reader and access handlers
  that hide deleted rows. Update hook 10002.
- #360 node-delete cascade: deleting an AV node soft-deletes its transcript and units, with a snapshot (title, legacy
  key, collections, who and when), and a warning on the node delete form. Update hook 10003.

## Decisions

- Option B: a node delete soft-deletes its transcript (editors in collections can delete AV nodes today, so this is
  everyday editing, not only rollbacks).
- Administrators alone handle orphans. Editor access is stickier (editor B deleting what editor A orphaned while
  tidying) and is deferred with the admin screen and re-attach, all behind one service (issue #357).
- First version of orphan handling is a service plus drush commands; no screen.
- The cascade is not suppressed during migrate rollback: T5's import finds the soft-deleted row by id (= D7 trid),
  re-points `node` and restores it.

## Built after the merges

- Orphan manager service and `av-transcripts:orphans|export|restore|purge` commands (see the plan, "Orphan manager as
  built"). Submitted as a separate PR.

## Corrections made along the way

- Drush has no Drupal user, so "re-checks the permission" was wrong; the safeguard is shell access plus the purge
  confirmation.
- Moving the snapshot from predelete to delete did not fail any test: Group removes relationship rows after
  `hook_node_delete`. Predelete is kept so the code does not rely on that ordering.

## Open

- Than (#353): 3,039 untimed units, the negative start on tcu 142269, where his repairs land.
- Not yet checked: what deleting a whole collection does to its members' transcripts.
- The 19 orphan trids, the four workflow base fields, T5 migration, the phase 7 resolver swap (must skip soft-deleted
  transcripts).
