# Session Log: Sprint 01 — 1b.1 Part 4 (NetBadge/SAML) scoping + kickoff

**Date:** 2026-07-13  
**Participants:** Yuji Shinozaki, Claude Code (Opus 4.8)  
**Outcome:** Reconciled state with `origin/main` (1b.1 parts 1–3 merged as PR #30; Spike 4 split 4a/4b). Scoped **1b.1 part 4** ([sprint doc](../sprints/sprint-01-images-implementation.md), [Spike 10](../spikes/spike-10-saml-oauth2-coexistence.md), [ADR 014](../adr/014-hybrid-solr-proxy-design.md)) against the UVA Library NetBadge/SimpleSAMLphp house pattern (`drupal-dsf`, `terraform-infrastructure`). Shipped the app-repo SP-side CMI (`simplesamlphp_auth` + attribute mapping, `register_users:false`) and filed [`saml-alb-routing-assumes-mod-shib.md`](../deferred/saml-alb-routing-assumes-mod-shib.md) — verified the terraform ALB routes mod_shib paths while the SP is SimpleSAMLphp; decided to **delete** the obsolete auth rules. All on PR #32 (`feat/1b1-part4-netbadge-saml`).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-07-13 13:21

please update your memory and figure out where we are in the current srpint

---

## Claude — 2026-07-13 13:21

I'll orient myself first by reading the session startup files and checking recent work, then update memory.

---

## Claude — 2026-07-13 13:21

My memory (last refreshed 2026-07-10) matches the current HEAD exactly — the top commit is the 1b.1 parts 1–3 session log. Let me confirm nothing has moved since then and check the latest session log.

---

## Claude — 2026-07-13 13:22

The branch was pushed AND appears merged into `origin/main` (local main is "behind 21", and `origin/main..HEAD` is empty). This is newer than my memory. Let me see what happened on origin/main since 07-10.

---

## Claude — 2026-07-13 13:22

Now I have the picture. Significant changes since my last memory refresh (2026-07-10). Let me read the new spike docs to capture details accurately.

---

## Claude — 2026-07-13 13:22

I now have a complete picture. Since my last memory refresh (2026-07-10), three things merged into `main`. Let me update my memory to reflect current reality, then summarize.

---

## Claude — 2026-07-13 13:22

Now the Spikes section:

---

## Claude — 2026-07-13 13:23

Now update the MEMORY.md index hook, which still says the branch is "not pushed":

---

## Claude — 2026-07-13 13:23

Memory reconciled with `origin/main`. Here's where things stand.

## Where we are — Sprint 1, Step 1b (auth increment)

Since my memory last refreshed (07-10), **three PRs merged into `main`** — most of what I had recorded as "in-progress / unpushed" has actually landed:

| Item | Status now |
|---|---|
| **1b.2** Group collections + OG migration | ✅ Merged (PR #27) |
| **1b.1 parts 1–3 of 4** (hybrid Solr proxy, ADR 014) | ✅ **Merged** (PR #30, 07-10) — was "unpushed" in my stale memory |
| **1b.1 part 4** — SAML+OAuth2 coexistence *outside DDEV* | ⬜ **Not started — the one open piece of 1b.1** |

**1b.1 recap (now on main):** solr-proxy forked from D7 reading `mandala_solr_fq:{uid}` from Redis; `simple_oauth` configured with a real `solrproxy` client; `mandala_solr_visibility` module writing/deleting the Redis token on login/logout/membership-change; kmassets image docs now carry real `visibility_i`/`collection_uid_s`.

**In parallel (Than, Texts spikes):**
- **Spike 4 was split** into **4a** (Tibetan Unicode round-trip — cross-cutting, pending) and **4b** (CKEditor 5 footnotes).
- **Spike 4b landed a decisive Fail (PR #31, merged today 07-13):** the CKEditor 5 `footnotes` 4.x module **cannot represent D7's cross-page footnote pattern** — Texts migration will need another approach.
- **Spike 3** (Group collections) corrected to ● **Proven**.

## What's next / still open
1. **1b.1 part 4** — prove SAML + OAuth2 coexistence outside DDEV (only remaining 1b.1 task; Spike 10 proved it in DDEV only).
2. **1a.9 staging execution** — deferred to end of Sprint 1 (post-1b); its security criterion is 1b-gated. Blocked on the two staging prerequisites (source DB + drush path).
3. **High-priority open bugs/blockers still unresolved:**
   - `group-relationship-delete-broken-no-data-field` — root-caused but **not fixed**; breaks already-merged 1b.2 membership removal on any collection with subcollections.
   - User migration blocked on the D7 shared user database (`d7_images_collection_memberships` still 38/249).

## One housekeeping note
Your local `main` is **21 commits behind** `origin/main`. You're currently sitting on the (now-merged) `feat/1b1-hybrid-solr-proxy` branch. Before starting new work you'll want:

```
git checkout main && git pull
```

Want me to do that checkout+pull, or start scoping **1b.1 part 4**?

---

## User — 2026-07-13 13:24

pull main and start scoping 1b.1 part 4

---

## Claude — 2026-07-13 13:24

I'll pull main and then dig into what 1b.1 part 4 actually requires.

---

## Claude — 2026-07-13 13:25

Main is up to date. Now let me scope 1b.1 part 4. Let me read the authoritative sources — ADR 014, the Spike 10 doc (which proved coexistence in DDEV), and the 1b task definition.

---

## Claude — 2026-07-13 13:25

The 1b task breakdown lives in `docs/sprints/sprint-01-images-implementation.md`. Let me read the 1b.1 section there, plus the Spike 10 doc's "what this does NOT establish" section (which defines exactly what part 4 must close).

---

## Claude — 2026-07-13 13:25

Now let me read Spike 10's boundaries — the "does NOT establish" section defines exactly what part 4 must close — plus ADR 014's deployment/environment assumptions.

---

## Claude — 2026-07-13 13:25

This is a well-defined boundary. Before I scope, I need to check the current state of two things: (a) whether `simplesamlphp_auth` is actually installed in the merged app (vs. only on the spike branch), and (b) what ADR 014 assumes about deployment environment, and (c) the user-migration gating from the D7 shared-user note.

---

## Claude — 2026-07-13 13:26

I have what I need. Let me confirm the exact user-migration gating wording, since it's central to part 4's dependencies.

---

## Claude — 2026-07-13 13:26

Pulled main (now at `01f5126`) and I've mapped part 4 against the source docs. Here's the scope.

## 1b.1 part 4 — scope

**Task as written (sprint doc line 103 / 106):** *"confirm SAML+OAuth2 coexistence outside DDEV (Spike 10 only proved it in DDEV)."*

### The key reframing
The **architectural** coexistence question is already decisively proven and is immutable (Spike 10). The core finding: `simple_oauth` operates entirely on the *finalized Drupal account* — its only gate is `isAnonymous()`, and `sub` = `$account->id()`. It never reads the authentication mechanism's native identifier, so the feared failure (`sub` = SAML NameID/email) **cannot occur**. SAML's job ends at session establishment; OAuth2's begins from the Drupal account.

So part 4 is **not** re-proving architecture. It's the **environment + real-IDP integration validation** that Spike 10 explicitly listed under "What this does NOT establish." Concretely, what's still open:

| # | Must close | Source |
|---|---|---|
| 1 | Enable + configure `simplesamlphp_auth` against the **real UVA Shibboleth IDP** (spike used SimpleSAMLphp's *test* IDP; the app never even enabled the module — see state below) | Spike 10 demo scope |
| 2 | Confirm **which released UVA attribute** maps to a Drupal account, and that it's stable/uid-mappable | Spike 10 fail-criterion #3 |
| 3 | Drive the full flow in a **deployed env** (staging): SAML session → `/oauth/authorize` (no re-login) → token → `sub`=uid → proxy reads Redis by that uid, with the real proxy container + Redis co-located | "outside DDEV" |
| 4 | **Token refresh / SAML session lifetime** interaction (refresh tokens issued in spike but never exercised vs. SAML expiry) | Spike 10 deferred |
| 5 | Confirm the **React KMaps app** needs nothing beyond the endpoint URL change (D7 `/oauth2/*` → D11 `/oauth/*`) | Spike 10 open item |

### Current repo state (verified just now)
- `simple_oauth` — **fully wired**: enabled in `core.extension`, scopes + token bundles + settings all committed to `config/sync/`. ✅
- `simplesamlphp_auth ^4.1` — **in composer and downloaded to contrib/, but NOT enabled and NOT configured** (nothing in `config/sync`, absent from `core.extension`). The spike's SAML config was throwaway on `spike/10-saml-oauth2` and never landed in the app.

### The blockers that make this "not just a DDEV task"
Part 4 has three external dependencies, none of which Claude can satisfy in the repo alone:

1. **User migration is a hard gate.** SAML maps a NetBadge identity onto a Drupal account *that must already exist*. The `d7-shared-user-database` note explicitly lists "SAML/NetBadge account mapping (Spike 10, 1b.1 part 4)" as gated on the user migration — and that migration is itself blocked on the shared-user-DB kludge. Which attribute maps to which account field (`name`/`mail`? a stored NetBadge id?) is an unanswered design question.
2. **Staging infrastructure** — "outside DDEV" implies a staging deploy of the proxy + D11 + Redis, sharing the same `staging-migration-execution-prerequisites` gating as 1a.9.
3. **UVA IAM coordination** (Dave/DevOps) — SP metadata registration + attribute-release policy is a human-in-the-loop external dependency with real lead time.

There's also an ADR 014 loose end: it flags a *"follow-on ADR"* for token issuance to SAML-authenticated users — part 4 likely closes that open question (or spawns the ADR).

### What this means
Part 4 naturally splits into **repo-side prep that's doable now** (enable + configure `simplesamlphp_auth` as committable CMI, add a SAML `settings.php` template + SP-metadata requirements doc, decide the attribute-mapping strategy on paper) versus **the actual outside-DDEV validation**, which is genuinely blocked on the three dependencies above and lines up naturally with the end-of-Sprint-1 staging run (same gating as 1a.9, and it needs migrated users anyway).

Given that, the decision is how far to push part 4 now:

---

## Claude — 2026-07-13 13:33

What would you like to clarify about the scope or the options? A few things I can expand on if useful — e.g. exactly what the "repo-side prep" config would touch, how hard the user-migration blocker really is (whether SAML auto-provisioning could sidestep it), what the UVA IAM coordination actually requires, or how part 4 relates to 1b.3 on the critical path. Tell me what's unclear or what you're weighing, and I'll reframe.

---

## User — 2026-07-13 13:33

what do you mean by UVA IAM?

---

## Claude — 2026-07-13 13:34

UVA IAM = the University's **Identity and Access Management** team — the group that runs UVA's central authentication infrastructure. At UVA that's **NetBadge** (the university's branded single sign-on) and the **Shibboleth Identity Provider (IdP)** behind it.

I invoked them because SAML login is a two-party arrangement, and only one side lives in our repo:

- **Our side (the SP — Service Provider):** the Mandala D11 site running `simplesamlphp_auth`. We configure this in code/config.
- **Their side (the IdP):** UVA's Shibboleth server that actually authenticates the user and sends back an assertion. We don't control it.

For our SP to trust their IdP (and vice versa), UVA IAM has to do two things on their end that we can't do ourselves:

1. **Register our SP** — accept our SP metadata (entityID, ACS URL, certificate) so their IdP will release assertions to `mandala.staging...`. Without this, NetBadge won't talk to our site at all.
2. **Set the attribute-release policy** — decide *which* user attributes their IdP sends us (eduPersonPrincipalName / computing ID / email / etc.). This is exactly the unknown in Spike 10's fail-criterion #3 ("does the IDP provide a stable uid-mappable attribute?") and in the `d7-shared-user-database` note ("which attribute maps to which Drupal account field?").

That's why part 4's *real* validation is human-in-the-loop and has lead time — it's a ticket/request to UVA IAM, not something I can complete in DDEV. Note this is almost certainly **already solved for the legacy D7 site** (it authenticates via NetBadge today), so a big chunk of part 4 is likely *reusing the existing D7 SP registration/attribute policy and pointing it at the new D11 SP* — worth checking the D7 SimpleSAMLphp config before assuming a from-scratch IAM engagement.

On our team, **Dave Goldstein** (Director, Cloud Infrastructure) is the natural person to own that IAM coordination.

Want me to dig into the legacy D7 SimpleSAMLphp config to see exactly what SP registration + attribute mapping is already in place? That would sharpen whether the IAM dependency is a small "re-point the existing SP" or a full new engagement.

---

## User — 2026-07-13 13:34

Please refer to what you are calling IAM, as UVa Netbadge (or Shibboleth)

---

## Claude — 2026-07-13 13:34

Got it — I'll refer to it as **UVA NetBadge (or Shibboleth)** rather than "IAM" from here on. Let me save that so future sessions use the right term.

---

## Claude — 2026-07-13 13:35

Saved. So, restating part 4's external dependency in the right terms:

The real "outside DDEV" validation depends on **UVA NetBadge (Shibboleth)** — UVA's SSO IdP. Two things must happen on the NetBadge side that we can't do in our repo:

1. **Register our D11 SP** with NetBadge (our SP metadata: entityID, ACS URL, cert) so its IdP will release assertions to the new Mandala site.
2. **Attribute-release policy** — which attributes NetBadge sends us (computing ID / eduPersonPrincipalName / email), the unknown behind Spike 10's fail-criterion #3 and the account-mapping question in the `d7-shared-user-database` note.

Since the legacy D7 site already authenticates via NetBadge, a lot of part 4 is likely **re-pointing the existing SP registration/attribute policy at the new D11 SP** rather than a from-scratch engagement. **Dave Goldstein** is the natural owner for that NetBadge coordination.

Want me to dig into the legacy D7 SimpleSAMLphp config to see exactly what SP registration + attribute mapping already exists with NetBadge? That would tell us whether the dependency is a small "re-point the existing SP" or something heavier.

---

## User — 2026-07-13 13:35

We already have an existing Shibboleth dev SP and staging SP to work with.

---

## Claude — 2026-07-13 13:35

That materially de-risks part 4 — the NetBadge SP-registration dependency I flagged as a blocker is largely already handled. Let me record that and re-scope.

---

## Claude — 2026-07-13 13:36

Updated. Here's the re-scoped picture with that in hand:

## 1b.1 part 4 — re-scoped

The **NetBadge/Shibboleth SP registration** dependency — the one true human-in-the-loop, external-lead-time blocker — is largely **off the table**, because we already have **dev and staging Shibboleth SPs** to work with. That collapses part 4 from "coordinate a new SP + attribute policy" down to mostly **repo/config + a deploy**:

**What part 4 now consists of:**
1. **Enable + configure `simplesamlphp_auth`** in D11 to use the existing dev/staging SP — SP entityID, cert, IdP metadata, ACS URL, wired via `settings.php` (secrets) + committable CMI (behavior/attribute mapping). *(Currently the module is downloaded but not enabled/configured.)*
2. **Attribute → Drupal account mapping** — map whatever the existing SP releases (computing ID / eduPersonPrincipalName / email) onto the Drupal account, and decide auto-provision vs. match-existing.
3. **Run the flow against the dev/staging SP** (the "outside DDEV" proof): NetBadge login → `/oauth/authorize` (no re-login) → token → `sub`=uid → proxy reads Redis by that uid.
4. **Token refresh / SAML session-lifetime** interaction (deferred from Spike 10).
5. **React KMaps app** — confirm only the endpoint URL changes (`/oauth2/*` → `/oauth/*`).

**Remaining real dependencies (much smaller now):**
- **User migration** — for *account mapping* against real users. But the existing **dev SP** may let us validate with auto-provisioned or test identities *before* the full migration, which would unblock most of part 4 now. Worth deciding whether we require real migrated accounts or accept auto-provisioning for the validation.
- **A deploy target outside DDEV** — the dev SP suggests there's a dev environment we could use, which is lighter than waiting for the full staging window.

Two things would sharpen this into an executable plan:

---

## User — 2026-07-13 13:40

[Request interrupted by user for tool use]

---

## User — 2026-07-13 13:42

Please look at the directory /Users/ys2n/Code/mandala-legacy/mandala-drupal/simplesamlphp   mandala-legacy amounts to checkouts of the D7 code.

---

## Claude — 2026-07-13 13:43

This is a full SimpleSAMLphp install. The important parts are `config/`, `metadata/`, `cert/`, and `attributemap/`. Let me look at the ones that define the SP and the IdP trust relationship.

---

## Claude — 2026-07-13 13:43

The `authsources.php` (SP definitions) and `saml20-idp-remote.php` (UVA IdP) plus the attribute map are what I need. Let me read them.

---

## Claude — 2026-07-13 13:43

This is the D7 SP config. Key facts already visible:
- **IdP:** `urn:mace:incommon:virginia.edu` — UVA's InCommon-federated NetBadge/Shibboleth IdP (production).
- **SP entityID:** auto-generated → effectively `https://mandala-dev.internal.lib.virginia.edu/simplesaml/module.php/saml/sp/metadata.php/default-sp` (the **dev** SP), with ACS on that host.
- Hosted on **Acquia** (`AH_SITE_ENVIRONMENT` env var), SP keypair `saml.pem`/`saml.crt`, no explicitly-requested attributes.

Let me read the IdP trust metadata and the attribute map — those tell us what NetBadge releases and how it's named.

---

## Claude — 2026-07-13 13:43

I now have the concrete SP/IdP facts. Let me save them as a reference — these exact values get reused in D11 part 4 config — then give you the sharpened scope.

---

## Claude — 2026-07-13 13:44

## 1b.1 part 4 — sharpened scope (with the legacy SP config in hand)

Reading the D7 SimpleSAMLphp install confirms the dependency is a **port, not a from-scratch engagement**:

- **IdP:** UVA NetBadge/Shibboleth, `urn:mace:incommon:virginia.edu`, SSO at `shibidp.its.virginia.edu`, cert `uva-idp.cer`. This is the production InCommon IdP the existing dev/staging SPs already trust.
- **Dev SP:** `default-sp`, entityID auto-derived on `mandala-dev.internal.lib.virginia.edu`, keypair `saml.pem`/`saml.crt`, no explicit attribute requests (relies on the IdP's default release).
- Was hosted on **Acquia** (`AH_SITE_ENVIRONMENT`) — so the environment-switching pattern (dev vs. staging SP) will need re-expressing for our AWS/Terraform+Ansible deploy rather than Acquia's env var.

**One gap the legacy `simplesamlphp/` dir does *not* answer:** which SAML attribute D7 mapped to the Drupal **username** (and mail). That's a `simplesamlphp_auth` *Drupal-module* setting, stored in the D7 `variable` table — not in this directory. It's the crux of part 4's account-mapping step, and I'd pull it from the D7 DB.

### Revised part 4 work breakdown
| Step | Doable now (repo/DDEV) | Notes |
|---|---|---|
| A. Enable `simplesamlphp_auth`; add `simplesamlphp` config (authsources/idp-remote/cert) as an Ansible-deployed asset, secrets via `settings.php` | ✅ mostly | Port from legacy; re-express Acquia env-switch for our deploy |
| B. Attribute→account mapping (username/mail) as committable CMI | ✅ once we retrieve the D7 setting | Retrieve from D7 `variable` table |
| C. Run full flow against **dev SP** outside DDEV: NetBadge → `/oauth/authorize` (no re-login) → token → `sub`=uid → proxy Redis read | ⬜ needs a dev deploy | The actual "outside DDEV" proof |
| D. Token refresh / SAML session lifetime | ⬜ | Deferred from Spike 10 |
| E. React app: confirm only endpoint URL changes | ⬜ | Coordinate w/ Than |

### Two decisions to lock the plan
1. **Test identity** — validate now against the **dev SP** with an auto-provisioned/test NetBadge identity (unblocks C before the user migration), or require real migrated accounts (keeps C gated behind the migration)? I'd recommend the former to prove the flow now.
2. **Where the D11 SAML config + secrets land** — Ansible-managed `simplesamlphp` config dir + `settings.php` secrets, mirroring how the proxy/other secrets are handled in this repo's deploy. I can check the existing Ansible/Terraform pattern to confirm.

Want me to (a) go retrieve the D7 attribute→username mapping from the D7 database, and/or (b) look at how this repo's Ansible/`settings.php` handles per-environment secrets so step A has a concrete home? Either firms up the plan without needing anything external.

---

## User — 2026-07-13 13:46

Please look at the way that drupal-netbagde, drupal-libary and drupal-dsf are configured to see how we handle Netbadge authentication

---

## Claude — 2026-07-13 13:47

Found them under `/Users/ys2n/Code/uvalib/`. Let me examine how each handles NetBadge — starting with `drupal-netbadge` (most on-point), then how the two site repos wire it.

---

## Claude — 2026-07-13 13:47

`drupal-netbadge` is a dedicated SAML/NetBadge reference. Let me read its README, the SAML guides, and the config files.

---

## Claude — 2026-07-13 13:47

This reveals the modern pattern clearly. `drupal-netbadge` is a **test IdP container** (`cirrusid/simplesamlphp`, with `example-userpass` student/staff/faculty test users), and crucially the README states SAML config + certs are now **managed via Ansible in the `terraform-infrastructure` repo**, env-driven, *not* committed to the app repo. Let me confirm the SP-side Drupal config (the attribute→username mapping I flagged as the gap) and the authoritative implementation doc.

---

## Claude — 2026-07-13 13:47

Let me read the SP-side CMI config (the attribute-mapping answer) from drupal-dsf, and locate the `terraform-infrastructure` repo to examine the Ansible SAML configs.

---

## Claude — 2026-07-13 13:48

The SP-side mapping is now answered — this directly fills the gap I flagged:

```yaml
auth_source: default-sp
user_name: 'urn:oid:0.9.2342.19200300.100.1.1'   # uid = UVA computing ID → Drupal username
unique_id: 'urn:oid:0.9.2342.19200300.100.1.1'   # same
mail_attr:  'urn:oid:0.9.2342.19200300.100.1.3'   # mail → Drupal mail
register_users: false                             # NO auto-provision — account must pre-exist
allow.default_login: true                         # local Drupal login still works
```

That confirms the user-migration gate (register_users:false means accounts must exist) and the exact attributes. Now let me examine the `terraform-infrastructure` Ansible configs for these three sites + mandala.

---

## Claude — 2026-07-13 13:48

Let me examine the Ansible/SAML setup in the DSF site config and mandala's config within terraform-infrastructure.

---

## Claude — 2026-07-13 13:48

Found the definitive deployment pattern. Per environment, each site's Ansible dir carries the full SAML config as deployed files. Let me read the DSF staging SP config + IdP metadata + the deploy playbook, and check what mandala already has scaffolded.

---

## Claude — 2026-07-13 13:48

Let me pull the two remaining concrete pieces: the SAML env-var contract (from DSF's container env) and how the ALB routes `/simplesaml` (the one SAML hit in mandala's own terraform).

---

## Claude — 2026-07-13 13:49

I now have the complete deployment pattern. Two findings jump out as important beyond the config mechanics:

1. **SimpleSAMLphp uses the shared Redis cluster for its session store** (`SIMPLESAML_STORE_TYPE: redis`, DB 3, prefix `SIMPLESAML_DSF:`) — that intersects directly with ADR 014's `mandala_solr_fq:{uid}` Redis usage, so DB/prefix separation matters.
2. **Mandala's ALB already routes `/user/netbadge` and `/Shibboleth.sso/*`** — but note that's the *native Shibboleth SP* (`mod_shib`) endpoint pattern, whereas DSF/dhportal and our own D7 legacy use **SimpleSAMLphp** (`/simplesaml/`). Worth verifying which SP mandala's infra actually intends.

Let me capture this pattern in memory, then synthesize.

---

## Claude — 2026-07-13 13:49

Now let me update the part 4 entry in the state snapshot to reflect that the deployment pattern is fully known, then synthesize.

---

## Claude — 2026-07-13 13:50

Memory's captured. Here's the synthesis of how UVA Library does NetBadge, and what it means for part 4.

## How UVA Library handles NetBadge (the house pattern)

I examined `drupal-netbadge`, `drupal-dsf`, `drupal-library`, and their `terraform-infrastructure` configs. The pattern is consistent and mature:

- **SP = SimpleSAMLphp**, **fully env-driven**. The `authsources.php` / `config.php` / IdP metadata are *identical boilerplate* across sites — everything site-specific comes from env vars (`SIMPLESAML_SP_ENTITY_ID`, `SIMPLESAML_DEFAULT_IDP`, `SIMPLESAML_PROJECT`, …).
- **Config + certs live in `terraform-infrastructure/<site>/<env>/ansible/`, NOT the app repo.** `drupal-netbadge`'s README is explicit: *"SAML configuration and certificates are now managed via Ansible templates and deployed through the terraform-infrastructure repository. Do not manually edit or add SAML config files in this repository."* Per-env, each site ships: `files/var/simplesamlphp/{config,metadata,drupal-config}/…`, a `deploy_netbadge.yml` playbook, and `container_0.env.{managed,secret}` (secrets = `SIMPLESAML_SECRET_SALT`, `SIMPLESAML_ADMIN_PASSWORD`).
- **IdP** is the same one our D7 legacy uses: `urn:mace:incommon:virginia.edu` / `shibidp.its.virginia.edu`, with the InCommon signing cert embedded in `saml20-idp-remote.php`.
- **Attribute → account mapping** (this fills the gap I flagged): `user_name`/`unique_id` = `urn:oid:0.9.2342.19200300.100.1.1` (**uid = UVA computing ID**), `mail_attr` = `urn:oid:…100.1.3` (**mail**), `register_users: false`, `allow.default_login: true`, `autoenablesaml: true`.
- **Local dev IdP** = the `drupal-netbadge` container itself (a SimpleSAMLphp test IdP with `example-userpass` student/staff/faculty users), gated on `SIMPLESAML_ENABLE_EXAMPLE_AUTH`. This is the sanctioned way to test the flow without hitting real NetBadge.

## What this means for 1b.1 part 4

The good news: **part 4 is now almost entirely a "follow the established pattern" exercise**, not novel work. Two of my earlier open decisions are effectively answered by the house pattern:
- *Attribute mapping* → uid→username, mail→mail (copy DSF's `simplesamlphp_auth.settings.yml`).
- *Where config/secrets land* → `terraform-infrastructure/mandala/drupal/<env>/ansible/`, env-driven, exactly like DSF.
- *Test identity* → the `drupal-netbadge` test IdP + `example-userpass` lets us prove the flow **now**, outside DDEV, without waiting on the user migration or real NetBadge.

### Concrete part 4 checklist
| # | Work | Where |
|---|---|---|
| A | Enable `simplesamlphp_auth`; commit `simplesamlphp_auth.settings.yml` CMI (uid→username, mail, `register_users:false`, `auth_source: default-sp`) | mandala-navina repo |
| B | Add the SimpleSAMLphp Ansible files + `deploy_netbadge.yml` + `container_0.env.*` for mandala staging/prod, `SIMPLESAML_PROJECT=mandala-…`, SP entityID on the mandala staging host | terraform-infrastructure/mandala |
| C | Generate/register the mandala SP keypair; wire the existing dev/staging SP | terraform-infra + NetBadge (Dave) |
| D | **Separate the SAML Redis session store** from the ADR 014 `mandala_solr_fq:{uid}` token (distinct DB index + prefix) | env vars |
| E | Run the flow outside DDEV: NetBadge (or test IdP) → session → `/oauth/authorize` no-reprompt → token → `sub`=uid → proxy Redis read | staging/dev deploy |
| F | Token refresh / SAML session lifetime; React app endpoint change | validation |

### Two things to verify before building
1. **SP type mismatch:** mandala's `alb-routing.tf` already routes `/user/netbadge` + `/Shibboleth.sso/*` — but `/Shibboleth.sso/` is the **native `mod_shib` SP** endpoint, whereas the whole house pattern (and our D7) is **SimpleSAMLphp** (`/simplesaml/`). Someone scaffolded mandala's ALB for a different SP than the pattern uses. This needs resolving first — it changes step B substantially.
2. **Redis collision:** confirm the SimpleSAMLphp session store and the visibility token won't clash on the shared cluster.

The `/Shibboleth.sso/*` vs `/simplesaml/` discrepancy is the one genuine unknown here and it's a fork in the road for the implementation. Want me to dig into who added mandala's ALB routing (git blame) and whether there's an intent to use native Shibboleth SP instead of SimpleSAMLphp — before we commit to a part-4 implementation path?

---

## User — 2026-07-13 13:59

The terraform configs were assuming that we could use mod_shib as the SP, but we instead used simplesamlphp_auth.  But we never went back to terraform to reflect this change.   Please look at the production site to verify this.

---

## Claude — 2026-07-13 14:00

Let me find the production hostname, then probe the live site's SAML endpoints to verify which SP is actually running.

---

## Claude — 2026-07-13 14:00

Production domain is `mandala.library.virginia.edu`. Let me confirm the base domain and then probe the live site's SAML endpoints — `/simplesaml/` (SimpleSAMLphp) vs `/Shibboleth.sso/` (mod_shib) — plus the `/user/netbadge` redirect, which will settle which SP is actually running.

---

## Claude — 2026-07-13 14:00

Conclusive. Let me confirm the *correct* NetBadge login path on production (so the terraform fix is concrete) and that it federates to the UVA IdP — one more targeted probe.

---

## Claude — 2026-07-13 14:01

Empirically confirmed. Here's the verification against the live production site (`mandala.library.virginia.edu`, title "Mandala Collections - Kmaps"):

| Probe | Result | Meaning |
|---|---|---|
| `/saml_login` | **302 → `shibidp.its.virginia.edu/idp/profile/SAML2/Redirect/SSO?SAMLRequest=…&RelayState=…/saml_login`** | `simplesamlphp_auth`'s Drupal login route, federating to UVA NetBadge |
| `/simplesaml/` | **200** (→ SimpleSAMLphp frontpage) | SimpleSAMLphp SP is running |
| `authenticate.php?as=default-sp` | references `shibidp.its.virginia.edu` | SP `default-sp` points at the UVA IdP |
| `/Shibboleth.sso/Metadata` | **404** | native `mod_shib` is **not** present |
| `/user/netbadge` | **404** | the mod_shib-assumed ALB path doesn't exist |

**Your recollection is exactly right.** Production authenticates via **SimpleSAMLphp (`simplesamlphp_auth`)** — login at `/saml_login`, SP endpoints under `/simplesaml/*`, ACS at `/simplesaml/module.php/saml/sp/saml2-acs.php/default-sp`. But the mandala terraform ALB rules route `/user/netbadge` + `/Shibboleth.sso/*`, which are **mod_shib** paths — and both 404 on the real site. The terraform was never updated to match the SimpleSAMLphp reality.

Let me record this verified finding.

---

## Claude — 2026-07-13 14:01

## Bottom line for 1b.1 part 4

The investigation resolved the one genuine unknown, and it landed in favor of the simpler path: **we follow the SimpleSAMLphp house pattern end-to-end** (matching DSF/library and our own live production), and the `mod_shib` ALB routing in mandala's terraform is a confirmed stale bug to fix — not a competing design to reconcile.

**What part 4 now concretely is:**
1. **App repo (doable now):** enable `simplesamlphp_auth`, commit `simplesamlphp_auth.settings.yml` CMI — copy DSF's mapping (`uid`→username, `mail`→mail, `register_users: false`, `auth_source: default-sp`).
2. **terraform-infrastructure/mandala (needs a fix + additions):**
   - **Fix the ALB routing** — swap `/user/netbadge` + `/Shibboleth.sso/*` → `/saml_login`, `/saml_logout`, `/simplesaml/*`.
   - Add the SimpleSAMLphp Ansible files (`authsources.php`, `config.php`, `saml20-idp-remote.php`, `drupal-config/…`, `deploy_netbadge.yml`) + `container_0.env.{managed,secret}`, `SIMPLESAML_PROJECT=mandala-…`, SP entityID on the mandala staging host — all cloned from DSF and re-pointed.
   - **Separate Redis:** distinct `SIMPLESAML_REDIS_DATABASE` + prefix from ADR 014's `mandala_solr_fq:{uid}`.
3. **Validate outside DDEV:** dev/staging SP (or the `drupal-netbadge` test IdP + `example-userpass`) → session → `/oauth/authorize` no-reprompt → token `sub`=uid → proxy Redis read. Then token-refresh/session-lifetime + React endpoint change.

**Still genuinely gated:** real-account mapping on `register_users: false` needs the user migration — but the test-IdP path lets us prove the whole flow *now* without it.

This terraform/SP mismatch is a real defect that belongs in the team's `docs/deferred/` (it affects any staging deploy, not just part 4). Want me to:
- **(a)** file the deferred note documenting the mod_shib→SimpleSAMLphp ALB-routing bug, and/or
- **(b)** start on step 1 (the app-repo `simplesamlphp_auth` enablement + CMI), which is fully unblocked?

I'd suggest doing both — (a) is a 2-minute capture, (b) is the first real part-4 code. Your call on whether to kick those off now or save for a dedicated part-4 session.

---

## User — 2026-07-13 14:13

sure do both

---

## Claude — 2026-07-13 14:14

On it. Let me branch first (we're on `main`), then check the local environment so I enable the module the proper way (drush → cex) rather than hand-editing CMI.

---

## Claude — 2026-07-13 14:14

DDEV is up (web/db/redis OK), and the SimpleSAMLphp 2.5 library + `externalauth` are present. Let me enable the module the proper way, then set the config to match the house mapping.

---

## Claude — 2026-07-13 14:15

Module enabled (pulled in `externalauth`). Noted on the **dev instance, not staging** — that applies to the terraform/deploy env work; the app-repo CMI I'm doing now is env-agnostic. I'll record that constraint. First let me set the config to match the house mapping, then export cleanly.

---

## Claude — 2026-07-13 14:15

Understood — the "outside DDEV" validation targets the **dev instance, not staging**. I'll record that. Now let me export the config and review exactly what changed.

---

## Claude — 2026-07-13 14:15

Four files changed. Three are expected (`simplesamlphp_auth.settings` new, `core.extension` +2 modules, `views.view.authmap` is externalauth's default view). But `user.settings` is unexpected — let me inspect it; I don't want unrelated drift in this commit.

---

## Claude — 2026-07-13 14:16

The `user.settings` flip (`register: visitors_admin_approval → admin_only`) is a legitimate, intended side-effect: `simplesamlphp_auth` takes over registration to block public self-registration outside SAML — it matches DSF's committed state exactly. All four changes are correct. Let me verify the new settings file reads as intended.

---

## Claude — 2026-07-13 14:16

Clean and mandala-appropriate (dropped DSF's site-specific uids/roles down to admin only). Let me confirm the site still boots healthy and the module registered its login route — the meaningful thing verifiable in DDEV without a live IdP.

---

## Claude — 2026-07-13 14:17

Site's healthy — `cr` succeeded (full bootstrap) and `/saml_login` is registered and live. (The curl 000 is just the host→DDEV port mapping; DDEV serves on :8443, and drush proves the site boots.) **Step 1 (b) done.**

Now part (a): the deferred note. Let me match the existing format and update the index + nav.

---

## Claude — 2026-07-13 14:17

Now I'll write the deferred note and wire it into the index and nav.

---

## Claude — 2026-07-13 14:17

Now wire it into the deferred index table and the `.pages` nav:

---

## Claude — 2026-07-13 14:18

Noted — that's an important divergence from DSF. Let me record it in memory and reflect it in scope. Mandala likely wants SAML **auto-provisioning** (`register_users: true`), so part 4 must test the provisioning path too — not just match-existing.

---

## Claude — 2026-07-13 14:18

Let me summarize the branch state.

---

## Claude — 2026-07-13 14:18

Both done. Here's what landed on branch `feat/1b1-part4-netbadge-saml` (off `main`, nothing committed yet):

## (a) Deferred note — the terraform/SP bug
`docs/deferred/saml-alb-routing-assumes-mod-shib.md` (High), wired into the README table and `.pages` nav. It documents the verified finding — ALB routes `mod_shib` paths (`/user/netbadge`, `/Shibboleth.sso/*`) while the real SP is SimpleSAMLphp (`/saml_login`, `/simplesaml/*`) — with the live-probe evidence and the concrete terraform fix (correct paths, add the Ansible SimpleSAMLphp assets, separate the Redis DB/prefix).

## (b) App-repo `simplesamlphp_auth` enablement + CMI
Enabled via drush (pulled in `externalauth`), configured to the house mapping, exported cleanly:
- `simplesamlphp_auth.settings.yml` (new) — `uid`→username/unique_id, `mail`→mail, `auth_source: default-sp`, `login_link_display_name: 'Netbadge Login'`, trimmed to mandala-appropriate admin-only local login.
- `core.extension.yml` — `+simplesamlphp_auth`, `+externalauth`.
- `views.view.authmap.yml` (new) — externalauth's default view.
- `user.settings.yml` — `register: → admin_only` (the module's intended registration takeover; matches production/DSF).

Verified in DDEV: `drush cr` clean, `/saml_login` route live.

## Two things your provisioning note raises
Your point about lifting the user restriction is now captured in memory and changes part 4's test matrix. Two open items:

1. **Commit `register_users: false` or `true`?** I kept the committed CMI at `false` — the safe baseline that matches current production. Flipping to `true` (auto-provision any NetBadge holder) is a real access-policy decision, so I didn't do it unilaterally. Want it flipped now, or kept `false` with provisioning tested via a runtime toggle?
2. **Provisioning semantics to test:** when a brand-new NetBadge user auto-provisions, what Drupal roles/access do they get, and how do they end up in private collections? A freshly-provisioned user with no group memberships would (correctly, per ADR 014) see only public content — but the "how do NetBadge users join private collections" path is worth pinning down. That likely reaches past part 4 into the broader 1b access model.

Want me to answer #1 now (flip or keep), and should I fold the provisioning test matrix into the deferred note / a part-4 checklist doc — or is that enough for this session?

---

## User — 2026-07-13 14:19

Keep register_users false, fold provisioning matrix into the note

---

## Claude — 2026-07-13 14:19

Good — `register_users: false` stays as committed (no change needed there). Let me fold the provisioning test matrix into the note.

---

## Claude — 2026-07-13 14:20

Let me update memory to reflect the decision (baseline `false` confirmed; matrix now in the note).

---

## Claude — 2026-07-13 14:20

Done. Summary of this turn's work on branch `feat/1b1-part4-netbadge-saml`:

**Deferred note** (`docs/deferred/saml-alb-routing-assumes-mod-shib.md`) now covers both halves of the part-4 SAML story:
- The verified terraform ALB bug (mod_shib paths vs. the real SimpleSAMLphp SP) with live-probe evidence and the concrete fix.
- A **5-row user-provisioning test matrix** — match-existing (scenarios 1–2, `register_users: false`) and auto-provision (scenarios 3–5, `register_users: true`), with the key security invariant spelled out: a provisioned NetBadge user gets the authenticated role + a public-only visibility token; private access comes only from Group membership. It records that the committed default stays `false` and provisioning is proven by toggling at validation time.

**App-repo CMI** — unchanged from before, `register_users: false` as decided: `simplesamlphp_auth` + `externalauth` enabled, `simplesamlphp_auth.settings.yml` with the house `uid`→username / `mail` mapping, `/saml_login` verified live in DDEV.

**Memory** — `project-mandala-state` reflects the decision and the folded matrix; the deploy-pattern and terminology references are captured for the next session.

Nothing is committed. When you're ready, the natural commit split is: one for the deferred note (docs), one for the `simplesamlphp_auth` enablement + CMI. Want me to commit them, or leave the branch staged for you to review first?

---

## User — 2026-07-13 14:21

I'm thinking of changing my mind:  what would be the downside of folding user-provisioning into the current work.

---

## Claude — 2026-07-13 14:22

Good instinct to pressure-test it before committing. The honest answer: the downside isn't really about the code — enabling `register_users: true` is a one-line toggle — it's about **what you couple this branch to**. Three real costs:

**1. It collides with the user migration's unsolved identity model.**
This is the biggest one. Auto-provisioning keys new accounts on computing-id → username. But the D7 user migration will *also* import accounts — and per the `d7-shared-user-database` note, the SAML→account mapping ("name/mail match? a stored NetBadge id?") is explicitly **not yet solved**. If provisioning is live before that's settled, you get an ordering race: a NetBadge user who logs in first gets a *fresh* uid; the migration later tries to import *their* D7 account (with the collection memberships the 211 skipped 1b.2 rows depend on) under the same username → duplicate/split identity or a failed import. Folding provisioning in now effectively forces you to freeze the migration's identity strategy first, or risk baking in a model that fights it.

**2. The half that matters — group membership — doesn't exist yet.**
A provisioned user with no memberships correctly sees only public content. But *how a NetBadge user gets into a private collection* (auto-assign from SAML affiliation? migrated OG memberships? manual?) is undesigned. So "testing provisioning" now really only proves the public-only path (scenarios 3–4); scenario 5 (the meaningful one) is blocked on a membership mechanism that's out of part 4's scope. You'd be committing the feature while its point is unreachable.

**3. It's a genuine access-policy decision, not a dev default.**
`register_users: true` means *every* UVA NetBadge holder auto-gets a Drupal account. The whole fleet (DSF) and current prod run `false`. That divergence deserves David Germano / Dave sign-off — it shouldn't ride in on an auth-plumbing branch. And it widens the branch's review/blast radius, coupling a clean, mergeable coexistence proof to an open-ended question — against the narrow-scope pattern the repo's held to.

**The kicker:** the toggle approach already gives you the *test coverage* — flip to `true` on dev, run scenarios 3–4, flip back. So folding it in doesn't buy you testing you couldn't otherwise get; it only moves the *policy commitment* earlier, which is precisely the part carrying all the downside.

**Where it *would* be reasonable:** if the team has already decided Mandala is a NetBadge-open site **and** the migration's identity model is locked on computing-id-as-username (so provisioning and migration converge instead of race). If both are true, doing it now while the SAML context is warm saves a second pass.

**My recommendation:** keep `register_users: false` committed, prove provisioning via the dev toggle (scenarios 3–4), and leave the flip as a follow-up gated on the migration identity design + a policy sign-off. That's essentially what the note now captures. If you want, I can add a short "Why not fold provisioning in now" subsection to the note so this reasoning is on the record for the team — want that?

---

## User — 2026-07-13 14:26

nah.  Let's just proceed with register_users: false

---

## Claude — 2026-07-13 14:26

We're aligned — the branch already reflects `register_users: false`, so nothing to change. Part 4's app-repo increment stands as-is:

- `simplesamlphp_auth` + `externalauth` enabled, CMI with the house `uid`→username / `mail` mapping, `register_users: false`, `/saml_login` verified live in DDEV.
- Deferred note documenting the mod_shib→SimpleSAMLphp ALB bug + the provisioning test matrix.

Nothing's committed yet. Ready when you are — I'd suggest two commits:
1. `docs(deferred): record SAML ALB mod_shib→SimpleSAMLphp mismatch + provisioning matrix`
2. `feat(1b.1): enable simplesamlphp_auth + NetBadge attribute-mapping CMII` *(register_users:false baseline)*

Want me to commit them that way, or leave the branch for you to review first?

---

## User — 2026-07-13 14:27

commit them that way

---

## Claude — 2026-07-13 14:27

I'll commit them as two separate commits. Let me stage and commit the docs first, then the code.

---

## Claude — 2026-07-13 14:27

Both committed cleanly on `feat/1b1-part4-netbadge-saml`:

- `4e46eda` — docs(deferred): the ALB mod_shib→SimpleSAMLphp mismatch + provisioning matrix
- `430ebe9` — feat(1b.1): enable `simplesamlphp_auth` + NetBadge attribute-mapping CMI

Working tree is clean. The branch is off `main` and **not pushed** — say the word when you want it pushed / a PR opened.

Where part 4 stands after this session:
- ✅ App-repo SP-side CMI (this branch)
- ⬜ terraform-infrastructure: fix ALB routing + add the SimpleSAMLphp Ansible assets, targeting the **dev** instance (per the deferred note)
- ⬜ Outside-DDEV validation against the dev SP (incl. the provisioning toggle scenarios 3–4)
- ⬜ Token refresh / SAML session lifetime + React endpoint change

Memory's updated to reflect all of it, so the next session (whoever drives) starts from here.

---

## User — 2026-07-13 14:29

push it and open a PR

---

## Claude — 2026-07-13 14:30

Pushed and PR opened: **https://github.com/uvalib/mandala-navina/pull/32**

- Branch `feat/1b1-part4-netbadge-saml` → `main`, two commits (docs + CMI).
- PR body lays out what's included, what's explicitly out of scope (the remaining terraform/validation work targeting the **dev** instance), and notes the architectural coexistence is already Spike-10-proven so this is integration work.

That closes out the session's part-4 kickoff. Memory's current, so whoever picks up the terraform/ALB fix and the outside-DDEV validation next starts from here.

---

## User — 2026-07-13 14:34

What would be next.   We have anout 25 minutes available minutes.  Is there anything else we can accomplish in that time?

---

## Claude — 2026-07-13 14:35

Let me quickly assess the highest-value contained option — the known High-priority 1b.2 regression — so I can give you an honest feasibility read rather than guess.

---

## Claude — 2026-07-13 14:35

Good — that bug is well-understood but a *proper* fix (it needs a design choice: add a real `data`/boolean field vs. re-mechanism the `_inherited` flag, plus write+read path changes and removal test coverage) is more than 25 clean minutes. Let me run one quick hygiene check on the PR I just opened while I lay out the realistic options.

---

## Claude — 2026-07-13 14:36

Config round-trips clean (`No differences between DB and sync directory`) — so PR #32's CMI is solid and importable. 

Here's my honest read for the ~25 min:

**Recommended — fix the terraform ALB routing (continues part 4, and it's the concrete half of the bug I just documented).** Correct the path patterns in the mandala terraform from the `mod_shib` assumptions (`/user/netbadge`, `/Shibboleth.sso/*`) to the real SimpleSAMLphp paths (`/saml_login`, `/saml_logout`, `/simplesaml/*`), on a branch in `terraform-infrastructure` with its own PR. It's self-contained and I've already specified the exact change. **Caveats:** I can't `terraform plan` from here, so it's a reviewed-not-applied change; the SimpleSAMLphp Ansible assets remain a separate follow-up; and I'll need to confirm whether a **dev** env config even exists in that repo (you flagged dev as the target — mandala only had `staging`/`production` dirs when I looked).

**Not a clean 25-min job — the `group_relationship` regression.** Restoring `removeMember()` needs a design choice (real `data`/boolean field vs. re-mechanism the flag) plus write/read changes and *removal* test coverage that needs migrated group data loaded locally. Worth a dedicated session, not a time-boxed sprint-end.

**Alternative — close the session out.** This was a substantial session (memory refresh + full part-4 scoping + PR); I can run `scripts/save-session-log.py` and we stop clean.

My vote: **the terraform ALB fix** — it's the most tangible forward progress and directly finishes the story of today's PR. Want me to start by inspecting the mandala ALB config + checking for a dev env, then make the change?

---

## User — 2026-07-13 14:36

Okay.  Let's fix the ALB routing issue.

---

## Claude — 2026-07-13 14:36

I'll start by inspecting the full mandala ALB routing config and checking whether a dev environment exists in the terraform repo.

---

## Claude — 2026-07-13 14:37

No `dev` env in terraform — only `staging` and `production`. And notably the SAML routing rules exist **only in production** (5 blocks); `staging` has none. Let me read the production ALB config to make the fix correctly.

---

## Claude — 2026-07-13 14:37

Let me read one full rule block with its surrounding context to understand the structure before editing.

---

## Claude — 2026-07-13 14:37

Before editing, one thing needs checking: these rules forward to `authproxy_target_arn` — which looks scaffolded for a **separate mod_shib auth-proxy container**. But SimpleSAMLphp runs *inside* the Drupal container (served at `/simplesaml/` by the same Apache), so the target group may need to change too, not just the paths. Let me verify against how DSF routes it and what targets mandala's LB module exposes.

---

## Claude — 2026-07-13 14:37

Important finding: **DSF has no SAML path-routing rules at all** — it routes everything to one Drupal app target group (`aws_alb_target_group.target.arn`). That's because under SimpleSAMLphp, `/simplesaml/*` and `/saml_login` are served *by the Drupal container itself*, so they just hit the normal app target — no dedicated rule needed. Mandala's 5 `public-0-auth-*` rules forwarding to a separate `authproxy_target_arn` are a mod_shib artifact. Let me confirm what that target actually is before concluding.

---

## Claude — 2026-07-13 14:38

That reframes the task decisively — and not toward a quick edit. Two findings converge:

1. **`authproxy` is a separate, shared component**, not a target inside the Drupal container. It has its own terraform state (`${env}/authproxy/terraform.tfstate`), is referenced by *both* the Solr proxies and the Drupal ALB, and a sibling comment calls it "Apache (authproxy)" — it's a standalone Apache proxy container, scaffolded for the mod_shib design.
2. **DSF (the SimpleSAMLphp reference) has zero SAML routing rules** — `/simplesaml/*` and `/saml_login` just hit the normal Drupal app target because SimpleSAMLphp runs *in* the Drupal container.

So the correct fix isn't "swap the path patterns" — it's an infra-topology decision: **retarget those 5 rules to the Drupal app target group (or delete them entirely, DSF-style), not just rename the paths**. Plus your note about a second "dev" env inside the staging config, and the fact these rules currently exist only in `production`. That's not something I can safely blind-edit in shared infra HCL without a `terraform plan` and the topology knowledge — doing so in the last few minutes would be reckless.

The right move is to **stop and bank the finding**, which is genuinely more valuable than a guessed edit. Let me sharpen the deferred note so whoever picks it up (with plan access) starts from the correct framing.

---

## Claude — 2026-07-13 14:38

Let me commit this refinement to the branch so PR #32 reflects it.

---

## Claude — 2026-07-13 14:38

Pushed to PR #32. Let me quickly bank the topology finding in memory, then wrap up — we're at time.

---

## Claude — 2026-07-13 14:39

We're at time. Here's where this landed:

## Session close — part 4 kickoff

**Shipped (PR #32, `feat/1b1-part4-netbadge-saml`):**
- App-repo SP-side CMI: `simplesamlphp_auth` + `externalauth` enabled, house `uid`→username / `mail` mapping, `register_users: false`, `/saml_login` live, config round-trips clean.
- Deferred note documenting the ALB/mod_shib bug + provisioning test matrix.

**The ALB task turned out to be a topology decision, not a quick edit** — and stopping was the right call. Key finding, now banked in the note and memory: the 5 auth rules forward to a *separate shared Apache `authproxy` component* (its own tfstate, also used by the Solr proxies) — a mod_shift artifact. DSF, the SimpleSAMLphp reference, has **zero** SAML routing rules because `/simplesaml/*` is served by the Drupal container. So the real fix is to **retarget those rules to the Drupal app target (or delete them, DSF-style)** — which needs a `terraform plan` and `lb-visibility` topology knowledge I can't validate from here. Plus: rules exist only in `production`, and "dev" is a second env *inside* the staging configs (your note).

**Net:** the app-repo half of part 4 is merged-ready; the terraform half is now correctly scoped for a session with plan access rather than guessed at. Nothing left half-edited.

If you want, next session's natural start is the terraform ALB rework with `terraform plan` in hand, targeting the dev-in-staging env. Memory's current for whoever drives.

---

## User — 2026-07-13 14:41

So let's discuss this briefly.   I think the shibboleth routing was for the purposes of coordinating shibboleth configs elsewhere in terraform.  Since we handle all the simplesaml_config in the drupal container and drupal-netbadge container configs, we don't need to do that anymore....  is that the correct read?

---

## Claude — 2026-07-13 14:41

Yes — that's the correct read, with one distinction worth keeping straight.

**Why your read holds:**
- SimpleSAMLphp runs *inside* the Drupal container and serves `/simplesaml/*` + `/saml_login` as ordinary app paths. So they just need to hit the normal Drupal app target — no dedicated rule required.
- DSF confirms this by counterexample: it has **zero** SAML routing rules and works fine.
- The strongest evidence is your own live production site: `/Shibboleth.sso/` already **404s** there, yet NetBadge login works. Those 5 rules are already functionally dead — SAML is succeeding *without* them doing anything. So deleting them is removing vestige, not changing behavior.

So the `/user/netbadge` + `/Shibboleth.sso/*` → `authproxy` routing was indeed there to hand SAML off to a separate Shibboleth-handling proxy, and with the SP now living in the Drupal (and dev-side drupal-netbadge) containers, that hand-off is obsolete. **Deleting the 5 `public-0-auth-*` rules is the right move** — not retargeting, not renaming paths.

**The one distinction:** the `authproxy` *component itself* doesn't go away — `mandala/solr/*/alb-routing.tf` still forwards to `authproxy_target_arn` for the Solr visibility proxy (ADR 014). We're only deleting the **Drupal** ALB rules that route Drupal auth paths to it; the authproxy stays for Solr.

**Two things to confirm at execution (not blockers, just diligence):**
1. Run `terraform plan` to check the deletion doesn't disturb listener-rule *priorities* other rules depend on.
2. A quick check that nothing else (a healthcheck, a redirect) relies on `/user/netbadge` specifically before it's removed.

Net: your instinct simplifies the fix from "retarget 5 rules + fix paths" down to "delete 5 obsolete rules." Want me to update the deferred note to record this conclusion (delete, don't retarget) so the next person with plan access just executes it?

---

## User — 2026-07-13 14:44

Yes, update the note to record delete-don't-retarget, And we can handle the resultant cleanup later.  We will need to build the environment variable handling later anyway, which will touch on how simplesaml will be configured.

---

## Claude — 2026-07-13 14:45

Let me check the numbering — I may have created a collision with the existing items 2 and 3.

---

## Claude — 2026-07-13 14:45

Collision — two item 2's. Let me fold my env-var point into item 1's closing and remove the duplicate numbered item, so the existing items 2/3 keep their numbers.

---

## Claude — 2026-07-13 14:46

Numbering's clean now (1 delete rules → 2 Ansible assets/env vars → 3 Redis). Let me commit and push.

---

## Claude — 2026-07-13 14:46

Pushed to PR #32. Let me firm up the memory to match the decision (delete, not retarget).

---

## Claude — 2026-07-13 14:46

Done. The note now records the decision cleanly, and it's on PR #32.

**Recap of the conclusion (now on record):**
- **Fix = delete the 5 `public-0-auth-*` rules.** They're a mod_shib vestige; SimpleSAMLphp serves `/simplesaml/*` + `/saml_login` from the Drupal container, so those paths hit the normal app target (DSF has no SAML rules; live prod already 404s `/Shibboleth.sso/*` yet NetBadge works — proof the rules are already dead).
- The **`authproxy` component stays** — the Solr proxies still use it.
- **Resultant cleanup + the env-var/SimpleSAMLphp config wiring is later work** (item 2 in the note), and the rule-deletion folds naturally into that pass.
- Execution caveats preserved: `terraform plan` for listener-rule priorities, and target the dev-in-staging env.

Memory's aligned. Good place to stop — PR #32 carries the app-repo CMI plus a fully-scoped, decision-complete deferred note for the terraform half.

---

## User — 2026-07-13 14:46

Save the session log

---

## Claude — 2026-07-13 14:47

Current session is `effa3b8c…`. Let me save it.

---
