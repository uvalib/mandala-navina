# Session Log: Images Missing Interactive Viewing Surfaces

**Date:** 2026-08-19  
**Participants:** ThanGrove, Claude Code  
**Outcome:** Filed
[docs/deferred/images-missing-interactive-viewing-surfaces.md](../deferred/images-missing-interactive-viewing-surfaces.md)
(PR #122) — D7's Images site has an OpenSeadragon deep-zoom viewer, an AJAX
collection-scoped carousel, and a `shanti_grid_view` mosaic/gallery Views plugin, none of
which exist in D11 yet and none of which Sprint 1's IIIF acceptance criterion covers.
Flagged as the next item for the team to discuss once Sprint 1 closes — not a blocker on
the current close. Session also confirmed `ys2n` = Yuji Shinozaki and retrieved the dev
test IdP's `staff`/`staffpass` example-userpass credentials (from the 08-18 deferred note,
[dev-0-needs-test-idp-for-saml-login-testing.md](../deferred/dev-0-needs-test-idp-for-saml-login-testing.md))
for live SimpleSAML login testing.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-08-19 18:16

remember where we are on this project and check the session log for latest work.

---

## Claude — 2026-08-19 18:16

Now let me update the frontmatter `modified` date and the MEMORY.md index line.

---

## Claude — 2026-08-19 18:16

That's fine, non-critical metadata. Now update MEMORY.md index.

---

## Claude — 2026-08-19 18:17

**Where we are:** main is at `b83de37`, working tree clean, nothing outstanding to pull.

Latest session (2026-08-18, ys2n driving, PR #120) built and proved a **dev test IdP**, closing 1b.3's live-session gap: SAML login through a local test IdP → real Drupal session → full OAuth2 authorization-code exchange, all verified live with a real non-admin, group-scoped migrated user. Found two more bugs along the way (fixed a bogus `MYSQL_*` requirement and a missing `enable.saml20-idp` config key).

**Two new open OAuth2 defects** (deferred notes, not yet fixed):
1. `simple_oauth` signing keys aren't persisted across `deploy_backend.yml` — will silently break OAuth2 again on the next normal deploy.
2. solr-proxy's `GenericProvider` never sends a Bearer header on `/oauth/UserInfo` calls — blocks the entire authenticated/private-collection path (different repo, `uvalib/mandala-solr-proxy`).

**Next step named in the log:** once the UserInfo bug is fixed, prove the proxy's Redis visibility-token read (ADR 014) against a *real* OAuth2 session — previously only tested with a hand-written Redis key. No explicit driver handoff was recorded for that piece.

I've refreshed my memory index and project-state file to reflect this.

---

## User — 2026-08-19 18:18

Is it possible for you at some point to reconstruct that local IdP in this environment? Don't want to do it now though.

---

## Claude — 2026-08-19 18:18

Yes, should be straightforward when you're ready — the IdP config isn't ephemeral, it's committed:

- The metadata + `authsources.php`/`config.php` changes live in `terraform-infrastructure` (commits `7ee3d2441`, `34febafd4`), gated behind `SIMPLESAML_ENABLE_EXAMPLE_AUTH`, which dev-0 already has set — so the test IdP itself should already be standing on dev-0 right now, not something torn down after the session.
- The one piece that *is* ephemeral and would need redoing: the `simple_oauth` signing keypair lives outside any persistent mount and gets wiped on every `deploy_backend.yml` run (that's the still-open deferred defect). So after any deploy since 08-18, I'd likely need to regenerate it by hand again before OAuth2 works.
- The `authmap` link from the `staff` test identity to a real migrated private-collection user was done live via `ExternalAuth::linkExistingAccount()` — that's DB state, so it persists unless the DB was reset/re-migrated since.

If you mean reproducing this in *local DDEV* instead of dev-0, that's a different, bigger lift — DDEV doesn't currently run a SimpleSAMLphp container at all, so it'd mean porting the SAML/OAuth2 stack locally rather than just re-enabling a flag. Let me know which one you want when the time comes.

---

## User — 2026-08-19 18:18

Oh. So Yuji did it on dev-0, correct?

---

## Claude — 2026-08-19 18:18

Yes — the session log lists the driver as `ys2n`, which matches Yuji Shinozaki's initials in the team list, and it was done on dev-0 (not local DDEV).

---

## User — 2026-08-19 18:19

Update the memory to note Yuji and ys2n are the same.

---

## Claude — 2026-08-19 18:19

Saved — added a reference memory noting `ys2n` = Yuji Shinozaki, indexed in MEMORY.md.

---

## User — 2026-08-19 18:23

So how do I get a username and password to login with SimpleSaml on https://mandala-dev.internal.lib.virginia.edu/

---

## Claude — 2026-08-19 18:23

Found it. This is documented (publicly, since it's SimpleSAMLphp's standard built-in test mechanism, not a real credential):

**Username:** `staff`
**Password:** `staffpass`

That's the `example-userpass` test identity that got linked via `authmap` to the real migrated non-admin test user during the 08-18 session. (Two others also exist, `student`/`studentpass` and `faculty`/`facultypass`, but only `staff` has the authmap link — the other two would log into SimpleSAMLphp fine but resolve to no Drupal account.)

Two caveats before you try it:
1. It only works if `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true` is still set and Drupal's active `auth_source` was actually switched to the test IdP path (the deferred note says this was the fix, but flag-based, not a permanent switch — worth confirming it's still active on dev-0 rather than reverted after the session).
2. If nothing works, the OAuth2 signing-key wipe-on-deploy defect could also be interfering if a deploy happened since 08-18 and anything downstream depends on those keys — SAML login itself shouldn't, but the OAuth2 leg (`/oauth/authorize`) would break again until regenerated.

---

## User — 2026-08-19 18:25

it works

---

## Claude — 2026-08-19 18:25

Good to hear — glad the test IdP is still live and working as documented. Let me know if you want to pick up the next thread (the solr-proxy UserInfo Bearer-header bug, or the visibility-token verification once that's fixed).

---

## User — 2026-08-19 18:43

Yuji is driving on his computer. They are fixing solr proxy token issue. I have a question on another area.

---

## Claude — 2026-08-19 18:43

Sounds good — go ahead with your question.

---

## User — 2026-08-19 18:45

One of the Acceptance Criteria for Sprint 1 is "Images render through the existing IIIF server with i3fid linkage intact" but in the sprint I don't see anywhere wher eit implements a IIIF viewer like seadragon.

---

## Claude — 2026-08-19 18:45

That's not a gap — it's confirmed by what D7 itself did. `shanti_iiif`'s `IiifImageFormatter`/`IiifUrlBuilder` (drupal/web/modules/custom/shanti_iiif/) is a direct port of D7's static `ShantiImage::buildIIIFURL()` — it builds a plain IIIF Image API derivative URL (`{server}/{i3fid}/{region}/{size}/{rotation}/default.jpg`) and drops it into an `<img>` tag via the field formatter. D7 never had a deep-zoom viewer wired into Drupal itself either — it always rendered flat derivative images the same way.

OpenSeadragon-style deep zoom lives in the **React client** (`mandala-om`), which talks to the IIIF server independently via `info.json` — that's Spike 6 / the client-compatibility track, not the Sprint 1 Drupal migration. The AC "renders through the existing IIIF server with i3fid linkage intact" is specifically about the Drupal-side identifier and URL contract being correct (1a.5, PR #9 — verified byte-identical URLs to D7, reachability confirmed against the real Cantaloupe server), not about building a new viewer.

So: no gap, just two different rendering surfaces — Drupal's own pages use flat IIIF derivatives (ported faithfully), and any deep-zoom experience is the React app's responsibility and out of this sprint's scope.

---

## User — 2026-08-19 18:48

But if you look at https://images.mandala.library.virginia.edu/image/hermitage-test images on Mandala also use a IIIF viewer (not sure if it's seadragon) but we need to have a viable means of viewing the images on mandala for sorting, classifying, and editing, as well as just viewing.

---

## Claude — 2026-08-19 18:51

No mentions anywhere — confirms this hasn't been flagged yet. You're right, and I was wrong.

**What's actually there in D7:** `sarvaka_images` theme + `shanti_images` module wire up a real OpenSeadragon deep-zoom viewer directly in Drupal, not just in the React app:
- `shanti-main-images.js` (`Drupal.behaviors.shantiImagesIIIF`) lazy-loads `openseadragon.min.js` and shows a full-screen overlay (`#sddiv`/`.sdwrapper`/`#iiiftools`, with rotation, navigator, close button) triggered by clicking the "View in IIIF Viewer" icon on the node page.
- Tile sources come from `Drupal.settings.shanti_images.infourls`, set server-side in `shanti_images.module` (line ~838) — real IIIF `info.json` URLs, not the flat derivative URLs `IiifUrlBuilder` builds.
- There's also a standalone `sdviewer.php`/`shanti_images_sdinit.js` pair (`data-iiifurls` with `|$|`-delimited multi-image sequences) used elsewhere — likely the sorting/classification screens you mentioned, not just single-image viewing.

**What D11's `shanti_iiif` module actually ported (1a.5):** only `IiifUrlBuilder` + `IiifImageFormatter` — a flat `<img>` derivative, matching D7's *fallback* `<img>` rendering, not the OpenSeadragon overlay. So the Sprint 1 AC "renders through the existing IIIF server with i3fid linkage intact" was verified for URL/identifier correctness, but the actual interactive deep-zoom viewer used for viewing/sorting/classifying/editing was never carried forward — it's a real gap, not just a client-app concern.

I'll write this up as a deferred note rather than fix it now, since you're mid-session on the solr-proxy fix with Yuji. Want me to draft `docs/deferred/no-iiif-deep-zoom-viewer-in-d11.md` now, or hold it for session-end?

---

## User — 2026-08-19 18:54

I do want to do a deferred note for this but have more to discuss first. There are also views, such as the gallery view on the home page, https://images.mandala.library.virginia.edu/. This view is a mosaic and when you clic on one tile, it shows a dropdown with more information, a larger version of the picture, and an "Details" button that links to the images page. We want to make sure all that gets built into the new site as well.

---

## Claude — 2026-08-19 18:54

Confirmed — nothing exists in D11 for this yet. That home-page mosaic is another distinct custom module: `shanti_grid_view` (`drupal/web/modules/custom/shanti_general/modules/shanti_grid_view` in the D7 repo — actually a submodule of `shanti_general`).

**What it does (from the module's own README + `.module` file):**
- Registers a custom **Views style plugin** — a Google-Photos-style masonry/mosaic grid (`shanti_grid_view.views.inc`, heavy lifting in `shanti_grid_view_views_pre_render()`) usable on *any* View, not IIIF-specific — it can source images from IIIF, plain Drupal files, node images, or an arbitrary data source, auto-detected from the view's fields.
- Click on a tile → AJAX "popdown" panel fetched from `shanti/grid/info/{type}/{eid}` (or `shanti/grid/dinfo/...` for data-source views) — shows the larger image + metadata, and a "Details" link through to the full node page.
- Uses PIG (Perfect Image Grid) for masonry layout + PhotoSwipe for the lightbox, per the JS/CSS files sitting alongside it (`pig-shanti-ext.js`, `photoswipe.js`).
- Has its own admin settings page (`admin/config/user-interface/shanti_grid_view`) and an image-size cache table (`shanti_grid_image_sizes`).
- The `images.mandala.library.virginia.edu` front page's `all_image_gallery` View is the live example the module's own README points at.

So that's a second, independent gap from the OpenSeadragon viewer: a general-purpose reusable Views plugin, not an image-detail-page feature. Since it's genuinely used across multiple views (the README mentions a non-IIIF example, `related_images`), it's likely used elsewhere on the site too, not just the homepage mosaic — worth checking usage across all five sites before scoping the deferred note.

What else did you want to cover before we write these up?

---

## User — 2026-08-19 18:57

On the individual images page, such as https://images.mandala.library.virginia.edu/image/local-people-light-fires-purify-their-bodies-4, There is the image with the title author and some other metadata below it and then a carousel of other images in its collection. Then under that the full detailed metadata. All this needs to be faithfully recreated in D11 for images, and the corresponding views and viewers for all the other asset types except visuals, which we are not concerned about.

---

## Claude — 2026-08-19 18:57

Confirmed and mechanism understood: the carousel isn't part of the page load — `shanti_images_get_node_carousel($nid)` is an AJAX callback that finds the node's collection, gets the full ordered nid list (`_shanti_images_get_coll_node_ids`), windows ±15 around the current node (30 total), and returns FlexSlider markup that replaces `#fscarousel-placeholder` client-side. Falls back to a hidden/no-data state if the image has no collection. So the full image-page stack is:

1. Main image (flat `<img>`, flexslider single-slide)
2. Title/author/summary metadata strip
3. OpenSeadragon deep-zoom overlay (click-triggered, per the last question)
4. AJAX sibling carousel scoped to the node's collection
5. Full detailed metadata (fields — this part already exists in D11 since 1a.7 migrated the content model)

None of 1–4 exist in D11 yet, and this is scoped to Images only so far.

Before I go spelunking through AV/Sources/Texts equivalents (Kaltura for AV, presumably something else for Sources/Texts — that's 3 more codebases to trace), a scoping question: Sprint 1 / ADR 009 has Images as the only site actually migrated so far — Phase 2 (Texts/Sources/AV/Home) hasn't been forked off yet. Do you want me to:

---

## Claude — 2026-08-19 18:58

Got it — one combined note. Are you ready for me to draft it now, or is there more to cover first (you mentioned having more to discuss)?

---

## User — 2026-08-19 18:58

first tell me how will this be incorporated into the sprint?

---

## Claude — 2026-08-19 18:58

Mechanically, per this project's conventions: a deferred note by itself doesn't automatically become sprint work — it just documents the gap in `docs/deferred/`, indexed in `docs/deferred/README.md`. To actually land in Sprint 1, it needs one of two things done to `docs/sprints/sprint-01-images-implementation.md`:

1. **A new numbered Backlog task** (next would be `1a.10` or similar, in the Step 1a table) with an owner, that the deferred note gets linked from — same pattern every other 1a/1b task follows.
2. **And/or a rewrite of the existing acceptance criterion** — right now, `- [ ] Images render through the existing IIIF server with i3fid linkage intact` (line 124) is satisfied narrowly by 1a.5's URL-contract proof (byte-identical derivative URLs, reachability verified). It says nothing about the deep-zoom viewer, carousel, or gallery — so as written, it's already checkable without any of what you're describing. If you want those to actually gate Sprint 1's close, the criterion needs to be reworded (or a new criterion added) to say so explicitly.

That's the decision point I'd flag rather than just do it myself: **Sprint 1's status line says it's down to two OAuth2 defects before close.** Adding this now means Sprint 1 doesn't close until a deep-zoom viewer + carousel + gallery view are rebuilt — a real, multi-week UI scope addition, not a quick follow-up. The alternative is: log it as a deferred note now (as planned), but scope it as a **new task queued right after Sprint 1 closes** rather than reopening the closing criteria — since Images is otherwise functionally done and the two OAuth2 bugs are the actual remaining blockers.

Which way do you want it — reopen the AC and Step 1a backlog now, or close Sprint 1 as scoped and queue this as the next task in line?

---

## User — 2026-08-19 19:00

I just want to flag this as the next item to be discussed by the group after we complete Sprint 1.

---

## Claude — 2026-08-19 19:00

Good template to follow. Writing the combined deferred note now.

---

## Claude — 2026-08-19 19:01

Now indexing it in `docs/deferred/README.md`.

---

## Claude — 2026-08-19 19:01

Done — filed as `docs/deferred/images-missing-interactive-viewing-surfaces.md`, indexed in `docs/deferred/README.md`, explicitly flagged as **the next item for the team to discuss after Sprint 1 closes**, not a Sprint 1 blocker. It covers all three findings (OpenSeadragon deep-zoom viewer, the AJAX collection carousel, the `shanti_grid_view` mosaic/gallery plugin), notes why Sprint 1's existing AC didn't catch this, and scopes AV/Sources/Texts as unaudited/out-of-scope-for-now with Visuals explicitly excluded.

Not committed — let me know if you want this pushed now or held until session end.

---

## User — 2026-08-19 19:01

go ahead and commit and make a pr

---

## Claude — 2026-08-19 19:02

PR #122: https://github.com/uvalib/mandala-navina/pull/122

---

## User — 2026-08-19 19:02

update memory with this session's findings

---

## Claude — 2026-08-19 19:02

No — PR #122 only contains the deferred note itself (`docs/deferred/images-missing-interactive-viewing-surfaces.md`) and the `docs/deferred/README.md` index update. No session log was written or committed this session.

---

## Claude — 2026-08-19 19:02

Confirmed: just those two files. Per the project's session-end ritual, a full session log (via `scripts/save-session-log.py`) is a separate step, typically done at session close — want me to run that now and add it to this PR (or a follow-up commit), or hold it since you're still mid-session with Yuji?

---

## User — 2026-08-19 19:03

do the session log to so that other clones of the repo are aware of this for whenever we finish sprint 1

---
