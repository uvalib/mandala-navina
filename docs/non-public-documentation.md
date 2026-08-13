# Non-public documentation

**Almost everything belongs in this repo.** ADRs, spikes, deferred notes, session logs and
planning are public by design, and that is deliberate — over-classifying hides work from
the team and from collaborators who are not GitHub org members.

A small amount of material cannot live here, because **this repository is public**. For
that, Mandala keeps two private documentation repositories.

## The test

> Would a stranger reading this file gain a working recipe against a live, unfixed system,
> or learn something we are obliged not to publish?

**Yes** → it goes in a private docs repo. **No** → it belongs here, where it is more useful.

Typical private material: unfixed vulnerabilities and access-control gaps; production
incident postmortems that name live weaknesses; credential inventories (never credentials
themselves — those belong in a secret store); infrastructure detail that is only safe
because it is obscure.

## Where it lives

| Repo | Holds |
|---|---|
| **`uvalib/mandala-legacy-docs`** (private) | material whose fix serves the **legacy D7 stack** |
| **`uvalib/mandala-navina-docs`** (private) | material whose fix serves the **D11 rebuild** |

Both contain an identical **`CONVENTION.md`** — the full ruleset, and the thing to read
before filing anything. It covers the routing rule (file by *where the fix lands*, not
where the problem was found), naming, indexing, and how to close items out.

**Access:** ask Yuji Shinozaki.

### Why two, and why both in `uvalib`

Sensitivity does not respect the legacy/rebuild boundary — a finding can be *about* the
legacy stack, *found during* rebuild work, and *affect* both. Mirroring the split we
already have, plus one tie-breaker rule, beats arguing case by case or scattering notes
into whichever infrastructure repo looks topically adjacent.

Both docs repos are in `uvalib` even though the legacy D7 **source code** is still in
`shanti-uva`. Documentation ownership follows the Library, not the code's current host.
The legacy code stays where it is because **legacy is still in production** and relocating
those repos would churn the infrastructure serving it; it moves to `uvalib` **after the
cutover**, when D7 is genuinely legacy. Documentation was able to move immediately because
it has none of that coupling — nothing builds, deploys, or references a docs repo.

## The rule that affects *this* repo

**Public documents may say that a problem exists and who to ask. They must never say what
the problem is.**

If you are writing here about something tracked privately, name the area and point at a
person — no hostnames, no reproduction steps, no measurements that amount to one. Holding
something back means policing *everything that references it*, not just the one file: a
correction table in a spike write-up will disclose it just as effectively as the note
itself.

When an item is fixed and no longer sensitive, its write-up should move here.

## Status

This is deliberately minimal — two repos and a shared convention, created 2026-08-13 so
that sensitive findings have a durable home instead of living untracked on one laptop.

Submodule wiring, Confluence sync as an access-controlled *reading* surface, and any
migration of the existing public corpus remain open and unscheduled — see
[Spike 9](spikes/spike-09-docs-hosting-confluence.md).
