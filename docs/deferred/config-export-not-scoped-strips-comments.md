# `drush config:export` re-serializes the entire active config, not just what you changed — and silently strips comments

**Area:** DX / config sync
**Raised during:** Session 2026-09-14, building AV6's KMaps display config
**Jira:** (add when available)
**Priority:** Medium — recurring, cheap to work around once you know to look, expensive if you don't

## What happened

Built AV6's two new `core.entity_form_display`/`core.entity_view_display` config entities for `audio` and `video` via a small PHP script (compute Drupal's own default, override the 6 KMaps fields, save) run in local DDEV, then ran `drush config:export -y` to write the result to `config/sync`.

The export touched **24 files**, not 2. Only 4 were the genuinely new display files — the other 20 were pre-existing files re-serialized with no intended change, because `config:export` dumps the *entire* active configuration to the sync directory, not just what changed. Among the collateral:

- `mandala_kmassets_sync.settings.yml` lost its entire AV8 rationale comment block (added 2026-09-14, same day) — **this is the third documented recurrence** of `config:export` stripping hand-written YAML comments (see the same symptom noted independently in 2026-09-08's session log, three files, never previously formalized into its own deferred note).
- Several `group.relationship_type.*.yml` and `migrate_plus.migration.*.yml` files picked up purely cosmetic re-serialization noise (a trailing blank line, key reordering) from local DDEV's active-config state diverging slightly from what's committed — itself a mild instance of the exact problem [[config-export-drift-hand-edited-yaml.md]] already tracks in the other direction.

No damage occurred here — caught by `git status`/`git diff` before committing, and the 20 unintended files were reverted with `git checkout --`, leaving only the 4 real ones. But it required noticing the count (24 changed files for an operation that should have touched 2) and diffing each one by hand.

## Why

`config:export` is a full-tree operation by design — Drupal's `ConfigExporter` walks every active config object and writes it out. It has no "export just this one config name" mode via `drush config:export` (there is `drush config:get`/manual per-object serialization, but nothing wired into the standard `config:export` workflow). Comment-stripping happens because YAML comments aren't part of a config object's data model at all — Drupal round-trips through PHP arrays, so any comment in the committed file that isn't backed by an actual config key is simply gone the moment anything re-exports that file, even if the specific value didn't change.

## Recommendation

1. **Never trust a bare `config:export` diff.** Always run `git status`/`git diff` on `config/sync` afterward and treat every file beyond the ones you intended to change as suspect — revert with `git checkout --` unless you can explain the diff.
2. **Comments in `config/sync` YAML are inherently fragile** — anyone's *next* unrelated `config:export` (even for a totally different config object) can silently delete them, not just a direct edit of that file. If a rationale genuinely needs to survive, consider putting it in the referencing planning doc / deferred note / session log instead of a YAML comment, or accept it needs periodic re-adding after config exports and treat that as a known cost.
3. **This strengthens, not replaces, [[config-export-drift-hand-edited-yaml.md]]'s open question** — that note is about the risk of *not* using `config:export` (hand-editing drifts from what Drupal computes); this note is about the risk that *does* come from using it correctly (it isn't scoped, and it eats comments). Both point toward option 2 in that note (a CI check running `cim`+`config:status`) being more valuable than it looked in isolation, since it would also catch "the export picked up unrelated drift" as a side effect, not just "someone hand-edited YAML."

## Related

- [[config-export-drift-hand-edited-yaml.md]] — the mirror-image risk (not exporting, hand-editing instead)
- Session log: `docs/session-logs/2026-09-14-*` (AV6/AV10 close-out)

## Direction and inventory, 2026-10-05 (group session)

**Direction (Yuji):** treat comments in `config/sync` as unsupported rather than merely fragile
-- do not add them, and record rationale where an export cannot touch it: the entity's own
text fields (`description`, `label`, help text) where they exist; a rationale doc keyed by
config name (for example under `docs/dev-notes/`), or the relevant ADR, spike or deferred
note; and the commit message for "why this changed". A comment appearing in a config diff is
rejected or relocated in review. Backing (not built): a comment lint in the proposed
`scripts/config-check.sh` (see
[config-export-drift-hand-edited-yaml.md](config-export-drift-hand-edited-yaml.md)).
**The lint must not be a line grep** (see the false positives below); the reliable test is a real export diff -- see "How to identify comments an export would strip" below.

**Inventory of `drupal/config/sync` at `main` (a line starting with optional whitespace and
`#`), validated by parsing each file with and without those lines:**

- **20 YAML files / 630 lines match; only 16 files / 296 lines are real comments.** (A recursive
  grep over the whole directory finds 21 files / 636 lines: the extra file is `.htaccess`, the
  Apache deny-all file Drupal places in a config directory. It is not YAML or config data, an
  export does not touch it, and the lint must skip it.)
- **4 files / 334 lines are false positives:** `search_api_solr.solr_field_type.text_en_6_0_0`,
  `text_en_7_0_0`, `text_und_6_0_0` and `text_und_7_0_0`. Their `#` lines sit inside string
  values (Solr character-mapping data), so removing them changes the parsed config. A plain
  grep lint would flag these wrongly, and stripping them would break the field types.
- **The 16 real-comment files are all comments we added** (first committed 2026-06-29 to
  2026-09-15), none shipped by a module:

  | Lines | File |
  |---|---|
  | 48 / 46 | `migrate_plus.migration.d7_av_audio` / `d7_av_video` |
  | 30 | `mandala_kaltura.settings` |
  | 25 | `migrate_plus.migration.d7_av_files` |
  | 21 | `mandala_kmassets_sync.settings` |
  | 18 / 17 | `migrate_plus.migration.d7_av_collections` / `d7_av_node_collection_membership` |
  | 17 / 14 | `migrate_plus.migration.d7_images_collections` / `d7_images_subcollections` |
  | 15 | `migrate_plus.migration.d7_av_user_memberships` |
  | 12 / 9 | `migrate_plus.migration.d7_av_workflow` / `d7_av_tags` |
  | 8 / 6 / 6 / 4 | `d7_av_url_alias` / `d7_av_collection_url_alias` / `d7_av_subcollections` / `d7_av_pbcore_instantiation` |

- Of the 16, 14 are migration definitions (data-audit counts and rationale), and two are
  settings files. **`mandala_kmassets_sync.settings` already lost its comment block once to an
  export** (2026-09-14, above) and it was re-added.

### How to identify comments an export would strip (verified 2026-10-05)

Diff `config/sync` against what Drupal would actually write: `drush config:export
--destination=<tmp> -y`, then `diff -r config/sync <tmp>`; the comments an export would
strip are the removed lines matching `^\s*#`. No heuristic is involved, because the export is
the thing that strips them. Run in DDEV against `main`, this found **exactly the 296 lines in
the 16 files above**, left the four Solr field-type files untouched (so their `#` lines are
data), and showed `.htaccess` only as "Only in `config/sync`" (an export never writes it).
A comment-tracking parser (ruamel.yaml) agreed on the 16 files and found no trailing
`value # note` comments, but over-counted lines (331), so use it for *which* files, not how
many lines.

**Caveats:** it needs a running DDEV whose database matches `config/sync` (check
`config:status` first), otherwise unrelated drift appears in the same diff. That drift is real
today: the same diff also showed 29 removed non-comment lines (mostly in the same migration
files, plus a few `group.relationship_type.*` files and one view). They were not examined;
they look like the cosmetic re-serialization described above. This is now built as
`scripts/config-check.sh` (2026-10-05): one export diff gives both the comment check and the
formatting-drift check. On `main` today it reports 296 comment lines in 16 files and 8 files
the export would reformat, as warnings; `--strict` fails on them, to be used after the
rationale has been relocated.

**Not done:** the rationale in those 296 lines has not been moved anywhere. It should be
relocated once, deliberately, before any export strips it -- decide the destination first
(a rationale doc per area is the likely fit for the migration files; the settings files may
fit their module's docs). Ask Yuji before doing the move.

## Fourth recurrence, 2026-09-15 (AV9 gallery-thumbnail session)

Same shape again: building `node.audio.teaser`/`node.video.teaser` view displays via a
small PHP script, then `drush config:export -y` touched ~20 unrelated files (migration
definitions, `mandala_kaltura.settings`, `group.relationship_type.*`), stripping their
comments. Caught and reverted the same way as before (`git status`/`git diff`, keep only
the intended new files). Switched to a **narrower workaround** worth adding to the
recommendation above: rather than a full `config:export -y` followed by a revert-and-diff
pass, write back only the single changed config object via
`\Drupal::service('config.storage.sync')->write($name, \Drupal::service('config.storage')->read($name))`
in a `drush php:eval` — this never touches any file but the one that actually changed, so
there's no revert step needed at all. Doesn't replace the CI-check recommendation (option
2 above) but is a cheaper per-session mitigation than "export everything, then clean up."

## Fifth recurrence, 2026-10-02 (orphaned-content review group build)

Added a new `field_is_review_holding` boolean field to the `collection`/`subcollection`
group bundles. A plain `config:export -y` touched 25 files — only 3 were the new field
definitions; the rest were comment-stripped `mandala_kaltura.settings.yml`,
`mandala_kmassets_sync.settings.yml`, several `migrate_plus.migration.d7_av_*.yml` and
`group.relationship_type.*.yml` files, and `views.view.collection_gallery.yml`, none of
which the field addition should have touched at all. Reverted all 22 with
`git checkout --`, then used the narrower per-object write-back (above) for the 6 display
configs that genuinely did need the new field added to their `hidden`/`dependencies`
lists (`core.entity_{form,view}_display.group.{collection,subcollection}.{default,teaser}`),
rather than a second blind `config:export -y`. Confirms the pattern is reliably
per-session, not a one-off — worth treating "run `config:export -y`, then diff and revert"
as the default expectation for any future Group-entity field addition, not an edge case.

## Update 2026-10-06: the 296 comment lines have been relocated

All 16 files listed in the inventory above are now comment-free. Destination decided by Yuji:
**one rationale doc keyed by config name**,
[docs/dev-notes/migration-config-rationale.md](../dev-notes/migration-config-rationale.md), with
sibling files merged and history-only remarks (for example the `uid: uid` correction, which
survives as a short "check with `migrate:fields-source`" note) condensed.

Comments that were really about data problems were checked against `docs/deferred/`; the two
without a deferred note got one:
[av-available-from-end-date-not-migrated.md](av-available-from-end-date-not-migrated.md) and
[av-collection-memberships-dropped-for-deleted-d7-user.md](av-collection-memberships-dropped-for-deleted-d7-user.md).

The files were rewritten by exporting from a live Drupal into a temp directory and copying the 16
files across (no hand-editing). The only non-comment differences were serialization: quoting of
`kaltura_domain` and `server_url`, and the list-item layout of `process` plugin entries. `config:status`
shows no difference between the DB and the sync directory (other than the local-only
`stage_file_proxy`). The four Solr field-type files were left alone; their `#` lines are string data.

Follow-up, same day: `scripts/config-check.sh` is now strict by default (`--lenient` downgrades), it
discounts the local-only `stage_file_proxy` (shared helper with `session-start-check.sh`), and
`session-close-check.sh` runs it when `config/sync` changed. Five more files an export would only
reformat (four Solr field types, `views.view.collection_gallery`) were brought to canonical form so
strict passes on `main`. See [config-export-drift-hand-edited-yaml.md](config-export-drift-hand-edited-yaml.md).
