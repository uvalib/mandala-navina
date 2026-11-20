# How-to: Dump a D7 asset database from production into a dated local repository

`scripts/dump-d7-production-source.sh` pulls a D7 site's database from
`rds-mysql8-production`, read-only, and keeps every dated copy locally. Use it to get the
source for a migration (for example Texts for TX9/TX10) without a one-off manual dump, and to
re-run a migration against the exact data it was developed on.

## Run it

```bash
./scripts/dump-d7-production-source.sh --check texts   # connect only, writes nothing
./scripts/dump-d7-production-source.sh texts           # dump one site
./scripts/dump-d7-production-source.sh av images       # several
./scripts/dump-d7-production-source.sh all             # the six asset sites (not shared)
./scripts/dump-d7-production-source.sh shared          # the user base: name it explicitly
./scripts/dump-d7-production-source.sh --push dev-0 av # dump, then copy up to dev-0 and verify
./scripts/dump-d7-production-source.sh --keep 3 texts  # dump, then keep the newest 3
./scripts/dump-d7-production-source.sh --list          # what the repository holds
```

Sites: `images`, `av`, `sources`, `texts`, `visuals`, `home`, and `shared` (the user and
role base all five sites share). A raw production database name also works.

Needs docker (with the VPN, so it can reach `*.internal.lib.virginia.edu`) and SSH access
to the production node as your own user with `~/.ssh/id_rsa`. The method and its traps
(the vhost password, the `mysql:8.0` container, `--no-tablespaces`) are explained in the
header of `scripts/refresh-d7-staging-source.sh`.

## Where the dumps go

Default `$HOME/mandala-d7-dumps`, outside this public repo; set `D7_DUMP_DIR` to move it.

```text
<site>/mandala-prod-<site>-db_YYYY-MM-DD.sql.gz         the dump
<site>/mandala-prod-<site>-db_YYYY-MM-DD.sql.gz.meta    sha256, size, source db, skipped tables, row counts
<site>/latest.sql.gz                                    symlink to the newest
```

A second dump on the same day gets a `-HHMM` suffix. Nothing is overwritten. Directories are
mode 700 and files 600. **The `shared` dump holds real user data**: keep it on an encrypted
disk and do not copy it to anything shared. The other sites carry no real user data, but
they are still production content, so the same care applies.

## What is left out

Every `cache*` table, plus `watchdog`, `accesslog`, `feeds_log`, `flood` and `sessions`.
No migrate source reads them, and `sessions` holds live session ids. Matching is by exact
name, because a `%log%` pattern would also catch real content such as
`field_data_field_catalog_workflow_notes`. Override the exact-name list with
`SKIP_TABLES="a b c"`. The skipped names are recorded in each `.meta` file.

## Copy up to dev-0 (`--push`)

`--push dev-0` (or `--push user@host`) copies each new dump and its `.meta` to
`/home/dumps/<site>/` on the host and checks the sha256 over there against the `.meta`.
`PUSH_DIR` changes the destination. Asset sites land `root:admin`, directory 2770, files
660, so any account in `admin` can read them. `shared` lands in `/home/dumps/shared/`, owned
`root:root`, directory 700 and files 600. dev-0's root volume is not backed up, so this is a
convenience copy, not the record.

## Check a dump, then load it

The `.meta` file holds exact row counts for `node`, `users`, `url_alias`, `og_membership`
and `field_collection_item` where the table exists. After loading, compare them with the
loaded copy.

- **DDEV:** `./scripts/load-d7-source.sh ~/mandala-d7-dumps/texts/latest.sql.gz d7_texts`
  (the dump has no `CREATE DATABASE`, so it loads into any name).
- **dev-0:** the staging load is `scripts/refresh-d7-staging-source.sh`, which streams
  production to staging RDS directly and does not read this repository. Loading a saved dump
  onto staging is not built yet.
