# SAML SP deploy playbooks (`deploy_netbadge.yml` / `deploy_backend.yml`): review findings, not yet addressed

**Area:** deployment / Ansible / SimpleSAMLphp / terraform-infrastructure
**Raised during:** Session 2026-10-05 (group session; side finding while checking what lives in `/mnt/data` on dev-0)
**Jira:** (add when available)
**Priority:** Low-Medium. Read-only review of the playbook source; nothing was run and no
live failure was observed. Finding 1 is the one worth fixing first.

## Scope and status

`terraform-infrastructure/mandala/drupal/staging/ansible/deploy_netbadge.yml` deploys the
`netbadge-0` container (SimpleSAMLphp SP, network alias `sp`). It was analysed by reading the
playbook and its helper playbooks only. **Deliberately deferred by the group on 2026-10-05:**
the findings are recorded here so they are not lost, and are not being worked now.

Overall the playbook is sound. Nothing unique lives on the host (cert, config and metadata are
all re-copied from source on each deploy), sessions are in shared Redis so recreating the
container does not log anyone out, and a required-env assert fails the deploy on an empty or
`CHANGE_ME` value.

## Findings, most important first

1. **A missing SAML private key is only a warning.** The "Copy SAML key" task runs
   `when: <decrypted .pem exists locally>`; if it does not, the play prints a `debug` message
   and continues. `check_private_keys.yml` does not catch it (it checks only the SSH instance
   key and the two bastion keys). The decrypted `.pem` is gitignored, so a run from a machine
   that has only the committed encrypted copy skips the copy silently. Effect depends on the
   host: a fresh host gets a container with no key; an existing host keeps whatever key it
   already has, which can hide a stale one. The `.crt` copy is unconditional, so a missing
   certificate does fail. **Not checked:** how the real CodePipeline run obtains the decrypted
   key (the `deployspec.yml` was not read). Candidate fix: make a missing key a hard failure,
   or decrypt inside the playbook.
2. **`ansible.builtin.copy` never deletes.** A file removed from the repo's `config/` or
   `metadata/` stays on the host, so deleted config can linger. `deploy_backend.yml` has the
   same pattern.
3. **The same SimpleSAMLphp files are copied to two host directories** (one by
   `deploy_backend.yml` for Drupal, one by `deploy_netbadge.yml`), though the comments describe
   them as shared volumes. They stay aligned only if both playbooks run.
4. **The playbook publishes a host port for the SP container** (`8081:80`), although the SP is
   meant to be reached through Drupal's reverse proxy. The terraform security groups were not
   found to mention it, so it is unconfirmed who can reach it. Worth confirming; ask Yuji
   rather than writing the answer here.
5. **Smaller items:** `SIMPLESAML_PROJECT` and `SIMPLESAML_ENV` build the key path but are not
   in `required_env_vars`; secrets reach the container as environment variables (the known
   deploy pattern); recreating the container briefly interrupts `/simplesaml/*`; the `log`
   directory sits on the root volume.

## Not verified

The pipeline's key-decryption step; network reachability of the published port; whether the
production playbook differs; and a cold deploy onto a fresh host end to end.

## Related

- [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md)
  (same playbook family; fixed)
- [saml-sp-cert-expired-renewal.md](saml-sp-cert-expired-renewal.md)
