# Spike 6: API Compatibility for React Application
**Status:** ◐ In progress — **headline architecture question is decided and proven**, but the
spike is not complete. **URL strategy DECIDED 2026-08-12: Option A**, the same-origin proxy,
generalized to every site (superseding the original pre-spike sketch — see "URL-strategy
DECISION" below). Proven end-to-end for one real site: `mandala_node_api`'s
`GET /api/json/{nid}` for Images is **live and verified against real migrated data in DDEV**
(public node → 200 with shaped JSON; private-collection node → real 403 via group membership,
not a stub). `mandala-wp-proxy`'s SSRF gap is fixed and pushed; the `wp-kmaps` dependency is
declared. **Pass-criteria scorecard:** URL strategy agreed ✅; feasible in D11 ✅ (and Option A's
whole point is that no ALB/WAF change is needed at all); D11 implementation approach clear —
◐ Images only, Sources/Texts/AV still need their own controllers (confirmed different shapes,
none built); all 8 D7 response formats documented — ◐ JSON done for all 4 sites, AJAX endpoints
(Texts' `node_embed`, `/user/current`) still unaudited. **The client-side proxy generalization
is now implemented** (2026-08-20, `mandala-om` `feat/generalize-json-proxy-all-sites` —
**pushed, and the proxy path is browser-verified end to end**; no PR yet, and only the Sources
detail page was exercised — AV/Images/Texts/Visuals remain untested). **Remaining work:** build Sources/Texts/AV controllers when each
site migrates, audit + build the AJAX endpoints, and validate the Images response shape against
what the live client actually reads (built from the D7 audit + kmassets logic, not yet checked
against client rendering code). Two known, deferred gaps: private-collection assets can't be
fetched through the JSON-proxy path because no caller identity reaches `mandala_node_api`
([note](../deferred/mandala-node-api-no-identity-forwarded-through-json-proxy.md)), and Option A's
proxy doesn't exist on the standalone non-WordPress deployments
([note](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md)).
**⚠️ Audit-reliability caveat — now CLOSED (2026-08-21).** The 2026-08-07 D7 endpoint audit was
done by reading source rather than calling the live endpoints, and its AV row was later found to
be wrong in **three** independent ways (2026-08-20). **All four rows have since been live-tested**
— AV on 2026-08-20, Sources and Texts on 2026-08-21. Sources and Texts held up: correct module,
callback, route and response family, no AV-scale errors. Two refinements were made (Sources'
augmentations are conditional by node type; Texts' `parent`/`children` are unreachable dead code)
plus one behavioural discovery — **the Texts endpoint normalizes any page nid to its book root**,
so `nid → document` is many-to-one. See "Sources + Texts live endpoint verification" below.
**Lead:** Than Grove (owns React app and D7 API contracts)
**Mode:** Team spike (candidate)
**Date:** —
**Branch:** `spike/6-api-compatibility` (superseded — pre-findings + audit now on `main`)

## Theory
A clear strategy exists for preserving API compatibility between the current
multi-site D7 API endpoints and the consolidated D11 single-instance, without
breaking the React application that consumes them.

## Demo
**Live (2026-08-12):** the D11 `/api/json/{nid}` endpoint for `shanti_image` (Images), verified
against real migrated data in DDEV — see "D11 node-JSON endpoint for Images" below. Sources/
Texts/AV still need their own controllers when each site migrates (each has a materially
different response shape per the D7 audit below), and the client itself hasn't yet been
generalized to call this endpoint through the decided proxy path (currently Sources-only). The
spike also produced two audits against real source that informed this build: the client-side
fetch architecture (pre-spike findings, 2026-07-30, against `mandala-om`) and the D7 server-side
node-JSON endpoints (2026-08-07, against `Site/mandala-drupal`), documented below.

## Findings

### D7 per-site node-JSON endpoint audit (2026-08-07, against live D7 source `Site/mandala-drupal`)

Located and read all four per-site individual-asset detail endpoints (the ones the React
client reaches via each Solr record's `url_json` field). This resolves Pass Criterion #1
for the JSON endpoints. **Key takeaway: the four endpoints are not uniform** — all four
return some variant of the augmented raw node, but differ in JSONP support (three
JSONP-capable via `?callback=`, AV has none) and Texts additionally embeds rendered HTML.
**Corrected 2026-08-20:** the original version of this takeaway (below, in the table and
gotchas) mischaracterized AV as a bespoke Solr-derived flat "doc" with no real Drupal
route — live evidence contradicts that; see the correction notes inline. A single generic
D11 controller still will not reproduce all four; each needs its own response shaping, but
for JSONP/route-existence reasons now, not because AV's shape is categorically different.

| Site | Public path | Module / file | Callback | Response shape | JSONP |
|---|---|---|---|---|---|
| **AV** | `/api/v1/media/node/{nid}.json` (and `.jsonp`) | Services module (per-content-type "JSON Path" setting, same mechanism as Images/Sources/Texts — see below) | Services `node` resource `retrieve` action | Augmented **raw node** (`vid`, `uid`, `title`, `field_*` incl. `field_kmap_terms`/`field_subject`/etc. in the usual `raw/id/header/domain/path` shape, `field_og_collection_ref`) **+ computed extras** (`thumbnail_url`, `duration: {seconds, formatted}`, `path`) — **not** a Solr-style flat doc | **Yes** — `.jsonp?callback=` (see correction) |
| **Images** | `/api/json/{nid}` | `shanti_images.module` | `shanti_images_node_json()` | Augmented **raw node** (entity-refs expanded in place); `?extend=true` gives a reshaped flat variant w/ IIIF url + dims | **Yes** (`?callback=`) |
| **Sources** ✅ *live-verified 2026-08-21* | `/sources-api/json/{nid}` | `shanti_biblio_modules/sources_misc/sources_misc.module` | `sources_misc_node_json()` | Augmented **raw node**; sends `Access-Control-Allow-Origin: *` (confirmed live). The three augmentations are **conditional and mutually exclusive by node type**, *not* present on every response: `description` only when `body` is non-empty, `subcollections` only on `collection`, `parent` only on `subcollection`. A plain `biblio` node — the common case the client fetches — gets **none** of them | **Yes** (`?callback=`) — but served as `content-type: application/json`, not `text/javascript` |
| **Texts** ✅ *live-verified 2026-08-21* | `/shanti_texts/node_json/{nid}` | `shanti_texts.module` | `shanti_texts_node_json()` | Augmented **raw node** **+ embedded rendered HTML** (`full_markup`, `toc_links`, `bibl_summary`, `views_links` via `views_embed_view()` — all four confirmed live as HTML strings). **⚠️ The endpoint normalizes any page nid to its book root**, so the response is keyed to `book.bid`, not to the nid requested. `toc` is therefore always present and `parent`/`children` are **unreachable via the public route** — see the verification note below | **Yes** (`?callback=` **or** `?json_wrf=`, both `text/javascript`) |

> **Correction (2026-08-20, Than + Claude Code):** the AV row above (module, callback, and
> response shape) as originally written 2026-08-07 was **wrong**. It was based on reading D7
> source (`mb_solr.module`) rather than hitting the live endpoint, and concluded AV served a
> bespoke Solr-derived flat `doc` with no real Drupal route. Live evidence contradicts this:
> `curl https://av.mandala.library.virginia.edu/api/v1/media/node/{42016,42167,42158}.json`
> returns an **augmented raw node** — the same family of shape as Images/Sources/Texts, not a
> Solr doc — via the Services module's standard per-content-type "JSON Path" setting (visible on
> `/admin/structure/types/manage/video`, value `api/v1/media/node/__NID__.json`), the exact same
> mechanism `shanti_kmaps_fields` documents for the other three sites. The table row and gotchas
> #1–#2 below are corrected accordingly; the original (incorrect) claims are struck through for
> the record rather than silently removed. This changes the D11 implementation picture:
> **no special server-level path rewrite is needed for AV**, and it is not tied to the Solr/
> kmassets write path the way the 2026-08-07 note assumed. See also the "AV is the exception"
> paragraph below, which needs the same correction.
>
> **Third error in the same row, found the same day: AV *does* support JSONP.** The row
> originally said "No (plain `drupal_json_output`)", and gotcha #4 concluded the client's JSONP
> dependency was "only satisfiable on 3 of the 4 today." Live evidence:
> `GET /api/v1/media/node/42016.jsonp?callback=mdldata` → **HTTP 200**,
> `content-type: text/javascript`, body wrapped as `mdldata({...})`. The React client
> **depends on this** — `useMandala.js:78-80` appends `'p'` to the `url_json` value whenever
> `asset_type === 'audio-video'`, specifically to hit the `.jsonp` variant. All four sites are
> JSONP-capable; gotcha #4 is corrected below.

**Load-bearing gotchas for D11:**

1. ~~**AV's public path has no Drupal route.** `/api/v1/media/node/{nid}.json` is a
   **server/proxy-level rewrite** to the internal `services/solrdoc/%` route
   (`mb_solr_get_solrdoc`) — verified: the string `api/v1/media/node/` appears in the D7
   codebase *only* where the endpoint self-documents its own URL (`mb_solr.module:885`),
   never as a `hook_menu` key. **D11 must recreate this path mapping explicitly** (route
   alias or rewrite); it will not fall out of a straight module port.~~ **Corrected
   2026-08-20:** live evidence shows this is a standard Services-module content-type JSON
   Path route, the same mechanism the other three sites use — no rewrite/alias needed, D11
   can register a normal route at this path like `mandala_node_api` already does for Images.
2. ~~**AV is Solr-derived, not node-derived.** Its `doc` shape mirrors the kmassets Solr
   record, which ties the AV endpoint to the kmassets write path (1a.8 / reindeer_x),
   consistent with the client already reading `url_json` from Solr.~~ **Corrected 2026-08-20:**
   live evidence shows AV's response is an augmented raw node like Images/Sources/Texts, not
   Solr-derived. It is not tied to the kmassets write path.
3. **Texts bakes rendered HTML into JSON** via four `views_embed_view()` panes — so the
   D11 equivalent depends on those Views (or replacements) existing and rendering, not
   just on node data. This overlaps the Texts book-display model (see
   [Spike 4b](spike-04b-ckeditor5-footnotes.md)).
4. **JSONP is per-endpoint inconsistent** — Images/Sources use `?callback=`, Texts adds
   `?json_wrf=`, ~~AV has none. The client's JSONP dependency (pre-findings) is only
   satisfiable on 3 of the 4 today~~ **and AV serves it from a separate `.jsonp` path
   variant** (`/api/v1/media/node/{nid}.jsonp?callback=`), which the client reaches by
   appending `'p'` to `url_json` for `asset_type === 'audio-video'`
   (`useMandala.js:78-80`). **Corrected 2026-08-20:** the client's JSONP dependency is
   satisfiable on **all four** sites today, not three. The inconsistency is in *how* the
   callback is requested (query param vs. path suffix vs. `json_wrf`), not in whether JSONP
   exists. The D11 reachability decision (proxy-everything vs. CORS) should standardize this
   rather than replicate the inconsistency — and note that under Option A none of it is
   needed, since a server-to-server proxy fetch wants plain JSON.
5. **Private-content gating is shared** — all four call `shanti_general_api_check($node)`
   before emitting. D11 must enforce the equivalent access check (ties to the ADR 015 /
   Group access model) in whatever replaces these endpoints, or private assets leak via
   the API.

### Sources + Texts live endpoint verification (2026-08-21)

Closes the audit-reliability caveat for the two remaining un-live-tested rows. Both were fetched
against **live production** with `curl`; the D7 source was then read to explain each observation.
(`curl` is valid evidence for *response shape and route behaviour* — the thing it cannot test is
the **WAF's treatment of a browser cross-origin request**, which is a separate question and is
unchanged by this pass.)

> **⚠️ Tooling constraint for the pending AJAX audit — `curl` cannot fetch the AJAX/embed
> endpoints at all** (found 2026-08-21, raised by Than). The edge bot-challenge keys on the
> **response content type**, not the site or the path: every JSON endpoint sails through, and
> every HTML-returning endpoint gets a `202` with an empty body. Measured the same minute, same
> nids:
>
> | endpoint | result |
> |---|---|
> | `sources-api/json/62716` | `200` `application/json` 8,066 B |
> | `shanti_texts/node_json/62716` | `200` `application/json` 19,329 B |
> | `api/v1/media/node/42016.json` | `200` `application/json` 3,160 B |
> | `sources-api/ajax/62716` | **`202` `text/html` 0 B** |
> | `shanti_texts/node_embed/62716` | **`202` `text/html` 0 B** |
> | `services/node/ajax/42016` | **`202` `text/html` 0 B** |
>
> The same `202`-empty is what the Sources *homepage* returns to `curl`, so this is the bot
> challenge, **not** a broken endpoint — all three AJAX endpoints work normally in a browser
> (confirmed by Than). **Anyone auditing the AJAX endpoints must use a real browser**, as the
> 2026-08-20 proxy verification did. A `curl`-based audit would conclude they are all dead, which
> is the same class of false negative that produced the retracted content-type/ORB diagnosis.
> This is a sharper form of the standing "curl cannot test this WAF" rule: it is not that `curl`
> always fails, it is that `curl` fails on the HTML-returning responses.

**Headline: both rows were substantially right — no repeat of the AV situation.** Unlike the AV
row, neither Sources nor Texts was wrong about its module, callback, route, or general response
family. Two refinements and one significant behavioural discovery follow.

**Both endpoints are live and healthy.** `sources-api/json/62716` → 200 `application/json`
(8,066 B); `shanti_texts/node_json/62716` → 200 `application/json` (19,329 B). Both return an
augmented raw node, as claimed.

**Sources — the augmentations are conditional, which the row read as unconditional.** All three
were proven live rather than inferred, by walking a real collection tree:

| node | type | result |
|---|---|---|
| 62716 | `biblio` | **no** `description` / `subcollections` / `parent` — its `body` is `[]` |
| 62311 | `subcollection` | `parent` = `"University of Flourishing\|24466"` |
| 24466 | `collection` | `subcollections` = 33 entries (`"Title\|nid"`), `description` present |

`sources_misc_node_json()` adds `description` only `if (!empty($node->body['und'][0]['safe_value']))`,
`subcollections` only for `type == 'collection'`, `parent` only for `type == 'subcollection'`.
**For D11 this means the Sources response shape is not uniform** — a generic controller that
always emits these keys would not match D7, and one that never emits them breaks collections.
`Access-Control-Allow-Origin: *` confirmed present on the live response.

**Texts — the real finding: the endpoint resolves any page nid to its book root.**

```
shanti_texts/node_json/{62701,62706,62711,62716,62721}
  -> all five return byte-identical 19,329 B documents for nid 62701
```

`shanti_texts_node_json()` does this explicitly: after the access check it runs
`if (!shanti_texts_is_book($node)) { $nid = $node->book['bid']; $node = node_load($nid); }`, and
`shanti_texts_is_book()` is true only when `book['nid'] == book['bid']`. So **the response is
keyed to the book, not to the nid requested**, and `nid → document` is many-to-one.

Two consequences the audit row did not capture:

1. **`parent` and `children` are dead code on this route.** They are set only in the
   `subtype == 'page'` branch, which the book-root swap makes unreachable — `hook_menu` registers
   `'page arguments' => array(2)` (the nid only), so the function's `$is_page` parameter is never
   passed and is always `FALSE`. Live confirmation: every response carried `subtype: "book"`,
   `is_page: false`, and a `toc`; none ever carried `parent` or `children`. **D11 need not
   implement `parent`/`children` for parity — D7 never serves them here.**
2. **`url_json` per-page is ambiguous for Texts.** If kmassets writes a page-level nid into
   `url_json`, D7 answers with the whole book. Whatever D11 does must be a deliberate decision,
   not an accident of porting.

**Error handling diverges between the two sites** — worth pinning before either D11 controller is
written:

| | missing nid |
|---|---|
| Sources | **HTTP 200** with a JSON body `{"nid": -1, "status": 404, "messsage": "..."}` (the key really is misspelled `messsage` in D7 source) |
| Texts | **HTTP 404** with a Drupal HTML error page — not JSON at all |

**JSONP confirmed on both**, as claimed: Sources `?callback=`, Texts both `?callback=` and
`?json_wrf=`, all wrapping as `cb({...});`. One quirk: **Sources returns its JSONP with
`content-type: application/json`** while Texts and AV use `text/javascript`. Recorded as an
observation only — no browser-level consequence was tested, and an earlier content-type/ORB
diagnosis in this spike was retracted after being built on a bad `curl` result.

**Gotcha #5 (shared private-content gating) holds for both** — `sources_misc_node_json()` and
`shanti_texts_node_json()` both call `shanti_general_api_check($node)` before emitting. This was
confirmed by reading source, not by fetching private content.

> **One access-control question is deliberately not documented here.** Reading
> `shanti_texts_node_json()` raised a question about the live D7 stack that this public repo is
> the wrong place for, per [non-public documentation policy](../non-public-documentation.md). It
> is not known to be exploitable and was not tested against production. **Ask Than Grove**; it
> belongs in `uvalib/mandala-legacy-docs` if it holds up.

**Evidence scope, stated honestly:** one Sources collection tree (3 nodes across all three
types) and one Texts book (5 page nids). Random nid sampling on both sites mostly returned
not-found, so a second Texts book was not located; the book-root normalization is nonetheless
unambiguous in source and consistent across every nid tested.

### AJAX / embed endpoint audit (2026-08-21)

Completes the second half of the "all eight D7 API response formats" criterion. Method: D7 source
read for all four, then live verification **in a real browser** (the `curl` constraint recorded
below makes browser use mandatory). Public nodes only — `62716` on Sources/Images/Texts.

**First result: the `curl` `202`s were the bot challenge, not dead endpoints.** All three
browser-tested endpoints returned real, populated HTML fragments. Nothing here is broken.

| Site | Route | Handler | Route access | Live |
|---|---|---|---|---|
| **Images** | `api/ajax/%` | `shanti_images_node_embed()` | `'access callback' => TRUE` | ✅ full metadata fragment (derivative sizes, agent, dimensions, license, UID, technical metadata) |
| **Sources** | `sources-api/ajax/%` | `sources_misc_node_embed()` | `access content` | ✅ bibliographic summary fragment |
| **Sources** | `sources-api/ajax/%/%` | `sources_misc_node_embed($nid, $type)` | `access content` | **not in the endpoint matrix** — see below |
| **AV** | `services/node/ajax/%` | `mb_services_node_ajax()` | `access content` | ✅ HTML fragment — and it renders the **full internal `Workflow` field group** (see below) |
| **AV** | `services/node/ajax/%/player` | `mb_services_node_player()` | `access content` | ✅ confirmed live — a **cross-domain redirect off-site to the Kaltura CDN** (`cdnapisec.kaltura.com/.../mwEmbedFrame.php`, partner `381832`, per-node `entry_id`) |
| **Texts** | `shanti_texts/node_embed/%` | `shanti_texts_node_embed()` | `access content` | ✅ embed container: body + Contents + About + Views panes |

**All four return HTML fragments, never JSON.** This is the cleanest reason they are a different
problem from the JSON endpoints: there is no JSONP, no `?callback=`, and nothing here participates
in the `url_json` Solr-record mechanism except Texts via `url_ajax`.

**The matrix undercounts them — there are six routes, not four.** Two surfaces were missing:

1. **`sources-api/ajax/{nid}/{type}`** — `$type='cite'` renders a formatted **citation** rather
   than a summary, with the biblio style taken from `arg(4)` (defaults to `chicago`, falls back to
   `biblio_style_chicago` if the requested style will not load). An entire citation-rendering
   surface the audit never listed. Relevant to [Spike 5](spike-05-bibcite-sources.md), which owns
   Sources citations.
2. **`services/node/ajax/{nid}/player`** — an AV-only redirect to the Kaltura player.

**Dead code found: `sources_misc_node_ajax()`.** The function exists and does
`node_load` → `node_view('full')` → `drupal_render`, but **no route points at it** —
`sources-api/ajax/%` is wired to `sources_misc_node_embed()` instead. Nothing to port.

**Texts: `node_embed` and `node_json` disagree about nid handling, and this is the more useful
behaviour of the two.** Both load the book root, but only `node_json` reassigns `$nid`:

```php
// node_json — swaps BOTH, so the response is the book root (many nids -> one document)
if (!shanti_texts_is_book($node)) { $nid = $node->book['bid']; $node = node_load($nid); }

// node_embed — swaps only $node, then passes the ORIGINAL $nid to the views
if ($node->book['bid'] != $nid) { $node = node_load($node->book['bid']); }
$content = views_embed_view('single_text_body', 'panel_pane_embed', $nid);
```

So **`node_embed` renders the page you asked for**, while `node_json` collapses to the book root.
A consequence worth noting for anyone porting it: in `node_embed` the reloaded `$node` is then
used **only** for the `if ($node)` guard — the book-root load is otherwise dead work.
`node_embed` also accepts **`?nostyle=true`** to suppress the inline `<style>` block it otherwise
injects, which is the flag a D11 equivalent would want if the fragment is being embedded into a
host page with its own CSS.

**Texts' four panes are `views_embed_view()` calls** — `single_text_body` /
`single_text_toc` / `single_text_meta` / `single_text_views` — confirming gotcha #3: a D11
equivalent depends on those Views (or replacements) existing and rendering, not just on node data.

**Consumer picture is unchanged by this audit** — still only `legacy/texts.js` (via `url_ajax`)
for Texts, and no identified consumer for the other five routes. This audit documents the
response contracts; it does not change the scope steer above.

> **Access-control review deliberately not written up here.** Reading these four handlers side by
> side raised a question about the live D7 stack that this public repo is the wrong place for, per
> [non-public documentation policy](../non-public-documentation.md). It concerns how these
> endpoints gate non-public nodes, it was **not** tested against production, and no private
> content was fetched. **Ask Than Grove**; it belongs in `uvalib/mandala-legacy-docs` if it holds
> up. This supersedes the narrower pointer in the Sources + Texts verification section, which was
> one instance of the same question.

**AV live-verified 2026-08-21 — all six routes are now evidence-based.** Two AV-specific results:

1. **`services/node/ajax/{nid}` renders the entire internal `Workflow` field group to an
   unauthenticated request** — ~25 cataloging/production status fields (`Video Quality
   Acceptable`, `Masters Archived`, `Transcribed`, `Timecoded`, the `Media Problem`/`Timecoding
   problem` slots, translation-language proofing state), almost all reading `Not Reviewed` on the
   node checked. This is a **response-contract fact**, observed on a public node with an ordinary
   browser GET. **Not a concern — resolved by Than, 2026-08-21: "Workflow fields being viewable by
   unauthenticated user is ok. Those fields are rarely used."** Recorded so it is not re-raised as
   a defect by a later reader; `field_workflow` being the one field D7 protects with
   `field_permissions` (for the AV-only `workflow editor` role, rid 5) makes it look more
   significant than it is. **This disposes of the field-level question only** — the separate
   node-level access question referred to privately below is unaffected and still open. **For D11
   the design point stands on its own merits:** an AV embed equivalent should choose which field
   groups it exposes rather than rendering the node's full display, if only to keep the fragment
   small.
2. **`/player` is a cross-domain redirect off the Mandala estate entirely** — to
   `cdnapisec.kaltura.com/html5/html5lib/v2.27.1/mwEmbedFrame.php` with Kaltura partner id
   `381832`, `uiconf_id` `24762821`, and a per-node `entry_id` (`1_lbuv4kg1` for node 42016).
   **This is [Spike 7](spike-07-kaltura-av-integration.md)'s territory, not Spike 6's** — it is not
   a Drupal-internal route and carries no API-compatibility question for the React client. Noted
   here only because the audit walked into it; the live values are recorded in Spike 7 as a
   starting point.

*(Method note: the AV domain initially refused automation inside a `browser_batch` call but
navigated normally as a standalone call — the "approved sites" list was empty throughout, so the
block was not a missing site approval.)*

### Scope steer: the AJAX endpoints are low-importance for *this* spike (Than, 2026-08-21)

**Than's steer:** the AJAX endpoints "are not that important. I'm not even sure they are used but
it would be in the context of JS / React or else same origin."

**The evidence already in this doc corroborates that**, and was gathered independently of the
steer:

| endpoint group | known consumer |
|---|---|
| Generic AJAX (`/api/ajax`, `/services/node/ajax`, `/sources-api/ajax`) | **None found** — no React client references |
| Browse-by-KMap Drupal endpoints | **None found** — no client references |
| Texts `node_embed` (via `url_ajax`) | **The only one with a live consumer** — and it is `legacy/texts.js`, a legacy path, rewriting `node_ajax`→`node_embed` with `?callback=pfunc` for an embeddable HTML fragment |

So three of the four have no identified consumer at all, and the fourth is reached only from
legacy client code.

**The structural argument is stronger than the usage argument, and it is what actually matters
here.** Spike 6 exists to resolve **cross-origin API reachability** — the WAF/CORS/JSONP problem.
An endpoint consumed same-origin, or from a JS context inside the embedding page, is **not
exposed to that problem at all**. So the AJAX endpoints are arguably out of scope for *this
spike* independent of whether they turn out to be used — they belong to whichever site's
migration owns them (Texts' `node_embed` is already noted as Texts-phase work).

**Consequence for the pass criteria — Than's call, not recorded as decided.** The criterion "all
eight D7 API response formats are fully documented" counts 4 JSON + 4 AJAX. If the AJAX half is
out of scope for Spike 6, that criterion is **over-scoped**, and the JSON half — now documented
*and* live-verified for all four sites (2026-08-20/21) — would satisfy it as narrowed. That would
leave the AJAX audit as **not a closure blocker**. The `curl`-cannot-reach-them constraint
recorded above then becomes a note for whoever picks the endpoints up later, rather than a
prerequisite for finishing this spike.

**One live observation while checking this** — D11 *does* write `url_ajax` into every kmassets
record (`KmassetDocBuilder.php:126`, `mandala_kmassets_sync.settings.yml`), so the field is
populated whether or not anything reads it. In the deployed `config/sync`, `url_ajax` — along
with `url_html` and `url_json` — currently points at the **D7 production host**
(`https://images.mandala.library.virginia.edu/api/ajax/__NID__`), not at `__BASE_URL__` as the
install config does. Flagged as an observation only: during D7/D11 coexistence that may well be
deliberate, and it was not investigated.

### ⚠️ Open consideration: empty fields are omitted, so no single record proves a contract

**Raised by Than, 2026-08-21. Recorded for the record — no approach chosen, nothing to act on
yet.**

The D7 JSON endpoints generally **omit a field entirely when it is empty** rather than emitting
it null or blank. The Sources finding below is one instance of the pattern (`description` absent
whenever `body` is empty), but the observation is broader and is not specific to the conditional
augmentations: it applies to ordinary `field_*` values across all four sites.

**The consequence is methodological, and it qualifies work already done.** Every endpoint
contract in this spike was captured by fetching a small number of real production nodes — AV from
three (42016/42167/42158), Sources from three, Texts from one book. A sampled record shows the
fields *that node happens to populate*, so **absence of a field in a captured sample is not
evidence that the endpoint never emits it.** The field inventories here should be read as
*lower bounds on the response contract*, not as complete field lists. Nothing captured so far is
known to be wrong; it is the completeness claim that is unsupported.

This matters most for the D11 controllers, since a controller built to match a sampled inventory
will silently under-implement whatever the samples did not exercise — and, per the migration
convention, will only be caught later against real client rendering.

Two candidate approaches were floated and **neither has been chosen or investigated**:

1. **Purpose-built test records** — one fully-populated node per content type, every field filled,
   as a fixture that makes the maximal response shape observable in one fetch.
2. **A programmatic derivation** — enumerate each content type's field definitions from D7 config
   directly, rather than inferring the contract from sampled instances.

Open questions if this is picked up: whether the omit-when-empty behaviour is uniform across the
four sites or per-module; whether it applies to the computed/augmented keys as well as raw
`field_*` ones; and whether fixtures would live in D7 (to capture the source contract) or in D11
(to test the replacement).

### AV live endpoint field inventory (2026-08-20, against real production data)

Captured to correct the 2026-08-07 audit above and to give the future AV migration a real
starting contract instead of re-deriving it from D7 source later. Fetched
`https://av.mandala.library.virginia.edu/api/v1/media/node/{nid}.json` for three real public
`video` nodes (42016, 42167, 42158) covering both a KMaps-term-sparse and a
KMaps-term-populated case. **AV migration has not started** (no D11 `video`-equivalent
bundle exists yet, and `mandala_kmassets_sync.settings.yml`'s `bundles` map only has
`shanti_image`) — this is reference material for that future work, not something acted on
now.

Confirmed field groups, beyond the base node keys (`vid`, `uid`, `title`, `nid`, `type`,
`language`, `created`, `changed`):
- **PBCore metadata paragraphs** (D7 field-collections): `field_pbcore_creator`,
  `field_pbcore_title`, `field_pbcore_description`, `field_pbcore_coverage`,
  `field_pbcore_extension`, `field_pbcore_identifier`, `field_pbcore_relation` (used for
  "Is Part Of" episode/series links via `field_relation_identifier` target_id +
  `field_relation_type`), each wrapped in the usual `item_id`/`revision_id`/`field_name`
  field-collection envelope.
- **KMaps term references**, all in the standard `raw`/`id`/`header`/`domain`/`path` shape
  already used elsewhere in this codebase (`shanti_kmaps_fields_default`-equivalent):
  `field_kmap_terms` (domain `terms`), `field_subject` and `field_subcollection_new`
  (domain `subjects`), `field_recording_location_new` (domain `places`).
- **Collection membership**: `field_og_collection_ref` → `target_id` (Group node id), same
  pattern as Images' owning-collection lookup.
- **Video asset**: `field_video` → `{entryid, mediatype, settings}` — a Kaltura entry id, not
  a file/media entity D11 would recognize directly.
- **Computed, not raw field data**: `thumbnail_url` (Kaltura CDN URL), `duration` (`{seconds,
  formatted}`), `path` (canonical alias URL).
- **Oddity worth a closer look whenever AV migration starts**: `field_pbcore_description`
  appears **twice** in the same response under two different keys — once as a
  **JSON-encoded string** (double-encoded) under its own field name, and again as a properly
  decoded object under an unrelated-looking key `field_pb_desc`. Not investigated further
  here; flag for whoever builds the AV migration/controller.

Raw sample responses were not committed (ephemeral `curl` output, not needed once this
summary exists); re-fetch directly from the URLs above if a future session needs the exact
bytes again.

### How the endpoint URL is configured & discovered — `url_json` is per-content-type config, not a hardcoded route (2026-08-07)

This closes the loop on the pre-finding that *"the node-JSON URL is data carried in the Solr
`url_json` field, not hardcoded in the client."* **Where that data comes from, in D7:**

- The **`shanti_kmaps_fields`** module adds, to **each content type's** edit form (e.g.
  `…/admin/structure/types/manage/shanti_image`), a block of asset settings:
  **`asset_type`, HTML Path, AJAX Path, JSON Path, Thumbnail Path** — each a **URL template
  with a `__NID__` placeholder** (e.g. JSON Path = `api/json/__NID__`; HTML Path defaults to
  `node/__NID__`). Stored as D7 vars `shanti_kmaps_fields_url_json__{type}` etc.
  (`shanti_kmaps_fields.module:867–895`).
- When the module builds a node's Solr doc, it substitutes `__NID__`→nid, wraps in an absolute
  `url()`, and writes `url_html` / `url_ajax` / `url_json` / `url_thumb` into the kmassets
  record (`…module:1192–1196`). The React client then reads `url_json` off the Solr record and
  fetches it. **So the discoverable API path is per-content-type configuration**, decoupled by
  design from the endpoint that serves it — the settings form itself notes the paths *"may not
  exist, so they may need to be created … by Services, Views, or a module"* (which is why the
  endpoint implementations audited above live in separate modules per site).
- ~~**AV is the exception**: it uses `mb_solr` (not `shanti_kmaps_fields`) to build its doc, so
  its `url_json` (`/api/v1/media/node/__NID__.json`) is set there, not via content-type
  config.~~ **Corrected 2026-08-20:** AV is **not** the exception. Its "JSON Path" setting
  (`api/v1/media/node/__NID__.json`) lives on the `video` content type's edit form exactly
  like the other three sites (confirmed by inspection of
  `/admin/structure/types/manage/video`), driven by the same Services-module mechanism, not
  `mb_solr`.

**D11 state — the mechanism was relocated & redesigned, NOT dropped** (verified across all D11
custom modules; it is **not** in the pared-down D11 `shanti_kmaps_fields`, which is field-type
only):

- It now lives in **`mandala_kmassets_sync`** (the 1a.8 kmassets write path). Per-**bundle** CMI
  config (`mandala_kmassets_sync.settings.yml`) keyed by node bundle instead of per-install D7
  vars (single-site, ADR 005), tokens `__BASE_URL__` + `__NID__`, built by `KmassetDocBuilder`.
- The `shanti_image` bundle default is already `url_json: '__BASE_URL__/api/json/__NID__'` — but
  the config's own comments flag that the **D11 single-site path scheme is a *deferred
  decision*** and these templates merely *"preserve the D7 path shape"* as placeholders. **That
  deferred decision is precisely the URL strategy this spike owes.**
- Only `shanti_image` is configured so far (images is the only migrated site) — AV/Texts/Sources
  bundles are not yet defined.

**⚠️ Concrete gap this surfaces:** the **producer** side exists (D11 writes a `url_json` into
kmassets), but the **server** side does **not** — a route check across all D11 custom modules
found **no controller serving `/api/json/__NID__`** (or any node-JSON path). So today D11 would
publish a `url_json` into Solr that resolves to nothing. Building that D11 endpoint (to return
the response shapes documented in the audit above) is core Spike-6 implementation work.

### What the current React client actually consumes — scoping the real D11 API surface (2026-08-07)

Audited `mandala-om` (`release/v1.1.0-rc`) for which endpoints/fields it truly uses, to size
the D11 work against reality rather than the full historical endpoint matrix. **The
Pass-Criteria "8 endpoint" table is both too big and too small:** the browse-by-KMap Drupal
endpoints appear unused, while a current-user endpoint the matrix omits *is* used.

**In scope — the client consumes these (must exist in D11):**

| Solr field / endpoint | How the client uses it | D11 status |
|---|---|---|
| **`url_json`** | Core node-detail fetch (`useMandala.js`, JSONP `callback`; `assetapi.js` uses `json_wrf`). | Written by `mandala_kmassets_sync`; **serving endpoint missing** (above) |
| **`url_html`** | Full-page links (`FeatureCard`, `TextsViewer`, `SourcesViewer`, legacy `searchui`) **and a reverse Solr lookup** — `MandalaMarkup.js` queries `q: url_html:"…"` to find an asset by its page URL. | D11 writes it (`__BASE_URL__/image/__NID__`); pages must resolve **and** match the value the client searches on |
| **`url_thumb`** | Image thumbnails + client-side size derivation (`searchapi.js`, legacy image/collection views). | Handled — `mandala_kmassets_sync` `ImageFieldContributor` builds IIIF thumb URLs |
| **`url_ajax` → embed** | **Texts only** (`legacy/texts.js`): rewrites `node_ajax`→`node_embed`, `?callback=pfunc`, to pull an embeddable HTML fragment. | D11 has no embed endpoint; Texts-phase work |
| **`/general/api/user/current`** | `LoginLink.js` — current-user / auth status. **Not in the endpoint matrix.** | Not yet in D11; ties to SAML/OAuth (Spike 10) |

**Out of scope — appears NOT consumed by the React client:**

- The **browse-by-KMap Drupal endpoints** (`/services/subject/{id}`, `/general/api/*images/{id}`,
  etc. from the Spike 2 pre-findings) — no client references found. Consistent with Spike 2's
  finding that browse/search is done **directly against Solr**, not via Drupal endpoints. So
  these likely **do not need reproducing in D11** for the React app (confirm before deleting the
  requirement — other consumers, e.g. the WordPress plugin, are unaudited).
- The generic **AJAX endpoints** for non-Texts sites (`/api/ajax`, `/services/node/ajax`) — only
  the Texts embed path showed up in the client.

**Net:** the D11 API surface the React client actually needs is **`url_json` (all sites) +
`url_html` page resolution + `url_thumb` (done) + a Texts embed endpoint + `/…/user/current`** —
materially smaller and differently-shaped than the historical 8-endpoint matrix. This should
refocus the remaining spike work and the D11 implementation estimate.

### Client-side architecture + live WAF incident (2026-07-30)
See the **Pre-spike findings (2026-07-30)** section below — how `mandala-om` fetches
(Solr record → `url_json` → node JSON, all JSONP across 6 subdomains), and the confirmed
Sources WAF-503 incident + its same-origin `/proxy/json` mitigation.

## URL-strategy DECISION (2026-08-12, Than): Option A — generalize the same-origin proxy

**Decided.** The React app is embedded via WordPress on third-party hosting (`thlib.org` on
hosting.com today, potentially other WordPress sites in the future) that Mandala/D11 does not
control and never will. That rules out **Option C (same-origin serving)** outright, not just
deprioritizes it — there is no single origin to serve the app *and* the API from, because the
set of embedding sites isn't fixed or Mandala-owned. It also weakens **Option D** (ALB-aliased
subdomains) for the same reason the doc already flagged: it doesn't defeat a browser-targeted
WAF rule. That leaves **Option A, generalized to every app**, as the strategy — not a cutover
stopgap with a later migration to C.

This is the spike's headline deliverable. The findings below reframe the original pre-spike sketch
(now relabelled under "Reference: original pre-spike URL-strategy sketch") into a sharper
question. Two facts drove it:

- **`url_json` is a lever D11 already controls** — `mandala_kmassets_sync` writes the client's
  fetch URL per bundle (`__BASE_URL__/api/json/__NID__` today, a *placeholder* by the config's
  own admission). Changing the scheme + re-indexing changes what the client hits **without a
  client redeploy**.
- **The real obstacle is not CORS, it's the WAF** — the Sources incident was a browser
  cross-origin block (503 on JSONP), not a plain CORS-header problem. Any option that keeps a
  **browser cross-origin call** is exposed to the same D11 AWS WAF rule; options that make the
  call **same-origin** or **server-to-server** sidestep it.

| Option | Cross-origin call from browser? | Client change | WAF exposure | Notes |
|---|---|---|---|---|
| **A. Generalize `/proxy/json`** (same-origin WP proxy → D11 server-side) | **No** (same-origin to WP; proxy fetches server-side) | Small — extend the proven Sources pattern to all apps | **Avoided** | Already working for Sources; adds a proxy hop + couples to WordPress; owner/host TBD |
| **B. Native CORS on D11** + client JSONP→`fetch` | **Yes** | Larger — touches aging client (React 16) | **Exposed** — WAF must allow-list the browser cross-origin call (the exact thing that broke Sources) | Cleanest standards-wise; risk concentrated in WAF policy + client rewrite |
| **C. Same-origin serving** (React app served from the D11 origin / ALB path) | **No** (no cross-origin at all) | Large — changes the WordPress-embed model | **Avoided** | Architecturally cleanest end state; biggest structural change; may not fit `wp-kmaps` embedding |
| **D. ALB-aliased subdomains** (keep per-app hosts → single D11) | **Yes** (still cross-origin from the WP-embed origin) | None (if kmassets writes subdomain URLs) | **Exposed** — does not by itself defeat a browser-targeted WAF rule | Preserves D7 shape / zero client change, but doesn't solve the actual blocker |

**Decision: Option A, generalized to all apps, no Option C migration planned.**

> **Scope caveat added 2026-08-20:** "all apps" turns out to mean *all apps on the
> WordPress-embedded deployments*. The proxy is a WordPress plugin, and `REACT_APP_WP_PROXY` is
> configured in only 2 of `mandala-om`'s 11 env files — the standalone builds (including
> production `mandala.kmaps.virginia.edu`) have no `/proxy/json` to route through at all. The
> decision below isn't wrong, but its stated scope is broader than what it actually covers. See
> [option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md).

- Generalize the same-origin `/proxy/json` proxy to Images/AV/Texts/Visuals (today it's
  Sources-only in the client). Already proven in production for Sources, needs no D11 CORS/WAF
  allow-listing, and the server side is already generic (see Implementation reality below) — the
  remaining work is client-side.
- **Option C is off the table, not deferred.** It required the React app and the D11 API to
  share an origin. The app is embedded on arbitrary third-party WordPress installs Mandala
  doesn't control — there is no single origin to converge on. This also means there's no
  "eliminate the proxy hop later" follow-up: the proxy *is* the permanent architecture.
- **Option D rejected** — preserves the D7 URL shape but doesn't defeat a browser-targeted WAF
  rule, and is now moot since A is already proven and generalizing it is strictly less work.
- Option B (native CORS) is superseded by A for the same reason C is off the table: CORS only
  helps if the WAF allows the browser-side cross-origin call, and A already sidesteps needing
  that permission at all.

## Implementation reality (2026-08-12): the proxy is a separate plugin, and it's currently an open proxy

Locating the actual `/proxy/json` implementation behind the proven Sources fix took three
lookups — it is **not** in `wp-kmaps` (the app-embedding plugin) and **not** in `mandala-kadence`
(the display theme). It lives in its own repo: **[`shanti-uva/mandala-wp-proxy`](https://github.com/shanti-uva/mandala-wp-proxy)**,
a small standalone WordPress plugin (`mandala-proxy.php`, one file) that predates this spike.
It registers four proxy routes via `add_rewrite_rule`: `/proxy/wfs` (Geoserver), `/proxy/ttt`,
`/proxy/solr` (hardcoded to `texts.thdl.org`), and the general-purpose `/proxy/json` used by the
Sources fix.

**Good news for generalizing Option A:** the `json_proxy` handler is already fully generic —
`$base_url = $params['url']; wp_remote_get($base_url);` — it isn't hardcoded to Sources or any
single host. What's hardcoded today is only the **client** (`useMandala.js` in `mandala-om`
gates the proxy path on `query.includes('sources.mandala.library.virginia.edu')`). Generalizing
to all four remaining sites is a client-side change, not a server-side one.

**Client-side specifics, read directly from `mandala-om` `release/v1.1.0-rc` (2026-08-20)** —
the generalization is slightly more than widening the substring test:

- **The gate is a plain substring check** (`useMandala.js:28`), confirming the 2026-08-12
  characterization above.
- **It falls through, it does not switch.** If `REACT_APP_WP_PROXY` is unset or empty, the
  proxy branch is skipped and the code proceeds to the direct JSONP path (lines 29–35) — so an
  env misconfiguration silently reverts Sources to the exact fetch that 503s, with no error.
  Worth deciding whether that should fail loudly once the proxy is the architecture rather
  than a patch.
- **AV's `.jsonp` special case must go with it.** Lines 78–80 append `'p'` to `url_json` when
  `asset_type === 'audio-video'`, converting `.json` → `.jsonp`. Under Option A the proxy does
  a server-side fetch and wants plain JSON; leaving the append in place would hand the proxy a
  `text/javascript` callback-wrapped body (`mdldata({...})`) that won't parse as JSON.
- Scheme handling is round-tripped oddly but harmlessly — `useMandala.js:74` strips the scheme
  to make the URL protocol-relative, then `getMandalaAPI()` lines 19–21 re-add `https:`.

So the change is: widen the host gate, drop the AV `'p'` append, and decide the fall-through
behavior. The D11 endpoint (`mandala_node_api`) already serves plain JSON with no JSONP, which
is the correct target shape for all of this.

#### Client generalization IMPLEMENTED (2026-08-20) — `mandala-om` `feat/generalize-json-proxy-all-sites`

Commit `e6e712ae` (branch off `release/v1.1.0-rc`, **pushed; not merged, no PR**) makes the
change described above. Three decisions worth recording, because two of them are traps:

1. **Host matching uses `URL()` parsing, not a widened substring test.** Simply broadening
   `.includes('sources.mandala.library.virginia.edu')` to
   `.includes('mandala.library.virginia.edu')` would also match lookalike hosts such as
   `mandala.library.virginia.edu.attacker.com` — verified: that spoof matches the substring
   test and does **not** match the parsed-hostname test. `mandala-wp-proxy`'s server-side
   allowlist remains the real guard, but the client shouldn't be handing it attacker-controlled
   URLs. Checked against 12 cases (all five app subdomains, the bare domain, two spoofs,
   unrelated + malformed input); all pass.
2. **The AV `'p'` append moved into the direct-JSONP branch only.** Leaving it applied on the
   proxy path would have sent the proxy to `.jsonp`, which returns `text/javascript` wrapped as
   `mdldata({...})` and does not parse as JSON — an AV-only, silent regression. AV still needs
   the suffix on the direct path, since it serves JSONP from a path variant rather than a query
   parameter (see the JSONP correction above).
3. **The no-proxy fall-through was deliberately kept.** See the new deferred note —
   `REACT_APP_WP_PROXY` exists in only 2 of 11 env files, so falling through to direct JSONP is
   a supported configuration for the non-WordPress deployments, not an error to fail loudly on.
   An earlier framing of it as a possible bug was withdrawn.

**Verification is partial.** `node_modules` is not installed in that checkout, so the app test
suite and prettier could not be run; syntax was checked with `node --check` and the host matcher
was unit-tested standalone. The proxy and JSONP request paths themselves are **unexercised** —
this needs a real browser check against a tibet build before merge.

**⚠️ Scope limit this surfaced:** Option A only covers the WordPress-embedded deployments. See
[option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md).

#### Client generalization IMPLEMENTED (2026-08-20) — `mandala-om` `feat/generalize-json-proxy-all-sites`

Commit `e6e712ae` (branch off `release/v1.1.0-rc`, **pushed; not merged, no PR**) makes the
change described above. Three decisions worth recording, because two of them are traps:

1. **Host matching uses `URL()` parsing, not a widened substring test.** Simply broadening
   `.includes('sources.mandala.library.virginia.edu')` to
   `.includes('mandala.library.virginia.edu')` would also match lookalike hosts such as
   `mandala.library.virginia.edu.attacker.com` — verified: that spoof matches the substring
   test and does **not** match the parsed-hostname test. `mandala-wp-proxy`'s server-side
   allowlist remains the real guard, but the client shouldn't be handing it attacker-controlled
   URLs.

   **Revised same day (commit `d83fa707`): the host set is now derived from the
   `REACT_APP_DRUPAL_*` env vars, not hardcoded.** The first version hardcoded the production
   domain, mirroring the wp-proxy allowlist. That is correct for production and wrong
   everywhere else — dev's Drupal hosts are `*-dev.internal.lib.virginia.edu`, so the proxy
   branch **could never fire in dev under any configuration**, which also made the change
   untestable there; and it would break again at D11 cutover when the consolidated host
   changes. Each `.env.*` file already enumerates its own deployment's Drupal hosts, so
   deriving from them makes the gate correct across dev/staging/prod and survives cutover with
   no code edit. Exact-hostname comparison is retained, so the spoof case stays blocked;
   non-URL vars (`REACT_APP_DRUPAL_SOURCES_API`, a path template) drop out of the `URL()`
   parse, and `REACT_APP_WP_PROXY` isn't a `DRUPAL_` var so the proxy can never self-proxy.
   **13 cases pass** across simulated production and dev env sets, including the
   previously-impossible dev match. Confirmed empirically that CRA inlines `process.env` as a
   whole object literal (the dev hostname appears in the emitted bundle), so enumerating it is
   build-time static rather than a runtime lookup.
2. **The AV `'p'` append moved into the direct-JSONP branch only.** Leaving it applied on the
   proxy path would have sent the proxy to `.jsonp`, which returns `text/javascript` wrapped as
   `mdldata({...})` and does not parse as JSON — an AV-only, silent regression. AV still needs
   the suffix on the direct path, since it serves JSONP from a path variant rather than a query
   parameter (see the JSONP correction above).
3. **The no-proxy fall-through was deliberately kept.** See the new deferred note —
   `REACT_APP_WP_PROXY` exists in only 2 of 11 env files, so falling through to direct JSONP is
   a supported configuration for the non-WordPress deployments, not an error to fail loudly on.
   An earlier framing of it as a possible bug was withdrawn.

**Verification status (updated after actually installing and building).** The app now builds:
`npm run-script build` against `.env.development` exits **0, "Compiled with warnings"** (188
files carry pre-existing lint warnings; the two in `useMandala.js` — an unused `GetSessionID`
import and a `==` on line 86 — are pre-existing code this change preserved). Husky's
prettier/lint-staged pre-commit hook passes. Host matching is unit-tested (13 cases).

**✅ VERIFIED END TO END IN A BROWSER (2026-08-20).** Chrome, against production Solr +
production Drupal hosts via an untracked `.env.development.local`, with the DDEV WordPress
running. The network log captures the entire story in one sequence:

| Request | Status | Meaning |
|---|---|---|
| direct JSONP → `sources.mandala.library.virginia.edu/sources-api/json/25581?callback=…` | **503** | the 2026-07-29 WAF bug, **reproduced live** |
| `localhost:3000/proxy/json/?url=…` | **404** | the generalized gate works (proxy path taken) but `setupProxy.js` was broken |
| `localhost:3000/proxy/json/?url=…` | **200** | after the `setupProxy.js` fix — same-origin, 4,677 bytes of real JSON |

The Sources detail page then renders its full record (title, journal, format, pages, year,
record creator, visibility, complete abstract) where it previously showed a blank body. **No
cross-origin request to the Sources host remains.** This is the first time the same-origin proxy
path has been observed working from a browser on any codebase.

**Two findings that fell out of doing this:**

1. **The WAF block is real and current — and curl cannot reproduce it.** A curl replay carrying
   full browser headers (`Origin`, `Referer`, `Sec-Fetch-Dest: script`, `Sec-Fetch-Mode:
   no-cors`, `Sec-Fetch-Site: cross-site`, Chrome UA) returned **200**, while the actual browser
   got **503** for the same URL. The rule keys on something header-spoofing can't fake (TLS/JA3
   fingerprint, header ordering, or similar). **Any future "is the WAF blocking?" check must use
   a real browser** — a curl result is not evidence either way. An intermediate diagnosis in this
   session (a content-type/ORB theory) was built on exactly that mistaken curl evidence and was
   wrong.
2. **`setupProxy.js` had never worked** — see
   [the mandala-om fix](https://github.com/shanti-uva/mandala-om) commit `eeefb203`. Express
   strips the `/proxy` mount path before `http-proxy-middleware` (v3.0.5) sees it, so the
   existing `pathRewrite: {'^/proxy': '/proxy'}` could never match — the prefix was already gone.
   Requests reached WordPress as `/json/…` and 404'd. Proven by deliberately doubling the prefix
   (`/proxy/proxy/json/…` → 200). **This is why the July 2026 Sources fix was only ever verified
   against production** — local verification was impossible, and nobody had reason to suspect the
   dev tooling rather than the feature.

**Local testing must point at PRODUCTION, and cannot point at staging** (decided 2026-08-20,
Than — documented in the untracked `.env.development.local`):

- **Not dev.** The `*-dev.internal.lib.virginia.edu` hostnames have been **taken over by the
  new D11 site**, so the D7 endpoints no longer exist there. The dev kmassets index still
  advertises `url_json` values aimed at those now-D11 hosts, so a dev asset-detail page can
  never render. **Expected fallout of the takeover, not a defect** — an earlier draft of this
  session's notes wrongly treated it as breakage.
- **Not staging, even though staging still serves the D7 endpoints**
  (`mandala-sources-staging.internal.lib.virginia.edu/sources-api/json/62716` works). The URL
  actually fetched is **`url_json`, a field stored in the Solr record** — it is *not* derived
  from `REACT_APP_DRUPAL_*`. Repointing to staging therefore needs a staging kmassets index
  whose `url_json` carries staging hosts, and none is reachable (`mandala-index-staging` and
  `mandala-solr-proxy-staging` both fail to connect). Changing the `DRUPAL_*` vars only changes
  which hosts the proxy gate treats as eligible, not which host is fetched.
- **So: production.** Read-only `select` queries and GETs of public records — no write risk,
  negligible load — but local dev is exercising live data, which anyone repeating this should
  know.
- **Test ids are environment-specific.** `62716` resolves on both staging and production;
  `137238` resolves on production but **not** staging (older snapshot). Do not carry an id from
  one index to another.

**Remaining dev-environment limitation** (unrelated to the above, still true): the dev Solr
index's `url_json` values point at
`mandala-sources-dev.internal.lib.virginia.edu`, which serves **no JSON API at all** —
`/sources-api/json/{nid}`, `/api/json/{nid}`, `/node/{nid}` and `/jsonapi` all return 404, and
`/` returns Drupal 10/11 chrome. That host is not the D7 Sources site the dev index assumes.
So a dev Sources page renders blank regardless of this change — a **pre-existing dev-data
problem**, not a regression. Testing the routing locally additionally needs the DDEV WordPress
(`thlddev.ddev.site`, currently down) and `REACT_APP_WP_PROXY` set in `.env.development`; even
then it would only confirm the request *routes* through the proxy, with a 404 payload. A real
end-to-end render needs a `url_json` that resolves — production, or a live D7 Sources instance.

**Method note for whoever verifies next:** do **not** judge a CRA build by artifact presence.
CRA emits `build/` *before* the `CI=true` lint gate runs, so fresh artifacts appear even on a
failed build — this misled an earlier check in this session into reporting success. Read the
exit code of the unpiped build (a piped exit code is the pipe's), or grep for
`Compiled successfully` / `Compiled with warnings` / `Failed to compile`. Note also that
`CI=true` escalates this repo's 188 files of pre-existing warnings into hard errors, so it is
the wrong flag for a smoke test here.

**⚠️ Scope limit this surfaced:** Option A only covers the WordPress-embedded deployments. See
[option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md).

#### D7 vs D11 delineation for `mandala-om` work (raised by Than, 2026-08-20)

`mandala-om` work needs to be kept clear about which environment it targets. `release/v1.1.0-rc`
is a **D7 branch**, and `feat/generalize-json-proxy-all-sites` branches from it. Assessment of
that branch:

| Change | D7 / D11 |
|---|---|
| `setupProxy.js` fix | **Neither** — local dev tooling, not shipped in a build |
| `package.json` / lockfile | **Neither** — no D7/D11 semantics |
| Host gate derived from `REACT_APP_DRUPAL_*` | **Agnostic by construction** — follows whatever hosts the build's env declares; this is precisely why the hardcoded production domain was replaced |
| Proxy branch (passes `url_json` through) | **Agnostic** — verified working against D7 production Sources |
| AV `.jsonp` append in the direct-JSONP fallback | **D7-specific** |

So the branch is **D7-compatible and D11-forward-compatible**, with one exception worth tracking:
D7's AV serves JSONP from a `.jsonp` path variant, so the `'p'` append is required there, while
D11's `mandala_node_api` deliberately serves **no JSONP at all**. That line is D7-only logic. It
is harmless under Option A in D11 (everything goes through the proxy, so the fallback isn't
taken), but it needs a D11-side decision whenever the client is pointed at D11.

**Note:** there is **no D11 branch in `mandala-om`** among the branches visible as of
2026-08-20 (`master`, `release/v1.1.0-rc`, several `*/release/v1.1.0-rc` feature branches,
`chore/react-upgrade`, dependabot branches). The D7/D11 fork hasn't happened in that repo yet.

**Policy confirmed by Than, 2026-08-21 — the client is modifiable, and that is not a spike
risk.** `mandala-om` is Mandala's own repo; the constraint is *not* "the app can't be changed"
(an old fail-criteria assumption, now retired below) but "changes must not break the running D7
integration." So D11-targeted client work is done on **a dedicated branch or fork**, cut when
the cutover work starts, rather than by conditionally reshaping `release/v1.1.0-rc`. Two
consequences for this spike:

- **Option A's client-side cost is real but bounded and takeable.** Widening the proxy gate,
  and later dropping the D7-only AV `.jsonp` append, are ordinary changes on a D11 branch — not
  blocked work.
- **Option B (native CORS) was never rejected for being unchangeable-client**, only for WAF
  exposure. Retiring this premise does not reopen it.

**Untested paths in that branch:** only the Sources detail page was exercised. **AV, Images,
Texts and Visuals detail pages were not tested**, and specifically the AV direct-JSONP fallback
— the exact code that was moved — has never been run.

**✅ FIXED (2026-08-12).** `json_proxy` was an open proxy (SSRF risk) — any `url` param, fetched
server-side with no host restriction. **Host allowlist + `X-Content-Type-Options: nosniff` added
and pushed to `shanti-uva/mandala-wp-proxy`'s `main`** (tagged `v1.0.0` pre-fix for rollback).
Verified `php -l` clean and unit-tested the allowlist against 8 cases (legit hosts, spoofed
subdomain suffix, the `169.254.169.254` cloud-metadata SSRF target, `file://`, malformed input).
Full detail — see
[mandala-wp-proxy-json-proxy-open-ssrf.md](../deferred/mandala-wp-proxy-json-proxy-open-ssrf.md).

**Merge-vs-separate decision (2026-08-12, Than): keep `mandala-wp-proxy` as its own plugin.**
Considered folding it into `wp-kmaps` for discoverability (three lookups to find it is itself a
signal), but rejected: (1) it isn't Mandala-specific — it already proxies Geoserver/WFS and a
THDL Solr endpoint unrelated to KMaps, so merging would misscope a general-purpose CORS proxy
into an app-embedding plugin; (2) once hardened with an allowlist it's a security-sensitive
component that benefits from its own release/review cycle, independent of `wp-kmaps`'s UI churn.
Instead: declare the dependency explicitly via a WordPress `Requires Plugins` header on
`wp-kmaps` (so sites can't activate it without the proxy present) plus README documentation —
fixes the actual problem (undiscoverable dependency) without the coupling cost of a merge.
Tracked: [wp-kmaps-mandala-proxy-dependency.md](../deferred/wp-kmaps-mandala-proxy-dependency.md).

### D11 node-JSON endpoint for Images (2026-08-12) — closes the "concrete gap" above

New module `mandala_node_api` (`drupal/web/modules/custom/mandala_node_api/`) serves
`GET /api/json/{node}` — the exact path `mandala_kmassets_sync.settings.yml` already declares
for `shanti_image`'s `url_json`, which until now resolved to nothing (see the "concrete gap"
finding above). Only `shanti_image` is handled (404 for any other bundle) since Images is the
only migrated site; Sources/Texts/AV each need their own controller when they migrate, per the
D7 audit's finding that the four sites' response shapes aren't uniform.

**Design choices:**
- **Access is not reimplemented** — the route declares `_entity_access: 'node.view'`, so
  `mandala_group_inheritance_entity_access()`'s existing private-collection gating (ADR 011)
  applies automatically. No bespoke `shanti_general_api_check()`-equivalent was written.
- **No JSONP, no CORS headers.** Both existed in D7 solely to support direct browser
  cross-origin fetches. Under the decided Option A architecture, the client always fetches
  through `mandala-wp-proxy`'s same-origin `/proxy/json`, which does a server-to-server fetch —
  CORS doesn't apply to server-to-server HTTP, and the client parses the response as plain JSON
  (`axios.get`), never as executed script. Implementing either would be dead code for a
  requirement Option A specifically eliminated.
- **Response shape is curated, not a raw-node-dump port of D7's `shanti_images_node_json()`.**
  D7 returned the *entire* raw node object, including internal fields (`field_admin_notes`,
  `field_private_note`). This deliberately excludes those and instead returns a shaped object
  (image/IIIF geometry, descriptions, agents, owning collection, KMaps term references,
  technical/rights field groups) built by reusing the same field-extraction logic as
  `ImageFieldContributor`/`CollectionFieldContributor` (the kmassets Solr-doc builder) so the
  detail JSON and the Solr-indexed thumb URL stay consistent. **This shape has not been
  validated against the live React client's actual rendering code** — Spike 6's client audit
  confirmed *that* `url_json` gets fetched, not which fields the detail view reads out of it.
  Treat it as a reasonable first cut, not a finished contract, before the same pattern is reused
  for Sources/Texts/AV.

**Verified live in DDEV against real migrated data** (111,343 `shanti_image` nodes), not just
`php -l`: a public node returns 200 with the expected shaped JSON (descriptions, agents,
collection, KMaps terms with domain/id/header/path, technical/rights fields all populated
correctly); a node in a private collection correctly returns 403 (`node->access()` denied it via
the real group-membership check, not a stub); a nonexistent nid and a non-numeric nid both
return 404; response headers show `Cache-Control: private, must-revalidate, no-cache` (the
per-user cache context is real, not just declared) and `X-Content-Type-Options: nosniff`.

## What this does NOT establish
- **Whether the browse-by-KMap and generic AJAX endpoints have any remaining consumer.** The
  React client does **not** use them (scoping audit above), but the **WordPress `wp-kmaps`
  plugin and any server-side consumers are unaudited** — confirm before dropping them from the
  D11 requirement. Their exact D7 response shapes were not documented (deprioritized as likely
  out of scope for the React app).
- **⚠️ Field-coverage caveat on every endpoint contract in this spike** — empty fields are
  omitted from these responses, so the sampled field inventories are lower bounds, not complete
  lists. See "Open consideration: empty fields are omitted" above. Recorded 2026-08-21, no
  approach chosen.
- **AJAX/embed audit — DONE 2026-08-21, all four sites browser-verified.** See "AJAX / embed
  endpoint audit". Six routes documented, not the four the matrix listed. Note for future work:
  `curl` cannot reach any of these (the edge bot-challenge returns `202`/empty for every
  HTML-typed response) — use a browser.
- **The Texts embed endpoint** (`node_embed`, reached via `url_ajax`) and the
  **`/general/api/user/current`** endpoint are identified as in-scope but **not yet audited**
  for response shape / D11 approach.
- **URL strategy is decided (2026-08-12): Option A, generalized.** What remains is
  implementation: generalize the client's Sources-only proxy gate to all sites, harden
  `mandala-wp-proxy`'s open `json_proxy` route with a host allowlist, and wire the
  `wp-kmaps`↔`mandala-wp-proxy` dependency declaration.
- **D11 endpoint exists and is verified for Images only** (`mandala_node_api`, see above).
  Sources' bespoke-flat-doc shape, AV's augmented-raw-node shape (corrected 2026-08-20 — see
  above), and Texts' embedded-Views-HTML shape each still need their own controller when that
  site migrates — none of that work has started, and this endpoint's shape is not yet confirmed
  against the live React client's actual field usage (see the caveat above).
- **The D11 single-site URL path scheme is still formally deferred for the *unmigrated* sites**
  — Sources/Texts/AV bundles have no `mandala_kmassets_sync.settings.yml` entry yet at all
  (`shanti_image` is the only bundle configured). Images' scheme (`/api/json/{nid}`) is now real
  and live, not just a placeholder, but the other three sites' path scheme is still an open
  choice for whenever they migrate.
- Whether node IDs are preserved across migration (a Fail-Criteria risk) — assumed via
  `field_legacy_nid`, not yet confirmed against the client's `url_json` values end-to-end. The
  new endpoint does surface `legacy_nid` in its response, which makes that end-to-end check
  possible now but it hasn't been run.

## Deferred notes
- [mandala-wp-proxy-json-proxy-open-ssrf.md](../deferred/mandala-wp-proxy-json-proxy-open-ssrf.md) —
  the open-proxy/SSRF finding in `json_proxy`, blocking generalized rollout
- [wp-kmaps-mandala-proxy-dependency.md](../deferred/wp-kmaps-mandala-proxy-dependency.md) —
  the plugin-dependency declaration needed since the two plugins stay separate repos
- [option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md) —
  Option A's proxy is a WordPress plugin, absent from 9 of `mandala-om`'s 11 deployment configs
- ~~Still to file: the client-side change to generalize the proxy gate beyond Sources.~~
  **Done 2026-08-20** (`mandala-om` `feat/generalize-json-proxy-all-sites`, unmerged). (A prior
  version of this bullet also flagged an AV `/api/v1/media/node` server-rewrite requirement for
  Terraform/ALB config — dropped 2026-08-20: live evidence showed no rewrite is needed, it's a
  normal Drupal route like Images'.)

---

## Reference: Pass Criteria
- All eight D7 API response formats are fully documented
- A URL strategy is agreed upon between Drupal and React teams (decided: **Option A** — see
  "URL-strategy DECISION" above; the lettering there is authoritative)
- The agreed strategy is technically feasible in D11 and in Terraform ALB config
- The D11 API implementation approach is clear per endpoint

## Reference: API Endpoints to Document
| Site | JSON API | AJAX API |
|------|----------|----------|
| AV | `/api/v1/media/node/{nid}.json` | `/services/node/ajax/{nid}` |
| Images | `/api/json/{nid}` | `/api/ajax/{nid}` |
| Sources | `/sources-api/json/{nid}` | `/sources-api/ajax/{nid}` |
| Texts | `/shanti_texts/node_json/{nid}` | `/shanti_texts/node_embed/{nid}` |

## Reference: original pre-spike URL-strategy sketch (SUPERSEDED)

> **Superseded by the "URL-strategy DECISION" section above, which re-lettered the options.**
> Kept for provenance only. **Do not cite these letters** — "Option A" elsewhere in this doc and
> in the deferred notes always means the decision section's Option A (generalize the same-origin
> proxy). Named, not lettered, to avoid the collision:
>
> - **Single domain, same paths** — React app updated to use the new domain.
> - **ALB-aliased subdomains** — old subdomains kept as ALB aliases to the single D11 instance,
>   no React changes. *This is the only one that carried forward: it is **Option D** in the
>   decision table, where it was rejected for not defeating a browser-targeted WAF rule.*
> - **301 redirects** from old subdomain paths — may break React depending on redirect handling.
>
> The sketch predates the 2026-07-29 WAF incident, which is why none of its three options
> addresses a browser cross-origin block.

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| ~~React app cannot be changed~~ **Premise retired (Than, 2026-08-21)** | **This row no longer applies.** The React app *can* be changed — `mandala-om` is Mandala's own repo and is already being modified on `feat/generalize-json-proxy-all-sites`. The real constraint is narrower: changes must not break the client's **current interaction with the live D7 site**, so D11-targeted work goes on a separate branch or fork, cut when the cutover work actually starts. See "D7 vs D11 delineation" above. (The original remedy here prescribed ALB-aliased subdomains — **Option D** — which the 2026-08-12 decision rejected as insufficient against a browser-targeted WAF rule, so it was never a usable fallback anyway.) |
| Expensive computed fields in D7 response | Design caching strategy before implementing |
| Node IDs change during migration | Implement nid mapping table; update API to accept old or new nid |
| API response structure inconsistent across nodes | Document exceptions; handle in D11 controller logic |

## Pre-spike findings from Spike 2 (Solr integration)

Spike 2 examined D7's search and API layer in detail. Key findings that bear directly on this spike:

### D7 has no Drupal-level free-text search endpoint

Text search in D7 is entirely **client-side**: the browser (or React app) calls the
Solr proxy directly using a weighted multi-field query built in `jquery.kmapsSolr.js`.
There is no Drupal controller or REST endpoint that accepts a keyword and returns ranked
asset results. D11's corrected Solarium query (the D7-equivalent weighted query with
prefix wildcards) produces results that match D7's text search output exactly, because
they are the same query against the same index.

This means there is no text-search API endpoint to replicate in D11 — the React app
queries Solr directly and will continue to do so.

### D7's Drupal-level API is browse-by-KMap, not text search

The actual Drupal API endpoints in D7 are KMap-term-scoped browse endpoints:

| Module | Endpoint | Returns |
|--------|----------|---------|
| `mb_services` | `/services/subject/{kmap_id}` | A/V assets tagged with that subject |
| `mb_services` | `/services/place/{kmap_id}` | A/V assets tagged with that place |
| `shanti_general` | `/general/api/subjectsimages/{kmap_id}` | Images for a KMap subject |
| `shanti_general` | `/general/api/placesimages/{kmap_id}` | Images for a KMap place |
| `shanti_general` | `/general/api/termsimages/{kmap_id}` | Images for a KMap term |

These issue a Solr query of the form `fq=kmapid:{domain}-{id}` against the kmassets
index, filtered by `asset_type`. They return paginated JSON.

**Implication for Spike 6:** D11 needs equivalent endpoints. The Solr query is
straightforward (already proven in Spike 2). The open question is URL strategy — whether
D11 keeps the same paths, redirects, or updates the React app to use new paths.

### The per-site node API endpoints (the table above in Pass Criteria) are separate

The per-site endpoints (`/api/v1/media/node/{nid}.json`, `/api/json/{nid}`, etc.) are
individual-asset detail endpoints — not search or browse. These are distinct from the
browse-by-KMap endpoints and likely handled by different D7 modules (shivanode,
shivadata, etc.). Spike 6 should audit those separately.

### Working reference implementation

The comparison page at `/spike/solr-comparison` on the D11 dev site demonstrates
corrected D11 text search (D7-equivalent weighted query via raw Solarium) matching D7
results. The controller at
`drupal/web/modules/custom/spike_solr_demo/src/Controller/SpikeComparisonController.php`
is a working reference for raw Solarium queries and native Solr field access, reusable
for building the browse-by-KMap endpoints.

---

## Pre-spike findings (2026-07-30): client API architecture + the WAF/proxy problem

Reviewed the React client (`mandala-om`, branch `release/v1.1.0-rc`) to map how it
actually consumes the mandala APIs, prompted by a **live production incident
(2026-07-29)** that already exercises the exact compatibility risk this spike exists
to address.

### How the React client fetches asset data (two steps)

Every asset-detail view does two calls:

1. **Solr** (`useKmap` / `useAsset`, `kmaps-app/src/hooks/`) — query the kmassets
   index (via the solr-proxy) for the asset's record.
2. **Node JSON** (`useMandala`, `kmaps-app/src/hooks/useMandala.js`) — read the
   **`url_json` field stored on that Solr record** and fetch it for the full Drupal
   node JSON. (AV special-case: append `p` so `.json` → `.jsonp`.)

**Load-bearing fact: the node-JSON endpoint URL is not hardcoded in the client — it
is data, carried per-record in the Solr `url_json` field.** The URL the browser hits
is therefore controlled by *what the kmassets sync writes into `url_json`*, which
couples this spike to the kmassets write path (Sprint 1a.8 / reindeer_x,
[ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) /
[ADR 007](../adr/007-reindeer-x-independent-service.md)), not just to ALB routing.

### Everything is JSONP, across six subdomains

The node-JSON fetch uses **JSONP** — a cross-origin `<script>` injection with a
callback param (`callback` in `useMandala`; `json_wrf` / `json.wrf` in
`kmaps-app/src/logic/assetapi.js`) — purely to dodge CORS, because the app (embedded
by the `wp-kmaps` WordPress plugin at `…/mandala`) fetches from **six distinct D7
subdomains** (`REACT_APP_DRUPAL_*` env vars):

| App | Production host |
|---|---|
| Home / places / subjects / terms | `mandala.library.virginia.edu` |
| AV | `av.mandala.library.virginia.edu` |
| Images | `images.mandala.library.virginia.edu` |
| Sources | `sources.mandala.library.virginia.edu` |
| Texts | `texts.mandala.library.virginia.edu` |
| Visuals | `visuals.mandala.library.virginia.edu` |

The D11 consolidation collapses all of these into one instance — so the client's env
hosts *and* every Solr `url_json` value need a coherent story at cutover.

### CONFIRMED incident (mandala-om commit `6a2ef22b`, 2026-07-29): WAF 503 on browser JSONP

`sources.mandala.library.virginia.edu` returns **HTTP 503 to cross-origin browser
JSONP requests** — an edge/WAF block that **`curl` and the WordPress server itself do
NOT hit**. Effect: Sources detail pages rendered the title (from Solr) but a **blank
body** (the `url_json` fetch was 503'd). The block is browser-cross-origin-specific —
i.e. keyed on `Origin` / `Referer` / `Sec-Fetch-*` / bot heuristics, exactly the class
of rule the new D11 AWS WAF will also enforce.

### The mitigation (commits `6a2ef22b`, `27a21c63`): same-origin server-side proxy

Fixed by routing Sources body fetches through a **WordPress server-side proxy**:
`{REACT_APP_WP_PROXY}/json/?url=<encoded target>` via a plain `axios.get` (not JSONP),
so the browser makes a **same-origin** request and the proxy performs the cross-origin
fetch server-side (not subject to the browser-targeted WAF rule). Verified:
`#/sources/127668` body fetch → `/proxy/json/?url=…/sources-api/json/127668` → 200.
Related config: `REACT_APP_JSON_PROXY=/proxy/json?url=` (same-origin relative rule),
and local dev `kmaps-app/src/setupProxy.js` proxies `/proxy/*` to DDEV WordPress.
**Currently scoped to the Sources host only** — images / AV / texts / visuals still use
direct JSONP and are one WAF-config change away from the same 503.

### Implications for this spike (new / updated criteria)

1. **The WAF/JSONP failure is live, not hypothetical.** The Fail-Criteria rows "React
   app cannot be changed" and (implicitly) a strict edge/WAF are already triggered in
   production for Sources; the D11 AWS WAF makes every app a candidate post-consolidation.
2. **Evaluate generalizing the same-origin proxy to all asset JSON** as the primary
   URL strategy — one same-origin `/proxy/json?url=<D11 endpoint>` call sidesteps CORS +
   JSONP + WAF in one move and is already proven for Sources. This is more concrete than
   the abstract pre-spike sketch above and reframes the choice as **proxy-everything vs.
   move the client to native `fetch` + CORS**.
3. **WAF must explicitly allow the server-to-server fetch path** — the fix works
   precisely because server-side requests bypass the browser rule. Add this to the
   "feasible in D11 + Terraform ALB/WAF config" pass criterion.
4. **`url_json` is a second lever.** D11 controls the client's API URL by what the
   kmassets sync writes into `url_json`, so the cutover strategy is a joint decision
   with the kmassets write path — and can avoid a client redeploy if the sync writes
   D11 URLs.
5. **Sources is the canary** (broke first, patched first) and is *also* the
   [Spike 5](spike-05-bibcite-sources.md) bibcite target — coordinate the two.
6. **The client is aging** (React 16, `react-scripts` 3.4.3, Node 14 in CI, a
   Dependabot backlog per `mandala-om` README). Any data-layer change (proxy-everything
   or JSONP→CORS) lands in old code — a cost input for the Phase 3 cutover.
### Key decision this spike must make: is `/proxy/json` the final solution?

The Sources fix works, but it was a **targeted stopgap**, not a chosen architecture.
This spike must **explicitly decide whether the `/proxy/json` same-origin proxy is the
final D11 answer, or whether a better solution exists** — and record the rationale.
Candidate alternatives to weigh against "generalize `/proxy/json` to all apps":

- **Native CORS on D11** — D11 sets `Access-Control-Allow-Origin` for the app origin(s)
  and the client moves from JSONP to plain `fetch`; no proxy tier, but touches the aging
  client and depends on the WAF allowing the browser cross-origin call.
- **Same-origin serving** — if the React app ends up served from the same origin as the
  D11 API (or an ALB path on it), there is no cross-origin call at all (no proxy, no CORS).
- **ALB-aliased subdomains** (the pre-spike sketch's second option; **Option D** in the
  decision table) — keep per-app hostnames as ALB
  aliases to the single D11 instance; may not by itself defeat a browser-cross-origin
  WAF rule.
- **A dedicated proxy service** (vs. the WordPress plugin) — if a proxy tier is chosen,
  decide where it lives (WordPress plugin, a small standalone service, or the D11
  app itself) and how it is deployed/owned on AWS.

Deliverable: a recommended API-reachability architecture with the WAF, CORS, ALB, and
`url_json` implications spelled out — not just "the Sources proxy works."

**Source refs** (`mandala-om`, branch `release/v1.1.0-rc`):
`kmaps-app/src/hooks/useMandala.js`, `kmaps-app/src/logic/assetapi.js`,
`kmaps-app/README.md` (architecture); commits `6a2ef22b` (Sources 503 → `/proxy/json`),
`27a21c63` (`REACT_APP_WP_PROXY` env split from the geoserver var).

---

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-6)*
