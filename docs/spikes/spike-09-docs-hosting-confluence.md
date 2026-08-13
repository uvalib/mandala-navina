# Spike 9: Documentation Hosting & Access Control (mkdocs → public site + Confluence)
**Status:** Pending — **partially superseded 2026-08-13** (see the amendment at the end)
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
2. **Private docs repo(s)** — the git-native authoring home. The session + markdown +
   mkdocs workflow is preserved exactly; only repo *visibility* changes. Mounted into the
   code repo as a **git submodule** (e.g. `internal-docs/`) so "one repo, one session"
   ergonomics survive — collaborators clone `--recurse-submodules`; others simply don't
   get the submodule.
   **⚠ Amended 2026-08-13:** this was written as a single `uvalib/mandala-navina-docs`
   holding the whole internal corpus. **Two private repos now exist**
   (`uvalib/mandala-legacy-docs`, `uvalib/mandala-navina-docs`) scoped to genuinely
   sensitive material only — not the corpus, and with no submodule. See the amendment at
   the end before designing against this item.
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

---

## Amendment — 2026-08-13

Part of this spike was overtaken by events. A live case (a high-severity access-control
finding that could not go in a public repo) arrived while the spike was still unscheduled,
and there was nowhere to put it — so the decision was taken early and minimally.

### What now exists

Two **private** repos, both in `uvalib`, each carrying an **identical `CONVENTION.md`**:

| Repo | Holds |
|---|---|
| `uvalib/mandala-legacy-docs` | material whose fix serves the **legacy D7 stack** |
| `uvalib/mandala-navina-docs` | material whose fix serves the **D11 rebuild** |

The public-facing summary is [docs/non-public-documentation.md](../non-public-documentation.md).

### How this differs from what the spike proposed

| Spike 9 as written | What was built |
|---|---|
| **One** repo, `mandala-navina-docs` | **Two**, mirroring the legacy/rebuild split |
| Holds the whole internal corpus (ADRs, spikes, deferred, session logs, planning) | Holds **only** material whose *source* must not be public. The corpus stays public by design |
| Mounted as a git submodule | **No submodule.** Plain repos, cloned separately |
| Paired with Confluence sync | **No sync.** Not built |

The scope change is the substantive one. Spike 9 framed the private repo as *"the internal
corpus moves out of the public repo"*. In practice the corpus is better off public —
over-classifying hides work from the team and from collaborators who are not GitHub org
members — and only a narrow class of content genuinely cannot be published. The two repos
are scoped to that narrow class.

The two-repo shape came from a boundary the single-repo design did not account for:
**sensitivity cuts across the legacy/rebuild split.** A finding can be *about* the legacy
stack, *found during* rebuild work, and *affect* both. The tie-breaker is **file by where
the fix lands, not where the problem was found**.

Both repos are in `uvalib` although the legacy D7 **code** remains in `shanti-uva`.
Documentation ownership follows the Library, not the code's current host. The code stays
put because **legacy is still in production** and moving it would churn live
infrastructure; it migrates to `uvalib` **after cutover**. Docs could move immediately
because they have no such coupling.

### What is still open — this spike remains Pending

Everything else, unchanged:

- **Submodule ergonomics** — untested; the two repos are cloned separately today.
- **Confluence sync fidelity** — the original core question. Untouched. Still the way to
  reach PM, directors, and external partners who are not GitHub collaborators; two private
  GitHub repos do not reach them.
- **Public-vs-internal split for the existing corpus** — now leaning strongly toward
  "the corpus stays public", which narrows this considerably but does not close it.
- **The public site itself** — a curated GitHub Pages front page is still unbuilt.
- **Git history** — unchanged and still public; the note above still applies.

Nothing in this amendment resolves the Confluence half. It only records that the
*access-controlled storage* question was answered early, under pressure, in a smaller
shape than proposed.
