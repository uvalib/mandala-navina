# Spike 7: Kaltura AV Integration on Drupal 11
**Status:** Pending  
**Date:** —  
**Branch/commit:** —

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
