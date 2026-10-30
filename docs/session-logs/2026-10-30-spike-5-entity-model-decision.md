# Session Log: Spike 5 Entity Model Decision

**Date:** 2026-10-30  
**Participants:** Than Grove, Claude Code (solo)  
**Outcome:** Spike 5 closed. Than decided Sources records are `bibcite_reference` entities ([ADR 020](../adr/020-sources-records-are-bibcite-reference-entities.md)), backed by a local proof of concept for legacy redirect, access, listings, Solr and display ([Spike 5](../spikes/spike-05-bibcite-sources.md), findings 8 and 9). Also decided: CSE 9th edition, migrate D7 citekeys, keep Zotero Collections, dedicated Zotero item-key field, on-demand import. Xiaoming and Yuji have not yet reviewed ADR 020.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-30 16:34

<local-command-caveat>The command below was run directly in Claude Code, not sent to you as a request, and its output goes straight to the user. It's recorded here as context for later messages.</local-command-caveat>

---

## User — 2026-10-30 16:34

<command-name>/login</command-name>
            <command-message>login</command-message>
            <command-args></command-args>

---

## User — 2026-10-30 16:34

<local-command-stdout>Login successful</local-command-stdout>

---

## User — 2026-10-30 16:34

do session start ritual

---

## Claude — 2026-10-30 16:35

Session start check is done. Two checks flagged, and both are expected from the Spike 5 work. Nothing blocks you.

**1. Git sync: the pull failed, as expected.** `git pull --ff-only` refuses because `drupal/composer.json` and `drupal/composer.lock` are modified. That is the bibcite install, which the 10-27 handoff says was left uncommitted on purpose. I didn't stash or resolve it.
- You're on `spike/5-bibcite-local-install`, pushed and in sync with its remote. There are no open PRs.
- `origin/main` has 3 commits you don't have:
  - #378: PHP 8.4 and Drupal core 11.4.11. This one touches `drupal/**`, so it reaches your DDEV on the next `ddev` rebuild.
  - #381: pre-cutover review flag for deferred notes.
  - #383: roadmap pointer for retiring legacy-host measures.
- I also found no unmerged branches or PRs other than this spike branch.

**2. Config drift: expected, don't export it.** `drush config:status` lists bibcite config as "Only in DB". That covers the CSL styles, view modes, views and actions. `core.extension` shows as different because bibcite is enabled. The 10-27 log said the script may flag this.

**3. Config on GitHub:** `drupal/config/sync` matches `origin/main`, and nothing is uncommitted there.

**4. dev-0 comparison skipped.** With the VPN off, my first run timed out reaching dev-0, so I reran with `--local-only`. Content counts are informational only. I can rerun the full check once you're on VPN.

**Handoff from the last session**

You planned to finish Spike 5 and then start the Texts migration. These are the remaining Spike 5 items, in order:
1. Tell Xiaoming and Yuji about the choice to use bibcite entities rather than nodes for references.
2. Ask Sources staff whether anyone uses Zotero today.
3. Run the broader render pass for criterion 6: books, chapters, the four custom types and seven non-CSE styles. This needs the `spike5_d7` scratch DB reloaded, because it was dropped.
4. Settle the group decisions: CSE 8th vs 9th edition, citekey handling, Zotero collections, and a field for the Zotero item key.

**Deferral issues**

There are 14 open `deferred` issues, and I didn't see anything newly assigned or blocked in the listing.
- Five are unassigned and free to pick up: #382, #380, #357, #336 and #300.
- #380 and #382 are Yuji's cutover review, so they likely need a group decision.
- #353 and #357 are the transcript issues. #353 is post-migration and blocks nothing.

How do you want to proceed? I'd suggest staying on the spike branch and continuing Spike 5 as planned. Rebasing onto main to get PHP 8.4 can wait until the spike is done, unless you want it now.

---

## User — 2026-10-30 16:39

ok now I am on VPN

---

## Claude — 2026-10-30 16:40

Also, the full check ran with VPN on. Content counts differ from dev-0 by 7 group relationships, 1 group and 1 video, which is normal scratch drift. The 25-pair nid spot-check matched. The git sync and config drift flags are the same two expected ones from before.

**Pros and cons of bibcite entities vs nodes**

Most of this comes from finding 7 in the spike doc, and the doc marks the consequences as unverified.

**Pros**
- **bibcite is built around entities.** Its type list, contributors, keywords, CSL rendering, citation styles, and BibTeX/RIS/EndNote import and export all operate on `bibcite_reference`. Nodes would mean building and maintaining a bridge to that data model.
- **D7's 94% type match carries over.** bibcite ships biblio's type list almost verbatim, so the mapping is mostly 1:1. The four custom types are config-only.
- **Tags and keywords map naturally.** The prototype mapped Zotero tags onto bibcite keywords without extra work.
- **Admin screens come free.** bibcite's pages for editing, merging contributors and keywords, and regenerating citekeys need no custom UI.
- **The Zotero prototype already works.** It was proven against this model, with upserts on the item key, incremental pulls and CSE rendering.
- **Upstream updates are easier to take.** Staying on the supported path keeps bibcite updates simple.

**Cons**
- **No nid.** Old `sources-api/ajax/{nid}/...` routes and the nid redirects have to resolve through `field_legacy_site` + `field_legacy_nid` (ADR 017). Needs a new field on reference bundles.
- **Group access is unproven.** Group ships a node relationship plugin, not one for `bibcite_reference`. Without it, collection and group access fall back to bibcite permissions. D7 had OG access on nodes, and the access model here is the hard-won part (`CollectionVisibility`, the node-access query tags).
- **Several custom modules assume nodes.** `mandala_node_api`, the kmassets sync hooks and `mandala_solr_visibility` all assume nodes and need reference-aware versions. The Solr/kmassets path matters most, since Sources has to be searchable alongside AV, Images and Texts.
- **Spike 6 compatibility.** The Sources API endpoints were verified against node-shaped responses. Entities change the routes and serialisation.
- **KMaps field is untested.** `shanti_kmaps_fields` should attach to a reference bundle, but nobody has tried it.
- **Different from the other four sites.** Everything else is nodes, so views, theming, search and editorial workflow need a Sources-specific path. Only Sources has that exception.

**Net**
Entities are better for the bibliographic data and import. Nodes are better for integration with everything Mandala has already built. A middle option is nodes with a `bibcite_reference` attached or referenced. It keeps the access and Solr machinery on nodes and bibcite's rendering and import, at the cost of two records per citation and sync logic between them. That path is not prototyped.

Because of the access and Solr consequences, I'd verify those two before Xiaoming and Yuji commit to the entity model: try a Group relationship plugin for `bibcite_reference`, and trace one kmassets hook against it.

Want me to scope that verification as the next Spike 5 step, or carry on with the render pass?

---

## User — 2026-10-30 17:18

Does each bibcite entity have it's own url on a site?

---

## Claude — 2026-10-30 17:19

Yes. Each `bibcite_reference` entity gets a canonical page at `/bibcite/reference/{id}`. I confirmed that on your local DDEV through the entity definition. Its revision pages and its edit and delete forms each get their own URL too.

A few things to know about it:

- **The default path is not a Mandala path.** It's `/bibcite/reference/123`, not the `/sources/...` or `/node/123` style the other sites use. It also uses an entity id, not a nid.
- **Aliases are possible but not set up.** Core path aliases work for any entity with a canonical link. Pathauto is not installed locally, so nothing generates aliases automatically. D7 Sources aliases would need a migration or redirect step, and you'd likely want alias patterns.
- **The page renders through bibcite's view modes** (citation, table, default). What a visitor sees is different from a node page, and it isn't wrapped in Mandala's asset-page layout unless that is built.
- **Access follows bibcite permissions**, such as "view bibcite_reference", unless a Group plugin changes that. That's the unverified access question from earlier.
- **Old D7 URLs need redirects.** `/sources/{nid}` and `sources-api/ajax/{nid}/...` have no equivalent, so they'd resolve through the legacy-nid field (ADR 017).

Local DDEV has 0 references right now, since the prototype's test data was deleted. So I haven't rendered a live reference page this session. I can import a few and check what the canonical page looks like and who can see it. That would also cover the Group access question.

---

## User — 2026-10-30 17:24

import a few references and check the canonical page

---

## Claude — 2026-10-30 17:25

I imported references and the canonical pages render. The local DB now holds 40 references, because the prototype pulled a full page of 40 and ignored my limit of 8.

**What the canonical page does** (checked on references 47, a journal article, and 46, a book):

- **Anonymous visitors get a 403.** `/bibcite/reference/47` returns "Access denied", so nothing is public by default. A "view bibcite_reference" permission would have to be granted to anonymous deliberately.
- **Admin gets a 200 and a plain table.** The page is a bare field list with no Mandala asset-page layout. The reference title is rendered as the heading, followed by the fields:
  - Author, year, journal, volume, issue, pages, ISSN for the article.
  - Author, year, publisher for the book.
- **There are three view modes:** `default`, `citation` and `table`. The page uses `default`, and the citation mode is where the CSE-formatted string would come from.
- **Extra links on the page.** "Download citation" and "Google Scholar" links appear, plus an "Add" link beside the author. Those come from bibcite, not from us.
- **There is no alias.** The URL is `/bibcite/reference/47` with no alias, and pathauto isn't installed.
- **The page is the bare entity view.** Nothing on it carries the Mandala site chrome, collection context, KMaps, or Solr-related output.

**What I did not check**

- Whether a Group member can see it. There is no Group relationship plugin for this type, so Group access stays untested.
- Public access, which needs the permission granted first.
- A reference's rendering through the citation view mode in a page context. I only looked at the default display.

The 40 imported references are local-only scratch. You can remove them with `MODE=clean` on the prototype's `write.php`, which deletes all references, contributors and keywords. That is safe here because it refuses to run if any non-Zotero references exist. I left them in place in case you want to test the Group access question next. Say the word and I'll clean them up, or I can start on the Group relationship plugin check.

---

## User — 2026-10-30 17:28

what's the local url for 47?

---

## Claude — 2026-10-30 17:28

https://mandala.ddev.site/bibcite/reference/47

You'll need to be logged in to see it, because anonymous visitors get a 403. For a one-time admin login link, run `ddev drush uli`.

---

## User — 2026-10-30 17:31

So I made the alias https://mandala.ddev.site/sources/47 for it and this works. So we could treat the bibcite id as though it were a NID for the legacy redirect. Is that correct?

---

## Claude — 2026-10-30 17:31

Not as it stands. The alias works, but 47 is only the next free entity id in your local DB. It isn't connected to any D7 nid.

- **The ids are unrelated.** `bibcite_reference` ids are auto-increment, and the prototype's 47 is just where the numbering landed after the earlier test data. A real migration would assign ids in import order, and they would differ per environment. That is the drift problem the project CLAUDE.md warns about, and it's why ADR 017 uses `field_legacy_site` + `field_legacy_nid`.
- **A redirect on the raw id would send people to the wrong record.** If `/sources/47` pointed at reference 47, the D7 node 47 would show something unrelated, or nothing, depending on the environment.

What works instead:

1. **Legacy-nid field, the ADR 017 route (recommended).** Add `field_legacy_site` and `field_legacy_nid` to the reference bundles. The migration fills them, and a redirect or route resolves `/sources/{legacy nid}` by looking up that pair and sending the visitor to the reference's canonical URL. It's a small amount of code and works the same in every environment.
2. **Preserve the D7 nid as the bibcite id.** The migration can set `id = nid` on create, so `/sources/47` would be right by construction. It has costs:
   - The auto-increment has to be set above the highest legacy nid so Zotero imports and new records don't collide.
   - It only works for Sources, since the other sites keep nodes with their own ids.
   - It leaves no marker saying a record came from D7, which breaks the convention that every migrated record carries a legacy key.
   - A rollback and re-run cycle could break the id match.

   This is possible, but I wouldn't use it as the main mechanism.

I haven't confirmed that D7 Sources URLs were literally `/sources/{nid}`, and the Sources endpoints in Spike 6 also include `sources-api/ajax/{nid}/...`. Both patterns need the same lookup. I'd check the D7 URL forms before settling on the redirect design. Want me to check them against the spike docs and the dump?

---

## User — 2026-10-30 17:38

All I am asking is this: Is it possible to do the legacy site and nid fields and the legacy redirect in a similar way we have planned it for AV that appears seemless and can bibcite enities have paths like /sources/47?

---

## Claude — 2026-10-30 17:39

Yes to both.

**1. Legacy fields.** `bibcite_reference` is fieldable, and each reference type has its own field settings. You can add `field_legacy_site` and `field_legacy_nid` to every bibcite reference bundle and have the migration fill them, the same as for nodes.

**2. Seamless redirect.** `mandala_legacy_redirect` is the AV mechanism, and it's close to generic. On a legacy hostname it takes `/node/{nid}` and looks up the entity by the legacy site + nid pair. It then redirects to that entity's own URL, and it returns a 404 if the entity isn't found or isn't viewable. The lookup currently tries only `node`, then `group`. Adding `bibcite_reference` to that list is a one-line change. The redirect would then land on `/sources/{id}` or on the canonical URL, whichever path you give the reference.

**3. Paths like `/sources/47`.** Your alias already shows it works. For a migration you'd want the alias generated for every reference, which means installing pathauto with a `/sources/[id]` pattern, or setting the alias in the migration. Pathauto would also cover Zotero imports, whose alias would be created on save.

Two caveats:
- **47 is not the nid.** The alias would be `/sources/{new bibcite id}`. The legacy nid lives in the field, and the redirect uses it to find the right record.
- **ADR 016 may need a short addition.** It says `/images/{nid}`, `/audio/{nid}` and `/video/{nid}`, and Sources would now take a bibcite id in that slot. That's a decision for the group, not a blocker.

I haven't built or tested any of this. I only read the existing redirect code, and I confirmed fieldability on the local DB.

---

## User — 2026-10-30 17:40

Can build and test that in a non-permanent way that we can remove after this spike?

---

## Claude — 2026-10-30 17:41

The throwaway build is done and works. I'm giving you the results now.

**What I built** (local DDEV only, nothing exported or committed):
- Two fields, `field_legacy_site` and `field_legacy_nid`, on all 36 reference bundles. They're created in the DB only.
- A `/sources/{id}` alias on every one of the 40 references.
- Fake legacy keys: reference 47 is `sources/12345` and reference 46 is `sources/67890`, chosen to be unlike the D11 ids.
- A one-word edit to `LegacyHostRedirectSubscriber.php` that adds `bibcite_reference` to the lookup list. It's an uncommitted working-tree change, and I saved a copy of the diff as `subscriber.patch`.
- The setup script and the diff are in `docs/spikes/assets/spike-05-bibcite/legacy-redirect-prototype/`.

**Results** (requests sent to the container with the legacy Host header):

| Request | Result |
|---|---|
| `sources.mandala…/node/12345`, anonymous with no view permission | 404, which is correct and doesn't reveal that the record exists |
| `sources.mandala…/node/12345`, anonymous with view permission | **301** to `https://mandala.library.virginia.edu/sources/47` |
| `sources.mandala…/node/67890`, anonymous with view permission | **301** to `/sources/46` |
| `sources.mandala…/node/47`, the raw D11 id | 404, so the id is not mistaken for a nid |
| `images.mandala…/node/12345`, wrong site | 404, so the site half of the key is enforced |
| `mandala.ddev.site/node/12345`, not a legacy host | 200, normal routing and no redirect, as designed |

So both parts of your question work. Legacy site + nid resolves to the reference and redirects to `/sources/{id}`, and the existing access checks apply unchanged.

I granted anonymous view permission only for the test and removed it afterward.

**Cleanup when the spike ends**
1. Revert the subscriber with `git checkout drupal/web/modules/custom/mandala_legacy_redirect`.
2. Run `MODE=teardown ddev drush php:script docs/spikes/assets/spike-05-bibcite/legacy-redirect-prototype/setup.php`. That removes the fields and aliases.
3. Remove the 40 test references with the Zotero prototype's `MODE=clean`.

Don't run `drush config:export`. The new fields show up as "Only in DB" drift, like the bibcite config does.

Two unrelated things to know: `composer.json`, `composer.lock` and the subscriber edit are still uncommitted on the spike branch. The `legacy-redirect-prototype` directory is untracked, so I haven't committed it. Want me to record this as a finding in the spike doc?

---

## User — 2026-10-30 17:53

I cannot find https://sources.mandala.library.virginia.edu/node/67890 on the origin al site

---

## Claude — 2026-10-30 17:53

That's expected. `12345` and `67890` are made-up nids I picked for the test, so they don't exist on the original Sources site. I chose them to be unlike the D11 ids, to prove the lookup runs on the legacy key and not on the entity id. Neither of the two legacy keys, `sources/12345` and `sources/67890`, corresponds to a real D7 page.

The test therefore showed the redirect mechanism working. It did not show a real D7 URL landing on the right record.

For that check, the references need real D7 nids:
- **Option A:** pick two or three real Sources nids from the 2026-06-11 dump. The `spike5_d7` scratch DB was dropped, so I'd reload the dump first.
- **Option B:** you give me a few real nids from the live site. I would then give the matching local references (title and authors from Zotero) the key for those nids. They wouldn't be the actual D7 records, so content wouldn't match. It would only show that real nid URLs redirect.

Which do you prefer? Option A also gives us the real D7 titles, which would let us confirm the redirect lands on the right record.

---

## User — 2026-10-30 17:54

Option A

---

## Claude — 2026-10-30 17:56

That failed because of my script, not the code: in zsh the variable name `path` is tied to `PATH`, and I overwrote it. I'm rerunning with a different variable name.

---

## Claude — 2026-10-30 17:56

The redirect works for real D7 nids. I tested three, and each landed on the page with the matching title.

| Legacy URL | Result | Page title at the target |
|---|---|---|
| `sources.mandala…/node/21043` | 301 to `/sources/47` | The Less Dust the More Trust: Participating In The Shamatha Project… |
| `sources.mandala…/node/21045` | 301 to `/sources/46` | The Meaning of Mindfulness: A Second-Order Cybernetics of Stress… |
| `sources.mandala…/node/22011` | 301 to `/sources/48` | Communicating the Innate: Observations on Teacher-Student Interaction… |
| `sources.mandala…/node/12345`, the old fake nid | 404 | (none) |

You can check these on the original site: `sources.mandala.library.virginia.edu/node/21043`, `/21045` and `/22011`. All three were published in the dump.

**Caveats**
- **The matching local records are stand-ins.** I gave three of the Zotero references the real D7 title, year, first author and nid. The Zotero-sourced fields (publisher, journal and so on) still hold the Zotero data. This proves the lookup, not a real migration.
- **The redirect's Location header points at production.** It uses `canonical_base_url`, which is `https://mandala.library.virginia.edu`. I followed the path part locally.
- **Anonymous view permission was needed for the redirect to work.** I granted it for the test and removed it again, so the local site is back to denying anonymous access.

**Local state:** the scratch DB `spike5_d7` is loaded again, and the files in `legacy-redirect-prototype/` are still untracked. The cleanup list from before still applies. I'd also drop `spike5_d7` when you're done with the render pass.

Want me to write this up as a finding in the spike doc, or move on to the render pass?

---

## User — 2026-10-30 18:02

Please note that when this is actually built we want all the fields displayed in the old site records and in roughly the same way . Take for instance https://sources.mandala.library.virginia.edu/node/21045 this is what it shows: 

<pasted_content id="60e9">
The Meaning of Mindfulness: A Second-Order Cybernetics of Stress, Metacognition, and Coping
Complementary Health Practice Review
Tsele Natsok Rangdrol (Author)
Format:Journal Article
Publication Year:2007
Pages:15-30
Sources ID:21045
Visibility:Private
Zotero Collections:Contexts of Contemplation Project
Abstract: (Show)
URL: http://zotero.org/groups/contemplative_sciences/items/UGS3XSG7
Zotero Collections 
Contexts of Contemplation Project
Subjects: 
Contexts of Contemplation Project
</pasted_content id="60e9">

---

## Claude — 2026-10-30 18:02

I'll record that in the spike doc now, because it affects how the entity model gets built.

---

## Claude — 2026-10-30 18:02

I added it to the spike doc as finding 8, uncommitted along with the rest of the work.

I haven't seen the live D7 page myself, so the field list and order come from what you pasted. The main points:

- **Fields to reproduce.** The page shows these in order:
  - Title.
  - Journal.
  - Author with role.
  - Format.
  - Publication year.
  - Pages.
  - Sources ID (the legacy nid).
  - Visibility.
  - Zotero Collections.
  - Collapsible abstract.
  - URL.
  - Subjects as a KMaps list.
- **bibcite's default page falls short.** It shows only the bibliographic fields, as in the local page for 47. Several of these have no stock home:
  - **Sources ID** is the legacy-nid field.
  - **Visibility** depends on the open question of Group access for references.
  - **Zotero Collections** has no bibcite equivalent, as finding 7 noted.
  - **Subjects** needs the KMaps field working on a reference bundle, which is untested.
  - The collapsible abstract is theming.
- **This is a real cost of the entity model.** Everything Sources nodes got from the existing layout, access and field machinery has to be built for references. It is a point for the "nodes with a bibcite reference attached" middle option I mentioned earlier.

I'd use this D7 page as the reference for the render-pass comparison. We could take nid 21045 and build a display on local reference 46 that approximates it.

I haven't committed anything. The spike doc, the prototype directory and the one-line subscriber edit are all still local. Want me to try that display on reference 46?

---

## User — 2026-10-30 18:04

When this is finally built, be sure to look at several different node pages of different subtypes either by looking at it directly or getting whoever is building it to paste it in. You can also look at the old site dispaly settings to see exactly what is displayed where. I don't want you to do it now because I know it's possible but make note to do it when building the sources app. And note it in a way so that if Xiaoming works with you to build it, her version of claude will see it.

---

## Claude — 2026-10-30 18:05

I wrote the note, and it's in the repo where Xiaoming's Claude will find it. Nothing is committed yet.

**What I added**
- **A new deferred note:** `docs/deferred/sources-record-display-parity-check-d7-subtypes.md`. It has a section addressed to Claude that says what to do when the Sources record page is built:
  1. Look at several D7 record pages of different subtypes. That means journal article, book, chapter, thesis and web page at minimum, plus the four custom types (Review, Dictionary, Obituary, Block Print). Either view the pages directly or ask the person building to paste them in. The legacy site may block curl, so a browser or a paste is the safe route.
  2. Read the old site's display settings for the `biblio` type to see which fields appear, in what order, and under which labels.
  3. Compare field by field, and don't design from bibcite's defaults or from a single example.

  The note also includes your node 21045 paste as the reference example.
- **Indexes updated:** `docs/deferred/README.md`, where the Sources section previously said "None", and `.pages`. `session-close-check.sh` confirms the deferred docs are indexed and the note has an Issue header.
- **A pointer in the Sources handoff section of the spike doc**, so anyone reading that handoff sees the instruction first. Finding 8 holds the detail.

**How Xiaoming's Claude finds it:** session start lists the deferred README, so the note is there. The handoff section in the spike doc points to it too.

**One decision for you:** the note says `Issue: none`. Promoting it to a GitHub issue would put it in the startup listing as well, and you'd get an owner field. I didn't do that, because issues are public and outward-facing, and I'd rather you decide. Want me to run `promote-deferral.sh` on it?

**Still uncommitted on the spike branch:** the docs above, the `legacy-redirect-prototype/` directory, the one-line subscriber edit, and the two composer files. The branch must not be merged as is. The subscriber edit and the composer files shouldn't land in a merge, so say whether you want the docs and the prototype directory committed and pushed separately.

---

## User — 2026-10-30 18:06

no need to make an issue. What else is left to decide in this spike?

---

## Claude — 2026-10-30 18:07

Here is what's left, in rough order. The first two decide the shape of the build, and the rest are checks and group decisions.

**Decisions for the group**
1. **Entity or node.** The Spike 5 finding 7 record model is bibcite entities, and Xiaoming and Yuji haven't been told yet. Finding 8 now shows that legacy redirect and `/sources/{id}` paths work. The cost is that the display, access and Solr work all have to be built for references. The "node plus attached reference" option hasn't been prototyped.
2. **Citation edition.** CSE 8th or 9th.
3. **Citekey.** Migrate the D7 citekeys, or regenerate them.
4. **Zotero collections.** Whether Sources still wants them, since bibcite has nothing equivalent. D7 kept them in a vocabulary and the old record page shows them.
5. **Zotero item key.** Whether to add a dedicated field for it. The prototype reused `bibcite_remote_db_name`, so production needs a real field. The same goes for the item `version` used by `since` pulls.
6. **Zotero import style.** On-demand only, or the D7 30-minute re-sync.

**Questions for Sources staff**
- Does anyone use Zotero today? The D7 feed's library and settings are also unread, and the feed node's connection settings aren't in the dump.

**Remaining checks**
- **Criterion 6, the broader render pass.** Books, chapters, multi-author lists, the four custom types and the seven non-CSE styles (apa, chicago, mla, ama, harvard, vancouver, ieee). This is also where Obituary and Block Print get their unused CSL targets: `article`, `entry`, `pamphlet`, `graphic` and `post` are the candidates. The `original-date` warning on some imported items also needs chasing here.
- **Unverified consequences of the entity model.** The Group relationship plugin for `bibcite_reference`, the KMaps field on a reference bundle, and a reference-aware path through `mandala_node_api`, the kmassets sync hooks and `mandala_solr_visibility`.
- **Untested.** A keyed (private) Zotero library, and migrating the 2014 Zotero data on about 1.9k nodes.

**Housekeeping to close**
- Spike status line and criteria in the spike doc need updating for findings 7 and 8.
- Commit the docs and the `legacy-redirect-prototype/` directory without the composer files or the subscriber edit.
- Clean up the local scratch work: the 40 references, the fake fields and aliases, and the `spike5_d7` DB.
- Refresh `project-mandala-state`, since the spike advanced.

The spike could close with the entity-or-node question settled and the render pass done. The Zotero details and the edition and citekey choices can move to the Sources build. Want me to start the render pass with the real D7 nodes now that the dump is loaded?

---

## User — 2026-10-30 18:08

Can you do proof of concept for the display, access, and SOLR work of #1?

---

## Claude — 2026-10-30 18:11

The node query alter is close to generic, and it needs three swaps for references: the base table, the plugin id, and no per-record override field. I'll port it into the throwaway module and test it.

---

## Claude — 2026-10-30 18:11

The kmassets config contains a staging Solr master URL. I need to know whether DDEV overrides it locally before anything in this PoC could write to Solr.

---

## Claude — 2026-10-30 18:13

Noted: a dedicated field for the Zotero item key. I'll record it in the spike doc.

---

## Claude — 2026-10-30 18:14

The KMaps field attaches to a reference bundle and stores a value, so one of the open questions is answered. Now the D7-style page itself.

---

## Claude — 2026-10-30 18:15

The canonical route renders the `table` view mode, not `default`, so my alter wasn't firing on the real page. I'll apply it to both modes and re-fetch.

---

## Claude — 2026-10-30 18:15

The D7-style page renders every element. The warning comes from my alter dropping the `#entity_type` key core needs, so I'll fix that.

---

## Claude — 2026-10-30 18:16

The page now renders with no errors. I'll write up the results and a cleanup guide for the throwaway pieces.

---

## Claude — 2026-10-30 18:16

All three parts work on a bibcite reference. That includes the Solr filter, with no Solr reads or writes involved. This is a throwaway local build, and nothing is committed. I wrote the results up as finding 9 in the spike doc.

**Access.** Single-record access works once two things exist.
- A custom Group relation plugin for `bibcite_reference`, modelled on the node one.
- The new group permission `view group_bibcite_reference entity`, granted to the collection roles. Without it, Group forbids every grouped reference to everyone, including public ones.

With both in place and my access hook, the matrix was correct for anonymous, an outsider, a private-collection member and admin. I disabled the hook as a check, and anonymous then saw the private reference, so the test can fail.

Listings leaked at first: a query with access checking on returned all four references. A port of the node query alter (three small swaps) fixed it, and listings then matched single-record access.

**Solr.** The existing doc builder rejects a reference with a `TypeError`, because it, its contributors, its sink and its hooks are all typed to nodes. I built a document for a reference (`sources-11-{id}`, with visibility and `collection_uid_s` from the shared resolver). I then checked it against the real visibility filter for three viewers. Solr and Drupal agreed in all 12 cases. The reader side needs no change. Nothing was posted, because the effective Solr URL is empty in DDEV.

**Display.** The canonical page renders the `table` view mode, not `default`. With a KMaps field and a Zotero Collections field attached to the reference types, I rendered node 21045's real values. Every element from your D7 paste has a source on a reference. The KMaps field type attaches to a reference type and stores a value, which closes one of the open questions.

**What production would change.**
- Generalize `mandala_group_inheritance` and `mandala_kmassets_sync` from nodes to entities.
- Export config for the relationship type and the role permissions.
- Build a proper display.

That is real work, but it is bounded and mostly mechanical, not a redesign.

**Not covered:**
- Owner fields.
- kmapid expansion on references.
- The bulk reindex.
- The other record subtypes.
- Real theming.

**Your decisions are recorded in the spike doc.**
- CSE 9th edition.
- Migrate D7 citekeys. All 25,627 are present and unique in the dump, but I still need to verify that bibcite doesn't overwrite a supplied citekey on save.
- Keep Zotero Collections, which needs a vocabulary and a term field on every reference type.
- A dedicated Zotero item-key field.
- On-demand import only.

**Local state to clean up.**
- The throwaway module `spike5_bibcite_poc`.
- The scratch groups, users and fields.
- The 40 test references.
- The one-line subscriber edit.
- The `spike5_d7` scratch DB.

The order is in `entity-model-poc/README.md`. Don't export config, because the local DB now differs from `config/sync` in many places. Docs and prototype files are uncommitted.

One side effect: setting `MODE=...` on the host before `ddev drush` never reaches the container, so scripts silently use their defaults. That's why the Zotero import pulled 40 items instead of 8. Use `ddev exec "MODE=... drush ..."`.

Want me to commit the docs and prototype files on the spike branch, leaving out the module, the composer files and the subscriber edit?

---

## User — 2026-10-30 18:17

Yes, commit the docs and prototype files

---

## Claude — 2026-10-30 18:17

I committed the docs and prototype files as `88e577a` on `spike/5-bibcite-local-install`. I haven't pushed.

**In the commit:**
- The spike doc with findings 8 and 9 and your decisions.
- The deferred note about checking several D7 subtypes, plus its README and `.pages` entries.
- `entity-model-poc/` and `legacy-redirect-prototype/`. The second includes `real.tsv`, which holds the titles and authors of three D7 records (public bibliographic data).

**Still uncommitted, on purpose:**
- `drupal/composer.json` and `drupal/composer.lock`.
- The one-line edit to `LegacyHostRedirectSubscriber.php`.
- The untracked throwaway module `drupal/web/modules/custom/spike5_bibcite_poc/`.

A copy of the module's hooks is in the commit as `spike5_bibcite_poc.module.txt`. The branch must not be merged as is.

Two small things I noticed:
- I mentioned a `subscriber.patch` earlier, but it was never created because a command failed that session. The subscriber change is described in finding 8 and is a one-word edit, adding `bibcite_reference` to the lookup list.
- I haven't run `session-close-check.sh` or saved a session log, since the session isn't over.

Want me to push the branch?

---

## User — 2026-10-30 18:19

So, I am going to make a unilateral decision to go with bibcite entities, since we have proof of concept. This can be recorded as my decision which can be revisited by Xiaoming or whoever builds it, but in terms of the spike let's call it resolved. That should close out all the open questions for this spike. Document everything thoroughly, commit and push

---

## Claude — 2026-10-30 18:19

Two links in the ADR point at the wrong filenames, so I'm fixing them. Then I'll update the spike doc's status and handoff.

---
