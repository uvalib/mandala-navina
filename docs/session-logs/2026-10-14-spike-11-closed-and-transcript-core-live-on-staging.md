# Session Log: Spike 11 closed; the transcript Solr core is live on staging and verified through the proxy

**Date:** 2026-10-14
**Participants:** Yuji Shinozaki (driving); Than Grove and Xiaoming Wang in the group meeting for the Spike 11 decisions; Claude Code. Hand-written summary, not a transcript. No credentials or privately-tracked material are recorded here.
**Outcome:** Spike 11 closed with Than's approval, all four open decisions recorded. #327 done and closed: `mandala-av-transcripts` exists on the staging Solr master and replica, the proxy join filter (#315) is merged and live on dev-0, and the visibility check passes end to end. The solr-proxy image build, broken by an end-of-life Debian base, is fixed with a stopgap (#335).

## What happened

1. **Orientation.** Session-start check clean (dev-0 unreachable at first, no VPN). Agenda from the 2026-10-13 pick-up list; a meeting with Than and Xiaoming set the Spike 11 questions.
2. **#327 prep.** Wrote `scripts/load-transcript-test-units.py` (synthetic units and kmassets parents in the reserved trid range 990000-990999; dry run by default; `--cleanup`; `--replica` times lag) and `--transcripts` for `verify-solr-proxy-visibility.py` (sections 7-8). Both tested only against the real system later in the session.
3. **How cores get created.** From `solr-shanti-configsets` history: the 2022 `mandala-*` cores were added by committing a core directory (`core.properties` plus `conf/`) and deploying it. No CoreAdmin call is recorded. The D7 `apachesolr_environment` table shows D7 wrote its transcripts straight to the production Solr master (core `mandala-av`); its second environment is a dead hosted Solr whose hostname no longer resolves (PR #331).
4. **A bug found before deploying.** The new core's replication switches (`solr.replication.*`) are not what the deployed nodes set (`enable.master`, `enable.slave`, `master.url`), so replication would have stayed off. Fixed and re-checked on a throwaway Solr 7.7.3 (#330).
5. **Spike 11 decisions** (PR #333, merged): titles in results are looked up from Drupal at render time; target Solr 7.7.3, the latest 7.x; the unproven items (join cost, replication, compatibility diff, new-core loading) are carried into Sprint 4, with join cost a go/no-go checkpoint whose threshold the team sets later; transcript editors and content editors are the same people per node, and the `keep_transcript_edits` flag is kept visible. Than approved closing the spike and all of these.
6. **Core on staging.** Merged configsets #33; before the deploy, every existing staging core's live config was byte-identical to the repo, and a kmassets baseline was taken. The team's TeamCity deploy restarted the whole master and the new core loaded (15 fields, five per-language text types, no init failures). kmassets differed only in `startTime`. The replica needed its own config deploy and restart: replication cannot create a core. Its service-discovery DNS name was missing for several minutes after the restart, then returned.
7. **Replica lag measured:** 1.1, 23.2, 22.2, 23.2 s, so about 22-23 s with a 30 s poll (PR #334).
8. **Join filter live.** Merged #315, but the solr-proxy build then failed: `php:7.4-apache` is Debian 11, whose packages left the main mirror (404s on `apt-get install`). Not caused by #315. Fixed with a stopgap pointing apt at `archive.debian.org` (#335, tested with a local build and run). Triggered the drupal pipeline by hand (the legitimate manual case: a solr-proxy-only merge never starts it); the deploy succeeded and the new proxy image is on dev-0. Moving to a supported base is tracked in #336.
9. **End-to-end check on dev-0** with synthetic data written to the new core and three kmassets parent documents: all 10 transcript checks pass (public visible to all; a private transcript visible only to uid 600 in its collection; another collection's hidden from both; a unit with no parent hidden). The test data was removed; kmassets is back to 771,881 documents. The verify script's old "mine" fixture had become public; refreshed, and the script passes 12/12.
10. **Merged:** #331, #333, #315, #335, #330, #334, #337, and configsets #33. #327 closed. #290 commented.

## Findings worth keeping

- A new core directory loads on a Solr restart. The staging deploy restarts the whole master.
- A new core also needs the replica config deploy; replication copies into an existing core only.
- The staging Solr is what dev-0 reads and writes; "dev Solr" in the issues means this.
- A merge that touches only `solr-proxy/**` runs the build-only pipeline; reaching dev-0 needs the drupal pipeline started by hand, and only after the build is green.
- A 504 from GitHub on a merge does not mean the merge failed: check the PR state before retrying.

## State at end of session

- **Done:** Spike 11 closed; #327 closed; #315, #330, #331, #333, #334, #335, #337 and configsets #33 merged.
- **Open, new:** [#336](https://github.com/uvalib/mandala-navina/issues/336) move solr-proxy to a supported base image (unassigned, free to pick up).
- **Open, carried:** #290 (writer waits on the T2 transcript entity; join cost is the go/no-go checkpoint); #300 (unassigned); mandala_drupal_docker#26 and #27; #316 leftovers; the local DB rebase and dev-0 `simplesamlphp_auth.settings` noted on 2026-10-13, neither investigated.
- **Not done:** Solr 9 copy of the core; production creation; join cost at real scale.

## Pick up next

1. **T2: build the transcript entity** (Sprint 4), then the `trid_i` writer for #290(a); then measure join cost on dev (threshold to be set by the team first).
2. [#336](https://github.com/uvalib/mandala-navina/issues/336): move solr-proxy off Debian 11 / PHP 7.4, ahead of other non-urgent proxy changes and before production cutover.
3. Decide who owns mandala_drupal_docker#26 and whether the legacy sync is revived or replaced by the D11 writer (needs Dave and Than).
4. #300 unassigned pickup; #316 leftovers.
