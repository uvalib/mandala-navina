# ADR 003: Replace Aegir/docker-compose with Terraform + Ansible + AWS CodePipeline

**Status:** Accepted  
**Date:** 2026-06-09  
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The legacy Mandala deployment used Aegir — a Drupal-based hosting control panel —
running inside a docker-compose environment (`mandala_drupal_docker`). This approach:

- Is architecturally obsolete; Aegir has no active community and adds a Drupal-inside-
  Drupal complexity layer that serves no current purpose
- Is out of step with UVA Library's standard deployment practice, which has moved to
  Terraform + Ansible + AWS CodePipeline (established on `drupal-library` and
  `dsf.library.virginia.edu`)
- Makes local development and CI/CD harder to reason about

## Decision

Mandala adopts the **UVA Library standard deployment pattern**:

| Concern | Tool |
|---------|------|
| Infrastructure provisioning | Terraform (`uvalib/terraform-infrastructure`, `mandala/drupal/` subdirectory) |
| Application configuration / deploy | Ansible playbooks |
| CI/CD orchestration | AWS CodePipeline (not GitHub Actions) |
| Container build | AWS CodeBuild (`pipeline/buildspec.yml`) → image pushed to AWS ECR |
| Container deploy | AWS CodeBuild (`pipeline/deployspec.yml`) → Terraform + Ansible |
| Authentication | aws-vault (local) / IAM instance role (CI) |

The `mandala_drupal_docker` repo and its Aegir machinery are retired. Docker-compose
is not used in production; DDEV is used for local development only.

## Consequences

- `pipeline/buildspec.yml` and `pipeline/deployspec.yml` in this repo define the
  full build and deploy pipeline, matching the pattern in `drupal-library`.
- Terraform infrastructure lives in `uvalib/terraform-infrastructure` under
  `mandala/drupal/` — not in this repo.
- Local development uses DDEV exclusively (`ddev start`, `ddev drush`, etc.).
- The `mandala_drupal_docker` legacy repo is preserved as read-only reference only.
- Operational scripts (e.g., production tagging) follow the reusable pattern
  established in `~/Code/scripts/`, with aws/aws-vault-agnostic auth handling.
