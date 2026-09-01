# Session Log: Texts content-model audit (Sprint 2 Workstream C3) — all three C audits closed

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** `docs/planning/texts-content-model-audit.md` written and committed, validated against the existing `d7_texts` DDEV database. This closes Sprint 2's C1/C2/C3 trio — all three now have real data-profile validation. The `og_membership` collection-membership finding is now confirmed 3-for-3 and consolidated into one shared deferred note.

---

## 1. A head start from an already-closed spike

Unlike AV and Sources, Texts already had substantial groundwork: Spike 4b (CKEditor 5
footnotes) is CLOSED/proven, with a full investigation of `shanti_texts`' body content
and the cross-page footnote citation/definition pattern. That spike's memory
([[project-spike-4b-ckeditor-footnotes]]) supplied node counts, the `field_book_content`
body field, the Tibetan-language count (1,138), and — critically — a `d7_texts`
database **already loaded in DDEV**, so no dump-hunting or corruption risk this time.
This audit deliberately did not re-investigate footnotes mechanics (out of scope,
already fully resolved) — only confirmed the module's existence for completeness.

## 2. Key structural finding: `book` is D7 core's Book module, not a custom bundle

The one audit of the three where the primary content type isn't site-invented at all.
`shanti_texts_features_node_info()`'s `book` entry uses `base => 'node_content'` and
Drupal core's own stock Book-module description string verbatim — it's re-asserting
the core bundle plus custom fields, not defining a new type. `field_book_mlid` (a
computed, non-stored field deriving from core's `menu_links` table) confirms the
outline hierarchy lives entirely in core's own `{book}`/`{menu_links}` tables. This
means the outline/hierarchy migration path is a known, core-supported mechanism — a
smaller open question than the modeling decisions AV and Sources both face.

## 3. `shanti_texts_splitter` clarified — not a migration tool

Read the code directly rather than guessing from the name: it's an editorial
convenience that auto-splits one pasted document into a full book-outline tree at
node-save time, based on heading levels. Irrelevant to D11 migration logic itself, but
its own code independently distrusts `field_og_collection_ref`'s storage enough to
explicitly reset it to empty on every generated page ("Without reseting collection for
pages, site crashes") — a hint that foreshadowed the data-profile finding below.

## 4. The `og_membership` bug — now confirmed 3 for 3

Checked the same collection-membership gap found on both AV and Sources this session:
`field_data_field_og_collection_ref` is **empty (0 rows)** for Texts too, with real
membership (7,419 node memberships, 97.1% of book nodes) living entirely in
`og_membership`. With all three sites checked this sprint showing the identical
pattern, this stopped being a per-site quirk worth three separate notes — **consolidated
into one shared deferred note**,
[`og-collection-ref-storage-empty-use-og-membership.md`](../deferred/og-collection-ref-storage-empty-use-og-membership.md),
cross-referencing all three audits and flagging that Images (audited before this
pattern was known) should be checked too before assuming migration parity across all
four sites.

## 5. Other real-data findings

- **Three overlapping "language" fields resolved with real numbers**:
  `field_dc_lang_code` (ISO list) is the clear practical primary at 62.8% filled
  (matching Spike 4b's known Tibetan count of 1,138 exactly), vs. `field_dc_language_original`
  (free text, 3.2%) and `field_language_kmap` (KMaps taxonomy, 2.3%) — both minority-use.
- `group_content_access` (required) is 100% filled — clean, matching AV, unlike
  Sources' 91.2%.
- `field_split_text` has 2,887 rows but all currently value `0` — no books mid-flagged
  for splitting in this snapshot, consistent with it being a transient authoring toggle.

## 6. Doc/tracking updates

- `docs/planning/texts-content-model-audit.md` — full audit, code + data verified.
- `docs/deferred/og-collection-ref-storage-empty-use-og-membership.md` — new,
  consolidating the cross-site finding.
- Sprint 2 backlog: C3 row checked off. **The sprint's own acceptance criterion for all
  three audits (real dump, not placeholders) is now checked** — all three exist and are
  data-validated.

## Next-session starting point

Workstream C (the three content-model audits) is done. **Workstream B (Images
interactive UI — OpenSeadragon viewer, sibling carousel, masonry grid) is now the
largest unstarted piece of Sprint 2**, owned by Than, with no dependency on C. Workstream
D (uniform endpoint access docs) is also open and small. Separately: verify whether
Images has the same `og_membership` gap found on all three other sites, per the new
deferred note's item #2.
