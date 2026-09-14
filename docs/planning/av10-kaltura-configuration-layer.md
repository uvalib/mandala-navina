# AV10: Kaltura configuration layer — scope note

**Status:** Scoping, 2026-09-14. Not yet built.
**Depends on:** AV1 (Spike 7 — module survey, playback prototype).
**Blocks:** AV9 (player formatter needs this to render from), AV12 (upload widget needs the uploader config), AV13 (migrates D7's real values into this layer's registry).
**Relates to:** [Sprint 3](../sprints/sprint-03-av-core-implementation.md)'s "Design note: uploads and multiple players" section, which this note supersedes with a concrete architecture — read that first for the *why* (multiple players, D7's real element set, the security note on credentials).

## What already exists (don't re-derive it)

`drupal/kaltura_media` (contrib, 1.0.4, D11-patched, already inert on `main`) provides:

- **Field type `kaltura`** (`KalturaItem`): stores `entry_id`/`partner_id`/`uiconf_id`/`domain` per field value. `field_video`/`field_audio` (cardinality 1) already use it.
- **A working baseline formatter** (`kaltura_default` → `#theme: kaltura_player` → `templates/kaltura-player.html.twig`): embeds via Kaltura's universal `kWidget.embed()` JS API, using only `entryId`/`partnerId`/`uiConfId`/`domain`. **No dimension, delivery, rotate/stretch, or per-view-mode support at all** — this is genuinely minimal, proven only as far as "a video plays" (Spike 7's prototype).
- **AV4 already migrated real values into every node**: `field_video/uiconf_id` etc. come from the migration's `constants` block (`kaltura_uiconf_id: '24762821'`, `kaltura_partner_id: '381832'`, `kaltura_domain: www.kaltura.com` — see `migrate_plus.migration.d7_av_video.yml`). **This is a single frozen value copied onto every node at migration time, not a per-view-mode setting** — it's what makes today's baseline formatter work at all, but it's exactly the thing AV10 needs to stop being the source of truth for, since D7 actually varied several of these per view mode (see the sprint doc's element-set table: at least 3 distinct `uiconf_id`s in real production use).

No Kaltura-specific Drupal config, settings.php wiring, or custom module exists yet — confirmed by direct inspection, not assumed. This is a greenfield build on top of a proven-but-minimal foundation, not a rebuild.

## The problem AV10 solves

The sprint's own design note already established the real requirement precisely: this is "a configuration *layer*, not a couple of formatter settings bolted on." Concretely:

1. **Multiple named player configurations must coexist** — at least 3 known `uiconf_id`s are live in production today (`31832371`, `48501`, `24762821`), used in different contexts (default view mode, node-view embed, the React app), "more expected." A single site-wide default is not sufficient.
2. **Each configuration is a bundle of settings, not just a player ID** — per the sprint doc's table: `entry_widget` (player), `custom_cw` (a *separate* ui_conf for the upload widget), `delivery`, `player_height`/`width`, `thumbsize_height`/`width`, `rotate`, `stretch`, `custom_player`.
3. **Selection is per view mode**, not per bundle or per site (D7's `teaser` view mode hides AV content entirely; `default` uses different dimensions than the node-view embed).
4. **New elements/players must be addable as config, no code change** — an explicit requirement, not a nice-to-have.
5. **Site-level constants and credentials are a separate concern** from per-player config: `kaltura_partner_id`/`kaltura_subp_id`/`kaltura_server_url` are site-wide facts; `kaltura_admin_secret`/`kaltura_secret` are credentials that must never reach `config/sync` or the browser (D7 stores them in cleartext — do not replicate that).

## Proposed architecture

**A new config entity type**, `kaltura_player_config` (working name — bikeshed later), living in a new custom module (`mandala_kaltura` or similar, TBD at build time). Modeled on Drupal's own `image_style` pattern: a named, reusable, exportable config entity, listable/selectable from a formatter's settings form — because that's exactly this problem shape (multiple named presets, selected per-context, must be addable without code).

Proposed schema (fields drawn directly from the D7 element set the sprint doc already inventoried, not invented):

```yaml
# config/sync/mandala_kaltura.player_config.<machine_name>.yml (example shape)
id: default
label: 'Default player'
uiconf_id: '31832371'
custom_cw: '4396241'        # uploader Contribution-Wizard ui_conf — used by AV12, not AV9
delivery: HTTP               # deliberate choice, not a blind RTMP port — see sprint doc
player_height: 425
player_width: 880
thumbsize_height: 45
thumbsize_width: 80
rotate: 0
stretch: null
custom_player: ''
```

**Site-level constants** (`partner_id`, `subp_id`, `server_url`) do NOT belong in this config entity — they're one value each, site-wide, not per-player. These go in a plain `mandala_kaltura.settings` config object (config, not secret — matches `KALTURA_PARTNER_ID`/`KALTURA_SUBP_ID`/`KALTURA_SERVER_URL`'s designation as non-secret in the sprint doc's split table), sourced from environment at deploy time the same way `mandala_kmassets_sync.settings.solr_master_url` already is — i.e. read via `settings.php`'s `$config[]` override pattern from env vars, not typed into `config/sync` directly, so per-environment values (dev/staging/prod partner IDs, if they ever differ) don't require a config-sync diff.

**Selection**: the field formatter (AV9's job to build) gets a formatter setting — "Player configuration" — a `<select>` populated from `kaltura_player_config` entities, stored per view mode via the normal `core.entity_view_display.*.yml` `third_party_settings`/formatter `settings` mechanism Drupal already provides for exactly this purpose. No new selection mechanism needed — this is what view-mode-scoped formatter settings are *for*.

**What AV10 does NOT need to build**: the actual embed/render logic. That stays AV9's job — AV10 only needs to produce the resolved settings; AV9's formatter reads the selected `kaltura_player_config`, merges it with `mandala_kaltura.settings`' site constants, and passes the result to (an extended version of) the existing `kaltura-player.html.twig` embed.

## Secrets (AV11's actual consumer, scoped here since the pattern must exist before AV11 can use it)

Follow the established `container_0.env.{managed,secret}` split exactly — this is a decided, non-negotiable convention (see [[reference-deploy-secret-ccrypt-pattern]]), confirmed live in `terraform-infrastructure/mandala/drupal/staging/ansible/`:

| Var | File | Notes |
|---|---|---|
| `KALTURA_PARTNER_ID` | `.env.managed` | `381832` today |
| `KALTURA_SUBP_ID` | `.env.managed` | `38183200` |
| `KALTURA_SERVER_URL` | `.env.managed` | `//www.kaltura.com` |
| `KALTURA_ADMIN_SECRET` | `.env.secret` → committed only as `.env.secret.cpt` | AV11 consumes this to mint a KS server-side; never reaches the browser |
| `KALTURA_SECRET` | `.env.secret` → `.cpt` | |

Add the two secret vars to `deploy_backend.yml`'s `required_env_vars` list (currently 11 entries, e.g. `MYSQL_HOST`, `SIMPLESAML_SECRET_SALT`) — by the playbook's own established "split by failure mode" logic, a missing Kaltura secret should fail the deploy loudly, not ship a silently-broken uploader. **This terraform-infrastructure change is a real prerequisite for AV11**, not AV10 itself, but the config-layer split above is what makes it a clean two-line addition instead of a redesign later.

## Relationship to AV11/AV12/AV13

- **AV11** (KS minting) consumes `mandala_kaltura.settings` (partner_id) + the two secret env vars. Independent of the player-config entity type above — AV11 doesn't need to know about named player presets, just the account-level credentials.
- **AV12** (upload widget) consumes a player config's `custom_cw` (uploader ui_conf) — this is why `custom_cw` lives on the SAME config entity as the player settings rather than a separate registry: D7 keeps them paired per context, and AV12 will want "the same context's" uploader config, not a global one.
- **AV13** (migrate D7's real per-view-mode values) is a data-population task against this registry, not a design task — the exact values are already in the sprint doc's table, keyed by D7 view mode (`default` vs the instance/widget settings), and just need mapping onto named `kaltura_player_config` entities once this schema exists. A reasonable first cut: three presets — `default` (`31832371`), `node_embed` (`24762821`, matching the live node-view embed Spike 6 found), and whatever the React app's `31832371` hardcode actually corresponds to once checked against which view mode serves that surface.

## Open questions (need a decision before or during build, not blocking the scoping itself)

1. **Exact config entity ID/module name** — cosmetic, pick at build time.
2. **Does `delivery`/`stretch`/`rotate` actually change the embed's JS parameters in a way worth modeling now**, or are `rotate`/`stretch` (both `0`/`NULL` in every real D7 row per the sprint table) effectively unused and safe to carry as inert config for parity without wiring real behavior yet? Recommend: model the field, don't build logic for it, until a real non-zero value is found in D7 data — avoids speculative work.
3. **Where does the "3rd+ player, added as config only" acceptance-criterion proof happen** — this note's schema supports it structurally (any new `kaltura_player_config` entity is immediately selectable), but the actual acceptance test (sprint doc: "a fourth added *as config only* to prove new players need no code change") is AV9/AV13's verification step, not something AV10 itself needs to demonstrate ahead of time.

## Why a config entity, not formatter-only settings

Considered and rejected: storing everything as per-view-mode formatter `third_party_settings` directly, with no separate entity type. Rejected because it fails requirement 4 above (reusable, named, addable without code) — formatter settings are per-(bundle, view mode), not a shared named registry, so the same "default" player config would need to be hand-typed identically into every view mode/bundle combination that uses it, and there'd be no single place to audit "what players exist" the way `image_style` gives you for image styles today. The config-entity approach costs one extra module and a settings-form dropdown; the formatter-settings-only approach costs silent duplication and drift the first time someone updates one context's player ID and forgets the other three.
