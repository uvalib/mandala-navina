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
10/11 chrome. That host is not the D7 Sources site the dev index assumes. A dev Sources page
therefore renders blank regardless of this change — pre-existing dev-data breakage, not a
regression. Local verification requires pointing at production (whose Sources API returns 200)
via an untracked `.env.development.local`, plus the DDEV WordPress for the proxy leg.

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

## Branches left open

| Repo | Branch | State |
|---|---|---|
| `mandala-navina` | `feat/av-node-json-controller` | 5 doc commits, unpushed. Branch name is now a misnomer — no controller was built |
| `mandala-om` | `feat/generalize-json-proxy-all-sites` | 1 commit (`e6e712ae`), unpushed, browser-unverified |

---

*Below is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

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
