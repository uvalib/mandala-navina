# AV10: Kaltura configuration layer — scope note

**Status:** Implementation scoped, 2026-09-15. Not yet built.
**Depends on:** AV1 (Spike 7 — module survey, playback prototype).
**Blocks:** AV9 (player formatter needs this to render from), AV12 (upload widget needs the uploader config), AV13 (migrates D7's real values into this layer's registry).
**Relates to:** [Sprint 3](../sprints/sprint-03-av-core-implementation.md)'s "Design note: uploads and multiple players" section, which this note supersedes with a concrete architecture — read that first for the *why* (multiple players, D7's real element set, the security note on credentials).

> **Revised 2026-09-15.** The original (2026-09-14) version of this note proposed a
> `kaltura_player_config` **config entity**. Reconsidered once implementation cost was
> actually weighed, not just the abstract shape: this codebase has **zero custom config
> entity types** today — every other structured setting (including
> `mandala_kmassets_sync.settings`, solving the identical "named, reusable, addable via
> config" shape) is a plain config object, no entity CRUD, no admin UI. A config entity
> means building an entity class, list builder, add/edit forms, routing, and permissions
> from scratch for presets that, per this project's own existing pattern, were never going
> to get a live admin UI anyway. Replaced with a single `mandala_kaltura.settings` config
> object holding a keyed `presets` array — same requirements met (named, reusable, addable
> without code — a new preset is a new YAML key), a fraction of the cost. **Confirmed with
> Yuji: this is engineering-owned, config-only, no write UI needed** — adding a preset means
> editing YAML and running `config:import`, exactly like every other setting in this
> project. The "Proposed architecture" and "Secrets" sections below reflect this revision;
> the problem statement and D7 element set above them are unchanged.

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

## Proposed architecture (revised 2026-09-15)

**A single config object**, `mandala_kaltura.settings`, in a new custom module `mandala_kaltura` (depends on `kaltura_media`). Site-level constants as top-level keys; named player configurations as a keyed `presets` map — same "named, reusable, addable without code" shape as the rejected config-entity version, at the cost of one YAML file instead of an entity type's worth of scaffolding.

```yaml
# config/sync/mandala_kaltura.settings.yml
partner_id: '381832'
subp_id: '38183200'
server_url: '//www.kaltura.com'
presets:
  default:
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

`partner_id`/`subp_id`/`server_url` are **not secret** — checked directly against `settings.php`'s actual pattern: `mandala_kmassets_sync.settings.solr_master_url` (a comparable non-secret internal value) is simply committed straight into `config/sync`, no env-var/`settings.php` override plumbing at all. Same here — these three constants are typed directly into the config object, versioned normally.

**Write path: engineering-owned, config-only, confirmed with Yuji 2026-09-15.** Adding or changing a preset means editing `mandala_kaltura.settings.yml` and running `config:import` — no admin form, no UI. If AV staff self-service ever becomes a requirement, that's a scope change to revisit then, not something to build speculatively now.

**Read path — the only code AV10 needs to ship**: a small service, `mandala_kaltura.resolver` (`KalturaConfigResolver`), with one real method — given a preset id, return that preset merged with the top-level site constants (`partner_id`/`subp_id`/`server_url`) as one flat array. This is the entire surface AV9's formatter consumes.

**Selection**: AV9's formatter gets a settings-form dropdown — "Player configuration" — populated from `array_keys($resolver->getPresetIds())`, stored per view mode via the normal `core.entity_view_display.*.yml` formatter `settings` mechanism Drupal already provides. No new selection mechanism needed.

**What AV10 does NOT need to build**: the actual embed/render logic (AV9's job — it calls the resolver, passes the result to an extended `kaltura-player.html.twig`), and no config entity CRUD of any kind.

## Secrets (AV11's actual consumer, scoped here since the pattern must exist before AV11 can use it)

Only the credential pair needs the deploy-time secret pattern — **not** `partner_id`/`subp_id`/`server_url` (see above, these are plain committed config). Follow the established `container_0.env.{managed,secret}` split exactly — this is a decided, non-negotiable convention (see [[reference-deploy-secret-ccrypt-pattern]]), confirmed live in `terraform-infrastructure/mandala/drupal/staging/ansible/`:

| Var | File | Notes |
|---|---|---|
| `KALTURA_ADMIN_SECRET` | `.env.secret` → committed only as `.env.secret.cpt` | AV11 consumes this **directly via `getenv()`** to mint a KS server-side — never through Drupal config, never reaches the browser |
| `KALTURA_SECRET` | `.env.secret` → `.cpt` | Same treatment |

Add both to `deploy_backend.yml`'s `required_env_vars` list (currently 11 entries, e.g. `MYSQL_HOST`, `SIMPLESAML_SECRET_SALT`) — by the playbook's own established "split by failure mode" logic, a missing Kaltura secret should fail the deploy loudly, not ship a silently-broken uploader. **This terraform-infrastructure change is a real prerequisite for AV11**, not AV10 itself, but the design above is what makes it a clean two-line addition instead of a redesign later.

## Relationship to AV11/AV12/AV13

- **AV11** (KS minting) consumes `partner_id` (via the resolver, or directly from config) + the two secret env vars directly via `getenv()`. Independent of the presets map above — AV11 doesn't need to know about named player presets, just the account-level credentials.
- **AV12** (upload widget) consumes a preset's `custom_cw` (uploader ui_conf) — this is why `custom_cw` lives on the SAME preset as the player settings rather than a separate registry: D7 keeps them paired per context, and AV12 will want "the same context's" uploader config, not a global one.
- **AV13** (migrate D7's real per-view-mode values) is a data-population task against this registry, not a design task — the exact values are already in the sprint doc's table, keyed by D7 view mode (`default` vs the instance/widget settings), and just need mapping onto named presets under `mandala_kaltura.settings.presets` once this schema exists. A reasonable first cut: three presets — `default` (`31832371`), `node_embed` (`24762821`, matching the live node-view embed Spike 6 found), and whatever the React app's `31832371` hardcode actually corresponds to once checked against which view mode serves that surface.

## Open questions (need a decision before or during build, not blocking the scoping itself)

1. **Exact module/service naming** — cosmetic, pick at build time.
2. ~~Does `delivery`/`stretch`/`rotate` actually change the embed's JS parameters~~ **Resolved 2026-09-15, confirmed by reading the real D7 embed code** (`field_kaltura_build_embed()`, `mandala-drupal/.../field_kaltura.module`): the production `kWidget.embed()` call only ever passes `targetId`/`wid` (`_{partner_id}`)/`uiconf_id`/`entry_id` — **`delivery`, `rotate`, `stretch`, and `thumbsize_height`/`thumbsize_width` are not consumed by the actual embed at all**, in D7 or otherwise. `player_height`/`player_width` do get used, but only to size the *outer CSS container* (a responsive aspect-ratio wrapper), never passed into the player's own JS config. `custom_player` genuinely does what the field name says — it overrides `uiconf_id` outright when set (`$uiconf = !empty($settings['custom_player']) ? $settings['custom_player'] : $settings['entry_widget'];`). **AV9's formatter should replicate exactly this** — `width`/`height` for the container, `uiconf_id`-or-`custom_player`/`partner_id`/`entry_id` for the actual embed — and carry `delivery`/`rotate`/`stretch`/`thumbsize_*` as inert config fields for schema parity only, per ADR 008 (floor = faithful migration of *actual* user-facing behavior, and D7's actual behavior never wired those fields to anything). Don't build logic for them.
3. **Where does the "3rd+ player, added as config only" acceptance-criterion proof happen** — this note's schema supports it structurally (any new key under `presets` is immediately selectable), but the actual acceptance test (sprint doc: "a fourth added *as config only* to prove new players need no code change") is AV9/AV13's verification step, not something AV10 itself needs to demonstrate ahead of time.

## Why a plain config object, not a config entity or formatter-only settings

Two alternatives considered and rejected, for opposite reasons:

- **Formatter-only settings** (`third_party_settings` on each view display, no shared registry) — fails the "reusable, named, addable without code" requirement: the same "default" player config would need hand-typing into every view mode/bundle combination that uses it, with no single place to audit "what players exist."
- **A `kaltura_player_config` config entity** (this note's original 2026-09-14 proposal) — satisfies the requirement, but at real, unnecessary cost: this would be the first custom config entity type in the codebase (entity class, list builder, forms, routing, permissions), for a write path (a live admin UI) that isn't actually wanted — **D7 never had a self-service UI for this either** (per ADR 008/MVP-migrate-not-improve, matching the floor D7 already set, not adding a new capability beyond it), and Yuji confirmed 2026-09-15 this stays engineering-owned.

The chosen shape — one config object, a `presets` map, a read-only resolver service — sits exactly between the two: a shared, named, auditable registry (what formatter-only settings lack) built with zero new entity scaffolding (what the config-entity version didn't need to spend).
