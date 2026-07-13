# Mandala terraform ALB routing assumes `mod_shib`, but the SP is SimpleSAMLphp

**Area:** deployment / infrastructure / SAML / NetBadge / terraform-infrastructure
**Raised during:** Session 2026-07-13 (1b.1 part 4 — scoping NetBadge/SAML outside DDEV)
**Jira:** (add when available)
**Priority:** High — blocks NetBadge auth on any AWS deploy (dev/staging/prod); part of 1b.1 part 4

## What we found

The mandala terraform config
(`terraform-infrastructure/mandala/drupal/{production,staging}/alb-routing.tf`)
routes SAML/NetBadge traffic on the path patterns:

```
/user/netbadge
/Shibboleth.sso/*
```

Those are **native Shibboleth SP (`mod_shib`, Apache module)** endpoints. The
terraform was written assuming mandala would use `mod_shib` as its SAML Service
Provider.

But the actual implementation — on the live production site **and** the D7 legacy —
uses **SimpleSAMLphp** (`drupal/simplesamlphp_auth`), whose endpoints live under
`/simplesaml/*` with the Drupal login trigger at `/saml_login`. The team switched
SP technology and never went back to update terraform.

## Evidence (probed live 2026-07-13)

Against `https://mandala.library.virginia.edu` (title "Mandala Collections - Kmaps"):

| Endpoint | Result | Interpretation |
|---|---|---|
| `/saml_login` | `302` → `https://shibidp.its.virginia.edu/idp/profile/SAML2/Redirect/SSO?SAMLRequest=…&RelayState=…/saml_login` | SimpleSAMLphp (`simplesamlphp_auth`) login, federating to the UVA NetBadge IdP |
| `/simplesaml/` | `200` (SimpleSAMLphp frontpage) | SimpleSAMLphp SP is running |
| `/simplesaml/module.php/core/authenticate.php?as=default-sp` | references `shibidp.its.virginia.edu` | SP `default-sp` points at the UVA IdP |
| `/Shibboleth.sso/Metadata` | `404` | native `mod_shib` is **not** present |
| `/user/netbadge` | `404` | the mod_shib-assumed ALB path does not exist |

This is consistent with the rest of the UVA Library fleet (`drupal-dsf`,
`drupal-library`, `drupal-netbadge`), which all deploy **SimpleSAMLphp** via Ansible
in `terraform-infrastructure/<site>/<env>/ansible/files/var/simplesamlphp/…`. See
also the D7 legacy config at
`mandala-legacy/mandala-drupal/simplesamlphp/` (same IdP, `default-sp`, SimpleSAMLphp).

## Fix (fold into 1b.1 part 4)

In `terraform-infrastructure/mandala/drupal/<env>/`:

1. **Correct the ALB routing path patterns** from `/user/netbadge` + `/Shibboleth.sso/*`
   to the SimpleSAMLphp paths: `/saml_login`, `/saml_logout`, and `/simplesaml/*`
   (SP ACS is `/simplesaml/module.php/saml/sp/saml2-acs.php/default-sp`).
   Confirm what target group / behavior those rules attach before editing.
2. **Add the SimpleSAMLphp Ansible assets** the fleet pattern expects (cloned from
   `dsf.library.virginia.edu` and re-pointed): `files/var/simplesamlphp/config/{authsources,config}.php`,
   `metadata/saml20-idp-remote.php`, `drupal-config/simplesamlphp_auth.settings.yml`,
   `deploy_netbadge.yml`, and `container_0.env.{managed,secret}` with
   `SIMPLESAML_PROJECT`, `SIMPLESAML_SP_ENTITY_ID`, `SIMPLESAML_DEFAULT_IDP=urn:mace:incommon:virginia.edu`, etc.
3. **Redis separation:** SimpleSAMLphp's session store uses the shared Redis cluster
   (`SIMPLESAML_STORE_TYPE=redis`). Give it a distinct `SIMPLESAML_REDIS_DATABASE` +
   key prefix so it never collides with ADR 014's `mandala_solr_fq:{uid}` visibility token.

## User provisioning test matrix (part 4)

**Divergence from DSF.** DSF runs `register_users: false` (SAML only maps to
pre-existing, admin-created Drupal accounts). Mandala will **likely lift that
restriction** and enable SAML **auto-provisioning** (`register_users: true`) so any
UVA NetBadge holder can get a Drupal account on first login. The committed app-repo
CMI keeps `register_users: false` as the safe baseline (matches current prod); this
is a deliberate decision (2026-07-13) — the provisioning path is proven by toggling
the setting at validation time, not by changing the committed default.

Part 4 must exercise **both** modes:

| # | Scenario | `register_users` | Precondition | Expected result |
|---|---|---|---|---|
| 1 | Match existing account | `false` | Drupal account exists, username = UVA computing id | SAML assertion maps to the existing account by `uid` (`urn:oid:…100.1.1`); no new account; user authenticated |
| 2 | No match, provisioning off | `false` | No Drupal account for that computing id | No account created; login yields no Drupal session (baseline / current prod behavior) |
| 3 | Auto-provision new account | `true` | No Drupal account for that computing id | New account created from the assertion (`uid`→username, `mail`→mail); authenticated with **authenticated-user role only** (no elevated roles) |
| 4 | Provisioned user → visibility token | `true` | Fresh provisioned user, no group memberships | `hook_user_login` writes `mandala_solr_fq:{uid}`; proxy returns a **public-only** filter — provisioned ≠ authorized for private content |
| 5 | Provisioned user joins a private collection | `true` | Provisioned user added to a `collection` group | `hook_group_relationship_insert` updates the Redis token; user now sees that collection's private content (and only that) |

Points to confirm while testing:

- **Roles on provision** — a freshly auto-provisioned user must land with *only* the
  authenticated role; access to private collections comes solely from Group
  membership (ADR 011 / ADR 014), never from mere authentication.
- **Toggle mechanism** — flipping `register_users` should be a config change (CMI /
  env), not a redeploy; verify the value can differ per environment (e.g. `true` on
  dev for provisioning tests, conservative default elsewhere).
- **Security framing** — auto-provisioning means "any NetBadge holder gets an
  *account*," not "any NetBadge holder sees private content." Scenarios 4–5 are the
  proof that the visibility model still gates on membership, not on login.
- **How NetBadge users join private collections** (scenario 5's precondition) is a
  broader 1b access-model question that likely extends past part 4 — note it, don't
  block part 4 on it.

## Notes

- Current-development environment target is the **dev instance**, not staging
  (per Yuji, 2026-07-13). The D7 `default-sp` entityID/ACS were on
  `mandala-dev.internal.lib.virginia.edu` — an existing dev SP is already available.
- The SP-side Drupal CMI (`simplesamlphp_auth.settings.yml`, uid→username / mail
  mapping, `register_users: false`) was added to the app repo on branch
  `feat/1b1-part4-netbadge-saml`; this note covers only the **terraform/ALB** half.
