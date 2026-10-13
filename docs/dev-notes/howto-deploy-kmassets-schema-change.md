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
  (a commit that writes something). Confirmed on staging and production
  2026-10-13.
- **A commit that changes nothing does not count.** A `deleteByQuery` that
  matches no document, plus commit, leaves the generation where it was. An
  add and delete of a throwaway document in one update request does move it
  (see Deploy, step 5).
- **Check that the index is receiving writes at all.** On 2026-10-13 the newest
  production `kmassets` document was dated 2025-08-11: the D7 to S3 listener is
  not running ([mandala_drupal_docker#26](https://github.com/shanti-uva/mandala_drupal_docker/issues/26)).
  So "wait for the next write" or "resave a node in D7" does not work there;
  trigger the write yourself.
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

Use `scripts/kmassets-baseline.py`. It records core status (counts, segments,
`startTime`, index version), replication state, the field's schema attributes,
a hash of the `uid,trid_i` list, and the hash of each canary query's response.
It is read-only.

```bash
C=http://mandala-solr-master-production-private.internal.lib.virginia.edu:8080/solr/kmassets
scripts/kmassets-baseline.py take prod-master-before $C > prod-master-before.json
# replica: curl runs on the SSH target, so the VPC-only host name resolves
scripts/kmassets-baseline.py take prod-replica-before \
  http://mandala-solr-replica-production.private.production:8080/solr/kmassets \
  "$(whoami)@mandala-drupal-0.internal.lib.virginia.edu" > prod-replica-before.json
# repeat as "...-after" once the replica has followed, then:
scripts/kmassets-baseline.py diff prod-master-before.json prod-master-after.json
```

Take two baselines a few minutes apart first, so you know whether the index
drifts on its own (production did not on 2026-10-13). The diff should show only
the expected differences listed under Verify. The canaries in the script are the
ones used for `trid_i`; replace them with queries for the field you are
changing.

The canary queries should stand for real traffic (take them from the replica's
request log): text lookups, grouped lookups, a page of results that includes
the field, and a facets/stats query. Use `sort=uid asc&fl=uid&rows=100` so the
result order is stable.

Production baseline taken 2026-10-13, for orientation only: 557,483 docs, 5,337 with `trid_i`, core `startTime`
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
   replication carry `conf/`. Add and delete a throwaway document in a single
   update request with a single commit (run it from any host that can reach the
   master; on production the legacy host `mandala-drupal-0` works):
   ```bash
   MASTER=http://mandala-solr-master-production-private.internal.lib.virginia.edu:8080
   P=zz-noop-probe-$(date -u +%Y%m%d%H%M%S)
   curl -s -m 60 -H 'Content-Type: application/json' \
     "$MASTER/solr/kmassets/update?commit=true&wt=json" \
     -d "{\"add\":{\"doc\":{\"uid\":\"$P\",\"id\":\"$P\",\"ids\":[\"$P\"]}},\"delete\":{\"id\":\"$P\"}}"
   ```
   `id`, `ids` and `uid` are required by the schema; `uid` is the unique key.
   Check `replication?command=details` on the master: the generation and index
   version must have moved, and `numFound` must be unchanged. The probe is never
   visible to searches. The replica followed in about 25 seconds on staging and
   15 seconds on production. This is a write to production Solr: announce it
   and get approval first.

   An alternative on staging is a one-node re-index from dev-0
   (`drush kmassets:index <nid>` for a D11 document, never a legacy one). It
   changes only `_version_`, `timestamp` and `solrdoc_ts_i` on that document,
   but needs the D11 writer host, which production does not have.
6. Verify the replica.

## Verify

Repeat the baseline and compare.

- **Must not change:** total and per-facet counts, `numDocs`, the hash of the
  `uid,trid_i` list, the canary hashes. Index size and segment count may drift
  slightly with normal merges.
- **May change:** index version, generation and replication timestamps (the
  probe), index size by a few bytes, core `startTime` (the reload), and `field(trid_i)` returning 0 for documents that have
  no docValues yet.
- **Config:** re-run the live-vs-repo diff of every `conf/` file on master and
  replica; the only difference from before is your change.
  `schema/fields/<field>` on both shows the new attribute.
- **Replication:** `replication?command=details` on the replica shows the same
  generation as the master, polling enabled, and `timesFailed` not growing.
- **Errors:** search the replica CloudWatch group for `ERROR`/`WARN` since the
  deploy time, and compare `QTime` for the canaries before and after. Expect
  background noise: on 2026-10-13 the replica logged two `solrconfig.xml`
  warnings at every reload (`Multiple defaults for : requestHandler`,
  `masterUrl must be specified without the /replication suffix`) and recurring
  `kmterms` errors unrelated to `kmassets`. Compare with the 14 days before.
  Logs Insights results can contain raw control characters; parse them with
  `json.loads(..., strict=False)`.

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
4. **Get the replica to follow:** one more probe write (Deploy, step 5). If
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
  matched the master's. A no-match `deleteByQuery` left the generation at
  329702; an add-and-delete probe moved it to 329703 and the replica followed
  in about 25 s.
- **2026-10-13, production:** deployed `trid_i` docValues. Master core reloaded
  (`startTime` moved), `docValues: true`. The replica followed after an
  add-and-delete probe from the legacy host (master generation 21229 to 21230,
  replica matched within about 15 s). Baseline before and after, master and
  replica: counts, the `uid,trid_i` hash (5,337 pairs) and 9 canary hashes
  unchanged; 62 replica `conf/` files identical to the master and the repo; no
  new `kmassets` errors. No rollback. Pre-change config commit: `18fd04d`.
