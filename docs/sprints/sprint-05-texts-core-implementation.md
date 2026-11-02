# Sprint 5: Texts core implementation (D7 `book` → D11)

**Status:** ○ Planned — not started. **Gated on [Spike 13](../spikes/spike-13-texts-whole-book.md)** (task TX3).
**Phase:** [Roadmap](../roadmap.md) Phase 2, the Texts track (un-paused 2026-11-02).
**Lead:** Than Grove.
**Mode:** Individual, with team sign-off on the TX3 decision record.
**Relates to:** [Texts content-model audit](../planning/texts-content-model-audit.md),
[Spike 13](../spikes/spike-13-texts-whole-book.md), [Spike 4a](../spikes/spike-04a-tibetan-unicode-roundtrip.md),
[Spike 4b](../spikes/spike-04b-ckeditor5-footnotes.md),
[Spike 6](../spikes/spike-06-api-compatibility.md),
[ADR 008](../adr/008-migrate-dont-improve.md), [ADR 011](../adr/011-group-collections-inheritance.md),
[ADR 015](../adr/015-contributor-and-editor-access-tiers.md), [ADR 016](../adr/016-public-url-structure-single-host.md),
[ADR 017](../adr/017-legacy-identity-composite-key.md),
[Sprint 1](sprint-01-images-implementation.md) and [Sprint 3](sprint-03-av-core-implementation.md)
(the patterns this follows)

---

## Goal

Migrate the D7 Texts site (7,633 books, 65 collections, 57 subcollections, 8 asset links) to D11 so
that it matches D7 in **structure** (book outline, collections, access, URLs, API) and **look and
feel** (the whole-book reader with its Contents / Description / Views sidebar, collection pages,
listings), following the Images and AV migrations' pattern.

## Scope boundary

| In scope | Out of scope |
|---|---|
| `book` and `asset_link` bundles, the outline, collections and subcollections as Groups, files, KMaps fields, aliases | Sources (separate track, gated on its own review) and Mandala Home |
| Whole-book display and the three-tab sidebar | Redesigning the reader; improving D7 behaviour (ADR 008) |
| The splitter (page tree from one paste) | A new editing workflow beyond what D7 offers |
| Endpoints: `/api/json`, `node_embed` equivalent, **raw text, PubReader and Voyant** (the D7 "Views" tab) | `node_thumb` (always redirects to one generic icon) and the TEI modules (installed in D7, no TEI data found) |
| Footnote transform per Spike 4b | A new Tibetan/CKEditor proof beyond what is listed in [Open items](#open-items) |

## Backlog

| | Task | Depends on | Status |
|---|---|---|---|
| **Phase 0: audit and decision** | | | |
| TX1 | Refresh the [Texts audit](../planning/texts-content-model-audit.md): Book is out of core, the display is a whole-book render, journal fields were missing, real counts. Rescore the complexity (the 2.0/5 figure predates both findings) | — | ◐ audit updated 2026-11-02; complexity rescore open |
| TX2 | Run [Spike 13](../spikes/spike-13-texts-whole-book.md) | TX1 | ◐ S1 (outline) passed 2026-11-02; S2–S6 open |
| TX3 | Decision record (ADR) from the spike: outline model; whole-book render strategy; page URL semantics (D7: any page URL shows the whole book scrolled to the page's anchor); `/api/json` per page vs book root (Spike 6's open item); `node_embed` equivalent; D11 routes for PubReader, raw text and Voyant (keep D7 paths by redirect under ADR 016?). Team sign-off | TX2 | ○ |
| TX4 | Settle the `kmassets` `texts` bundle naming against the 5,381 existing legacy documents; plan the Texts orphan review-group sweep ([#289](https://github.com/uvalib/mandala-navina/issues/289)) | TX1 | ○ |
| **Phase 1: content model (config exported from a live Drupal, never hand-edited)** | | | |
| TX5 | Install contrib `drupal/book` 3.x (S1: works on D11.4; export its config from a live Drupal, since `allowed_types` is a list of `{content_type, child_type}` entries, not core's flat list). `book` bundle with the field set in the audit (plus journal fields, `field_legacy_site = texts`, `field_legacy_nid`). `asset_link` bundle. Reuse the `collection` and `subcollection` group types | TX3 | ○ |
| TX6 | Text formats and CKEditor 5 for `full_html` with Tibetan fonts. **Port the splitter** (heading-based page tree from one paste) as a D11 form action, tested against CKEditor 5 output | TX3 | ○ |
| TX7 | Group relationship types `collection-group_node-book` and `-asset_link`; `mandala_group_inheritance` wiring; ADR 015 `content_editor` CRUD on Texts bundles; map `group_content_access` 0/1/2 (4,668 / 2,693 / 272 books). **Fix `NodeQueryAlter::baseTable()`**: Book's `node_access`-tagged queries declare base table `book`, which it mistakes for the node table and adds a nonexistent `b.type` (SQL error for non-administrators; Spike 13 S1 finding 3) | TX5 | ○ |
| TX8 | Add `book` and `asset_link` to `mandala_kmassets_sync.settings.yml`, values matching the legacy index documents | TX5, TX4 | ○ |
| **Phase 2: migrations (group `mandala_texts`, DB key `migrate_texts`, ids `d7_texts_*`)** | | | |
| TX9 | Add the `migrate_texts` DB key to `settings.php`; scaffold the group (pattern: `mandala_av`) | TX5 | ○ |
| TX10 | `D7TextsNode` source plugin (model: `D7AvNode`). Exclude orphan body rows from deleted nodes; decide the 65 unpublished books; map multi-value author/editor/translator, the three language fields, Dublin Core dates (the machine name `field_dc_date_orginial_year` really has that typo), rights, journal fields, `field_external_pdf` | TX5 | ○ |
| TX11 | Outline migration: a Mandala copy of Book's `d7_book` using `migration_lookup` for `nid` and `book/bid` (the shipped one assumes preserved node ids, which collide here; S1 finding 1), still on Book's own plugins. Parents before children, `no_stub`; cross-check against `menu_links` `book-toc-<bid>` | TX10, TX3 | ○ |
| TX12 | Files: `d7_texts_file` plus `file_copy` (537 files; 228 PDFs). `field_pdf_version` (211), `field_external_pdf` (20), featured image (67). NFC filenames (`mandala_file_hygiene`) | TX10 | ○ |
| TX13 | KMaps fields (`field_kmap_term` Subjects, `field_kmap_places`, `field_kmap_terms`) using the AV KMaps `sub_process` | TX10 | ○ |
| TX14 | Collections and subcollections as Groups (pattern `d7_av_collections`); memberships from `og_membership`, **not** `field_og_collection_ref` (empty); `d7_image_group_membership` with `member_types: [book, asset_link]`; user memberships | TX10, TX7 | ○ |
| TX15 | Body transform: footnotes per Spike 4b (book-outline-aware; the 6-item checklist in [texts-footnotes-production-transform.md](../deferred/texts-footnotes-production-transform.md)), inline media (957 bodies with `<img>`, 292 with media-module tags), NFC. **Cross-node, so it runs after TX11; idempotent and resumable** | TX11, TX3 | ○ |
| TX16 | URL aliases via `d7_image_url_alias` (`node_types`: book, collection, subcollection, asset_link): 850 books with no alias, about 2,900 Tibetan-script aliases, 1,183 `redirect` rows; confirm the `mandala_legacy_redirect` texts host | TX10 | ○ |
| TX17 | `scripts/run-texts-migration.sh` (ordered, resumable; **rollback order matters**: outline migration, then node migration, then re-import, because Book's destination cannot update an existing outline, S1 finding 2) and `scripts/verify-texts-migration.sh` (D7 SQL against D11 SQL: counts by type and status, depth histogram, roots, files, memberships, KMaps references, footnote citation/definition counts; documented expected anomalies) | TX9–TX16 | ○ |
| **Phase 3: access and search** | | | |
| TX18 | Access verification: public / private / default books in restricted collections, as anonymous, member and editor | TX14, TX7 | ○ |
| TX19 | `kmassets:index-all` and `kmassets:audit`; confirm `MigrateSyncSubscriber` suppresses hooks during the run | TX17, TX8 | ○ |
| **Phase 4: look and feel (theme `shanti_sarvaka`)** | | | |
| TX20 | Book display: the whole-book render plus the Contents / Description / Views sidebar, ported from D7's `node--book.tpl.php`; PDF icon; Notes list. The Views tab shows D7's three links with D7's labels ("View in PubReader", "View as raw text", "View in Voyant"), opening in a new tab | TX3, TX15, TX23 | ○ |
| TX21 | Collection and subcollection pages: featured image, body, member listing (5 per page), create links. Reuse the `collection_gallery_*` pattern with a Texts view | TX14 | ○ |
| TX22 | Listings: `/texts` (D7 `/alltexts`) with subject / place / collection facets, `/alltexts_open`, `/mytexts`, `/collections`, `/my_collections`, Solr text search; Texts on the landing page (AV16 follow-up) | TX19 | ○ |
| TX23 | Endpoints, all access-checked per the [entity-access convention](../planning/entity-access-endpoint-convention.md): `/api/json/{node}` (per TX3); `node_embed` equivalent (the requested page's fragment, `?nostyle`, JSONP and CORS as D7); **raw text** (whole-book render, whitespace-collapsed, tags stripped); **Voyant** (redirect to voyant-tools.org with the raw-text URL as `input`); **PubReader** (outline into the bundled NCBI PubReader3 library). Keep legacy D7 paths working through `mandala_legacy_redirect` where ADR 016 requires | TX3 | ○ |
| **Phase 5: acceptance** | | | |
| TX24 | Acceptance run; side-by-side D7 and D11 of about 10 books (small, the 374-page book, Tibetan, footnote-heavy, restricted); production-scale timing (AV4's live run took about 5 hours; DDEV timing does not transfer) | all | ○ |

## Acceptance criteria

- [ ] Spike 13 closed with a go decision and TX3 signed off by the team
- [ ] `verify-texts-migration.sh` passes against the D7 dump with every expected anomaly documented
- [ ] A migrated book's page URL shows the whole book scrolled to that page, with the D7 section markup and anchors
- [ ] Contents, Description and Views tabs match D7; the three Views links work (PubReader, raw text, Voyant)
- [ ] Footnotes resolve for sampled books, including the two outlier books
- [ ] Access matches D7 for public, private and default books in restricted collections (TX18)
- [ ] Legacy URLs (aliased and unaliased) redirect or resolve; `/texts/{nid}` is canonical (ADR 016)
- [ ] `kmassets:audit` clean for Texts
- [ ] Side-by-side D7/D11 review of the sample books accepted by Than

## Open items

Unproven and tracked rather than assumed (see [deferred notes](../deferred/README.md)): Tibetan survival
through MySQL to Solr; CKEditor 5's normalization of Tibetan; the splitter against CKEditor 5 output.
Also open: whether the 65 unpublished books, the orphan body rows (rows left behind by deleted nodes)
and the 63 books with no body row migrate; the two footnote outlier books (`bid=15582`, `bid=15988`);
PubReader's third-party library (licence, size, asset handling); Voyant needing a publicly fetchable
URL (WAF behaviour on each environment is unverified).

## References

See the **Relates to** line above. Pattern sources: `mandala_migrations` (`D7AvNode`, `D7AvFile`,
`D7ImageGroupMembership`, `D7ImageUrlAlias`), `scripts/run-av-migration.sh`,
`scripts/verify-av-migration.sh`, `scripts/load-d7-source.sh`, `mandala_kmassets_sync`,
`mandala_group_inheritance`, `mandala_node_api`, `mandala_legacy_redirect`.
