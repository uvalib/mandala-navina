# Plan: normalize `docs/deferred/` Area → Category, add Owner, restructure README

**Status:** Design complete, ready to execute. Branch `docs/deferred-notes-categories-and-owners`
created (not pushed yet as of this writing). Two content fixes already applied (see "Already
done" below); the mechanical 132-file header pass has not started.

**Why:** `docs/deferred/README.md`'s "Open items" table has accumulated 90+ distinct free-text
`**Area:**` tag combinations across 132 notes (e.g. `solr / kmassets / Images / write path` vs
`solr / kmassets / write path / Images` for the same concept) — unruly, hard to scan, hard to
filter. Ownership is similarly inconsistent: only 19 of 132 notes have an `**Owner:**` line at
all (not part of the documented header convention), the rest bury it as prose inside the
Priority cell ("ASSIGNED to Yuji...") or omit it entirely.

## Decisions made (all confirmed with Than, 2026-10-13)

1. Replace `**Area:**` with `**Category:**`, holding **1-3 values** from a **fixed, extensible**
   vocabulary (documented in README's "File header" section, can add new categories later by
   adding a line — not frozen, just not free-text prose).
2. **Final category vocabulary (10 values)** — note: an earlier draft had `local-dev` and
   `process` as standalone categories; Than asked to drop both and fold them into a new
   `miscellaneous` catch-all instead (see "miscellaneous" below):
   - `migration-av`
   - `migration-images`
   - `migration-texts`
   - `migration-sources` (currently 0 notes — site not migrated yet, kept for when it is)
   - `migration-general` (cross-site: orphans, legacy-nid convention, OG/Group content model,
     user migration, etc.)
   - `access-control` (Group permissions, visibility enforcement, content_editor/contributor
     tiers)
   - `auth` (SAML, OAuth2, SimpleSAMLphp, NetBadge)
   - `solr-search` (kmassets, kmterms, transcripts, search quality)
   - `infrastructure-deploy` (Terraform, CodePipeline, Ansible, RDS, Redis, fail2ban, reindeer_x,
     s3-sync)
   - `security` (vulnerabilities/exposures specifically, not just "infra")
   - `miscellaneous` — catch-all added for the 2 notes whose *only* category would otherwise
     have been the now-dropped `local-dev`/`process`: `local-dev-files-provisioning-mechanism.md`
     and `jira-issue-tracking-integration.md`. Every other note that had `local-dev`/`process` as
     a *secondary* tag just drops that tag and keeps its other category (see full mapping below).
3. Add/normalize an `**Owner:**` header line, documented as a real convention alongside
   Category/Raised during/Issue/Priority. Rules:
   - If the note already states ownership unambiguously (existing `Owner:`/`Owners:` line, or
     clear "ASSIGNED to X" prose) → carry it over verbatim. Allow **up to 2-3 names/teams** as a
     list (e.g. `Yuji, Dave`).
   - Do **not** infer ownership from who merely discussed/raised/was-present-for the note
     ("Session 2026-08-07, Than, Yuji present" is NOT ownership).
   - If the note is resolved/done/fixed/superseded/closed → `**Owner:** n/a (resolved)` — this
     **overrides** a historically-named owner (e.g. `transcript-tier-analyzers-and-language-fields.md`
     credits Than for building it, but since both its questions are DECIDED+BUILT, Owner is
     `n/a (resolved)`, not `Than`).
   - Otherwise → `**Owner:** unassigned`.
   - One note (`images-agent-name-paragraph-title-mapping.md`) literally has
     `**Owner:** mob (1a.7 is the pattern-setting migration)`. Than's call: items can legitimately
     be group-owned (the group has to address them in session), and `mob` reads as programming
     jargon rather than the term used everywhere else in this corpus — use **`group`** instead
     (matches "group decision"/"group session" terminology already standard here).
4. `mandala-home-customizable-content-system.md` doesn't fit any subsystem category cleanly
   (product/content feature, not migration-fidelity or infra) — Than's call: tag it
   `migration-general` rather than invent an 11th category for one file.
5. Restructure the README's "Open items" table into sections grouped by category (resolved notes
   collapsed into a short per-area appendix so the live/actionable list is visibly shorter than
   132 rows). Keep the existing "Awaiting a team decision" carve-out at the top as-is.
6. Scope: header-field changes only (Category + Owner lines) across all 132 notes, plus the
   README restructure. No renames. No other body edits — **except** the 3 specific
   stale-status fixes below, which Than explicitly authorized going beyond headers for.

## Already done (committed on this branch, not yet pushed)

Three items were flagged during classification as having stale/wrong status text, not just a
header problem. Than said "resolve 1-3" (a 4th, the `mob` label, is the Owner decision above,
not a content edit):

1. **`group-entity-editing-unwired.md`** — body said PR #308 was "held, not merged without
   asking Than." Verified via `gh pr view 308`: **merged 2026-10-09**. Updated the Status line
   to say RESOLVED/merged. → Owner: n/a (resolved).
2. **`migrate-group-import-aborts-on-partial-failure.md`** — body said the bug blocks every
   `--group` run "until the user migration is unblocked." User migration finished 2026-08-12.
   Added a dated (2026-10-13) note: the specific trigger no longer applies, but the underlying
   `migrate_tools` abort-on-any-partial-failure behavior is untouched and will recur with
   Texts/Sources/any future migration; the note's own 3-option recommendation was never decided
   and nothing has fixed it since. → stays Owner: unassigned, still genuinely open.
3. **`d11-app-has-no-cicd-pipeline.md`** — turned out to already have its own
   `## RESOLVED — 2026-08-11` section in the body (missed on first pass because only the 8-line
   header was read). No content edit needed. → Owner: n/a (resolved).
   - Checking this **also caught** that `dev-0-code-config-delivery-rebuild-or-pipeline.md` has
     the identical situation — already resolved in-body 2026-08-11 ("Option B was chosen and
     built... this note's premise no longer holds") — which corrects the original draft
     classification of it as "Owner: Yuji/Dave, decision open." → Owner: n/a (resolved).

**Process note for whoever finishes this:** the original classification pass was built mostly
from 8-line headers via a bulk `head -8` dump, not full-file reads. A follow-up grep across all
132 full bodies for resolution markers (`grep -icE "RESOLVED|DONE|FIXED|SUPERSEDED"`) caught these
two false negatives. **Before writing the final Category/Owner header on any note classified
below as still-open, do one more check that nothing later in the body already resolved it** —
the mapping below is believed accurate as of 2026-10-13 but was not exhaustively re-verified
file-by-file beyond what's noted above.

**Also learned this session, worth remembering generally:** a background Agent/fork call that
returns a detailed-looking result with **0 tool_uses** did no real work — it pattern-matched
plausible specifics from conversation context rather than reading files. Two consecutive
attempts at delegating "read all 132 notes and classify them" to a fork produced fabricated,
specific-sounding claims (e.g. an invented `**Owner:** mob` on a file that doesn't have one,
an invented `**Owner:** (unassigned) literal` that wasn't literal at all) that didn't survive a
spot-check against the real files. The actual classification in this plan was built by directly
reading real file dumps (`head -8` across all files via Bash, plus full-body greps for
Owner/ASSIGNED/RESOLVED patterns, plus full reads of ambiguous files) in-session, not delegated.

## Full per-file mapping (as classified 2026-10-13)

Grouped by primary category. "Owner" already reflects the corrections above (the two
pipeline-delivery notes, the two authorized content fixes, the `group` relabel, and the
migration-general placement for the home-page note).

### migration-av
| File | Categories | Owner |
|---|---|---|
| av-available-from-end-date-not-migrated.md | migration-av | unassigned |
| av-collection-memberships-dropped-for-deleted-d7-user.md | migration-av | n/a (resolved) |
| av-collections-without-d7-group-access.md | migration-av, access-control | unassigned |
| av-delete-kaltura-checkbox-admin-session-verification.md | migration-av | n/a (resolved) |
| av-dump-corrupted-no-data-profile.md | migration-av | n/a (resolved) |
| av-field-audit-d7-vs-d11-technical-metadata.md | migration-av | Than |
| av-migration-verify-orphan-count-drift.md | migration-av | unassigned |
| av-private-uva-content-not-enforced-in-drupal.md | migration-av, access-control | n/a (resolved) |
| av14-media-less-nodes-staff-handoff.md | migration-av | unassigned |
| av15-avinfo-abandoned-fields-review-with-than.md | migration-av | unassigned |
| av15-pbcore-duration-vs-kaltura-duration.md | migration-av | unassigned |
| av2-field-tags-old-label-inconsistency.md | migration-av | unassigned |
| av4-84-und-en-content-differences.md | migration-av | unassigned |
| av4-dev0-live-migration-run.md | migration-av, infrastructure-deploy | n/a (resolved) |
| av4-field-pbcore-language-iso639-conversion.md | migration-av, solr-search | unassigned |
| av4-instantiation-wrong-winner.md | migration-av | n/a (resolved) |
| av4-paragraph-ordering-ties.md | migration-av | unassigned |
| av4-relation-identifier-not-migrated.md | migration-av | n/a (resolved) |
| transcript-bad-timecodes-investigate-and-fix.md | migration-av | unassigned |
| transcript-source-file-accounting.md | migration-av | unassigned |
| transcript-tier-analyzers-and-language-fields.md | migration-av, solr-search | n/a (resolved) |

### migration-images
| File | Categories | Owner |
|---|---|---|
| d7-alias-preservation-scope-beyond-shanti-image.md | migration-images, migration-general | unassigned |
| image-descriptions-summary-length.md | migration-images | n/a (resolved) |
| images-agent-name-paragraph-title-mapping.md | migration-images | group |
| images-description-text-format-fidelity.md | migration-images | unassigned |
| images-field-image-binary-migration.md | migration-images | unassigned |
| images-iiif-thumb-size-format.md | migration-images | unassigned |
| images-missing-interactive-viewing-surfaces.md | migration-images | unassigned |
| images-node-authorship-not-migrated.md | migration-images | unassigned |
| images-prod-packaging-monorepo-pass.md | infrastructure-deploy, migration-images | Yuji, Xiaoming |
| images-projects-ss-producer-field.md | migration-images, solr-search | unassigned |
| images-rotation-field-support.md | migration-images, solr-search | unassigned |
| images-title-sort-s-possible-silent-truncation.md | migration-images, solr-search | unassigned |
| iiif-prefix-alignment-mandala-vs-canonical.md | migration-images | Than Grove |
| collection-featured-images-missing-on-production.md | migration-images | n/a (resolved) |

### migration-texts
| File | Categories | Owner |
|---|---|---|
| texts-footnotes-production-transform.md | migration-texts | unassigned |

### migration-sources
None yet.

### migration-general
| File | Categories | Owner |
|---|---|---|
| adr-015-unanswered-questions-at-merge.md | access-control, migration-general | n/a (resolved) |
| authenticated-contributor-crud-not-wired-in-d11.md | access-control, migration-general | unassigned |
| canonical-d7-dev-source-dump.md | migration-general | unassigned |
| carousel-demo-file-churn-on-dev0.md | migration-general | unassigned |
| d7-editor-permissions-og-group-scoped-not-migrated.md | access-control, migration-general | unassigned |
| d7-shared-user-database.md | migration-general, auth | Yuji |
| d7-user-role-migration-wipes-committed-role-permissions.md | migration-general, access-control | n/a (resolved) |
| endpoint-field-inventories-are-lower-bounds.md | migration-general | unassigned |
| kmaps-raw-format-rebuild-on-migration.md | migration-general, solr-search | unassigned |
| kmassets-kmapid-ancestor-id-resolution.md | solr-search, migration-general | unassigned |
| kmassets-sync-hook-fires-during-migration.md | solr-search, migration-general | n/a (resolved) |
| kmassets-uid-consumer-analysis.md | solr-search, migration-general | unassigned |
| kmassets-uid-identity-across-migration.md | solr-search, migration-general | unassigned |
| legacy-url-host-aware-redirect-mechanism.md | migration-general, infrastructure-deploy | unassigned |
| legacy-url-syntax-audit-drupal-content.md | migration-general | unassigned |
| load-staging-baseline-false-clean-on-nonzero-schema.md | migration-general | unassigned |
| migrate-drupal-noise-site-specific-dump.md | migration-general | Than Grove |
| migrate-entity-group-update-mode-nulls-uid.md | migration-general | unassigned |
| migrate-group-import-aborts-on-partial-failure.md | migration-general | unassigned |
| migrate-large-migration-oom-and-resume-behavior.md | infrastructure-deploy, migration-general | Yuji |
| migrate-shared-vs-migrate-users-connection-duplication.md | migration-general | n/a (resolved) |
| migration-legacy-nid-required-convention.md | migration-general | unassigned |
| og-collection-ref-storage-empty-use-og-membership.md | migration-general | n/a (resolved) |
| orphaned-content-temp-group-on-migration.md | migration-general, access-control | Than |
| production-migration-planning.md | migration-general, infrastructure-deploy | unassigned |
| user-migration-run-on-dev0.md | migration-general | n/a (resolved) |
| mandala-home-customizable-content-system.md | migration-general | unassigned |

### access-control
| File | Categories | Owner |
|---|---|---|
| collection-visibility-not-enforced-in-listings.md | access-control | n/a (resolved) |
| group-access-inheritance-subcollections.md | access-control | n/a (resolved) |
| group-entity-editing-unwired.md | access-control, migration-general | n/a (resolved) |
| group-relationship-delete-broken-no-data-field.md | access-control | n/a (resolved) |
| group-subgroup-nesting-approach.md | access-control | n/a (resolved) |
| mandala-group-inheritance-cascade-remove-null-account-on-user-delete.md | access-control | unassigned |
| mandala-node-api-no-identity-forwarded-through-json-proxy.md | auth, access-control | unassigned |
| option-a-proxy-unavailable-on-standalone-deployments.md | security, access-control | unassigned |
| solr-proxy-uid1-admin-gets-anonymous-filter.md | solr-search, access-control | n/a (resolved) |
| subcollection-access-overwritten-by-inheritance-hook.md | access-control, migration-general | unassigned |

### auth
| File | Categories | Owner |
|---|---|---|
| dev-0-needs-test-idp-for-saml-login-testing.md | auth | n/a (resolved) |
| oauth-openid-scope-client-credentials-crash.md | auth | unassigned |
| oauth2-signing-keys-not-persisted-across-deploy.md | auth, infrastructure-deploy | n/a (resolved) |
| redis-enterprise-store-location.md | infrastructure-deploy, auth | unassigned |
| saml-alb-routing-assumes-mod-shib.md | auth, infrastructure-deploy | n/a (resolved) |
| saml-logout-does-not-terminate-netbadge-idp-session.md | auth, security | unassigned |
| saml-session-expires-8h-forcing-logout-mid-session.md | auth | unassigned |
| saml-sp-cert-expired-renewal.md | auth, infrastructure-deploy | unassigned |
| saml-sp-deploy-playbook-review.md | auth, infrastructure-deploy | unassigned |
| simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md | auth | n/a (resolved) |
| simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md | auth | unassigned |
| simplesamlphp-never-configured-in-ddev.md | auth | unassigned |
| simplesamlphp-redis-store-missing-predis.md | auth, infrastructure-deploy | n/a (resolved) |
| solr-proxy-genericprovider-no-bearer-header-on-userinfo.md | auth, solr-search | n/a (resolved) |
| d11-asset-endpoints-uniform-access-and-authenticated-fetch.md | access-control, auth | Than |
| kmassets-index-has-no-d11-uids.md | solr-search, auth | n/a (resolved) |

### solr-search
| File | Categories | Owner |
|---|---|---|
| kmaps-widget-ux.md | solr-search | unassigned |
| kmassets-audit-checks-master-not-search-reader.md | solr-search | Yuji |
| kmassets-audit-hardening.md | solr-search | Yuji |
| kmassets-collection-docs-and-facets.md | solr-search, access-control | unassigned |
| kmassets-production-index-frozen.md | solr-search | unassigned |
| kmassets-sync-error-management.md | solr-search, infrastructure-deploy | unassigned |
| solr-cross-environment-write-targets.md | solr-search, infrastructure-deploy, security | Yuji |
| solr-pipeline-cost-discussion.md | solr-search, infrastructure-deploy | Yuji |
| solr-proxy-has-no-cicd-pipeline.md | infrastructure-deploy, solr-search | n/a (resolved) |
| solr-proxy-session-id-forwarded-to-solr.md | solr-search, security | unassigned |
| solr-proxy-session-per-anonymous-request.md | solr-search | n/a (resolved) |
| solr-sync-architecture-d11.md | solr-search | unassigned |
| spike-solr-demo-enabled-with-anonymous-route.md | solr-search, infrastructure-deploy, security | unassigned |
| searchstax-defunct-external-solr-config.md | solr-search, security | unassigned |
| tibetan-search-quality.md | solr-search | unassigned |
| transcript-core-replica-lag-review.md | solr-search | unassigned |
| transcript-index-queue-triage.md | solr-search, infrastructure-deploy | unassigned |
| production-solr-not-receiving-d7-writes.md | solr-search, infrastructure-deploy | Yuji |

### infrastructure-deploy
| File | Categories | Owner |
|---|---|---|
| codepipeline-webhook-plus-manual-trigger-duplicates-deploy.md | infrastructure-deploy | unassigned |
| config-export-drift-hand-edited-yaml.md | infrastructure-deploy | n/a (resolved) |
| config-export-not-scoped-strips-comments.md | infrastructure-deploy | unassigned |
| d11-app-has-no-cicd-pipeline.md | infrastructure-deploy | n/a (resolved) |
| d11-dev-database-bootstrap-and-migration-source.md | infrastructure-deploy | unassigned |
| dev-0-code-config-delivery-rebuild-or-pipeline.md | infrastructure-deploy | n/a (resolved) |
| dev-migration-slower-than-ddev-cross-az-latency.md | infrastructure-deploy, migration-general | n/a (resolved) |
| dev0-data-directory-on-unbacked-root-volume.md | infrastructure-deploy | unassigned |
| dev0-image-styles-directory-root-owned.md | infrastructure-deploy, migration-images | n/a (resolved) |
| dev0-unicode-filenames-need-nfc-normalization.md | infrastructure-deploy | n/a (resolved) |
| drupal-image-missing-mysql-client.md | infrastructure-deploy | unassigned |
| deploy-never-imports-config-sync.md | infrastructure-deploy | n/a (resolved) |
| pipeline-triggers-on-every-monorepo-commit.md | infrastructure-deploy | n/a (resolved) |
| pre-deploy-rds-snapshot-gate.md | infrastructure-deploy | unassigned |
| rdx-alb-target-unhealthy-in-production.md | infrastructure-deploy | Yuji |
| reindeer-x-aws-credential-strategy.md | infrastructure-deploy | Yuji |
| reindeer-x-has-no-ecr-repo-or-pipeline.md | infrastructure-deploy | Yuji |
| s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md | infrastructure-deploy | n/a (resolved) |
| staging-migration-execution-prerequisites.md | infrastructure-deploy, migration-general | Xiaoming, Yuji, Dave |
| terraform-infrastructure-full-clone-on-every-deploy.md | infrastructure-deploy | n/a (resolved) |
| wp-kmaps-mandala-proxy-dependency.md | infrastructure-deploy | n/a (resolved) |
| fail2ban-need-and-ownership.md | security, infrastructure-deploy | unassigned |
| iiif-cantaloupe-404-information-disclosure.md | security, infrastructure-deploy | UVa Library DevOps |

### security
| File | Categories | Owner |
|---|---|---|
| mandala-wp-proxy-json-proxy-open-ssrf.md | security | n/a (resolved) |

### miscellaneous
| File | Categories | Owner |
|---|---|---|
| local-dev-files-provisioning-mechanism.md | miscellaneous | n/a (resolved) |
| jira-issue-tracking-integration.md | miscellaneous | Yuji |

**Count check:** that's 133 files (132 classified 2026-10-13, plus `production-solr-not-receiving-d7-writes.md`, added to `main` by #326 after the plan was drafted and classified 2026-10-14) total across the table above (migration-av 21, migration-images
14, migration-texts 1, migration-general 27 incl. subcollection-access note counted once, access-control
10, auth 16, solr-search 18, infrastructure-deploy 23, security 1, miscellaneous 2 — some files
appear once under their primary category heading even though they carry 2-3 category tags;
cross-tagged files are listed once, under whichever heading was more natural while drafting, not
duplicated).

## Remaining execution steps

1. Re-verify (quick grep pass, per the "Process note" above) that none of the "still open" notes
   in the table have a hidden resolution deeper in the body that a header-only read would miss.
2. For each of the 132 files: replace `**Area:**` with `**Category:**` (values from the table
   above) and add/replace the `**Owner:**` line (value from the table above), per the format
   documented in README's "File header" section (to be updated as step 4).
3. Update `docs/deferred/README.md`:
   - Add `**Category:**` vocabulary (the 10 values above, with one-line descriptions) and
     `**Owner:**` rules to the "File header" convention section.
   - Restructure "Open items" into sections by category, each still a File/Raised/Priority
     table; pull resolved notes into a collapsed per-category "Resolved" subsection or a single
     "Resolved" appendix so the open/actionable list is visibly short.
   - Keep "Awaiting a team decision" carve-out as-is.
4. Commit, push, open PR for Than + team review (do not self-merge — this is a mechanical pass
   across every note, worth a second pair of eyes before merging per the repo's normal PR
   workflow).
