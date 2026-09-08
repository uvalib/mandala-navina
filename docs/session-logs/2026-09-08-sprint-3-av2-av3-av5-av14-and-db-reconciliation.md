# Session Log: Sprint 3 opened — AV2, AV3, AV5, AV14 decided and built; local DB reconciled with dev-0

**Date:** 2026-09-08
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** Sprint 3 (AV core) moved from ○ Planned to ◐ In progress. **Four backlog
items closed** — AV2, AV3, AV5 and a new AV14 — across three PRs
([#189](https://github.com/uvalib/mandala-navina/pull/189) merged,
[#190](https://github.com/uvalib/mandala-navina/pull/190),
[#191](https://github.com/uvalib/mandala-navina/pull/191)). The local development
database was reconciled with dev-0, which exposed four real defects in
`scripts/update-db-from-remote.sh`. **AV4 preparation is underway.**

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

## 9. AV4 preparation — in progress at session end

**Decision: the first AV migration run is LOCAL.** dev-0 cannot run it without work only
Yuji can do — its `MIGRATE_SOURCE_DATABASE=mandala_d7_images` points at the Images source,
**no D7 AV database exists on the staging RDS**, and creating one needs DB credentials
(blocked by this session's auto-mode classifier) plus a `terraform-infrastructure` env
change and a deploy. Local is also where migration *development* belongs.

Done so far:

- `settings.php` wired with a **separate `migrate_av` connection key** (rather than
  repointing `migrate`) so the Images and AV D7 sources coexist and every migration names
  its source explicitly. The env-driven block gained `MIGRATE_AV_DATABASE`, so wiring dev-0
  later is a pure env change, no settings edit.
- `scripts/load-d7-source.sh` parameterised to take a target DB name (defaults to
  `d7_images`, so existing usage is unchanged).
- The D7 AV dump is loading locally as `d7_av`.

Still to do for AV4: create the `audio`/`video` bundles with their fields from one shared
definition, attach the 13 paragraph reference fields, enable `kaltura_media` (installed but
not enabled; its field type is `kaltura`), and write the migration YAMLs.

## Corrections made this session

Recorded because both were the same shape — asserting something without checking when the
check was cheap:

1. Reported the 18 media-less AV nodes as "not in any doc yet." They **were** in the
   audit's data profile as a required-field gap. What was new was only their character and
   the absence of an owner.
2. Justified the catalog-note body rename on structure alone and documented it as a
   footnote, while the same doc claimed there were only "two forced exceptions." The
   conclusion held up, but the reasoning was inference presented as settled.

## Follow-ups

- **AV4** — the migration. Everything above lands here.
- **`config-export-drift-hand-edited-yaml`** — now has concrete evidence; belongs on the
  next group agenda.
- **The 30.1% undescribed figure** — belongs in the audit's data profile, not buried here.
- **AV staff** need the AV14 cleanup list (18 nids) and a decision on `field_tags`
  "Tags Old".
- **dev-0 AV source** — loading `mandala_d7_av` onto rds-mysql8-staging and adding
  `MIGRATE_AV_DATABASE` to the container env, once the migrations are proven locally.
