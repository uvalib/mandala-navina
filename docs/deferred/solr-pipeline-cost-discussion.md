# Solr Ingest Pipeline Cost & Architecture Discussion with Cloud Infrastructure
**Area:** solr / infrastructure / kmassets
**Raised during:** Session 2026-06-12
**Jira:** (add when available)
**Priority:** High. **ASSIGNED directly to Yuji, 2026-09-02 (group decision)** — the original
cost/architecture question is largely moot (see the 2026-06-26 update below: no always-on ECS
to right-size). What remains is closing the loop with Dave on the still-open items in
`kmasset-solr-doc-contract.md` §3 (direct-to-master access/credentials, second-writer
acceptability, batch cadence, regen-directory support, failure-log reliability, timestamp
keying) — notably, the direct-to-master sink already **shipped** in 1a.8 ahead of confirming
those, so this needs following up on, not opening fresh.
**Owner:** Yuji Shinozaki (point of contact with Dave Goldstein)

## Context

The AWS ECS ingest pipeline (S3 → SQS → ECS transform/update tasks) was set up by
Dave Goldstein's team following the UVA Library pattern for large Solr indexes. It
handles both kmassets and kmterms ingest. However, the pipeline was sized for
high-throughput indexes — Drupal content saves are low-frequency by comparison —
and the cost may be disproportionate to the workload.

See [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) for the full
architecture context.

## Questions for Dave Goldstein

1. **Cost breakdown:** How is the current pipeline priced? Which components dominate
   — always-on ECS tasks, SQS message volume, S3 operations, or data transfer?

2. **Invocation frequency:** What is the actual invocation frequency of the kmassets
   ECS tasks? How does it compare to other indexes the team manages?

3. **Lighter-weight patterns:** Is there a cheaper pattern for low-frequency,
   latency-tolerant updates? Options to discuss:
   - Lambda triggered by S3 instead of always-on ECS tasks
   - Scheduled ECS task (periodic batch) instead of event-driven per-document
   - Drupal writing directly to Solr (bypassing ECS entirely for Drupal content)

4. **D11 design point:** For D11, is the ECS pipeline the right home for Drupal
   content assets, or should Drupal own its own Solr writes? The answer affects
   both cost and the visibility/debuggability story (see below).

## The visibility problem

A content editor saves a node and it doesn't appear in search. The current pipeline
gives Drupal no feedback — the write is fire-and-forget across 4+ async hops. Any
design for D11 should give operators a way to answer "what happened to this node?"

## Proposed direction (pending conversation)

- **Drupal content assets**: Drupal queue worker → HTTP POST to reindeer_x →
  reindeer_x handles S3 upload and reports outcome. Or: Drupal queue worker →
  direct Solr write (bypasses ECS entirely). Decision pending Dave conversation.
- **kmterms-derived assets** (subjects/places/terms): reindeer_x subscribes to
  SQS completion event from ECS kmterms Solr update task. Race condition fixed,
  no ECS changes needed beyond adding the completion notification.

This split aligns pipeline complexity with actual workload and restores visibility
where it matters most. See [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md)
for the full reindeer_x event-driven architecture proposal.

## Current understanding (2026-06-26) — discussion ongoing

An initial conversation reframed the picture, but **the discussion with Dave is
still open** — he may surface new constraints about the mechanism (especially the
direct-to-master sink). This is a working model, not a settled decision.

**There is no ECS "transform"** — the producer writes the *complete, final* add-doc
and Dave's mechanism is a **small-batch (~32-doc) S3 poster** that POSTs docs to the
master unchanged. Operational facts so far:

- Batches are **atomic** (one bad doc fails the batch; whole-batch regenerate is the
  remedy), with **cited-failure logs** (culprit named, though it can mask later
  failures in the same batch).
- Change detection is **object-level** — **rewrite/rename a file** to force a doc,
  or stage a **"regen directory"** to force a larger set. No need to match Dave's
  internal timestamp.
- **Reconciliation bookkeeping is ours**, but light — Drupal's changed-timestamps
  mostly cover "what needs (re)writing."

**Direction (working):** keep bulk + authoritative writes on the **S3 batch** path
(Dave owns it); add a **direct-to-master** sink for **incremental day-to-day**
updates **and** as the fast diagnostic loop for batch failures (synchronous error,
de-masks serial failures, tests systematic fixes). Both emit the identical contract
doc. The original cost worry recedes — there's no always-on ECS to right-size — but
that's contingent on the mechanism as currently understood.

**Still open with Dave** (see the list in
[kmasset-solr-doc-contract.md §3](../planning/kmasset-solr-doc-contract.md)):
direct-to-master access/credentials + whether a second writer is acceptable; batch
cadence; regen-directory support; failure-log reliability; which timestamp he keys
on. Full write-up in that §3.
