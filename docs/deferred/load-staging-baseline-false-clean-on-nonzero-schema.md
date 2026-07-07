# load-staging-baseline.sh False "Clean Baseline" on a Non-D11 Schema
**Area:** migration / tooling / DX / scripts
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9, local rehearsal)
**Jira:** (add when available)
**Priority:** Medium — a real footgun; it actively misled a session

## What happened

`scripts/load-staging-baseline.sh` imports a dump into DDEV's default `db` and
then asserts a clean **pre-migration** baseline by counting `shanti_image` nodes,
image paragraphs, and `migrate_map_d7_images_*` rows. Its count helper swallows
errors:

```bash
q() { ddev mysql -N -e "$1" 2>/dev/null | tr -d '[:space:]' || echo 0; }
```

When the dump is **not a D11 schema at all** (e.g. a D7 source dump — whose tables
`node_field_data` / `paragraphs_item_field_data` don't exist), every check errors
on a missing table, `|| echo 0` turns each into `0`, and the script reports
**"CLEAN pre-migration baseline"** and exits 0. It cannot tell "a clean D11
baseline" apart from "not a D11 database."

This bit this session: the file `mandala-stage-images-db_*.sql.gz` — despite the
`-stage-` name and a staging-RDS header — is the **D7 images source**, not a D11
baseline. `load-staging-baseline.sh` loaded it into `db` and falsely green-lit it;
it had to be reloaded into `d7_images` via `load-d7-source.sh`, and `db` rebuilt
as a fresh D11 site.

## Recommendation

1. **Assert the schema is actually D11 before the baseline check** — e.g. require
   `node_field_data` (or the `shanti_image` content-type config) to *exist*, and
   fail loudly if not, instead of coercing a missing-table error to `0`.
2. Drop the `|| echo 0` swallow (or distinguish "table absent" from "count is 0").
3. **Revisit whether the script is still needed at all.** This session established
   that the local D11 baseline comes from a fresh `site:install` + `config:import`
   (see the install-path note below), *not* from a staging D11 dump — so a
   separate "staging baseline dump" may not exist in the normal workflow. If it's
   kept for the case where a real D11 dump is used, the schema guard above is
   mandatory.

## Also worth capturing (adjacent gotcha)

`scripts/rebuild.sh` (`site:install --existing-config`) is a **known-broken** local
install path: the committed config declares the `standard` profile, which has a
`hook_install()` and therefore can't be installed from config. The working flow is
plain `drush site:install` → `drush config:set system.site uuid <committed-uuid>`
→ `drush entity:delete shortcut` + `shortcut_set` → `drush config:import`. This is
noted in the 2026-06-23 (1a.7) session log; repeated here because it recurs on
every from-scratch rebuild.

## Related

- [Staging migration execution prerequisites](staging-migration-execution-prerequisites.md)
- `scripts/load-staging-baseline.sh`, `scripts/load-d7-source.sh`, `scripts/rebuild.sh`
