# Session Log: CI/CD Pipeline and SAML/ALB Decisions

**Date:** 2026-08-11
**Participants:** Than Grove (driving), Yuji Shinozaki, Xiaoming Wang, Claude Code
**Status:** Agenda drafted pre-session; fill in Outcome/decisions below as the session proceeds.

---

## Agenda

Both items are Sprint 1b blockers (1b.1 part 4) that are stalled on a team/infra
decision rather than more Drupal work, and both keep resurfacing (2026-07-14,
2026-07-21) without a recorded decision. Bringing them to Yuji and Xiaoming today.

### 1. No CI/CD pipeline for the D11 app

[`d11-app-has-no-cicd-pipeline.md`](../deferred/d11-app-has-no-cicd-pipeline.md) — **High**

- `pipeline/buildspec.yml` / `deployspec.yml` exist but have never run. No ECR repo
  (`RepositoryNotFoundException`), no `/mandala/drupal/build_tag` SSM parameter, no
  `mandala-drupal` entry in `terraform-infrastructure/aws_cicd/pipelines/` (only
  `drupal-dsf` exists as a reference shape).
- Blocks 1b.1 part 4 validation directly — `deploy_backend.yml` pulls an image that
  doesn't exist.
- Escalated 2026-07-21: PR #45 (user-migration config) merged to `main` but can't
  reach dev-0 without a manual rebuild — generalized into
  [`dev-0-code-config-delivery-rebuild-or-pipeline.md`](../deferred/dev-0-code-config-delivery-rebuild-or-pipeline.md).
- **Decision needed:** write the real pipeline (`aws_cicd/pipelines/mandala-drupal/`
  modelled on `drupal-dsf`) vs. bootstrap by hand (manual ECR push) to unblock
  validation sooner and track the real pipeline as follow-up. Left open for
  Yuji/Dave in both deferred notes — no decision recorded yet.

### 2. SAML/ALB routing assumes mod_shib, but the SP is SimpleSAMLphp

[`saml-alb-routing-assumes-mod-shib.md`](../deferred/saml-alb-routing-assumes-mod-shib.md) — **High**

- Verified live against prod `mandala.library.virginia.edu`: the ALB routes mod_shib
  paths (`/user/netbadge`, `/Shibboleth.sso/*` → `authproxy`) but the actual SP is
  SimpleSAMLphp (`/saml_login`, `/simplesaml/*`, served by the Drupal container).
  `/Shibboleth.sso/*` 404s; NetBadge login works anyway — those 5 ALB rules are
  already dead in prod.
- App-repo half of 1b.1 part 4 is merged (PR #32); this is the remaining terraform +
  Ansible half, plus outside-DDEV validation on the dev instance.
- **Decision needed:** confirm deleting the 5 obsolete `public-0-auth-*` rules
  (not retargeting them), then fold the SimpleSAMLphp env-var/Ansible config into
  the terraform pass. Blocks 1b.1 part 4 → 1b.3 → 1b.4 → the deferred staging
  acceptance run → Sprint 1 close.

---

## Outcome

Than is not driving the live session — handing off to Yuji, who will run it with Xiaoming.
This doc is pushed as-is (agenda only, no decisions recorded yet) so Yuji/Xiaoming have the
two items queued up with full context. Whoever drives should fill in the actual decisions
made under each item above (or add a follow-up dated section) and update the two deferred
notes' status once resolved, per the usual session-end ritual.
