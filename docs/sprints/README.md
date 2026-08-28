# Sprints

Execution units that turn the [roadmap](../roadmap.md) phases into time-boxed,
trackable work. Where the roadmap and ADRs decide *what* and *in what order*, a sprint
records *the concrete backlog being built now* — goal, scope boundary, task breakdown,
owners, and the acceptance criteria that close it.

Sprints do not make architectural decisions. When a sprint surfaces one, it goes to
`docs/adr/`; gaps go to `docs/deferred/`. A sprint links to the ADRs and audits it
executes against rather than restating them.

| # | Title | Phase | Status |
|---|-------|-------|--------|
| [Sprint 1](sprint-01-images-implementation.md) | Mandala Images implementation (pilot) | Roadmap Phase 1 | ◐ In progress |
| [Sprint 2](sprint-02-theme-images-ui-and-endpoint-access.md) | D11 base theme, Images interactive UI, other-asset-type groundwork | Roadmap Phase 2 setup | ◐ Not started |

**Status key:** ● Done · ◐ In progress · ○ Planned

---

Each sprint file follows this shape:

```
# Sprint N: Title
**Status / Phase / Dates / Lead / Mode**

## Goal            One sentence: what shipping this sprint proves.
## Scope boundary  In / out, inherited from the governing ADRs.
## Backlog         The task breakdown, in dependency order, with owners.
## Acceptance      The criteria that close the sprint (verification gates).
## References      ADRs, audits, spikes, and deferred items this executes.
```
