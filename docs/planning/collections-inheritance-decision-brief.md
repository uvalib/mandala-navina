# Decision Brief: Group Collections Inheritance Model

**Status:** ✅ RESOLVED (2026-06-18) — superseded by [ADR 011](../adr/011-group-collections-inheritance.md). See **Resolution** section at the bottom.
**Decision owner:** Than Grove (Spike 3 lead), with Yuji Shinozaki, Xiaoming Wang
**Outcome:** ADR 011 written and accepted; supersedes the "recommended" status of the deferred notes
**Inputs:** [Spike 3](../spikes/spike-03-group-collections.md) (now Proven),
[group-subgroup-nesting-approach.md](../deferred/group-subgroup-nesting-approach.md) (superseded),
[group-access-inheritance-subcollections.md](../deferred/group-access-inheritance-subcollections.md) (superseded)

> **Why this is first.** The Images content model (Sprint 1 Step 1a) is built, but
> images are organized and **access-gated** by collections. The reason Images chose
> Paragraphs was access inheritance (audit debt item #2) — and paragraph access only
> holds if the *parent image's* access is correct, which derives from **Group
> collection visibility**. So the 1a.7 image migration's access story depends on this
> model. Resolving it unblocks collections modeling and de-risks 1a.7.

---

## The questions to resolve (in order)

### Q1 — Inheritance implementation (primary)
How do we implement collection → subcollection **inheritance** on Group 3.x?
The requirements are settled (below); this is purely *how*.

**Standing recommendation: Option D** (entity-reference nesting + custom hooks).
The meeting's job is to **ratify, amend, or reject** — not re-derive.

| Option | Membership inheritance | Visibility inheritance | D11 status | Verdict |
|---|---|---|---|---|
| A — entity-ref field only | ✗ | ✗ | proven (Spike 3) | insufficient alone |
| B — `ggroup` | ✓ | ✗ (needs custom) | **not D11-ready** | blocked |
| C — `subgroup` | ✓ | ✗ (needs custom) | D11 unconfirmed | risky |
| **D — entity-ref + custom hooks** | ✓ | ✓ | no dep risk | **recommended (~2–3 d)** |
| E — ggroup/subgroup + custom visibility hook | ✓ | ✓ | dep-blocked | revisit when ggroup stable |

Note: **no Group option provides visibility inheritance out of the box** — visibility
requires custom logic under *every* path, which is the main argument for D.

### Q2 — Sequencing vs. the completed 1a image model
Do we, now:
- **(a)** add the *structural* Group types to the CMI baseline immediately — `collection`
  + `subcollection` group types, a `group_node:shanti_image` relation, and the
  `parent_collection` reference field — **deferring inheritance behavior**; or
- **(b)** hold *all* collections work for Step 1b?

(a) gets the skeleton committed alongside `shanti_image` without pre-judging Q1's
contrib decision; (b) preserves ADR 009's "win decoupled from auth." Pick one.

### Q3 — Phasing consistency
The deferred notes say "before **Phase 3**"; Sprint 1 task **1b.2** puts OG→Group in
**Step 1b**. Reconcile: is the OG→Group *membership migration* (1b.2) separable from the
*inheritance-behavior* implementation (Phase 3)? State which phase owns which.

---

## Confirmed requirements (settled 2026-06-12 — not reopening)
Match D7 `og_subgroups` behaviour, one level of nesting only (prod: 55 collections /
116 subcollections):
1. **Visibility inheritance** — private parent ⇒ subcollections private by default; a
   subcollection may independently override to public.
2. **Membership inheritance** — a subcollection inherits all parent members; additional
   subcollection-specific members may be added.

---

## What "resolved" looks like (exit criteria for this agenda item)
- [ ] Q1: an option (or hybrid) chosen; owner assigned to **write the ADR**.
- [ ] Q2: sequencing decided — if (a), Yuji/Claude can model the Group types as config in
      this repo right after the meeting.
- [ ] Q3: phase ownership stated (which phase implements inheritance vs. runs 1b.2).
- [ ] If Option D: the **membership-sync edge cases** scoped into a task (notably: member
      removed from collection but added *directly* to a subcollection must not be removed;
      `visibility_overridden` flag so parent changes don't stomp independent settings).

## Pre-meeting prep (5 min, optional)
- Re-check **`ggroup` / `subgroup` D11 release status** — the "not D11-ready / unconfirmed"
  findings date from 2026-06-12 and could have moved; if `ggroup` is now stable on D11,
  Option E re-enters contention.

---

## Resolution (2026-06-18)

Resolved in working session with Than Grove, Yuji Shinozaki, and Xiaoming Wang.
Outcome recorded as [**ADR 011 — Group collections inheritance**](../adr/011-group-collections-inheritance.md).

- **Q1 (inheritance approach):** Option D ratified — entity-reference nesting
  plus a custom module providing visibility and membership inheritance via
  Group's lifecycle hooks. No contrib subgroup dependency adopted for MVP;
  Option E remains revisitable later if `ggroup` matures on D11.
- **Q2 (sequencing):** All collections work — both structural CMI config and
  the inheritance module — held for **Step 1b**. No `collection` /
  `subcollection` group config lands in the Sprint 1 Step 1a baseline.
  Rationale: Images audit explicitly puts OG-related fields out of scope for
  Step 1a, and task 1a.7 (the image migration) doesn't touch OG/Group fields.
- **Q3 (phase ownership):** Inheritance hooks land **with 1b.2 in Sprint 1
  Step 1b**, not deferred to Phase 3. Rationale: Sprint 1's security acceptance
  criterion cannot honestly pass for nested-collection content without
  visibility inheritance in place. The "before Phase 3" wording in the
  superseded deferred notes was defensive against contrib-readiness risk,
  which Option D eliminates.

See ADR 011 for full decision text, edge-case handling
(`visibility_overridden` flag, sub-only member retention), and the closeouts
that flow from it.
