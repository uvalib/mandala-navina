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
