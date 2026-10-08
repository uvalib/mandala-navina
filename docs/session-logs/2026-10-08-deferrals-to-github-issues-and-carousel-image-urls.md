# Session Log: Deferrals Move to GitHub Issues; Carousel Image-URL Fix

**Date:** 2026-10-08  
**Participants:** Yuji Shinozaki, Claude Code (Xiaoming Wang and Than Grove in the session for the issue-tracking discussion)  
**Outcome:** Deferrals are now tracked as GitHub Issues (label `deferred`) with the `docs/deferred/` note holding the why ([#287](https://github.com/uvalib/mandala-navina/pull/287), [#288](https://github.com/uvalib/mandala-navina/pull/288), [#293](https://github.com/uvalib/mandala-navina/pull/293), [#299](https://github.com/uvalib/mandala-navina/pull/299)); eleven issues opened (#289–#292, #294–#298, #300, #304); a live dev-0 carousel report was diagnosed and fixed ([#306](https://github.com/uvalib/mandala-navina/pull/306), deployed). See [README.md](../deferred/README.md#tracking-github-issues-decided-2026-10-08).

## Start-of-session state

dev-0 healthy (last deploy `222cc75f`, 2026-10-06). Local DB differed from dev-0 by two groups and their memberships; that is Than's orphan-review-group work, so the local DB was left alone. PR #286 (AV field audit go-ahead) merged.

## Decisions

- **Deferrals are tracked in GitHub Issues, not Jira.** They are internal developer tracking, not user-facing work, and Than has no developer Jira access. Note = the why, issue = status/owner. This supersedes the "each note maps 1:1 to a Jira ticket" intent (dated update in [jira-issue-tracking-integration.md](../deferred/jira-issue-tracking-integration.md)).
- **Any deferral may be promoted to an issue, owner or not.** Unassigned = *free to pick up* or *needs a group decision*; the first line says which. `scripts/promote-deferral.sh` does it (preview + confirmation, body is a link and status only because the repo is public).
- **Start and close reviews cover issue status, including unassigned issues.** CLAUDE.md and both check scripts changed together.
- **Doc-only deferrals stay as notes** and are reviewed periodically, via the standing issue [#300](https://github.com/uvalib/mandala-navina/issues/300). Experiment; change it if it doesn't work.
- **Do not assign issues to Dave.** The team manages interactions with him; the issue body says "needs input from Dave" instead.
- **Jira is for user reports and requests, coordinated internally through one or more GitHub issues.** There is no Jira project yet; standing it up is [#304](https://github.com/uvalib/mandala-navina/issues/304) (owner Yuji). Until it exists, GitHub issues carry "Jira: none yet" and are backfilled.

## Open question left for the team

Bugs are a fourth kind of tracking item alongside sprint tasks, spikes and deferrals. The light rule proposed: a quickly fixed bug is recorded by its issue and fix PR; a bug not fixed now becomes a deferral; a lasting lesson goes in a session log or `docs/dev-notes/`; a design decision goes in an ADR. Session start/close do not list `bug` issues yet. Not decided.

## Carousel report ([#303](https://github.com/uvalib/mandala-navina/issues/303))

A stakeholder reviewing dev-0 as an anonymous user could not see the Mandala Home carousel images. Diagnosis: not an access problem. The carousel renders, but each image URL was absolute on the container's internal address over http (`ImageStyle::buildUrl()` takes host/scheme from the request as the container sees it; `reverse_proxy` is unset), so browsers on the https page block or cannot reach it. A VPN client can reach the internal address, which hides the bug in team testing. Fix: `CarouselBuilder` now emits root-relative URLs ([#306](https://github.com/uvalib/mandala-navina/pull/306)). Deploy `ec632f5e` succeeded 11:43 ET; dev-0 markup verified (12 slides, no internal addresses, image fetch 200). **#303 stays open until the reporter confirms in a browser** (Yuji is emailing her). The site-wide fix, Drupal reverse-proxy settings, is [#307](https://github.com/uvalib/mandala-navina/issues/307) and needs input from Dave.

## Not finished

- **Spike 11 `trid_i` docValues on dev-0 ([#291](https://github.com/uvalib/mandala-navina/issues/291)):** not checkable from here. The schema API is behind SSO on the dev replica; needs the deployed kmassets schema file from the Solr host or from Dave/Xiaoming.
- **Two deferred notes look resolved but were not changed:** `dev-0-code-config-delivery-rebuild-or-pipeline` (a webhook pipeline now deploys to dev-0) and `images-prod-packaging-monorepo-pass`. Add dated *Resolved* sections once confirmed.
- **Four owned notes not yet promoted** (`iiif-prefix-alignment-mandala-vs-canonical`, `migrate-drupal-noise-site-specific-dump`, `images-agent-name-paragraph-title-mapping`, `transcript-tier-analyzers-and-language-fields`): probably stale or done; check first.
- **Baseline triage of the ~110 unowned notes** (live / resolved / stale) not run.
- PRs #285 (Spike 11 solrconfig + join prototype), #302 and #308 (group-editing fix) were open and not part of this session's work.
