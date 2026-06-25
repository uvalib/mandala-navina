# Spike 9: Documentation Hosting & Access Control (mkdocs → public site + Confluence)
**Status:** Pending
**Date:** (not yet scheduled)
**Branch/commit:** —
**Priority:** Low — long-run / precedent-setting. Not on the implementation critical path.

## Theory

We can split the documentation surface in two without abandoning the git-native,
session-driven authoring workflow:

1. A **small, curated public-facing site** on GitHub Pages (project overview,
   high-level architecture, status, links).
2. An **access-controlled internal corpus** (ADRs, spikes, deferred notes,
   session logs, planning) whose markdown is authored in git as today but
   **auto-published to a restricted Confluence space** for the broader audience.

…and that the markdown → Confluence sync can be made faithful enough to be worth
automating rather than re-authoring content by hand.

## Background

The motivation is a project-management one, not a technical one: we want more
control over who can read the internal workproducts, while keeping a genuinely
public front page for the project.

The current state that forces the design:

- **The repo is public.** `uvalib/mandala-navina` is a public repo on a **free**
  org plan. Every doc lives as markdown under `docs/` — 12 ADRs, 9 spikes,
  17 deferred notes, 17 session logs, plus planning/dev-notes/sprints — and is
  readable directly in the repo regardless of where GitHub Pages publishes.
- **GitHub Pages only *renders* that markdown.** Re-pointing or restricting the
  published site does **not** make the content private while the source sits in
  a public repo.
- **GitHub's own private Pages (org-member-restricted) is not available** — that
  is an Enterprise Cloud feature; `uvalib` is on the free plan.

Decisions already taken (PM session 2026-06-25):

- **Application code stays public.** So the internal docs' *source* must move out
  of the public repo to be access-controlled — not just the published output.
- **Authoring stays git-markdown**, auto-published to Confluence. We do not move
  authoring into Confluence's editor; that would break the session-driven,
  docs-beside-code workflow this repo is built around.

This supersedes the earlier "defer until Confluence/Jira available" posture —
they are now available. The Jira half (deferred notes → tickets) is tracked
separately and is the more urgent track; see
[docs/deferred/jira-issue-tracking-integration.md](../deferred/jira-issue-tracking-integration.md).

## Proposed architecture

1. **Public repo (`uvalib/mandala-navina`)** — keeps code + a handful of
   hand-written, outward-facing pages served by GitHub Pages. This becomes the
   "public-facing project information" site; the internal corpus leaves it.
2. **Private docs repo (`uvalib/mandala-navina-docs`, private)** — the git-native
   authoring home for ADRs/spikes/deferred/session-logs/planning. The session +
   markdown + mkdocs workflow is preserved exactly; only repo *visibility*
   changes. Mounted into the code repo as a **git submodule** (e.g.
   `internal-docs/`) so "one repo, one session" ergonomics survive — collaborators
   clone `--recurse-submodules`; others simply don't get the submodule.
3. **Confluence (restricted space)** — CI in the private docs repo renders
   markdown → Confluence on merge. This is the access-controlled *reading*
   surface, and crucially reaches people who are not GitHub collaborators
   (PM, directors, external partners such as Than/Andres). A private GitHub repo
   alone would not reach them.
4. **Jira** — deferred notes → tickets (1:1). Tracked separately (see above).

## Work (when scheduled)

The cheapest unknown to retire first is the **sync fidelity**, so the spike is
mostly that:

1. Pick one ADR and one session log as test inputs (they exercise the hard cases:
   admonitions, `pymdownx.superfences` code fences, awesome-pages nav,
   git-revision dates, internal `[[wiki-style]]` links, tables).
2. Evaluate a markdown → Confluence path. Candidates to try, cheapest first:
   - `mkdocs-with-confluence` (publishes from an existing mkdocs build)
   - Confluence Cloud REST API + a markdown→storage-format converter
   - Foliant / `md_to_conf` style converters
3. Round-trip the two test docs into a throwaway/restricted Confluence space and
   judge fidelity by eye.
4. Prove the git-submodule ergonomics on a throwaway private repo (clone,
   session, commit-in-both-repos flow) — confirm it doesn't unacceptably degrade
   the workflow.

## Pass Criteria

- A merge to the (test) private docs repo publishes a recognizably faithful
  page into a restricted Confluence space, unattended.
- Admonitions, code fences, and tables survive; internal links resolve or
  degrade gracefully.
- Submodule flow is tolerable for day-to-day sessions.

## Fail Criteria and Response

| Finding | Response |
|---|---|
| No sync tool renders mkdocs features faithfully | Reduce scope: publish a flattened HTML/PDF export to Confluence as attachments, or accept lossy conversion for reading-only |
| Confluence REST API auth/permissions too fiddly for CI | Manual periodic publish instead of per-merge; or host a private mkdocs site elsewhere (e.g. behind UVA SSO) instead of Confluence |
| Submodule workflow too painful | Use a documented sibling-clone convention instead of a submodule |

## What this does NOT establish (open questions to settle when scheduled)

- **Git history is already public and stays public.** Moving `docs/` to a private
  repo does *not* retroactively hide it — every ADR/spike/session log in the
  current public repo's history remains readable via past commits. Decide
  consciously: scrub history (`git filter-repo`) or accept the existing corpus as
  already-disclosed. For architecture docs and session logs this is likely
  acceptable, but it must be a decision, not an accident.
- What exactly belongs on the **public** page vs. the internal corpus.
- Confluence space structure and permission model (space-level vs. page
  restrictions).

## Deferred notes

- [docs/deferred/jira-issue-tracking-integration.md](../deferred/jira-issue-tracking-integration.md)
