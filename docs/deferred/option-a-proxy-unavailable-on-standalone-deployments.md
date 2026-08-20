# Option A's proxy doesn't exist on the standalone (non-WordPress) deployments

**Area:** Spike 6 / URL strategy / mandala-om deployment topology / WAF
**Raised during:** Spike 6 (2026-08-20) — generalizing the `/proxy/json` gate in `mandala-om`
**Jira:** (add when available)
**Priority:** Medium — no known break today, but it means the decided URL strategy covers only
part of the deployment matrix, which matters at D11 cutover

## What we found

[ADR-less decision, 2026-08-12](../spikes/spike-06-api-compatibility.md#url-strategy-decision-2026-08-12-than--option-a--generalize-the-same-origin-proxy):
**Option A** — route every asset JSON fetch through `mandala-wp-proxy`'s same-origin
`/proxy/json`, so the browser never makes the cross-origin call the AWS WAF blocks. The spike
doc describes this as generalizing the proxy "to all apps" and calls the proxy "the permanent
architecture."

**But the proxy is a WordPress plugin, and most `mandala-om` deployments have no WordPress.**
Counted directly in `mandala-om` (`release/v1.1.0-rc`): `REACT_APP_WP_PROXY` is defined in
**2 of 11** env files.

| Env file | `REACT_APP_WP_PROXY` | Deployment |
|---|---|---|
| `.env.tibet.prod` | ✅ `https://thlib.org/proxy` | WordPress-embedded (thlib.org) |
| `.env.tibet.staging` | ✅ `https://staging.thlib.org/proxy` | WordPress-embedded (staging) |
| `.env.production` | ❌ | Standalone — `mandala.kmaps.virginia.edu` |
| `.env.development` | ❌ | Local dev |
| `.env.uf` / `.env.uf.prod` | ❌ | Standalone (UF) |
| `.env.cj.dev` / `.env.cj.prod` | ❌ | Standalone (CJ) |
| `.env.contport.prod` | ❌ | Standalone (has `REACT_APP_JSON_PROXY` but no `WP_PROXY`) |
| `.env.tibet` | ❌ | (base tibet, no proxy set) |
| `.env.test` | ❌ | Test |

So Option A, as implemented, protects the thlib.org WordPress embeds. The standalone builds —
including the production `mandala.kmaps.virginia.edu` deployment — have **no `/proxy/json`
endpoint available** and must keep making direct cross-origin JSONP calls, which is exactly
the fetch pattern that produced the 2026-07-29 Sources 503.

## Why this wasn't visible before

The 2026-07-29 fix and the 2026-08-12 decision were both driven by the Sources incident, which
surfaced on thlib.org — a WordPress-embedded deployment where the proxy does exist. The
question "what about the deployments without WordPress?" never came up because the incident
didn't occur there. The spike doc's Option A analysis discusses WordPress as an implementation
detail of the proxy ("adds a proxy hop + couples to WordPress; owner/host TBD") but doesn't
note that the coupling makes the strategy inapplicable to a subset of deployments.

## Current state (2026-08-20)

The client-side generalization
(`mandala-om` branch `feat/generalize-json-proxy-all-sites`, commit `e6e712ae`) **deliberately
preserves the fall-through**: when `REACT_APP_WP_PROXY` is unset, the code proceeds to direct
JSONP rather than erroring. That is correct behaviour given the above — the absence of a proxy
is a supported configuration, not a misconfiguration. An earlier framing of this fall-through
as a possible bug ("should it fail loudly?") was **withdrawn** once the env-file counts were
checked.

**Net effect:** the standalone deployments are no worse off than before, but they are not
covered by the decided strategy either.

## What's undecided

1. **Are the standalone deployments actually WAF-exposed?** The 503 was observed against
   `sources.mandala.library.virginia.edu` from a browser on thlib.org. Whether the same rule
   fires for a browser on `mandala.kmaps.virginia.edu` was never tested — different origin,
   possibly different WAF evaluation. **This is the cheapest thing to check and should come
   first**; if they aren't exposed, the gap is theoretical.
2. **If they are exposed, what covers them?** Options that don't depend on WordPress:
   - Native CORS on D11 (the spike's Option B, currently marked superseded) — the standalone
     deployments are exactly the case Option B was suited to.
   - A non-WordPress proxy tier (the spike's own "dedicated proxy service" alternative, raised
     in the pre-findings and never resolved).
   - Accept that standalone deployments run without WAF protection, documented as a known
     limitation.
3. **Does "Option A is the permanent architecture" need restating?** As written, the decision
   reads as covering everything. It covers the WordPress embeds. Whether that warrants a
   superseding note or just a scope clarification in the spike doc is a judgement call for
   whoever picks this up — the decision itself isn't wrong, its stated scope is too broad.

## Cross-references

- [Spike 6](../spikes/spike-06-api-compatibility.md) — the Option A decision and the client
  generalization work
- [mandala-node-api-no-identity-forwarded-through-json-proxy.md](mandala-node-api-no-identity-forwarded-through-json-proxy.md)
  — the other structural gap in the same Option A proxy path
- [mandala-wp-proxy-json-proxy-open-ssrf.md](mandala-wp-proxy-json-proxy-open-ssrf.md) — the
  proxy handler itself
