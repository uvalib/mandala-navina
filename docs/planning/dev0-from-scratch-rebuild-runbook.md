# dev-0 from-scratch rebuild runbook

**Decision:** the group, 2026-08-26 — rebuild dev-0 from an empty database rather than
`migrate:rollback` → `import`.
**Applies to:** dev-0 only. **Not** a production procedure.
**Related:** [1a.9 acceptance checklist](1a9-staging-acceptance-checklist.md),
[ADR 016](../adr/016-public-url-structure-single-host.md), [ADR 017](../adr/017-legacy-identity-composite-key.md)

## Why from scratch rather than rollback → import

`migrate:rollback` does not reset `AUTO_INCREMENT`. Every cycle therefore assigns **higher
nids** — "reversible to *clean*, not to *identical*". Three consequences settled it:

1. **Deterministic nids.** From an empty database, nid *N* maps to the same D7 row every
   time. Both ADR 016's redirects and the kmassets `images-11-{nid}` uids are keyed on D11
   nids, so under rollback→import **every rebuild silently invalidates them**.
2. **It clears the dev-0 duplicate by construction** (D7 nid `981206` → D11 nids 76584 and
   76585) rather than hoping rollback does.
3. **It rehearses cutover.** Production D11 will be built fresh, never rolled back.

## Pre-flight assessment (done 2026-08-26 — recorded so it can be re-checked, not assumed)

| Item | Finding | Action |
|---|---|---|
| Config drift | `config:status` → **No differences** | none — all in `config/sync` |
| Nodes / groups without `field_legacy_nid` | **0 / 0** | none — all migration-produced |
| Users without a `migrate_map_d7_users` row | **0** (of 1,543) | none — all migration-produced |
| Roles | `anonymous`, `authenticated`, `content_editor`, `administrator` | all in `config/sync` |
| `sites/default/files` (1.9M), `keys/` | bind-mounted | survive the DB reset |
| **`solrproxy` OAuth2 consumer** | hand-registered; secret is **hashed**, unreadable | **must be recreated — step 5** |
| uid-600 test IdP authmap link | hand-made via `linkExistingAccount()` | **must be redone — step 7** |

**`hash_salt` is safe.** `settings.php:359` reads it from the environment
(`getenv('DRUPAL_HASH_SALT')`) and never writes it into the file, so `site:install` cannot
change it. Confirmed present in the container (74 chars), injected from
`container_0.env.secret` by `deploy_backend.yml`. Same for `SIMPLESAML_SECRET_SALT` and
`SOLRPROXY_CLIENT_SECRET` — **no secret needs to be decrypted, copied or typed.**

## The rebuild

Run **on dev-0**. `D` is shorthand for
`sudo docker exec mandala-drupal-0 /opt/drupal/app/drupal/vendor/bin/drush`.

### 1. Checkpoint first

```bash
./scripts/db-checkpoint.sh save pre-rebuild
```
`restore` is proven (2026-08-26, exact against live), so this is a real floor, not a
formality.

### 2. Confirm the pre-flight still holds

```bash
$D config:status          # must say: No differences
$D php:eval 'echo getenv("DRUPAL_HASH_SALT") ? "salt present\n" : "SALT MISSING\n";'
```
**Stop if either fails.** A missing salt means the rebuilt site gets
`temporary-local-dev-only`, silently.

### 3. Install from existing config

```bash
$D site:install --existing-config -y
```

`--existing-config` installs **from `config/sync`** rather than the profile defaults, so the
site UUID (`dfc3f060-…`, in `system.site.yml`), `core.extension`, all fields and the ADR 015
roles come back exactly as committed. `config_sync_directory` is `../config/sync`
(`settings.php:952`), baked into the image.

This drops existing tables — that *is* the reset; no separate drop step is needed.

> ⚠ uid 1's password is regenerated. Get a login link with `$D uli` when needed.

### 4. Verify the install before building on it

```bash
$D config:status                       # No differences
$D php:eval 'echo \Drupal::config("system.site")->get("uuid") . "\n";'   # dfc3f060-…
$D pm:list --status=enabled --format=list | wc -l
```

### 5. Recreate the `solrproxy` OAuth2 consumer

The one thing a fresh install cannot rebuild. Read the secret **from the container's own
environment** — never paste it:

```bash
$D php:eval '
$s = getenv("SOLRPROXY_CLIENT_SECRET");
if (!$s) { echo "ABORT: SOLRPROXY_CLIENT_SECRET not in env\n"; return; }
$c = \Drupal\consumers\Entity\Consumer::create([
  "client_id" => getenv("SOLRPROXY_CLIENT_ID") ?: "solrproxy",
  "label" => "Solr Proxy (ADR 014)",
  "secret" => $s,
  "automatic_authorization" => TRUE,
  "grant_types" => ["authorization_code", "refresh_token"],
  "redirect" => "https://mandala-index-dev.internal.lib.virginia.edu/auth",
]);
$c->save();
echo "consumer created: " . $c->id() . "\n";'
```

Shape to match (captured from the live consumer before the reset): `client_id=solrproxy`,
`automatic_authorization=on`, `redirect` = the ALB `idx` CNAME's `/auth`, **not** the Drupal
host. `simple_oauth` hashes the secret on save, so verify by exercising the flow
(`scripts/verify-oauth2-userinfo.sh`), not by reading it back.

### 6. Migrations — **users first**

```bash
$D migrate:import --group=mandala_users        # ~1,542 users + authmap; minutes
sudo docker exec -d mandala-drupal-0 bash -c \
  'php -d memory_limit=1024M /opt/drupal/app/drupal/vendor/bin/drush.php \
   migrate:import --group=mandala_images > /tmp/import.log 2>&1'
```

**Order matters:** `d7_images_collection_memberships` needs the users to exist, and getting
this backwards is what left 211 memberships skipped in July.

- The **memory limit is not optional** — 128M has killed a long run twice — and it must
  target `drush.php`, not the `drush` wrapper (a shell script; `php -d` never reaches the
  PHP that matters).
- Run **detached**; long-held SSH connections to dev-0 are unreliable. Poll with fresh short
  connections, never a held pipe.
- **~15 h.** `d7_images_shanti_image` alone is ~9.3 h at ~200 rows/min.

### 7. Re-link the uid-600 test identity

Not a migration; it was made by hand for the dev IdP. The identity itself is in
`mandala-navina-docs` (private) — referred to publicly only as "uid 600".

```bash
$D php:eval '\Drupal::service("externalauth.externalauth")
  ->linkExistingAccount("<staff-identity>", "simplesamlphp_auth", \Drupal\user\Entity\User::load(<uid>));'
```

### 8. Validate

```bash
EXPECT_FILE=scripts/baselines/dev-0.txt ./scripts/migration-cycle.sh validate
```

`integrity:legacy_nid_dupes` **must now be 0.** If the duplicate survives a from-scratch
rebuild it is a **migration defect**, not an artefact of the resumed July run — and that is
the single most valuable thing this rebuild can tell us.

Then fill the remaining baseline keys — **reconciled against the D7 source**, not copied from
the result. See [canonical-d7-dev-source-dump.md](../deferred/canonical-d7-dev-source-dump.md).

### 9. kmassets

Nids change, so every existing `images-11-*` doc is orphaned in an index other people read.

```bash
$D kmassets:delete "uid:images-11-*"
$D kmassets:index-all shanti_image     # use the raised memory limit; ~5.8-7/sec
$D kmassets:audit --check-stale        # expect 0 missing / 0 stale / 0 orphaned
```

### 10. Smoke-test what this session built

```
/images/{slug}      public → 200,  private → 403,  bogus → 404
/collection/{slug}  public → 200,  private → 403
```
403-not-404 is the discriminator: it proves the alias resolves and then meets an *access*
decision, rather than failing at routing.

## Known risks

- **Nightly shutdown is suspended** (2026-08-26). If it is ever reinstated, a 16–18 h run
  will not fit the 17 h window, and a resume costs close to a full run because
  `prepareRow()` runs on every source row regardless of the migrate map.
- **Undocumented hand-configuration.** The pre-flight found none, but the rebuild is the real
  test. Anything that turns out to be missing afterwards should be **codified**, not
  hand-fixed again.
- `site:install --existing-config` fails if `config/sync` and the profile disagree. Step 4
  exists to catch that before 15 hours of migration are spent on it.
