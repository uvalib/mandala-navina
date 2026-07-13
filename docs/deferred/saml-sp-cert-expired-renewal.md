# SAML SP signing cert is expired — plan renewal + ITS re-registration

**Area:** deployment / SAML / NetBadge / terraform-infrastructure
**Raised during:** 2026-07-13 (1b.1 part 4 — recovering the live SP keypair for the D11 deploy)
**Jira:** (add when available)
**Priority:** Medium (works today), High before a production cutover

## What we found

The live mandala SimpleSAMLphp SP keypair (recovered from `dev-0` at
`/var/aegir/platforms/mandala-base-dev/simplesamlphp/cert/saml.{crt,pem}`) is a
**self-signed X.509 cert that expired 2026-06-30**:

```
subject=issuer = C=US, ST=Virginia, L=Charlottesville, O=SHANTI, OU=SHANTI, CN=Raf, emailAddress=rca2t@virginia.edu
notBefore=Jun 30 2016 ... notAfter=Jun 30 2026   (expired)
```

It **still functions** because Shibboleth/SAML trusts SP certs by **metadata
registration**, not PKI validity (`notAfter` is not hard-enforced for SP
signing/encryption certs). That is why we could store this exact pair to match the
current NetBadge registration and keep the D11 deploy moving — see the stored,
encrypted copy:

- `mandala/drupal/staging/keys/mandala-drupal-saml-staging.crt` (public)
- `mandala/drupal/staging/keys/mandala-drupal-saml-staging.pem.cpt` (ccrypt-encrypted,
  reusing the `mandala/drupal/staging/keys/mandala-drupal-staging.pem` Secrets-Manager
  secret-name, per the library/dsf convention)

**Renewal is decoupled from the D11 bring-up** (the stored pair reproduces today's
behavior) but must happen — the cert is expired now and this pair is on borrowed
time.

## Renewal plan

1. **Generate** a fresh self-signed keypair with **`openssl`** (NOT
   `terraform-infrastructure/scripts/gen-key.ksh` — that's `ssh-keygen`, for SSH host
   keys). Match the fleet convention dsf/library used when they regenerated in
   Mar 2026 (10-yr validity):
   `C=US, ST=Virginia, L=Charlottesville, O=University of Virginia, OU=Library IT,
   CN=Mandala-drupal-simplesamlphp`, RSA ≥ 3072.
2. **Store** via the proven pattern: `scripts/crypt-key.ksh <new.pem>
   mandala/drupal/<env>/keys/mandala-drupal-<env>.pem`, commit the new `.crt` +
   `.pem.cpt` (raw `.pem` stays gitignored). Run under `aws-vault exec staging --`.
3. **Register the new cert / SP metadata with ITS NetBadge — via Dave Goldstein.**
   This is the gating step (lead time). NetBadge **encrypts assertions to the SP
   cert**, so the IdP must trust the new cert *before* cutover or logins break.
4. **Rollover without an outage** — preferred: publish SP metadata containing **both**
   old + new certs (SimpleSAMLphp supports multiple keys with
   `use=signing`/`use=encryption`), have ITS ingest it, deploy the new key, then
   retire the old cert. Fallback: a coordinated ITS maintenance window for an atomic
   swap.
5. **Both environments** — staging (dev) and production
   (`mandala-drupal-saml-production`). **Check production's *actual* current cert
   first** — it may be a different keypair than this dev one (the recovered pair is
   from `mandala-base-dev`).
6. **Validate** — SP metadata at
   `/simplesaml/module.php/saml/sp/metadata.php/default-sp`, a full NetBadge login,
   and confirm assertion **decryption** works with the new private key.

## Notes

- The SP `entityID` is `null` (host-derived) in the D7 config — so the SP identity is
  tied to the hostname (`mandala-dev.internal.lib.virginia.edu`). Keep that host on
  the D11 instance and the entityID/ACS stay stable; only the **cert** changes at
  renewal, which is exactly what ITS needs to re-trust.
- Related: `saml-alb-routing-assumes-mod-shib.md` (the other part-4 terraform item),
  `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5 (Dave/ITS open items),
  [[reference-d7-shibboleth-sp-config]].
