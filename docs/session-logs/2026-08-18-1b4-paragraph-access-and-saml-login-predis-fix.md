# Session Log: 1b.4 paragraph access proven; SAML login blocked (predis fix) then unblocked

**Date:** 2026-08-18
**Participants:** Than Grove (driving), Claude Code
**Outcome:** Closed [Sprint 1 task 1b.4](../sprints/sprint-01-images-implementation.md) (paragraph
access inheritance) with no code change needed — [PR #118](https://github.com/uvalib/mandala-navina/pull/118).
Attempting the first live NetBadge login for 1b.3 surfaced a real dev-0 bug (`predis/predis`
missing, blocking all SAML login with a 500); root-caused, fixed, deployed, and verified —
[PR #119](https://github.com/uvalib/mandala-navina/pull/119). 1b.3's actual live-session proof
is still open, now blocked on a different, narrower gap: dev-0 has no practical test-IdP path,
only the real UVA NetBadge IdP. **Handoff: Yuji is driving next**, picking up
[dev-0-needs-test-idp-for-saml-login-testing.md](../deferred/dev-0-needs-test-idp-for-saml-login-testing.md).

**⚠ This log is hand-written, not machine-generated from the raw transcript.** Diagnosing the
SAML bug involved dumping container environment variables over SSH, which incidentally echoed
a hashed admin-password value into the working conversation. The hash was never committed or
reused, but the raw transcript would have republished it verbatim into a public repo, so this
summary was written by hand instead.

---

## Where the session started

Picking up mid-sprint: Step 1a and most of Step 1b (1b.1, 1b.2) were already done; 1b.3 was
proven at the data/token level (2026-08-13) but never through a real login; 1b.4 hadn't been
started. See the [2026-08-13 D11 kmassets population log](2026-08-13-d11-kmassets-population-and-adr014-proof.md)
and [ADR 009](../adr/009-migration-sequencing-strategy.md)/[ADR 014](../adr/014-hybrid-solr-proxy-design.md)
for the fuller context that framed this session — the team asked "is Phase 1 complete?" before
starting, and the honest answer was no: 1b.4 was unstarted and 1b.3's live-session leg was open.

## 1b.4 — paragraph access inheritance (closed, no code change)

Investigated whether a private image's satellite paragraphs (`image_agent`,
`image_descriptions`, `external_classification`) are independently retrievable, bypassing the
node-level private-collection access check. Two findings:

1. **No retrieval surface exists that could bypass it.** `jsonapi`/`rest` are not enabled, no
   View exposes the `paragraphs_item` base table, no Search API index touches paragraphs, and
   the `paragraph` entity type declares no canonical route. The one custom endpoint that embeds
   paragraph field values (`mandala_node_api`'s `NodeJsonController`) is already gated by the
   same `_entity_access: node.view` requirement its route declares.
2. **The mechanism already works and was never custom-built.** Contrib
   `ParagraphAccessControlHandler::checkAccess()` unconditionally ANDs a `view` check against
   `$paragraph->getParentEntity()->access($operation, $account, TRUE)`, so
   `mandala_group_inheritance`'s existing node-access hook applies to paragraphs for free.

Verified live in DDEV against real migrated data (not just read from source): a flat private
collection (group 5, node 1 "Crab Nebula", agent paragraph 10) and a private **sub**collection
nested under a public parent (group 173 under collection 8, node 111342, agent paragraph
111207). Anonymous and a plain non-member authenticated test account got `FALSE`/`FALSE`
(node/paragraph access) in both cases; a real added member got `TRUE`/`TRUE`. This confirms
[ADR 011](../adr/011-group-collections-inheritance.md)'s explicit expectation that 1b.4
"composes correctly... under nesting." Test users were created and removed within the same
verification script; no residual state.

Landed as a docs-only sprint-tracking update, [PR #118](https://github.com/uvalib/mandala-navina/pull/118),
merged.

## 1b.3 — first live NetBadge login attempt (bug found, fixed; login still not completed)

With 1b.4 closed, the session moved to scoping the live-session proof 1b.3 still needs. A
dev-0 state check confirmed the OAuth2 `solrproxy` consumer was registered, the SimpleSAMLphp
config referenced real UVA IdP metadata (not a mock), and a real migrated user existed with
private-collection membership (uid 4, computing-id `dfg9w`, ~9 real private-collection
memberships from the completed user migration).

**Then the actual click-through hit a white screen.** Visiting
`https://mandala-images-dev.internal.lib.virginia.edu/user/login` and clicking NetBadge
produced a WSOD. First assumption — that this hostname routed to a stopped legacy D7 Aegir
container — was checked and **disproven**: the hostname does route to the real D11 app (its
response carries the Drupal 11 generator tag); the Aegir containers are separately stopped and
unrelated.

**Root cause, found by direct live diagnosis, not guessing:**

- `watchdog:show` on dev-0 showed `SimpleSAML\Error\MetadataNotFound` for the UVA IdP entity —
  a red herring. The metadata file was present, `php -l` clean, and resolved correctly when
  queried directly via SimpleSAMLphp's `MetaDataStorageHandler`.
- Simulating exactly what `simplesamlphp_auth` does —
  `new SimpleSAML\Auth\Simple('default-sp')` — inside the running container surfaced the real
  error: `CriticalConfigurationError: predis/predis is not available`, thrown from
  `RedisStore.php` before any SAML logic runs.
- dev-0 is configured for `SIMPLESAML_STORE_TYPE=redis` session storage. SimpleSAMLphp's
  `RedisStore` hard-requires the **`predis/predis` Composer package** (`Predis\Client`), not
  the native `ext-redis` PHP extension — which *is* present on the box, but irrelevant to this
  code path. `predis/predis` was only ever a `require-dev`/`suggest` of
  `simplesamlphp/simplesamlphp`, never actually pulled into `drupal/composer.json`. All the
  `store.redis.*` config wiring on dev-0 was already correct — this was purely a missing
  dependency.

**Fixed same session:** [PR #119](https://github.com/uvalib/mandala-navina/pull/119) added
`predis/predis: ^3.3` to `drupal/composer.json` via `ddev composer require` (v3.6.0 resolved,
no conflicts, no new `composer audit` advisories). Merged to `main`, which triggered the
existing CI/CD pipeline (`drupal/**` is inside `deploy_backend.yml`'s `trigger_paths`); the
pipeline ran and deployed a new image to dev-0.

**Verified live post-deploy, not just locally:**
- `predis/predis` present in the newly deployed `vendor/`
- `class_exists('Predis\Client')` now true
- `Auth\Simple::getLoginURL()` succeeds (was throwing before)
- Over real HTTP, `/saml_login` now returns `303`, redirecting to UVA's actual production IdP
  (`shibidp.its.virginia.edu/idp/profile/SAML2/Redirect/SSO?SAMLRequest=...`) instead of a 500

Full incident writeup:
[simplesamlphp-redis-store-missing-predis.md](../deferred/simplesamlphp-redis-store-missing-predis.md)
(Resolved).

## What's still open, and why the session stopped here

Getting a valid `303` to the IdP proves only the first leg of the SAML flow. Completing it for
real would mean authenticating as `dfg9w` — a specific real person's UVA NetBadge credential —
which isn't a good fit for routine testing. The team decided **not** to keep borrowing a real
account, and instead to stand up a proper test-IdP path on dev-0 for this and future SAML
testing.

dev-0 already has the pieces for this, just not wired together: `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true`
is already set, and a built-in `example-userpass` test auth source with three canned identities
already exists in `authsources.php`. But Drupal's active `auth_source` is still `default-sp`
(the real-IdP source), and even after switching, two gaps remain unsolved: the canned test
identities don't carry the `unique_id` attribute `simplesamlphp_auth` looks for, and none of
them has a matching `authmap` row pointing at a real migrated private-collection user. There's
also a flagged-but-unverified risk: the SP's configured identity (`SIMPLESAML_SP_ENTITY_ID`,
`SIMPLESAML_BASE_URL`, `SIMPLESAML_COOKIE_DOMAIN`) is pinned to `mandala-dev.internal.lib.virginia.edu`,
not the `mandala-images-dev` hostname the team actually uses — the redirect-to-IdP leg worked
fine over `images-dev`, but the return leg (assertion → Drupal session) was never tested and
may hit a cookie-domain mismatch.

Full scope, options, and open questions are written up in
[dev-0-needs-test-idp-for-saml-login-testing.md](../deferred/dev-0-needs-test-idp-for-saml-login-testing.md).

## Handoff — next session picks up here

**Yuji is driving next.** Starting point:
1. Read [dev-0-needs-test-idp-for-saml-login-testing.md](../deferred/dev-0-needs-test-idp-for-saml-login-testing.md)
   in full — it lays out the two concrete gaps (attribute mapping, authmap row) and the open
   question about which of the three canned identities to use / whether to add a new one.
2. Decide where the fix lives — this is Ansible/`terraform-infrastructure` territory
   (`authsources.php` is env-templated from there), not this repo, so it may need
   cross-repo coordination.
3. Once a test identity resolves to a real migrated private-collection account, redo the
   1b.3 live-session chain: confirm the Redis visibility token gets written on login
   (`mandala_solr_fq:{uid}`), drive the OAuth2 flow against the registered `solrproxy`
   consumer, hit the live `solr-proxy`, and diff results against that account's known
   memberships.
4. Resolve the cookie-domain open question (`mandala-dev` vs `mandala-images-dev`) before
   assuming the full round trip will work — it was never tested this session.

Also still outstanding from before this session, unaffected by today's work: the deferred
end-of-Sprint-1 staging acceptance run (1a.9), and Sprint 1's "Phase 2 hasn't been forked off
yet" observation from the pre-session discussion (Texts is spike-cleared and ready to start
whenever the team wants to pick it up).
