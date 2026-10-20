# Session Log: T2 Transcript Entities, Phases 2-6

**Date:** 2026-10-16  
**Participants:** Yuji Shinozaki, Claude Code  
**Outcome:** Sprint 4 T2 phases 2-6 are built and tested. Phase 2 (`av_tcu`, #345) is merged to `main`. Phases 3-6 (#346, #347, #349, #348) are merged into the branch `integration/t2-transcripts`, which is open as PR #350 against `main` and **not merged**: nobody else can review before Monday 2026-10-19. See the [T2 plan](../planning/t2-transcript-entities-plan.md).

This is an abridged, hand-written log, not the raw transcript.

### Pick-up list

- **Review and merge #350** (merge commit, to keep the phase history). Review phase 3 first: `src/Access/*` and `AvTranscriptAccessTest`. Merging deploys to dev-0 and runs `mandala_av_transcripts_update_10001` via `updb`; no data is created. Afterwards delete `integration/t2-transcripts` and the phase branches.
- **Monday agenda:** the "For review Monday 2026-10-19" section of the plan lists the open items: timecode repair policy (needs Than or AV staff), untimed units, the one negative start, transcript deletion not cascading to units, deleted-unit history, and the four original plan questions.
- **Phase 7 is gated on T5** (the migration). Do not start it before then.
- **T5 notes:** wrap the migration in `TranscriptChangeNotifier::suppressed()`; round D7 floats to 3 decimals before classifying; set D7 `trid` and `tcuid` explicitly; transcript-level events are not coalesced outside `drush migrate:import`, so a custom batch would reindex each node.
- **Not yet tested:** an editor updating a transcript inside a collection (depends on Group grants).

### What was built

- **Phase 2:** `av_tcu` entity with per-unit revisions, indexes on (transcript, sequence) and (transcript, start), id floor 2,000,000.
- **Phase 3:** access handlers. View follows node view; every other operation follows node update, so unknown operations fail closed. Superuser is not an outsider; the shared test fixture had treated uid 1 as one and was fixed.
- **Phase 4:** access-checked read service; a denial looks the same as "no such transcript".
- **Phase 5:** change events plus a kmassets reindex subscriber. A throwing subscriber is caught and logged, because it runs inside the save transaction.
- **Phase 6:** timecode classifier, a pure labelling function. Checked against an independent SQL oracle over all D7 units with zero mismatches.
- **Integration branch:** the phases were stacked on one branch so there is one review, one deploy and one `updb`.
