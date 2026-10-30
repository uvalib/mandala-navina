# Spike 5 entity-model proof of concept (throwaway)

Local DDEV only. Findings: [Spike 5, finding 9](../../../spike-05-bibcite-sources.md). Not for merge.
`MODE=...` must be passed through the container: `ddev exec "MODE=teardown drush php:script <file>"`
(a host-side `MODE=x ddev drush ...` is silently ignored).

Files: `access.php` (relation plugin, scratch collections, users, access matrix), `solr.php`
(builds a kmassets doc for a reference; never posts), `display.php` (KMaps + Zotero Collections
fields, real D7 values on reference 46), `spike5_bibcite_poc.module.txt` (copy of the throwaway
module's hooks; the module itself lives untracked in `drupal/web/modules/custom/spike5_bibcite_poc/`).

## Cleanup (in this order)

1. `ddev exec "MODE=teardown drush php:script docs/spikes/assets/spike-05-bibcite/entity-model-poc/access.php"` (scratch groups, users)
2. `ddev drush role:perm:remove anonymous 'view bibcite_reference'` and the same for `authenticated`
3. Remove the group role permission `view group_bibcite_reference entity` from `collection-anonymous`, `collection-outsider`, `collection-member` (Group admin UI, or `GroupRole::revokePermission`)
4. Delete field storages `field_zotero_collections`, `field_kmaps_subjects` (and `field_legacy_site`, `field_legacy_nid` from the legacy-redirect prototype) on `bibcite_reference`, the `zotero_collections` vocabulary, and the `group_bibcite_reference` relationship type
5. `ddev drush pm:uninstall spike5_bibcite_poc`, then delete `drupal/web/modules/custom/spike5_bibcite_poc/`
6. `git checkout drupal/web/modules/custom/mandala_legacy_redirect`
7. Remove the 40 test references (`write.php` with `MODE=clean`), drop the `spike5_d7` database, and do not run `drush config:export`
