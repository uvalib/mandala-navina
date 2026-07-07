# kmassets Sync Hook Fires Per-Node During Migration
**Area:** solr / kmassets / migration / sync / performance
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9, DDEV migrate → validate → rollback run)
**Jira:** (add when available)
**Priority:** Medium — not a correctness bug, but a real efficiency + cutover-load concern

## Observation

During `migrate:import` of the `mandala_images` group, **every `shanti_image` node
insert fires `mandala_kmassets_sync`'s `hook_node_insert`**, which calls
`KmassetDirectSink` to do a *synchronous* Solr write. On the full Images migration
that is **~111k inline Solr writes**, one per created node, interleaved with the
import.

It surfaced on the DDEV rehearsal as a per-node log error:

```
[error] Failed to index kmassets doc for node 42457:
        mandala_kmassets_sync.settings.solr_master_url is not configured.
```

On DDEV `solr_master_url` is injected out-of-band (settings.php / VPN), not in
committed config, so locally it is unset and every insert logs this. **The
migration is unharmed** — by design (1a.8) Solr failures are logged and never crash
a node save — so all 111k nodes still import. This note is about the *pattern*, not
a failure.

## Why it matters

1. **Redundant with the intended bulk index.** The 1a.9 cycle already has a
   dedicated `audit` phase that runs `kmassets:index-all shanti_image` (bulk) plus
   `kmassets:audit`. Per-node sync during the migrate duplicates that work.
2. **Couples migration to Solr availability + throughput.** A migrate run should be
   a DB-only concern; today it fans out one synchronous HTTP write per node.
3. **Cutover load — the sharp edge.** On the *staging* run `solr_master_url` **is**
   configured, so those ~111k synchronous writes would actually hit the **shared
   staging Solr master inline**, during the migrate — significant load on shared
   infra and a materially slower import, for docs the bulk `kmassets:index-all`
   step is going to (re)write anyway.
4. **Log noise** — ~111k error/emit lines per run obscure real problems.

## Recommendation

Suppress the sync hook while a migration is running, then rely on the bulk index:

- Gate `hook_node_insert/update/delete` on a "migration in progress" signal — e.g.
  a static/`State` flag toggled by a migrate event subscriber
  (`MigrateEvents::PRE_IMPORT` / `POST_IMPORT`), or check
  `\Drupal::state()`/a container param — and **no-op the sync** when set.
- Optionally also short-circuit the sink when `solr_master_url` is empty (turns the
  111k DDEV errors into a single "sync disabled: not configured" notice).
- Post-migration, index deliberately via the cycle's `audit` phase
  (`kmassets:index-all` + `kmassets:audit`), which is already the sanctioned bulk path.

## Related

- [kmassets sync error-management & resubmit](kmassets-sync-error-management.md)
- [kmassets:audit hardening follow-ups](kmassets-audit-hardening.md)
- [kmassets uid identity across migration](kmassets-uid-identity-across-migration.md)
- Sink + hooks live in `drupal/web/modules/custom/mandala_kmassets_sync/`.
