# Session Workflow & Rituals

How we run a working session so that every team member's Claude instance starts
from the same shared context, regardless of who drove the previous session.

> The canonical short form of these rituals lives in [`CLAUDE.md`](https://github.com/uvalib/mandala-navina/blob/main/CLAUDE.md).
> This page is the expanded, human-facing version.

## Ground rules

- **One repo, one session.** Always open Claude Code from the monorepo root.
  Never work on Mandala from a legacy repo directory.
- **Spikes over engineering.** Prove unknowns with the lightest possible demo
  before building production code. See [Spikes](../spikes/README.md).
- **ADRs are immutable.** Once accepted, don't edit an ADR — write a new one that
  supersedes it. See [Architecture Decisions](../adr/README.md).

## Session start

At the start of every session, orient before doing any work by reading:

1. `docs/adr/README.md` — index of all architectural decisions; read any ADR
   relevant to the task.
2. `docs/spikes/README.md` — spike status; read the doc for any spike being
   continued or referenced.
3. `docs/deferred/README.md` — known gaps and deferred work.

## Session end

Before closing a significant session:

1. **Flush decisions and findings** to their homes:
   - decisions → `docs/adr/`
   - spike findings → `docs/spikes/`
   - deferred notes → `docs/deferred/`
2. **Update the `.pages` file** for every directory you added a doc to
   (`docs/adr/.pages`, `docs/spikes/.pages`, `docs/deferred/.pages`,
   `docs/dev-notes/.pages`). New files are invisible in mkdocs until listed there.
   `docs/session-logs/.pages` uses `...` and self-updates.
3. **Save a session log** for long planning or spike sessions:
   ```bash
   scripts/save-session-log.py
   ```

## Why this matters

Development is driven collaboratively — team members take turns leading sessions.
The rituals above are what make that hand-off lossless: the docs *are* the shared
memory between sessions and between people.
