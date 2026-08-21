# D7 endpoint field inventories are lower bounds, not complete contracts

**Status:** Open — no approach chosen
**Priority:** Medium (rises to High as each site builds its node-JSON controller)
**Raised:** 2026-08-21 by Than Grove, during [Spike 6](../spikes/spike-06-api-compatibility.md)'s
live endpoint verification
**Area:** migration / API contracts / `mandala_node_api` + per-site equivalents

## The problem

The D7 JSON endpoints generally **omit a field entirely when it is empty**, rather than emitting
it as `null` or `""`. Every endpoint contract captured in Spike 6 was derived by fetching a small
number of real production nodes:

| site | nodes sampled |
|---|---|
| AV | 3 (42016, 42167, 42158) |
| Sources | 3 (one `biblio`, one `subcollection`, one `collection`) |
| Texts | 1 book (5 page nids, all collapsing to the same book root) |
| Images | shape drafted from the D7 audit + kmassets logic, never validated against client usage |

A sampled record shows the fields *that node happens to populate*. So **absence of a field in a
sample is not evidence that the endpoint never emits it.**

Nothing captured is known to be wrong. It is the **completeness** claim that is unsupported.

## Why it matters

A D11 controller built to match a sampled inventory will silently under-implement whatever the
samples did not exercise. Per the per-site migration checklist, that surfaces late — against real
client rendering, after the migration has run.

The Sources finding is the pattern in miniature: `description` is absent from a `biblio` node not
because the endpoint never sends it, but because that node's `body` is empty.

## Candidate approaches — neither chosen, neither investigated

1. **Purpose-built test records** — one fully-populated node per content type, every field filled,
   as a fixture that makes the maximal response shape observable in a single fetch.
2. **Programmatic derivation** — enumerate each content type's field definitions from D7 config
   directly, rather than inferring the contract from sampled instances.

## Open questions if this is picked up

- Is the omit-when-empty behaviour uniform across the four sites, or per-module?
- Does it apply to the computed/augmented keys (`thumbnail_url`, `duration`, `full_markup`,
  `subcollections`, …) as well as raw `field_*` ones?
- Would fixtures live in D7 (capturing the source contract) or in D11 (testing the replacement)?

## Related

- [Spike 6](../spikes/spike-06-api-compatibility.md) — endpoint contracts and live verification
- [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md) —
  the per-site checklist that now owns building these controllers
