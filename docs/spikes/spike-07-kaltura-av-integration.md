# Spike 7: Kaltura AV Integration on Drupal 11
**Status:** ◐ Partial — started 2026-09-04 (Yuji)
**Date:** 2026-09-04 (module survey + live D11 prototype)
**Branch/commit:** untracked local experiment only, not committed (see note at end of
this section)

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
