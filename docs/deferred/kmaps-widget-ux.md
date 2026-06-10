# KMaps Autocomplete Widget UX
**Area:** `shanti_kmaps_fields` — field widget  
**Raised during:** Spike 1  
**Jira:** (add when available)  
**Priority:** Medium

## Problem

The current D10 widget uses a single textfield for both search input and display
of the selected term label (e.g. `Buddhism (subjects-2610)`). If a user manually
edits this field — for example, deleting the closing parenthesis — the partial
string is sent as a live Solr autocomplete query. Parentheses are Solr special
characters, so an unmatched `(` causes a `400 Bad Request` that surfaces as an
error message in the autocomplete dropdown.

## Required fix

Replace the single-input design with a two-element approach:

1. **Read-only display field** — shows the selected term label; not editable
   directly. Has a clear/remove button.
2. **Hidden search input** — appears only when the display field is empty or
   cleared; accepts free-text search and triggers autocomplete.

This matches the D7 original design (result box + separate search term input)
and eliminates the partial-edit problem entirely.

## Additional hardening needed

- Debounce autocomplete requests (min 2–3 chars, not every keystroke)
- Strip/escape Solr special characters from the query string in the controller
  before sending — current `escapeSolrQuery()` escapes `(` and `)` but they
  can still reach Solr inside a partially edited label
- Consider minimum query length to avoid Solr load on single-character searches

## Context

The D7 widget rendered a `kmap-result-box` div (tag cloud of selected terms)
and a separate `kmap-search-term` input, with JavaScript coordinating between
them. The D10 port should follow the same pattern. The existing D7 JS in
`shanti_kmaps_fields.widgets.js` is a useful reference for the interaction model.
