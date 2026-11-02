# Spike 13 S1 artifacts (throwaway, not for production)

Evidence for [Spike 13](../../spike-13-texts-whole-book.md) step S1. Nothing here is merged
into the application.

- `s1-setup.php`: creates a `book` node type and sets `book.settings` (the Book 3.x shape).
  Its stand-in node creation is **not used** (see the doc: every D7 Texts node id collides with an
  existing D11 node); the stand-in nodes came from `spike13_node` instead.
- `migrate_plus.migration.spike13_node.yml`: stand-in node migration (core `d7_node` source,
  `book` type), new D11 ids.
- `migrate_plus.migration.spike13_book.yml`: the contrib `d7_book` migration with `nid` and
  `book/bid` resolved by `migration_lookup` instead of copied straight through.
- `s1-compare.sql`: compares every D11 `book` row with D7 (`menu_links` + `book`), translating D7
  ids through the migration map. Counts rows missing and differing in bid, pid, weight, depth, p1..p9.

Run order: `drush config:import --partial --source=<dir with the two yml>`; point the DDEV `migrate`
DB connection at `d7_texts`; grant the `db` user on `d7_texts` (`scripts/load-d7-source.sh` does it);
`migrate:import spike13_node --idlist=<nids>`; `migrate:import spike13_book --idlist=<mlids> --force`.
