# Legacy URL bare-nid redirects: host-awareness mechanism (Option 2 chosen)

**Area:** URL structure / migration / deployment / ADR 016
**Raised during:** Session 2026-10-12 (Than, Yuji)
**Issue:** #316
**Status:** Option 2 (host-aware request subscriber) chosen 2026-10-12 by Yuji; precondition verified live. Implemented in `mandala_legacy_redirect` (PR #319, merged and deployed to dev-0 2026-10-12; verified live there). Still open: public-edge `Host` recheck at cutover; `/images/{nid}` canonical routes (redirects target the alias until they exist); group lookup untested.
**Priority:** High — [ADR 016](../adr/016-public-url-structure-single-host.md) is now Accepted (2026-10-12); the subscriber must be built **before the next collection migrates** (Texts/Sources/AV are next per [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md))

## Context

ADR 016 (public URL structure) has one open item left standing in the way of
"Accepted" status: how a legacy D7 bare-nid URL resolves to the right D11 node.

**Already settled, not part of this note:** which field disambiguates the
per-site nid collision. [ADR 017](../adr/017-legacy-identity-composite-key.md)
(accepted 2026-09-02) answered that — `field_legacy_site` + `field_legacy_nid`
is the composite key, `field_legacy_site` is live in `config/sync` on both
`node` and `group`, with tokens `images`, `texts`, `sources`, `audio-video`,
`visuals`, `mandala`. ADR 016's own open-item-1 text predates this and is
stale on that point — worth a small doc correction whenever this note closes.

**What's still open, and the actual subject of this note:** D7 served two URL
forms per node:

- `/image/{slug}` — the bookmarked pathauto alias. Migrated as a real D11
  path alias (ADR 016 decision 7, proven end-to-end for Images: 111,304/111,304,
  0 mismatches). Needs **no** host-awareness — Drupal resolves it natively.
- `/node/{d7nid}` — the bare canonical. D7 nids are unique only *per site*, so
  the bare nid is ambiguous without knowing which legacy host it came from.
  This is the hard half, and it's what's undecided.

Core `redirect` module matches on path only, not host — and it isn't installed
yet anyway (confirmed absent from `composer.json` and `config/sync`, no
existing redirect logic anywhere in `drupal/web/modules/custom/`). Something
has to turn `(host, path)` into a `(field_legacy_site, field_legacy_nid)`
lookup before `redirect` (or equivalent) can act on it.

## Options discussed

### Option 1 — Edge-tag rewrite

The ALB/edge rewrites each legacy host into an internal path prefix on the
single D11 host (e.g. `av.mandala…/node/1631632` → `/legacy/audio-video/node/1631632`),
and a plain Drupal route/controller reads the site token out of that prefix.

- No event subscriber needed — a route + controller suffices.
- Requires an infra change in `terraform-infrastructure` (one listener rule
  per legacy host) — couples this decision to the ALB/host-routing cleanup
  that is itself still unreconciled (`docs/planning/1b1-part4-d11-backend-deploy-scope.md`,
  postponed to Dave as of 2026-07-14).
- A new source added to the `field_legacy_site` vocabulary needs a matching
  listener rule.

### Option 2 — Host-aware request subscriber

A custom Drupal event subscriber (`KernelEvents::REQUEST`, early priority)
reads the `Host` / `X-Forwarded-Host` header directly, maps it to a
`field_legacy_site` token via module config, and does the composite lookup
itself for the bare-nid path shape before core routing resolves.

- Self-contained in this repo — no infra PR, no dependency on the ALB
  cleanup timeline.
- **Unverified precondition:** whether the legacy hostname survives intact
  to the D11 container under the current (also unreconciled) edge setup. If
  it's stripped or rewritten upstream already, this option isn't viable and
  Option 1 is the only path.
- Needs a cheap early-exit (path regex check) so it doesn't add overhead to
  every request on a legacy host.

## Resolved 2026-10-12: Option 2 chosen

**Precondition verified live on dev-0** (Yuji, with Claude). The `Host` header reaches
Drupal intact for every per-site hostname. The same `GET /user` through the ALB returns
a 302 whose absolute `Location` names the requested host:

| Request host | `Location` |
|---|---|
| `mandala-av-dev.internal…` | `https://mandala-av-dev.internal.lib.virginia.edu/user/login` |
| `mandala-images-dev.internal…` | `https://mandala-images-dev.internal.lib.virginia.edu/user/login` |
| `mandala-texts-dev.internal…` | `https://mandala-texts-dev.internal.lib.virginia.edu/user/login` |

Sending SNI `av` with `Host: images` yields the `images` host, so Drupal reads the
Host header, not the TLS name. Why it holds:

- Each legacy-style hostname has its own ALB `host_header` listener rule forwarding to
  the same target group (`terraform-infrastructure/mandala/drupal/*/alb-routing.tf`),
  and the ALB does not rewrite Host.
- `settings.php` deliberately does **not** trust `X-Forwarded-Host`, so
  `$request->getHost()` is the raw Host header, the value the subscriber should read.
- `trusted_host_patterns` already admits every per-site hostname.

**Decision: Option 2.** Self-contained in this repo, no terraform change, no dependency
on the ALB cleanup. Requirements for the implementation:

- Subscriber on `KernelEvents::REQUEST` at early priority; host to `field_legacy_site`
  token mapping lives in module config.
- Cheap early-exit (path regex for the bare `/node/{nid}` shape) so requests that are
  not legacy-shaped pay nothing.
- Resolve via `field_legacy_site` + `field_legacy_nid` (ADR 017), never a raw D11 id.
- Read `getHost()` only; keep `X-Forwarded-Host` untrusted.

**Not verified:** only the internal dev hostnames were tested. If a CDN or other edge sits
in front of the public names (`av.mandala.library.virginia.edu` etc.) at cutover,
recheck that Host survives there. The production ALB rules have the same shape.

## What would close this

1. ~~A live check of whether `Host`/`X-Forwarded-Host` reaches Drupal intact.~~ Done, see above.
2. ~~A decision between the two options.~~ Option 2. Recorded in ADR 016 (now Accepted), 2026-10-12.
3. ADR 016's open-item-1 text updated to drop the already-resolved discriminator question
   and reference ADR 017 instead. (Partly done 2026-10-12; the Option 2 decision remains.)
4. Implement the subscriber and the `redirect` wiring before the next collection migrates.

### Original criteria (for reference)


1. A live check of whether `Host`/`X-Forwarded-Host` reaches Drupal intact
   for a legacy domain under today's edge setup (~10 minutes — log
   `$request->getHost()` against a real or DDEV-equivalent request). This
   directly gates whether Option 2 is even on the table.
2. A decision between the two options (or a third not yet considered),
   recorded back into ADR 016 to let it move from Proposed to Accepted.
3. ADR 016's open-item-1 text updated to drop the already-resolved
   discriminator question and reference ADR 017 instead.

## Cross-references

- [ADR 016](../adr/016-public-url-structure-single-host.md) — the parent decision (Accepted 2026-10-12)
- [ADR 017](../adr/017-legacy-identity-composite-key.md) — resolves the discriminator half
- [kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md) — step 6 at cutover ("wire redirect module to `field_legacy_nid`") assumes this is already decided
