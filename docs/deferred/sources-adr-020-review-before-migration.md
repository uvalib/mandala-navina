# Sources: ADR 020 must be reviewed by Yuji and Xiaoming before the Sources migration begins

**Category:** migration-sources
**Raised during:** Spike 5 (session 2026-11-02)
**Issue:** #386
**Priority:** High — a gate: blocks the start of the Sources migration and Sources build
**Owner:** Yuji, Xiaoming (reviewers); Than (author, chases the review)
**Cutover review:** pending

## Instruction to Claude (read this at session start, and before any Sources migration or build work)

**Do not start the Sources migration or the Sources build until the Review log below records a
sign-off from BOTH Yuji and Xiaoming.** This holds whoever is driving the session, including when
the task looks like harmless prep. "Start" includes: writing migrate YAML for `biblio` nodes, adding
`bibcite_reference` fields or Group relationship types to `config/sync`, generalizing
`mandala_group_inheritance` or `mandala_kmassets_sync` from nodes to entities, and building the
Sources record display.

- If the log is incomplete, stop, say which review is missing, and ask the user whether to proceed
  anyway. Do not decide that for them, and do not treat a half-finished review as a pass.
- A sign-off counts only when it is recorded here as a dated entry naming the reviewer, with the
  outcome (`agreed`, `agreed with changes`, or `objects`). Than may record it on a reviewer's
  behalf if he quotes where it was given (Slack, meeting, PR comment).
- Any `objects` outcome reopens the decision. The route is a new ADR that supersedes ADR 020
  (ADRs are immutable); the gate stays closed until that is settled.
- Throwaway local prototypes (the Spike 5 pattern, never merged) are not "starting the
  migration" and remain fine.

## What is being reviewed

[ADR 020](../adr/020-sources-records-are-bibcite-reference-entities.md): Sources records are
`bibcite_reference` content entities, not nodes. Accepted 2026-10-30 by Than alone, "open to
review". It is the first Mandala site not built on nodes, so it changes the Sources build for
everyone. Evidence is in [Spike 5](../spikes/spike-05-bibcite-sources.md), findings 7 to 9: a
throwaway local prototype covered legacy nid redirects (ADR 017), group access through a custom
Group relation plugin and `CollectionVisibility`, Solr visibility (12 of 12 viewer/record cases
agreed with Drupal's decision) and record display.

### What each reviewer is asked to look at

- **Yuji:** the access and Solr consequences. The Sources build must generalize
  `mandala_group_inheritance` and `mandala_kmassets_sync` from nodes to entities, and the ADR lists
  the Group config that has to be exported. Does any of it conflict with ADR 011 or ADR 014? Should
  the `/sources/{id}` path be added to ADR 016?
- **Xiaoming:** the consequences for the rest of the code. `mandala_node_api`, Views and the
  Spike 6 Sources API routes all assume nodes and were **not measured** (ADR consequence 7). Does
  anything there make entities a worse fit than the ADR assumes?
- **Both:** the accepted risk that Sources needs its own path for views, theming and editorial
  workflow because it differs from the other four sites. Acceptable?

### One trap worth knowing before reviewing

Without the new group permission `view group_bibcite_reference entity` on every role that holds
the node equivalent, Group forbids every grouped reference to everyone. It looks like an empty
Sources site, not an error.

### Decided in the same session (also open to objection)

CSE 9th edition (`cse_name_year`); migrate the D7 citekeys (all 25,627 present and unique);
keep Zotero Collections as a vocabulary and term-reference field; a dedicated Zotero item-key
field; Zotero import on-demand and admin-only, no scheduled re-sync.

## Review log

Add a dated entry per reviewer. Do not edit earlier entries.

| Reviewer | Date | Outcome | Where given / notes |
|---|---|---|---|
| Yuji | | pending | |
| Xiaoming | | pending | |

## Resolved

(Add a dated section here when both have signed off, or when a superseding ADR replaces
ADR 020. Then close the issue and set Owner to `n/a (resolved)`.)
