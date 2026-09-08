# Session Log: Sprint 3 opened — AV2, AV3, AV5, AV14 decided and built; local DB reconciled with dev-0

**Date:** 2026-09-08
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** Sprint 3 (AV core) moved from ○ Planned to ◐ In progress. **Four backlog
items closed** — AV2, AV3, AV5 and a new AV14 — and **AV4 half-built**. The local
development database was reconciled with dev-0, which exposed four real defects in
`scripts/update-db-from-remote.sh`.

| PR | State | |
|---|---|---|
| [#189](https://github.com/uvalib/mandala-navina/pull/189) | merged | AV2 / AV5 / AV14 |
| [#190](https://github.com/uvalib/mandala-navina/pull/190) | merged | `update-db-from-remote.sh` fix + CLAUDE.md startup ritual |
| [#191](https://github.com/uvalib/mandala-navina/pull/191) | merged | AV3 — 15 paragraph types |
| [#192](https://github.com/uvalib/mandala-navina/pull/192) | this log | |
| [#193](https://github.com/uvalib/mandala-navina/pull/193) | **open (draft)** | AV4 part 1 — bundles, source plugin, 17 paragraph migrations |

> **This log is hand-written and abridged, not produced by `scripts/save-session-log.py`.**
> The session handled real user PII (1,543 migrated user records) and quoted D7 node
> titles containing individuals' names. The repo is public and its history is permanent,
> so the transcript was not committed. Personal names are omitted throughout.

---

## 1. AV2 — `audio` and `video` stay two content types (PR #189, merged)

Decided against the real production dump rather than the audit's summary.
**Two D11 content types, built from one shared field definition** so they cannot drift
the way D7's did. Recorded as a scope note, not an ADR, because it conforms to
ADR 008/010/016 rather than changing any.

Deciding evidence:

- 29 of the fields are shared; the exclusive ones are `field_audio`, `field_video` **and
  `field_thumbnail_image`**.
- [ADR 016](../adr/016-public-url-structure-single-host.md) clause 3 already keys the URL
  grammar on the D11 content type and says explicitly not to align it to the single
  `audio-video` kmassets facet. Keeping two bundles needed no ADR amendment; collapsing
  would have required one.
- All 29 shared fields differ in stored settings, but 28 differ only in widget weight.
  The substantive residue — `field_tags` labelled **"Tags Old"** and hidden on video while
  live as "Tags" on audio, and a diverged `field_language_kmap` search root — migrates
  **as-is**. Harmonising it is an improvement, not a migration.

**Audit correction:** the audit said the bundles "differ only in which single field holds
the media reference." They differ in two places — `field_thumbnail_image` is **audio-only**
and used on 2,844 of 4,187 audio nodes (68%), zero on video. Kaltura generates a poster
frame for video; audio has none, so editors upload cover art.

## 2. AV5 and AV14 — dispositions for the two anomalous node populations (PR #189)

**AV5 — exclude all 68 `MISSING_TYPE` nodes.** The audit framed this as exclude-vs-repair;
repair turned out never to have been available. The bundle's only field instance is
`og_group_ref`, so the Kaltura entry ID was dropped at save time — there is nothing to
repair *from* — and `node_type` has no `MISSING_TYPE` row, so there is no bundle to
migrate into. All 68 are 2014, uid 1, titled with `.jpg` filenames, hold zero field data,
and sit in one staff triage collection ("Admin: On Kaltura Not in Mediabase") which
survives regardless, holding 285 real AV nodes.

**AV14 (new backlog item) — migrate the 18 media-less AV nodes as-is.** The audit recorded
these as a required-field gap but gave them no owner. They are **not** empty shells: all 18
have a PBCore title, 17 have workflow data, and they span 15 published collections of which
only 7 are scratch. Migrating them published reproduces what D7 already does; the nid list
goes to AV staff as pre-cutover cleanup. ⚠ The media field is required in D7, so these will
be **uneditable in the D11 node form** until someone supplies a reference.

## 3. Local database reconciled with dev-0 — and a silent failure it exposed

Yuji asked mid-session for the local DB to be reconciled with dev-0. What surfaced was
worse than config drift:

| | Local (before) | dev-0 |
|---|---|---|
| Active config vs `config/sync` | 17 missing, 5 different | in sync |
| users | **2** | **1,543** |
| `collection-group_membership` | 18 | 133 |
| `subcollection-group_membership` | 41 | 421 |
| `authmap` | 0 | 1,385 |

**The local database had memberships for admin only**, because migration had run with no
real user base. Any access, permission or membership testing against it would have been
meaningless while appearing to pass — and nothing surfaced it. `git status` was clean and
the site worked.

Resolved in two stages: config imported first (which also installed two modules never
installed locally, `shanti_images_carousel` and `shanti_collections_view`), then a **full
dump restore from dev-0** at Yuji's direction. Local now matches dev-0 exactly on nodes,
users, groups, paragraphs, authmap and both membership counts. Snapshots
`pre-av3-config-import-20260908` and `pre-dev0-full-restore-20260908` retained.

**New standing practice, added to [CLAUDE.md](../../CLAUDE.md) "Session startup" step 3:**
check the local DB against dev-0 at the start of every session, alongside `config:status`
and confirming `config/sync` is pushed. This failure mode is silent and cost real
confidence this session.

## 4. `scripts/update-db-from-remote.sh` — four defects (PR #190)

The script could not complete a pull from dev-0 on a personal computing-id login. All four
were found by running it; the fixed script was then verified end to end (86MB dump pulled,
imported, config reasserted clean).

1. **`DRUPAL_HOME` wrong** — the Drupal root in the deployed image is
   `/opt/drupal/app/drupal`, not `/opt/drupal/app`. Exit 126.
2. **`docker exec` needs `sudo`** — personal logins are not in the docker group on these
   hosts; passwordless sudo is granted instead.
3. **`mariadb-dump` fails TLS against RDS** — *"self-signed certificate in certificate
   chain"*; the container CA bundle lacks the RDS CA. Drupal's own PDO connection is
   unaffected, so this only ever bit the dump path.
4. **The validation gate could not detect a failed dump — the dangerous one.** The only
   check was `[ ! -s "$DUMP_FILE" ]`. Defect 3 produced a **20-byte file**, which passes a
   non-empty test, so the script would have proceeded to `ddev import-db` and **replaced
   the local database with it**. Now checks gzip integrity *and* mysqldump's
   `-- Dump completed` trailer.

`REMOTE_HOST` now defaults to the internal DNS name rather than a per-developer SSH alias.

**Correction to a standing note:** `mysqldump` **is** present in the current deployed
container, so the "drush sql:dump fails silently on dev-0" behaviour recorded on
2026-08-25 is fixed in this image. Verify the artifact anyway — a dump can still fail for
other reasons.

## 5. AV3 — 15 paragraph types, built (PR #191)

Every D7 `field_collection` becomes a Paragraph type 1:1, **except** the three
structurally-identical workflow-note collections, which consolidate into a single
`av_workflow_note` referenced by three separate fields on `av_workflow`. The streams stay
distinct at the field level; only the shape is defined once — the same constraint AV2 set,
against the same drift risk.

The two cardinality-1 groups (`av_workflow`, 29 sub-fields; `av_pbcore_instantiation`, 25)
stay Paragraphs rather than flattening onto the node, **because of AV2**: two node bundles
means ~54 node fields would need instance config twice. Paragraph types are shared across
both bundles for free.

**Built, not just designed:** 15 types, 84 new field storages, 87 instances, 186 config
files. Config was generated through Drupal's entity API and exported, not hand-authored.
Nested paragraphs were created, saved, reloaded and read back through both levels, and an
invalid `list_string` was rejected with exactly one violation — confirming the 46
`list_text` fields' allowed values carried across and are enforced.

**Two more audit corrections:** `field_workflow` has a **third** nested note collection
(`field_workflow_notes`, 1,945 live items) the audit never listed, and
`field_pbcore_format_id` is itself a nested `field_collection`, not the scalar the audit's
flat list implies. `field_pbcore_genre` is vestigial — 1 item, no live instance — and
excluded.

> ⚠ **`config:export` collateral damage, worth the group's attention.** The export
> round-tripped three unrelated hand-edited files (the two `d7_images` collection
> migrations and `views.view.collection_gallery`) through Drupal's YAML dumper, stripping
> their explanatory comments. Those were reverted and are not in the PR — comments are not
> config data, so `config:status` stays clean. This is
> [`config-export-drift-hand-edited-yaml.md`](../deferred/config-export-drift-hand-edited-yaml.md)
> hitting for real; anyone running `config:export` must currently catch and revert it by
> hand.

## 6. Why the catalog-note body was renamed — challenged, then evidenced

Yuji asked why `field_description` and `field_workflow_note` were combined. The original
justification was structural inference (the surrounding author/date/importance fields are
identical), which was **not good enough**. Checked against the dump, and the evidence is
stronger than the inference was:

`field_description` is **not exclusive to catalog notes**. In D7 it is attached to two
field_collection bundles meaning different things — `field_pbcore_description` (15,669
rows, public HTML descriptive metadata, median 197 chars) and
`field_catalog_workflow_notes` (2,049 rows, internal cataloguing notes, median 88). D7
treats them differently downstream too: `mb_metadata` strips the whole `field_workflow`
group from the Solr index as internal state, while PBCore descriptions are public.

So `field_description` is a D7 modeling accident carrying two unrelated meanings. Keeping
it on the note type would have put **three** unrelated meanings on one `paragraph` storage:
Images' `image_descriptions`, AV's public description, and AV's internal notes.

**No public description data is lost.** All 15,669 public rows go to
`av_pbcore_description.field_description`, name unchanged. Only the 2,049 internal notes
are renamed.

**AV4 must map** `field_catalog_workflow_notes.field_description` →
`av_workflow_note.field_workflow_note`. This is the one place the otherwise-1:1 field
mapping breaks, and it is easy to miss because the source name still exists in D11 meaning
something else.

## 7. A content finding worth recording before search quality is judged

Chasing the empty-description question surfaced something larger:

| AV nodes (audio + video) | 11,583 |
|---|---|
| With at least one populated description | 8,096 (69.9%) |
| With description items but all empty | 249 |
| With no description item at all | 3,238 |
| **→ No usable description text** | **3,487 (30.1%)** |

**Nearly a third of AV content has no description at all**, and only 249 nodes of that are
an "empty item" problem. Those assets migrate showing a title and nothing else and
contribute nothing to `caption`/`summary` in kmassets. This is a **pre-existing D7 content
gap the migration cannot fix** — but it will look like a search-quality problem after
cutover if nobody knows the number.

Of the 1,796 empty description items: **814 are blank** (no text, type or language — 783 of
them on nodes that already have descriptions, so inert noise) and **982 are stubs** carrying
a type and/or language but no text. The stubs are the structured counterpart of the catalog
workflow notes that say in prose *"Needs an English description."* Proposed for AV4: skip
the blanks, keep the stubs.

## 8. Migration runtime estimate

Derived from the measured Images run (2026-08-27), not guessed. Measured rates: nodes
**~243/min**, paragraphs **~1,150/min**, membership **~239/min**, aliases **~1,311/min**.
Note the Images log's headline "~15h" was the pre-run estimate — the measured segments sum
to **19h14m**.

Applied to AV's volumes (11,583 nodes · 125,523 paragraphs · 11,672 memberships · 11,583
aliases): **~3h34m**, realistic band **3.5–5h**. AV has one tenth the nodes Images did, and
nodes are the slow axis. Largest uncertainty: AV nodes are heavier (~30 fields plus 13
paragraph reference fields), so the node rate may come in below 243/min.

**Operationally this matters more than the number.** dev/staging instances stop nightly
11pm–6am, a full instance stop that kills a `docker exec`'d migration, and a resumed
migration re-processes every source row regardless of the migrate map. At ~4h AV fits in a
single window; Images' 19h could not.

## 9. AV4 — half built (PR #193, open as a draft)

**Decision: the first AV migration run is LOCAL.** dev-0 cannot run it without work only
Yuji can do — its `MIGRATE_SOURCE_DATABASE=mandala_d7_images` points at the Images source,
**no D7 AV database exists on the staging RDS**, and creating one needs DB credentials
(blocked by this session's auto-mode classifier) plus a `terraform-infrastructure` env
change and a deploy. Local is also where migration *development* belongs.

**Built and verified:**

- The D7 AV dump is loaded locally as `d7_av`, matching the source exactly (7,396 video ·
  4,187 audio · 125,528 field_collection items · 11,672 memberships).
- **`audio`/`video` node bundles** — 2 types, 29 new field storages, 63 instances. Per AV2
  the 30 shared fields come from **one definition** applied to both; only
  `field_audio`/`field_thumbnail_image` and `field_video` are bundle-specific.
  `field_og_collection_ref` is deliberately not a node field — membership migrates as Group
  relationships, as Images did. ADR 017 identity fields included, both bundles carrying
  `field_legacy_site = audio-video`.
- `kaltura_media` enabled (field type `kaltura`).
- **`D7AvFieldCollection`** — one parameterised source plugin serving all 17 paragraph
  migrations. Images' plugins do not apply: its satellites were separate nodes referenced by
  inline entity form, while a field_collection item is embedded in its host.
- **All 17 migrations registered, every total matching an independent SQL prediction to the
  row** — 125,101 across the group. A 20-row live import produced correct paragraphs and
  rolled back cleanly, twice.

**Still needed before anything can run:** the `d7_av_audio`/`d7_av_video` node migrations,
AV collections → Groups, collection membership from `og_membership`, and `url_alias`.
Paragraphs are the bulk of the runtime but importing them before the nodes exist would
leave 125,101 orphans, so the node migration must land first.

### The two silent failures this caught

Both would have produced plausible-looking wrong data, and neither would have announced
itself.

**1. Language fan-out — 22,735 duplicate paragraphs.** D7 stores the host field per
language, so the same field_collection item is frequently linked twice, once under `und`
and once under `en`. On `field_pbcore_title`: 20,799 link rows for 18,647 real items, 2,083
of them at *different deltas*. Across all 17 collections that is **147,836 raw join rows
against 125,101 real items**. A naive join attaches each duplicate's paragraph to the same
node twice.

**2. Ordering — ambiguity cut 41×, on Yuji's domain knowledge.** My first rule was
`MIN(delta)`, which deduplicates correctly but orders badly. Yuji supplied the history:
`und` is D7's `LANGUAGE_NONE`, and it was **written here by an earlier conversion to
language-specific storage** which declined to assume the pre-existing language-agnostic
data was English, labelling it "unknown" instead. Later edits wrote `en` rows on top, so
`en` is the authoritative layer.

Confirmed before changing anything: of the 1,732 hosts carrying both languages, **1,694
(97.8%) have an `en` list that fully covers their `und` list**. But `und` cannot simply be
dropped — 6,958 items exist only there, plus `und`-only items on hosts that *do* have `en`
(44 on titles, but **790 on descriptions, 746 on publishers, 533 on contributors**). So the
rule is a union with `en`-preferred ordering, not a language filter:

| Rule | Items preserved | Hosts with ambiguous order |
|---|---:|---:|
| `MIN(delta)` | 18,647 | **1,739** |
| `en`-preferred union | 18,647 | **42** |

That conversion's caution still holds up: only ~42% of `und`-layer title items carry
English as their *content* language (2,937 of 6,950; the rest Tibetan 1,766, Chinese 1,144,
Dzongkha 86, and 1,017 unrecorded). Defaulting them to `eng` would have mislabelled roughly
3,900 items.

> ⚠ **Two different "languages" live in this data and only one is the artifact.** The
> storage-layer `und` is the D7 mistake, and it dies at the migration boundary — D11
> entities take the site default, so everything migrates as `en` (verified against the
> already-migrated Images corpus: 166,386 paragraphs and 111,340 nodes, all `en`). The
> `field_language` sub-field inside PBCore titles and descriptions is something else
> entirely: it records what language the **text** is in — English 8,315 · Dzongkha 2,121 ·
> Tibetan 2,108 · Chinese 1,541 · Russian 23. That is real multilingual content and **must
> not be collapsed to English** by a later well-meaning cleanup.

### Open decision carried forward

The 42 remaining ordering ties still need a tiebreak. `(delta, item_id)` is deterministic
but arbitrary; a cataloguing convention would be better input than anything derived from
the data. Belongs with the node migration.

## Corrections made this session

Recorded because all three were the same shape — asserting something without checking when
the check was cheap, or presenting inference as settled:

1. Reported the 18 media-less AV nodes as "not in any doc yet." They **were** in the
   audit's data profile as a required-field gap. What was new was only their character and
   the absence of an owner.
2. Justified the catalog-note body rename on structure alone and documented it as a
   footnote, while the same doc claimed there were only "two forced exceptions." The
   conclusion held up, but the reasoning was inference presented as settled.
3. Committed `MIN(delta)` describing it as making the ordering choice "deterministic."
   Deterministic it was, but not *good* — it left 1,739 hosts with an ambiguous paragraph
   order, and I had not measured that before claiming it. The measurement only happened
   because Yuji asked what delta meant. His account of the `und` layer then produced a rule
   with 42 ambiguous hosts instead. **Domain knowledge beat data analysis here**, and the
   analysis had looked complete from the inside.

## Follow-ups

- **AV4** — finish it: node migrations, collections → Groups, membership, `url_alias`.
  PR #193 is open as a draft and holds the first half. **Do not run the paragraph
  migrations until the node migration exists** — 125,101 orphans otherwise.
- **The 42 ordering ties** — need a tiebreak rule. Ask AV staff about cataloguing
  convention before inventing one; `(delta, item_id)` is the fallback.
- **`field_relation_identifier`** — carried from AV3: narrow it to the `audio`/`video`
  bundles now that they exist, and handle the intra-AV reference with a second pass.
- **`config-export-drift-hand-edited-yaml`** — now has concrete evidence; belongs on the
  next group agenda.
- **The 30.1% undescribed figure** — belongs in the audit's data profile, not buried here.
- **AV staff** need the AV14 cleanup list (18 nids) and a decision on `field_tags`
  "Tags Old".
- **dev-0 AV source** — loading `mandala_d7_av` onto rds-mysql8-staging and adding
  `MIGRATE_AV_DATABASE` to the container env, once the migrations are proven locally.
