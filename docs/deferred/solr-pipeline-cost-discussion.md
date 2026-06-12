# Solr Ingest Pipeline Cost & Architecture Discussion with Cloud Infrastructure
**Area:** solr / infrastructure / kmassets
**Raised during:** Session 2026-06-12
**Jira:** (add when available)
**Priority:** High
**Owner:** Yuji Shinozaki + Dave Goldstein

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
