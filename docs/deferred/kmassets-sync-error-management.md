# kmassets Sync Error-Management & Resubmit Automation
**Area:** solr / kmassets / sync / observability / DevOps tooling
**Raised during:** Planning session 2026-06-26 (write-mechanism deep-dive)
**Jira:** (add when available)
**Priority:** Medium — **out of Sprint 1 scope; candidate for its own downstream sprint**

## Scope boundary

**This is explicitly NOT Sprint 1 work.** Sprint 1 (task 1a.8) needs only the basic
two-sink writer described in
[kmasset-solr-doc-contract.md §3](../planning/kmasset-solr-doc-contract.md): a file
sink (→ S3 → Dave Goldstein's batch poster, the authoritative path) and a
direct-to-master sink (incremental day-to-day updates + a manual diagnostic loop).
That's enough to migrate Images and keep them in sync.

Everything below is the **automation/tooling layer on top of that** — a managed
error-and-resubmit capability. It is broken out here so it doesn't bloat the sprint
or the contract, and may well become **its own sprint** once the implementation phase
needs it.

## The problem it solves

Dave's mechanism is a small-batch (~32-doc) **atomic** S3 poster: one bad doc fails
the whole batch, and the failure surfaces **only in CloudWatch logs**, which today
must be located and read by hand — involved and taxing. Because the batch aborts at
the first failure, a cited doc also **masks later bad docs** in the same batch, so
fixing one and resubmitting can fail again on the next, round-trip by round-trip.

The direct sink is the manual workaround (synchronous, exact errors). This capability
automates the whole loop.

## The envisioned capability

1. **Normalize batch errors out of CloudWatch.** Pull failing-batch errors from
   CloudWatch and turn them into the **same error representation** the direct sink
   produces. The synchronous direct-sink error becomes the **canonical shape**; batch
   errors are adapted to it.

2. **One error-management surface.** A single failure queue and one fix-and-resubmit
   workflow, regardless of which courier (batch vs. direct) produced the error.

3. **Managed resubmit.** From one interface, re-landing a fixed doc is "rewrite the
   file / stage a regen directory" (batch path) or "direct-POST" (fast path) — the
   operator/automation triggers it the same way.

4. **Provenance as first-class metadata (not hidden).** Every queued failure carries
   highly visible metadata: its **source channel** (batch vs. direct) and, for batch
   failures, **which batch** it came from (ideally with sibling members). This
   provenance drives the resubmit *decision* — re-land one doc, or regenerate the
   whole (atomic) batch.

5. **Batch de-masking via direct replay.** Use the batch-membership metadata to
   replay the **entire batch doc-by-doc through the direct sink**. Because direct
   submits are per-doc and non-atomic, this surfaces **every** failure with its own
   exact error in one pass — a complete failure list up front instead of one masked
   failure at a time.

6. **Systemic-error guard (critical).** The dark side of de-masking: a *systemic*
   cause — a field/schema change, a producer bug — can fail nearly every doc. We must
   **not** dump ~100,000 near-identical errors into the queue, nor naively
   direct-replay them against the master. The surface must:
   - **group by error signature** — collapse one root cause into a single actionable
     item ("N docs failed with X"), not N rows; and
   - apply a **threshold / circuit-breaker** — halt and alert on a mass-failure spike
     instead of enqueuing/replaying everything.

   When the cause is systemic, the answer is **one fix + one regenerate**, not 100k
   queue items.

## Relationship to existing work

- This is the natural home for the **observability/reporting** ideas already sketched
  in [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) (SNS
  completion/failure events) and [Spike 8 Part C](../spikes/spike-08-reindeer-x-consolidation.md)
  (SNS sync reporting). Those should fold into this capability rather than be built
  separately.
- Depends on the basic two-sink writer (1a.8) existing first.
- Several details remain **open with Dave** (failure-log reliability/format,
  whether failed batch members are preserved, batch cadence) — see the "Open with
  Dave" list in the contract §3.

## When to start

After Sprint 1, once day-to-day editing volume makes the manual CloudWatch loop a
real operational drag. Strong candidate to be scoped as its own sprint.
