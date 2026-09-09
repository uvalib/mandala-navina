# AV4: node, group, membership and alias migrations — decisions and findings

**Sprint:** [Sprint 3 — AV core](../sprints/sprint-03-av-core-implementation.md), backlog item AV4
**Status:** built 2026-09-09; first full local run same day
**Relates to:** [AV2 content-type decision](av-content-type-decision.md) ·
[AV3 paragraph model](av-paragraph-model.md) ·
[AV5/AV14 dispositions](av-anomalous-node-dispositions.md) ·
[ADR 016](../adr/016-public-url-structure-single-host.md) ·
[ADR 017](../adr/017-legacy-identity-composite-key.md)

AV3 built the paragraph types and the 17 paragraph migrations. AV4 is everything
that turns those into actual content: the `audio`/`video` node migrations, AV
collections as Groups, memberships, URL aliases, and the two supporting
migrations (taxonomy and files) the nodes depend on.

---

## 1. What AV4 adds

Ten new migrations, bringing the `mandala_av` group to **27**:

| Migration | Rows | Notes |
|---|---:|---|
| `d7_av_tags` | 1,489 | D7 `tags` vocabulary → D11 `tags`. Entirely flat — no hierarchy rows |
| `d7_av_files` | 8,292 | ~2.9 GB, fetched over HTTP from the live D7 AV site |
| `d7_av_audio` | 4,187 | |
| `d7_av_video` | 7,396 | |
| `d7_av_collections` | 152 | → `collection` Group bundle |
| `d7_av_subcollections` | 85 | → `subcollection` Group bundle |
| `d7_av_node_collection_membership` | 11,517 | `group_node:audio` / `group_node:video` |
| `d7_av_user_memberships` | 1,235 | `group_membership` |
| `d7_av_url_alias` | 11,900 | → `/node/{id}` path aliases |
| `d7_av_collection_url_alias` | 247 | → `/group/{id}` path aliases |

Every total was predicted with independent SQL before the migration was written,
and each matched on registration. The one apparent exception is instructive:
`d7_av_node_collection_membership` predicted 11,518 (7,333 video + 4,185 audio)
and registered **11,517**. The missing row is a membership whose *group* is an
`audio` node rather than a collection — D7 junk that the source's
collection/subcollection filter correctly excludes.

### Supporting code

- **`D7AvNode`** (`d7_av_node`) — extends core's `d7_node`; supplies the ordered
  `field_collection` item ids each of the 13 paragraph reference fields needs.
- **`D7AvFile`** (`d7_av_file`) — scopes `file_managed` to the files three named
  AV fields actually reference, and builds a correctly encoded fetch URL.
- **`AvLanguageLayerTrait`** — the single place the `und`/`en` storage layers are
  reconciled, shared by `D7AvNode` and `D7AvFieldCollection` (see §3).
- `D7ImageGroupMembership` gained a `member_types` option; `D7ImageUserGroupMembership`
  now collapses duplicate (user, group) pairs. Both defaults are unchanged for
  Images, verified against its data.
- `D7ImageUrlAlias` is reused as-is — it already took a `node_types` option. Its
  name is historical ("Image" is the D7 *site*, not the bundle); the query is
  site-agnostic.

### Content-model additions

`field_subcollection_root` (KMaps, `subjects` domain, 60 rows) is added to the
`collection` Group bundle. It is AV-only — Images collections have no equivalent
and simply leave it empty. Adding it in AV4 rather than deferring to AV6 avoids
re-running the collection migration later just to populate one field.

Four Group relationship types were installed: `group_node:audio` and
`group_node:video` on both `collection` and `subcollection`.

---

## 2. Field mapping — the decisions worth recording

Most of the 33 node fields are 1:1. These are not.

**Kaltura identity is written as constants, and that is temporary.**
`kaltura_media` stores `entry_id` / `partner_id` / `uiconf_id` / `domain` per
field item and its formatter reads all four from there, so a migrated node is
unplayable without them. Only `entry_id` is real per-item content; the other
three come from D7's site-level `variable` table (`kaltura_partner_id` = 381832,
`kaltura_server_url` = `//www.kaltura.com`) and `mb_kaltura`'s
`MB_MAIN_PLAYER_ID` = 24762821, which is the uiconf_id the live D7 node-view
embed uses (Spike 6). **AV10 builds the real configuration layer and AV13
migrates D7's per-view-mode player settings into it**; at that point these three
columns stop being the source of truth. They are configuration living in a
content column, and the migration config says so.

**`field_available_from` loses its end date on 9 rows.** D7's field is a date
*range* (`value`/`value2`); D11's is a single `datetime`. 595 rows exist, of
which 9 have a `value2` that differs from `value`. Those end dates are not
carried. Widening the D11 field would be an improvement, not a migration.

**`field_rating` migrates raw.** D7 `fivestar` stores 0–100; the whole corpus has
**two** rows, both `20`. Rescaling would be a judgement about what the number
means, and no D11 UI reads it yet.

**Text formats.** `field_pbcore_rights_summary` has a NULL format on all 2,340
D7 rows (plain text) and D11's `text_long` needs one, so it defaults to
`basic_html` — the same convention every other formatted-text field in the
Images and AV migrations uses. `field_copyright_owner`, `field_license` and
`field_year_published` were D7 `text` with a format but map to D11 `string`,
which has none; nothing is lost, as no row carried markup.

**`group_content_access` → `field_group_content_access`.** D7's field has no
`field_` prefix. All four AV realms (0 Public, 1 Private, 2 UVA members, 3
Collection admins) were already present in the D11 storage's allowed values.
AV4 migrates the raw value; **AV7 owns the access *mapping*.**

**Deliberately excluded.** `field_og_collection_ref` (membership migrates as
Group relationships, as Images did); `group_group`, `og_roles_permissions`,
`og_user_inheritance`, `og_user_permission_inheritance` (OG mechanics superseded
by the Group module); `field_rss_feed` (zero collections have it set).

---

## 3. The finding: `und`/`en` on a cardinality-1 field

The 2026-09-08 session established the language-layer rule for *multi-valued*
field_collections — a union of both layers with `en`-preferred ordering, because
`und` holds 6,958 items that exist nowhere else. AV4 found that this rule is
**wrong for a cardinality-1 host field**, and that neither obvious alternative is
right either.

`field_pbcore_instantiation` is cardinality 1 in both D7 and D11. **667 of its
5,297 hosts link two *different* items**, one under each layer. (`field_workflow`,
the other cardinality-1 field, has none: 10,647 hosts, 10,647 items.) A D11
cardinality-1 field cannot hold both, so it would keep whichever reference it saw
first and drop the other — silently, with the node still saving "successfully".

Measured against the 2026-09-01 production dump, across those 667 hosts:

| | hosts |
|---|---:|
| `und` item completely empty, `en` item has data | **353** |
| `en` item's populated sub-fields a strict subset of `und`'s | **314** (307 with `und` genuinely richer, 7 both empty) |
| both items hold something the other lacks | **0** |

**In every case one item strictly contains the other.** So "take the item with
more populated sub-fields" is not a heuristic trade-off — it is exact, and loses
nothing. Both simple rules do lose data: always-`en` would blank 307
instantiations, always-`und` would blank 353.

Confirmed on the 2026-09-09 run: `d7_av_pbcore_instantiation` yields **5,297**
source rows and imports all of them — exactly one per host, exactly the predicted
winners.

⚠ The losers are excluded in `query()`, and that placement matters. The first
implementation dropped them by returning FALSE from `prepareRow()`, which reads
as equivalent and is not: a row dropped in `prepareRow` never reaches the id map,
so migrate still counts it **unprocessed**. `checkRequirements()` then refused
every dependent migration — *"d7_av_audio did not meet the requirements. Missing
migrations d7_av_pbcore_instantiation"* — with 5,297 of 5,964 rows imported and
nothing actually wrong. Excluding them from the source query instead makes the
totals honest: 5,297 in, 5,297 mapped, 0 unprocessed.

The rule is implemented once, in `AvLanguageLayerTrait`, and applied on **both**
sides — `D7AvFieldCollection` skips the losing item so it is never created
(no orphaned paragraphs), and `D7AvNode` references the winner. The sub-field
list is discovered from `field_config_instance` rather than restated in config,
because a duplicated list across two plugins is precisely how they would drift.

> ⚠ Note what this says about the `und`/`en` story. For this field the later
> `en` edit frequently produced a **thinner** record than the `und` one beside
> it. That does not contradict `en`-is-authoritative for *ordering*, but it is
> the one place the layer rule alone gives the wrong answer. Whether it reflects
> an AV cataloguing convention is a question for staff — see §6.

### What the `und`/`en` split actually IS — mechanism, not mystery

Established 2026-09-09, and it settles whether this is a data or a schema
problem. **It is a stale-flag artifact, and the answer is data.**

D7's `language` column on field storage is standard and correct. What is odd is
that this site has multilingual support **disabled** for these content types —
`language_content_type_audio` and `language_content_type_video` are both `0` — so
by the site's own configuration nothing should be language-specific at all.

The `en` rows exist because `node.language` was flipped from `und` to `en` on
7,633 of the 11,583 AV nodes at some point. After the flip, Field API wrote new
rows under `en` and left the old `und` rows in place; it does not clean up the
other layer. The correlation is total:

| `node.language` | field rows under `en` | field rows under `und` |
|---|---:|---:|
| `en` (7,633 nodes) | 11,689 | **2,196** ← stranded pre-flip rows |
| `und` (3,950 nodes) | 0 | 6,914 ← the only data there is |

So `und` on an `en` node is stranded history; `und` on an `und` node is the
record itself. That is why the union is a union and not a filter.

**The 667 instantiation conflicts are the same mechanism producing dirty data**
rather than merely redundant links. Three independent signatures confirm they are
edit residue and not genuine alternatives: the `en` item is newer in **667/667**
cases, one item strictly contains the other in **667/667**, and **all 667 sit on
`en`-language nodes** (none on `und` nodes). Real alternatives would overlap
partially and would appear on both.

> ⚠ **The actual schema question is one level down, and it survives migration.**
> AV does not express Tibetan/Chinese/Dzongkha content through Drupal's
> translation system at all. Each language is a **separate delta** of
> `field_pbcore_title` / `field_pbcore_description` carrying a `field_language`
> sub-field naming the language — a PBCore-shaped model where a title *has* a
> language attribute. That is why the 44 title "differences" below are
> Tibetan-and-English siblings rather than conflicts.
>
> The consequence for D11: every Tibetan title arrives as an `en`-langcode entity
> with "Tibetan" recorded in a content field, so **Drupal's language system knows
> nothing about AV's multilingual content**. Fine for display and for the existing
> Solr contract; a real constraint on any later language-aware search, faceting or
> interface translation. Changing it is an improvement, not a migration
> ([ADR 008](../adr/008-mvp-migrate-not-improve.md)) — but it should be a known
> constraint, not a surprise.

### Is the `und` layer ever just a redundant copy? No.

Asked directly (Yuji, 2026-09-09) and measured by CONTENT across all 13 host
fields, not just by link:

| | items |
|---|---:|
| Same item linked under **both** layers — pure duplicate link | **22,734** |
| `und`-only, host has no `en` items for that field at all | **62,117** |
| `und`-only on a host that has `en` items, content **identical** to one | **0** |
| `und`-only on a host that has `en` items, content **differs** | **751** |
| `en`-only | 32,685 |

**Every redundancy is a duplicate *link*, never a duplicate *item*.** There is
not one case in the corpus where the `und` layer stores a second copy of `en`
content, so the union rule prunes nothing that should be pruned.

Of the 751 real differences, 667 are the `field_pbcore_instantiation` case above.
The other 84 are content, and mostly not English:

| Field | Differences | The `und` side |
|---|---:|---|
| `field_pbcore_title` | 44 | Tibetan 31 · Chinese 5 · Dzongkha 1 · unlabelled 7 (the `en` side is English on 43) |
| `field_pbcore_creator` | 35 | Different people and roles — not translations |
| `field_pbcore_description` | 4 | Tibetan/unlabelled vs Chinese |
| `field_pbcore_contributor` | 1 | `Gye` vs `Chimi Dorji` |

The creator rows read as cataloguing questions rather than migration ones — e.g.
nid 20516 has `und: Rinchen Dorji/Creator` against `en: Rinchen Dorji/Editor` +
`Penchela/Editor` + `Yeshi Wangchuk/Creator`. Worth putting to AV staff.

And the wider population says the same: of the 6,958 `und`-only title items, only
**42%** are English — Tibetan 1,766, Chinese 1,144, unlabelled 1,017, Dzongkha 86,
Russian 7, German 1. Dropping `und` would delete roughly 4,000 non-English titles.

---

## 4. Four silent bugs — two caught before the run, two by it

Both would have produced plausible, wrong data with a clean "success" report.

**1. `uid: uid` maps nothing.** Core's `d7_node` source exposes the node author
as **`node_uid`**; there is no `uid` source field
(`drush migrate:fields-source d7_av_audio` confirms). AV's node and collection
migrations were written with `uid: uid` copied from the Images collection
migration, and produced NULL. Corrected to `node_uid` in all four AV migrations
— and the same latent bug was found and fixed in `d7_images_collections` and
`d7_images_subcollections`. See
[the deferred note](../deferred/images-node-authorship-not-migrated.md), which
also records that all 111,340 migrated Images *nodes* are owned by Anonymous
because that migration has no `uid` mapping at all.

**2. Fetch mode.** `D7AvNode` read query results as objects (`$record->item_id`)
where the result set was associative arrays. Every paragraph reference list came
back **empty** while the node migrated "successfully" — 125,101 paragraphs would
have been created and never referenced. Now `fetchAll(\PDO::FETCH_ASSOC)`
explicitly, in all three plugins.

**3. `sub_process` shape on nested references — 6,814 orphaned paragraphs.**
Found by the first full run. `D7AvFieldCollection` collapsed a single-value
sub-field to a scalar and returned a flat list otherwise, but a nested
field_collection reference is consumed through `sub_process`, which needs an
array of arrays. The failure was almost entirely silent: **4,561 of the 4,562
workflow-note hosts have exactly one note**, so they took the scalar branch and
`sub_process` quietly produced nothing; only the single host with *two* notes
reached the flat-array branch and raised

```
d7_av_workflow:field_transcript_workflow_notes:sub_process:
  Input array should hold elements of type array, instead element was of type 'string'
```

One error line, against 6,814 paragraphs created and **zero referenced** — 4,562
`av_workflow_note` and 2,252 `av_pbcore_format_id`. A nested field_collection
reference now always yields `[['value' => id], ...]`, detected from D7's own
`field_config.type`, while ordinary sub-fields keep the documented
scalar/array distinction.

**4. Nested references set only `target_id`.** The AV3 configs mapped
`migration_lookup` straight onto `target_id`, but a lookup against a paragraph
destination returns `[id, revision_id]` — so the pair was being assigned to one
column of an `entity_reference_revisions` field. All four nested mappings now use
the `_para` / `target_id` / `target_revision_id` shape the node migration uses.

> **The pattern worth noticing.** Bugs 2 and 3 have the same shape: the *rare*
> input errors loudly, the *common* input fails silently. Row counts pass in both
> cases. That is why `scripts/verify-av-migration.sh` checks **reference** counts
> — `paragraph__field_*` and `node__field_*` row counts against their D7
> equivalents — and not just how many entities were created.

Also fixed, for speed rather than correctness: `D7AvFieldCollection::prepareRow()`
ran a `SHOW COLUMNS` and a `tableExists` **per sub-field per row** — around
600,000 redundant round trips across the full run, for an answer that cannot
change mid-migration. Now resolved once per sub-field.

---

## 5. Files

8,292 files, ~2.9 GB, fetched one at a time over HTTP from
`https://av.mandala.library.virginia.edu`. The breakdown is not what the field
names suggest:

| Field | Files | Size |
|---|---:|---:|
| `field_transcript` | 5,379 | 82 MB |
| `field_thumbnail_image` | 2,843 | **2,598 MB** |
| `field_general_featured_image` | 70 | 94 MB |

Audio has no Kaltura-generated poster frame, so editors upload cover art — at
full camera resolution, the largest single file being 10.7 MB. That is 90% of
the migration's file payload.

**URL encoding is done in the source plugin, not with the `urlencode` process
plugin.** 3,467 of these files have names needing encoding, including 79
non-ASCII (Tibetan among them). Core's `urlencode` runs the whole string through
`parse_url`, which tears the tail off the 10 filenames containing `?` or `#` and
is ambiguous for the 4 containing `%`. Encoding per path segment on the raw
filepath, before anything can mistake a literal character for URL syntax, is both
correct and simpler. Verified byte-for-byte: a Tibetan-named transcript
(`ཞིང་ཁམས།.xml`) downloaded with an md5 identical to the live D7 copy.

**`field_transcript` migrates as an inert file field** — present and
downloadable, never parsed. All processing of transcript content is
[Sprint 4](../sprints/sprint-04-av-transcripts.md) / Spike 11.

---

## 6. Runtime: what the local run does and does not tell us

**The stage timings below are DDEV timings and do not predict dev-0.** Worth
stating plainly, because the numbers are seductive and the comparison is
invalid: the ~243/min `shanti_image` baseline everyone quotes was measured **on
dev-0** (container against RDS — see the
[2026-08-27 log](../session-logs/2026-08-27-migration-complete-and-verified.md)),
while every figure below is local. They are not the same measurement, and there
is no correction factor between them.

The environments differ in ways that pull in opposite directions:

| | local DDEV | dev-0 |
|---|---|---|
| D11 database | in-container MySQL, sub-millisecond | RDS over the network |
| Migration source | `d7_av`, same container | `mandala_d7_av` on staging RDS |
| File writes | **mutagen sync** — write amplification over 2.9 GB | direct volume write |
| File fetch | developer network → production host | AWS us-east-1 → production host |

Migrate is chatty per row, so DB round-trip latency dominates and that favours
local. The file stage is the reverse — mutagen makes the local write path much
worse than dev-0's — so the file-stage timing below is the **least**
transferable figure of all.

**The dev-0 estimate therefore stands unchanged** at the 2026-09-08 projection
(~3h34m, realistic band 3.5–5h, derived from dev-0-measured Images rates applied
to AV volumes), plus a file stage that was never in it. Local timings are not a
basis to revise it in either direction.

What the local run *does* establish is correctness — row counts, reference
counts, the language rules and the four bugs — and that transfers completely.

### Full local timing table (2026-09-09, DDEV, both runs collated)

Produced by `scripts/collate-migration-timings.sh local`; row counts are read
from each migration's map table (authoritative), not parsed from log text. A
handful of stages ran twice (the original run, then the remediation after the
nested-reference fix); the table below keeps only the final, correct duration
for each.

| Stage | Rows | Duration | Rate/min |
|---|---:|---:|---:|
| `d7_av_tags` | 1,489 | 8s | 11,168 |
| `d7_av_files` | 8,292 | 676s (11m16s) | 736 |
| `d7_av_pbcore_format_id` | 2,252 | 9s | 15,013 |
| `d7_av_catalog_workflow_notes` | 2,053 | 10s | 12,318 |
| `d7_av_transcript_workflow_notes` | 565 | 6s | 5,650 |
| `d7_av_workflow_notes` | 1,944 | 13s | 8,972 |
| `d7_av_pbcore_instantiation` | 5,297 | 46s | 6,909 |
| `d7_av_workflow` | 10,647 | 125s (2m5s) | 5,111 |
| `d7_av_kmap_annotation` | 2,428 | 12s | 12,140 |
| `d7_av_pbcore_contributor` | 17,350 | 43s | 24,209 |
| `d7_av_pbcore_coverage` | 2,569 | 8s | 19,268 |
| `d7_av_pbcore_creator` | 20,713 | 51s | 24,368 |
| `d7_av_pbcore_description` | 17,435 | 47s | 22,257 |
| `d7_av_pbcore_extension` | 2,692 | 10s | 16,152 |
| `d7_av_pbcore_identifier` | 3,375 | 12s | 16,875 |
| `d7_av_pbcore_publisher` | 7,549 | 20s | 22,647 |
| `d7_av_pbcore_relation` | 6,117 | 30s | 12,234 |
| `d7_av_pbcore_sponsor` | 2,801 | 10s | 16,806 |
| `d7_av_pbcore_title` | 18,647 | 49s | 22,833 |
| **`d7_av_audio`** | **4,187** | **405s (6m45s)** | **620** |
| **`d7_av_video`** | **7,396** | **871s (14m31s)** | **509** |
| `d7_av_collections` | 152 | 6s | 1,520 |
| `d7_av_subcollections` | 85 | 6s | 850 |
| `d7_av_node_collection_membership` | 11,517 | 458s (7m38s) | 1,509 |
| `d7_av_user_memberships` | 1,235 | 18s | 4,117 |
| `d7_av_url_alias` | 11,900 | 48s | 14,875 |
| `d7_av_collection_url_alias` | 247 | 5s | 2,964 |
| **total** | **171,207** | **50m2s** | |

The two node migrations (bolded) are the only stages with any bearing on the
dev-0 node-rate uncertainty flagged on 2026-09-08 — 620/min and 509/min locally.
**Do not use these to revise the dev-0 estimate**; per the table above, local
node rates run faster than dev-0's measured Images rate because they skip the
RDS round trip, which is exactly the effect the 09-08 estimate was worried about
running the other way (AV nodes carrying more fields than Images nodes). The two
effects are unrelated and neither cancels the other.

---

## 7. Open questions

- **The 42 ordering ties.** Carried from 2026-09-08. Two items on one host still
  resolving to the same delta; `item_id` is the documented fallback, not a
  considered answer. Ask AV staff whether a cataloguing convention should decide
  it.
- **`field_pbcore_instantiation`'s thinner `en` records** (§3). The rule is
  data-preserving regardless, but knowing *why* would tell us whether it applies
  elsewhere.
- **`field_tags` "Tags Old"** — labelled that way and hidden on `video` while
  live as "Tags" on `audio`. Migrates as-is per AV2; staff should say what it
  should become.
- **AV14's 18 media-less nodes** — the nid list still needs to reach AV staff.
- **uid 7471** holds two AV collection memberships but has no D11 user; it is a
  D7 account deleted with the OG rows left behind, not a user-migration gap (the
  user migration imported all 1,542 shared-DB users with zero failures, verified
  on dev-0). Those two rows are skipped.
- **dev-0 cannot run this yet.** Its `MIGRATE_SOURCE_DATABASE` points at the
  Images source and no D7 AV database exists on the staging RDS. Loading
  `mandala_d7_av` and adding `MIGRATE_AV_DATABASE` to the container env is a
  `terraform-infrastructure` change plus a deploy, once the migrations are
  proven locally.
