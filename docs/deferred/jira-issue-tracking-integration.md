# Jira issue-tracking integration

**Area:** process / project tooling / tracking
**Raised during:** PM session 2026-06-25 (documentation hosting & access-control discussion)
**Jira:** (this item bootstraps the project — add the key once the project exists)
**Priority:** Medium now, rising to High as the implementation phase deepens
**Start trigger:** Kick off **when Sprint 1 (Images pilot) closes** — i.e. at the Sprint 1 acceptance gate. Not before; Sprint 1 keeps using the git deferred-note workflow. (PM decision 2026-06-25.)
**Owner:** Yuji Shinozaki

## Context

Confluence and Jira Cloud are now available to the Mandala team (they were not
when the git/mkdocs interim workflow was set up). Of the two halves of the
documentation/tracking strategy, **Jira is the more urgent**: as the project
moves from spikes/planning into sustained implementation, ad-hoc deferred notes
and session logs stop being sufficient for tracking who-owns-what and what's
in-flight. The Confluence/docs-hosting half is comparatively long-run and is
parked as a low-priority spike — see
[Spike 9](../spikes/spike-09-docs-hosting-confluence.md).

The deferred-note format already anticipated this: every note carries a
`**Jira:** (add when available)` placeholder for backfill, and
[docs/deferred/README.md](README.md) states each file should map 1:1 to a ticket
once Jira exists.

## What needs deciding / doing

1. **Stand up the Jira project** — key, issue types, workflow. Decide how it
   relates (if at all) to any existing UVA Library Jira projects.
2. **Deferred notes → tickets (1:1).** Backfill the current ~14 open deferred
   notes as tickets and record each ticket key back in the note's `**Jira:**`
   header. Decide whether the markdown note or the Jira ticket is the source of
   truth going forward (recommend: ticket for status/assignment, markdown for the
   detailed technical context, cross-linked).
3. **Spikes ↔ Jira.** Decide whether spikes get tracked as Jira epics/tasks or
   stay git-only with the spikes-plan as the index.
4. **Define the working rhythm** — what gets a ticket vs. what stays a session
   log; how session-end deferred notes flow into Jira.
5. **Session logs stay in git** (not Jira) — they are narrative, not trackable
   work items.

## When to start

Sequenced **after Sprint 1 (Images pilot) completes** — start at the Sprint 1
acceptance gate so the first sprint isn't disrupted mid-flight. The
Confluence/docs-hosting half ([Spike 9](../spikes/spike-09-docs-hosting-confluence.md))
trails this further still; it can wait longer. When Sprint 1 closes, promote this
note to High and begin step 1 below.

## Why this is separable from the docs-hosting spike

Jira adoption delivers value immediately and independently: it does not depend on
solving the markdown → Confluence sync, the public/private repo split, or the git
submodule question. It can proceed now while the hosting spike waits.

## Related

- [Spike 9 — Documentation hosting & access control](../spikes/spike-09-docs-hosting-confluence.md)
- [docs/deferred/README.md](README.md) — deferred-note → ticket mapping convention
