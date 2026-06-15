# Session Log: Migration Roadmap Mvp Sequencing

**Date:** 2026-06-15  
**Participants:** Yuji Shinozaki, Claude (Sonnet 4.6 → Opus 4.8 for planning)  
**Outcome:** [Migration Roadmap & MVP Sequencing](../roadmap.md) — also new deferred note [tibetan-search-quality.md](../deferred/tibetan-search-quality.md) and re-scoped [Spike 4](../spikes/spike-04-ckeditor5-footnotes.md). Follow-on session ratified [ADR 008](../adr/008-mvp-migrate-not-improve.md) and [ADR 009](../adr/009-migration-sequencing-strategy.md) (see addendum).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-15 14:13

This session is being continued from a previous conversation that ran out of context. The summary below covers the earlier portion of the conversation.

Summary:
1. Primary Request and Intent:
   The user invoked the `/claude-api` skill (the Claude API reference skill) at the start of a session in the `mandala-navina` repository. No specific implementation task was stated — the invocation appears to be to load the Claude API reference for consultation. Immediately after, the user requested a text-only conversation summary (this current request).

2. Key Technical Concepts:
   - **Claude API / Anthropic SDK**: The `/claude-api` skill loaded comprehensive reference documentation covering the Messages API, streaming, tool use (manual loops and tool runner beta), managed agents, prompt caching, extended thinking/adaptive thinking, structured outputs, model migration, and more.
   - **Current recommended model**: `claude-opus-4-8` (default), with adaptive thinking `{type: "adaptive"}` and streaming for large outputs.
   - **Mandala Navīna project**: A Drupal 11 monorepo rebuild of the UVA Library Mandala Digital Library platform, consolidating five D7 sites into one Drupal instance deployed on AWS via Terraform + Ansible + CodePipeline.
   - **Session startup ritual**: CLAUDE.md requires reading `docs/adr/README.md`, `docs/spikes/README.md`, and `docs/deferred/README.md` at the start of every session.
   - **ADR pattern**: Immutable once accepted; superseded by new ADRs rather than edited in place.

3. Files and Code Sections:
   - **`/Users/ys2n/Code/uvalib/mandala-navina/CLAUDE.md`** (loaded via system context): Project instructions. Defines session startup ritual, team workflow, ADR immutability rule, team members, repository structure, local dev commands (DDEV-based), custom modules, and related repos. Key instruction: read ADR README, spikes README, and deferred README at session start.
   - **`/Users/ys2n/.claude/projects/-Users-ys2n-Code-uvalib-mandala-navina/memory/MEMORY.md`** (loaded via system context): Memory index pointing to project-naming conventions (Mandala Navīna), kmassets search pitfalls, curl read-only preference, no-sed preference, mandala-scripts location, Confluence/Jira not yet available.
   - **`/Users/ys2n/Code/uvalib/mandala-navina/docs/adr/README.md`** (read by assistant): ADR index showing 7 accepted ADRs (001–007): monorepo, Drupal 11, Terraform+Ansible+CodePipeline, Solr as source of truth, single-site, kmterms-in-kmassets shadow pattern, reindeer_x as independent service.

4. Errors and fixes:
   - No errors occurred. The session was interrupted by the summary request before any implementation work began.

5. Problem Solving:
   - No problems were encountered or solved. The session was in startup/orientation phase only.

6. All user messages:
   - `<command-message>claude-api</command-message>` / `<command-name>/claude-api</command-name>` — invoked the Claude API reference skill.
   - "CRITICAL: Respond with TEXT ONLY. Do NOT call any tools. [detailed summary request instructions]" — requested a plain-text conversation summary following a specific structure.

7. Pending Tasks:
   - Complete session startup orientation: read `docs/spikes/README.md` and `docs/deferred/README.md` (only `docs/adr/README.md` was read before the summary request).
   - No specific implementation task has been stated yet — awaiting the user's actual work request after the skill was loaded.

8. Current Work:
   The session had just started. The `/claude-api` skill was invoked and its documentation loaded. I had begun the required CLAUDE.md session startup ritual by reading `docs/adr/README.md`. The summary request arrived before any further orientation or implementation work could begin.

9. Optional Next Step:
   Once the summary is acknowledged, resume the session startup ritual per CLAUDE.md requirements:
   > "At the start of every session, read these files to orient yourself before doing any work: 1. `docs/adr/README.md` — index of all architectural decisions; read any ADR that seems relevant to the task 2. `docs/spikes/README.md` — spike status; read the doc for any spike being continued or referenced 3. `docs/deferred/README.md` — known gaps and deferred work"
   
   The next step would be to read `docs/spikes/README.md` and `docs/deferred/README.md`, then await the user's specific task request related to the Claude API.

If you need specific details from before compaction (like exact code snippets, error messages, or content you generated), read the full transcript at: /Users/ys2n/.claude/projects/-Users-ys2n-Code-uvalib-mandala-navina/3390ac50-1662-45e7-8807-c177fdbc7b09.jsonl
Continue the conversation from where it left off without asking the user any further questions. Resume directly — do not acknowledge the summary, do not recap what was happening, do not preface with "I'll continue" or similar. Pick up the last task as if the break never happened.

---

## Claude — 2026-06-15 14:12

Prompt is too long

---

## User — 2026-06-15 14:12

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-06-15 14:12

<command-name>/compact</command-name>
            <command-message>compact</command-message>
            <command-args></command-args>

---

## User — 2026-06-15 14:13

<local-command-stdout>[2mCompacted (ctrl+o to see full summary)[22m</local-command-stdout>

---

## User — 2026-06-15 14:15

ok.  I guess I don't know what I am doing.  What does /claude-api do?

---

## Claude — 2026-06-15 14:15

`/claude-api` loads a reference skill that gives me detailed, up-to-date documentation on how to use the Anthropic Claude API — things like:

- Which model IDs to use (e.g., `claude-opus-4-8`)
- How to use the SDK in Python, TypeScript, Go, Ruby, etc.
- Tool use / function calling patterns
- Streaming, structured outputs, prompt caching
- Managed Agents vs. raw API calls
- Model migration guidance

It's intended to be invoked when you're **about to build something that calls Claude**, so I have accurate API surface knowledge rather than relying on potentially stale training data. It's essentially a "load the manual" command before writing Claude API integration code.

If you just wanted to orient to the project rather than build a Claude integration, you don't need it — it had no effect on the Mandala codebase. Is there a specific task you'd like to work on today?

---

## User — 2026-06-15 14:16

okay.   How I get data about my remaining usage

---

## Claude — 2026-06-15 14:16

For Claude Code (what you're using now): run `/cost` in the prompt to see token usage and cost for the current session.

For your overall Anthropic account quota and billing: go to **console.anthropic.com → Settings → Billing** (or Usage). That shows monthly spend, token usage by model, and rate limits.

There's no in-CLI command to check account-level quota — it's web-only.

---

## User — 2026-06-15 14:18

What does "total cost" really mean?  I am on the $20 / month pro plan.

---

## Claude — 2026-06-15 14:18

On a Pro subscription ($20/month), the "total cost" shown by `/cost` is **informational only** — it shows what those tokens would cost at raw API rates (pay-per-token). You're not actually being charged that amount; your $20/month covers your Claude Code usage within the plan's limits.

Think of it as a consumption gauge, not a bill line item. If you consistently see very high numbers there, it's a signal you might be hitting or approaching plan limits — but the dollar figure doesn't come out of your credit card beyond the flat $20.

The actual limits on the Pro plan (how many tokens/messages per time window) are managed by Anthropic and not exposed as a simple number — you'd just notice slowdowns or a "rate limited" message if you hit them.

For authoritative details on what the Pro plan includes, check **claude.ai → Settings → Plan** or the Anthropic pricing page.

---

## User — 2026-06-15 15:11

So what does Opus give me that Sonnet doesn't?

---

## Claude — 2026-06-15 15:11

For most tasks in this project — reading files, editing code, writing ADRs, debugging Drupal config — the difference is minimal. Sonnet 4.6 (what you're running now) handles those well.

Where Opus 4.8 pulls ahead:

- **Complex multi-step reasoning** — architectural decisions with many interacting constraints
- **Long-context synthesis** — making sense of a large codebase across many files at once
- **Ambiguous problems** — when the right answer requires judgment calls, not just pattern-matching

The practical tradeoff on a Pro plan: Opus consumes your usage budget faster, so you get fewer turns per time window before hitting rate limits. Sonnet stretches further.

For day-to-day Mandala work, Sonnet is the right default. Switch to Opus (via `/model`) when you're working through something genuinely hard — like designing the Solr refactor or a tricky ADR where the tradeoffs aren't obvious.

---

## User — 2026-06-15 15:14

I'm finding claude's intution on things a bit shallow.  It takes a bit to get to the understanding that I would like it to have.  Does that necessarily mean that I should look into switching to Opus?

---

## Claude — 2026-06-15 15:14

Not necessarily — model is one lever, but often not the biggest one. A few things to consider:

**Context loading matters more than model tier.** Claude starts every session cold. The session startup ritual in CLAUDE.md (reading ADRs, spikes, deferred docs) exists precisely because without it, Claude has no institutional memory of your decisions. If that ritual is skimped, even Opus will seem shallow on Mandala-specific questions.

**Doc depth is the ceiling.** Claude's intuition about your project is bounded by what's written down. If the ADRs and spikes capture the *what* but not the *why* and *tradeoffs*, Claude's reasoning will reflect that gap regardless of model. Richer docs → deeper intuition.

**Opus does genuinely help for hard problems.** If the shallowness is on questions involving multiple interacting constraints — "how should we handle X given our Solr constraints, the single-site decision, and the D11 migration?" — Opus handles that kind of synthesis better.

**Framing helps.** Giving Claude more context upfront ("here's what I already know, here's what's unclear, here are the constraints") gets you to the depth you want faster than waiting for it to surface it on its own.

My read: try Opus for a session or two on the harder architectural questions and see if it feels different. But also look at whether the spikes and deferred docs are capturing enough reasoning — that investment pays off across all model tiers.

---

## User — 2026-06-15 15:15

Okay let's take this from a different angle.  Is there sense in using Opus for planning?

---

## Claude — 2026-06-15 15:15

Yes, that's actually the clearest case for Opus.

Planning is where the model-tier gap is most pronounced — Opus holds more competing constraints in mind simultaneously, catches more second-order consequences, and surfaces tradeoffs you didn't ask about. For Mandala specifically, things like "how do we phase the Solr refactor given ADR 004" or "what's the right module boundary for KMaps fields" — those benefit from the deeper reasoning.

The practical pattern that works well: use Opus for the planning session, then hand off the implementation plan to Sonnet for execution. Sonnet is fast and cheap for "make this edit", "write this config", "find this file". You're not paying the Opus cost for mechanical work.

The `/plan` mode in Claude Code (or just asking explicitly for a plan before implementation) fits naturally with this — do the hard thinking in Opus, commit to a direction, then execute in Sonnet.

Given your CLAUDE.md workflow already separates planning artifacts (ADRs, spikes) from execution, this maps cleanly onto what you're already doing.

---

## User — 2026-06-15 15:16

Explain plan mode

---

## Claude — 2026-06-15 15:16

Plan mode (`/plan`) puts Claude into a read-only, think-before-you-act mode. It can read files and explore the codebase but **cannot make any edits**. You review and approve the plan before any changes happen.

The flow:

1. You describe the task
2. Claude explores the codebase, asks clarifying questions if needed, and drafts a plan
3. You review it — approve, redirect, or refine
4. Claude exits plan mode and executes

**Why it's useful:**
- Forces explicit alignment before any code changes
- Catches misunderstandings early (cheaper to fix a plan than undo edits)
- Naturally pairs with Opus — do the hard thinking in plan mode, then execute

**How to invoke it:**
- Type `/plan` in the prompt, or
- Just ask Claude to "plan this before doing anything" — I'll enter it implicitly

**What you see:**
Claude uses an `EnterPlanMode` / `ExitPlanMode` cycle internally. While in plan mode, any edit/write/bash attempt is blocked — you can see Claude reasoning without it touching anything.

For Mandala work, the natural fit is: start a planning session in Opus with `/plan`, work through the architecture, then switch to Sonnet for implementation once the ADR or spike doc is clear.

---

## User — 2026-06-15 15:18

Hmmm.  I guess the danger is leaving it on the wrong model at any point in time.  Spending too much on routine tasks.   Spending too little on complex tasks.

---

## Claude — 2026-06-15 15:18

Right. And the friction of manually switching models mid-session is enough that most people just don't do it.

A few ways people handle this:

**Just stay on Sonnet, escalate deliberately.** Use Sonnet as default, and when you hit a wall — Claude's answer feels thin, the tradeoffs aren't surfacing — that's your signal to switch. You'll recognize it quickly once you've seen it a few times.

**Separate sessions by intent.** Planning sessions get Opus, implementation sessions get Sonnet. The CLAUDE.md session startup ritual already creates a natural boundary — a new session for a new task isn't much friction.

**Don't overthink the cost.** On a Pro plan, the cost difference between Sonnet and Opus doesn't come out of your wallet — it comes out of your usage budget. Spending Opus tokens on a routine task doesn't charge you more; it just depletes your rate-limit headroom faster. Whether that actually matters depends on how heavily you use Claude Code. If you're not hitting rate limits, the optimization is theoretical.

Honestly, for where you are in the Mandala project — early architecture, lots of unknowns, complex interdependencies — I'd lean toward defaulting to Opus and only worrying about switching down if you start hitting rate limits. The planning work you're doing now is exactly where Opus earns its keep.

---

## User — 2026-06-15 15:20

So that gets me back to the question of account access.   I would like a visible running tally:  Here's what you've spent this month.  Here's what's left.  Where can I get that?

---

## Claude — 2026-06-15 15:20

For a **Pro plan subscription**, that dashboard doesn't really exist in a meaningful form — Anthropic doesn't expose a "tokens remaining this billing period" meter for subscription users. The plan is usage-limited by rate limits (requests per hour/day), not by a token budget you can watch drain.

What you *can* see:

- **console.anthropic.com** → Usage — shows token consumption broken down by model and day, but this is API key usage, not Claude Code Pro subscription usage
- **claude.ai → Settings → Plan** — shows your plan tier and renewal date, not consumption

The honest answer: Anthropic hasn't built the visibility dashboard that would make your question easy to answer. It's a known gap. You'll know you've hit a limit when Claude Code starts responding slowly or tells you you're rate-limited — there's no proactive warning.

If you wanted that kind of visibility, the **API tier** (pay-per-token, no subscription) gives you exactly it — console.anthropic.com shows spend in dollars, by model, by day. But that's a different product and likely more expensive for heavy use.

For now, the practical proxy is: `/cost` at the end of a session to see what that session consumed, and watch for rate-limit messages as your early warning signal.

---

## User — 2026-06-15 15:21

Excuse my language, but that sucks.

---

## Claude — 2026-06-15 15:21

It does. You're paying $20/month and can't see what you're consuming. That's a product gap Anthropic hasn't closed yet for subscription users.

The only real workaround if you want visibility: switch to API keys (pay-per-token), which gives you the full spending dashboard. The tradeoff is unpredictable monthly cost instead of a flat fee.

For what it's worth — if you're not hitting rate limits in practice, the visibility gap probably doesn't matter operationally. But not being able to answer "how much have I used?" is still just bad UX.

---

## User — 2026-06-15 15:22

But how can I actually tell when I hit the limit?

---

## Claude — 2026-06-15 15:22

Claude Code will show a message in the chat saying something like "Claude is currently unavailable due to usage limits" or the response will fail with a rate limit error. It's reactive — you find out when it happens, not before.

There's no proactive warning like "you're at 80% of your limit."

Anthropic's rate limits for Pro are also not publicly documented in a specific way — they're soft limits that vary by time window (per hour, per day) and can change. So you can't even calculate your headroom manually.

Practically speaking: if you're doing a long important planning session and Claude suddenly goes unavailable, your options are wait an hour and try again, or switch to a different session context. That's the failure mode to be aware of.

---

## User — 2026-06-15 15:25

Frankly that's baffling.  It's like asking:  How do I know if I'm going over the speed limit?   If you are being chased by the police, then you are.

---

## Claude — 2026-06-15 15:25

Exactly. It's a surprisingly poor experience for a developer tool that's supposed to be integrated into your workflow.

The charitable explanation is that Anthropic's priority has been building the capability, not the account management UX. The less charitable one is that opacity around limits is convenient — users who don't know they're limited don't complain as loudly.

Either way, it's a real friction point for anyone trying to plan serious work around Claude Code. You can't budget your sessions, can't prioritize which work deserves Opus vs. Sonnet from a consumption standpoint, and can't anticipate interruptions.

Worth filing feedback at github.com/anthropics/claude-code/issues if you haven't — that's where the team actually tracks product gaps.

---

## User — 2026-06-15 15:51

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-06-15 15:51

<command-name>/plan</command-name>
            <command-message>plan</command-message>
            <command-args></command-args>

---

## User — 2026-06-15 15:51

<local-command-stdout>Enabled plan mode</local-command-stdout>

---

## User — 2026-06-15 15:51

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-06-15 15:51

<command-name>/plan</command-name>
            <command-message>plan</command-message>
            <command-args></command-args>

---

## User — 2026-06-15 15:51

<local-command-stdout>Already in plan mode. No plan written yet.</local-command-stdout>

---

## User — 2026-06-15 15:52

please scan the documentation and let's formulate a plan from it

---

## Claude — 2026-06-15 15:52

I'll run the session startup ritual — reading spikes and deferred docs — then surface what's there for planning. Launching two parallel explorers now.

---

## Claude — 2026-06-15 15:52

Two explorers running in parallel — one reading all spike docs, one reading all deferred notes. I'll synthesize once both return.

---

## User — 2026-06-15 15:53

<task-notification>
<task-id>aa818a2880b6cbee6</task-id>
<tool-use-id>toolu_019LnQxw2TC5gaDTu6gvFWcd</tool-use-id>
<output-file>/private/tmp/claude-504/-Users-ys2n-Code-uvalib-mandala-navina/b19dfd33-b56c-47c2-88df-b5027b6739a7/tasks/aa818a2880b6cbee6.output</output-file>
<status>completed</status>
<summary>Agent "Read deferred documentation" completed</summary>
<result>## Summary

I've read all deferred notes documents in the mandala-navina project. Here's what I found:

### README.md
The deferred notes directory contains items identified during spike work and development that require downstream implementation. Documents follow a naming convention (AREA-short-description.md) and include headers with area, raised phase, Jira ticket (when available), and priority level.

### Four Open Deferred Items

**1. group-subgroup-nesting-approach.md** (collections/group module, Spike 3, High Priority)
- **Confirmed requirements:** Full inheritance model matching D7 `og_subgroups` behavior — visibility inheritance (private parent defaults to private child, child can override) and membership inheritance (child inherits parent members, can add independently)
- **Options evaluated:** Entity reference field alone (insufficient), `ggroup` module (handles membership but not visibility, D11 incompatible), `subgroup` module (similar limitations), and a custom hooks approach
- **Recommendation:** Option D — entity reference field + custom hooks handling both inheritance types via `hook_group_relationship_insert/delete` and `hook_group_presave/update`, with a `visibility_overridden` boolean field to prevent parent changes from overriding independent subcollection settings
- **Pre-Phase 3 task:** Design the custom module and scope membership sync edge cases

**2. group-access-inheritance-subcollections.md** (collections/access control, Spike 3, High Priority)
- **Problem:** In Group 3.x with entity reference nesting, nodes in subcollections don't inherit parent collection restrictions — a public subcollection's nodes would be visible even if the parent is restricted
- **Resolution:** References the companion group-subgroup-nesting-approach.md (Option D) with custom hooks for both visibility and membership inheritance
- **Impact:** Affects Phase 3 collections implementation and increases implementation effort if custom code is used instead of a contrib module

**3. solr-sync-architecture-d11.md** (solr/kmassets/kmterms, Session 2026-06-12, High Priority)
- **Background:** D7 maintains two Solr indices — `kmterms` (KMaps ontology, read-only from Drupal) and `kmassets` (two populations: KMaps-derived assets and Drupal content assets)
- **Known bugs:** Race condition in kmterms→kmassets sync where UDP ping fires before ECS pipeline finishes, causing stale data writes
- **D11 problems:** Content asset sync needs redesign without Aegir; kmaps-solr-sync service needs modernization; operational ownership needs clarification
- **Proposed architecture:** Evolve `reindeer_x` as a fully managed, event-driven subsystem — consolidate shell/Perl scripts into Node.js, replace UDP triggers with SQS subscriptions, add SNS completion/failure reporting, enable HTTP POST from Drupal to replace file-watching
- **Next steps:** Consolidate synchandler into reindeer_x, fix race condition via SNS/SQS completion events, discuss D11 content asset sync options with Dave Goldstein, spike D11 Drupal→reindeer_x HTTP POST

**4. solr-pipeline-cost-discussion.md** (solr/infrastructure, Session 2026-06-12, High Priority)
- **Context:** AWS ECS ingest pipeline handles kmassets and kmterms but may be over-sized for low-frequency Drupal content saves
- **Key questions for Dave Goldstein:** Cost breakdown, invocation frequency, lighter-weight alternatives (Lambda, scheduled ECS, direct Drupal→Solr), whether ECS is the right home for D11 Drupal content
- **Visibility problem:** Current pipeline gives Drupal no feedback on write success — fire-and-forget across async hops
- **Proposed direction:** Split approach — Drupal content via queue worker→HTTP POST to reindeer_x (or direct Solr write), kmterms-derived assets via SQS completion subscription</result>
<usage><subagent_tokens>15045</subagent_tokens><tool_uses>5</tool_uses><duration_ms>18132</duration_ms></usage>
</task-notification>

---

## Claude — 2026-06-15 15:53

Deferred notes are in. Still waiting on the spikes explorer.

---

## User — 2026-06-15 15:53

<task-notification>
<task-id>a4845784c1d6d5767</task-id>
<tool-use-id>toolu_01QtieVFERSPCb6wFv1MC5LU</tool-use-id>
<output-file>/private/tmp/claude-504/-Users-ys2n-Code-uvalib-mandala-navina/b19dfd33-b56c-47c2-88df-b5027b6739a7/tasks/a4845784c1d6d5767.output</output-file>
<status>completed</status>
<summary>Agent "Read spikes documentation" completed</summary>
<result>Perfect! I have successfully read all spike documents. Here is the complete status report:

## Spike Investigation Status Summary

This Drupal 11 monorepo for the UVA Library Mandala Digital Library platform has 8 time-boxed technical spikes tracking critical architectural decisions. Here is the full status:

### COMPLETED SPIKES (2/8)

**Spike 1: KMaps Field Type on Drupal 11** — PROVEN (June 2026)
- The D7 `shanti_kmaps_fields_default` 6-column field type successfully ported to D10 Field API
- Autocomplete queries the live `kmterms` Solr index with live KMaps terms (Buddhism `subjects-2610`, Tibet `places-5226`)
- All plugin types (`FieldType`, `FieldWidget`, `FieldFormatter`) register and function correctly
- Multiple mixed-domain terms save/reload correctly from MariaDB
- **Limitations:** Migration path not proven; widget UX not production-ready (edits trigger Solr errors); Solr indexing write path not ported; ancestor path resolution not implemented
- **Deferred:** [`docs/deferred/kmaps-widget-ux.md`](../deferred/kmaps-widget-ux.md)

**Spike 2: Solr Index Read-Only Integration** — PROVEN (June 2026)
- `search_api_solr` 4.3.10 connects cleanly to existing `kmassets` index without schema modification
- Top 3 results for "Buddhism" match D7 exactly (`subjects-2610`, `subjects-1205`, `subjects-882`)
- **Critical findings:**
  - Connection config requires: `path: /` (NOT `/solr/`), `host: mandala-solr-proxy.internal.lib.virginia.edu`, `skip_schema_check: true`
  - Proxy is a visibility filter, not a pass-through: anonymous sees 147,778 docs, direct Solr shows 557,483
  - Language filter must be disabled (`language_field: ''`) or KMaps taxonomy silently drops from results
  - `title` and `names_txt` are STRING fields requiring prefix wildcards (`title:Buddhism*`), not term queries
  - `kmterms` index uses block-join nested documents; requires raw Solarium or React KMaps app, not search_api
  - Date fields normalize to Unix timestamps
- **Limitations:** Full result ordering requires D7-equivalent raw Solarium queries; auth integration with proxy for private content deferred; Views integration not tested
- **Deferred:** Authentication integration with proxy (blocking for private content), search query strategy design

### PENDING SPIKES (6/8)

**Spike 3: Group Module Collections Architecture** — PARTIAL (June 2026)
- Group 3.3.5 is stable with no breaking API changes expected
- One-level collection/subcollection nesting proven via entity reference field on `subcollection` type
- Type system enforces one-level constraint automatically (attempting sub-subcollection fails validation)
- Content membership works; group roles provide four scopes (anonymous outsider, authenticated outsider, member, admin)
- **Critical limitation:** Access is NOT inherited collection → subcollection; requires explicit implementation
- **Known open issues to monitor:** #3162511 (forbidden interference), #2955656 (anonymous edge case), #3134058 (revision access), #3263349 (listings bypass)
- **Not established:** D11 compatibility (tested on D10.6.10), whether `ggroup`/`subgroup` modules are production-ready
- **Deferred:** [`docs/deferred/group-subgroup-nesting-approach.md`](../deferred/group-subgroup-nesting-approach.md), [`docs/deferred/group-access-inheritance-subcollections.md`](../deferred/group-access-inheritance-subcollections.md)

**Spike 4: CKEditor 5 Footnotes and Tibetan Unicode** — PENDING
- Lead: Than Grove (built D7 shanti_texts and footnotes)
- Must prove CKEditor 4 footnote markup transformation determinism and Tibetan Unicode round-trip through D11 database
- Pass criteria: markup format documented, deterministic transformation, correct rendering, no Unicode corruption, edge cases accounted for

**Spike 5: bibcite for the Sources Site** — PENDING
- Lead: Xiaoming Wang
- Must prove `bibcite` D11 support for all D7 reference types, `zotero_feed` workflow, and migration path from `biblio`
- Pass criteria: stable D11 release, equivalent reference types, required citation styles, Zotero import works

**Spike 6: API Compatibility for React Application** — PENDING (Team candidate)
- Lead: Than Grove (owns React app and D7 API contracts)
- Must define URL strategy for eight D7 API endpoints across four sites (AV, Images, Sources, Texts) without breaking React app
- Pre-spike findings from Spike 2: D7 has no Drupal-level free-text search (browser queries Solr directly); D7 Drupal API is browse-by-KMap, not text search; per-site node endpoints are separate
- Options: Option A (single domain, React updated), Option B (old subdomains as ALB aliases), Option C (301 redirects)

**Spike 7: Kaltura AV Integration on Drupal 11** — PENDING (Individual)
- Must prove D11 Media + custom/contrib Kaltura solution covers both upload/ingest and playback workflows
- Work includes module landscape survey, Kaltura API v3 capabilities, playback path (oEmbed vs. custom MediaSource), upload/ingest workflow, migration path for existing entry IDs, permissions model for single-instance
- Pass criteria: integration path for upload+playback, working embed prototype, upload workflow demonstrated, migration strategy, credential model confirmed

**Spike 8: reindeer_x Consolidation as Managed Sync Subsystem** — PENDING (Individual)
- Lead: Yuji Shinozaki
- Three parts: (A) fold `synch`/`synchandler` shell+Perl into reindeer_x using chokidar + AWS SDK S3; (B) subscribe to SQS events to eliminate UDP trigger race condition; (C stretch) SNS completion reporting
- Pass criteria (A): chokidar file detection reliable, AWS SDK S3 uploads work, no rclone/Perl needed; (B): SQS polling works, message triggers job, no crashes; (C): SNS publishes summaries
- **Not established:** D11 Drupal→reindeer_x HTTP POST, ECS kmterms task completion event publishing, reindeer_x production readiness, cost impact
- **Deferred:** [`docs/deferred/solr-sync-architecture-d11.md`](../deferred/solr-sync-architecture-d11.md), [`docs/deferred/solr-pipeline-cost-discussion.md`](../deferred/solr-pipeline-cost-discussion.md)

---

### Key Takeaways

- **High confidence:** KMaps field type and Solr read integration are proven and guide subsequent work
- **Architectural decisions pending:** Group nesting model (entity reference vs. subgroup module), API compatibility strategy, Kaltura integration approach
- **Content migration blockers:** CKEditor 4 footnote determinism and Tibetan Unicode stability must be proven before full content migration
- **Infrastructure consolidation:** reindeer_x can eliminate shell/Perl dependencies and race conditions with SQS-based triggering
- **Phase 2 work:** API authentication (proxy OAuth), search query implementation, Kaltura upload workflow design</result>
<usage><subagent_tokens>24460</subagent_tokens><tool_uses>9</tool_uses><duration_ms>30060</duration_ms></usage>
</task-notification>

---

## Claude — 2026-06-15 15:53

Both explorers are back. I now have a full picture of the project state. Before I write a plan, I need to know what you want to focus on.

---

## Claude — 2026-06-15 15:55

Good — a roadmap. I have enough from the two explorers to write this directly without another agent. Let me draft the plan.

---

## User — 2026-06-15 15:55

[Request interrupted by user]

---

## User — 2026-06-15 15:55

wait I would like to switch to Opus

---

## Claude — 2026-06-15 15:56

Type `/model` in the prompt to switch — select `claude-opus-4-8` from the list. Once you're on Opus, come back and I'll continue drafting the roadmap plan.

---

## User — 2026-06-15 15:56

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-06-15 15:56

<command-name>/model</command-name>
            <command-message>model</command-message>
            <command-args></command-args>

---

## User — 2026-06-15 15:56

<local-command-stdout>Set model to [1mOpus 4.8[22m and saved as your default for new sessions</local-command-stdout>

---

## User — 2026-06-15 15:56

please restart the assessment of the plan

---

## Claude — 2026-06-15 15:58

Now on Opus. I've re-assessed the full picture from both explorers, and before I commit a roadmap to the plan file I want to surface what the data actually tells me — and settle the one decision that genuinely forks the plan.

## What I see

**Proven (2/8):** KMaps field type (Spike 1) and Solr read (Spike 2) — but both have substantial unfinished tails. Spike 1's migration path, write path, ancestor resolution, and production widget UX are all open. Spike 2's proxy auth for private content is explicitly "blocking."

**The real structure of the remaining work** isn't 6 spikes + 4 deferred items in a flat list. It's three different *kinds* of work:

1. **Cross-cutting foundation** — every site needs it: KMaps field productionization, the Solr *write/sync* path (Spike 8 + the two solr deferred notes), and proxy auth. Nothing ships without these.
2. **Per-site go/no-go gates** — each can *kill or rescope a whole site* if it fails: Spike 4 (Tibetan Unicode/footnotes → Texts), Spike 5 (bibcite → Sources), Spike 7 (Kaltura → AV). These are independent and owned by different people, so they parallelize cleanly.
3. **Cutover gate** — Spike 6 (API URL strategy → React app).

## Two things nobody has named yet

- **There's no migration-tooling spike.** Every per-site gate feeds into an actual D7→D11 data migration (Migrate API: nodes, fields, taxonomy, files, users) that has no spike and no owner. That's the convergence point of the whole project and it's currently invisible.
- **Images has no spike — which makes it the obvious pilot.** It's the lowest-risk site (standard media + KMaps terms). Running Images end-to-end first would exercise the entire foundation (KMaps fields → migration → Solr sync) on the *simplest* content before you bet the hard sites on it.

One external-latency item I'd pull forward regardless: the **Solr pipeline cost/architecture conversation with Dave Goldstein** gates Spike 8's final design. That's human-scheduling latency, so it should start now in parallel with everything else.

The one decision that genuinely changes the ordering:

---

## Claude — 2026-06-15 16:03

What would you like to clarify about the question? I can reframe it, or if there's context I'm missing about how the team wants to approach sequencing, tell me and I'll factor it in.

---

## User — 2026-06-15 16:04

Could you list the choices again.  I would like to discuss them.

---

## Claude — 2026-06-15 16:04

Here are the three sequencing strategies, with the reasoning behind each:

## 1. De-risk the go/no-go gates first

**Do:** Front-load Spikes 4, 5, 7 in parallel — Tibetan Unicode/footnotes (Texts), bibcite (Sources), Kaltura (AV).

**Why:** Each of these can *invalidate a whole site's migration plan*. If Tibetan text corrupts round-tripping through D11, or bibcite can't represent the D7 reference types, or Kaltura entry IDs can't migrate — you've just learned your scope is wrong. Finding that out *now* is cheap. Finding it out after you've built foundation around the assumption is expensive.

**Cost:** You're spending early effort on proofs, not shippable progress. No demo for a while.

## 2. Pilot vertical slice (Images) first

**Do:** Build the cross-cutting foundation (KMaps fields, Solr sync, migration tooling) and prove it end-to-end on Images — the simplest site — before touching the hard ones.

**Why:** Images has no spike because it's low-risk (standard media + KMaps terms). Running it end-to-end forces you to actually build the migration tooling that currently has no owner, and gives you a real, demo-able migrated site early. It validates the *plumbing* against real content.

**Cost:** You're deferring the scary unknowns. If Spike 4 later says "Tibetan doesn't work," your nice Images pilot didn't surface that risk.

## 3. Maximize team parallelism now

**Do:** Hand every owner their independent track immediately — Than → Spikes 4 & 6, Xiaoming → Spike 5, you → Spike 8, someone → Spike 7 — and fit the foundation work around whoever has capacity.

**Why:** Optimizes wall-clock throughput. Nobody waits. Best raw use of a multi-person team.

**Cost:** Less strategic coherence. People may build on foundation that isn't settled yet, and integration risk accumulates because tracks advance independently without a forcing convergence point.

---

The honest tension: **#1 maximizes information, #2 maximizes demonstrable progress, #3 maximizes utilization.** They're not mutually exclusive at the margins — but whichever you name *first* determines what gets the team's prime attention in the next few weeks. Which one matches where the project actually is right now?

---

## User — 2026-06-15 16:09

My inclination is 2.  Early-demonstrable win.  But what is the trade-off?  I have perhaps unfounded confidence that there are solutions to the go/no-go's (I.e. no no-go's).  I also was worried about the migration practice.  From experience, getting migrations going early, helps ease a lot of anxiety down the road.  But I do want to promote 3.   I was hoping to do it by "team" developing (akin to pair programming ---  trio-vibing?) the first few components.   Comments?

---

## Claude — 2026-06-15 16:11

Your instincts are mostly right, and I want to sharpen one distinction because I think you're conflating two different things when you say you want to "promote 3."

## The migration-first instinct is correct — especially *here*

This isn't a generic Drupal migration. You're collapsing five sites into one. That means the migration design itself has to solve problems that have nothing to do with content type complexity:

- Node IDs collide across the five D7 databases
- Taxonomy vocabularies have to merge (and KMaps term references have to survive the merge)
- Users dedupe across sites
- The multisite→single-site identity model has to be reconciled

You cannot discover those problems by reading. You discover them the first time you run Migrate API against real data. So getting a migration *running* early doesn't just "ease anxiety" — for a consolidation it surfaces a whole category of structural decisions that would otherwise ambush you in Phase 3. Your experience is pointing you at the right thing.

## The actual tradeoff of pilot-first

It's **false confidence from easy content.** Images is the right *pilot* precisely because it's simple — but that simplicity is also the trap. Images has no footnotes, no bibliographic entities, no Kaltura embeds, no rich-text Tibetan. So the foundation you "prove" on Images proves the common 60%, not the risky 40%. The danger is you declare the plumbing solid, fork the team onto the hard sites, and *then* Spike 4 reveals that footnote-bearing body fields need the migration mapping restructured.

The cheap insurance: run the go/no-go's as **narrow probes in parallel**, not full builds. You don't need to *solve* Tibetan/bibcite/Kaltura — you need each owner to spend a few days confirming there's no *no-go*. That converts your "perhaps unfounded confidence" into founded confidence for the cost of a few days each, and it inoculates the pilot against the false-confidence problem. So my real recommendation is **2 + cheap-1**, not pure 2.

## On "promoting 3" — you're describing the opposite of 3

This is the distinction I want to make. Option 3 as I framed it was *split people onto independent tracks for throughput* — N people, N tasks. What you're describing — trio-vibing the first components together — is **mob mode: N people, 1 task.** That's not parallelism; it's deliberate *anti*-parallelism. You're choosing to under-utilize in the short term to build shared context.

And that's the right call **for the pilot specifically**, for a reason that matters to your workflow: the foundation (KMaps productionization, migration patterns, Solr sync conventions) is shared infrastructure everyone builds on later. If the team builds it *together*, everyone internalizes the patterns, the naming, and the gotchas firsthand — and your ADR/spike discipline gets full buy-in because the decisions were made in the room. When they later fork onto Texts/Sources/AV, they're not reverse-engineering your conventions from docs.

But mob mode is *wrong* later. Once the foundation and migration pattern are established, mobbing on bibcite while Than sits idle is pure waste — the per-site gates are independent and *that's* where real option-3 parallelism earns its keep.

So the natural arc is: **mob the pilot → fork to parallel tracks.** Shared apprenticeship on the foundation, then spread out. That also gets Conway's law working *for* you — shared mental models baked in before the codebase forks into site-specific work, instead of the sites drifting apart.

## One caution on mob + migration

Mobbing is great for *establishing the pattern* and terrible for *the grind*. So: mob the **first** migration end-to-end — one Images content type, with the testing approach and rollback story — to set the template. Then individuals replicate the established pattern for the remaining types. Don't mob the 40th field mapping.

## The one critical-path item this doesn't escape

Even in pilot-first mode, Images depends on the **Solr write/sync path** (your Spike 8), which is gated on the **Dave Goldstein cost/architecture conversation**. That has human-scheduling latency. So regardless of how we sequence the build, that conversation should start *now*, in parallel — it's the one thing that can quietly stall the pilot.

So the shape I'd commit to the roadmap: **mob-build an Images vertical slice (migration → KMaps → Solr sync) as the spine; run Spikes 4/5/7 as cheap parallel go/no-go probes; open the Dave conversation immediately; fork to parallel site tracks once the pilot establishes the pattern.**

Does that arc match what you're picturing — and is the team actually co-located/available enough to mob the pilot, or is "trio-vibing" more aspirational than schedulable?

---

## User — 2026-06-15 16:25

I totally agree with you.  And the mod->parallel evolution  was exactly what I was getting at, just perhaps I expressed it too glibly.  i.e. I want 3, but not yet: we aren't ready for that.   So its sounding like 2 is the right path, but the spikes are integral to gaining more understanding about the nature of the downstream work, beyond 2.   BTW, the images apt DOES involve tibetan handling, as a large majority of it uses tibetan metadata.  The av apt represents the greatest complexity as it also involves tibetan transcripts.

---

## Claude — 2026-06-15 16:26

That correction materially reshapes the analysis — and, counterintuitively, it makes the case for Images *stronger*, not weaker. Let me work through why.

## Tibetan isn't a Texts gate — it's cross-cutting foundation

I had Spike 4 mentally filed as a Texts-specific go/no-go. Wrong. If Images metadata is majority-Tibetan and AV carries Tibetan transcripts, then **Tibetan Unicode integrity threads through every site.** That means Spike 4 was actually two different concerns I'd wrongly fused:

- **Tibetan Unicode round-trip** → *foundation*. Affects every site, including the pilot. Lives in metadata everywhere.
- **CKEditor 5 footnote markup transformation** → *Texts-specific*. The rich-text-body problem, genuinely independent.

Those should be separated in the roadmap. The Unicode half can't be a parallel probe deferred off to the side — it's load-bearing for the whole migration.

## This makes Images a *better* pilot, not a worse one

My earlier worry about pilot-first was "false confidence from easy content." But Images-with-Tibetan-metadata isn't easy-in-the-wrong-way — it's easy in exactly the *right* way. It's structurally simple (no footnotes, no Kaltura, no bibcite entities) **but it still carries the single most pervasive risk in the project.** So the pilot becomes a clean experiment: it isolates the Tibetan Unicode variable and drives it through the *entire* stack — D7 → Migrate API → MariaDB storage → Solr sync → index — without footnote/media/bibliographic noise confounding the result.

And there's a bonus you get for free: it also tests **Tibetan through Solr's analysis chain.** Remember the Spike 2 pitfalls — `language_field` dropping taxonomy, `names_txt`/`title` being string fields. Tibetan script tokenization/normalization in that index is an open question, and the Images pilot exercises it on real Tibetan metadata. So the pilot retires *two* foundation risks (Unicode storage integrity + Tibetan searchability) on the cheapest representative site. That's the opposite of false confidence — it's the highest-information slice you could pick.

(It also confirms Images over Mandala Home as the pilot: Home is structurally simpler but *unrepresentative* — it wouldn't exercise the KMaps+Tibetan+Solr content model. You want the simplest site that's still representative, and that's Images.)

## AV as last is now overdetermined

AV = Kaltura **+** Tibetan transcripts. That's the convergence of the most risk vectors, so it belongs last — every other risk retired before you touch it. But the transcript detail raises a structural question that changes how I'd scope the spikes:

**What's the nature of the AV transcripts?**
- If they're **plain Tibetan text blocks** → the pilot's Unicode round-trip already covers them, and AV's only novel risk is Kaltura (Spike 7).
- If they're **structured / time-coded / rich-text with markup** → then AV's transcript handling overlaps the *Texts footnote* problem (structured markup + Unicode), and Spike 4's footnote work and Spike 7's transcript work are really the *same underlying spike*: "structured Tibetan rich-text round-trip." That would let Than's footnote spike and the AV transcript work share a proof instead of duplicating it.

That one fact determines whether Spike 4 and the AV transcript piece are one spike or two — which is worth settling before I commit the roadmap.

Second, smaller one: **is Tibetan present in the Sources bibliographic metadata too?** If so, the bibcite spike (Spike 5) needs to explicitly cover Tibetan-bearing reference fields, not just reference *types*.

Tell me about the transcript format and I'll fold both into the sequencing.

---

## User — 2026-06-15 16:36

And it complicates SOLR too. (And this might be out of scope as we have workarounds, and our MVP involves migrating and not improving the solr handling) but search in tibetan is fraught with complications.   e.g. how do you handle searching in fields with latin and tibetan script?   BTW, I might have been mistaken about the images metadata containing lots of tibetan script.   It looks like most of the tibetan is latin-encoded transliterations.

---

## Claude — 2026-06-15 16:38

Good — and notice the pattern: each correction *relocates* a risk rather than reversing the conclusion. Images-as-pilot has now survived two corrections; only the risk map underneath it keeps sharpening. Let me lock down where things actually stand, because the Latin-transliteration point matters more than it looks.

## Where the Tibetan risk actually lives (final map)

- **True Tibetan *Unicode script*** → concentrated in **Texts bodies and AV transcripts**, *not* pervasive. So it's a per-site (Texts/AV) concern, which swings back toward my original framing.
- **Latin transliteration** (EWTS/Wylie, the bulk of Images metadata) → looks like "just ASCII," but it isn't free of Unicode risk: Extended Wylie carries diacritics (ḍ, ṇ, ś, ṭ…) that are non-ASCII. The silent killer there is **normalization form** — if the D7 data is NFC and Migrate API + MySQL collation + Solr round-trip it as NFD (or vice versa), your transliterations mutate invisibly and never compare equal again. So even "mostly Latin" Images still earns a real fidelity check; it just isn't the *script-integrity* check.

So the corrected pilot rationale: Images is the **clean plumbing proof** — migration consolidation + KMaps + Solr sync + transliteration-normalization fidelity — and it deliberately does **not** retire the Tibetan-*script* risk. That stays in Texts/AV. The discipline that buys us: **a green Images pilot must not be read as confidence about Texts or AV.** That's my original "false confidence" caution, now aimed precisely at the one risk that genuinely doesn't transfer.

## Scope boundary — I want to nail this down

You're right to fence it, and it's worth making explicit because it's exactly where a pilot quietly balloons:

- **In scope (MVP):** *faithful* migration. Tibetan in = identical Tibetan out; transliteration + diacritics preserved at the correct normalization form; content indexes and is **retrievable via existing query patterns**.
- **Out of scope (MVP):** *improving* Tibetan search — mixed Latin/Tibetan field handling, transliteration diacritic folding, Tibetan tokenization/analysis. Workarounds exist, and this already sits behind ADR 004 (Solr as source of truth, defer the refactor).

The trap to flag for the team: "docs land in Solr" (MVP, ✓) is **not** "Tibetan search works well" (deferred). The pilot's Solr success criterion has to be written narrowly or someone *will* wander into the analysis-chain rabbit hole.

## One open question I'll carry rather than block on

I still don't know the **AV transcript format** (plain text vs. structured/time-coded/rich markup). That decides whether Spike 4 (footnotes) and the AV transcript work are *one* spike — "structured Tibetan rich-text round-trip" — or two. It only affects Phase-3 scoping, so I'll record it as a pre-spike decision rather than hold up the roadmap.

I'm convinced enough to commit this to the plan file now — the migrate-not-improve scope, the Images pilot spine, mob→parallel, AV last, and the spike re-scoping. Let me write it up for you to react to.

---

## Claude — 2026-06-15 16:38

The roadmap is written to the plan file. It captures everything we worked through: the migrate-not-improve scope boundary, the corrected Tibetan risk map (script in Texts/AV, transliteration-normalization fidelity in Images), the Images pilot spine built mob-style, the fork to parallel tracks, AV last, and the open AV-transcript question carried as a pre-Phase-3 decision. The concrete output is doc artifacts — a roadmap doc, a deferred note, spike re-scoping, and two decisions flagged for team-ratified ADRs (not written unilaterally, per your ADR discipline).

---

## User — 2026-06-15 16:46

[Request interrupted by user for tool use]

---

## User — 2026-06-15 16:46

About the proxy auth.  Images actually has lots of content subject to the proxy auth.

---

## Claude — 2026-06-15 16:48

This is a bigger correction than the Tibetan ones. Those *relocated* a risk; this one **changes the pilot's critical path.** Proxy auth moves from an optional "only if" footnote to a load-bearing Phase 1 requirement — and that's worth thinking through carefully before I touch the plan.

## What it changes

Spike 2 already flagged proxy-auth-for-private-content as **"blocking."** So this isn't newly discovered work — it's a known blocker that the Images choice now *forces into the pilot* instead of letting us defer it. Two consequences:

1. **Good news, oddly:** auth is exactly the kind of hard, cross-cutting foundation problem you want proven *early*. Discovering an auth integration wall on the pilot beats discovering it after three sites are migrated. So Images-with-auth de-risks more than Images-without.
2. **Bad news:** it undercuts "Images = the *simplest* clean plumbing proof." The pilot now carries a security-sensitive integration, which raises its risk and could stall the early-win you wanted.

## Three things I want baked in regardless of how we structure it

- **Reuse, don't redesign.** Wire D11 into the *existing* proxy auth contract — don't invent a new one. That's migrate-not-improve, and it's consistent with ADR 004. The Spike 2 deferred item is an *integration* task, not an auth redesign.
- **A hard security verification criterion.** A restricted Images item must be **non-retrievable** by an unauthorized user through the D11 search path, and retrievable by an authorized one. Getting this wrong leaks restricted content — so it's a named pass/fail, not a "looks fine."
- **Two access-control layers must agree.** Solr-proxy visibility filtering and Group collection/subcollection access (Spike 3 + the access-inheritance deferred note) are *different mechanisms answering the same question* — "who can see what." A user's search-visible results and their node-level access have to be coherent, or you get confusing leaks/hides. Flagging it as an integration concern now so it isn't a surprise.

## The structural fork this opens

The decision-relevant question is how to sequence auth *within* the pilot — and that hinges on your domain knowledge about Images' public/private split. Let me update the plan to promote proxy auth into Phase 1, then put the fork to you.

---

## Claude — 2026-06-15 16:48

Plan updated — proxy auth is now a required Phase 1 foundation element with the security criterion and the cross-layer coherence note. Here's the fork I need your call on:

---

## Claude — 2026-06-15 16:55

Locked in. Let me reflect the decision in the plan and finalize.

---

## Claude — 2026-06-15 16:55

The roadmap is complete and reflects everything we worked through:

- **MVP scope boundary** — migrate, don't improve; Tibetan/Solr search quality explicitly deferred behind ADR 004
- **Corrected Tibetan risk map** — script in Texts/AV, transliteration-normalization fidelity in Images
- **Images pilot as a two-step vertical slice** — public plumbing first (early win), then the proxy-auth increment with a hard security pass/fail
- **Access-control coherence** flagged across the Solr-proxy and Group layers
- **Mob → parallel** team evolution, **AV last**, and the **AV-transcript-format** question carried as a pre-Phase-3 decision

The implementable output is doc artifacts — a `docs/roadmap.md`, a deferred note for Tibetan search quality, spike re-scoping, two decisions flagged for team-ratified ADRs, and the `.pages` updates so they render in mkdocs.

---

## Claude — 2026-06-15 17:51

Plan approved. Let me set up task tracking and read the existing docs to match the team's conventions before writing anything.

---

## Claude — 2026-06-15 17:52

Conventions are clear. Now I'll produce the artifacts. Starting with the roadmap doc.

---

## Claude — 2026-06-15 17:53

Now update the deferred README's open-items table, then re-scope Spike 4.

---

## Claude — 2026-06-15 17:54

Now the `.pages` nav updates — top-level for the roadmap, and the deferred list.

---

## Claude — 2026-06-15 17:54

All artifacts are in place. Quick check of what changed:

---

## Claude — 2026-06-15 17:54

Done. Here's what landed, all matching the team's existing doc conventions:

**New files**
- `docs/roadmap.md` — the phased roadmap (MVP scope boundary, Tibetan risk map, Phase 0–3, two-step Images pilot with the security verification, the AV-transcript open question, and the two decisions flagged for team-ratified ADRs). Marked **Status: Proposed — for team review**, and explicit that it *sequences* spikes/ADRs rather than superseding them.
- `docs/deferred/tibetan-search-quality.md` — the out-of-scope search work captured behind ADR 004, with the migrate-vs-improve distinction spelled out so it doesn't get pulled into the pilot.

**Edited files**
- `docs/spikes/spike-04-ckeditor5-footnotes.md` — added a scope note splitting the Unicode-round-trip concern (cross-cutting) from footnote markup (Texts), carried the AV-transcript open question, and added the transliteration normalization-form (NFC/NFD) check to the pass criteria.
- `docs/deferred/README.md` — added the new deferred note to the open-items table.
- `docs/.pages` — added a top-level **Roadmap** nav entry after Home.
- `docs/deferred/.pages` — listed the new deferred note so it renders in mkdocs.

Two things I deliberately did **not** do, because they're yours/the team's to make, not mine:
1. **Write the two ADRs.** Per your "ADRs are accepted collaboratively / immutable once accepted" rule, I flagged them as proposed in the roadmap rather than writing accepted ADRs unilaterally.
2. **Renumber/split Spike 4 into two formal spikes.** That depends on the AV-transcript-format answer and is a structural change worth team ratification — so I noted it in the spike doc instead.

A couple of follow-ups worth noting for your session-end ritual: the roadmap leaves several **owners as TBD** (Kaltura probe, Collections track), and the CLAUDE.md ritual suggests running `scripts/save-session-log.py` for a planning session like this one. Want me to run the session-log script?

---

## User — 2026-06-15 18:05

Go ahead and save this session

---

---

## Addendum — 2026-06-15 (follow-on session): ADR ratification

Continuation after team discussion of the proposed roadmap. The two decisions the
roadmap flagged for ratification were accepted collaboratively and written up as ADRs.

**Decisions ratified**
- **ADR 008 — MVP is migrate, not improve** ([008-mvp-migrate-not-improve.md](../adr/008-mvp-migrate-not-improve.md)).
  Faithful migration in scope; Tibetan/Solr search-quality improvement deferred.
  Carries the in/out-of-scope boundary table, the NFC/NFD normalization-fidelity
  requirement, and the Spike 2 query-layer constraints (`language_field` disabled;
  `title`/`names_txt` are string fields needing prefix wildcards). Relates to ADR 004.
- **ADR 009 — Migration sequencing strategy** ([009-migration-sequencing-strategy.md](../adr/009-migration-sequencing-strategy.md)).
  Phase 0 parallel start → Images two-step pilot (1a public plumbing, 1b auth
  increment) mob-built → fork to parallel site tracks → AV last; Spike 6 as the
  cutover gate. Notes the access-control coherence concern and the open AV-transcript
  question. Relates to ADR 005 and ADR 008.

**Doc wiring (session-end ritual)**
- Added rows 008/009 to `adr/README.md` and entries to `adr/.pages`.
- Flipped `roadmap.md` status from *Proposed — for team review* to *Accepted*,
  referencing both ADRs; updated its *Decisions to ratify* section to point at them.

**Delivery**
- Commit `56bd339` on branch `docs/migration-roadmap`, pushed.
- PR [#3](https://github.com/uvalib/mandala-navina/pull/3) opened against `main`
  (covers the roadmap commit + the ratification commit).

**Open follow-ups carried forward**
- Several owners still TBD (Kaltura probe; Collections track).
- Phase 0 human-latency item: open the Solr cost/architecture conversation with Dave
  Goldstein (gates Spike 8 design).
- AV-transcript format triage still resolves the one-spike-vs-two question for Spike 4.

---
