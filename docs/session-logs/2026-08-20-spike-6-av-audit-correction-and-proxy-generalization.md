# Session Log: Spike 6 — AV Audit Correction And Client Proxy Generalization

**Date:** 2026-08-20
**Participants:** Than Grove, Claude Code
**Outcome:** Corrects three independent errors in
[Spike 6](../spikes/spike-06-api-compatibility.md)'s 2026-08-07 AV endpoint audit against live
evidence, implements the client-side `/proxy/json` generalization in `mandala-om`
(`feat/generalize-json-proxy-all-sites`, unmerged), and files a new scope gap in the Option A
URL strategy:
[option-a-proxy-unavailable-on-standalone-deployments.md](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md).

---

## Summary

Session ran in parallel with Yuji's OAuth2 session-handling work (the fourth defect from
2026-08-19); nothing here touches that code path.

**Started as:** build the AV node-JSON controller. **Became:** a documentation correction, once
it emerged that AV isn't migrated to D11 yet (no `video`-equivalent bundle, no
`mandala_kmassets_sync` entry), so there was nothing to build a curated controller against.
Than's call: fix the docs now, defer the code.

**1. The 2026-08-07 AV audit row was wrong in three independent ways.** That audit was done by
reading D7 source rather than calling the live endpoint. Live `curl`s against three real
production `video` nodes (42016, 42167, 42158) showed:

| Claimed (2026-08-07) | Actual (2026-08-20) |
|---|---|
| Served by `mediabase/mb_solr`, `mb_solr_get_solrdoc()` | Services module, per-content-type "JSON Path" setting |
| Bespoke Solr-derived flat `doc` | Augmented raw node, same family as Images/Sources/Texts |
| No JSONP | `.jsonp?callback=` returns 200 `text/javascript`; the client depends on it |

Two consequences beyond the row itself: the believed **Terraform/ALB server-rewrite requirement
for AV doesn't exist** (it's a normal Drupal route), and gotcha #4's "JSONP satisfiable on only
3 of 4 sites" is wrong — it's all four. **Reliability caveat now flagged on the spike:** the
Sources and Texts rows come from the same source-read pass and have never been live-verified.

**2. Client proxy generalization implemented** (`mandala-om` `e6e712ae`, branched from
`release/v1.1.0-rc`, unpushed). Widens the gate from Sources-only to all Drupal hosts. Two traps
found in the process:

- **A widened substring test would have opened a spoofing hole.** Broadening
  `.includes('sources.mandala…')` to `.includes('mandala.library.virginia.edu')` also matches
  `mandala.library.virginia.edu.attacker.com`. Verified both spoofs match the substring test and
  neither matches the `URL()`-parsed hostname test. 12 host-matching cases pass.
- **The AV `.jsonp` append had to move to the direct-JSONP branch only.** A server-side proxy
  fetch of `.jsonp` returns `mdldata({...})`, which won't parse as JSON — this would have shipped
  as a silent, AV-only regression.

Verification is partial: `node_modules` isn't installed in that checkout, so the app test suite
and prettier couldn't run. Syntax checked via `node --check`; host matcher unit-tested
standalone. **The proxy and JSONP request paths are unexercised and need a real browser check
against a tibet build before merge.**

**3. New deferred note — Option A's scope is narrower than recorded.** `REACT_APP_WP_PROXY` is
defined in only **2 of 11** `mandala-om` env files (`.env.tibet.prod`, `.env.tibet.staging`).
The proxy is a WordPress plugin; the standalone builds — including production
`mandala.kmaps.virginia.edu` — have no `/proxy/json` at all and still make the direct
cross-origin call that caused the 2026-07-29 Sources 503. This also **withdrew an earlier
suggestion** made this session: the no-proxy fall-through looked like a bug worth failing loudly
on, but it's load-bearing for exactly those deployments. Cheapest next step is checking whether
the WAF actually fires on the standalone origin — never tested.

**Also corrected mid-session:** an earlier claim that the Sources proxy routing dated from
2026-08-12. It's 2026-07-29 (`6a2ef22b`), with the env rename 2026-07-30 (`27a21c63`), both by
Than. 2026-08-12 was the URL-strategy decision and the SSRF fix.

## Addendum — `mandala-om` has 8 phantom dependencies (found while trying to run the app)

Than installed `mandala-om` to browser-verify the proxy change and hit
`Module not found: Can't resolve 'date-fns'`. Root cause is a repo-structure trap worth
recording, since it will bite anyone else who tries to run this app:

**`mandala-om` is two separate npm projects, not npm workspaces.** The root `package.json`
(`mandala-om`, 13 deps, exists for the root `__tests__/` jest suite) and
`kmaps-app/package.json` (`kmaps-app`, 62 deps, the actual CRA app) are independent. All app
source lives in `kmaps-app/src/`.

Eight packages are **imported from `kmaps-app/src/` but declared only in the root
`package.json`** — someone ran `npm install <pkg>` from the repo root instead of from
`kmaps-app/`. This only ever worked via Node's walk-up module resolution finding them in the
parent `node_modules`. Install into `kmaps-app/` alone and the build fails.

| Package | Files importing it | Was |
|---|---|---|
| `date-fns` | 2 | absent → compile error |
| `iso-639-1` | 6 | absent → compile error |
| `iso-639-3` | 6 | absent → compile error |
| `react-image-magnify` | 1 | absent → compile error |
| `react-rnd` | 1 | absent → compile error |
| `react-split-pane` | 2 | absent → compile error |
| `react-tiny-popover` | 1 | absent → compile error |
| `react-router` | 14 | resolved as a *phantom* transitive dep of `react-router-dom` — worked by accident |

All eight installed into `kmaps-app/`. `react-router` was additionally *declared* rather than
left phantom; it moved 5.2.0 → 5.3.4 in the process, same-major but under 14 files.

**Installing at caret ranges broke the build, and this is the durable lesson.** The first
rebuild failed with:

```
./node_modules/react-draggable/build/cjs/chunk-ACOTSM7X.mjs
Can't import the named export 'Children' from non EcmaScript module
```

`react-rnd`'s caret (`^10.3.7`) let npm resolve **10.5.3**, which requires
`react-draggable ^4.5.0` and pulled **4.7.1** — a post-ESM-transition build shipping `.mjs`
chunks that this app's **webpack 4 / `react-scripts` 3.4.3** cannot parse. Root's declared
floor `react-rnd@10.3.7` pins `react-draggable` to exactly **4.4.4**, which predates that.
Fixed by installing `react-rnd@10.3.7` with `--save-exact`. Build then compiled successfully
(with pre-existing ESLint/SASS warnings).

**Generalize this before adding any dependency to `mandala-om`:** on this toolchain, caret
ranges are unsafe — anything published since the ESM transition may ship `.mjs` and fail the
same way. Prefer the exact versions root declares. Three packages still sit above root's floor
(`iso-639-1` 2.1.15, `react-router` 5.3.4, `react-tiny-popover` 8.1.6); the build passes with
them, but they're the first place to look if something misbehaves at runtime.

`kmaps-app/package.json` + `package-lock.json` changes were left **uncommitted** — unrelated to
the proxy change on that branch, so they want their own commit.

**Not investigated:** whether the root `package.json`'s duplicated app deps (`react`, `axios`,
`react-router-dom`, …) should exist at all, or whether the two projects should become real npm
workspaces. That's a repo-hygiene question for whoever owns `mandala-om` packaging.

## Addendum 2 — the host gate was redesigned, and two verification mistakes

**Design change (`mandala-om` commit `d83fa707`).** The first version of the host gate hardcoded
the production domain, mirroring `mandala-wp-proxy`'s server-side allowlist. Trying to actually
test it exposed the flaw: dev's Drupal hosts are `*-dev.internal.lib.virginia.edu`, so the proxy
branch **could never fire in dev under any configuration** — the change was untestable there by
construction, and would break again at D11 cutover when the consolidated host changes. The gate
now derives its host set from the `REACT_APP_DRUPAL_*` env vars each `.env.*` file already
defines, which are the authoritative per-deployment list. Exact-hostname matching is retained
(spoof case still blocked); non-URL vars like `REACT_APP_DRUPAL_SOURCES_API` drop out of the
`URL()` parse, and `REACT_APP_WP_PROXY` isn't a `DRUPAL_` var so the proxy can't self-proxy.
13 cases pass across simulated prod and dev env sets.

**Verification mistake 1 — artifact presence is not build success.** An earlier check in this
session reported the build "succeeded" on the strength of freshly-written `build/index.html` and
JS chunks. That reasoning is wrong: CRA emits `build/` *before* the `CI=true` lint gate runs, so
artifacts appear even on a failed build. The build had in fact exited 1. Correct method: read the
exit code of an **unpiped** build (a piped exit code is the pipe's), or grep for
`Compiled successfully` / `Compiled with warnings` / `Failed to compile`. Also: `CI=true`
escalates this repo's **188 files** of pre-existing lint warnings into hard errors, so it's the
wrong flag for a smoke test here. Re-run without it: **exit 0, "Compiled with warnings."**

**Verification mistake 2 — over-confident attribution of a runtime error.** When a Sources page
showed `Error: Item not found!`, this was asserted to be the node-JSON fetch, reasoning "the page
frame loaded, so Solr succeeded." Than pushed back. He was right that the evidence was
ambiguous: `useSolr.js:58` uses the **same** `logic/axios-jsonp` adapter (with
`callbackParamName: 'json.wrf'`) that throws that exact string, so the message alone cannot
distinguish the Solr call from the node-JSON call. The mechanism does distinguish them —
`script.onerror` fires on HTTP/load failure, not on runtime exceptions in a script that loaded —
and dev Solr returns 200 while dev Sources returns 404, so the original attribution held. But it
was stated as fact before being checked.

**Incidental bug found while checking (unrelated to this work):** the dev Solr response comes
back **double-wrapped** — `testcb(testcb({...}))`. The PHP solr-proxy adds a callback wrapper on
top of Solr's own `json.wrf` wrapper. Traced through the adapter: the inner call resolves the
promise with correct data and restores `window[jsonp] = old` (undefined), so the outer call hits
`undefined(...)` and throws an **uncaught TypeError** in the console. Harmless to the fetch, but
every Solr call in the app emits a stray console error. Not filed yet.

**Why dev cannot verify this change end-to-end:** the dev Solr index's `url_json` values point at
`mandala-sources-dev.internal.lib.virginia.edu`, which serves **no JSON API at all** — 404 on
`/sources-api/json/{nid}`, `/api/json/{nid}`, `/node/{nid}` and `/jsonapi`; `/` returns Drupal
10/11 chrome.

**Explained by Than (2026-08-20), and it is NOT a defect:** all the
`*-dev.internal.lib.virginia.edu` hostnames have been **taken over by the new D11 site**, so the
D7 endpoints are simply gone there. This session initially wrote it up as breakage and nearly
filed it as a deferred note; asking first avoided adding a non-issue to the queue. **Staging
still serves the D7 endpoints** — e.g.
`mandala-sources-staging.internal.lib.virginia.edu/sources-api/json/62716`.

**Local testing therefore points at PRODUCTION, and cannot point at staging** (decided by Than;
caveat documented in the untracked `.env.development.local`). The reason staging is unreachable
by config is worth remembering: **the URL actually fetched is `url_json`, a field stored in the
Solr record — not derived from `REACT_APP_DRUPAL_*`.** Pointing at staging would need a staging
kmassets index whose `url_json` carries staging hosts, and none is reachable
(`mandala-index-staging`, `mandala-solr-proxy-staging` both fail to connect). Changing the
`DRUPAL_*` vars only changes which hosts the proxy gate considers eligible. Production means
read-only selects and GETs of public records — no write risk, negligible load, but live data.

**Test ids are environment-specific:** `62716` resolves on staging and production; `137238`
resolves on production but not staging (older snapshot).

## Addendum 3 — VERIFIED end to end in a browser, and two more findings

Than brought up the DDEV WordPress, which made a real browser test possible. Result: **the full
chain is verified for the first time on any codebase.**

| Request (Chrome, localhost:3000, production Solr + hosts) | Status |
|---|---|
| direct JSONP → `sources.mandala.library.virginia.edu/sources-api/json/25581?callback=…` | **503** — the 2026-07-29 WAF bug, reproduced live |
| `localhost:3000/proxy/json/?url=…` | **404** — gate works, but `setupProxy.js` was broken |
| `localhost:3000/proxy/json/?url=…` (after fix) | **200**, 4,677 bytes of real JSON |

The Sources page then rendered its full record — title, journal, format, pages, year, record
creator, visibility, complete abstract — where it had shown a blank body. No cross-origin
request to the Sources host remains.

**Finding A — `setupProxy.js` had never worked** (`mandala-om` `eeefb203`). Express strips the
`/proxy` mount path before `http-proxy-middleware` (v3.0.5) sees it, so the existing
`pathRewrite: {'^/proxy': '/proxy'}` could never match — the prefix was already gone when it ran.
Requests reached WordPress as `/json/…` and 404'd. Proven by deliberately doubling the prefix:
`/proxy/proxy/json/…` → 200 while `/proxy/json/…` → a WordPress 404 page. **This explains why
the July 2026 Sources fix was only ever verified against production** — local verification was
impossible, and the failure looked like the feature rather than the dev tooling. The comment on
the old rule (`// ensure /proxy/ttt -> /proxy/ttt`) shows the symptom had been noticed; the fix
just doesn't hold under v3 + Express mounting.

**Finding B — curl cannot test this WAF, and a third verification mistake.** A curl replay with
full browser headers (`Origin`, `Referer`, `Sec-Fetch-Dest: script`, `Sec-Fetch-Mode: no-cors`,
`Sec-Fetch-Site: cross-site`, Chrome UA) returned **200** on the exact URL a real browser got
**503** on. The rule keys on something header-spoofing can't fake — TLS/JA3 fingerprint, header
ordering, or similar. On the strength of that false negative this session declared "the WAF
doesn't reproduce today" and constructed a content-type/ORB theory to explain the failure
instead. Both were wrong; the browser showed a plain 503. **Rule going forward: only a real
browser is evidence about this WAF.**

**Consequence for the Option A gap note:** its first open question ("are the standalone
deployments actually WAF-exposed?") is now substantially answered — the 503 came from
`localhost:3000`, an origin with no relationship to thlib.org, so the rule is not origin-scoped
to the WordPress embeds. The standalone deployments should be treated as genuinely exposed.
Priority on that note raised accordingly.

## Addendum 4 — a regression I introduced, and the rule that prevents it

Fixing the phantom dependencies **broke every browse/list page** (`/sources/all/list`,
`/images/all/list`, and by implication all asset types). Symptom:

```
TypeError: Cannot read properties of undefined (reading 'match')
  useParams → const match = useContext(RouterContext).match;
  AssetHomeCollection.js:15 → const { view_mode } = useParams();
```

`useContext(RouterContext)` returning `undefined` is the signature of **two copies of
react-router** in the tree — the context the `<Router>` provider fills is not the one
`useParams` reads.

**Cause:** declaring the previously-phantom `react-router` at the root's range (`^5.2.0`) let
npm resolve it to **5.3.4** at top level, while `react-router-dom@5.2.0` pins
`react-router: 5.2.0` **exactly** and therefore kept its own nested 5.2.0 copy. Two instances,
two distinct `RouterContext` objects.

```
node_modules/react-router                          → 5.3.4   (added by the "fix")
node_modules/react-router-dom/node_modules/react-router → 5.2.0   (what <Router> uses)
```

**Fix:** `react-router` pinned to exactly **5.2.0**, matching what `react-router-dom` already
resolved. One copy remains; all three pages verified rendering afterwards (26,429 Sources hits /
108,084 Images hits / the Sources detail record).

**The generalizable rule — this is the second time the same class of mistake bit today.** When
converting a phantom dependency into a declared one, pin it to **exactly the version already
resolved in the tree**, not to a range. A range is not "the same dependency written down"; it
invites a different resolution, and for a library carrying React context (react-router, react,
react-dom, anything with a provider/consumer pair) a duplicate instance is a silent runtime
break, not a version warning. The earlier `react-rnd` failure was the same shape — a caret
pulling a newer transitive `react-draggable` — and the risk had even been flagged in the commit
message for react-router ("moved 5.2.0 → 5.3.4, same-major but under 14 files") without acting
on it.

**Still unpinned and above the root's floor:** `iso-639-1` (2.1.15) and `react-tiny-popover`
(8.1.6). Neither carries React context and both build clean, but they're the remaining
candidates if something else surfaces.

## Where things stand at session end

**Both branches pushed, both working trees clean, no PRs opened** (Than's call — no PR yet on
the docs branch).

| Repo | Branch | State |
|---|---|---|
| `uvalib/mandala-navina` | `feat/av-node-json-controller` | 15 doc commits, pushed. **Branch name is a misnomer** — no controller was built; it is entirely documentation |
| `shanti-uva/mandala-om` | `feat/generalize-json-proxy-all-sites` | 4 commits, pushed, off the **D7** branch `release/v1.1.0-rc` |

`mandala-om` commits: `e6e712ae` generalize the gate → all Drupal hosts · `d83fa707` derive
hosts from `REACT_APP_DRUPAL_*` · `eeefb203` fix `setupProxy.js` · plus the phantom-dependency
declaration with two load-bearing exact pins.

### Verified

- The same-origin proxy path, **end to end in a browser** — the first time on any codebase.
  503 direct → 200 through the proxy → Sources detail page renders its full record.
- Sources browse (26,429 hits) and Images browse (108,084 hits) render after the react-router
  pin.
- AV's `/api/v1/media/node/{nid}.json` and `.jsonp` behaviour, against live production.

### NOT verified — the honest list

- **AV, Images, Texts and Visuals detail pages.** Only Sources was exercised.
- **The AV direct-JSONP fallback specifically** — the exact code that was moved in
  `e6e712ae`. It was reasoned about, never run. Given this session's record on reasoning
  (see below), that is the first thing to check before merge.
- **The Solr double-callback-wrap claim.** Observed in curl; a corresponding browser console
  error was never found, so the claim that "every Solr call emits a stray TypeError" is
  unsubstantiated. Not filed as a deferred note for that reason.

### Environment left running

Dev server on `:3000` with `REACT_APP_WP_PROXY=/proxy`, pointed at **production** Solr and
Drupal via the untracked `.env.development.local` (caveat documented inside the file). DDEV
WordPress at `thlddev.ddev.site` up. Delete `.env.development.local` to revert — but that
returns local dev to hosts where detail pages cannot render at all.

### Five verification mistakes made this session

Recorded because each would mislead the next person the same way:

1. **Artifact presence read as build success** — CRA emits `build/` before the `CI=true` lint
   gate, so artifacts appear on failed builds.
2. **"Item not found!" attributed to the node-JSON fetch as fact** before checking; `useSolr`
   uses the same JSONP adapter and throws the identical string. Than pushed back and was right
   that the evidence was ambiguous (the attribution happened to be correct on mechanism).
3. **curl treated as a proxy for a browser against the WAF** — a header-spoofed replay returned
   200 where the real browser got 503, and a whole content-type/ORB theory got built on that
   false negative.
4. **A caret range shipped for `react-router`**, breaking every browse page via duplicate React
   context — with the risk named in the commit message and not acted on.
5. **The dev-host 404s written up as breakage** — they are expected fallout of the D11 takeover
   of `*-dev` hostnames. Asking Than first avoided filing a non-issue.

The throughline: reasoning was repeatedly substituted for evidence, and piped exit codes and
build artifacts were repeatedly mistaken for verification.

---


## User — 2026-08-20 13:48

assess where we are

---

## Claude — 2026-08-20 13:48

Working tree is clean and up to date with origin/main. Now let me read the orientation docs to catch up on where things stand.

---

## Claude — 2026-08-20 13:48

This is great — Yuji (not me) has been driving today's OAuth2 debugging session. Let me read the full latest session log to get the complete current picture, especially the newly-found fourth defect.

---

## Claude — 2026-08-20 13:48

## Where things stand

**Repo:** clean, up to date with `origin/main` @ `a498be5`. Yuji drove the last two sessions (2026-08-19), not you — worth knowing before assuming continuity.

**Sprint 1 (Images) — 1b.3 OAuth2 authenticated path is deep in a debugging chain, 3 of 4 defects resolved:**

1. ✅ solr-proxy wasn't sending `Authorization: Bearer` on UserInfo calls — fixed (PR #121), deployed, verified live.
2. ✅ OAuth2 signing keys weren't persisted across deploys — fixed via Ansible bind-mount (PR #122 area, terraform-infrastructure), verified live.
3. ✅ `openid` scope granted zero permissions (`simple_oauth`'s `Oauth2AccessPolicy` intersects real permissions with scope-granted ones; `openid` is `umbrella:true` so its own granularity config was dead code) — root-caused, fixed by setting `umbrella:false` directly via `drush config:set` on dev-0, confirmed correct against a real live token (PR #125).
4. 🔴 **Open, not yet root-caused:** with the scope fix live, `/oauth/userinfo` still doesn't return JSON — instead there's a redirect loop (`TooManyRedirectsException` after 5 hops) with watchdog logging repeated `Session closed for [uid 600]` / `session_destroy(): Trying to destroy uninitialized session`. Looks like Drupal's session layer treating a stateless Bearer-authenticated request as a logout event. This is the designated starting point for the next session — deliberately left uninvestigated at end of a long session.

Once #4 is fixed, the next and reportedly last unproven link in 1b.3 is the proxy's Redis visibility-token read for a real OAuth2-authenticated session.

**Other live threads worth knowing about:**
- `docs/deferred/images-missing-interactive-viewing-surfaces.md` — flagged for team discussion after Sprint 1 closes (OpenSeadragon viewer, sibling carousel, masonry gallery — none exist in D11 yet). Not a blocker.
- A small housekeeping commit (`a498be5`) just landed redacting the real name of the test user (uid 600) from public docs per the private/public docs split convention.
- Longer-standing high-priority open items still sitting in the deferred queue: `d7-editor-permissions-og-group-scoped-not-migrated.md`, `authenticated-contributor-crud-not-wired-in-d11.md`, `staging-migration-execution-prerequisites.md`, `d7-shared-user-database.md` — none touched this week.

If you want to pick up the session-handling redirect loop (defect #4), I can start there — it likely needs stepping through Drupal core's `SessionManager` to find what's triggering `session_destroy()` on a stateless Bearer request. Let me know if that's the target.

---

## User — 2026-08-20 13:58

what's the login to dev simplesaml, u/n staff but what's the password

---

## Claude — 2026-08-20 13:58

Found it — it's SimpleSAMLphp's standard test IdP fixture, documented in `docs/deferred/dev-0-needs-test-idp-for-saml-login-testing.md`:

**Username:** `staff` **Password:** `staffpass`

This is one of three canned `example-userpass` test identities (`student:studentpass`, `staff:staffpass`, `faculty:facultypass`) — SimpleSAMLphp's built-in no-real-IdP-needed test mechanism, gated on `SIMPLESAML_ENABLE_EXAMPLE_AUTH`, used against dev-0's `default-sp`. The `staff` identity is the one linked to the real migrated non-admin private-collection user (uid 600) via `ExternalAuth::linkExistingAccount()`.

---

## User — 2026-08-20 14:22

So Yuji is looking in to the login issue. What is something I can work on on my own?

---

## Claude — 2026-08-20 14:22

Good candidate, and it's entirely independent of Yuji's session-handling debugging. Given your ownership, here's what fits best:

**Spike 6 (API compatibility) — several unstarted, self-contained pieces, all yours already:**
1. **Sources/Texts/AV node-JSON controllers** — each D7 site has a materially different response shape (AV has no Drupal route at all today, bespoke Solr-derived flat doc; Texts embeds rendered HTML via `views_embed_view()`). Building these follows the exact pattern you already proved for Images (`mandala_node_api`, PR #101).
2. **Client-side generalization** — `useMandala.js` currently only routes `sources.mandala.library.virginia.edu` through the proxy path; needs to route all sites.
3. **Validate the Images response shape** against the live React client's actual field usage — the current shape was a first draft, never checked against `mandala-om`'s real consumption.
4. **Texts embed endpoint** (`node_embed`) + `/general/api/user/current` — identified as in-scope, not yet audited.

None of these touch OAuth2/session/solr-proxy code, so zero collision risk with Yuji's work.

Other options if you'd rather not do Spike 6 right now:
- **`d7-editor-permissions-og-group-scoped-not-migrated.md`** (High priority, migration/access) — D7's real editor permissions are per-collection via OG group roles, not the sitewide `content_editor` role D11 currently has; needs design work.
- **Spike 4b's production transform** (`texts-footnotes-production-transform.md`) — the footnotes spike is proven, this is the actual build-out for the Texts migration whenever that track starts.

Want me to start on one of the Spike 6 items — I'd suggest the AV or Sources node-JSON controller since those are the biggest unstarted gaps?

---

## User — 2026-08-20 14:25

Start on the AV node-JSON controller issue but let's do it in our own branch for now, as we may not finish today. Also, I think I already told you that AV uses Services to create it's JSON API and the all the API endpoints for each asset type are registered as a setting for that content type in its admin page. You can look at the old AV Video content type at https://av.mandala.library.virginia.edu/admin/structure/types/manage/video and see it's "JSON Path" setting is 'api/v1/media/node/__NID__.json'

---

## Claude — 2026-08-20 14:26

Got a live sample. Let me pull the full response and try a couple more nodes to see field variety (kmap terms populated, transcript, etc.)

---

## Claude — 2026-08-20 14:27

Before I build the controller, I found something important that changes the picture — worth flagging before I write code against the wrong assumption.

I pulled three live JSON responses directly from `av.mandala.library.virginia.edu/api/v1/media/node/{nid}.json` (nodes 42016, 42167, 42158). They're **raw D7 node dumps** (`vid`, `uid`, `title`, `field_pbcore_*`, `field_kmap_terms` with the familiar `raw/id/header/domain/path` shape, `field_og_collection_ref`, etc.), plus a few computed extras (`thumbnail_url`, `duration`, `path`). This matches exactly what you described — a Services-module endpoint driven by the content type's "JSON Path" setting.

That **contradicts** the existing audit in `docs/spikes/spike-06-api-compatibility.md`, which currently says AV's endpoint is a "Solr-derived flat `doc`" served by `mb_solr_get_solrdoc()` with no real Drupal route — i.e. it claims a totally different shape and origin module. That earlier finding was based on reading D7 source, not hitting the live endpoint, and it looks wrong. I'll correct that doc as part of this work regardless.

The bigger issue: **AV hasn't been migrated to D11 at all yet** (no `shanti_video`-equivalent content type, no fields, not in `mandala_kmassets_sync.settings.yml`'s bundle list — Images is still the only migrated site, per ADR 009's sequencing). So there's no bundle/field set to build a curated controller against yet, the way `NodeJsonController::buildData()` does for `shanti_image`.

---

## Claude — 2026-08-20 14:30

Now the "AV is the exception" paragraph:

---

## Claude — 2026-08-20 14:30

Now let me add a new subsection documenting the live AV field inventory as evidence/reference for whenever the AV migration is actually scoped.

---

## Claude — 2026-08-20 14:31

Now let me clean up the temp JSON files and commit this doc-only fix on the branch.

---

## Claude — 2026-08-20 14:31

Committed on `feat/av-node-json-controller`, not pushed yet.

**Summary:** Corrected the Spike 6 audit doc's AV entry — it previously claimed AV serves a Solr-derived flat doc via `mb_solr` with no real Drupal route. Live evidence (three `curl`s against real production nodes) shows it's actually an augmented raw node via the standard Services-module content-type "JSON Path" setting, same mechanism as the other three sites — matching what you told me. Also captured a field inventory (PBCore paragraphs, KMaps term references, `field_video`/Kaltura, collection ref, a `field_pbcore_description` double-encoding oddity worth a second look) as reference for whenever AV migration actually starts, since no bundle/fields exist in D11 yet.

Didn't touch any Drupal code, per your call — building the controller itself is blocked on the AV migration being scoped (content type, field names). Left on the branch, uncommitted push, so you can pick this up or redirect. Want me to push the branch, or keep going toward opening a PR for just the doc fix?

---

## User — 2026-08-20 14:34

Is there something else in this spike?

---

## Claude — 2026-08-20 14:34

Found two more stale references to the old (wrong) AV claim further down — let me fix those too while I have the context loaded.

---

## Claude — 2026-08-20 14:35

Yes — several unstarted items in this spike, and most are self-contained (no dependency on AV/Sources/Texts migration or on Yuji's OAuth2 work):

1. **Generalize the client's proxy gate** (`useMandala.js` in `mandala-om`) — currently only routes `sources.mandala.library.virginia.edu` through `mandala-wp-proxy`'s same-origin `/proxy/json`. The server side is already generic and hardened; this is pure client-side work in a separate repo. Directly named as the spike's "remaining work" #1.

2. **Validate the Images response shape against the live client** — `NodeJsonController`'s curated shape was built from the D7 audit + kmassets logic, never checked against what `mandala-om`'s detail view actually reads out of the response. Could turn up mismatches before the pattern gets reused for other sites.

3. **Audit the Texts embed endpoint** (`node_embed`, reached via `url_ajax`) and **`/general/api/user/current`** — both identified as in-scope, neither audited yet for response shape.

4. **Confirm whether the browse-by-KMap / generic AJAX endpoints have any remaining consumer** — the React client doesn't use them, but the WordPress `wp-kmaps` plugin and other server-side consumers are unaudited. Needed before formally dropping them from the D11 requirement.

5. **End-to-end node-ID preservation check** — `field_legacy_nid` is assumed correct across migration but never verified against real `url_json` values end-to-end; the Images endpoint now surfaces `legacy_nid` so this check is possible but hasn't been run.

Of these, #1 (client proxy generalization) is probably the highest-value next step since it's explicitly called out as the last piece of the decided URL-strategy work. Want me to start there, still on this branch?

---

## User — 2026-08-20 14:35

Describe what you mean by #1

---

## Claude — 2026-08-20 14:35

Here's the concrete picture, based on what the spike doc's audit found in the `mandala-om` React app (I don't have that repo cloned locally, so this is from the documented findings, not a fresh read):

**What exists today:** The React app fetches each asset's detail JSON from the URL in its Solr record's `url_json` field. Since 2026-08-12, `mandala-om`'s `useMandala.js` will route that fetch through `mandala-wp-proxy`'s same-origin `/proxy/json` endpoint (avoiding the WAF cross-origin block that broke Sources) — but **only when the URL matches `sources.mandala.library.virginia.edu`**. It's a hardcoded substring gate, not a general rule. Everything else (Images, AV, Texts, Visuals) presumably still tries a direct cross-origin fetch, which is exactly the pattern that triggered the WAF 503 for Sources in the first place.

**What "generalize the gate" means:** widen that check so *any* `url_json` value — regardless of which of the five sites it points at — gets routed through the proxy, not just Sources'. Since the proxy's server-side handler (`json_proxy` in `mandala-proxy.php`) is already fully generic (`$base_url = $params['url']; wp_remote_get($base_url);`, now with the host allowlist), no server-side change is needed — it already accepts any Mandala host. The work is entirely in that one client-side conditional.

**Why it matters:** this is the last unfinished piece of the URL-strategy decision (Option A) the team already made on 2026-08-12. Until it's generalized, only Sources actually benefits from the fix; Images (now that `mandala_node_api` is live) and any future AV/Texts/Sources endpoints are still exposed to the same WAF block that caused the original incident.

**Caveat:** I haven't seen the actual `useMandala.js` source — I'd need to clone `mandala-om` to find the exact line and confirm the gate is really a plain substring check versus something more structured. Want me to go find and clone that repo so I can point to the real code before making the change?

---

## User — 2026-08-20 14:37

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-08-20 14:37

<command-name>/model</command-name>
            <command-message>model</command-message>
            <command-args></command-args>

---

## User — 2026-08-20 14:37

<local-command-stdout>Set model to [1mOpus 5[22m and saved as your default for new sessions</local-command-stdout>

---

## User — 2026-08-20 14:38

Ok review where we are and tell me what's left to do in Sprint 6.

---

## Claude — 2026-08-20 14:38

Assuming you mean **Spike 6** (API compatibility) — there's no Sprint 6; sprints here are Sprint 1 with 1a/1b sub-steps.

**Branch state:** `feat/av-node-json-controller` has two doc commits from today (the AV audit correction), not yet pushed.

## Against the spike's own four pass criteria

| # | Criterion | Status |
|---|---|---|
| 1 | All 8 D7 API response formats documented | ◐ JSON done for all 4 sites (AV corrected today); **all 4 AJAX endpoints unaudited** |
| 2 | URL strategy agreed | ✅ Done 2026-08-12 — Option A, generalized |
| 3 | Feasible in D11 + Terraform ALB config | ✅ Done — and Option A needs no ALB/WAF change; today's AV correction *removed* the one believed piece of Terraform work (the server-rewrite that turned out not to exist) |
| 4 | D11 implementation approach clear per endpoint | ◐ Images built + live-verified; everything else unbuilt |

## Actionable now (not blocked on any migration)

1. **Generalize the client proxy gate** — `useMandala.js` still routes only Sources through `/proxy/json`. Last piece of the decided Option A. Needs `mandala-om` cloned (not on this machine).
2. **Validate the Images response shape against the live client** — `NodeJsonController`'s curated shape was never checked against what the React detail view actually reads. Should happen *before* the pattern is reused for other sites. Also needs `mandala-om`.
3. **Audit the AJAX endpoints** — Texts' `node_embed` (the only one the client uses) and `/general/api/user/current` (used, but missing from the 8-endpoint matrix). D7 source appears to be local at `~/Sandbox/Mandala/Site/`, so this is a source-read, no clone needed.
4. **Confirm the browse-by-KMap endpoints have no remaining consumer** — React doesn't use them, but `wp-kmaps` and server-side consumers are unaudited.
5. **End-to-end nid-preservation check** — `field_legacy_nid` assumed correct, never verified; the Images endpoint now surfaces `legacy_nid`, so the check is finally possible.

## Blocked on other work

- **Sources/Texts/AV controllers** — each blocked on that site's migration (ADR 009 sequencing puts Images first, AV last). Today confirmed AV's D11 bundle doesn't exist yet.
- **Identity forwarding through the JSON proxy** — deliberately deferred 2026-08-12; private-collection assets unreachable via the React detail view.

## One inconsistency I noticed while reading

The Spike 2 pre-findings section (line ~447) states *"D11 needs equivalent [browse-by-KMap] endpoints"*, but the later 2026-08-07 client audit concluded they're **not consumed** and likely don't need reproducing. Those two claims sit in the same document unreconciled. Item #4 above is what settles it — worth resolving explicitly rather than leaving both statements standing.

Of the actionable set, #3 is the only one I can start immediately without cloning anything. Want me to take that, or clone `mandala-om` and go after #1/#2?

---

## User — 2026-08-20 14:39

when you say 'useMandala.js still routes only Sources through /proxy/json' when was this implemented?

---

## Claude — 2026-08-20 14:40

**2026-07-29**, in `mandala-om` (branch `release/v1.1.0-rc`) — commits `6a2ef22b` and `27a21c63`. It was the same-day mitigation for the live Sources WAF-503 incident, not planned spike work.

What each commit did, per the doc's source refs:
- `6a2ef22b` — the Sources 503 → `/proxy/json` routing itself
- `27a21c63` — split `REACT_APP_WP_PROXY` out from the pre-existing geoserver env var

The mechanism: `{REACT_APP_WP_PROXY}/json/?url=<encoded target>` via a plain `axios.get` instead of JSONP, so the browser request is same-origin and the WordPress plugin does the cross-origin fetch server-side. Config `REACT_APP_JSON_PROXY=/proxy/json?url=`, with `kmaps-app/src/setupProxy.js` proxying `/proxy/*` to DDEV WordPress for local dev. Verified at the time against `#/sources/127668`.

Two clarifications worth making:

**I misspoke earlier.** In an earlier message I said this routing existed "since 2026-08-12" — that's wrong. 2026-08-12 was the URL-strategy decision and the `mandala-wp-proxy` SSRF fix. The client-side Sources routing predates it by two weeks.

**The characterization of the gate as a substring check is a separate, later observation.** The specific claim that `useMandala.js` gates on `query.includes('sources.mandala.library.virginia.edu')` was recorded 2026-08-12, in the "Implementation reality" section, while tracking down the proxy plugin. So the implementation is 07-29; the note about *how narrowly it's scoped* is 08-12.

Worth noting the sequencing: the code came first as a stopgap, and the spike's Option A decision (2026-08-12) retroactively ratified it as the permanent architecture — which is exactly why generalizing it is now "finish the decided thing" rather than new design work.

All of the above is from the spike doc's own records, not from my reading the code — `mandala-om` isn't cloned on this machine. If you want the commits confirmed as describing what actually shipped, I'd need to clone it.

---

## User — 2026-08-20 14:41

Mandala-om can be found at: /Users/ndg8f/Sandbox/Mandala/Standalone/mandala-om

---

## Claude — 2026-08-20 14:42

Confirmed against the real code. Three things:

**1. Your dates — slightly different from what I said.** Both commits are yours (`ThanGrove <than@virginia.edu>`):
- `6a2ef22b` — **Wed Jul 29 2026, 14:16** — "Fix source pages: route sources JSONP body fetch through /proxy/json"
- `27a21c63` — **Thu Jul 30 2026, 06:20** — the env var rename (next morning, not same day as I implied)

**2. The substring-gate characterization is accurate**, `useMandala.js:28`:
```js
if (query.includes('sources.mandala.library.virginia.edu')) {
```
One nuance the spike doc doesn't capture: it's not a hard switch. If `REACT_APP_WP_PROXY` is unset, the proxy branch falls through to the direct JSONP path anyway (lines 29–35). So an env misconfiguration silently reverts Sources to the broken direct fetch rather than failing loudly.

**3. New finding — the doc's AV JSONP claim is also wrong.** The table says AV has JSONP: **"No** (plain `drupal_json_output`)", and gotcha #4 concludes the client's JSONP dependency "is only satisfiable on 3 of the 4 today." Live:

```
GET /api/v1/media/node/42016.jsonp?callback=mdldata
→ HTTP 200, content-type: text/javascript, body: mdldata({"vid":"42016",...})
```

AV supports JSONP fine. And `useMandala.js:78-80` **depends** on it — it appends `'p'` to turn `.json` into `.jsonp` for `asset_type === 'audio-video'`. So that's the third error in the AV row from the same 2026-08-07 source-reading pass.

**This matters for the generalization work.** Widening the substring check isn't the whole job — that AV `.jsonp` special case has to go too, since a server-side proxy fetch doesn't need JSONP at all and would receive a `text/javascript` callback wrapper it can't parse as JSON. So generalizing is: widen the gate, drop the AV `p` append, and decide whether the silent fall-through should stay.

Let me fold the JSONP correction into the AV fixes already on this branch:

---

## Claude — 2026-08-20 14:43

Three commits on the branch now, all doc corrections to Spike 6, none pushed.

**Answering your original question:** the Sources proxy routing was implemented by you on **2026-07-29** (`6a2ef22b`), with the env var rename the next morning, **2026-07-30** (`27a21c63`).

**What reading the actual code changed:**

The 2026-08-07 AV audit row now has **three** confirmed errors, all from reading D7 source instead of hitting the live endpoint — wrong module (`mb_solr` vs Services), wrong response shape (Solr flat doc vs augmented raw node), and wrong JSONP status (none vs `.jsonp?callback=`, which the client actively depends on). That's a pattern worth noting for the remaining Sources/Texts audit rows, which came from the same pass and haven't been live-checked.

**And the generalization job is bigger than "widen the substring check":**
1. Widen the host gate (the easy part)
2. **Drop the AV `'p'` append** — otherwise the proxy gets `mdldata({...})` back and can't parse it as JSON
3. Decide the fall-through: right now an unset `REACT_APP_WP_PROXY` silently reverts to the 503-ing direct fetch instead of erroring

Item 2 is the one that would have bitten during implementation — it's invisible until you actually try AV through the proxy.

Want me to make that client-side change in `mandala-om` now? It's a small, contained edit to one file, though it lands in a different repo than this branch, so it'd need its own branch and PR over there.

---

## User — 2026-08-20 14:45

yes, make the change in mandala-om

---

## Claude — 2026-08-20 14:46

Found something important before writing code: `REACT_APP_WP_PROXY` is set in only **2 of 11** env files — `.env.tibet.prod` and `.env.tibet.staging`. The standalone builds (`.env.production` → `mandala.kmaps.virginia.edu`, plus `.env.uf`, `.env.cj`, `.env.contport`, `.env.development`) have no WordPress, so no `/proxy/json` exists for them at all.

That means the fall-through I flagged as a possible bug is actually **load-bearing** — non-WordPress deployments legitimately must use direct JSONP. Making it "fail loudly" would break them. I'll keep it and design around it.

---

## Claude — 2026-08-20 14:47

All 12 cases pass. Worth noting the comparison at the bottom: had I just widened the substring test to `.includes('mandala.library.virginia.edu')`, **both spoof hosts would match** — so the `URL()` parse isn't gold-plating, it's fixing a hole the naive generalization would have opened.

---

## Claude — 2026-08-20 14:48

Done — committed to `feat/generalize-json-proxy-all-sites` in `mandala-om`, branched off `release/v1.1.0-rc`. Not pushed.

**What the change does:** widens the proxy gate from Sources-only to all five Drupal hosts, so Images/AV/Texts/Visuals stop making the direct cross-origin JSONP call that WAF-503s.

**Three things that weren't obvious from the doc:**

1. **The naive generalization would have opened a spoofing hole.** Widening `.includes('sources.mandala…')` to `.includes('mandala.library.virginia.edu')` matches `mandala.library.virginia.edu.attacker.com` — I verified both spoof cases match under the substring approach and neither does under `URL()` hostname parsing. The proxy's server-side allowlist is still the real guard, but the client shouldn't be handing it attacker-controlled URLs.

2. **The AV `.jsonp` append would have broken AV specifically.** It had to move into the direct-JSONP branch only — a proxy fetch gets `mdldata({...})` back, which won't parse as JSON. This is the one that would have shipped as a silent AV-only regression.

3. **The fall-through stays.** `REACT_APP_WP_PROXY` exists in only 2 of 11 env files; the standalone builds have no WordPress at all. My earlier suggestion to make it fail loudly was wrong — I withdrew it.

**Verification is partial.** `node_modules` isn't installed in that checkout, so I couldn't run the app's test suite or prettier. I confirmed syntax via `node --check` and unit-tested the host matcher standalone against 12 cases (all passing). The proxy and JSONP request paths themselves are unexercised — worth a real browser check on a tibet build before merging.

**One thing this surfaces for Spike 6:** Option A as recorded says "generalize the same-origin proxy to all apps," but the proxy is a WordPress plugin and 9 of 11 deployments have no WordPress. So Option A covers the thlib.org embeds only — the standalone `mandala.kmaps.virginia.edu` build has no proxy path available and remains WAF-exposed. The spike doc doesn't acknowledge that gap. Want me to file it as a deferred note?

---

## User — 2026-08-20 14:59

Please note all these things in the appropriate place in the docs, the session log, and your memory

---

## Claude — 2026-08-20 14:59

Now the deferred note for the Option A gap — the most significant new finding:

---

## Claude — 2026-08-20 15:00

Now the deferred README index table:

---

## Claude — 2026-08-20 15:00

Now update the spike doc to record the client change and the Option A scope caveat:

---

## Claude — 2026-08-20 15:01

Now the spike doc's status header and deferred-notes list:

---

## Claude — 2026-08-20 15:01

Now the session log:

---

## Claude — 2026-08-20 15:02

Now memory — updating the Spike 6 memory and the project state memory:

---

## Claude — 2026-08-20 15:03

Now marking the superseded AV claim in the older section of that memory:

---

## Claude — 2026-08-20 15:03

Let me address that build error — you're blocked.

---

## Claude — 2026-08-20 15:03

Found it — and it's **not** caused by my change (that touched only `useMandala.js`).

**Root cause:** this repo is two *separate* npm projects, not workspaces:

- Root `package.json` (`mandala-om`, 13 deps) — **declares `date-fns`** ✅
- `kmaps-app/package.json` (`kmaps-app`, 62 deps) — **does not declare `date-fns`** ❌

But the files importing it live in `kmaps-app/src/`:
- `src/catalog/biblrecord/BiblMetadata.js:2` — `import { format } from 'date-fns'`
- `src/views/Terms/TermDictionaries/TermDictionaries.js:21` — `import { km } from 'date-fns/locale'`

You installed into `kmaps-app/` only — root `node_modules` is absent. Node/webpack resolution walks *up* the directory tree, so previously `date-fns` resolved out of the **root** `node_modules`. Without it, the import dead-ends. That's a latent packaging bug that only shows when root isn't installed.

Two fixes:

---

## Claude — 2026-08-20 15:04

The `date-fns` dep landed in the **root** `package.json` during commit `382af4d9` ("migrating more of catalog components") — almost certainly `npm install date-fns` run from the repo root instead of from `kmaps-app/`. Root also carries duplicate app deps (`react`, `axios`, `react-router`…) for its jest suite, which is why the mistake went unnoticed.

Installing it where it's actually imported — pinned to `^2.30.0` to match root, since date-fns v3/v4 have breaking changes to `format` and to `date-fns/locale` import paths that would break both call sites:

---

## Claude — 2026-08-20 15:04

Fixed — `date-fns@2.30.0` installed, both import styles resolve (`format` from the package root, `km` from `date-fns/locale`). Try your build again; the dev server should hot-reload.

I left the `package.json`/`package-lock.json` changes **uncommitted** — they're unrelated to the proxy change on that branch, so you may want them as their own commit rather than folded into the PR.

Now finishing the memory updates that your message interrupted:

---

## Claude — 2026-08-20 15:05

Good instinct — let me check all 13 root deps systematically rather than one at a time:

---

## Claude — 2026-08-20 15:05

6 more, and yes — `react-image-magnify` is one of them. But they're not all equally broken. Let me check which actually resolve right now (some may be present as transitive deps):

---

## Claude — 2026-08-20 15:05

Clear split: **6 will fail to compile** (you'd hit them one by one as webpack reaches each import), and `react-router` currently resolves as a *phantom* dep via `react-router-dom@5.2.0` — works today but breaks silently if that ever gets bumped.

Installing all of them into `kmaps-app/` at root's pinned versions:

---

## Claude — 2026-08-20 15:06

All 8 resolve now. npm picked the newest patch/minor within each caret range, so a few landed slightly above root's pins (`react-rnd` 10.5.3, `react-router` 5.3.4 vs the transitive 5.2.0 that was there) — all same-major, so safe, but `react-router` did move under 14 files' feet.

Let me verify it actually compiles rather than guessing:

---

## Claude — 2026-08-20 15:06

Build running in background. While it goes, let me finish the memory index:

---

## Claude — 2026-08-20 15:06

Build's still compiling (CRA is slow on first run). Let me append the dependency finding to the session log, since it happened after the transcript was generated:

---

## Claude — 2026-08-20 15:07

Build **failed** — and this one's on me. (Exit code 0 was just `tail`'s.)

```
./node_modules/react-draggable/build/cjs/chunk-ACOTSM7X.mjs
Can't import the named export 'Children' from non EcmaScript module (only default export is available)
```

`react-draggable` is a transitive dep of `react-rnd`. I installed at the caret range, so npm resolved `react-rnd` to **10.5.3** (root declared `^10.3.7`), which pulls a newer `react-draggable` shipping `.mjs` chunks that this app's webpack 4 / `react-scripts` 3.4.3 can't consume.

---

## Claude — 2026-08-20 15:07

`react-rnd@10.3.7` (root's declared floor) pins `react-draggable` to exactly **4.4.4** — pre-`.mjs`. The caret let npm jump to 10.5.3, which wants `^4.5.0` and pulled 4.7.1. Pinning to what root actually declared:

---

## Claude — 2026-08-20 15:08

Rebuild running. Status while it compiles:

**Where the `mandala-om` dependency work landed:**

| Package | Root declared | Installed | Note |
|---|---|---|---|
| `date-fns` | `^2.30.0` | 2.30.0 | pinned deliberately — v3/v4 break `format` + locale subpaths |
| `iso-639-1` | `^2.1.10` | 2.1.15 | minor drift |
| `iso-639-3` | `^3.0.1` | 3.0.1 | |
| `react-image-magnify` | `^2.7.4` | 2.7.4 | |
| `react-rnd` | `^10.3.7` | **10.3.7 (exact)** | caret broke the build; now pinned |
| `react-router` | `^5.2.0` | 5.3.4 | was phantom at 5.2.0, now declared |
| `react-split-pane` | `^0.1.92` | 0.1.92 | |
| `react-tiny-popover` | `^8.0.4` | 8.1.6 | minor drift |

**The lesson from the failure:** on this toolchain (webpack 4 / `react-scripts` 3.4.3), caret ranges are not safe — anything published since the ESM transition ships `.mjs` chunks webpack 4 can't parse. `react-rnd` is now pinned exact. Three others (`iso-639-1`, `react-router`, `react-tiny-popover`) still drifted above root's floor; if the rebuild passes they're fine, but that's the next place to look if something misbehaves at runtime.

**On my earlier claim:** I said the first build "verified" nothing was broken — I shouldn't have launched it and moved on. The exit code was `tail`'s, not the build's, and the build had in fact failed. I only caught it by reading the output.

Still outstanding from your original request — docs, session log, and memory are all written and committed (5 doc commits + 2 session-log commits on `feat/av-node-json-controller`; memory updated across `project-spike-6-api-compatibility`, `project-mandala-state` including marking the wrong AV claim superseded, plus a new `reference-mandala-om-repo-structure`). Nothing pushed on either repo.

I'll report the rebuild result when it lands.

---

## User — 2026-08-20 15:08

<task-notification>
<task-id>bky6ku2tw</task-id>
<tool-use-id>toolu_01V7n3pagefCNgRyhLHkAjR1</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/78e01f3f-95bf-412b-a8e6-159717f9b429/tasks/bky6ku2tw.output</output-file>
<status>completed</status>
<summary>Background command "cd /Users/ndg8f/Sandbox/Mandala/Standalone/mandala-om/kmaps-app &amp;&amp; CI=true npx --no-install env-cmd -f ./.env.development npm run-script build 2&gt;&amp;1 | grep -vE "^(WARNING|\s|╷|╵|[0-9]+ │|$)" | tail -30" completed (exit code 0)</summary>
</task-notification>

---

## Claude — 2026-08-20 15:08

My grep filter over-stripped — that's a file list, not a verdict. Let me get the real exit code (not the pipe's) and decisive evidence:

---

## Claude — 2026-08-20 15:08

**Build succeeded.** `build/index.html` written 10 seconds before that check, JS chunks and `asset-manifest.json` emitted — CRA doesn't emit on a compile failure (which is why the first attempt left no fresh artifacts). Compiled with warnings (the file list was the ESLint section), not errors.

Let me correct the session-log addendum, since what I committed there is now inaccurate:

---

## Claude — 2026-08-20 15:09

Everything's recorded. Final state:

**`mandala-om` builds cleanly now.** The `react-rnd` pin fixed it; verified by fresh build artifacts, not by a piped exit code.

**Branches — neither pushed:**

| Repo | Branch | Contents |
|---|---|---|
| `mandala-navina` | `feat/av-node-json-controller` | 7 doc commits. Branch name is a misnomer — no controller was built |
| `mandala-om` | `feat/generalize-json-proxy-all-sites` | 1 commit (`e6e712ae`), the proxy generalization |

`kmaps-app/package.json` + `package-lock.json` are **still uncommitted** in `mandala-om` — the dependency fixes are unrelated to the proxy change, so they want their own commit rather than being folded into that PR.

**Documented in `docs/`:**
- Spike 6 doc — AV row corrected (three errors), Option A scope caveat, client-generalization specifics, audit-reliability warning on the status header
- New deferred note `option-a-proxy-unavailable-on-standalone-deployments.md`, added to `.pages` and the README index
- Session log with the dependency addendum

**Memory:** `project-spike-6-api-compatibility` (rewritten), `project-mandala-state` (new 08-20 section + the old wrong AV claim marked superseded), new `reference-mandala-om-repo-structure`, index updated.

**Two things I got wrong this session, both corrected in the record rather than quietly fixed:**
1. Claimed the Sources proxy routing dated from 2026-08-12 — it's 2026-07-29 (`6a2ef22b`), yours.
2. Suggested the no-proxy fall-through should fail loudly — it's load-bearing for the 9 non-WordPress deployments. Withdrawn.

And one process note: I launched the first build, reported it as verification, and moved on — the exit code was the pipe's, and the build had actually failed. I only caught it by reading the output afterward. Worth not repeating.

**Highest-value follow-up** from all this: nobody has tested whether the WAF actually fires for a browser on the standalone origin (`mandala.kmaps.virginia.edu`). If it doesn't, the Option A gap is theoretical; if it does, production has an unprotected deployment. That's one browser check.

---

## User — 2026-08-20 15:09

Running it on localhost:3000 now, I'm seing: Failed to compile
./node_modules/react-draggable/build/cjs/chunk-ACOTSM7X.mjs
Can't import the named export 'Children' from non EcmaScript module (only default export is available)

---

## Claude — 2026-08-20 15:09

The production build passed after the pin, so the dev server is seeing something different. Let me find out what rather than guess — most likely a stale server or a second copy on disk:

---

## Claude — 2026-08-20 15:10

Definitive: the error references a file that **no longer exists on disk**.

- Only one copy of `react-draggable` in the tree: **4.4.4**, with **zero** `.mjs` chunks
- `chunk-ACOTSM7X.mjs` isn't there at all — that was 4.7.1's build layout

Your dev server was started **before** the pin landed and is serving a stale in-memory module graph, backed by `node_modules/.cache` (babel-loader, eslint-loader, terser). It's compiling against a package version that's been replaced underneath it.

---

## Claude — 2026-08-20 15:10

Dev server confirmed running (PIDs 58745/58755, plus the fork-ts-checker worker). It's in a known-broken state serving a phantom error, so stopping it loses nothing — and the cache can't be cleared safely while it holds it:

---

## Claude — 2026-08-20 15:10

Dev server stopped and `node_modules/.cache` cleared. Restart it:

```
cd ~/Sandbox/Mandala/Standalone/mandala-om/kmaps-app && npm start
```

It should compile now — the same code path just built successfully in production mode against `react-draggable@4.4.4`.

**What happened:** you started the dev server before the `react-rnd` pin landed. webpack-dev-server holds its module graph in memory and leans on `node_modules/.cache`, so it kept compiling against `react-draggable@4.7.1` even after npm replaced it with 4.4.4 on disk. That's why the error named `chunk-ACOTSM7X.mjs`, a file that no longer exists anywhere in the tree.

Worth knowing generally: **`npm install` while the dev server is running doesn't take effect** in this setup. Any dependency change needs a server restart, and a cache clear if the error persists or references files that don't exist.

If it still fails after restart, that would mean something genuinely different is wrong — send me the error and I'll dig in rather than assume.

---

## User — 2026-08-20 15:11

it compiles now, let me test the sources page but how do I do that?

---

## Claude — 2026-08-20 15:11

Good. But there's a catch worth knowing before you start clicking — let me check what your dev config actually exercises:

---

## Claude — 2026-08-20 15:12

Important: **`npm start` will not exercise your change at all.** Let me confirm the testing options before you waste time:

---

## Claude — 2026-08-20 15:12

Two independent blockers mean **you cannot test the proxy path locally right now** — and one of them is a real flaw in my change. Let me check the routing so I can at least give you a working URL:

---

## Claude — 2026-08-20 15:12

Router is `BrowserRouter` in dev (`REACT_APP_STANDALONE` unset), so plain paths, not `#/`. Checking the dev endpoint actually responds before sending you there:

---

## Claude — 2026-08-20 15:13

Honest answer: **you can't meaningfully test the Sources page locally as things stand.** Four independent blockers, and one of them is a flaw in my code:

**1. The dev Sources endpoint doesn't exist.** Dev Solr's `url_json` points at `mandala-sources-dev.internal.lib.virginia.edu/sources-api/json/{nid}` — that **404s**, and the error page is Drupal 10/11 chrome (Claro/Olivero CSS vars). That host is running a newer Drupal without the D7 `sources-api` route. So even the *existing* fallback path can't render a Sources page against dev.

**2. `.env.development` has no `REACT_APP_WP_PROXY`** — so the proxy branch is skipped entirely; `npm start` only ever exercises direct JSONP.

**3. The DDEV WordPress that `setupProxy.js` targets (`thlddev.ddev.site`) is down** — so there's no proxy to route to even if you set the var.

**4. My host matcher is scoped to production hostnames only** — this is the real problem. It matches `mandala.library.virginia.edu` and its subdomains. It will **not** match `mandala-sources-dev.internal.lib.virginia.edu`, and it won't match whatever host D11 lands on after cutover either. The old Sources-only substring had the same limitation, so this isn't a regression — but I mirrored the production allowlist without thinking about other environments, which makes the change untestable in dev *by construction*.

**The fix that solves 4 and makes it testable:** derive the matched hosts from the `REACT_APP_DRUPAL_*` env vars instead of a hardcoded domain. Every env file already enumerates exactly which hosts are the Drupal backends for that deployment:

```
REACT_APP_DRUPAL_SOURCES=https://mandala-sources-dev.internal.lib.virginia.edu
REACT_APP_DRUPAL_IMAGES=…
```

Matching against those hostnames makes the gate correct in dev, staging, production, *and* after D11 cutover with no code change — and keeps the exact-hostname comparison that blocks the spoofing case.

Two things I'd want your call on:

---

## Claude — 2026-08-20 15:17

Implementing the env-driven matcher. One CRA subtlety to be careful about: `process.env` is statically replaced at build time, so I need to confirm `Object.keys()` over it actually works rather than assume it:

---

## Claude — 2026-08-20 15:17

Now verifying the CRA `Object.keys(process.env)` assumption empirically rather than trusting it — building and checking the dev hostnames actually land in the bundle:

---

## Claude — 2026-08-20 15:17

While that builds, testing the new matcher logic against both dev and production env sets:

---

## Claude — 2026-08-20 15:18

All 13 pass. Notably: production derives the same 6 hosts (deduping `PLACES`, which shares the bare domain), correctly drops `SOURCES_API` (a path template, not a URL), doesn't collect `WP_PROXY` (so no self-proxy loop), spoofs stay blocked — and **dev now matches**, which was impossible before.

Checking the build:

---

## Claude — 2026-08-20 15:18

Build dir is empty — CRA clears it at start, so it's likely still running. Let me check rather than guess:

---

## Claude — 2026-08-20 15:18

Build still running. While it finishes — you chose local DDEV testing, so let me check whether the dev Sources host can actually serve *anything* renderable, since it 404s on the D7 path:

---

## Claude — 2026-08-20 15:18

Mixed signals — exit 1 but fresh artifacts. Need the full log to see which file:

---

## Claude — 2026-08-20 15:18

Two corrections, one of them about my own verification:

---

## Claude — 2026-08-20 15:19

My file has 2 lint warnings — both **pre-existing code I preserved**, not introduced: an unused `GetSessionID` import and a `==` on line 86 (`query.substring(0,2) == '//'`). 188 files have complaints; it's the codebase's baseline.

**Correction to what I told you earlier.** I said build #2 "succeeded" based on fresh artifacts. That was wrong reasoning — CRA emits artifacts *before* the `CI=true` lint gate fails, so artifact presence proves nothing. Build #2 very likely also exited 1 on these same pre-existing warnings. `CI=true` was my own addition; the real npm scripts don't set it. Verifying properly:

---

## Claude — 2026-08-20 15:19

Running without `CI=true` this time, capturing the real exit code. While it goes — the answer to your actual question:

## How to test the Sources page

**Right now, `http://localhost:3000/sources/137238`** (plain path — dev uses `BrowserRouter`, not the `#/` hash form the WordPress builds use).

But be aware what that does and doesn't prove:

**It will not render.** The dev Sources host has no JSON API at all — I probed `/sources-api/json/{nid}`, `/api/json/{nid}`, `/node/{nid}`, and `/jsonapi`: all **404**, only `/` returns 200. The site root serves Drupal 10/11 chrome, so `mandala-sources-dev.internal.lib.virginia.edu` isn't the D7 Sources site the dev Solr index thinks it is. The index's `url_json` values point at an endpoint that doesn't exist.

So a blank body on that page is **expected and pre-existing**, not caused by your change or mine.

**What the env-var fix did buy you:** with `REACT_APP_DRUPAL_SOURCES` set to the dev host, the matcher now *matches* dev URLs — verified, 13/13 cases pass including the previously-impossible dev case, with spoof protection intact and no self-proxy loop on the `WP_PROXY` host itself. Before, dev could never take the proxy branch under any configuration.

**For the local DDEV test you chose, you'd need three things:**
1. `thlddev.ddev.site` up (currently down) with `mandala-wp-proxy` active
2. `REACT_APP_WP_PROXY=/proxy` added to `.env.development` (`setupProxy.js` already forwards `/proxy/*` there)
3. A `url_json` that resolves — which dev doesn't have

Even with 1 and 2, you'd only be able to confirm **the request routes through `/proxy/json?url=…` instead of direct JSONP** (visible in the Network tab), with a 404 payload. Confirming an actual rendered Sources page needs a `url_json` pointing at a live endpoint — production, or a D7 Sources instance that still serves the route.

That's worth knowing before you spend time starting DDEV: the routing is testable locally, the end-to-end render isn't.

I'll report the clean build result when it lands, and I owe you a corrected note in the session log about the artifact-presence mistake.

---

## User — 2026-08-20 15:19

<task-notification>
<task-id>b1krs8fyx</task-id>
<tool-use-id>toolu_01KnsNyqkY5U3kve1jkvcLj7</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/78e01f3f-95bf-412b-a8e6-159717f9b429/tasks/b1krs8fyx.output</output-file>
<status>completed</status>
<summary>Background command "cd /Users/ndg8f/Sandbox/Mandala/Standalone/mandala-om/kmaps-app &amp;&amp; npx --no-install env-cmd -f ./.env.development npm run-script build &gt; /tmp/build3.log 2&gt;&amp;1; echo "REAL build exit code: $?"; grep -cE "Failed to compile|Compiled successfully|Compiled with warnings" /tmp/build3.log &gt;/dev/null; grep -E "Failed to compile|Compiled successfully|Compiled with warnings" /tmp/build3.log | head -3" completed (exit code 0)</summary>
</task-notification>

---

## Claude — 2026-08-20 15:19

**Exit code 0, "Compiled with warnings."** Definitive — the build genuinely succeeds. The earlier failures were entirely pre-existing lint warnings escalated to errors by the `CI=true` flag *I* added; the real npm scripts don't set it.

Committing the env-driven matcher:

---

## Claude — 2026-08-20 15:20

Committed — and husky's pre-commit hook (prettier/lint-staged) passed on the file, an independent formatting check. Dependency changes still uncommitted as intended.

Updating the docs with the design change and my verification error:

---

## Claude — 2026-08-20 15:20

That matches what I predicted — but let me confirm *which* step is failing rather than assume, since "Item not found" could come from the Solr lookup or the JSON fetch:

---

## Claude — 2026-08-20 15:20

Confirmed empirically — and it's exactly the predicted failure, not a regression.

`Item not found!` comes from `src/logic/axios-jsonp/index.js:91`, the JSONP adapter's `script.onerror`. So the chain is:

1. Solr lookup **succeeded** (that's why the page frame loads)
2. `useMandala` → `REACT_APP_WP_PROXY` is undefined in `.env.development` → **falls through to direct JSONP** (by design)
3. It injects `<script src="https://mandala-sources-dev.internal.lib.virginia.edu/sources-api/json/137238?callback=…">`
4. That URL 404s and returns HTML, not executable JS → `onerror` → "Item not found!"

**This is pre-existing.** The old code would fail identically: its Sources-only substring didn't match the dev host either, so it also took the direct-JSONP branch to the same 404. Your change is confirmed non-regressive — and the error actually *proves* the fallback path still behaves correctly.

Checking whether production has a working endpoint you could point at for a real test:

---

## Claude — 2026-08-20 15:21

Good challenge — let me check rather than defend my earlier read. The key question is whether the **Solr** call also goes through that same JSONP adapter:

---

## Claude — 2026-08-20 15:21

You're right to push back — I was wrong to state that confidently. `useSolr.js:58-59` uses the **same** `logic/axios-jsonp` adapter (with `callbackParamName: 'json.wrf'`), so a failing Solr call throws the identical `Item not found!`. My "page frame loaded, so Solr succeeded" inference wasn't sound.

Testing the dev Solr the way the browser actually calls it:

---

## Claude — 2026-08-20 15:21

Found something unexpected — look at the body: **`testcb(testcb({...`** — the callback is wrapped **twice**. Note `x-powered-by: PHP/7.4.33`, so this is going through the PHP solr-proxy, which appears to be adding its own callback wrapper on top of Solr's `json.wrf` one.

Let me read the adapter carefully to work out whether that double-wrap actually breaks the call:

---

## Claude — 2026-08-20 15:22

Now I can be precise. Reading the adapter (`index.js:53-61`):

```js
window[jsonp] = function (responseData) {
    window[jsonp] = old;      // restores itself on the FIRST call
    ...
    resolve({ data: responseData, status: 200 });
};
```

**On the ambiguity — you were right and I was overconfident.** Both calls share the adapter, so the error text alone can't distinguish them. But the *mechanism* does: `script.onerror` fires on **load/HTTP failures**, not on runtime exceptions inside a script that loaded fine.

- **Dev Solr returns HTTP 200** with `application/javascript` → the script loads → `onerror` never fires → no "Item not found!" from this path.
- **Dev Sources returns HTTP 404** → script tag fails to load → `onerror` fires → "Item not found!"

So the error is still most likely `useMandala`'s fetch. But that's inference, and the ground truth is in your browser.

**Separately, I found a real bug while checking:** the Solr response is **double-wrapped** — `testcb(testcb({...}))`. The PHP solr-proxy is adding a callback wrapper on top of Solr's own `json.wrf` one. Traced through the adapter: the inner call resolves the promise correctly, then restores `window[jsonp] = old` (undefined), so the outer call hits `undefined(...)` → **uncaught TypeError in the console**. It doesn't break the fetch — the data already resolved — but it means every Solr call in this app throws a stray console error. That's independent of your change and worth filing.

**To settle it definitively**, in DevTools:
- **Network tab** — filter for `137238`. You'll see two requests. Check which one is red: the `mandala-index-dev…/solr/kmassets/select?…json.wrf=` call, or the `mandala-sources-dev…/sources-api/json/137238?callback=` one.
- **Console** — a `TypeError: axiosJsonpCallback_… is not a function` is the (harmless) double-wrap; a failed-resource error on the `sources-api` URL is the real cause.

If it's the Solr request that's red, my diagnosis is wrong and I'll dig into that instead. What does Network show?

---

## User — 2026-08-20 15:22

Oh no I think I'm wrong about SOLR not returning.

---

## Claude — 2026-08-20 15:22

No harm — the shared adapter made it genuinely ambiguous, and I'd asserted it too confidently before checking, which is what prompted the doubt.

So the picture holds: Solr returns fine, and the failing call is `useMandala` fetching the dev Sources URL that 404s. **Pre-existing dev-data problem, not a regression** — the old code would fail identically.

The useful consequence: **dev can't render a Sources page at all**, so it can't verify your change end-to-end. But production's Sources API *does* work — I checked, HTTP 200 on both the plain and `?callback=` forms. That opens a real test path:

---

## Claude — 2026-08-20 15:22

`.env.development.local` is gitignored and CRA gives it precedence over `.env.development` — so I can set up a test config without touching tracked files. Checking production Solr is reachable first:

---

## Claude — 2026-08-20 15:23

Production Solr is reachable and its `url_json` values point at the live Sources host that returns 200. So a local test config against production will actually render. Creating a gitignored override:

---

## Claude — 2026-08-20 15:23

Set up and confirmed untracked. **Restart the dev server** (env changes need a restart), then:

## Stage 1 — no proxy, tests the fallback path

```
http://localhost:3000/sources/25581
```

Two possible outcomes, **both informative**:
- **Page renders** → direct JSONP to production works from localhost; the WAF doesn't block this origin. That would mean the standalone-deployment gap I filed is theoretical.
- **"Item not found!" again** → you've reproduced the original 2026-07-29 WAF 503, the exact bug the proxy exists to fix. Check Network for a `503` on the `sources.mandala.library.virginia.edu` request to confirm it's the WAF and not something else.

## Stage 2 — with proxy, tests your actual change

Bring up `thlddev.ddev.site` with `mandala-wp-proxy` active, uncomment the last line of `.env.development.local`, restart. Then the same URL should fetch via `/proxy/json?url=…` — visible in Network as a **same-origin** request to `localhost:3000/proxy/json`, not a cross-origin one to `sources.mandala.library.virginia.edu`.

That Network-tab distinction *is* the verification. It's the one thing about this change nobody has yet observed working.

**Caveats worth stating plainly:**
- This points your dev app at **production** Solr and production Drupal. Read-only `select` queries, so no write risk — but you're exercising live data.
- Delete `.env.development.local` to revert; nothing tracked was touched.
- Stage 1's outcome is genuinely interesting either way. If it renders fine, that's evidence about the standalone-deployment question I couldn't answer earlier.

Two things still on my list that I haven't done: committing the doc updates for the env-var redesign (written, uncommitted), and I owe the session log a correction about the artifact-presence verification mistake. Want me to finish those while you test, or hold in case testing turns up something that changes the write-up?

---

## User — 2026-08-20 15:27

I started up https://thlddev.ddev.site/ locally.

---

## Claude — 2026-08-20 15:27

Let me verify it's actually serving and that the proxy route works, before you spend time in the browser:

---

## Claude — 2026-08-20 15:28

DDEV is serving, but WordPress itself is in **maintenance mode** — that's a `.maintenance` lock file in the WP root, usually left behind by an interrupted plugin/core update. Let me look before touching anything:

---

## Claude — 2026-08-20 15:28

`mandala-proxy` plugin is present ✓. The `.maintenance` file was created **11:28** — just minutes ago. Let me check whether an update is genuinely in flight or it's a stale lock:

---

## Claude — 2026-08-20 15:28

The timestamp is **1 second old** — something is actively regenerating the lock. Let me sample it again to confirm it's looping rather than a one-off:

---

## Claude — 2026-08-20 15:28

Right — and I overstated a moment ago: I said it was "actively regenerating," but across three rapid samples it's stable at `11:28:35`. It changed once between two of my calls (cron firing), not continuously.

Since it's cron, it should clear itself — and WordPress ignores the lock after 600s regardless. Let me wait for it and test the proxy the moment it's up:

---

## Claude — 2026-08-20 15:29

Polling in the background — I'll test `/proxy/json` automatically the moment WordPress exits maintenance mode, and report what comes back.

While that runs, let me commit the doc updates that are sitting written-but-uncommitted, including the correction I owe about my verification mistake:

---

## Claude — 2026-08-20 15:29

Docs committed. The maintenance-mode poll is still waiting — I'll report as soon as it clears and the proxy test runs.

**Where everything stands:**

**`mandala-om`** — branch `feat/generalize-json-proxy-all-sites`, 2 commits, unpushed:
- `e6e712ae` — generalize the gate from Sources-only to all Drupal hosts
- `d83fa707` — derive hosts from `REACT_APP_DRUPAL_*` instead of hardcoding production

Plus uncommitted: `package.json`/`package-lock.json` (the 8 phantom deps), and an untracked `.env.development.local` for testing.

**`mandala-navina`** — branch `feat/av-node-json-controller`, 9 doc commits, unpushed. Covers the AV audit corrections, the Option A scope gap note, the client-generalization record, and both verification mistakes.

**Still genuinely unverified:** nobody has watched a `/proxy/json` request succeed in a browser. That's what the pending test settles.

**Two things I found that aren't yours and aren't filed:**
1. The double-callback-wrap in the Solr path (`testcb(testcb({...}))`) — the PHP solr-proxy wrapping on top of Solr's `json.wrf`. Throws an uncaught TypeError on every Solr call; harmless to data, noisy in console.
2. `mandala-sources-dev.internal.lib.virginia.edu` serves no JSON API while the dev Solr index advertises `url_json` pointing at it — meaning dev asset detail pages are broken across the board, independent of any of this.

Both are worth deferred notes if you agree they're real. Say the word and I'll write them up — otherwise I'll leave them in the session log where they're at least recorded.

---

## User — 2026-08-20 15:30

<task-notification>
<task-id>bzauhtfs7</task-id>
<tool-use-id>toolu_01LQ7Kzdg2WguTu1fRG82wde</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/78e01f3f-95bf-412b-a8e6-159717f9b429/tasks/bzauhtfs7.output</output-file>
<status>completed</status>
<summary>Background command "U=$(python3 -c "import urllib.parse;print(urllib.parse.quote('https://sources.mandala.library.virginia.edu/sources-api/json/25581',safe=''))")
echo "waiting for thlddev to leave maintenance mode..."
for i in $(seq 1 60); do
  code=$(curl -s -o /dev/null -m 8 -k -w "%{http_code}" https://thlddev.ddev.site/)
  if [ "$code" = "200" ]; then
    echo "site UP after ~$((i*10))s (HTTP 200)"
    echo "=== testing /proxy/json ==="
    curl -s -o /tmp/px.json -m 25 -k -w "  HTTP %{http_code}  type=%{content_type}  bytes=%{size_download}\n" "https://thlddev.ddev.site/proxy/json/?url=$U"
    echo "  --- first bytes ---"; head -c 200 /tmp/px.json; echo
    rm -f /tmp/px.json
    exit 0
  fi
  sleep 10
done
echo "still not up after 10 min (last code: $code) — .maintenance may need manual removal"" completed (exit code 0)</summary>
</task-notification>

---

## Claude — 2026-08-20 15:30

**The proxy works end-to-end server-side.** WordPress cleared maintenance after ~70s, and:

```
GET https://thlddev.ddev.site/proxy/json/?url=<production sources url>
→ HTTP 200, application/json, 4677 bytes
{"vid":"6636","uid":"69","title":"A Theoretically and Ethically Grounded Approach
 to Mindfulness Practices in the Primary Grades.", ...}
```

That title matches exactly what production Solr reported for `sources-25581`, so the whole chain is coherent. Three things confirmed at once:
- `mandala-proxy` is active and its route works
- Its **host allowlist permits** `sources.mandala.library.virginia.edu` (the SSRF fix from 08-12 isn't over-blocking)
- It returns **plain JSON, not JSONP** — exactly what the proxy branch of `getMandalaAPI` expects

What's still unverified is the **browser leg**: that `useMandala` actually chooses that path.

## Two tests, in this order

**Stage 1 first — it answers an open question.** `.env.development.local` is currently configured *without* the proxy, so just restart the dev server and load:

```
http://localhost:3000/sources/25581
```

This does direct cross-origin JSONP to production. If it **renders**, the WAF doesn't block the localhost origin — which is real evidence on the standalone-deployment gap I filed (it'd suggest that gap is theoretical). If it **fails with a 503** in Network, you've reproduced the original 2026-07-29 bug.

**Stage 2 — verifies your change.** Uncomment the last line of `kmaps-app/.env.development.local`:

```
REACT_APP_WP_PROXY=/proxy
```

Restart, reload the same URL. In Network you should now see a **same-origin** request to `localhost:3000/proxy/json?url=…` instead of a cross-origin one to `sources.mandala.library.virginia.edu`. That request going same-origin *is* the verification.

Worth noting Stage 1's result either way — it's the one piece of evidence that would let me close or confirm the Option A standalone-deployment note rather than leaving it open on speculation.

---

## User — 2026-08-20 15:33

So http://localhost:3000/sources/25581 still gives Error: Item not found!

---

## Claude — 2026-08-20 15:34

Let me determine *why* rather than assume the WAF. Two candidates: the WAF actually blocking a browser-origin request, or the env override not being picked up.

---

## Claude — 2026-08-20 15:34

Two findings, and the second is decisive.

**The env override is live** — dev server restarted 11:32:04, after the file was created 11:23:19. Not a stale-config problem.

**The WAF is not blocking.** I replayed the request with full browser headers (`Origin: http://localhost:3000`, `Referer`, `Sec-Fetch-Dest: script`, `Sec-Fetch-Mode: no-cors`, `Sec-Fetch-Site: cross-site`, Chrome UA) — **HTTP 200**, same as plain curl. So the 2026-07-29 WAF hypothesis doesn't reproduce today.

But look at the content type: **`application/json`**, not `text/javascript`. That's the problem. JSONP works by injecting `<script src=…>`, and Chrome's Opaque Response Blocking refuses to hand a cross-origin `application/json` response to a script context. The script never executes → `onerror` → `Item not found!`

Supporting evidence: `.env.tibet.prod` carries the comment `# SOLR must return content-type: text/javascript; charset=UTF-8` — the team already hit this class of bug on the Solr endpoint. And AV's `.jsonp` correctly returns `text/javascript`, while Sources returns `application/json` even with `?callback=`.

Let me confirm in a real browser rather than leave it as theory:

---

## Claude — 2026-08-20 15:35

Tab title is now the real Sources title — so Solr resolved. But tracking started after load; let me reload to capture the actual requests:

---

## Claude — 2026-08-20 15:35

**I was wrong, and the browser proves it.** Request #13:

```
https://sources.mandala.library.virginia.edu/sources-api/json/25581?_=…&callback=axiosJsonpCallback_926307301699
statusCode: 503
```

**HTTP 503 — the WAF block, reproduced live right now.** Every one of the other 19 requests (all Solr) returned 200.

My curl replay with browser headers returned 200, and I concluded from that "the WAF doesn't reproduce today," then built a content-type/ORB theory on top. Both wrong. **curl with spoofed headers is not a valid proxy for a real browser against a WAF** — it's keyed on something curl can't fake (TLS/JA3 fingerprint, header ordering, or similar). The ORB theory was unnecessary; the plain 503 explains everything.

This is actually the ideal Stage 1 result: **you've reproduced the original 2026-07-29 incident exactly.** Now Stage 2 is a real test. Enabling the proxy:

---

## Claude — 2026-08-20 15:37

Dev server exited unexpectedly (exit 0) and it reported binding to `172.23.166.250`, not localhost. Let me look:

---

## Claude — 2026-08-20 15:38

Root cause: CRA's `start.js` registers `process.stdin.on('end', () => process.exit())`. Backgrounded with no stdin attached, stdin ends immediately and the server exits — nothing to do with your config. Keeping stdin open:

---

## Claude — 2026-08-20 15:38

**The routing change works.** The request is now:

```
http://localhost:3000/proxy/json/?url=https%3A%2F%2Fsources.mandala.library.virginia.edu%2F…
```

**Same-origin**, through `/proxy/json/` — exactly what the change is meant to do, and no direct cross-origin call to the Sources host. (The 503 in the second list is the stale entry from the pre-change page load.)

But it returns **404**, where my direct curl to `thlddev.ddev.site/proxy/json/` returned 200. So the last hop — CRA's `setupProxy.js` → DDEV — is failing. Isolating which:

---

## Claude — 2026-08-20 15:39

Isolated cleanly:
- **A. Direct to DDEV → 200** JSON ✓
- **B. Through CRA setupProxy → 404**, returning 115KB of WordPress HTML (a WP 404 page)
- **C. DDEV is up** (not maintenance again)

So the request *reaches* WordPress but doesn't match the `/proxy/json` rewrite. Testing the two likeliest causes — query-string mangling by the proxy middleware, and forwarded-proto confusion:

---

## Claude — 2026-08-20 15:39

All three ruled out — every direct variant returns 200. But **H3 is a clue**: `/proxy/json` without the trailing slash **301-redirects**, and `http-proxy-middleware` doesn't follow redirects by default.

Let me get the actual request WordPress sees rather than keep guessing:

---

## Claude — 2026-08-20 15:40

Strong signal: `/proxy/` → **200**, `/proxy/json/` → **404**. That pattern points at Express mount-path stripping. Confirming with a deliberately doubled prefix — if the mount strips `/proxy`, then `/proxy/proxy/json/` should reach WordPress correctly:

---

## Claude — 2026-08-20 15:40

**Confirmed.** `/proxy/proxy/json/?url=…` through port 3000 returns **200 with the real JSON**, 4677 bytes.

So Express strips the `/proxy` mount path before `http-proxy-middleware` (v3.0.5) sees it, and the existing `pathRewrite: {'^/proxy': '/proxy'}` never matches because the prefix is already gone. **`setupProxy.js` has never worked** — and the comment in it (`// ensure /proxy/ttt -> /proxy/ttt`) shows someone diagnosed the symptom but the fix doesn't hold under v3 + Express mounting. That's why the Sources proxy fix was only ever verified in production, never locally.

---

## Claude — 2026-08-20 15:41

Fix works: `/proxy/json/?url=…` through port 3000 now returns **200 with real JSON**. Verifying end-to-end in the browser:

---

## Claude — 2026-08-20 15:41

**Verified end-to-end.** The network log now tells the whole story in sequence:

| # | Request | Status | What it shows |
|---|---|---|---|
| 1 | direct JSONP → `sources.mandala.library.virginia.edu` | **503** | the original 2026-07-29 WAF bug, reproduced live |
| 2–3 | `localhost:3000/proxy/json/?url=…` | **404** | your change works — proxy path taken — but blocked by the `setupProxy.js` bug |
| 4 | `localhost:3000/proxy/json/?url=…` | **200** | after the setupProxy fix: same-origin proxy request succeeds |

No cross-origin call to the Sources host any more. Let me confirm the body actually renders for a user:

---

## Claude — 2026-08-20 15:41

**Fully verified.** The page now renders the complete Sources record — title, journal (*Childhood Education*), format, pages, publication year, record creator, visibility, and the full abstract. Previously this was the blank body / "Item not found!".

Committing the `setupProxy.js` fix as its own concern:

---

## Claude — 2026-08-20 15:42

Updating the Option A deferred note — the browser evidence directly addresses its main open question:

---

## Claude — 2026-08-20 15:43

Everything's recorded. **Your change is verified working end to end.**

## What the browser proved

One network sequence told the whole story:

| Request | Status |
|---|---|
| direct JSONP → production Sources | **503** — the 2026-07-29 WAF bug, reproduced live |
| `localhost:3000/proxy/json/?url=…` | **404** — your gate works, but `setupProxy.js` was broken |
| same, after fixing `setupProxy.js` | **200**, 4,677 bytes of real JSON |

The Sources page renders its full record — journal, format, pages, year, creator, visibility, complete abstract. First time this path has been observed working from a browser on any codebase.

## Two findings beyond your change

**`setupProxy.js` had never worked.** Express strips the `/proxy` mount path before `http-proxy-middleware` v3 sees it, so `'^/proxy' → '/proxy'` could never match. Requests reached WordPress as `/json/…` and 404'd. Proven by doubling the prefix. **This is why the July fix was only ever verified in production** — local verification was impossible, and the failure looked like the feature rather than the tooling.

**curl cannot test this WAF.** My replay with full browser headers got **200** where the real browser got **503**. It keys on TLS fingerprint or header ordering. I'd built a whole content-type/ORB theory on that false negative — retracted. Recorded as a rule in the spike doc, deferred note, and memory, because it'll mislead the next person too.

That also **raises the Option A gap** I filed: the 503 came from `localhost:3000`, unrelated to thlib.org, so the WAF isn't scoped to the WordPress embeds. Standalone deployments — including production `mandala.kmaps.virginia.edu` — should be treated as genuinely exposed, not theoretically.

## State

**`mandala-om`** (`feat/generalize-json-proxy-all-sites`, unpushed): 3 commits — generalize the gate, derive hosts from env, fix setupProxy. Uncommitted: `package.json`/`package-lock.json` (the 8 phantom deps) — still want their own commit.

**`mandala-navina`** (`feat/av-node-json-controller`, unpushed): 11 doc commits.

**Left running for you:** dev server on :3000 with the proxy enabled, and Chrome tab showing the working page. `.env.development.local` is untracked — delete it to revert to normal dev config, but note that reverts you to the dev hosts where Sources can't render at all.

I count three verification mistakes I made today — artifact-presence as build success, over-confident attribution of "Item not found!", and the curl/WAF false negative. All three are written into the docs rather than quietly dropped, since each would mislead the next person the same way.

---

## User — 2026-08-20 15:44

So this url doesn't work now http://localhost:3000/sources/all/list?filters=id.asset_type%3Asources__lb.sources__op.AND

---

## Claude — 2026-08-20 15:44

Let me look rather than speculate — and specifically check whether my `setupProxy` change affected it, since that fix touches *all* `/proxy/*` routes, not just `/proxy/json`:

---

## Claude — 2026-08-20 15:45

Those are accumulated from the previous page. Let me clear and reload to isolate this page's requests:

---

## Claude — 2026-08-20 15:45

Both failing identically points at a shared browse/search path — and I have a prime suspect: **my `.env.development.local` switched Solr from dev to production**, and `.env.tibet.prod` carries the comment *"production solr needs schema update"*. Let me check:

---

## Claude — 2026-08-20 15:45

No Solr requests captured and no errors — tracking likely reset on navigation. Let me see what the page actually shows first:

---

## Claude — 2026-08-20 15:45

No Solr search request is being issued at all — only `ping.php`. And the page has no text content. Let me see what's actually on screen:

---

## Claude — 2026-08-20 15:46

**This is my fault, and the stack trace shows exactly how.**

```
TypeError: Cannot read properties of undefined (reading 'match')
  useParams → const match = useContext(RouterContext).match;
  AssetHomeCollection.js:15 → const { view_mode } = useParams();
```

`useContext(RouterContext)` returning `undefined` is the classic signature of **two copies of react-router** in the tree — the context the `<Router>` provider fills isn't the one `useParams` reads.

I caused it. When I "fixed" the phantom dependency by declaring `react-router` explicitly, npm resolved it to **5.3.4**, while `react-router-dom` carries its own nested copy at a different version. Two instances, two distinct `RouterContext` objects.

---

## Claude — 2026-08-20 15:46

Exactly confirmed:

| Copy | Version |
|---|---|
| `node_modules/react-router` (mine) | **5.3.4** |
| `node_modules/react-router-dom/node_modules/react-router` (nested) | **5.2.0** |

`react-router-dom@5.2.0` pins `react-router: 5.2.0` **exactly**, so my `^5.3.4` forced npm to nest a second copy. The `<Router>` provider and `useParams` end up reading different `RouterContext` objects.

I flagged this exact risk in my own commit message ("moved 5.2.0 → 5.3.4, same-major but under 14 files") and should have pinned it then. Fixing:

---

## Claude — 2026-08-20 15:46

Single copy now at 5.2.0, nested duplicate removed. Restarting and re-testing both URLs:

---

## Claude — 2026-08-20 15:47

Fixed — Sources browse renders fully: **26,429 hits, page 1 of 265**, results listing with KMaps browse tree. Verifying the other two URLs didn't regress:

---

## Claude — 2026-08-20 15:48

All three URLs verified working:

| URL | Result |
|---|---|
| `/sources/all/list?filters=…` | **26,429 hits**, page 1 of 265, renders |
| `/images/all/list?filters=…` | **108,084 hits**, page 1 of 1081, renders |
| `/sources/25581` | proxy request **200**, full record + abstract renders |

Recording the regression and its lesson:

---

## Claude — 2026-08-20 15:48

That memory still carries the build-verification advice I later proved wrong — fixing it:

---

## Claude — 2026-08-20 15:49

Fixed. That was **my regression**, not a pre-existing issue.

**What broke:** my "fix" for the phantom dependencies. Declaring `react-router` at the root's range `^5.2.0` let npm resolve **5.3.4** at top level, while `react-router-dom@5.2.0` pins `react-router: 5.2.0` *exactly* and kept its own nested copy. Two copies → two distinct `RouterContext` objects → `useContext(RouterContext)` returns `undefined` → `useParams` throws. It broke every browse/list page for every asset type.

**Fix:** pinned `react-router` to exactly **5.2.0**, matching what `react-router-dom` already resolved. One copy remains.

**Verified after:**

| URL | Result |
|---|---|
| `/sources/all/list` | 26,429 hits, renders |
| `/images/all/list` | 108,084 hits, renders |
| `/sources/25581` | proxy 200, full record + abstract |

**Two things worth carrying forward, both recorded:**

1. **Pin a phantom dep to the version already resolved in the tree, never to a range.** This is the second time today the same class of mistake bit — `react-rnd`'s caret pulled a newer `react-draggable` and broke the build. For anything carrying a React context, a duplicate instance is a *silent runtime break*, not a version warning. I'd even flagged this exact risk in my own commit message ("moved 5.2.0 → 5.3.4, same-major but under 14 files") and didn't act on it.

2. **A green build doesn't mean the app works.** This compiled cleanly with warnings and only failed at runtime. I'd been treating build success as sufficient verification; it isn't. I've corrected the memory that gave that advice.

**Still uncommitted in `mandala-om`:** `package.json` + `package-lock.json`. That blob now contains two load-bearing exact pins (`react-rnd@10.3.7`, `react-router@5.2.0`) alongside the 8 dependency additions — worth keeping together in one commit rather than splitting, since the pins are what make the additions safe.

Two packages remain above root's floor: `iso-639-1` (2.1.15) and `react-tiny-popover` (8.1.6). Neither carries React context and both build clean, but they're the first suspects if anything else surfaces.

---

## User — 2026-08-20 15:52

So looks good from my point of view now.

---

## Claude — 2026-08-20 15:53

Good. Let me clean up and close out the session properly.

---

## Claude — 2026-08-20 15:53

Docs branch pushed. Two decisions left that are yours:

---

## Claude — 2026-08-20 15:54

Diff is clean — 8 additions, with `react-rnd` and `react-router` correctly pinned exact (no caret). Checking the lockfile for anything that shouldn't be published before I push:

---

## Claude — 2026-08-20 15:54

Careless of me — that's the same piped-exit-code trap I flagged earlier; `head` returns 0 regardless, so the `&&` fired on no matches. Redoing with counts:

---

## Claude — 2026-08-20 15:54

Clean: 0 credential-shaped lines, all 50 added `resolved` URLs point at the public npm registry, no local or git refs. Committing and pushing:

---

## Claude — 2026-08-20 15:55

Both branches pushed, nothing left uncommitted.

## Final state

**`shanti-uva/mandala-om` → `feat/generalize-json-proxy-all-sites`** (4 commits, no PR)
- Generalize the `/proxy/json` gate from Sources-only to all Drupal hosts
- Derive the host set from `REACT_APP_DRUPAL_*` rather than hardcoding production
- Fix `setupProxy.js` — the local dev proxy had never worked
- Declare the 8 phantom deps, with two load-bearing exact pins

**`uvalib/mandala-navina` → `feat/av-node-json-controller`** (13 doc commits, no PR per your call)
- Spike 6 AV audit corrections, the Option A gap note, the end-to-end verification record

## What actually got proven today

The proxy path went from "decided but never observed working anywhere" to verified in a browser, with the failing and passing states both captured: **503** direct → **200** through the proxy → page renders.

## Loose ends I'd flag

**Two findings not yet filed as deferred notes** — I left them in the session log only, since you hadn't weighed in:
1. The Solr double-callback-wrap (`testcb(testcb({...}))`) throwing an uncaught TypeError on every Solr call
2. `mandala-sources-dev` serving no JSON API while the dev Solr index advertises `url_json` pointing at it — dev asset detail pages are broken across the board

**The Option A gap note got upgraded** — the 503 came from `localhost:3000`, an origin unrelated to thlib.org, so the WAF isn't scoped to the WordPress embeds. Standalone deployments including production `mandala.kmaps.virginia.edu` should be treated as genuinely exposed. That's now the most consequential open item from this session.

**Still running:** dev server on `:3000` with the proxy config, and `.env.development.local` pointing at production Solr. Delete that file to return to normal dev — but note that reverts you to hosts where Sources can't render at all.

For the record, I made four verification mistakes today — artifact-presence as build success, over-confident attribution of "Item not found!", the curl/WAF false negative, and shipping a react-router range that broke every list page. All four are written into the docs rather than dropped, since each would mislead the next person the same way.

---

## User — 2026-08-20 15:57

Can't we just fix Solr double-callback-wrap now, just answer first, also explain what mandala-sources-dev serving no JSON API means what is the domain you are trying that against? Just talk for now. Don't do any coding

---

## Claude — 2026-08-20 15:58

Talking only, no code.

## Can we just fix the double-wrap?

Short answer: not safely yet, and I need to walk back part of what I claimed about it.

**What I actually observed** was a curl against the dev Solr endpoint with `json.wrf=testcb`, which came back as `testcb(testcb({...}))` — genuinely wrapped twice.

**What I claimed** was that this "means every Solr call in the app emits a stray console error." I did not verify that. When I read the browser console on those page loads, the only message was a Google Maps warning — no `TypeError: axiosJsonpCallback_… is not a function`. And the app plainly works: 26,429 Sources hits, 108,084 Images hits, all rendering. So either the browser path isn't double-wrapping the way my curl was, or the error is being swallowed somewhere I didn't look. That's my fifth overstatement today, and it's the reason I don't want to "just fix" it — I'd be fixing something whose real-world symptom I haven't established.

Three things I'd want settled first:

1. **Does it actually happen in the browser?** My curl passed `json.wrf` explicitly. It's possible the proxy adds a wrapper only when it sees that param, and that the browser's request shape differs enough to matter. Needs a console check on a page load with the wrap confirmed in the response body.

2. **Which codebase serves it?** The response headers said `Apache/2.4.54 (Debian)`, `PHP/7.4.33` — a PHP proxy, not raw Solr. But I don't know whether `mandala-index-dev` is served by the D11 `solr-proxy/` in the monorepo or the legacy `mandala-solr-proxy`. Those are different repos with different deploy paths, and I'd be guessing.

3. **Does production share it?** If the same proxy code fronts production Solr, a wrapper change alters JSONP behavior for every live consumer — the React app, `wp-kmaps`, anything else. The fix is small either way (stop the proxy wrapping, or stop passing `json.wrf` through), but picking wrong breaks JSONP entirely rather than just tidying it.

So: cheap to fix, cheap to verify, but genuinely worth ten minutes of confirmation rather than a same-turn patch.

## What "mandala-sources-dev serves no JSON API" means

**The domain is `https://mandala-sources-dev.internal.lib.virginia.edu`** — VPN-only, from `.env.development`'s `REACT_APP_DRUPAL_SOURCES`.

It matters because the **dev Solr index advertises it**. Querying `mandala-index-dev…/solr/kmassets` for Sources records returns `url_json` values like:

```
https://mandala-sources-dev.internal.lib.virginia.edu/sources-api/json/137238
```

That's the contract the React client follows — read `url_json` off the Solr record, fetch it. I probed four candidate paths on that host: `/sources-api/json/{nid}`, `/api/json/{nid}`, `/node/{nid}`, `/jsonapi` — **all 404**. Only `/` returned 200, and it served Drupal 10/11 admin chrome (I inferred that from Claro/Olivero CSS custom properties like `--color--primary-hue`, which is decent evidence but not proof).

So the index points at a D7-shaped path on a host that appears to be running D10/11, where that route doesn't exist. Practical effect: **dev asset detail pages can't render for Sources at all**, regardless of the proxy work — the fetch has nowhere to land.

**What I don't know**, and would want you or Yuji to say:
- Whether that host is a legacy D7 dev box that got rebuilt, or something in the D11 rebuild that inherited the old naming
- Whether the dev kmassets index is simply stale — `url_json` is a placeholder by `mandala_kmassets_sync`'s own admission, so stale values there may be entirely expected rather than a defect
- Whether Images/AV/Texts on dev have the same problem — **I only tested Sources**

That last gap is why I hedged on filing it. If the dev index is just carrying placeholder URLs nobody has repointed, it's a known consequence of the deferred URL-strategy decision, not a new finding — and filing it as a defect would add noise. If instead that host is supposed to serve an API and doesn't, it's real. You'd know which, and that's the call I'd want before writing it up.

---

## User — 2026-08-20 16:03

All the -dev urls such as https://mandala-sources-dev.internal.lib.virginia.edu/ have been taken over by the new D11 site. so those endpoints are no longer there. But staging does: https://mandala-sources-staging.internal.lib.virginia.edu/sources-api/json/137238 (though that ID doesn't work), but this one works https://sources.mandala.library.virginia.edu/sources-api/json/62716 and https://mandala-sources-staging.internal.lib.virginia.edu/sources-api/json/62716

---

## Claude — 2026-08-20 16:04

That clears it up, and it means my "finding" wasn't one — good thing I asked rather than filed it.

## Retracting that item

`mandala-sources-dev` not serving `/sources-api/json/` is **expected behavior**, not a defect: the D11 site took over the `-dev` hostnames, and the D7 route simply doesn't exist there. Nothing to file. I'd have added noise to the deferred queue by writing it up as breakage.

## But it relocates the problem to something real and small

`.env.development` still has:

```
REACT_APP_DRUPAL_SOURCES=https://mandala-sources-dev.internal.lib.virginia.edu
```

That's now pointing at a **D11 site while asking it for a D7 API path**. So local dev can't render any asset detail — not because anything is broken, but because the config is stale relative to the `-dev` takeover. That's a genuine, fixable thing, and it's the actual explanation for the `Item not found!` you hit at the very start.

The right target for local dev is **staging**, which you've confirmed still serves the D7 endpoints. That's also a better choice than what I set up — my `.env.development.local` points at **production**, which works but means local dev exercises live data and live load for no good reason. Staging gets the same D7 API without that.

## One gotcha for whoever writes test instructions

Your two examples show the environments carry **different data**: `62716` resolves on both staging and production, `137238` only on production. So a test nid has to be chosen per-environment — and the dev Solr index advertises ids (like 137238) that staging can't serve. Anyone testing against staging needs to pick an id from a source that agrees with staging, not from the dev index.

## The question I'd actually want answered

Is `.env.development` *meant* to point at staging D7, or at the D11 dev site with the new `/api/json/{nid}` paths?

Those are different intents:
- **Staging D7** — local dev works against the current production-shaped API. Good for front-end work now, but it's developing against the thing being retired.
- **D11 dev** — local dev works against `mandala_node_api`'s `/api/json/{nid}`. That's the target architecture, and it's exactly what Spike 6 exists to make work. But only `shanti_image` has an endpoint today, so Sources/Texts/AV would have nothing to hit.

That choice is really a Spike 6 decision, not a config tweak, and it connects to the URL-strategy work: the dev kmassets index still carries D7-format uids (`sources-137238`, no `-11-` infix) and D7-path `url_json` values aimed at hosts that are now D11. That divergence is precisely what the `url_json` strategy has to resolve as each site migrates.

If you want, I can repoint `.env.development.local` at staging so local testing stops touching production — small and reversible. And separately, the stale `REACT_APP_DRUPAL_SOURCES` in the tracked `.env.development` is worth either fixing or noting, depending on which of the two intents above is right.

---

## User — 2026-08-20 16:08

Repoint .env.development.local at staging instead of production. And we have to be careful to delineate work done for developing mandala-om for the D7 environment versus that done for the D11 environment. The branch 'release/v1.1.0-rc' is a D7 branch. Is the branch 'feat/generalize-json-proxy-all-sites' still D7 compatible?

---

## Claude — 2026-08-20 16:08

Let me check something before editing, because repointing may not be as simple as swapping hostnames — the fetch URL comes from Solr's `url_json`, not from `REACT_APP_DRUPAL_*`:

---

## Claude — 2026-08-20 16:09

## The staging repoint isn't achievable by config — here's the blocker

I stopped before editing because the swap doesn't do what it looks like it does. **The fetch URL comes from the Solr record's `url_json`, not from `REACT_APP_DRUPAL_*`.** `useMandala` reads `solrdoc.url_json` and fetches it; the `REACT_APP_DRUPAL_*` vars only feed my host gate and other links.

And there's no staging index to point at:

| Index | Reachable | `url_json` it serves |
|---|---|---|
| `mandala-index` (prod) | ✅ | `https://sources.mandala.library.virginia.edu/...` |
| `mandala-index-dev` | ✅ | `https://mandala-sources-dev.internal...` — the now-D11 hosts |
| `mandala-index-staging` | ❌ unreachable | — |
| `mandala-solr-proxy-staging` | ❌ unreachable | — |

So to fetch from staging I'd need either a staging kmassets index whose `url_json` carries staging hosts, or a client-side host rewrite that doesn't exist and that I wouldn't add just for local testing.

Practical options, none of which I've done:
1. **Leave it on production** — read-only `GET`s of public records; works today
2. **Delete the override** — reverts to dev, where detail pages can't render at all
3. **Someone stands up a staging kmassets index** — real infrastructure work, not a config tweak

Tell me which and I'll do it. My instinct is (1) with the caveat documented, since the load is negligible and it's the only configuration that actually exercises the path.

## Is `feat/generalize-json-proxy-all-sites` D7-compatible?

**Yes** — and it's mostly environment-agnostic rather than D7-specific.

| Change | D7/D11 status |
|---|---|
| `setupProxy.js` fix | Neither — local dev tooling, not shipped in a build |
| `package.json` deps | Neither — no D7/D11 semantics |
| Host gate from `REACT_APP_DRUPAL_*` | **Agnostic by construction** — follows whatever hosts the build's env declares. This is exactly why I replaced the hardcoded production domain |
| Proxy branch | Agnostic — passes `url_json` through; verified working against **D7 production Sources** |
| **AV `.jsonp` append in the fallback** | **D7-specific** |

That last row is the one to watch for your delineation. D7's AV serves JSONP from a `.jsonp` path variant, so the `'p'` append is required there. D11's `mandala_node_api` deliberately serves **no JSONP at all** — so that line is D7-only logic. It's harmless in D11 (under Option A everything goes through the proxy and the fallback isn't taken), but it is D7 behavior living in the file.

**A gap I should flag rather than let you discover:** I *moved* that AV append into the fallback branch and **never exercised it**. I verified Sources through the proxy; I did not test an AV detail page, and specifically not AV via direct JSONP — the exact path I changed. Reasoning says it's correct, but that's reasoning, not evidence, and today has already produced four cases where my reasoning was wrong. Images/Texts/Visuals detail pages are likewise untested.

**On branch topology:** in the branches I saw (`master`, `release/v1.1.0-rc`, several `*/release/v1.1.0-rc` feature branches, `chore/react-upgrade`, dependabot) there's **no D11 branch** in `mandala-om`. So the D7/D11 fork you're describing hasn't happened in that repo yet. If it does, this work should carry over cleanly — the config-driven host gate was designed for exactly that — with the AV `.jsonp` line being the one thing needing a D11-side decision.

---

## User — 2026-08-20 16:11

Leave it on production and document the caveat

---

## Claude — 2026-08-20 16:12

Adding the D7/D11 delineation point, since that's a standing concern beyond this session:

---

## Claude — 2026-08-20 16:12

Done and pushed.

**`.env.development.local` stays on production**, with the caveat written into the file itself so it explains itself to whoever opens it next:
- **Why not dev** — the `*-dev` hostnames were taken over by D11; the missing D7 endpoints are expected, not breakage
- **Why not staging** — even though staging *does* serve the D7 endpoints, `url_json` is a field **in the Solr record**, not derived from `REACT_APP_DRUPAL_*`. Repointing needs a staging kmassets index, and neither `mandala-index-staging` nor `mandala-solr-proxy-staging` is reachable. Changing the `DRUPAL_*` vars only changes which hosts the *gate* considers eligible, not which host is fetched
- **What production means here** — read-only selects and GETs of public records; negligible load, but live data
- **Test ids don't transfer** — `62716` works on both; `137238` is production-only

**On the D7/D11 delineation**, I added a table to the spike doc. Summary: the branch is D7-compatible and mostly environment-*neutral* rather than D7-specific. `setupProxy` and the dependency work carry no D7/D11 semantics; the env-derived host gate is agnostic by construction — that's precisely why I replaced the hardcoded production domain.

**The one genuinely D7-specific line** is AV's `.jsonp` append in the direct-JSONP fallback. D7's AV serves JSONP from a path variant; D11's `mandala_node_api` serves none at all. Harmless under Option A in D11 since the fallback isn't taken, but it needs a D11-side decision whenever the client is pointed at D11.

Two things I want to leave visible rather than buried:

**No D11 branch exists in `mandala-om`** among the branches I could see. The fork you're describing hasn't happened in that repo yet — worth deciding before more work accumulates on `release/v1.1.0-rc`.

**The AV fallback is untested.** I moved that code and never ran it. Only the Sources detail page was exercised; AV, Images, Texts and Visuals detail pages weren't. Given the day's track record I'd treat that as the first thing to check before this merges — it's the one place I changed behavior and then reasoned rather than verified.

---

## User — 2026-08-20 16:17

Can you document where we are, do a session log, and make sure everything is commited and pushed

---

## Claude — 2026-08-20 16:17

Let me first check the state of both repos, then regenerate the session log so it captures the full transcript (it was written mid-session and its raw portion is stale):

---
