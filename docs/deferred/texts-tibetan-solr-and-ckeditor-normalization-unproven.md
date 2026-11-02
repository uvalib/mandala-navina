# Texts: Tibetan through Solr and through CKEditor 5 is still unproven

**Category:** migration-texts, solr-search
**Raised during:** Texts migration planning (2026-11-02), from Spike 4a's "does not establish" list
**Issue:** #388
**Priority:** Medium. Does not block Spike 13 or the start of Sprint 5; must be answered before Texts acceptance (Sprint 5 TX24).
**Owner:** Than
**Cutover review:** pending

## What is unproven

[Spike 4a](../spikes/spike-04a-tibetan-unicode-roundtrip.md) proved Tibetan script, including
stacked conjuncts, survives the Migrate API and MySQL byte for byte as NFC. Its own doc lists what
it did **not** establish, and nothing since has closed these:

1. **MySQL to Solr.** Whether Tibetan keeps its form through the Solr analysis chain and the field
   mapping. 4a could not test it because D11 published no documents to Solr at the time. D11 Texts
   documents will be the first Tibetan-heavy body text indexed from D11 (1,138 books are tagged
   `bo`; about 2,900 URL aliases and 2,665 titles contain Tibetan script). Related:
   [tibetan-search-quality.md](tibetan-search-quality.md).
2. **CKEditor 5 normalization.** 4a tested the entity API and SQL directly, not a CKEditor
   round trip. Whether CKEditor 5 (the Texts body editing surface) changes the Unicode
   normalization form on save is unknown.
3. **Browser font rendering** of migrated Tibetan text. Not checked.

## Why it is parked, not a spike

None of the three changes the Sprint 5 design: the migration path already preserves NFC, and the
Solr and CKEditor checks only need real Texts content in D11, which exists after the migration
tasks (Sprint 5 TX15, TX19). They are acceptance checks, not design risks.

## Start trigger

Texts documents indexed on a D11 environment (after Sprint 5 TX19), and Texts bodies editable in
CKEditor 5 (after TX6). Then: sample Tibetan-heavy books, compare stored and indexed text, and edit
and re-save a Tibetan page in CKEditor 5, checking the normalization form each time.
