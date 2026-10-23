# Session Log: Spike 5 Zotero Dormancy Check

**Date:** 2026-10-23  
**Participants:** Than Grove, Claude Code (solo)  
**Outcome:** Spike 5 Zotero feed checked against the 2026-06-11 Sources dump: dormant since 2014-2015, so criterion 4 reduces to preserving imported fields and criterion 5 is dropped pending one confirmation. See [Spike 5, finding 3](../spikes/spike-05-bibcite-sources.md).

## Handoff: pick up at the start of the next session

Spike 5 is still ◐ Partial. Next steps, in order:

1. **Confirm dormancy with Sources staff** ("does anyone still sync from Zotero?"). Until then criterion 5 is dropped *pending* confirmation. Optionally look at the feed's settings (module config or the feed node's revisions); they likely hold credentials, so read deliberately.
2. **Install `drupal/bibcite` locally in DDEV.** Config changes must come from a live Drupal export, never hand-edited; expect a `core.extension` change and new composer packages.
3. **Verify the CSL mapping UI accepts a custom reference type id** (the config-only conclusion for Review/Dictionary/Block Print/Obituary rests on source reading only).
4. **Compare bibcite per-type field sets** against D7 `biblio_field_type_data`.
5. **Criterion 6 output comparison:** import the prepared CSE configs from `docs/spikes/assets/spike-05-bibcite/`, render nid 25581 etc., diff against `d7-citation-baseline-nid25581.json`.

Notes: the dump is `~/Sandbox/Mandala/data/mandala-prod-sources-db_2026-06-11.sql.gz` (no `mysql` client installed; use `gzip -dc`, macOS `zcat` wants `.Z`). The spike doc's criterion 3 text still reads as an unresolved gap although the CSE configs are prepared; refresh when next editing. Open PR #378 (PHP 8.4 / core 11.4.11) was left untouched this session.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-23 14:00

do a session start ritual

---

## Claude — 2026-10-23 14:02

Session start is done, and all the automated checks passed.

**Git and config**
- `main` is clean and up to date with origin.
- Local `config:status` is clean. The only differences are `core.extension` and `stage_file_proxy.settings`, which come from the dev-only `stage_file_proxy` module the script enabled locally. That's expected.
- `drupal/config/sync` matches `origin/main`, and nothing is uncommitted.
- A sample of 25 legacy-identity pairs has the same node ids locally and on dev-0.

**Content counts, local vs dev-0**
Local is slightly ahead: 7 more `group_relationship` rows, 1 more group and 1 more video. This is just local scratch content, so it doesn't block anything.

**Open PR**
- **#378** moves to PHP 8.4 on the official `drupal:11-php8.4` base, with Drupal core 11.4.11. It was opened 10-22 on branch `fix/php-8.4-official-base-core-11.4.11`. It touches `drupal/**`, so merging it triggers a real deploy and container restart. Before merging, check that no long-running job is active on dev-0.

**Last session (10-22)**
- Transcript timecode repair (#353) was marked as post-migration cleanup that blocks nothing. That work is merged via #376 and #377.
- The last log entry shows a session log was requested, but I haven't checked whether it was committed. `docs/session-logs/2026-10-22-transcript-repair-dependencies-and-post-migration-scope.md` exists on `main`.

**Deferred issues**
There are 12 open. Three have no assignee, so they're free to pick up:
- **#357** — transcript orphans: admin screen, re-attach and editor access.
- **#336** — solr-proxy runs on an end-of-life base image (PHP 7.4 / Debian 11).
- **#300** — periodic review of doc-only deferrals.

#336 may overlap with the PHP 8.4 work in #378. #353 and #289 are the ones most recently touched.

I haven't read the ADR, spike or deferred READMEs in full. What are we working on today?

---

## User — 2026-10-23 17:40

Do I have any open spikes?

---

## Claude — 2026-10-23 17:40

You have one open spike under your name: **Spike 5, bibcite for Sources**. Its status is ◐ Partial, and it was started 2026-08-24.

- **Met so far:** bibcite 3.1.2 is stable on Drupal 11, and reference-type coverage is 94.2%.
- **Type gap:** the 4 custom types you want kept (Review, Dictionary, Obituary, Block Print) are config-only to add.
- **Style gap:** Sources uses the `cse` style, which bibcite doesn't ship. You'd import a CSE CSL file, but the output won't be byte-identical to D7.
- **Zotero:** there is only one `zotero_feed` node, so the criterion was reframed. Whether that feed is live is still open.
- **Not started:** credentials and the output comparison. Nothing is installed yet.

Your other spikes are all closed: 3, 4a, 4b, 6, 11 and 12.

Two spikes open in the table belong to Yuji:
- **Spike 8**, reindeer_x consolidation, is ◐ Partial.
- **Spike 9**, docs hosting and Confluence, is ○ Pending and low priority.

Spike 11 closed on 10-14, but its follow-on work continues as issue #290 (the T9 `trid_i` writer and join-based access filter).

---

## User — 2026-10-23 17:40

let's pick up Spike 5. Load your memory on this and let me know what it involves

---

## Claude — 2026-10-23 17:40

Spike 5 asks whether `drupal/bibcite` can replace D7 `biblio` on the Sources site (25,627 biblio nodes). PR #140 merged on 2026-08-24, so it isn't an open branch. All the findings so far come from package metadata and a 2026-06-11 production dump. Nothing is installed on D11.

**Pass criteria**

| # | Criterion | Status |
|---|---|---|
| 1 | Stable D11 release | **Pass.** bibcite 3.1.2, core `^10.1 \|\| ^11 \|\| ^12`. |
| 2 | Reference types covered | **Pass.** bibcite ships biblio's own type list, so 26 of 36 types match 1:1, which is 94.2% of rows. The four custom types you want kept (Review, Dictionary, Block Print, Obituary) are config-only to add, each with a CSL target. The three authorship-distinction types collapse into `book`. |
| 3 | Citation styles | **Prepared, not tested.** Sources uses `cse`, which bibcite doesn't ship. Two CSE CSL configs (9th and 8th edition) plus a D7 baseline for nid 25581 are in `docs/spikes/assets/spike-05-bibcite/`. They're deliberately not in `config/sync`, because `cim` would fail with bibcite not installed. |
| 4 | Zotero feed approach | **Reframed.** There is exactly one `zotero_feed` node. The imported Zotero data is large (about 12.8k tag rows and 6.2k collection rows). |
| 5 | Zotero credentials | **Not started.** It falls away if the one feed is dormant. |
| 6 | Citation output comparable | **Not started.** D7 biblio styles are PHP plugins and bibcite is CSL-only, so output won't be byte-identical. Whether the difference is acceptable is a stakeholder call. |

**Remaining work, in the order I'd do it**
1. **Is the one `zotero_feed` node still syncing?** This is desk work and the cheapest way to move forward. If it's dormant, criterion 4 reduces to preserving the imported field data and criterion 5 drops out.
2. **Install bibcite locally in DDEV.** This unblocks everything else.
3. **Check that the CSL mapping UI accepts a custom reference type id.** The "config-only" conclusion rests on this, and so far it's only from reading source.
4. **Compare field sets.** Compare bibcite's per-type fields against D7 `biblio_field_type_data`. Matching type names don't prove matching fields.
5. **Output comparison.** Import the CSE style, render nid 25581 and the other sampled nodes, and diff the results against the D7 baseline.

**Things to know**
- The spike doc's own text on criterion 3 still reads as an unresolved gap. The memory and the prepared configs show it as prepared, so the doc needs a refresh when you next touch it.
- The citation route is `/sources-api/ajax/{nid}/cite/{style}`. It serves at least 8 styles, and you decided to defer harvard, vancouver and ieee until something requests them.
- Counts are about four and a half months stale, so refresh them if the numbers matter.
- The installed bibcite will add composer packages and a `core.extension` change. Per the config rules, that needs a live Drupal export and shouldn't be hand-edited.

Where do you want to start? I'd suggest the Zotero feed check, then the install.

---

## User — 2026-10-23 17:41

What is CSE and CSL?

---

## Claude — 2026-10-23 17:42

**CSE** is the Council of Science Editors citation style. It's a standard format for citing sources in the sciences, and it comes in a few variants. Two are relevant here:
- **Name-year:** an in-text citation looks like (Smith 2019).
- **Citation-sequence:** citations are numbered in order of appearance.

The Sources site runs D7 `biblio` with `biblio_style = "cse"`, so every citation it generates follows this format. The spike prepared the name-year variants, in 9th and 8th editions.

**CSL** is the Citation Style Language. It's an open XML format for describing a citation style, such as how to order authors, when to italicize a title and how to punctuate. A CSL file is a recipe, and a citation processor reads it and formats a reference. The Zotero/CSL style repository holds thousands of these files, including CSE. Zotero, Mendeley and bibcite all use CSL.

CSL also defines a fixed vocabulary of reference types, such as `book`, `review-book`, `entry-dictionary` and `manuscript`. Each bibcite reference type is mapped to one of those, and that's how the style knows how to format an entry.

**Why they matter for this spike:**
- D7 `biblio` implemented each style as PHP code. bibcite only accepts CSL files.
- Moving to bibcite therefore means substituting one engine's rendering of "CSE" for another's, so the output won't be byte-identical to D7. Criterion 6 measures how far apart the two outputs are.
- The CSL type mapping is why the four custom types (Review, Dictionary, Block Print, Obituary) still render correctly even though CSL has no type named after them.

---

## User — 2026-10-23 17:44

Ok Explain #1 about the zotero_feed in more detail?

---

## Claude — 2026-10-23 17:44

Criterion 4 asks whether bibcite offers a workable way to manage Zotero feeds. The question is how big that problem actually is.

**What the Zotero feed is in D7**

Two modules are enabled in production: `biblio_zotero` and `sources_zotero_importer`. Between them, a `zotero_feed` node holds the settings for pulling bibliographic records from a Zotero library into Sources as `biblio` nodes. That presumably covers which Zotero library or group to pull from and the credentials. The credentials criterion (#5) hangs off this too. I haven't looked at the node's fields, because the spike deliberately didn't extract credentials.

**What the dump shows**
- **Imported data:** about 12,817 `field_zotero_tags` rows, about 6,212 `field_zotero_collections` rows and about 1,015 `field_zotero_attachment` rows. So Zotero seeded a large share of the corpus at some point.
- **Ongoing configuration:** exactly one `zotero_feed` node and one row in `biblio_zotero_collections`.

The criterion reads like a feed subsystem to be replaced, but the evidence is one feed definition plus a lot of historical data. Those are two different migration problems.

**What's unknown**

Whether that one feed is still syncing. Having one node tells us it exists, not that it runs. The two outcomes lead to different work:
- **Dormant:** the feed is history. Criterion 4 shrinks to preserving the imported fields (tags, collections, attachments) on the migrated nodes. Criterion 5 (credentials) falls away, and nothing needs a live Zotero integration in D11.
- **Active:** someone is still adding records through Zotero. D11 then needs an ingest path, either a bibcite-compatible Zotero import or a custom config entity or Feeds-based approach (the spike's fail criteria list those). Criterion 5 also becomes real, because we'd have to confirm the credentials still work.

**How to check**

This is read-only work against the D7 data, either the dump or an inspected live copy:
1. Read the `zotero_feed` node's `created` and `changed` timestamps.
2. Look at the `biblio` nodes tagged with Zotero data and find the most recent `created`, `changed` and Zotero import timestamps. A last import in 2021 would mean dormant.
3. Check whether D7's cron or queue logs (`watchdog`) show recent Zotero sync entries.
4. Failing that, ask Sources site staff, since someone presumably knows whether they still push through Zotero.

The dump is from 2026-06-11, so it can show activity up to then but not since. If it shows nothing for years, that's strong evidence on its own.

Want me to run those checks against the dump? I'd first look for where it lives locally, and I'd keep to timestamps and structure, without reading any credential fields.

---

## User — 2026-10-23 18:05

yes run checks

---

## Claude — 2026-10-23 18:05

macOS `zcat` wants `.Z`; use `gzip -dc`.

---

## Claude — 2026-10-23 18:06

The dump says the Zotero feed has been dormant for years, though it can't prove nothing has run since 2026-06-11. I only read timestamps and structure, no credential fields. I decompressed the dump to the scratchpad, queried it with a small script, and deleted the 200 MB copy afterward.

**The feed node**
- There is one `zotero_feed` node: nid 20812, "Zotero Feed Importer", published.
- It was created 2014-04-28 and last changed 2015-12-01, which is more than ten years ago.
- It has no field rows in any `field_data_*` table. The feed's connection settings aren't stored on the node, so I can't see them from the dump.
- The only Zotero variables in `variable` are per-content-type settings: pathauto patterns, node options and kmaps field settings. There is no feed schedule or credential variable.
- The `queue` table has no Zotero entries.

**The Zotero-imported records**
- 1,920 nodes carry Zotero tag or URL fields, and all of them were created between 2014-05-12 and 2014-09-05. That was one import burst, with nothing created through Zotero since.
- Their `changed` dates cluster in 2015 (890), 2016 (54), 2020 (975) and 2023 (1). The 2020 batch looks like a bulk edit, not a sync. I haven't verified that.

**The wider corpus**
- Most of the 25.6k biblio nodes did not come from Zotero. They were created mostly in 2019–2022 (2019: 6,608; 2020: 10,440; 2022: 5,222). The latest was created 2025-12-19.
- So Sources is still growing, but not through this feed.

**The watchdog log**
It has only 20,193 `php` rows, 6 `cron` rows and 1 `actions` row. There are no Zotero-typed entries, so there's no log evidence of recent sync.

**What this means for the spike**
- **Criterion 4:** this reduces to preserving the imported Zotero fields (tags, collections, attachments, URLs) on the migrated nodes. No live feed management is needed in bibcite.
- **Criterion 5:** this almost certainly falls away. Nothing has pulled from Zotero since 2014 as far as the dump shows.

**Two caveats**
- The dump is 4.5 months old. One question to Sources staff, something like "does anyone still sync from Zotero?", would settle it.
- The feed's actual settings live somewhere I couldn't see, probably in the module's own config or the feed node's revisions. If you want to be thorough before dropping criterion 5, that's the one place left to look. I'd stop short of reading it unless you say so, since it likely holds credentials.

I haven't edited the spike doc. If you agree, I'd record criterion 4 as reduced and criterion 5 as dropped (pending that confirmation), and add the evidence above. Should I go ahead?

---

## User — 2026-10-23 18:16

yes record all that in the spike. commit and post it to main as it's only documentation, right? I need to close out. Note this for me to pick up at the start of my next session.

---
