# ADR 016: D11 public URL structure — one host, asset-type-namespaced paths; legacy URLs redirected via `field_legacy_nid`

**Status:** Proposed
**Date:** 2026-08-25 (proposed)
**Deciders:** Yuji Shinozaki (Lead Architect) — path grammar and redirect layer decided 2026-08-25; three items open below
**Corrected by:** Than Grove (2026-08-25) — real D7 URL forms; see the correction note under decision 2
**Relates to:** [ADR 005](005-single-site.md) (single-site redesign), [ADR 008](008-mvp-migrate-not-improve.md) (migrate, not improve), [ADR 010](010-adr-008-scope-clarification.md) (scope clarification), [ADR 013](013-drupal-source-of-truth-solr-client-compatibility.md) (Drupal is source of truth; client compatibility is active)
**Implements:** ADR 005's stated consequence — *"URL paths for each collection must be designed to preserve existing external links where possible (e.g. `/texts/...`, `/images/...`)"*

## Context

ADR 005 consolidated the legacy per-service D7 apps into a single Drupal 11 site, and
said collection identity would be expressed through *"content types, taxonomies, and URL
structure rather than separate Drupal instances."* The URL half of that was never
designed. `mandala_kmassets_sync`'s own config comment still records the D11 public URL
scheme as *"a deferred decision."*

That gap is no longer neutral. Five things force it now:

**1. The committed URLs are wrong in two independent ways.** `config/sync`'s
`mandala_kmassets_sync.settings.yml` hardcodes
`https://images.mandala.library.virginia.edu/image/__NID__`.

- *Wrong host and id space:* the host is the live D7 production site, but the substituted
  `__NID__` is the **D11** nid, and the two nid spaces differ (the migration assigns fresh
  nids and preserves D7's separately in `field_legacy_nid`).
- *Wrong path shape:* browser-verified below, **`/image/{nid}` 404s on D7 regardless of
  which nid is used** — that route takes a slug, not an id. So the template could not
  produce a working URL even with the host and id space corrected.

All 111,340 docs indexed on 2026-08-13 carry this.

**2. `base_url` is inert.** The module's install default uses the token form
(`__BASE_URL__/image/__NID__`), but the exported `config/sync` replaced the token with
absolute per-service hosts. `base_url` therefore substitutes nothing, and setting it
per-environment — as the 1a.9 acceptance checklist asks — changes nothing. A single
`base_url` also *cannot* express per-service subdomains, which is presumably why the
export hardcoded them.

**3. The single site makes the subdomain unnecessary.** One database means one globally
unique nid space, so a per-service host is no longer needed to disambiguate an id.

**4. D11 has already built a flat API route.** `mandala_node_api` declares
`/api/json/{node}`, live and browser-verified for Images in [Spike 6](../spikes/spike-06-api-compatibility.md).

**5. `url_html` is a client contract with a reverse dependency.** Spike 6 established that
`url_html` is used not only for full-page links (`FeatureCard`, `TextsViewer`,
`SourcesViewer`, legacy `searchui`) but for a **reverse Solr lookup** —
`MandalaMarkup.js` queries `q: url_html:"…"` to find an asset *by its page URL*. Changing
the emitted value breaks that lookup unless `mandala-om` changes in step.

## Decision

**1. One host.** Every collection is served from a single public host. The legacy
per-service hosts become redirect sources, not origins.

**2. Asset pages are namespaced by asset type:**

```
/images/{nid}
/texts/{nid}
/sources/{nid}
/audio/{nid}
/video/{nid}
```

This matches ADR 005's own sketch.

> **Correction, 2026-08-25 (Than Grove).** An earlier draft of this ADR claimed the
> grammar "preserves D7's path component exactly." **It does not.** Real D7 URLs take the
> forms `…/image/village-and-houses-2` (singular `image`, **slug alias**) and
> `…/node/1631632` (core canonical). There is no `/images/{nid}` form; that claim came
> from a second-hand description and was never verified. The destination grammar above
> stands on its own merits — it is a fresh design, not a preservation — but the
> "identical path" argument for it was wrong and the redirect source set is different and
> larger than this ADR first assumed. See open item 2.
>
> **Verified in a real browser, 2026-08-25.** Neither `curl` nor an in-page `fetch()` can
> establish anything here — both get `202` with a zero-byte body for every path, real or
> bogus alike (the [Spike 6](../spikes/spike-06-api-compatibility.md) trap; the edge
> discriminates document navigations from subresource requests). Real navigations, with a
> deliberately bogus slug as the control:
>
> | Path | Result |
> |---|---|
> | `/image/village-and-houses-2` | ✅ "Village and houses" |
> | `/node/1631632` | ✅ "Village and houses" — same node, **not** redirected to the alias |
> | `/images/village-and-houses-2` | ❌ 404 — the plural form does not exist |
> | `/image/1631632` | ❌ **404** |
> | `/images/1631632` | ❌ 404 |
> | `/image/definitely-not-a-real-slug-zzz` | ❌ 404 *(control)* |
>
> So D7's human URL is **`/image/{slug}` — singular, slug only.** The plural `/images/…`
> 404s, and `{nid}` is accepted only on `/node/`.

**3. Audio and video are separate in the URL, deliberately, even though the Solr
`asset_type` facet is a single `audio-video` value** (11,537 production docs). The URL
grammar keys on the **D11 content type**, not on the kmassets facet. These two will
therefore not match, and that is intended — *do not "align" them.* Anyone reconciling the
Solr contract against the URL scheme should read this clause first.

**4. The JSON API stays flat and un-namespaced:** `/api/json/{nid}`. It is already built,
already browser-verified, and nid uniqueness makes a namespace redundant. This keeps the
Spike 6 endpoint and the `mandala-om` JSON-proxy work ([mandala-om #79](https://github.com/shanti-uva/mandala-om/pull/79))
untouched.

**5. `mandala_kmassets_sync` URL templates return to the `__BASE_URL__` token form**, so
`base_url` becomes the real per-environment control and dev/staging stop emitting
production URLs.

**6. Legacy redirects are handled in Drupal**, by the contrib `redirect` module keyed on
`field_legacy_nid`, generated at migration time. An edge-only rewrite was rejected because
an ALB/DNS rule can move host and path prefix but **cannot translate a D7 nid to a D11
nid** — deep links would silently resolve to the wrong asset. The translation has to
happen where the mapping data lives. `field_legacy_nid` is already migrated on
`shanti_image` and both Group types, so the join key exists; nothing consumes it yet.
Neither `redirect` nor `pathauto` is currently installed (`core.extension` carries only
core `path`/`path_alias`), so both the module and the generation step are new work.

### The two real redirect source forms

Than's evidence gives two D7 URL shapes to redirect, with **different** requirements:

| D7 form | Example | What the redirect needs |
|---|---|---|
| **Slug alias** — the bookmarked form | `/image/village-and-houses-2` | The *slug*, not the nid. No id translation — **but D11 has no slugs at all** (see below) |
| **Core canonical** | `/node/1631632` | D7 nid → D11 nid via `field_legacy_nid` |

**D7 path aliases are not migrated.** `mandala_migrations` has no `url_alias` job, and
`pathauto` is not installed, so D11 nodes are reachable only at `/node/{nid}` and carry no
slug. There is therefore *nothing to redirect the bookmarked form to* until aliases are
either migrated from D7 (preserving the exact strings, which is what makes the redirect a
pure path mapping) or regenerated. Migrating the real D7 aliases is strongly preferable to
regenerating them: a regenerated slug that differs by even one character breaks the link
it was meant to save.

### The legacy host is part of the identity — it cannot be discarded

**Correction, 2026-08-25 (Yuji Shinozaki).** An earlier draft of this section suggested the
D7 and D11 nid ranges might prove disjoint, making a path-only redirect safe. That framing
was wrong, and the measurement it proposed would not have settled anything.

**D7 nids are unique per domain, not globally.** Each legacy site had its own database and
its own nid sequence, so `node/1631632` is a *different asset* on
`images.mandala.library.virginia.edu` than on `av.mandala.library.virginia.edu`. The
hostname is not decoration on a legacy URL — it is half the identifier. Strip it and the
remaining nid is ambiguous across up to five source sites, regardless of how the numbers
happen to be distributed.

Two consequences follow, and neither is optional:

**1. Redirects must be host-aware.** This is structural, not contingent on any
measurement. A path-only rule cannot distinguish `images…/node/1631632` from
`av…/node/1631632`, and silently resolving to the wrong asset is the exact failure this
ADR rejected the edge-only option to avoid.

**2. `field_legacy_nid` is not a unique key.** It is a bare unsigned integer on
`entity_type: node`, shared by every bundle, with no companion field recording the source
site. Once a second collection migrates, a lookup by `field_legacy_nid` alone can match
several nodes from different D7 sites. **This is latent rather than broken today only
because Images is the sole migrated collection** — it becomes a live defect the moment
Texts, Sources or AV lands.

The lookup therefore needs a composite key. Two ways to get one:

- **Scope by bundle**, mapping legacy host → candidate D11 bundles
  (`images…` → `shanti_image`, `av…` → the audio *and* video bundles, …). Sound because
  each D7 site was one database with one nid sequence, so host-plus-nid is unique even
  where a host carries two bundles. Requires no schema change.
- **Add a `field_legacy_site`** companion so the pair is self-describing and does not
  depend on the host→bundle mapping staying accurate.

Whichever is chosen must be settled **before the next collection migrates**, since
retrofitting a discriminator across already-migrated content is materially harder than
populating it during the migration that creates the rows.

## Open — must be resolved before this ADR is Accepted

1. **How host-awareness is implemented.** *That* it is required is settled above — the
   legacy host carries half the identity. What is open is the mechanism, since Drupal's
   `redirect` module matches on path, not host: have the edge tag each legacy host onto a
   distinct internal prefix that Drupal then translates, or use a host-aware request
   subscriber. Paired with this: bundle-scoped lookup vs a new `field_legacy_site`, which
   must be decided **before the next collection migrates**.
2. **Do D11 asset pages use the nid or a slug?** Decision 2 fixes `/images/{nid}`, chosen
   before browser verification established that D7's *only* human-facing form is
   `/image/{slug}` — the plural and the id forms both 404. `/node/{nid}` works but is the
   canonical fallback, not the form people share. So decision 2 currently departs from D7
   on **both** axes (plural vs singular, id vs slug) with only ADR 005's `/images/...`
   sketch supporting it. If D7's slugs are migrated to preserve inbound links, D11
   plausibly wants `/images/{slug}` canonical with the nid form secondary. **Unresolved,
   and it changes decision 2.** It also decides whether a D7 alias migration is required
   work or optional — and note D7 serves both forms without redirecting one to the other,
   so "which is canonical" is a genuinely new choice, not an inherited one.
3. **`mandala-om` coordination on `url_html`.** Per ADR 013 this is an active
   compatibility requirement, not a courtesy. Needs Than, and needs to cover the
   `MandalaMarkup.js` reverse lookup as well as the forward links.

## Consequences

- **Deep links survive cutover only if decision 6 is actually built** — host-aware, with a
  composite legacy key — and the bookmarked slug form additionally needs a D7 alias
  migration that does not yet exist. Two deferred notes already anticipate part of this
  work:
  [`kmassets-uid-identity-across-migration.md`](../deferred/kmassets-uid-identity-across-migration.md)
  ("wire redirect module to `field_legacy_nid`") and the High-priority
  [`kmassets-uid-consumer-analysis.md`](../deferred/kmassets-uid-consumer-analysis.md).
- **New dependency and new migration output.** `drupal/redirect` must be added, enabled and
  exported to `config/sync`, and the migration gains a redirect-generation step — one entry
  per migrated node, so on the order of 111k rows for Images alone and more per site as the
  other collections land. Sizing, and whether entries are generated in-migration or as a
  post-pass, are implementation choices this ADR does not fix.
- **This is ADR 005 being implemented, not ADR 008 being broken.** The URL change is
  user-facing, but it is the direct consequence of a consolidation decision already
  accepted on 2026-06-11 — not a new improvement introduced under cover of the migration.
- **Per-environment correctness comes free once item 5 lands:** dev, staging and
  production each emit their own host, closing the same class of gap flagged as the
  residual in [`spike-solr-demo-enabled-with-anonymous-route.md`](../deferred/spike-solr-demo-enabled-with-anonymous-route.md)
  ("decide the per-env override mechanism before a 2nd D11 environment exists").
- **Not blocking the 1a.9 acceptance run.** The run's criteria cover indexing and
  retrievability, not URL correctness. The acceptance cycle proceeds with the URL
  templates as-is; the docs it writes carry known-wrong URLs, which is no worse than the
  111,340 already indexed, and they are namespaced `images-11-*` and cleanable.
- **`url_ajax` has no D11 target yet.** No `/api/ajax/` route exists; Spike 6 found its
  only live consumer is Texts' `legacy/texts.js` embed path. Texts-phase work.
