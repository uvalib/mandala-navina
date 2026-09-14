# `shanti_image`'s existing `title_sort_s` values were never re-scanned for the same UTF-8 truncation bug fixed in AV8

**Area:** kmassets / Solr / data quality
**Raised during:** Session 2026-09-14, fixing AV8's `title_sort_s` bug (PR #199)
**Jira:** (add when available)
**Priority:** Low — no known symptom yet; this is a risk assessment, not a confirmed defect

## What happened

`KmassetDocBuilder::normalize()` computed `title_sort_s` with `trim($title, "'\"“”‘’()-: []")` — a byte-oriented `trim()` charlist containing multi-byte UTF-8 curly quotes, which silently added raw continuation bytes (`0x80`/`0x98`/`0x99`/`0x9C`/`0x9D`) to the strip set. Any title ending in a multi-byte character whose last byte collides gets that byte stripped. For 4 real AV video nodes (Cyrillic/Chinese titles), this produced a fully invalid UTF-8 string that crashed `json_encode()` outright — loud, caught, fixed (see [[project-kmassets-sync-writer-bugs]]).

**The quieter failure mode was never checked**: for a title where stripping the trailing byte(s) doesn't happen to leave a *fully* invalid sequence (e.g. the corruption removes exactly one whole trailing character's worth of bytes, or the collision lands somewhere that doesn't break the encoding), the result is a **silently truncated `title_sort_s`** — no crash, no error, just a slightly-wrong sort key. This code path is shared by every bundle, not AV-specific, and `shanti_image` has been indexing through it since Sprint 1 (111,339 docs). It never surfaced there because English titles essentially never end in a byte matching the collision set — but "essentially never" is not "never," and Images does contain some non-English titles (transliterated terms, foreign-language captions) that could plausibly hit this.

## Why this wasn't fixed today

Scope call, not an oversight: fixing the bug (a Unicode-safe `preg_replace`) was in scope for AV8; re-auditing 111,339 already-indexed Images docs for a *possible* pre-existing symptom of a *different* bug's blast radius was not, and doing so requires either re-deriving each doc's correct `title_sort_s` from its live title and diffing (a real audit script, not a quick check) or waiting for a live symptom report.

## Recommendation

- **If Images' sort-by-title browsing/search behavior is ever reported wrong** (a title appears out of alphabetical order, or a truncated-looking value shows in a sort-key-driven UI), check this bug first before assuming it's a new defect.
- **A real fix, if ever prioritized**: re-run `KmassetDocBuilder` (now using the fixed `preg_replace`) against all `shanti_image` docs and diff the resulting `title_sort_s` against what's currently in Solr — anything that changes was silently wrong before. This is a `kmassets:index-all shanti_image` re-run plus a before/after Solr comparison, not a schema change.

## Related

- [[project-kmassets-sync-writer-bugs]] — the fix itself and its root cause
- Session log: `docs/session-logs/2026-09-14-av8-kmassets-sync-and-two-bugs-found.md`
