# dev-0's `/mnt/data` (uploaded files and deploy state) sits on the root volume, with no backup

**Area:** infrastructure / terraform-infrastructure / dev-0 durability
**Raised during:** Session 2026-10-05 (group session; checking where dev-0's files live)
**Jira:** (add when available)
**Priority:** Low-Medium. Read-only check of the host and terraform. Impact is limited
because the contents turned out to be recoverable (below); this records the gap and its
real, narrow cost.

## What was found

On dev-0 (`uva-mandala-drupal-staging-0`), the container bind-mounts its uploaded files,
SimpleSAMLphp state, OAuth keys and DB checkpoints from `/mnt/data/...`. `/mnt/data` is an
ordinary directory on the **root volume** (`delete_on_termination = true`). The separate
"persistent" EBS volume that terraform creates and attaches is mounted at `/mnt/docker` and
holds Docker's own storage only. Neither volume has a backup or snapshot policy (the
persistent volume is tagged `none`; the root volume carries no policy tag; no matching
snapshots were found). Production's root volume, by contrast, carries a backup tag in the
terraform. The host survives reboots and the nightly stop; it would not survive the instance
being replaced or terminated (for example an AMI-driven re-create).

## Why the impact is limited (checked 2026-10-05)

- **Uploaded files:** every managed file on dev-0 exists on legacy production with identical
  size, except 30 regenerable dev-0-only thumbnails (see
  [local-dev-files-provisioning-mechanism.md](local-dev-files-provisioning-mechanism.md),
  addendum 2026-10-05). Production is itself temporary.
- **SAML material:** the SimpleSAMLphp config, metadata and certificate are copied from the
  deploy repo on each run, not created on the host (but see
  [saml-sp-deploy-playbook-review.md](saml-sp-deploy-playbook-review.md)).
- **OAuth signing keys:** generated on the host only if missing. Losing them is recovered by
  the next deploy, at the cost of invalidating tokens already issued (see
  [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md)).
- **DB checkpoints** written there are the only contents with no other copy, and they are
  intentionally temporary.

## Options (not decided, not started)

Move or mount `/mnt/data` on the persistent volume; set `delete_on_termination = false`;
give the volume a snapshot policy; or, longer term, move uploaded files off the host.
Terraform-infrastructure takes commits straight to master, so any change there needs the
usual re-plan care. Ask Yuji before acting.

## Not verified

Production's actual mounts and its backup state in AWS (only its terraform was read); whether
any other host data is unique to dev-0.
