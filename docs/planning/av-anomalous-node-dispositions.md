# AV5 / AV14: Dispositions for the two anomalous AV node populations

**Decision date:** 2026-09-08
**Decided by:** Yuji Shinozaki (Sprint 3 lead, per [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md))
**Backlog items:** [Sprint 3](../sprints/sprint-03-av-core-implementation.md) AV5 and AV14
**Status:** Both decided — AV4's source queries can now be written
**Relates to:** [ADR 008](../adr/008-mvp-migrate-not-improve.md) (migrate, not improve),
[AV Content-Model Audit](av-content-model-audit.md) (open question #8; the required-field
gap in its data profile), [Spike 7](../spikes/spike-07-kaltura-av-integration.md)
(`create_node_mediabase()` root cause), [AV2 scope note](av-content-type-decision.md)
(same investigation)

Sprint 3's acceptance criteria require that these nodes be "triaged with a documented
disposition (not silently dropped or silently included)." This note is that document.
Both nid lists are recorded in full below so the exclusion and the cleanup list are
auditable after the fact.

All figures are from `mandala-prod-av-db_2026-09-01.sql.gz` (`gzip -t` verified before
use, per Spike 7's dump-hygiene rule), read by streaming the dump. Row counts for
`field_transcript` (5,380), `field_subject` (16,999) and `field_workflow` (11,465)
reproduce the audit's independently-derived figures exactly, which cross-validates the
extraction.

---

## AV5 — the 68 `MISSING_TYPE` nodes: **exclude**

### Decision

**Exclude all 68 from migration.** Do not create a D11 bundle for them, do not repair
them to `audio`/`video`. Record the nid list (below) in the migration source query's
exclusion so the omission is explicit rather than a side effect of filtering by bundle
name.

### Why the alternative isn't available

The audit framed this as "exclude, or repair to a real bundle by checking the underlying
Kaltura entry's actual type." **Repair is not on the table**, because there is nothing to
repair *from*:

- They carry **no `field_video`, no `field_audio`, and no thumbnail.** The only field
  instance defined on the `MISSING_TYPE` bundle is `og_group_ref`. When
  `create_node_mediabase()` wrote the invalid bundle string, the Field API had no
  matching instance to attach the media reference to, so **the Kaltura entry ID was
  silently dropped at save time.** There is no entry ID left on these nodes to look up.
- `node_type` has **no `MISSING_TYPE` row.** It was never a content type — only an
  invalid string in `node.type` — so there is no D11 bundle to migrate them into without
  inventing one.

### Why exclusion is safe

| Property | Value |
|---|---|
| Count | 68, all published |
| Created | all in **2014**, all by **uid 1** |
| nid ranges | `2799`–`2833` and `2901`–`2934` — two contiguous runs, consistent with two batch-import passes |
| Titles | **all 68 are plain `.jpg` filenames** (100% `.jpg`; none deviates from an image-filename pattern) |
| `field_pbcore_title` | 0 |
| `field_workflow` | 0 |
| `field_subject` / `field_kmap_terms` | 0 / 0 |
| `field_transcript` | 0 |
| `field_thumbnail_image` | 0 |
| Collection membership | **all 68 in exactly one collection** — "Admin: On Kaltura Not in Mediabase" (nid `2503`) |

That collection is the decisive fact. It is a staff triage bucket whose name states its
own purpose, created 2014-11-25 by uid 1. These are Kaltura **IMAGE** entries pulled
through the batch importer and parked there — confirming Spike 7's code-level root cause
with data rather than inference.

**Excluding them does not strand the collection.** Node 2503 holds **353 members: 193
`video`, 92 `audio`, and the 68 `MISSING_TYPE`.** The 285 real AV nodes in it migrate
normally, so the collection survives with its genuine content intact.

There is no metadata to lose, no media to lose, and no user-facing content: 68 nodes
whose entire substance is an image filename.

### The 68 excluded nids

```
2799 2800 2801 2802 2804 2805 2806 2807 2808 2809 2810 2811 2812 2813 2814 2815 2816
2817 2818 2819 2820 2821 2822 2823 2824 2825 2826 2827 2828 2829 2830 2831 2832 2833
2901 2902 2903 2904 2905 2906 2907 2908 2909 2910 2911 2912 2913 2914 2915 2916 2917
2918 2919 2920 2921 2922 2923 2924 2925 2926 2927 2928 2929 2930 2931 2932 2933 2934
```

---

## AV14 — the 18 AV nodes with no Kaltura entry: **migrate as-is, hand staff the list**

### Decision

**Migrate all 18 exactly as D7 has them — published, in their existing collections —
and deliver the list below to AV staff as a pre-cutover cleanup item.**

This is ADR 008's faithful floor. These nodes are already published and already render
metadata with no player on the live D7 site; migrating them as-is reproduces current
behaviour rather than making anything worse. Unpublishing them would be us making a
content decision on staff's behalf and would change user-facing state relative to D7.
Recording the list is what keeps this from being a silent inclusion.

### Why not exclude

These are **not** empty shells like the `MISSING_TYPE` nodes — they are real records
that lost, or never got, their media:

- **All 18 have a `field_pbcore_title`.**
- **17 of 18 have `field_workflow`** cataloguing/QA data — staff did work on these.
- 3 carry `field_subject` KMaps tagging; 1 has an attached transcript.
- They are spread across **15 different collections**, all published.

Only 7 of the 18 sit in obvious scratch collections (`test` ×2, `Docs Test` ×2, `Create a
Collection Test 1`, `Testing New Collection`, `test course`). The other 11 are in real,
substantive collections — *Amdo Collection*, *Tibetan and Himalayan Library*, *Repgong
Folk Songs Project*, *Hurricane Harvey*, *White House Evening of Poetry, Music, and the
Spoken Word*, and four course collections. A blanket exclusion would silently drop
catalogued records from real collections on the strength of a missing media reference
alone, and splitting the population by collection would require us to judge which
collections are "real" — a call AV staff are better placed to make than we are.

### The cleanup list

Node titles are deliberately omitted: several are individual presenters' names, and this
repository is public. Two course-collection titles have likewise had an instructor
surname trimmed (`6371` and `15536`), so those two strings will not match D7 verbatim —
match on the collection nid, not the title. The nid is what makes the list actionable.

| D7 nid | Bundle | Created | Collection (nid) | Workflow | Subject | Transcript |
|---|---|---|---|---|---|---|
| `1894` | video | 2012-09-26 | Amdo Collection (`1760`) | ✓ | — | — |
| `2096` | video | 2014-02-26 | Tibetan and Himalayan Library (`3`) | ✓ | — | — |
| `3895` | video | 2015-11-12 | Create a Collection Test 1 (`3888`) | ✓ | — | — |
| `3915` | video | 2015-12-02 | Testing New Collection (`3914`) | ✓ | — | — |
| `4068` | video | 2016-01-07 | Repgong Folk Songs Project (`3939`) | ✓ | ✓ | — |
| `5581` | video | 2016-09-04 | Italian 2010 (`5576`) | ✓ | — | — |
| `6196` | video | 2016-09-29 | test (`4884`) | ✓ | ✓ | — |
| `6211` | video | 2016-10-13 | test (`4884`) | ✓ | ✓ | — |
| `6566` | video | 2016-11-10 | Section 20 (12:30pm) (`6501`) | ✓ | — | — |
| `6596` | video | 2016-11-14 | Section 18 (11am) (`6386`) | ✓ | — | — |
| `6751` | video | 2016-11-16 | STS4500 Fall2016 Prospectus Presentations (`6371`) | ✓ | — | — |
| `7826` | video | 2016-11-29 | STS4500 Fall2016 Prospectus Presentations (`6371`) | ✓ | — | — |
| `13766` | video | 2017-04-24 | STS4600 Spring 2017 (`15536`) | ✓ | — | — |
| `13851` | video | 2017-07-07 | White House Evening of Poetry, Music, and the Spoken Word (`13856`) | ✓ | — | — |
| `13936` | video | 2017-07-26 | test course (`13931`) | ✓ | — | — |
| `14561` | audio | 2017-09-07 | Hurricane Harvey (`14551`) | ✓ | — | — |
| `14636` | video | 2017-09-08 | Docs Test (`14626`) | — | — | ✓ |
| `16246` | video | 2017-12-04 | Docs Test (`14626`) | ✓ | — | — |

Nothing in this population is newer than **2017-12**, which suggests whatever caused the
media reference to go missing stopped happening some years ago rather than being an
ongoing fault.

### Note for AV4

`field_video`/`field_audio` are **required** at the D7 form level, and these 18 violate
that. The D11 migration will create them the same way (the migrate API bypasses form
validation), which means the resulting D11 nodes will also be uneditable in the node form
until someone supplies a media reference or the field is made optional. That is a real
consequence of the "migrate as-is" choice, and it is the practical reason the cleanup
list needs to reach AV staff rather than sitting in a doc.

---

## Observation, not a claim: the Solr AV document count

[ADR 016](../adr/016-public-url-structure-single-host.md) cites **11,537** production
kmassets docs at `asset_type: audio-video`, against **11,583** `audio` + `video` nodes —
a gap of **46**. Neither population in this note accounts for it: the 68 `MISSING_TYPE`
nodes are a different bundle (and would not carry that facet), and the 18 media-less
nodes are the wrong size. The two figures may also simply have been taken on different
dates.

This is recorded as a loose thread, **not** as a finding — it was not investigated here
and neither disposition above depends on it. If AV8 (Solr/kmassets sync wiring) wants a
reconciled count, this is the discrepancy to start from.
