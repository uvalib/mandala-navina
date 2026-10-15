# How-to: Write `trid_i` onto AV kmassets documents

Spike 11 / Sprint 4 T9 half (a), [#290](https://github.com/uvalib/mandala-navina/issues/290).
The React client finds a node's transcript through `trid_i` on the node's kmassets document,
and the solr-proxy join filter (PR #315) joins on the same field. Until this writer, no D11
AV document carried it.

## How it works

- `TranscriptIdContributor` (a `mandala_kmassets_sync.doc_contributor`) sets `trid_i` on
  `audio` and `video` documents. A node with no transcript gets no field.
- It asks a `TridResolverInterface`. Today's implementation, `LegacyTridMapResolver`, reads
  the table `mandala_kmassets_trid_map`, keyed by `(legacy_site, legacy_nid)` per ADR 017,
  never by a D11 node id.
- **Swap point for T2:** when the `av_transcript` entity exists, add a resolver that reads
  it and repoint the `mandala_kmassets_sync.trid_resolver` service. The contributor and the
  table's consumers do not change; the table and `kmassets:load-trid-map` can then be removed.

## Load the map

Source is D7 `transcripts_apachesolr_transcript` (`id` = D7 nid), read through the
`migrate_av` connection, so the D7 AV database must be present in that environment.

```bash
ddev drush updb -y                       # creates mandala_kmassets_trid_map (update 10001)
ddev drush kmassets:load-trid-map --dry-run
ddev drush kmassets:load-trid-map        # truncates and reloads; safe to re-run
```

Expected: 5,343 rows (one transcript per node, audited in Spike 11). The 19 orphan `trid`s
have no node and are not loaded; the 41 never-processed nodes have no tracking row and get no
`trid_i`.

## Then reindex the AV documents

`trid_i` reaches Solr only when a document is rewritten (`kmassets:index <nid>` or
`kmassets:index-all video` / `audio`). Existing D11 AV documents carry no `trid_i`, so the
rewrite is what adds it, and since #321 the field has docValues.

**Check which Solr master your environment points at first** (`solr_master_url` in
`mandala_kmassets_sync.settings`): the default is the staging master, and a local
`index-all` writes there.

## Next: the go/no-go measurement

Once `trid_i` is populated on dev, measure cross-core join time through the proxy (the T9
checkpoint). The pass/fail threshold is set by the team before measuring.
