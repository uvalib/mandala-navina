# Session Log: shanti_sarvaka theme installed on dev-0

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** PR #169 (Sprint 2 Workstream A base theme) confirmed merged; `shanti_sarvaka` is now the live default theme on dev-0, verified against the running site. Local docs check-off pushed to `main`.

---

## 1. Picking up from 2026-08-31

PR #169 had merged since the previous session (commit `e8d5e8f`, "now merged via PR #169"). The
sprint doc (`docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md`) had already been
updated locally to check off A1–A6, but that commit was sitting unpushed, 1 commit ahead of
`origin/main`.

## 2. Dev-0 install

The CodePipeline webhook on `main` (touches `drupal/**`) had already auto-deployed by the time
this session picked up — `mandala-drupal-0` on dev-0 was running a container built minutes
earlier (`build-20260901140732`), with core 11.4.5 and the `bootstrap5`/`shanti_sarvaka`/`facets`
code already in the image. But the **active config was still stale**: `system.theme.default` was
still `olivero`, and `drush config:status` showed `core.extension`, `system.theme`, and all 16
`shanti_sarvaka_*` blocks pending import. (Two other diffs, `search_api.server.kmassets` and
`simplesamlphp_auth.settings`, were checked and confirmed benign/expected — not drift.)

Confirmed no migration/reindex/index-all jobs were running on dev-0 before touching anything
(per [[feedback-avoid-deploys-during-long-running-jobs]]).

Ran the standard activation sequence on `mandala-drupal-0`:
1. `drush updatedb -y` — 3 pending core update hooks (externalauth, help, search), clean.
2. `drush config:import -y` — hit the **known 128MB CLI memory landmine** (same one documented
   in the 2026-08-27/08-28 kmassets-audit sessions) partway through a trailing step, *after* all
   listed config changes (installing `bootstrap5`/`shanti_sarvaka`, creating all 16 blocks,
   flipping `system.theme`) had already synchronized successfully. Re-ran with
   `--php-options="-d memory_limit=1024M"`; confirmed "no changes to import" — the first run had
   in fact fully completed.
3. `drush cache:rebuild` (also with the raised memory limit).

**One new wrinkle not seen in prior deploys:** the site came back in maintenance mode
(`system.maintenance_mode` state flag = `1`) after the pipeline's deploy, serving 503s with a
"Site under maintenance | Drush Site-Install" page. Cleared via `drush state:set
system.maintenance_mode 0`; site returned 200 immediately after.

## 3. Verification

- `system.theme:default` = `shanti_sarvaka`.
- `bootstrap5`, `shanti_sarvaka`, `facets` all confirmed Enabled via `drush pml`.
- Homepage HTML confirmed loading theme-specific CSS/JS bundles (`theme=shanti_sarvaka` in the
  aggregated asset URLs) and the `shanti_sarvaka` favicon.
- `drush watchdog:show --severity=Error` showed no new errors from the install — only
  pre-existing/known ones (kmassets Solr reader gap from 2026-08-28, dev environment mail-sender
  errors, stray debug scripts from 2026-08-28).

## 4. Docs housekeeping

Pushed the already-committed sprint-doc check-off (`e8d5e8f`) to `origin/main` — `main` and
`origin/main` now match.

## Next-session starting point

Group visual review of `shanti_sarvaka` on dev-0 against real migrated content (the actual
purpose of this install — DDEV only has local data). The open items noted in the theme README
(per-asset-type accent color/banner icon, faceted search UI wiring, multi-level menu
verification) remain unstarted and are natural next tickets once sign-off happens, per the
2026-08-31 log.
