# Spike 5 assets — CSE citation style for bibcite

Produced 2026-08-24. Supports [Spike 5](../../spike-05-bibcite-sources.md) pass criterion 3
("required citation styles are available").

**These are spike artifacts, not deployable config.** They are deliberately *not* in
`drupal/config/sync/` — bibcite is not installed, so a `cim` there would fail on a missing
dependency. Move them only when bibcite is a real dependency.

## The problem

Production D7 Sources runs `biblio_style = "cse"` (Council of Science Editors), with
`biblio_user_style = "system"` so every user gets the site style. bibcite ships five CSL
styles — APA, Chicago author-date, AMA, MLA, MLA 8th — and **CSE is not among them**.

## What's here

| File | What |
|---|---|
| `bibcite.bibcite_csl_style.cse_name_year.yml` | CSE 9th edition (name-year) — **recommended** |
| `bibcite.bibcite_csl_style.cse_name_year_8th_edition.yml` | CSE 8th edition (name-year) — fallback if 9th differs too much |
| `d7-citation-baseline-nid25581.json` | D7's live rendered output for nid 25581 across all 8 styles the route serves |

Both configs match the structure of bibcite's own shipped styles (`id`, `parent`, `label`,
`csl`, `updated`, `custom: true`, `url_id`). **Validated:** each parses with Symfony YAML and
its embedded `csl` payload round-trips as valid XML.

Sources: the [CSL styles repository](https://github.com/citation-style-language/styles),
`cse-name-year.csl` and `cse-name-year-8th-edition.csl`.

## Why name-year, and why two files

The CSL repository carries **seven** CSE styles across three families — citation-name,
citation-sequence, and name-year. D7's live output picks the family unambiguously:

```
[Anonymous]. 2017. A Theoretically and Ethically Grounded Approach to ... Childhood Education
```

Author, then year, then title — **name-year**. The citation-sequence and citation-name
variants render a bracketed or superscript number instead and are not what Sources produces.

The edition is *not* determined by that evidence. D7 biblio's `cse` is a hand-written PHP
style plugin with no edition declared, so both 8th and 9th are provided; pick on rendered
output, not on the name.

## To use

```bash
ddev drush config:import --partial --source=../docs/spikes/assets/spike-05-bibcite
ddev drush config:get bibcite.settings default_style
```

Then set it as the default style in bibcite's settings, and compare rendered output against
`d7-citation-baseline-nid25581.json`.

## What this does NOT settle

**D7 biblio styles are PHP plugins; bibcite is CSL-only.** This substitutes one rendering
engine's idea of CSE for another's — output will *not* be byte-identical, and the baseline
file exists to measure how far apart they are, not to prove they match. Whether the delta is
acceptable is a stakeholder call (criterion 6).

**The style is per-request on one route.** Spike 6 found `sources-api/ajax/{nid}/cite/{style}`
takes the style from the URL, and D7 serves at least **eight** — `cse`, `apa`, `chicago`,
`mla`, `ama`, `harvard`, `vancouver`, `ieee`. bibcite ships equivalents for APA, Chicago, AMA
and MLA; **`harvard`, `vancouver` and `ieee` would each need importing too**, exactly as CSE
did here. Which of the eight clients actually request is still unknown.

**One node.** The baseline covers nid 25581 (a `journal_article`) only. A real comparison
needs at least one record per reference type in use, including the four custom types.
