# Session Log: JSON proxy re-scoped after browser testing disproved its premise; uniform endpoint access escalated to the team

**Date:** 2026-08-24 (solo session)
**Participants:** Than Grove, Claude Code
**Outcome:** Two loose threads from [2026-08-21](2026-08-21-spike-6-closed-proven.md) closed —
one **merged** ([mandala-om #79](https://github.com/shanti-uva/mandala-om/pull/79)), one open for
the team ([mandala-navina #136](https://github.com/uvalib/mandala-navina/pull/136)). The larger
result is that **a three-week-old premise turned out to be wrong**: the Sources WAF block was
never a general property of the Drupal hosts, and the `mandala-om` branch built on that
assumption was re-scoped to what the evidence actually supports before it landed.

> Hand-written rather than machine-generated — not for sensitivity reasons this time (nothing here
> is private), simply because the finding below is the point of the session and a verbatim
> transcript would bury it.

## Starting point

Solo day. `main` clean at `ed07df0`, no open PRs, nothing landed since 08-21. Orientation
identified two items carrying Than's name and needing no one else:

1. The uniform-endpoint-access requirement, filed 08-21 with "does this need an ADR?" open.
2. `mandala-om` `feat/generalize-json-proxy-all-sites` — pushed, unmerged, **no PR**.

## 1. Uniform endpoint access → group-meeting agenda item

Than declined to draft the ADR solo: it spans services Yuji and Xiaoming own and is a knowing
departure from [ADR 008](../adr/008-mvp-migrate-not-improve.md), so it needs those people in the
room. Escalated rather than written ([PR #136](https://github.com/uvalib/mandala-navina/pull/136),
docs-only).

The routing mechanism matters more than the wording. The startup ritual guarantees
`docs/deferred/README.md` is *read*, but the row for this note sits at line 82 of a ~50-row
table — reliably read, not reliably noticed. So the change is a **new "Awaiting a team decision"
callout above the Open items table**, which also picks up the
[Images interactive-viewing-surfaces](../deferred/images-missing-interactive-viewing-surfaces.md)
flag that had the same visibility problem.

Four questions were framed for the group. The substantive one: whether
[ADR 010](../adr/010-adr-008-scope-clarification.md)'s "internal architecture" clause already
covers this, or whether it is user-facing enough that ADR 008 needs an explicit exception.
Genuinely arguable both ways, and deliberately written without steering.

## 2. The `mandala-om` branch became PR #79

Base chosen as `release/v1.2.0-rc` (newest line, contains `v1.1.0-rc`); `master` is 625 commits
behind and not the integration branch. **Squash-merged as `04ef2b41` at 17:24Z.**

## 3. Browser verification disproved the branch's premise

**This is the session's real finding.** Run in a real browser from `https://thlib.org` — the
actual origin for the `.env.tibet.*` builds — using real `<script>`-tag injection, the same
mechanism `jsonpAdapter` uses:

| Asset type | Cross-origin JSONP |
|---|---|
| **sources** | **FAILED — HTTP 503** |
| texts | OK, callback fired |
| images | OK, callback fired |
| visuals | OK, callback fired |
| audio-video | OK, callback fired |

**Only Sources is blocked**, and specifically so rather than merely down: the same URL returns
200 to `curl` even when sent a browser `User-Agent`, `Origin` and `Referer`, and the site root is
200. Only a real browser draws the 503 — the documented WAF signature, keying on something `curl`
cannot forge. Confirmed a genuine server status via the network log, not a client-side block.
(Tested in Brave; Shields ruled out — it would give `ERR_BLOCKED_BY_CLIENT` with no status, and
four sibling subdomains succeeded.)

### Where the wrong premise came from

The original July fix was **correctly scoped**, and said so in its own code comment: *"Scoped to
the sources host; other Drupal apps (images/av/texts/visuals) still use the direct JSONP path
below."* The claim that "the same WAF class applies to every Drupal host" was **later inference,
recorded in a deferred note and never tested**. It then propagated into the branch, and into the
first draft of PR #79 stated as fact.

Nobody had ever tested the other four hosts. The generalization was extending a fix to hosts that
were never observed failing.

### Two related things it clarified

- **thlib.org is not broken** — the July proxy fix is deployed and working there. Loading
  `https://thlib.org/#/sources/128200` shows exactly one request, through `/proxy/json/`, HTTP 200.
- **There is no "blocked URL" to hand anyone.** Pasting the Sources JSON URL into an address bar
  returns 200 with full JSON. The 503 is a property of *how* the request is made — a cross-origin
  subresource request from a browser — not of the address. Any future report of this needs a
  reproduction, not a link.

### What the PR became

Routing all five hosts would have added a hard dependency on the WordPress proxy for four that do
not need it, including pushing the **4.2 MB Texts payload** (the Spike 6 book-root collapse)
through it on every detail view.

The merged gate is `PROXY_HOSTS`: defaults to the `REACT_APP_DRUPAL_SOURCES` host, overridable via
a new `REACT_APP_PROXY_HOSTS`. **The generalized mechanism the branch set out to build is kept —
if the WAF spreads it is an env change, not a code change — while the routing policy stays scoped
to observed evidence.** The default derives from the env var rather than being hardcoded,
preserving the property that made the original host gate worth having: dev and staging point at
different Sources hosts, and a hardcoded production domain would silently never match there.

A second overstatement was corrected in the same pass: the claim that a proxied `.jsonp` URL
returns callback-wrapped JavaScript. It wraps **only when a `callback=` param is present**, which
`axios.get` never adds — both forms currently return byte-identical JSON (40,622 B, measured). The
`.json` request is still right, and the comment now says why (a `callback=` riding along in a
`url_json` value would break the proxied parse). The `'p'` suffix *is* load-bearing on the direct
path, where `.json?callback=` returns unwrapped JSON that `jsonpAdapter` cannot consume.

## 4. The lesson worth keeping

**`curl` and a browser disagree in both directions, and each blind spot has now cost real time.**

- [Spike 6](../spikes/spike-06-api-compatibility.md) (08-21): `curl` returned `202`/empty on every
  HTML-typed response. A `curl`-based audit would have concluded all six AJAX endpoints were dead.
- This session (08-24): `curl` returned **200** on a Sources request a browser gets **503** on —
  even with forged UA, Origin and Referer. A `curl`-based check would have concluded the WAF block
  did not exist, and a browser check of *only* the failing host left the generalization untested
  for three weeks.

Standing rule, sharpened: **for anything touching the edge/WAF, `curl` establishes nothing on its
own — in either direction.** Test the hosts that work, not just the one that fails; a fix scoped by
inference rather than observation is an untested claim wearing a fix's clothes.

Corollary for reviews: the deferred-note framing outlived the code comment that contradicted it.
The *narrower, evidence-bearing* statement was in the source; the *broader, inferred* one was in
the docs, and the docs won. Worth watching for whenever a note generalizes from a single incident.

## 5. Merge conflict resolution

`release/v1.2.0-rc` had conflicts in `kmaps-app/package.json` and `package-lock.json`. Resolved
**in favour of the release branch throughout**, per Than.

Release had **independently declared the same phantom dependencies** the branch added — two
solutions to one problem, not competing changes. Release's pins win (`iso-639-1` `^2.1.15` →
`^3.1.6`, `react-rnd` `10.3.7` → `^10.5.3`) and its `package.json` was taken wholesale, picking up
work the branch lacked: node-version-aware `--openssl-legacy-provider` detection, the
`tiblocalsync` path fix, the `react-draggable` override.

One non-conflicting addition carried forward: **`react-router@5.2.0`**, which release did not
declare but which is imported directly at 14 sites under `src/` (`useParams`, `Redirect`,
`useHistory`, `generatePath`) rather than via `react-router-dom`. Already in release's lock at
exactly 5.2.0 as a transitive dep, so promoting it to direct was one line in each file. The lock
was hand-edited to that single line rather than regenerated — `npm install` rewrites ~46 unrelated
`"dev": true` flags that would have buried the real change.

## Artifacts

| | |
|---|---|
| PR (open) | [mandala-navina #136](https://github.com/uvalib/mandala-navina/pull/136) — escalate uniform endpoint access to a group-meeting agenda item (docs-only) |
| PR (**merged** `04ef2b41`) | [mandala-om #79](https://github.com/shanti-uva/mandala-om/pull/79) — scope the JSON proxy to hosts that need it, via a configurable host list |
| New convention | "Awaiting a team decision" callout in `docs/deferred/README.md` |

## Open items

- **Group meeting:** uniform endpoint access — needs Yuji + Xiaoming; four questions framed in the
  [deferred note](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md).
- **Still unanswered from 08-21:** do the private docs repos exist? Than has access to none, so the
  D7 access-defect write-up remains unfiled anywhere the team can read. **Ask Yuji.**
- **Unverified:** whether the Sources 503 is user-visible on standalone production
  `mandala.kmaps.virginia.edu`. The standalone builds have no proxy, so it is a reasonable
  inference, but it was not confirmed end to end. Relates to
  [option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md),
  whose "cheapest next step" — test whether the WAF fires for a browser on the standalone origin —
  **is now partly done**: it fires for Sources cross-origin, and does not for the other four.
- **`mandala-om` deployment:** #79 is merged to `release/v1.2.0-rc` but the fix only takes effect
  where the build is redeployed. Nothing in this session redeployed anything.
