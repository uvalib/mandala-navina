# s3-sync's CI/CD pipeline is deferred, pending the reindeer_x consolidation decision

**Area:** deployment / CI-CD / s3-sync / reindeer_x consolidation
**Raised during:** Session 2026-08-11 (CI/CD pipeline inventory)
**Jira:** (add when available)
**Priority:** Low — no pipeline needed until/unless s3-sync ships standalone code
**Status:** **DECIDED (2026-08-11, Yuji) — deferred.** Not scoping a pipeline now.

## What we found

`s3-sync/` in the monorepo is currently **empty** — one `README.md` pointing at the
legacy `mandala_s3_synch` repo (per CLAUDE.md: "being merged into `s3-sync/`"), no
code migrated yet. There is nothing to build a pipeline for today.

Read the legacy `mandala_s3_synch` repo directly: it's the `clsync` (inotify) +
`synchandler` (Perl) + `rclone` pipeline — watches a directory, splits `.json`
(add) vs `.ids` (delete) files, uploads to S3. **This is exactly the same
`synch`/`synchandler` pipeline described in
[solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) and already proven
replaceable by [Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) Part A** —
a native Node `chokidar` + `@aws-sdk/client-s3` file watcher folded directly into
`reindeer_x`, proven on the `spike/08-reindeer-x-consolidation` branch (not yet
merged to `main`).

The architecture doc's own recommended next step #1 is "fold synchandler logic into
reindeer_x" — i.e. the plan was already to retire this as a standalone component,
not stand it up fresh in D11.

## Why deferred rather than scoped now

Building `s3-sync/` out as its own deployed service with its own pipeline would
likely be **wasted work** if Spike 8 Part A gets promoted to `main` — reindeer_x
would absorb the function instead. But that promotion is itself entangled with the
still-open reindeer_x review (is an always-on sync service even needed — see
[reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md)).
Scoping a pipeline for `s3-sync/` before that review lands risks building the wrong
thing twice.

## What needs to happen

Nothing yet. Revisit once the reindeer_x review (deferred to a later session,
2026-08-11) resolves:

- If reindeer_x absorbs the sync function (Spike 8 Part A promoted): `s3-sync/`
  likely stays empty permanently, and this note can close as superseded.
- If reindeer_x is retired or the sync moves to a pull/batch model: reassess
  whether `s3-sync/`'s legacy `mandala_s3_synch` content needs to land in the
  monorepo after all, and only then scope its pipeline.

## Cross-references

- [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md) — the gating review
- [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) — the consolidation architecture
- [Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) — Part A proof
- `s3-sync/README.md`
- Legacy: `mandala_s3_synch` (`/Users/ys2n/Code/mandala-legacy/mandala_s3_synch`, per-driver path)
