# Session Log: Docs Access Confluence Jira Sequencing

**Date:** 2026-06-25  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** Documentation access-control strategy set. Code stays public; internal docs move out of the public repo, authored as git-markdown and auto-published to a restricted Confluence space — captured as low-priority [Spike 9](../spikes/spike-09-docs-hosting-confluence.md). The more urgent, separable track — [Jira issue-tracking integration](../deferred/jira-issue-tracking-integration.md) (deferred notes → tickets 1:1) — is sequenced to start at the Sprint 1 close gate. ("Schedule" here means sequence, not calendar; time-boxed 2-week sprints begin after mob-coding completes.)

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-25 16:40

So a project management issue, outside of the normal development flow:  I would like to move our documentation and workproducts to a place which we have more control over who can read it.   Specifically the mkdocs documentation.   Currently it is published to github pages. I would like to change that page to be more public-facing project information and have a separate access-controlled web-presence for the rest.  We have access to Confluence and Jira cloud.  That seems like the likely future home.

---

## Claude — 2026-06-25 16:40

This is a planning/strategy question rather than a code task. Let me orient myself on the current documentation setup and what's already been noted about Confluence/Jira before advising.

---

## Claude — 2026-06-25 16:40

Memory says Confluence/Jira weren't available yet — but you're now saying you have access, so that's changed. Let me look at the current publish setup.

---

## Claude — 2026-06-25 16:42

Here's the lay of the land, and it changes the framing of your question in one important way.

## The critical finding: the repo itself is public

- `uvalib/mandala-navina` is a **public** repo, and the `uvalib` org is on the **free** plan.
- Every doc lives as markdown in `docs/` inside that public repo: **12 ADRs, 9 spikes, 17 deferred notes, 17 session logs**, plus planning/dev-notes/sprints.
- GitHub Pages just *renders* that same markdown via mkdocs.

So the access-control problem is bigger than the published site. **Changing the GitHub Pages target does not make the content private** — anyone can read the source markdown directly in the public repo. And because the org is on the free plan, GitHub's own "private Pages restricted to org members" feature isn't available to you (that's Enterprise Cloud).

That means real access control requires moving the *source* of the internal docs out of the public repo — not just re-pointing where it's published. Confluence is a perfectly good destination for that; the question is mostly about the authoring workflow.

## The workflow tension you're trading against

Your current setup is deliberately git-native: ADRs live beside code, sessions append to `docs/`, mkdocs renders it, and CLAUDE.md enshrines "one repo, one session." Confluence-as-authoring-home breaks that — markdown editing in a session is far better than editing in Confluence's UI, and Claude sessions lean on these files being local.

The clean resolution is to **keep authoring in git markdown and let Confluence be the access-controlled *rendering/reading* surface** (auto-published), rather than retyping content into Confluence. That preserves the dev workflow and gives the broader team an access-controlled web presence. Your existing deferred-note plan already anticipated this (deferred → Jira 1:1; ADRs/spikes → Confluence via automated publish; session logs stay in git).

Two genuine forks remain that change the architecture, so let me get your read before I sketch a concrete plan.

---

## Claude — 2026-06-25 16:46

Got it — **code stays public, internal docs stay git-markdown-authored and auto-publish to Confluence.** That pins down the architecture. Here's the recommended shape and the migration path, plus two caveats that matter before anyone starts moving things.

## Recommended architecture

**1. Public repo (`uvalib/mandala-navina`) — keeps code + a small curated public site**
- Code stays where it is.
- The `docs/` internal corpus moves out (see #2). In its place, a handful of hand-written, genuinely outward-facing pages: project overview, high-level architecture, status, links. This is what GitHub Pages serves going forward — your "public-facing project information."

**2. Private docs repo (`uvalib/mandala-navina-docs`, private) — the git-native authoring home**
- Holds ADRs, spikes, deferred notes, session logs, planning. The session/markdown workflow is preserved exactly; only the repo's *visibility* changes.
- Access-controlled at the GitHub layer (org members/collaborators).
- To keep "one repo, one session" ergonomics, mount it into the code repo as a **git submodule** (e.g. `internal-docs/`). People with access clone `--recurse-submodules` and Claude sessions see both trees; people without simply don't get the submodule. CLAUDE.md's session-startup paths get repointed.

**3. Confluence — the access-controlled *reading* surface**
- CI in the private docs repo renders markdown → a restricted Confluence space on each merge. This is what the broader audience reads — crucially including people who aren't GitHub collaborators (Carla, Dave, David, and external partners like Than/Andres). A private GitHub repo alone wouldn't reach them; Confluence does, with Atlassian Cloud auth + space restrictions.
- No second mkdocs/Pages site needed for internal docs — Confluence *is* the internal site.

**4. Jira — deferred notes → tickets (1:1)**, as your existing deferred-note format already anticipated with its `**Jira:**` placeholders.

## Two caveats to decide on first

**A. Git history is already public — and stays that way.** Moving `docs/` to a private repo going forward does *not* retroactively hide it. Every ADR, spike, and session log in the current public repo's history remains readable by anyone via past commits. For architecture docs and session logs that's likely acceptable, but if any of it is sensitive you'd need a history scrub (`git filter-repo`) or to treat the existing content as already-disclosed. Worth a conscious call, not an accident.

**B. The markdown→Confluence sync is the real unknown.** Tools exist (`mkdocs-with-confluence`, Confluence REST API + a storage-format converter, Foliant, etc.) but fidelity varies — admonitions, mkdocs `awesome-pages` nav, the git-revision dates, internal `[[links]]`, code fences. Per your own "spikes over engineering" rule, this should be a **light spike** proving one ADR and one session log round-trip cleanly *before* committing the architecture.

## Suggested sequencing

1. **Spike** the markdown→Confluence sync on 1–2 real docs (cheapest unknown to retire).
2. Stand up the restricted Confluence space + decide the Jira project/mapping for deferred notes.
3. Create the private docs repo, move `docs/` (preserving structure), wire it as a submodule, update CLAUDE.md paths.
4. Shrink the public GitHub Pages site to the curated public pages.
5. Decide caveat A (scrub history or accept).

---

I'd suggest capturing this as a **planning doc** in `docs/planning/` plus a **spike stub** for the Confluence sync (and I'll repoint the deferred→Jira note). I'd hold off on an ADR until the spike proves the sync — ADRs are for accepted decisions, and this is still a proposal.

Want me to write that planning doc + spike stub now? And I'll update my memory to record that Confluence/Jira are now available and this is the chosen direction.

---

## User — 2026-06-25 16:53

Let's schedule this as a low-priority spike.  Its really only important in the long-run, and maybe for precedent-setting for future projects.  The perhaps more urgent aspect is the integration with Jira.  As we get further into the implementation phase.  Jira-tracking will get more important.

---

## Claude — 2026-06-25 16:54

Let me look at the existing spike and deferred conventions so the new docs match house style.

---

## Claude — 2026-06-25 16:54

Conventions are clear. Let me write the Spike 9 doc and the Jira deferred note, then wire up the indexes. First, the spike-plan heading format:

---

## Claude — 2026-06-25 16:55

Now I have everything. Let me create the Spike 9 doc and the Jira deferred note.

---

## Claude — 2026-06-25 16:55

Now wire up the indexes and `.pages` files.

---

## Claude — 2026-06-25 16:56

Now the deferred README table and the spikes-plan.md entry.

---

## Claude — 2026-06-25 16:56

Now update the memory to reflect that Confluence/Jira are available and the chosen direction.

---

## Claude — 2026-06-25 16:57

Done. Here's what I recorded:

**Spike 9 (low priority, Pending)** — `docs/spikes/spike-09-docs-hosting-confluence.md`
- The full architecture (public GH Pages site + private docs repo as git submodule + auto-publish to a restricted Confluence space).
- Marked explicitly off the implementation critical path / precedent-setting.
- The two caveats baked into "What this does NOT establish": **git history is already public** (decide scrub vs. accept) and the markdown→Confluence sync fidelity is the unknown to retire first.
- Added to the spikes README table, `spikes/.pages`, and a matching section in `planning/spikes-plan.md`.

**Jira integration (Medium, rising)** — `docs/deferred/jira-issue-tracking-integration.md`
- Framed as the more urgent, *separable* track: stand up the project, backfill the ~14 open deferred notes as 1:1 tickets, decide source-of-truth (recommended ticket-for-status / markdown-for-context), and define the working rhythm.
- Explicitly notes it doesn't depend on solving the Confluence sync, so it can proceed now.
- Added to the deferred README table and `deferred/.pages`.

**Memory** — updated `project-confluence-jira` (and the index hook) to flip from "not yet available / deferred" to the now-available state and the chosen direction, so future sessions don't re-litigate it.

Two small notes:
- I made **Yuji** the lead on Spike 9 since it's your PM concern — say the word if it should sit with someone else.
- These are committed to the working tree but not staged/committed to git. Want me to commit them (on a branch, per the repo convention)? And for a planning session like this, CLAUDE.md's session-end ritual suggests running `scripts/save-session-log.py` — I can do that too if you'd like.

---

## User — 2026-06-25 17:00

Schedule the jira integration after the current sprint is done.   The confluence intergration can wait a little longer.

---

## Claude — 2026-06-25 17:01

No concrete sprint end date is recorded yet — let me confirm before I either set a real reminder or just encode the sequencing.

---

## Claude — 2026-06-25 17:01

No calendar dates are recorded for Sprint 1 — it's `◐ In progress` with tasks 1a.8/1a.9/1b.x still open, and no end date in the doc. So "after the current sprint is done" is a *condition*, not a date I can pin a cron/reminder to without inventing a timeframe. I'll encode the sequencing as a trigger in the docs (the natural place it'll surface is the Sprint 1 close ritual), and I can set a real calendar reminder if you give me a target completion date.

Let me record the trigger on the Jira note.

---

## Claude — 2026-06-25 17:02

Now reflect the sequencing in memory.

---

## Claude — 2026-06-25 17:02

Done. Since Sprint 1 has no recorded end date, I sequenced the work as a **condition-triggered** dependency rather than a calendar entry:

- **Jira note** (`docs/deferred/jira-issue-tracking-integration.md`) — added a **Start trigger** ("when Sprint 1 closes, at the acceptance gate") and a "When to start" section; priority now reads *Medium → High after Sprint 1*. Updated the deferred README cell to match.
- **Sprint 1 doc** — added an **"On close"** line right after the acceptance checklist that points to the Jira kickoff, so it surfaces at the natural close ritual and won't get forgotten.
- **Confluence (Spike 9)** — left as low priority and noted it trails the Jira track even further ("can wait longer").
- **Memory** — recorded the sequencing (Jira after Sprint 1; Confluence later).

One thing I deliberately didn't do: set an actual cron/cloud reminder, because that needs a real date and Sprint 1 doesn't have one — I'd have been inventing a timeframe. **If you give me a target completion date for Sprint 1** (or want it tied to a date once 1a.9/1b close), I can set a real `/schedule` reminder to kick off the Jira work then.

These are all uncommitted working-tree changes. Want me to commit this batch (Spike 9 + Jira note + index/sequencing edits) on a branch?

---

## User — 2026-06-25 20:07

We aren't actually doing calendar scheduling yet.  So "schedule" really means "sequence"

---

## Claude — 2026-06-25 20:07

Right — and that's exactly what landed: no cron or reminder was set, just a sequencing dependency (Jira gated on Sprint 1 close, Confluence trailing further). So nothing to undo; the docs already reflect "sequence," not "calendar." I'll drop the offer to set a date-based reminder.

Let me record that convention so I don't reach for calendar tooling again.

---

## Claude — 2026-06-25 20:07

Noted and saved. To recap where things stand:

- **Jira integration** — sequenced to start at the **Sprint 1 close gate** (trigger recorded on the deferred note + an "On close" pointer in the sprint doc). No date.
- **Confluence/docs-hosting (Spike 9)** — trails further, parked as low priority.
- **Convention saved** — "schedule" = sequence on this project; I won't reach for calendar/cron tooling unless you explicitly ask for a real date.

All still uncommitted in the working tree. Want me to commit this batch on a branch?

---

## User — 2026-06-25 20:08

We will start implement actual scheduling (time-boxing, 2-week sprints) after the mob-coding is completed.

---

## Claude — 2026-06-25 20:09

That's a useful forward marker — it sets the trigger for when "sequence" flips back to real calendar scheduling. Let me refine the memory so a future session has the concrete condition rather than a vague "yet."

---

## Claude — 2026-06-25 20:09

Captured. The memory now records both halves cleanly:

- **Current phase = mob-coding** → sequence by dependency/gate, no calendars.
- **After mob-coding completes** → time-boxed 2-week sprints, and calendar scheduling becomes real (so "schedule" can mean a date again).

Worth noting the alignment: both the **Jira integration** and the **switch to time-boxed sprints** are gated on roughly the same moment — the end of the mob-build pilot. So when mob-coding wraps, that's the natural point to (a) stand up Jira and (b) start planning in dated 2-week increments.

This is all in my session memory, not the repo. If you want the mob → 2-week-sprint cadence reflected in the project docs too (e.g. the roadmap or `sprints/README.md`, which currently only describe time-boxed sprints generically), I can add it — otherwise I'll leave the repo as is. And the Spike 9 / Jira-note / sequencing edits from earlier are still uncommitted; say the word and I'll commit them on a branch.

---

## User — 2026-06-25 20:11

leave out documenting the Sprint scheduling for now.  Go ahead and check-in and push the rest and start closing out this session.

---

## Claude — 2026-06-25 20:11

All changes are the docs work (the sprint-scheduling cadence was only ever in memory, never added to repo docs — good). On `main`, so I'll branch first, commit, generate the session log, then push and open a PR.

---

## Claude — 2026-06-25 20:11

Now generate the session log per the session-end ritual, then commit it.

---
