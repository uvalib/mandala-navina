# IIIF URL prefix: `/mandala/` vs canonical `/iiif/2/`

**Area:** IIIF / configuration / consistency
**Raised during:** Sprint 1 (Step 1a.5 — IIIF wiring, 2026-06-22)
**Jira:** (add when available)
**Priority:** Low — works either way; cosmetic alignment question
**Owner:** Than Grove

## What we found

The Cantaloupe IIIF server at `iiif.lib.virginia.edu` exposes **two prefixes**
that resolve to the same images:

| Prefix | Status |
|---|---|
| `/mandala/` | What D7 uses (D7 default `shanti_images_view_path = /mandala/`). |
| `/iiif/2/` | What the server **self-reports as canonical `@id`** in `info.json`. |

For example, `https://iiif.lib.virginia.edu/mandala/shanti-image-680687/info.json`
returns a JSON body whose `@id` is
`https://iiif.lib.virginia.edu/iiif/2/shanti-image-680687`. Both URLs serve the
same image, but the canonical one (per the IIIF Image API 2.x spec) is `/iiif/2/`.

## What we did

D11 Sprint 1 1a.5 chose **`/mandala/`** for the initial `shanti_iiif.settings`
default, to match D7 exactly and avoid any surprise behavior for cached
clients, embedded URLs in legacy content, or downstream consumers that might
key on the `/mandala/` form.

The choice is configurable per environment via
`config/install/shanti_iiif.settings.yml` (the `view_path` key), so a future
session can switch globally with a settings change + cache rebuild — no code
changes required.

## Question to revisit

Before any cache or pre-generated derivative work, decide whether D11 should
align with the canonical `/iiif/2/` prefix. The argument for switching:

- It's the IIIF 2.x spec-canonical form.
- It future-proofs against the `/mandala/` alias being removed (the alias is
  presumably a custom Cantaloupe handler config maintained by Library DevOps).
- Any tooling that crawls or compares `@id` values will be consistent with
  what we actually serve.

The argument for staying:

- ADR 004 says treat IIIF infra as-is. The prefix change is cosmetic for
  end users (the image looks identical), but it's a divergence from D7.
- Anything that hardcoded `/mandala/` (legacy HTML body content, exported
  JSON, third-party blog embeds) would 404 if Library DevOps ever retired
  the alias. That risk exists today regardless.

## What to do

Park until either (a) we hit a concrete reason to align — e.g. a tooling
consumer that needs the canonical form — or (b) Library DevOps signals the
`/mandala/` alias is going away. Either way, the fix is a one-line config
change.

## Related

- [ADR 004](../adr/004-solr-source-of-truth.md) — IIIF/Solr stays as-is
- [Sprint 1, Step 1a.5](../sprints/sprint-01-images-implementation.md) — IIIF wiring
