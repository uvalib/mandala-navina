# Spike 5 Zotero import prototype (throwaway)

Not a Drupal module and not meant to be merged as code. It exists to prove the design in
[Spike 5, finding 7](../../../spike-05-bibcite-sources.md). Local DDEV only.

- `ZoteroClient.php` — Zotero API v3 reader (public libraries need no credentials; a private
  library takes an API key header — never store a key in config, a node, or this repo).
- `ZoteroMapper.php` — Zotero item JSON to bibcite values; type table seeded from D7's
  `ZoteroBiblioMap.inc`.
- `run.php` — fetch and map only, no Drupal: `php run.php users|groups <id> [max]`.
- `write.php` — Drush writer (`MODE=upsert|mutate|render|clean`), upsert keyed on the Zotero item
  key. It reuses `bibcite_remote_db_name` as the key purely to avoid a config change; production
  needs a dedicated field. `MODE=clean` deletes everything it created.
