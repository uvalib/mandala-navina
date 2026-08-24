# Session Log: D7 access defect filed, sized and dispositioned; Spike 5 opened

**Date:** 2026-08-24 (second session of the day; continues
[2026-08-24-json-proxy-rescoped-and-endpoint-access-escalated.md](2026-08-24-json-proxy-rescoped-and-endpoint-access-escalated.md))
**Participants:** Than Grove, Claude Code
**Outcome:** The 08-21 private-docs blocker cleared and the D7 access defect **filed, verified,
sized and dispositioned** in one sitting. Spike 5 moved ○ Pending → ◐ Partial with **4 of 6 pass
criteria resolved**. Spike status key gained a missing definition.

> Hand-written. The defect itself is **not described here** — it is tracked in
> `uvalib/mandala-legacy-docs` per the [non-public documentation policy](../non-public-documentation.md).
> **Ask Than.** Everything below is deliberately non-disclosing.

## 1. Private-docs access resolved — the 08-21 action for Yuji is closed

Than got access to both private repos (checked out at `~/Sandbox/Mandala/mandala-legacy-docs`
and `~/Sandbox/Mandala/mandala-navina-docs`). The 08-21 ambiguity — repos missing, or merely
invisible — resolves as **invisible, not missing**.

Filing destination per `CONVENTION.md`: routing keys on **which stack the fix serves**, not
where the finding was made. This fix serves D7 → `mandala-legacy-docs`. Worth noting because the
instinct is `navina-docs`: it was found during rebuild work and motivates a D11 requirement. The
convention explicitly anticipates that case.

The 08-21 draft **did not survive** — both that day's transcripts were checked and contain only
conversational prose. It was rewritten from Than's description and **re-verified against
production before filing**, so it rests on fresh evidence rather than a three-day-old
recollection. Public trail updated by [PR #138](https://github.com/uvalib/mandala-navina/pull/138),
still saying only that a problem exists and who to ask.

## 2. The defect: verified, scoped, sized, decided — all in one session

Four rounds, each of which changed the answer:

1. **Verified** — reproduced anonymously against production, and found it broader than described.
2. **Scoped** — Than spot-checked the other three D7 apps; each restricts private nodes. **Sources
   only**, not platform-wide. Consistent with Spike 6's finding that the four apps diverged
   *precisely* in access enforcement.
3. **Sized** — ~2,200 published private records exposed. The structural detail worth keeping:
   **the exposure is inherited, not explicit.** Only 3 nodes carry an explicit private flag while
   23,348 say "use group default"; privacy comes from 54 private collections. *Sizing by searching
   for explicitly-private nodes would have found 3 and concluded it was trivial.* Validated 10/10
   on a live random sample.
4. **Dispositioned** — **Than: do not patch D7. Severity Low. Fix it in D11.** The records are
   bibliographic entries for published books available elsewhere; the unique content is the
   collection structure, also low value.

**The technical arguments alone pointed toward patching** — one site, small fix, access value
already loaded, indefinite pre-cutover window. It was the *content assessment* that decided it.
That distinction is written into the private record explicitly, because a future reader
re-deriving the technical case would otherwise reach the opposite conclusion and wonder why
nobody acted.

**This makes the [#136](https://github.com/uvalib/mandala-navina/pull/136) agenda item the
remediation**, not just an architectural preference.

Two near-misses worth recording, both caught by checking rather than reasoning:

- The `system` table put `status` in column 5, not where the pattern assumed — the Zotero modules
  read as *disabled* before the schema was confirmed. They are enabled.
- The first sizing pass parsed only rows with quoted descriptions, silently dropping every
  built-in biblio type and reporting 36 types as "undefined".

## 3. Spike status key — "Complete" was undefined

`Complete` was used on Spike 4b but appeared nowhere in the key, so the one spike that closed on
a *failed* hypothesis looked like a typo for `Proven`. Defined
([PR #139](https://github.com/uvalib/mandala-navina/pull/139)):

- **Proven** — the theory under test was demonstrated true.
- **Complete** — question resolved, direction chosen, no blockers, but **the original hypothesis
  did not pass**. 4b's fail criterion fired; marking it Proven would imply the footnotes module
  worked, which is the opposite of what was found.

The key now also states what neither means: **not "no work left"**. Spike 6 is Proven and still
handed off its controllers. The status describes the **state of the question, not the state of
the code** — which was the actual confusion.

## 4. Spike 5 opened — the main risk doesn't materialise

[PR #140](https://github.com/uvalib/mandala-navina/pull/140). ○ Pending → ◐ Partial.

**bibcite is biblio's successor and ships biblio's own type list near-verbatim.** The theory
implicitly assumed a re-modelling onto CSL; it is a 1:1 name mapping. 26 of 36 types in use
match, covering **94.2%** of 25,629 rows.

Every gap is a Mandala custom type. Three are **authorship distinctions D7 encoded as types**
(300 rows) which bibcite expresses through contributor roles — they collapse into `book`. Than
confirmed the other four are wanted, with meanings (Review = *book reviews*; Block Print = *a
wood-block print of a Tibetan text*). All four are **config-only** — a reference type is one YAML
over a shared field pool, and bibcite's configurable type→CSL mapping has exact targets
(`review-book`, `entry-dictionary`, `article-newspaper`, `manuscript`). **The "critical reference
type is missing" fail criterion resolves affirmatively rather than firing.**

Than's semantics mattered directly: "Review" as *book reviews* is what makes `review-book` right
rather than the weaker generic `review`.

**Criterion 3 resolved by doing the work.** Production runs `biblio_style = "cse"`, which bibcite
does not ship. Two importable configs prepared (CSE 9th and 8th, name-year) plus a D7 baseline,
under `docs/spikes/assets/spike-05-bibcite/` — deliberately *not* `config/sync`, since bibcite
isn't installed and a `cim` would fail. Identifying the variant was the real work: the CSL repo
carries seven CSE styles across three families, and D7's live output settles the family
unambiguously while leaving the *edition* undetermined — so both are provided.

Also found: **the citation route serves at least 8 styles**, not one. `harvard`, `vancouver` and
`ieee` would each need importing; **deferred by Than until something actually requests them.**

## 5. Recurring lesson, third instance today

The morning session recorded: *for anything touching the edge/WAF, `curl` establishes nothing on
its own.* The afternoon added two more of the same shape — **a claim that looks settled because
nobody tested the other side of it**:

| | The untested assumption | What testing found |
|---|---|---|
| Morning | WAF blocks all Drupal hosts | Sources only |
| Afternoon | The access defect is platform-wide | Sources only |
| Afternoon | Sizing = count explicitly-private nodes | 3 explicit vs ~2,200 inherited |

The first two are the *same site* and the same inference error: generalising from one app to four
when Spike 6 had already documented that the four diverged. **Where D7 is concerned, per-site
confirmation is the default, not the exception.**

## Artifacts

| | |
|---|---|
| PR (merged) | [#138](https://github.com/uvalib/mandala-navina/pull/138) — private-repo access resolved; write-up filed |
| PR (open) | [#139](https://github.com/uvalib/mandala-navina/pull/139) — define "Complete" in the spike status key |
| PR (open) | [#140](https://github.com/uvalib/mandala-navina/pull/140) — Spike 5 desk research + CSE import job |
| Private | `mandala-legacy-docs` `d56a2da` → `a885e48` (scoped) → `2a84b13` (sized) → `2d3a1f9` (dispositioned) |

## Open items

- **#139 and #140 need review.**
- **Group meeting:** uniform endpoint access ([#136](https://github.com/uvalib/mandala-navina/pull/136)) — Yuji + Xiaoming. Now also the remediation for the D7 defect.
- **Spike 5 next:** is the single `zotero_feed` node still syncing (desk work, may remove criterion 4); then install bibcite in DDEV to verify the CSL mapping accepts custom type ids, and run criterion 6.
- **Never established:** whether the D7 defect was ever exploited — needs D7 access logs for `sources-api/*`.
- **Unchanged from this morning:** mandala-om #79 is merged but **nothing was redeployed**.
