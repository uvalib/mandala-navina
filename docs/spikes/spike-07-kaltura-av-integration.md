# Spike 7: Kaltura AV Integration on Drupal 11
**Status:** ◐ Partial — started 2026-09-04 (Yuji)
**Date:** 2026-09-04 (module survey + live D11 prototype)
**Branch/commit:** `f383ff6` (pushed directly to `main`, 2026-09-04) — the packaging
solution below (`drupal/composer.json` + `drupal/patches/`), landed inert (nothing
references `kaltura_media` in `config/sync` yet) so Sprint 3 starts from a working
base.

## ⚠ CORRECTION 2026-09-04 (same session): there IS a supported in-Drupal upload path

**The section below claims "there is no Drupal-side file-upload-to-Kaltura path for
primary AV media." That claim is WRONG.** Yuji challenged it; re-checking found a
second, fully supported ingest path that the first pass missed. The import/pull
findings below remain accurate — they are just not the *whole* picture.

**What was actually missed — two ingest paths exist, not one:**

- **Path A — bulk admin import (pull).** `admin/content/mediabase/import` → batch
  `create_node_mediabase()`. Described correctly below.
- **Path B — per-node upload (push), from the video/audio node form itself.** The
  `field_kaltura_entryid` field's `all_media` widget
  (`contrib/kaltura/plugins/field_kaltura/field_kaltura.module`,
  `kaltura_widget_hendler()`) renders a modal action button that is **either**
  "add existing" (`kaltura/nojs/existing/…`) **or an uploader** — and when the
  `kaltura_chunked_uploader` plugin is enabled, that uploader is
  `kaltura/nojs/chunked-upload/<widget_type>` (falling back to `kaltura/nojs/kcw`,
  the classic Kaltura Contribution Wizard, when it isn't). The contrib module ships
  a complete chunked-upload implementation for this (`kaltura_chunked_uploader`
  plugin: `jquery.fileupload-kaltura.js`, `webtoolkit.md5.js`, its own
  `kaltura/%ctools_js/chunked-upload/%` and
  `kaltura-chunked-uploader/ajax/add-media/%` routes).

**Mandala actively supports Path B — this is not dormant contrib code:**
- `mb_kaltura_menu()` defines `mb_kaltura/upload-keepalive`, described in its own
  title as *"A Keep-alive callback to be called by js during long uploads."*
- `mb_kaltura_form_alter()` attaches `js/keepalive.js` to **both**
  `video_node_form` and `audio_node_form`; that JS pings the keepalive route every
  60 seconds so the Drupal session survives a long upload.
- The same `form_alter` manages the widget's **"add media" button visibility** —
  hiding it when an `entryid` is already set, with JS to un-hide it if the media is
  removed.

Mandala wrote a custom route and JS whose only purpose is keeping sessions alive
during long uploads *from the node form*. That is not something you build unless
editors upload files there.

**Why the first pass got it wrong (worth recording):** I grepped for *server-side
PHP* Kaltura upload API calls (`uploadToken`, `media->addContent`, `media->upload`)
inside the *custom* `mediabase` modules, found none, and treated that negative
result as proof of absence. But the upload is **client-side — browser directly to
Kaltura** — and the widget lives in the **contrib** module. No server-side call was
ever going to appear where I looked. The signals were in things I had already
listed and not opened (`mb_upload_keepalive()`, `mb_kaltura_form_alter()`, and the
`all_media` widget's name).

### Settled from real production data (2026-09-01 dump, same session)

Both remaining unknowns are now **confirmed against the real production AV database**
(`mandala-prod-av-db_2026-09-01.sql.gz` — Yuji had downloaded the known-good
re-export to the repo root; `gzip -t` passes, unlike the three corrupt dumps noted
below; gitignored via `*.sql.gz`, never committable). Read by streaming the dump, no
DB load needed:

1. **`kaltura_chunked_uploader` IS enabled in production** — `system` table row
   `'…/kaltura_chunked_uploader.module','kaltura_chunked_uploader','module','',1,…`
   (status `1`). Also enabled alongside it: `kaltura`, `field_kaltura`,
   `kaltura_views`, `mb_kaltura`, `mb_metadata`, `audio_video`, `media_listings`.
2. **`add_existing = 0` on BOTH `field_video` and `field_audio` instances.** In
   `kaltura_widget_hendler()` the "add existing" branch requires `'both'` or
   `'existing'`; `0` is neither, so both fields take the **else** (uploader) branch,
   and since the chunked uploader is enabled the button resolves to
   `kaltura/nojs/chunked-upload/field_kaltura_video` /
   `…/field_kaltura_audio`.

**Conclusion, stronger than the correction above:** on the production AV node form
the media button is **exclusively an upload control**. There is no "browse existing
Kaltura entries" option there at all — that capability exists only on the separate
admin batch-import page (Path A). The two ingest paths are cleanly divided:
**Path A attaches entries that already exist in Kaltura (creating new nodes); Path B
uploads new files to Kaltura from the node form.** The editor-facing help text on the
audio field says exactly this: *"Click on button to add a media item or click on X to
remove the media item."*

**Two incidental corrections to the [AV content-model audit](../planning/av-content-model-audit.md):**
- The audit says the field's widget is `all_media`. That is the field *type's declared
  default* (`'default_widget' => 'all_media'` in `field_kaltura_field_info()`), **not
  what the instances actually use** — production has `field_kaltura_video` and
  `field_kaltura_audio` (the type-specific "Video only"/"Audio only" widgets). Doesn't
  change the upload conclusion (all widget types route through the same
  `kaltura_widget_hendler()`), but it's the wrong value to build a migration against.
- **There is no single Kaltura player `uiconf_id` to carry over.** At least three are
  in play: the video field's display settings use **31832371** (the same one the React
  app's `audiovideo.js` hardcodes), a second display formatter on both fields uses
  **48501** (the contrib module's own default), and the live D7 node-view embed Spike 6
  captured used **24762821**. The partner ID is consistently `381832`, but "D11 can
  reuse the existing player config" — as the earlier prototype section puts it — needs
  narrowing to *which* player, per view mode, in Sprint 3.

**Dump hygiene note:** the copy on this machine at `~/mandala-prod-av-db_2023-12-05.sql.gz`
(219MB) is **corrupt** — `gzip -t` fails with "unexpected end of file" and streaming
dies at the `cache*` tables, before `system`/`field_config_instance`. That is the
**third** corrupt AV dump after the two the content-model audit recorded on
2026-06-11. Always `gzip -t` an AV dump before drawing a negative conclusion from it.

**Revised D11 implication (this supersedes the softer version below):**
`kaltura_media`'s "type in an Entry ID" form is **not** near-parity. D7 editors can
upload a media file directly from the node form; `kaltura_media` has no upload
capability at all. So post-cutover authoring needs a real decision, not a nicety:
either accept a workflow change (editors upload via Kaltura's own KMC, then paste
the entry ID into D11) — which should be checked with the AV content staff who
actually do this work, not assumed — or build an upload integration for D11. Still
**not a migration blocker** (migration reads each node's existing entry ID), but it
is a genuine functional gap for ongoing content creation and belongs in Sprint 3's
scope discussion.

## Progress 2026-09-04 (continued, same day): the real D7 upload/ingest workflow

> **⚠ Superseded in part by the correction above** — the "no upload path exists"
> conclusion in the first bullet is wrong. Everything about the import/pull path,
> `MISSING_TYPE`, and the dead PBCore auto-population code remains accurate.

Read the actual `mb_kaltura` module in full (`mb_kaltura.module` + `mb_kaltura.inc`,
~2,150 lines together) rather than assuming from the original spike's generic
"file picked in Drupal → posted to Kaltura API" framing. **That framing is wrong for
this site** — real findings:

- **There is no Drupal-side file-upload-to-Kaltura path for primary AV media at
  all.** Grepped every upload-related Kaltura API call (`uploadToken`,
  `media->addContent`, `media->upload`, `->add(`) across the whole `mediabase`
  module family — the only hits are `captionAsset->add()` (pushing a *transcript
  caption* to Kaltura, a live cross-reference for Spike 11 not chased further here)
  and `metadata->add()` (dead code — see below). **The base contrib `kaltura` module
  does have its own uploader form** (`kaltura_uploader_form`/`_submit`/
  `_validate_file` in `kaltura.module`), but nothing in `mb_kaltura` references,
  wires, or menu-alters it the way it does the import path — whether AV staff
  actually use that stock form out-of-band, or media is uploaded entirely outside
  Drupal (Kaltura's own KMC), is **unconfirmed, flagged as open below**, not assumed.
- **The real workflow is the reverse: import (pull), not upload (push).**
  `mb_kaltura_menu_alter()` explicitly overrides the base module's own "Import
  Kaltura Items" page because "their import mechanism is broken." The real path:
  `mb_kaltura_import_form()` → `get_importable_entries()` calls the real Kaltura API
  (`$kaltura_client->media->listAction()` with `KalturaMediaEntryFilter`/
  `KalturaFilterPager`), lists entries in the Kaltura account, and filters out any
  already linked to a local node (cross-referencing every `field_kaltura_entryid`
  field's stored values via `get_local_entries()`). An editor checks entries from
  that table, types a title per entry, picks a collection + author, and submits — a
  **batch operation whose only real step is `create_node_mediabase($entry_id,
  $title, $collection_nid, $author_uid)`** per checked entry.
- **🔴 Root cause found for the AV audit's open question #8 (68 `MISSING_TYPE`
  nodes).** `create_node_mediabase()` maps `$entry->mediaType` to a Drupal bundle:
  `KalturaMediaType::VIDEO` → `video`, `::AUDIO` → `audio`, and **the fallback for
  anything else is the literal string `$type = 'MISSING_TYPE'`, saved onto the node
  anyway** (no validation, no abort). This is not corruption from an unknown source —
  it's this exact function creating nodes with a genuinely invalid bundle value
  whenever a Kaltura entry's `mediaType` doesn't cleanly map to VIDEO/AUDIO (most
  likely Kaltura `IMAGE` entries, or entries with an unset/unexpected media type,
  imported through this same admin page). **Resolves the audit's open question**;
  update propagated to the audit doc itself, see below.
- **PBCore auto-population from Kaltura is dead code, never wired in — confirmed,
  not assumed.** `create_node_mediabase()`'s own docblock carries `@todo Add pbcore
  title one time` / `@todo Add pbcore instantiation one time`. The block that would
  call `_apply_remote_pbcore()` is **commented out** in the function body,
  and grepping the entire module for call sites of `_apply_remote_pbcore`,
  `autopopulate_pbcore_identifier`, `autopopulate_pbcore_title`,
  `_populate_pbcore_instantiation`, and `_save_kaltura_metadata` finds **zero
  invocations anywhere** — all five functions are defined but never called. **A
  freshly-imported D7 node has only**: title (editor-typed at import), collection
  membership, author, the entry-id field, Kaltura's tag string, and a partner-data
  identifier — the rich PBCore descriptive/technical/workflow metadata the content
  audit inventoried is filled in **by hand, as a separate cataloging step after
  import**, not synced automatically. This matters for Sprint 3: it means D11's
  migration only needs to preserve whatever a cataloger actually typed into D7's
  fields (already covered by the planned `field_collection` migration) — there is no
  "Kaltura metadata sync" behavior to replicate, because it never worked in D7
  either.
- **One real Drupal→Kaltura write exists, but only for audio thumbnails.**
  `mb_kaltura_node_presave()` → `_mb_kaltura_add_audio_thumb()`: if an audio node's
  `field_thumbnail_image` changes, the file is pushed to Kaltura via
  `$kclient->baseEntry->updateThumbnailFromUrl()` (falling back to a generic
  placeholder image if none is set). This is the one place primary content
  genuinely flows *from* Drupal *to* Kaltura in the whole module.
- **D11's `kaltura_media` module already matches the real workflow shape**, not the
  wrong upload-focused one: its `KalturaForm.php` (`media_library_add` form) is a
  plain "enter an already-known Entry ID / partner ID / uiconf ID" form — exactly
  what's needed once media exists in Kaltura, which is always true for D7's real
  workflow. **The one real gap versus D7** is the "browse a paginated list of
  not-yet-imported Kaltura account entries" convenience UI
  (`mb_kaltura_import_form`'s table) — `kaltura_media` requires knowing the entry ID
  already. Not a migration blocker (D11's migration reads the entry ID straight off
  each D7 node, per the content audit); would matter for **ongoing post-cutover
  content creation**, and could be built later as a thin controller wrapping
  `media->listAction()` + the same "filter out already-linked entries" logic, if the
  team wants full parity.

**Still genuinely open, not resolved here:**
- ~~Whether AV staff actually use the base `kaltura` module's stock uploader form~~
  **RESOLVED — see "Settled from real production data" above.** The node form's media
  button is exclusively a chunked upload to Kaltura (`add_existing = 0` on both
  fields, `kaltura_chunked_uploader` enabled). The only thing still unverified is
  *behavioural* rather than technical: how often staff actually use it versus
  uploading in Kaltura's KMC and importing via the admin page — a question for the AV
  content staff, not the codebase.
- The `captionAsset->add()` cross-reference to Spike 11 (Kaltura-native caption
  storage, separate from the `transcripts_apachesolr`/XSLT pipeline already found) —
  flagged for Spike 11, not chased here.
- The Kaltura API's authentication model / current partner admin-secret validity
  (Work item 2 of this spike, unchanged).

## Progress 2026-09-04: module survey + live D11 prototype (real work, not just reading)

**A maintained D11-installable contrib module exists: `drupal/kaltura_media`
(1.0.4, 2023-09-22, Evolving Web, 151 sites reporting use).** This directly answers
most of work item 1 (module landscape survey) and item 3 (playback path). The
original `kaltura` module the D7 site actually runs (7.x-3.2) has **no D9/10/11
release at all and is marked Unsupported** — confirmed dead end, not usable as a
starting point.

**Verified live, not just read:**
- Downloaded the real 1.0.4 release tarball, patched its `core_version_requirement`
  from `^8 || ^9 || ^10` to add `^11` (a one-line change — the only thing standing
  between it and formal D11 support), and installed it directly into a running
  Drupal 11.4.5 DDEV instance (`drush pm:enable kaltura_media` — succeeded cleanly,
  no fatal errors, no missing-dependency failures).
- Ran `upgrade_status:analyze` against it for deeper deprecated-API confidence beyond
  the info.yml declaration. **Found 3 real, all non-blocking, issues**: (1) a
  deprecated `FileSystemInterface::EXISTS_REPLACE` constant (removed in Drupal 12,
  not 11 — fine for now, worth fixing anyway since we'd be touching the file), (2) a
  genuine **pre-existing latent bug in the module itself** — `Kaltura.php` catches
  `FileException` in its thumbnail-download error path but never imports that class
  (`use` statement missing), so that catch branch would fatal with a class-not-found
  error if it ever actually triggered (dormant until a thumbnail HTTP fetch fails),
  (3) a logger-injection code-quality warning (DependencySerializationTrait). None of
  these block D11 use; all are small, well-understood fixes.
- **Rendered the module's actual embed output using the real Kaltura IDs Spike 6
  already found live in production** (`partnerId=381832`, `uiconf_id=24762821`,
  `entry_id=1_lbuv4kg1`, node 42016) via `drush php:eval` + the renderer service.
  Output:
  ```html
  <div class="kaltura-player" id="kaltura-player-1_lbuv4kg1"></div>
  <script src="//cdnapisec.kaltura.com/p/381832/sp/38183200/embedIframeJs/uiconf_id/24762821/partner_id/381832"></script>
  <script>kWidget.embed({
    'targetId': 'kaltura-player-1_lbuv4kg1',
    'wid': '_381832',
    'uiconf_id': '24762821',
    'entry_id': '1_lbuv4kg1'
  })</script>
  ```
  This is **structurally identical** to the live D7 embed Spike 6 captured
  (`mwEmbedFrame.php` with the same `wid`/`uiconf_id`/`entry_id` params) — same
  partner account, same player config, same `kWidget.embed()` call shape. This
  meaningfully de-risks the "Kaltura partner/credential re-provisioning" open
  question: D11 does not need a new Kaltura profile/player to be provisioned to
  prove playback, it can point at the exact same live partner/uiconf IDs the D7
  site already uses.
- **Field model fit**: `kaltura_media`'s field type stores `entry_id`/`partner_id`/
  `uiconf_id`/`domain` per field item (richer than D7's `field_kaltura_entryid`,
  which stores only a scalar entry-id string and reads partner/uiconf config from
  Drupal *variables*). A migration source plugin would populate `partner_id`/
  `uiconf_id`/`domain` from the same constant Spike 6 already found
  (`381832`/`24762821`), not per-row — straightforward.

**Cleanup note:** the module was uninstalled and its files removed from the working
tree after the test (`drupal/web/modules/contrib/` is gitignored — composer-managed
contrib is never committed directly — so nothing was ever at risk of being committed
accidentally). `composer require drupal/kaltura_media` **fails outright** as-is
(Composer resolves the *unpatched* published version's `^8 || ^9 || ^10` constraint
against root `drupal/core: ^11` and refuses) — a real installation would need either a
`cweagans/composer-patches` patch on `kaltura_media.info.yml`, or a fork/replace
entry, not a plain `composer require`. That packaging step is the concrete next
action, not a new unknown.

**What this does NOT yet establish** (still open, unchanged from the original spike
scope below): the upload/ingest path (this prototype only proved playback of an
*existing* entry ID); whether `METADATA_PROFILE_ID`/`MB_MAIN_PLAYER_ID` map onto
anything `kaltura_media` needs; the multi-site/partner credential model question; a
real migration source plugin.

## Progress 2026-09-04 (continued, same day): real `composer require` packaging solved

The manual hand-patch used for the prototype above (`sed`-editing a downloaded
tarball, copying it straight into `web/modules/contrib/`) is not how a real
dependency gets added — `composer require drupal/kaltura_media` fails outright as
published, because **the drupal/core version constraint that blocks it lives in
Composer's own package metadata, not in a file** — a `composer-patches` file patch
alone cannot fix that (patches apply after the dependency solver has already
succeeded or failed). Built and verified the real two-part fix:

1. **A local Composer `package`-type repository override** in `composer.json`,
   listed *before* `packages.drupal.org` so it wins (repository order determines
   which definition Composer uses for a given name+version — first match wins).
   It re-declares `drupal/kaltura_media` 1.0.4 with a corrected
   `require: {"drupal/core": "^8 || ^9 || ^10 || ^11"}`, sourced from the real
   `dist` tarball (`ftp.drupal.org`) — **not** `git.drupalcode.org` as a `source`,
   which was tried first and failed: the raw git tag lacks the "Information added
   by Drupal.org packaging script" footer lines the tarball has, so a patch built
   against the tarball doesn't apply against a git checkout. Dist-from-tarball
   fixed this.
2. **`cweagans/composer-patches` (^2.0)**, newly added, applying two real patches
   from a new `drupal/patches/` directory:
   - `kaltura_media-d11-core-version.patch` — the same one-line
     `core_version_requirement` addition as the earlier prototype, now applied
     automatically on every `composer install`/`update` instead of by hand.
   - `kaltura_media-file-exists-deprecation-and-missing-import.patch` — fixes the
     two real code issues `upgrade_status:analyze` found in the first prototype
     pass: the deprecated `FileSystemInterface::EXISTS_REPLACE` constant (→
     `FileExists::Replace`) and the missing `use Drupal\Core\File\Exception\FileException;`
     import that made one catch branch dormant-broken.

**Verified end-to-end, not just configured:** `ddev composer update drupal/kaltura_media`
resolves cleanly, downloads the tarball, and applies both patches
(`- Patching drupal/kaltura_media`, no errors). Confirmed the installed files
actually carry both fixes (grepped for `FileExists`/`FileException`/
`core_version_requirement` post-install). Re-enabled the module and re-rendered the
same real Kaltura embed test from the first prototype pass (`partnerId=381832`,
`uiconf_id=24762821`, `entry_id=1_lbuv4kg1`) — **identical correct output**, confirming
the patches didn't break anything. `upgrade_status:analyze` no longer flags the two
patched issues; it does newly flag several inherited-member "Check manually" items
(`t()`, `$configuration`, `$pluginDefinition`, `$configFactory`,
`getConfiguration()`) that did **not** appear in the pre-patch scan — these look like
static-analysis noise (the class instantiates and renders correctly at runtime, which
is stronger evidence than a static scan), but the cause of the discrepancy wasn't
root-caused before time ran out; worth a second look before treating `kaltura_media`
as fully clean.

**Local DDEV state restored** after the test (module uninstalled, config drift back to
the same pre-existing baseline as before this session — unrelated `views.view.collections`
etc. entries that predate this work). **Committed to `main` (`f383ff6`, 2026-09-04):**
`composer.json`, `composer.lock`, `drupal/patches/`, `drupal/patches.lock.json` — Yuji
decided to land it now rather than hold for Sprint 3, since it's inert as committed
(nothing in `config/sync` references `kaltura_media` yet) and means Sprint 3 starts
from a working base instead of re-deriving this. Anyone running `composer install`
after pulling `main` will now pull `kaltura_media` down automatically.

## Theory
The D7 Kaltura module's two responsibilities — uploading AV content to Kaltura and embedding the Kaltura player in node display — can both be satisfied on D11 using a combination of Drupal core Media, a D11-compatible Kaltura contrib module or custom Media Source plugin, and the Kaltura API v3, without loss of workflow or playback capability.

## Live evidence available before this spike starts (found 2026-08-21 during Spike 6)

Spike 6's AJAX/embed audit walked into the live D7 playback path incidentally. Recorded here so
this spike does not re-derive it — **none of it is a finding of this spike, and none of it is
verified beyond the single node checked.**

D7's `services/node/ajax/{nid}/player` (`mb_services_node_player()`, `mb_services.module`) is a
**redirect off the Mandala estate** to the Kaltura CDN. Observed live for AV node `42016`:

```
https://cdnapisec.kaltura.com/html5/html5lib/v2.27.1/mwEmbedFrame.php
  /p/381832 /uiconf_id/24762821 /entry_id/1_lbuv4kg1
  ?wid=_381832&iframeembed=true&playerId=js-kaltura-media-1_lbuv4kg1
  &entry_id=1_lbuv4kg1&flashvars[streamerType]=auto
```

What this gives the spike for free:

- **Partner id `381832`** and **`uiconf_id` `24762821`** — concrete values for the "confirm
  Kaltura partner/credential model for consolidated single-instance D11" step, and a real player
  UI configuration to compare any D11 embed against.
- **The `entry_id` shape** (`1_lbuv4kg1`) and confirmation that D7 stores a per-node entry id, so
  the "D7 AV nodes → D11 nodes referencing Kaltura Media entities, **no re-upload**" migration
  strategy has a real identifier to key on.
- **The embed mechanism actually in production is the `mwEmbedFrame.php` iFrame player**, on
  `html5lib` **v2.27.1** — relevant to the "no Kaltura oEmbed endpoint → use iFrame/JS embed"
  fallback row in this spike's risk table, which can be treated as the likely path rather than a
  contingency.

Caveats: one node, one observation, read from a redirect rather than from the D7 Kaltura module's
configuration. Player library v2.27.1 is old and its support status was **not** checked. Nothing
here addresses the **upload/ingest** half of this spike, which remains completely unexplored.

## Background

The D7 AV site uses the `kaltura` contributed module (7.x branch) to:

1. **Upload/ingest** — content editors upload video/audio files through Drupal, which posts them to Kaltura via the Kaltura API. Kaltura stores and transcodes the media, returning a Kaltura entry ID that is stored on the Drupal node.
2. **Playback** — node display embeds the Kaltura player (iFrame or JavaScript embed) using the stored entry ID.

Drupal 11 ships with the core Media module, which replaced D7's file-handling patterns. D11 Media uses pluggable **Media Source** plugins; oEmbed and custom sources are both supported. If a maintained D11-compatible Kaltura module exists, it should be evaluated first. If not, the fallback is a custom Media Source plugin wrapping the Kaltura API v3.

Key risks:
- Kaltura's official Drupal module may not have a D11 release.
- The upload workflow (posting files *to* Kaltura from Drupal) is more complex than simple oEmbed playback and may require a custom solution regardless.
- Existing D7 nodes store Kaltura entry IDs that must survive migration.

## Work

1. **Module landscape survey**
   - Check drupal.org for a D11-compatible Kaltura module (`kaltura`, `media_kaltura`, or similar); note release status and active maintenance.
   - Check the Kaltura Community for any official Drupal 11 integration guidance.
   - Identify whether any D10/D11 contrib module handles the *upload* workflow (not just playback).

2. **Kaltura API v3 capabilities**
   - Confirm Kaltura API v3 is the current version and review the upload (ingestion) and player embed endpoints.
   - Document the authentication model (KS session tokens vs. application tokens) used by the D7 module and whether it is still supported.

3. **Playback path (embedding)**
   - Attempt to embed a Kaltura player on a D11 node using Drupal core Media + oEmbed, if Kaltura publishes an oEmbed endpoint.
   - If no oEmbed endpoint: prototype a minimal custom `MediaSource` plugin that takes a Kaltura entry ID as input and renders the Kaltura iFrame embed in a field formatter.
   - Confirm the embed renders correctly and any required Kaltura JS is loaded without CSP conflicts.

4. **Upload/ingest path**
   - Prototype the content-editor upload workflow: file picked in Drupal → posted to Kaltura API → entry ID stored on the Media entity.
   - Determine whether this can be handled by a contrib module's widget or requires a custom `FieldWidget` / Media form extension.
   - Identify how the D7 module triggered upload (on node save, via a dedicated upload form, etc.) and replicate the same authoring UX if feasible.

5. **Migration path for existing content**
   - Query the D7 database: identify which field stores the Kaltura entry ID on AV nodes and how many nodes are affected.
   - Confirm that a D11 Media entity can store an existing entry ID without re-uploading (migration should reference, not re-ingest).
   - Document the Drupal Migrate plugin strategy for converting D7 AV nodes → D11 nodes referencing a Kaltura Media entity.

6. **Permissions and multi-site context**
   - In D7 the Kaltura partner credentials are per-site. In the consolidated D11 single instance, confirm whether one set of Kaltura credentials covers the full AV collection, or whether separate Kaltura partners/categories are needed per legacy site.

## Pass Criteria

- A clear D11 Kaltura integration path exists (contrib module or custom plugin) for both upload and playback.
- A working D11 prototype can embed a Kaltura player for a known entry ID.
- The upload workflow from D11 to Kaltura is demonstrated or a concrete implementation approach is documented.
- A migration strategy for existing D7 Kaltura entry IDs is defined (no re-upload required).
- The Kaltura partner/credential model is confirmed for the single-instance D11 context.

## Fail Criteria and Response

| Finding | Response |
|---|---|
| No maintained D11 Kaltura module exists | Build a minimal custom Media Source plugin (entry ID → iFrame embed) and a custom upload widget using Kaltura API v3 |
| Kaltura oEmbed endpoint is unavailable or unreliable | Use iFrame/JS embed directly; custom MediaSource plugin is required |
| Upload workflow cannot be replicated in D11 without significant custom work | Scope a dedicated upload module sprint; consider using Kaltura's own upload portal as an interim workflow |
| Migration requires re-uploading all media to Kaltura | Escalate to David Germano — re-ingestion may be out of scope and must be a stakeholder decision |
| Kaltura API v3 authentication model has changed | Obtain current application token credentials from AV site admin; update auth layer |
| D7 stores entry IDs inconsistently across AV nodes | Audit the full AV node corpus before writing the migration plugin; document edge cases |

## Outputs

- Inventory of D11-compatible Kaltura modules and their maintenance status.
- Documented Kaltura API v3 authentication and embed approach.
- Working D11 prototype: Kaltura player embedded via entry ID (even if minimal).
- Upload workflow prototype or detailed implementation plan if full prototype is out of scope for the spike.
- Migration strategy for existing Kaltura entry IDs.
- Credential/partner model recommendation for single-instance D11.
- Go/no-go recommendation for AV site Phase 4 work.

## Deferred notes

*(To be filled in after the spike runs.)*
