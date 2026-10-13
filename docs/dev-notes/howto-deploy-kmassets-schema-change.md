# How-To: Deploy a kmassets Solr schema change and verify nothing broke

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-10-13

## Goal

Push a `kmassets` config change (first used for the explicit `trid_i` docValues
field, [#321](https://github.com/uvalib/mandala-navina/issues/321),
[solr-shanti-configsets PR #32](https://github.com/shanti-uva/solr-shanti-configsets/pull/32))
to staging or production, prove the live index is unaffected, and know how to
roll back.

The config source of truth is `shanti-uva/solr-shanti-configsets`
(`solr7.3.x/{staging,production}/kmassets/conf/`). The deploy itself runs from
the team's TeamCity job. Ask Dave only if that job does not work. The older
manual Ansible playbooks in `terraform-infrastructure`
(`mandala/solr/*/master/ansible/deploy_master_config.yml`) tar a directory over
the whole Solr home and do **not** reload cores.

## Facts that shape the procedure

- **Master first, replica second, and not on its own schedule.** The master
  core must reload before the new schema is live. The replica receives `conf/`
  files by replication (`confFiles` in `solrconfig.xml`), but only together with
  an index fetch. A config push does not change the master's index version, so
  the replica sits on the old schema until the master's index next changes
  (a commit that writes something). Confirmed on staging 2026-10-13.
- **The replica core reloads itself when the config arrives.** A bad schema can
  therefore take the replica down; verify on the master first.
- **`docValues` change on an existing field applies to new writes only.**
  Existing documents get no docValues until reindexed. Term and range queries
  and stored-field reads are unaffected. Sorting, faceting, grouping and
  function queries (`field(trid_i)`) on old documents see missing values.
- **Hosts** (read-only `admin/file` and `select` work without credentials):
  - master staging: `mandala-solr-master-staging-private.internal.lib.virginia.edu:8080`
  - master production: `mandala-solr-master-production-private.internal.lib.virginia.edu:8080`
  - replicas: `mandala-solr-replica-{staging,production}.private.{staging,production}:8080`
    resolve only inside the VPC. Reach them over SSH from `mandala-drupal-dev-1`
    (staging) or `mandala-drupal-0` (production); see
    [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md).
- **Production master CloudWatch group holds OS logs only.** Solr request logs
  are in `uva-mandala-solr-replica-0-production` (read with
  `aws-vault exec staging -- aws logs ...`; the staging profile reaches
  production).

## Before you deploy

1. **Check usage of what you are changing.** For `trid_i` the only legacy
   client use was a stored-value read in the React viewer
   (`mandala-om/.../legacy/audiovideo.js`), and a 30-day Logs Insights search of
   the production replica found six `trid_i` requests, all one-off audit
   queries. Search again for the field you are touching:
   ```bash
   G=uva-mandala-solr-replica-0-production; E=$(date +%s); B=$((E-30*86400))
   aws-vault exec staging -- aws logs start-query --log-group-name $G \
     --start-time $B --end-time $E \
     --query-string 'filter @message like /FIELD/ | stats count() as n'
   # then: aws logs get-query-results --query-id <id>
   ```
2. **Diff the live config against the repo.** Fetch every file from the master
   and replica with `admin/file` (list with `.../admin/file?wt=json`, one file:
   `?file=schema.xml&contentType=text/xml`) and compare to the repo's `master`
   branch. The only difference after your PR should be your change. Do not push
   on top of unexplained drift.
3. **Take the baseline** (next section) and save it somewhere you will find it.
4. **Pick a quiet time** for production. Core reload briefly warms a new searcher.

## Baseline (take before, repeat after, diff)

Set `C` to the core URL, e.g.
`C=http://mandala-solr-master-production-private.internal.lib.virginia.edu:8080/solr/kmassets`.
Run against the master directly, and against the replica over SSH.

```bash
# Index health and reload marker (startTime moves when the core reloads)
curl -s "${C%/kmassets}/admin/cores?action=STATUS&core=kmassets&wt=json" |
  python3 -I -c "import json,sys;s=json.load(sys.stdin)['status']['kmassets'];i=s['index'];print(s['startTime'],i['numDocs'],i['maxDoc'],i['deletedDocs'],i['version'],i['segmentCount'])"

# Totals and facets
curl -s -G "$C/select" --data-urlencode 'q=*:*' --data-urlencode rows=0 \
  --data-urlencode facet=true --data-urlencode facet.field=asset_type \
  --data-urlencode facet.field=service --data-urlencode wt=json

# The data itself: every uid with the field, in a fixed order, hashed
curl -s -G "$C/select" --data-urlencode 'q=trid_i:[* TO *]' \
  --data-urlencode 'fl=uid,trid_i' --data-urlencode 'sort=uid asc' \
  --data-urlencode rows=20000 --data-urlencode wt=csv > trid_baseline.csv
wc -l trid_baseline.csv; shasum -a 256 trid_baseline.csv
```

Add 5 to 10 **canary queries** that stand for real traffic (take them from the
replica's request log), each with `sort=uid asc&fl=uid&rows=100`, and save a
hash of each result.

Production baseline taken 2026-10-13, for orientation only (the numbers move
as D7 writes): 557,483 docs, 5,337 with `trid_i`, core `startTime`
2026-09-26T12:25:03Z, `numDocs` 557,483, `maxDoc` 809,293, `deletedDocs`
251,810, 19 segments, index size about 1.59 GB. Facets by `service`: images
111,499, sources 28,461, audio-video 11,757, texts 5,440, visuals 960,
mandala 67.

## Deploy

1. Merge the config PR in `solr-shanti-configsets`.
2. Run the TeamCity deploy for the target environment.
3. **Confirm the master core reloaded.** `startTime` in the core status
   should have moved. If not, reload it (`admin/cores?action=RELOAD&core=kmassets`;
   harmless but production, so announce it).
4. **Verify the master** (section below) before touching the replica.
5. **Get the replica to follow.** Cause one index change on the master and let
   replication carry `conf/`. For `kmassets` use a one-node re-index from the
   Drupal host that owns the documents:
   ```bash
   ssh <you>@mandala-drupal-dev-0.internal.lib.virginia.edu \
     "sudo docker exec mandala-drupal-0 sh -c 'cd /opt/drupal/app/drupal && vendor/bin/drush kmassets:index <nid>'"
   ```
   Pick a **D11 document** (`uid` like `audio-video-11-<nid>`), never a legacy
   document, so legacy stored values stay untouched. On staging this took about
   30 seconds from the index write to the replica showing the new schema. The
   re-index changes only `_version_`, `timestamp` and `solrdoc_ts_i` on that
   document.
6. Verify the replica.

## Verify

Repeat the baseline and compare.

- **Must not change:** total and per-facet counts, `numDocs`, the hash of the
  `uid,trid_i` list, the canary hashes. Index size and segment count may drift
  slightly with normal merges.
- **May change:** `_version_`, `timestamp`, index version and generation (the
  one-node re-index), and `field(trid_i)` returning 0 for documents that have
  no docValues yet.
- **Config:** re-run the live-vs-repo diff of every `conf/` file on master and
  replica; the only difference from before is your change.
  `schema/fields/<field>` on both shows the new attribute.
- **Replication:** `replication?command=details` on the replica shows the same
  generation as the master, polling enabled, and `timesFailed` not growing.
- **Errors:** search the replica CloudWatch group for `ERROR`/`WARN` since the
  deploy time, and compare `QTime` for the canaries before and after.

## Rollback

Existing documents are not rewritten by this kind of change, so rollback is a
config revert, not a data repair.

1. **Stop condition (decide before deploying):** roll back if any count or hash
   differs, if the replica's error rate rises above baseline, or if a core
   fails to load.
2. **Revert the config.** In `solr-shanti-configsets`:
   `git revert -m 1 <merge sha>` (for #32 that is `88da645`), merge it, or
   redeploy the commit before the merge (`18fd04d`). Git is the restore point.
3. **Run the same TeamCity job** against the same environment and reload the
   master core.
4. **Get the replica to follow:** one more one-node re-index (step 5 above). If
   the replica core is down or will not accept the config, deploy to the
   replica directly instead of waiting.
5. **Verify with the baseline.**

Documents written while the new field definition was live keep their
docValues. Dropping docValues from the schema afterwards is expected to be safe
(Solr ignores them); this has not been exercised here.

Optional: an EBS snapshot of the master data volume just before the deploy
would allow a data restore. It should be unnecessary for a schema-only change
and is an AWS action for Yuji or Dave to approve.

## Record of use

- **2026-10-13, staging:** deployed `trid_i` docValues. Master showed
  `docValues: true` immediately. The replica did not until a one-node re-index
  of `audio-video-11-111341` moved the master from generation 329701 to 329702,
  after which the replica matched in about 30 s. All 62 replica `conf/` files
  matched the master's.
- **Production:** not yet deployed. Live production master and replica `conf/`
  were identical to the repo before the change (checked 2026-10-13).
