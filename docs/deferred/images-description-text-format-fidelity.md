# Image description HTML differs: D11 `basic_html` vs D7 auto-paragraph `safe_value`

**Area:** migration / Images / text formats / kmassets write path
**Raised during:** Sprint 1 (1a.8 — doc-builder fixture validation)
**Jira:** (add when available)
**Priority:** Medium

## What we found

The kmassets `description` (and `description_alt_txt`) golden values carry
filter-rendered HTML — D7's `field_description` **`safe_value`**:

```
<p>Ra am is a  local name of maize</p>\n
```

The D11 description paragraph stores the **raw** D7 input (migration mapped
`field_description/0/value`, format `basic_html`), and the builder correctly emits
the filter-processed output (`->processed`, matching the intent of D7 `safe_value`).
But `basic_html` does **not** auto-wrap paragraphs, so:

```
raw value:   [Ra am is a  local name of maize]
->processed: [Ra am is a  local name of maize]      # no <p>, no trailing \n
```

The **text content matches**; only the `<p>`/`<br />` wrapping differs. This is a
text-format-configuration fidelity gap, **not a builder bug** — the builder is
already doing the right thing (emitting processed output).

## Why

D7's description format ran a "convert line breaks / auto-paragraph" filter that
produced the `<p>…</p>` + `<br />` markup captured in the golden. The 1a.7
migration assigned `basic_html`, whose filter pipeline omits that step.

## Options (decision needed)

1. **Match D7's filtering** — migrate descriptions to a format whose pipeline
   includes line-break→`<br>` / auto-`<p>` (or add that filter), so `->processed`
   reproduces `safe_value`. Best for retrieval/display parity (ADR 008).
2. **Accept the difference** — the searchable text is identical; only presentation
   markup differs. Lower effort if the React/display layer re-wraps anyway.

Cross-ref the [doc contract §7.2-C](../planning/kmasset-solr-doc-contract.md) and
[ADR 008/010](../adr/008-mvp-migrate-not-improve.md) (migrate, not improve).
