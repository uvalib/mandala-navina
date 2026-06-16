# Session Log: Spike Status Glyphs

**Date:** 2026-06-15  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** Added status glyphs (● proven · ◐ partial · ○ pending) to the [spikes index and sidebar nav](../spikes/README.md); reconciled Spike 3 to ◐ Partial.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-15 19:38

A little bit of documentation tweaking.  Could we add a typographical indication of which spikes are done and in-progress to the documentation index?

---

## Claude — 2026-06-15 19:38

I'll look at the documentation index files to understand the current format before making changes.

---

## Claude — 2026-06-15 19:38

Let me check the actual status of each spike file so the indicators are accurate.

---

## Claude — 2026-06-15 19:39

The status column already uses words, and Spike 3's table entry ("Pending") is stale — its file says "Partial". I'll add consistent typographic glyphs to the index table and reconcile Spike 3. I'll match the existing minimalist `✓` glyph style with geometric symbols (filled = done, half = in-progress, open = not started).

---

## Claude — 2026-06-15 20:00

I also mirrored the glyphs into the mkdocs sidebar nav so the status is visible while browsing any spike page:

---

## Claude — 2026-06-15 20:00

Done. I added a typographic status scheme to the spikes index using filled/half/open circle glyphs that match the existing minimalist `✓` style:

- **● Proven** — done
- **◐ Partial / in progress**
- **○ Pending** — not started

Changes:
1. **`docs/spikes/README.md`** — applied the glyphs to the Status column and added a status-key legend below the table.
2. **`docs/spikes/.pages`** — prefixed each sidebar nav entry with its glyph, so the at-a-glance status follows you onto every spike page.

One correction worth flagging: the index table listed **Spike 3** as `Pending`, but its own file says `Partial — hierarchy and access control proven; nesting model requires design decision`. I reconciled the table to `◐ Partial`, which is also the only spike currently in the "in progress" state.

---

## User — 2026-06-15 20:02

That's fine.  I'm actually referring to the navigation sidebar.

---

## Claude — 2026-06-15 20:03

Good — that's already in place. The `docs/spikes/.pages` edit I made does exactly that: each spike in the mkdocs sidebar now carries its status glyph as a prefix:

```
● Spike 1: KMaps field type
● Spike 2: Solr integration
◐ Spike 3: Group collections
○ Spike 4: CKEditor 5 footnotes
○ Spike 5: bibcite Sources
○ Spike 6: API compatibility
○ Spike 7: Kaltura AV integration
○ Spike 8: reindeer_x consolidation
```

So `●` proven, `◐` partial/in-progress, `○` pending show right in the nav.

Do you want me to leave the README table legend as-is, or revert that and keep the change scoped to just the sidebar?

---

## User — 2026-06-15 20:03

can I preview?

---

## Claude — 2026-06-15 20:04

Yes. I'll start the mkdocs dev server in the background — it uses the `awesome-pages` plugin that reads your `.pages` files, so the sidebar glyphs will render.

---

## Claude — 2026-06-15 20:04

Port 8000 is already in use — likely a mkdocs server is already running. Let me check.

---

## Claude — 2026-06-15 20:05

Something else is on 8000 (not the Mandala docs). I'll use a different port.

---

## Claude — 2026-06-15 20:05

It's serving with a base path. Let me confirm the sidebar glyphs render in the built HTML.

---

## Claude — 2026-06-15 20:05

The dev server is up and the sidebar glyphs render correctly. Open:

**http://localhost:8042/mandala-navina/spikes/**

The left nav shows the status prefixes live:

```
● Spike 1: KMaps field type
● Spike 2: Solr integration
◐ Spike 3: Group collections
○ Spike 4: CKEditor 5 footnotes
○ Spike 5: bibcite Sources
○ Spike 6: API compatibility
○ Spike 7: Kaltura AV integration
○ Spike 8: reindeer_x consolidation
```

`●` proven · `◐` partial/in-progress · `○` pending. It's running with `--livereload`, so if you want to try different glyphs (e.g. ✓/🚧/▢ or filled-vs-empty variants) I can edit `.pages` and the sidebar will refresh automatically.

The server's running in the background. Let me know when you're done previewing and I'll stop it — or tell me if you'd like to tweak the symbols.

---

## User — 2026-06-15 20:11

looks good.

---

## Claude — 2026-06-15 20:11

Stopped the preview server. The changes are on disk:

- **`docs/spikes/.pages`** — status glyphs in the sidebar nav (`●` proven · `◐` partial · `○` pending)
- **`docs/spikes/README.md`** — matching glyphs in the index table plus a status-key legend, and Spike 3 reconciled to `◐ Partial`

Both are still unstaged — let me know if you'd like me to commit them (and whether you want to keep the README table change or revert it to keep this scoped to just the sidebar).

---

## User — 2026-06-15 20:11

yep commit and push and record

---

## Claude — 2026-06-15 20:12

Committed and pushed. Now the "record" step — your CLAUDE.md session-end ritual uses `scripts/save-session-log.py` for long planning/spike sessions. This was a short docs tweak, but you asked to record it, so let me run it.

---
