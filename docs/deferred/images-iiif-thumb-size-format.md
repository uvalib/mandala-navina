# IIIF thumb size segment: D7 `^!200,200` vs D11 `IiifUrlBuilder` `!200,200`

**Area:** IIIF / kmassets / Images / write path
**Raised during:** Sprint 1 (1a.8 — doc-builder fixture validation)
**Jira:** (add when available)
**Priority:** Low

## What we found

`url_thumb` matches the golden in every segment except the size token:

```
golden : https://iiif.lib.virginia.edu/mandala/shanti-image-469626/full/^!200,200/0/default.jpg
built  : https://iiif.lib.virginia.edu/mandala/shanti-image-469626/full/!200,200/0/default.jpg
```

D7 emitted `^!200,200` (the IIIF Image API **3.0** "allow upscaling" `^` prefix +
`!` best-fit). The shared `shanti_iiif\IiifUrlBuilder` emits `!200,200` (best-fit,
no upscale). All other thumb fields — `url_thumb_width/height/size`, `img_*_s`,
`url_iiif_s` — match exactly.

## Decision needed

- **Bug-for-bug** — add the `^` (upscaling) prefix to `IiifUrlBuilder` so `url_thumb`
  byte-matches the live index. Note `IiifUrlBuilder` is shared (used by the field
  formatter too), so changing it affects display URLs as well.
- **Canonical** — keep `!200,200`; accept the one-character divergence. The
  Cantaloupe server resolves both; the practical question is only exact-match with
  existing indexed values and whether the source images are ever smaller than
  200px (where `^` upscaling would matter).

Cross-ref [doc contract §7.2-C](../planning/kmasset-solr-doc-contract.md) and the
`IiifUrlBuilder` service in `shanti_iiif`.
