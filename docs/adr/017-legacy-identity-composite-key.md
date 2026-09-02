# ADR 017: Legacy identity is a composite key — `field_legacy_site` + `field_legacy_nid`

**Status:** Accepted — 2026-09-02, ratified by Yuji Shinozaki, Than Grove, Xiaoming Wang
**Date:** 2026-08-25 (proposed), 2026-09-02 (accepted)
**Deciders:** Yuji Shinozaki (Lead Architect) — direction set 2026-08-25; team sign-off 2026-09-02
**Relates to:** [ADR 005](005-single-site.md) (single-site redesign), [ADR 016](016-public-url-structure-single-host.md) (public URL structure), [ADR 006](006-kmterms-in-kmassets-shadow-pattern.md)
**Resolves:** the gap recorded in [`migration-legacy-nid-required-convention.md`](../deferred/migration-legacy-nid-required-convention.md)

## Context

Every content-entity migration records its source D7 nid in `field_legacy_nid` (that
convention is already mandatory). Two consumers depend on that mapping being **unique**:
the legacy-URL redirects of [ADR 016](016-public-url-structure-single-host.md) decision 6,
and the planned `uid_legacy_s` kmassets compatibility shim.

**It is not unique.** D7 nids are unique *per site*, not globally — each legacy site was its
own database with its own nid sequence. `node/1631632` on
`images.mandala.library.virginia.edu` is a different asset than `node/1631632` on
`av.mandala.library.virginia.edu`. `field_legacy_nid` is a bare integer on
`entity_type: node`, shared by every bundle, with no companion recording the source site, so
a lookup by nid alone can match several entities once more than one site has migrated.

This is **latent, not broken, today** only because Images is the sole migrated collection. It
becomes a live defect the moment Texts, Sources or AV lands, and it fails quietly: the lookup
returns *an* entity, just not reliably the right one.

## Decision

**1. Legacy identity is the pair `(field_legacy_site, field_legacy_nid)`.** Neither half
identifies a D7 entity on its own. Every consumer — redirects, the kmassets shim, audits —
must key on both.

**2. The discriminator is the D7 *site*, not the asset type.** This distinction is
load-bearing and easy to get wrong: the nid space is the *database*. AV's audio and video are
two content types sharing **one** D7 nid sequence, so they take the **same** token. Splitting
by asset type would divide a space that is not divided.

**3. `field_legacy_site` is a `list_string` field using the kmassets `service` vocabulary:**

| D11 bundle | D7 site | `field_legacy_site` |
|---|---|---|
| `shanti_image` | Images | `images` |
| Texts bundles | Texts | `texts` |
| Sources / biblio | Sources | `sources` |
| `audio` | AV | `audio-video` |
| `video` | AV | `audio-video` |
| Visuals bundles | Visuals | `visuals` |
| Home / page | Mandala Home | `mandala` |
| `collection` / `subcollection` groups | the site they came from | that site's token |

Allowed values are enforced by the field, not by convention — a typo is rejected at
validation rather than silently stored (verified: `imagez` → *"The value you selected is not
a valid choice"*).

**4. It lives on both `node` and `group`**, mirroring `field_legacy_nid`, and is populated
per migration with `default_value` — a constant, because each migration reads exactly one D7
database.

## Why an explicit field, and not bundle-scoped lookup

The alternative was to derive the site from the bundle at lookup time (`shanti_image` →
Images, the audio *and* video bundles → AV) with no schema change. Rejected for three
reasons:

1. **`uid_legacy_s` falls out for free.** The D7-era kmasset uid is `{service}-{d7nid}`, so
   the shim becomes `field_legacy_site` + `-` + `field_legacy_nid` with no mapping table and
   no lookup. Verified live: group 1 yields `images-41`, exactly the legacy uid format.
   Bundle-scoping would require a parallel host→bundle map maintained in code indefinitely.
2. **It is self-describing.** A redirect, an audit query, or a person reading a row resolves
   identity without needing to know the mapping.
3. **The kmassets uid contract already made this choice** — service+nid as the composite key
   ([ADR 006](006-kmterms-in-kmassets-shadow-pattern.md) era). Adopting the same vocabulary
   aligns with a proven precedent instead of inventing a second, subtly different scheme.

## Consequences

- **Every future content migration must set it**, alongside the existing `field_legacy_nid`
  requirement. Added to the per-site checklist in the convention note.
- **Retrofitting is cheap here, unlike the URL aliases.** The value is a constant per site
  with no nid dependence, so a backfill is a single update per bundle. Populating it during a
  migration is free rather than urgent — but Images gets it during the 1a.9 re-import anyway.
- **Two bundles deliberately share `audio-video`.** Anyone reconciling asset types against
  this vocabulary will notice the mismatch; that is intended, and decision 2 is why.
- **Six values are fixed now, before the sites that need five of them exist.** If a sixth
  source turns up (or Mandala Home splits), the allowed-values list is a config change plus a
  `cim` — cheap, but it is a schema-visible change rather than a code constant.
- Does **not** by itself build the redirects or the shim; it makes both expressible.

## Verification

Implemented and exercised in DDEV, 2026-08-25: config imports cleanly; the Images migrations
populate `images`; the composite key reconstructs `images-41`; invalid tokens are rejected at
validation.
