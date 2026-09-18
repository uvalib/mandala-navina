# AV4: 42 hosts still have ambiguous paragraph ordering — tiebreak never confirmed with staff

**Area:** migration / AV / content model
**Raised during:** Session 2026-09-08 (building AV4), carried unresolved since
**Jira:** (add when available)
**Priority:** Low — out of scope for D11 implementation as such (the
migration already runs and is data-preserving either way), but a real
open question worth a decision before it's forgotten entirely

## What happened

AV4's `D7AvFieldCollection` source plugin resolves D7's `und`/`en`
storage-layer duplication with an `en`-preferred ordering rule
(`COALESCE(MIN(CASE WHEN language='en' THEN delta END), MIN(delta))`),
which reduced ordering ambiguity from 1,739 hosts (a naive `MIN(delta)`)
down to 42, measured on `field_pbcore_title` (see the project's `und`/`en`
language-layer background for the full derivation).

**42 hosts still have two items resolving to the same delta.** The
migration's fallback is `item_id` order — a deterministic, data-preserving
choice, but never confirmed as the *correct* one. Nobody has asked AV
cataloguing staff whether there's a real convention (e.g. "later `item_id`
wins," or a specific field that should break the tie) that should decide
this instead.

## Why this is safe to leave running, but shouldn't be forgotten

The current fallback doesn't lose or corrupt any data — it only affects
*display order* for the specific paragraphs on these 42 hosts (which of two
"Is Part Of" relations, or two cataloguing entries, etc. appears first).
Nothing blocks on resolving this, which is why it's been carried across
several sessions without action. But "carried across sessions in narrative
notes" is exactly how something like this quietly becomes permanent by
default — worth a real decision, not indefinite deferral.

## Recommendation

Ask AV staff (Than in particular, as the original D7 AV developer) whether
D7's cataloguing workflow has a real convention for which of two
same-position field_collection items should be considered "first." If not,
explicitly ratify `item_id` order as the intentional answer rather than
leaving it as an unconfirmed fallback.

## Related

`docs/planning/av-node-migration-notes.md` §8 (§3 also notes
`field_pbcore_instantiation`'s `en` records tend to be *thinner* than
`und`'s on the same host — worth cross-checking against
[av4-instantiation-wrong-winner.md](av4-instantiation-wrong-winner.md), a
real scoring bug found and fixed 2026-09-17 on this exact field, though the
two are not confirmed to be the same population: the bug was specifically
about *completely empty* items outscoring populated ones via a `COUNT(*)`
defect, not the general "en genuinely has less data than und" pattern this
note originally flagged, which the scoring rule was already designed to
handle correctly). See also
[av4-field-pbcore-language-iso639-conversion.md](av4-field-pbcore-language-iso639-conversion.md)
and
[av2-field-tags-old-label-inconsistency.md](av2-field-tags-old-label-inconsistency.md),
raised in the same session.
