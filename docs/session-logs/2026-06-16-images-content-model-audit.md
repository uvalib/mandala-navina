# Session Log: Images Content Model Audit

**Date:** 2026-06-16  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** [Images Content Model Audit](../planning/images-content-model-audit.md) and [ADR 010](../adr/010-adr-008-scope-clarification.md). Reconciled `main` (merged stale divergence); audited the D7 `shanti_images` model; ratified ADR 010 (scope of "migrate, not improve"); decided Images satellites → Paragraphs and `external_classification_scheme` → taxonomy.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-16 18:09

are we ready to talk about the mandala-image implementation?

---

## Claude — 2026-06-16 18:09

Let me orient myself per the session startup ritual before we dig in.

---

## Claude — 2026-06-16 18:09

Let me check the migration sequencing decision (Images is the pilot) and look for any existing image-specific docs.

---

## Claude — 2026-06-16 18:10

Oriented. Here's where the Images pilot actually stands.

## Short answer: yes to *talk*, with two things to reconcile first

Per **ADR 009**, Images is deliberately the **Phase 1 mob-build pilot** — "the spine" — split into:
- **Step 1a (public plumbing):** consolidation + KMaps + Solr sync + retrieval, end-to-end on the public subset
- **Step 1b (auth increment):** wire into the *existing* proxy-auth contract (no redesign, per ADR 004)

The two foundations the pilot rides on are both **proven**: Spike 1 (KMaps field type on D11 ●) and Spike 2 (Solr read-only ●). So the design conversation is well-supported.

## Two things worth settling before we go deep

**1. There's a sequencing conflict between two docs.**
`critical-path.md` (June 2026) still places Images at **Phase 4b**, *gated behind* Phase 3 Group/Collections, and tags it "IIIF integration." ADR 009 (2026-06-15, newer and accepted) overrides that — Images goes *first* as the pilot, with collections deferred to the Phase 2 fork. The critical-path doc predates the ADR and hasn't been reconciled. We should be explicit that **ADR 009 wins** and decide whether to update critical-path.md, or it'll keep misleading whoever reads it next.

**2. The Images-specific pre-work audits aren't recorded as done.** The critical-path audit table lists items that gate this work — most relevant to Images:
- **KMaps API current contract** (gates the widget port)
- **Solr `kmassets` schema field names** (gates Solr integration) — though we've clearly already been poking at this index (I have notes on the `language_field`/`names_txt` search pitfalls)
- Plus Images-specific unknowns *not* in any spike yet: the `shanti_images` custom table → D11 migration, IIIF, and the Images JSON/AJAX API endpoints (`/api/json/{nid}`, `/api/ajax/{nid}`)

## So — what do you want to focus on?

---

## Claude — 2026-06-16 18:22

Yes. Here's the path I'd take.

## Recommended path: a thin audit pass, then scope Step 1a — don't try to plan the whole pilot yet

The instinct to plan the full pilot (1a+1b) is tempting, but ADR 009's whole point is that 1a is the *early demonstrable win decoupled from auth risk*. Planning 1b now would pull auth complexity into a conversation that should stay simple. So I'd keep auth out of scope until 1a is real.

But I would **not** jump straight into 1a design either, because two of the three Images unknowns aren't proven — and they're cheap to check. So:

**Step 1 — One short audit pass (this session or a probe).** Three questions, all low-effort, all gating:
1. **`shanti_images` D7 table shape** — what fields actually exist on an Image asset? This defines the D11 content type. I can read the legacy source directly (`/Users/ys2n/Code/mandala/mandala-drupal/mandala-scripts/` and the D7 module). This is the biggest real unknown.
2. **`kmassets` field mapping for Images** — we've already hit this index (I have notes on the `language_field` and `names_txt`/string-field pitfalls), so this is mostly confirming, not discovering.
3. **Images JSON/AJAX API response shape** — defer. That's Phase 5 per both docs; only matters once a content type exists.

So really only **#1 is new work.**

**Step 2 — Scope Step 1a against what we find.** Content type + KMaps field instances + Solr retrieval path, public subset only. That's the mob-build target.

**Step 3 — Housekeeping (cheap, do it while we're here).** We're already on `docs/migration-roadmap`. Add a one-line note to `critical-path.md` that **ADR 009 supersedes its Phase 4b placement of Images** so the next reader isn't misled. Two-minute fix, prevents a real trap.

## Why this order
The `shanti_images` table is the one thing that genuinely shapes the 1a design and that we *haven't* looked at. Everything else is either proven (KMaps field, Solr read) or correctly deferred (auth, APIs). Audit the one unknown, then design on solid ground — exactly the "spikes over engineering" practice in CLAUDE.md.

**Want me to start by reading the `shanti_images` D7 module + schema** so we can scope the D11 content type from real field data? That's the highest-leverage next move.

---

## User — 2026-06-16 18:25

could you check the existing branches for updates that might change this assessment

---

## Claude — 2026-06-16 18:25

Found something. `origin/main` moved — there's a merged PR #3 from this very branch. Let me see what's now on main that isn't on our local branch.

---

## Claude — 2026-06-16 18:26

Let me verify whether any of the files relevant to my Images assessment (ADRs, critical-path, spikes) differ across branches.

---

## Claude — 2026-06-16 18:26

The only assessment-relevant file that differs is `docs/spikes/README.md`. Let me see how.

---

## Claude — 2026-06-16 18:27

Checked. Here's what the branches reveal — and the short version is **my Images assessment stands, but there's a branch-hygiene problem worth fixing before any Images track starts.**

## Nothing on any branch changes the Images assessment
- There are **no Images-related files anywhere** — not on our branch, not on `origin/main`. No implementation work exists yet.
- The files my assessment rests on are **byte-identical across branches**: ADR 009, `critical-path.md`, ADR 008, and Spikes 1, 2, 3. So the doc-conflict and the `shanti_images` audit recommendation are unchanged.
- Spikes **1 and 2 — the foundations Images rides on — read "Proven" on both branches.** Readiness for the pilot is intact.

## But: our local branch has diverged from `origin/main`, and main is currently stale
The repo is in an odd state. `origin/main` absorbed earlier versions of this branch via merged PRs #2 and #3, but our local `docs/migration-roadmap` was then rebuilt with newer work. Result: **7 ahead / 2 behind**, and the "behind" commits are a *regressed* older copy of the same docs. Concretely, `origin/main` right now is missing things that exist on our branch:

| On our branch (`aebb00a`) | On `origin/main` (stale) |
|---|---|
| Spike status glyph system (●◐○) | plain text ("Proven"/"Pending") |
| **Spike 8: "Partial — Part A proven"** + full findings + credential strategy | **Spike 8: "Pending"**, findings stripped |
| Spike 3: "◐ Partial" | Spike 3: "Pending" |
| `docs/deferred/reindeer-x-aws-credential-strategy.md` (a HIGH-priority deferred item) | **absent** |
| Spike 8 session logs | absent |

## Why this matters for Images
CLAUDE.md's whole premise is "one repo, one shared context — everyone's Claude starts from the same baseline." Right now **`main` is the stale one.** If someone cuts a new Images work branch off `main` (the normal move), they'd inherit:
- Spike 8 shown as untouched "Pending" — losing the reindeer_x file-watcher + AWS credential strategy, which is exactly the **Solr-sync ingest path the Images pilot's Step 1a depends on** ("prove KMaps + Solr sync end-to-end").
- The HIGH-priority credential-strategy deferred item silently gone from the index.

So before we start Images: **this branch's newer state needs to land on `main`** (merge/PR), or main needs reconciling. Otherwise the pilot starts from a baseline that's missing the sync groundwork it builds on.

Want me to dig into *why* the histories diverged (looks like a rebase/force-push after PR #3 merged) and lay out the cleanest way to get `main` current — or is reconciling that a you-and-Xiaoming call I should just flag and leave alone?

---

## User — 2026-06-16 18:30

I know why. Please go ahead and merge everything into main, and we should progress from there.   Are there any logical conflicts with doing that?

---

## Claude — 2026-06-16 18:30

Before touching `main`, let me check two things: whether main has any *unique* content we'd lose, and whether the merge will conflict textually.

---

## Claude — 2026-06-16 18:31

No conflicts, and nothing to lose. The checks are unambiguous:

- **No files exist only on `main`** — nothing unique there.
- **`origin/main`'s tree is identical to the merge-base** (`git diff bb89dfa origin/main` is empty) — its 2 extra commits are the PR-merge commits but they net to **zero content change**. main is effectively sitting at the old baseline.
- **Merge-tree dry run produced no conflict markers.**

So our branch is a clean content **superset** of main. Merging is safe — no textual *or* logical conflicts. Proceeding.

---

## Claude — 2026-06-16 18:31

Local main is now at `origin/main` (`dbd6aaf`). Now merge the newer branch work in.

---

## Claude — 2026-06-16 18:31

Merge is clean — `main`'s tree now exactly matches the branch (empty diff confirms it). Pushing to `origin/main` so the shared baseline is current.

---

## Claude — 2026-06-16 18:32

Done. `main` is now current.

## Summary
- **`origin/main`: `dbd6aaf` → `caff7e3`** — now contains everything from the branch.
- **No conflicts, nothing lost** — confirmed before merging: main had zero unique content (its 2 PR-merge commits netted to no file changes), and the merge tree matched the branch exactly.
- What main was missing and now has: spike status glyph system, **Spike 8 Part A proven** (reindeer_x file watcher), the **reindeer_x AWS credential strategy** deferred note, and the session logs.

To answer your direct question: **no, there were no logical conflicts** — main was sitting at the old baseline, so this was purely additive.

## Where this leaves the Images pilot
The team baseline is no longer stale, so a new Images track cutting off `main` now inherits the correct Spike 8 sync groundwork. Two housekeeping notes:
- The `docs/migration-roadmap` branch is now fully merged — it can be retired whenever you like (I left it alone rather than deleting a shared remote branch unprompted).
- Per ADR 009, my recommended first move on Images still stands: **read the `shanti_images` D7 module + schema** to scope the D11 content type from real field data — it's the one genuinely unproven unknown for Step 1a.

Want me to start on that `shanti_images` audit now?

---

## User — 2026-06-16 18:32

Could you make sure that the migration-roadmap branch is cleaned up and deleted

---

## Claude — 2026-06-16 18:32

Cleaned up. The `docs/migration-roadmap` branch is gone — verified fully merged into `main` first (no unmerged commits), then deleted both local and remote.

State now:
- On `main`, which is current with `origin/main`.
- Only `main` remains (plus the `origin/HEAD` → `main` pointer).

We're on a clean baseline. Ready to start the `shanti_images` D7 audit whenever you want to kick off the Images pilot.

---

## User — 2026-06-16 18:33

Start the shanti_images D7 audit

---

## Claude — 2026-06-16 18:33

I'll set up a work branch first (per the one-repo workflow — don't work on `main`), then dig into the legacy source.

---

## Claude — 2026-06-16 18:33

Branch created. The module isn't where I expected — let me explore the legacy source structure to find it.

---

## Claude — 2026-06-16 18:33

The legacy source moved to `/Users/ys2n/Code/mandala-legacy` (my memory had the old path). Let me locate `shanti_images` there.

---

## Claude — 2026-06-16 18:34

Found it. Let me read the core module files — the `.install` (schema), `.info` (dependencies), and `.module` (the heart of it).

---

## Claude — 2026-06-16 18:34

The `shanti_images` table is a **sidecar registry**, not the content type itself — it maps file↔node↔IIIF-server-id↔MMS-id. The actual content type comes from the `shanti_image_type` dependency. Let me read the README and find that module plus the Features export.

---

## Claude — 2026-06-16 18:35

The README is comprehensive — five content types, IIIF server integration, MMS legacy IDs, and the full API surface. Now the most important artifact for D11 scoping: the actual field definitions on the `shanti_image` content type. Let me read the Features field exports.

---

## Claude — 2026-06-16 18:35

This file is 4,688 lines of display config — too much noise to read linearly. The **field_base** file has the actual storage types (what matters for D11 schema). Let me extract the structured essentials from both with targeted greps.

---

## Claude — 2026-06-16 18:37

Let me pair each field name with its storage type and which bundle uses it. I'll extract that mapping directly.

---

## Claude — 2026-06-16 18:38

My grep patterns missed — let me check the actual key format in field_base and do a clean Python extraction pairing field name → type → label → bundle.

---

## Claude — 2026-06-16 18:38

Now I have the complete field inventory. Let me confirm the entity-reference targets and the KMaps field domains to round out the audit, then I'll write it up.

---

## Claude — 2026-06-16 18:39

I have the complete content model now (5 content types, all fields, references, KMaps domains, IIIF sidecar). Let me check the docs nav structure so I file the audit where it'll be discoverable.

---

## Claude — 2026-06-16 18:39

Now I'll write the audit document.

---

## Claude — 2026-06-16 18:49

Of course — hold off on writing it up. What are your questions? I've got the full content model loaded in context (5 content types, all 50 `shanti_image` fields with types/cardinalities/required flags, the satellite-entity references, the KMaps domains, and the `shanti_images`/IIIF/MMS sidecar table), so fire away.

---

## User — 2026-06-16 18:51

UNder "What this audit establishes (ready to scope Step 1a)" you only mention one related entity in the entity graphs, but there should be three (we think)...  why is that?

---

## Claude — 2026-06-16 18:51

Good catch — that's a wording problem in the summary bullet, not a gap in the findings. The three are all there; I just under-stated it.

That bullet — "the satellite-entity graph and which references are required (`field_image_agents`)" — the parenthetical was naming **which reference is required**, not the full set. Only `field_image_agents` is required (cardinality `-1`, `required=1`); the other two are optional, so I named just the required one and it read like the whole list.

The actual graph, which is in the audit's "Referenced satellite entities" table, is **three satellites referenced directly from `shanti_image`**:

| Reference field | → target bundle | required? |
|---|---|---|
| `field_image_agents` | `image_agent` | **yes** |
| `field_image_descriptions` | `image_descriptions` | no |
| `field_external_classification` | `external_classification` | no |

And there's a **fourth, nested one level deeper**: `external_classification` → `field_external_class_scheme` → `external_classification_scheme`. That's why the content-type count is **five** (`shanti_image` + those four) even though only **three** hang directly off the image node.

So your "three" is right for the direct entity graph. The fix is to reword that bullet to list all three and flag only `image_agents` as required (and note the nested scheme lookup). 

One thing worth surfacing while we're here: that required `image_agents` reference matters for Step 1a sequencing — you can't migrate/create a valid `shanti_image` node without at least one `image_agent`, so the `image_agent` content type has to exist and be migrated *before* images, even in the public-plumbing slice. Want me to treat all three satellites as in-scope for Step 1a, or just the required `image_agent` and defer descriptions/classifications?

---

## User — 2026-06-16 18:53

All three satellites in scope for Step 1a

---

## Claude — 2026-06-16 18:53

Got it — all three satellites in scope for Step 1a. Let me capture what that pins down:

**Content types to build/migrate in Step 1a (the public-plumbing slice):**
1. `shanti_image` — primary
2. `image_agent` — required reference
3. `image_descriptions`
4. `external_classification`

Plus the nested lookup **`external_classification_scheme`** comes along for the ride, since `external_classification` references it — so realistically Step 1a touches all **five** content types. That's the right call for a representative pilot: it proves the full inline-entity-graph migration pattern once, rather than discovering the satellite complexity later in a parallel track.

**Migration ordering this forces** (referenced entities before referencing ones):
1. `external_classification_scheme` (lookup, no deps)
2. `image_agent`, `image_descriptions`, `external_classification`
3. `shanti_image` (+ re-establish the references)
4. the `shanti_images` sidecar / IIIF identity linkage

The KMaps fields ride along on each (Spike 1), but **IIIF display and OG→Group/auth stay deferred** — display gated on the IIIF-server decision, auth on Step 1b.

Do you have more questions, or should I write up the audit now — with the corrected three-satellite wording and this "all five types in scope for Step 1a" scoping decision baked in?

---

## User — 2026-06-16 18:54

go ahead and write it up, and we can read it and ask more questions as needed

---

## User — 2026-06-16 18:56

And you can also note that the IIIF server implementation is staying as-is for the foreseeable future.

---

## Claude — 2026-06-16 18:56

I'll fold that decision in — it resolves most of open item #1. Updating the relevant spots.

---

## Claude — 2026-06-16 18:56

Now update open item #1 (no longer an open decision) and the recommended next step.

---

## Claude — 2026-06-16 18:57

Done. The IIIF-server-stays-as-is decision is now woven in across the three places it mattered:

1. **Sidecar table section** — added a dedicated *"Decision: the IIIF server stays as-is"* callout, and reframed the migration implication: `i3fid` values stay valid, so D11 must **preserve** the linkage verbatim rather than reconstruct it. Tied it to ADR 004 (don't refactor existing external infra for MVP).
2. **Open item #1** — downgraded from "needs a strategy decision" to "IIIF *wiring* details": confirm endpoints/credentials are reachable from D11 and port the upload/display path. No new stack, no identifier change.
3. **Recommended next step** — image display now wires to the existing server as a known quantity; remaining risk is just the wiring.

The audit's still on the `spike/images-shanti-images-audit` branch, uncommitted. Read it over and fire away with more questions — when you're happy with it, I can add the `docs/planning/.pages` nav entry and commit (per the session-end ritual, new docs are invisible in mkdocs until listed there).

---

## User — 2026-06-16 19:02

About the decision to not collapse the image entity graph:  what is the the technical debt that this incurs?

---

## Claude — 2026-06-16 19:03

Good question to force now, because the "don't collapse" choice is a deliberate debt trade, not a free default. Here's what we're actually signing up for.

## The core irony: the D7 model is already paragraph-shaped, built out of the wrong primitive

The field settings tell the story. The satellites use Inline Entity Form with **`allow_existing => 0`** (you can't point at an existing agent/description — each is created fresh inside the parent) and, for agents, **`delete_references => 1`** (deleting the image deletes its agents). That's a *composite/owned* relationship — exactly what Paragraphs or composite content entities exist for. D7 emulated it with full nodes because that's what it had. Keeping nodes in D11 means **re-implementing a paragraph-shaped relationship on the heavier node primitive.** The debt items all flow from that mismatch:

**1. Node-space pollution requiring active suppression.** The README says agent and description nodes "are not viewed directly on the site." But they're still real nodes with nids, canonical URLs, revisions, and access records. You must *actively* exclude all five satellite types from: search/Solr indexing, Views listings, sitemaps, JSON:API collection endpoints, and breadcrumb/pathauto. Every new surface that lists "nodes" is a potential leak of a satellite. Paragraphs never appear in node-space, so this whole suppression burden simply wouldn't exist.

**2. Access-propagation coupling — and this one is security-adjacent.** A `shanti_image` carries `group_content_access` (Visibility, required). Its description/agent nodes have their *own* independent access. Nothing automatically makes a private image's description node private. You have to propagate the parent's visibility onto every satellite, and keep them in sync on every edit. Paragraphs inherit parent access for free. **This intersects directly with Step 1b auth and the solr-proxy visibility filtering** — it's not isolated debt, it lands in the access-control coherence work ADR 009 already flags.

**3. Orphan lifecycle, inconsistently.** Cascade-delete is set per-field and it's *not* uniform: agents cascade (`delete_references => 1`), external_classification does **not** (`=> 0`). So you inherit an inconsistent ownership model — some satellites get orphaned by design. With nodes you own that cleanup logic forever; with composite entities the parent owns lifecycle and it's consistent.

**4. IEF dependency for the edit UX.** Keeping referenced nodes means the D11 editing experience leans on the Inline Entity Form contrib module (its D11 maturity, its quirks) to make editing-satellites-in-the-parent tolerable. Paragraphs have first-class core-ish tooling. This is a contrib-surface-area bet.

**5. Loose referential integrity + migration fragility.** entityreference-by-nid has no DB-level FK; references can dangle. The migration must carefully preserve and remap nids across five types in dependency order, and any miss leaves a broken graph. A composite model would migrate the children *as part of* the parent.

**6. A likely second migration later.** If a post-MVP "improve" pass ever moves these to paragraphs/sub-entities (the natural model), that's a *second* content migration (node → paragraph) on already-migrated production data — the most expensive kind. We're deferring that cost, with interest.

## The honest counterweight

All of that is the **price of de-risking the MVP**, and per ADR 008 (migrate, not improve) it's likely the right price. Collapsing now means designing the target paragraph model *and* writing node→paragraph transform plugins *during* the pilot — more upfront design and migration-plugin risk in exactly the slice meant to establish the shared pattern. The debt above is mostly *operational* (suppression, propagation, cleanup) rather than *blocking*, and items #2 and #1 fold into auth/Solr work we're doing anyway.

The one I'd flag as genuinely sharp is **#2 (access propagation)** — because it's silent, it's a privacy leak if missed, and it's the kind of thing that's much cheaper to design into Step 1b than to retrofit after content is live.

Want me to add a short "Technical debt of preserving the entity graph" subsection to the audit (anchored on #1/#2/#6), so this reasoning is captured rather than living only in this conversation?

---

## User — 2026-06-16 19:08

Yes, document this.  And we should seriously review the meaning of ADR 008, because really I originally meant the user-facing features.

---

## Claude — 2026-06-16 19:09

Let me read ADR 008 as written before I engage with the reinterpretation — I want to ground this in its actual wording.

---

## Claude — 2026-06-16 19:10

ADR 008 as written is striking: every example — context, the scope table, all consequences — is about **Solr and search quality**. It never actually says anything about internal data modeling. So when the audit leaned on it to justify keeping the entity graph as nodes, that was me stretching a search-quality guardrail to cover an architecture decision it doesn't address. Your original intent — "improve" = *user-facing features* — fits the text far better than the broad reading does.

This matters concretely: if "don't improve" governs only user-facing behavior, then **collapsing the entity graph to paragraphs is not forbidden** — users never see whether an agent is a node or a paragraph. It becomes a pure engineering choice (migration simplicity vs. model correctness), and the conservative "keep as nodes" recommendation loses its ADR backing.

Let me do three things: add the technical-debt subsection, fix the audit's misuse of ADR 008, and draft a Proposed ADR to put the scope clarification in front of the team (ADRs are immutable, so a clarification is a new ADR). First let me check the ADR nav file.

---

## Claude — 2026-06-16 19:10

Now the audit edits — fix the ADR 008 misuse and add the technical-debt subsection.

---

## Claude — 2026-06-16 19:15

Two more spots in the audit need to reflect that the model is now an open decision.

---

## Claude — 2026-06-16 19:18

Now the Proposed ADR putting the scope clarification in front of the team.

---

## Claude — 2026-06-16 19:23

Now wire up the nav so these new docs are visible in mkdocs (ADR index + both `.pages`).

---

## Claude — 2026-06-16 19:24

Done. Here's what I wrote and how it hangs together.

## 1. Technical-debt subsection (in the audit)
Added under the entity-graph section, anchored on the insight that **the D7 model is already paragraph-shaped, built from the wrong primitive** (`allow_existing => 0`, `delete_references => 1` = owned composites emulated with nodes). Six debt items, with the two sharpest flagged: **node-space pollution requiring active suppression** (#1) and **access-propagation coupling** (#2, security-adjacent — folds into Step 1b auth + solr-proxy filtering).

## 2. Fixed the audit's misuse of ADR 008
- The keep-vs-collapse choice is now framed as an **open engineering decision**, not settled by ADR 008.
- Added a note that "five content types" is the *D7* shape; the D11 target shape depends on the decision.
- **Recommended next step** now leads with the two decisions to settle (ADR 008 scope, then keep-vs-collapse) before building.

## 3. Proposed ADR 010 — scope clarification
A new ADR (immutability respected — it *refines* ADR 008, doesn't edit it), **Status: Proposed**, same deciders as 008. Core decision: **"improve" = user-facing features/behavior, not internal architecture.** Includes an in/out scope table and the operative test ("if a user could perceive it as a different/better feature, it's deferred"), with guardrails: faithful migration still the floor, no API-shape changes, no backdoor scope creep. Listed in the ADR index as Proposed and added to nav.

## Nav wired up
`docs/adr/.pages`, `docs/adr/README.md`, and `docs/planning/.pages` all updated so the two new docs are visible in mkdocs.

Everything's on `spike/images-shanti-images-audit`, uncommitted. Two things for you:
- **Read ADR 010 and the audit's new sections** — the scope test and the debt framing are the parts most worth your eyes.
- ADR 010 is the real decision now. It needs team ratification; until it's Accepted, the audit correctly treats the modeling choice as open. Want me to leave it Proposed for the next team session, or is this a call you want to make now?

---

## User — 2026-06-16 19:26

We are meeting right now and have ratified ADR 010

---

## Claude — 2026-06-16 19:26

Ratified — flipping ADR 010 to Accepted and propagating that everywhere. (Moving Proposed→Accepted is the normal lifecycle; immutability applies from here on.)

---

## Claude — 2026-06-16 19:28

Now update the audit so the ADR 008 scope question reads as settled, leaving only the engineering call.

---

## Claude — 2026-06-16 19:29

ADR 010 is now **Accepted** everywhere it appears:

- **`010-...md`** — status flipped to Accepted, blockquote de-conditionalized, consequences bullet records ratification on 2026-06-16.
- **ADR index** (`README.md`) — status column now Accepted.
- **`adr/.pages`** — dropped the "(Proposed)" label.
- **Audit** — the keep-vs-collapse paragraph and the "Recommended next step" now read the scope question as settled (remodeling permitted, subject to the faithful-migration floor), leaving **one** open decision: keep-as-nodes vs. collapse-to-paragraphs.

Net effect on the Images pilot: the only thing standing between this audit and scoping Step 1a is that single engineering call. The audit lays out the trade — keep-as-nodes is lower migration risk now but carries the six debt items (with #2 access-propagation the sharp one); collapse-to-paragraphs is more upfront design/migration work but gives owned lifecycle and inherited access for free, which directly helps Step 1b auth.

Two things:

1. **Since you're all in the room — do you want to make the keep-vs-collapse call now too?** If so I'll record it in the audit and we're clear to scope Step 1a. My read: collapse-to-paragraphs, because #2 (access propagation) is a silent privacy risk that's far cheaper to get right by inheritance than to retrofit — but it's a real trade and your migration-capacity call.

2. This is a natural commit point (ratified ADR + the audit). Everything's staged on `spike/images-shanti-images-audit` but uncommitted. **Want me to commit?** I can do the audit + ADR 010 + nav as one commit, or split the ADR from the audit.

---

## User — 2026-06-16 19:30

Yes, let's collapse these and implement paragraphs.  This decision is for this case only.  Future decisions will be made about other cases on their own merits.

---

## Claude — 2026-06-16 19:30

Recorded — collapse to Paragraphs for Images, explicitly **non-precedential**. Let me bake that into the audit, including the "this case only" scoping so nobody later cites it as a blanket rule.

---

## Claude — 2026-06-16 19:31

Let me align the migration-ordering list with the paragraph model (satellites now migrate as embedded paragraphs, not separate pre-migrated nodes).

---

## Claude — 2026-06-16 19:32

The audit now reads as a settled plan end-to-end. Decision captured with three guardrails baked in:

- **Decision block** — collapse to Paragraphs, with the *why* (item #2 access propagation the decider, #1 and #6 reinforcing) and a callout that the keep-as-nodes debt is recorded as rationale, not accepted debt.
- **Non-precedent scope** — explicit blockquote: applies to the Images satellite graph only, sets no precedent, per ADR 010 each case decided on its own merits.
- **Downstream consistency** — the target-shape note, migration ordering (satellites now migrate as embedded paragraphs, scheme stays a referenced lookup), and "Recommended next step" all updated to match.

Step 1a is now fully scopeable from this doc: `shanti_image` content type + three paragraph types + scheme lookup + 4 KMaps fields → kmassets, IIIF wired to the existing server, node→paragraph migration transform, auth deferred to 1b.

## Session is at a clean milestone — ready to commit
Lots landed this session: the `main` reconciliation/branch cleanup, the audit, ADR 010 (ratified), and the paragraphs decision. Per the session-end ritual, decisions and `.pages` are flushed. Two things I can do when you say go:

1. **Commit** on `spike/images-shanti-images-audit` — I'd suggest two commits: (a) ADR 010 + nav, (b) the Images content-model audit + nav. Then it's ready for a PR to `main`.
2. **`scripts/save-session-log.py`** — this was a substantial planning/decision session, which is exactly what that script is for.

Want me to do both? And should I open the PR to `main`, or leave that to you?

---

## User — 2026-06-16 19:33

please explain "scheme stays a referenced lookup"

---

## Claude — 2026-06-16 19:34

Good thing to pin down, because it's the one piece of the graph that does **not** become a paragraph — and the reason is about *ownership vs. sharing*.

## The distinction: owned data vs. shared vocabulary

When we "collapse to paragraphs," we're collapsing the satellites that are **owned by a single image** — each image has its own agents, its own descriptions, its own classification tags. In D7 these used `allow_existing => 0` (created fresh per image, never shared), which is exactly the signal that they're owned composite data. Paragraphs are the right primitive: embedded in the parent, cascade-deleted with it, never shared.

`external_classification_scheme` is a different kind of thing. It's not per-image data — it's a **shared controlled vocabulary**. A scheme is something like *"Getty Thesaurus of Geographic Names"* or *"Library of Congress Subject Headings"*, and it carries the scheme's own URL/API info. Many classification tags, across many different images, all point at the *same* scheme.

```
shanti_image (node)
├─ paragraph: image_agent              ← owned by THIS image
├─ paragraph: image_description        ← owned by THIS image
└─ paragraph: external_classification  ← owned by THIS image
      └─ reference → external_classification_scheme   ← SHARED, one canonical record
                                                         (e.g. "Getty TGN"), pointed at
                                                         by many classification paragraphs
```

## Why the scheme can't be a paragraph

If you embedded the scheme as a paragraph, you'd **duplicate** "Getty TGN" (its label, URL, API endpoint) inside every image that uses it — thousands of copies, no single source of truth. Update the Getty URL once and you'd have to rewrite every copy. That's precisely what a referenced lookup avoids: **one** canonical `external_classification_scheme` record, and every classification paragraph holds a reference to it.

So "stays a referenced lookup" means: in D11 the scheme remains a **standalone, shared entity** that other things reference — *not* collapsed into the image. That's also why it migrates **first** in the ordering (the canonical scheme records must exist before the classification paragraphs that point at them).

## One detail still open

*What* entity type the scheme should be in D11 isn't nailed down — a **taxonomy vocabulary** is the natural fit for "a controlled list of schemes, each with a few extra fields (URL, API)," but a small content type or a custom content entity would also work. That's a minor build-time choice; what's decided is that it stays **referenced, not embedded**. Want me to add that "scheme entity-type TBD (taxonomy vs. content type)" as a small open item in the audit so it isn't lost?

---

## User — 2026-06-16 19:37

Could you give us the pros and cons of taxonomy vs. custome content entity?

---

## Claude — 2026-06-16 19:39

Real Drupal architecture question — and the honest answer turns on one thing: **does the scheme need behavior, or just hold data?** Let me lay it out.

## What the scheme actually is

From the D7 README, an `external_classification_scheme` holds a **label + URLs + API info** for an external authority (Getty TGN, LoC, etc.), and the D7 field description says *"if the desired scheme is not listed, contact Shanti and we'll add it."* So it's a **small, slowly-changing, editorially-managed controlled list** — a handful of records, added occasionally by admins. That framing matters for both options below.

## Comparison

| | **Taxonomy vocabulary** | **Custom content entity** |
|---|---|---|
| **Code required** | None — vocabulary + fields via UI, CMI-exportable | Entity class + annotation + handlers (list builder, forms, access, permissions); boilerplate you own forever |
| **Admin UI** | Free, ready-made (term list, add/edit, autocomplete) | You build the forms/list builder (more bespoke, more control) |
| **Reference from the paragraph** | Native ER field, `target_type: taxonomy_term` — trivial | Native ER field, `target_type: <your entity>` — also fine |
| **Fields (URL, API, link template)** | Terms are fieldable — add freely | Base fields in code, fully controlled |
| **Behavior / logic** | Bolt-on via hooks/field formatters/a service | Lives naturally as methods on the entity class (e.g. `buildExternalUrl($externalId)`) |
| **Semantics** | Slight stretch — taxonomy = "categorization," not "data record with URLs" | Exact fit; self-documenting |
| **Permissions** | Coarse (per-vocabulary needs contrib/custom for fine control) | Dedicated permission set |
| **Migrate / Views / JSON:API / search_api** | All free; well-trodden D7→D11 term migration | Supported, but you wire the handlers and write the destination |
| **Editor accessibility** | Non-developers can manage the list | Same, once you've built the UI |
| **Maintenance surface** | Effectively zero | Another custom module to test & maintain |

## The deciding question

**Is there real logic on the scheme — specifically link/API construction?** The README hints at it ("urls and apis to the scheme to *link references*"): turning a classification's external ID into a working link or API call against that scheme. 

- If that's genuinely just *string concatenation* (base URL + ID) → a **field formatter or tiny service** handles it; **taxonomy** wins on cost. 
- If it's real behavior (varied URL templates per scheme, API auth, validation, computed fields) that you'd rather encapsulate and unit-test on the object → the **custom entity** earns its keep.

## My recommendation: **taxonomy**, for this case

For a small editorially-managed lookup in a *migrate-not-improve* MVP (ADR 008), taxonomy is the lower-code, lower-risk path that's still fully faithful — and the link-building can ride on a field formatter. Start there; promote to a custom entity only if the scheme outgrows it.

**One caveat — lock-in.** Switching taxonomy → custom entity later is itself a migration (the same "second migration" debt we just weighed for the paragraphs). It's cheap here because the volume is tiny (a handful of schemes), but if the room already believes link/API logic will be substantial, picking the custom entity now avoids that re-migration. So: **taxonomy unless you expect real scheme behavior soon.**

Want me to record this as the scheme's entity-type decision in the audit (taxonomy, with the field-formatter-for-links note and the lock-in caveat), or leave it as an open item for you to decide after the meeting?

---

## User — 2026-06-16 19:41

Let's use taxonomy

---

## Claude — 2026-06-16 19:41

Recorded — taxonomy it is. Let me fix the three spots in the audit (one currently contradicts itself by lumping the scheme into the paragraphs).

---

## Claude — 2026-06-16 19:41

Now add the scheme decision block (the note references "see decision below") and update the migration ordering + next-step to name taxonomy.

---

## Claude — 2026-06-16 19:42

Done — the taxonomy decision is recorded and the doc is now fully consistent end to end. Updated in four places: the target-shape summary, a dedicated **scheme decision block** (taxonomy, link-building via field formatter/service, lock-in caveat), the migration ordering (D7 scheme nodes → taxonomy terms first; classification paragraphs reference the term), and the next-step build list.

The D11 Images target model is now completely specified in the audit:

| D7 | D11 |
|---|---|
| `shanti_image` node | `shanti_image` content type |
| `image_agent`, `image_descriptions`, `external_classification` nodes | **Paragraph** types embedded on the image |
| `external_classification_scheme` node | **Taxonomy vocabulary** (referenced) |
| 4 KMaps fields | KMaps fields → kmassets (Spike 1) |
| external IIIF server | same server, wired to D11 |

That's everything needed to scope ADR 009 Step 1a.

## Still pending from earlier — commit + session log
Nothing's committed yet. This session produced: the `main` reconciliation/cleanup, ADR 010 (ratified), and the Images content-model audit with three recorded decisions. When you're ready, I'd suggest:

1. **Commit** on `spike/images-shanti-images-audit` — two commits: (a) ADR 010 + nav, (b) the audit + nav.
2. **`scripts/save-session-log.py`** — this was a substantial decision session.
3. Optionally **open a PR to `main`**.

Want me to go ahead with all three, or just commit?

---

## User — 2026-06-16 19:43

go ahead with all three

---

## Claude — 2026-06-16 19:43

Two commits — ADR 010 first, then the audit.

---

## Claude — 2026-06-16 19:44

Both commits in. Now the session log — let me find this session's JSONL.

---
