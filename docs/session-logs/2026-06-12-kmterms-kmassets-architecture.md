# Session Log: kmterms/kmassets Architecture Investigation and reindeer_x Transfer

**Date:** 2026-06-12  
**Participants:** Yuji Shinozaki, Claude Sonnet 4.6  
**Outcome:** Full kmassets/kmterms architecture documented; race condition identified; reindeer_x transferred to `uvalib/mandala-reindeer_x`; ADRs 006 and 007 filed; Spike 8 scoped

**Outputs:**
- [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) — kmterms-in-kmassets shadow pattern
- [ADR 007](../adr/007-reindeer-x-independent-service.md) — reindeer_x as independent service
- [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md) — D11 sync architecture gaps
- [docs/deferred/solr-pipeline-cost-discussion.md](../deferred/solr-pipeline-cost-discussion.md) — Dave Goldstein conversation needed
- [docs/spikes/spike-08-reindeer-x-consolidation.md](../spikes/spike-08-reindeer-x-consolidation.md) — Spike 8 scoped
- [github.com/uvalib/mandala-reindeer_x](https://github.com/uvalib/mandala-reindeer_x) — new canonical repo

---

*This is the conversation transcript. Tool calls and raw code output are omitted; only the text exchanges are recorded.*

---

## User

I want to explore a concept which is not clearly delineated in the notes. In the old D7 mandala there were two distinct concepts in two distinct Solr indexes: kmterms and kmassets. kmterms embodies the KMaps ontology and kmassets are descriptions of assets. One design decision was that there were kmassets entries which mirror kmterms. So there should be one kmasset per kmterm and the kmassets were kept in sync with the kmterms by an external process (which was a node.js process on the mandala servers). I first want to make sure that this is well-documented. Could you scan the former repos to see if you can formulate an understanding about this mechanism?

---

## Claude

Searched the legacy repos (`mandala-drupal`, `mandala_drupal_docker`, `mandala-scripts`). Initial finding was incomplete — the search concluded there was no 1:1 sync between kmterms and kmassets, characterizing kmassets as purely Drupal-node-derived content. This was wrong.

---

## User

So okay. Listen carefully. There are kmassets of type subjects, places and terms. They are generated from kmterms. There should be a one-to-one correspondence between kmterms and kmassets of type subjects, places and terms.

---

## Claude

Acknowledged the correction and re-ran the search with the specific framing: three asset types within kmassets (`subjects`, `places`, `terms`) are generated 1:1 from kmterms. Found:

- The Solr proxy code explicitly filters these three as a distinct population: `(asset_type:(places subjects terms))` — confirmed the concept.
- The ECS ingest pipeline in Terraform infrastructure uses S3 buckets (`kmterms-inbound/`, `kmassets-inbound/`) with SQS notifications and parallel ECS tasks for transform and Solr update.
- The actual Node.js sync code was not found in the legacy repos — it lives elsewhere.

---

## User

I think the code lives outside, and that needs to be remedied also immediately. Right now it looks like that code resides inside the container (it could be in a volume). How can I get you to see it?

---

## Claude

Offered three options for accessing code in a remote container: copy files out via `docker cp`, run commands in-session using the `!` prefix with SSH, or pull the Docker image locally.

---

## User (via `!` commands)

SSHed to `mandala-drupal-dev-0.internal.lib.virginia.edu`. Hit Docker permission error — not in the docker group. Used `sudo docker ps` successfully.

Running containers found:
- `mandala-solr-proxy-php-proxy` — Solr proxy
- `dockerfiles-hostmaster-1` — Aegir/Drupal D7 (image: `mandala/mandala_drupal_docker`)
- `dockerfiles-database-1` — MariaDB
- `reindeer_x` — the kmaps-solr-sync service (image: `reindeer_x`, running 2 years)
- `workqueue` — Redis

The sync scripts are in the `dockerfiles-hostmaster-1` container at `/usr/local/bin/synch` and `/usr/local/bin/synchandler`.

---

## Claude

Extracted both files via `docker cp`. Contents:

**`synch`** (shell): Uses `clsync` to watch six Drupal site `private/files/solrdocs/` directories. On change, calls `synchandler`.

**`synchandler`** (Perl): Called by `clsync` with a list of changed files. Uploads `.json` files to `s3://mandala-ingest-{env}-inbound/kmassets-inbound/` and `.ids` files to `kmassets-delete/` via `rclone`. From there the ECS pipeline handles Solr updates.

This confirmed the full content-asset sync pipeline. But noted: this is only for Drupal content assets. The `subjects`/`places`/`terms` kmassets sync (kmterms → kmassets) must live elsewhere.

---

## User

I need to find the other code.

---

## Claude

Checked local directories for KMaps-related repos. Found `kmaps-solr-sync/` at `/Users/ys2n/Code/kmaps-solr-sync/` — a Node.js project with `Dockerfile.reindeer_x`. Matched to the `reindeer_x` container seen on the server.

---

## User

Ah. There is one repo I have neglected to add: `git@github.com:shanti-uva/kmaps-solr-sync`

---

## Claude

The repo was already cloned locally. It is the kmterms→kmassets sync service:
- **Description:** "Utility to generate and synchronize kmaps-in-kmassets entries"
- Node.js, uses Redis queue (`bee-queue`), Express web UI for queue state
- Triggered by UDP packet on port 9001
- Reads changed kmterms from Solr, generates corresponding kmassets entries
- Author: Yuji Shinozaki

Proposed bringing it into the monorepo as `kmaps-solr-sync/` alongside `solr-proxy/` and `s3-sync/`.

---

## User

Yes, it will need to be invited to the party.

---

## Claude

Copied the repo into `mandala-navina/kmaps-solr-sync/` (excluding `node_modules`). During copy, found real AWS credentials in three files:
- `docker/reindeer_x/files/root/.aws/credentials`
- `docker/reindeer_x/files/root/.config/rclone/rclone.conf`
- `docker/reindeer_x/files/etc/passwd-s3fs`

Also noted: `synch` and `synchandler` are already committed in the repo at `docker/reindeer_x/files/usr/local/bin/` — baked into the Docker image build.

Sanitized all three credential files (replaced with `REPLACE_WITH_...` placeholders), added them to `.gitignore`. Updated `CLAUDE.md` to document `kmaps-solr-sync/` in the repo structure.

---

## User

Yes those old keys were flagged and removed a while ago.

---

## Claude

Committed. GitHub push protection blocked the push — caught AWS keys in `passwd-s3fs` which had not been caught by the initial audit. Sanitized that file too, amended the commit, pushed successfully.

---

## Findings summary

### Architecture confirmed

Two distinct populations coexist in the `kmassets` Solr index:

| Population | Asset types | Source | Sync mechanism |
|---|---|---|---|
| KMaps-derived | `subjects`, `places`, `terms` | kmterms Solr index | `kmaps-solr-sync` Node.js service (reindeer_x) |
| Drupal content | `audio-video`, `images`, `texts`, `visuals`, `sources`, `collections` | Drupal nodes | `shanti_kmaps_fields` → clsync → synchandler → S3 → ECS |

### Code now in monorepo (later reversed — see Part 2)

- `kmaps-solr-sync/` — the Node.js service that syncs kmterms→kmassets for subjects/places/terms
- `kmaps-solr-sync/docker/reindeer_x/files/usr/local/bin/synch` — clsync launcher
- `kmaps-solr-sync/docker/reindeer_x/files/usr/local/bin/synchandler` — Perl S3 uploader

### D11 implications

See [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md) for full detail. Key open questions:
1. What replaces the `clsync`/`synchandler` pipeline for content assets in D11 (no Aegir)?
2. How does `kmaps-solr-sync` get modernized and integrated into ECS?
3. Does Drupal post directly to S3/ECS, or via a sidecar service?

---

## Part 2: reindeer_x architectural discussion

### User

I want to discuss the re-engineering. I am the lead architect and this is all my original code.

---

### Claude

Read `server/index.js`, `sync/kmassetSync.js`, `queue/jobCreationQueue.js`, and the transform modules in full. Understood the complete pipeline.

**The current D7 content asset pipeline** (Drupal → kmassets):
Node save → `shanti_kmaps_fields` writes JSON to `private://solrdocs/` → `clsync` detects file → `synchandler` (Perl) uploads to S3 via `rclone` → SQS → ECS → Solr.

**The current kmterms→kmassets pipeline**:
UDP ping → reindeer_x queries kmterms Solr → transforms entries → writes to kmassets Solr directly.

**Race condition identified:** KMaps Rails fires the UDP ping simultaneously with posting to S3. `reindeer_x` races ahead of the ECS pipeline — reads stale kmterms, writes stale kmassets silently. Confirmed this has caused "update didn't take" behavior in production.

---

### User

There are two ways I think about this: 1. we don't want to over-specialize the Drupal code to handle this particular implementation detail. 2. This implementation has its own headaches that probably should be handled inside Drupal for cleaner management purposes. Those two inclinations are in conflict.

---

### Claude

Named the tension precisely: **separation of concerns** (Drupal is a content store, not a pipeline operator) vs. **operational visibility** (when something goes wrong, debug it in one place). 

Proposed the middle position: Drupal's abstraction boundary is the queue, not the filesystem. On node save, Drupal pushes to Drupal's Queue API. A queue worker — separate concern, same codebase — handles S3 delivery. The ECS pipeline downstream unchanged. This gives visibility into what's queued/failed within Drupal without coupling node save to AWS.

---

### User

The visibility one is apt: "Someone makes an update and it's not reflected anywhere, what happened?"

---

### Claude

Identified the root cause: the pipeline is fire-and-forget from Drupal's perspective across 4+ async hops, none of which report back. Proposed splitting along the natural seam: Drupal-originated content owns its own Solr write (via queue worker → Solr direct); the ECS pipeline becomes exclusively the home of kmterms-derived assets. Drupal then knows whether its content landed in Solr.

---

### User

We will need to work with our Cloud architect (Dave Goldstein) to see what the issues are. Those pipelines are expensive so we need to talk to them about lowering the costs while still maintaining timely updates.

---

### Claude

Identified cost levers: the ECS pipeline may be sized for high-throughput indexes; Drupal content saves are low-frequency. A direct Drupal→Solr write path would eliminate the ECS pipeline for Drupal content entirely. For kmterms, `reindeer_x` is already cost-efficient (single container, on-demand trigger). Filed `docs/deferred/solr-pipeline-cost-discussion.md` for the Dave conversation.

---

### User

In the meantime we could make the reindexer and the synch/synchandler a fully managed subsystem. Perhaps folding the synchandler into reindeer_x.

---

### Claude

Affirmed: consolidate `synch` + `synchandler` (shell+Perl+rclone) into `reindeer_x` using `chokidar` + `@aws-sdk/client-s3`. One container, one codebase, one log stream. The HTTP `/post` endpoint already in Express means the D11 path (Drupal POSTs to `reindeer_x` directly instead of dropping files) is already partially built.

Proposed full event-driven architecture:
- **Inbound:** SQS subscription (kmterms completion event) + HTTP POST (Drupal doc push) + UDP (manual/legacy)
- **Processing:** existing transform logic unchanged
- **Outbound:** SNS completion/failure events — visible to Drupal, alerting, monitoring

Race condition eliminated by design: SQS trigger fires after ECS kmterms Solr update confirms completion.

---

### User

Do you get how reindeer_x is named?

---

### Claude

`re-index-er` → `reindeer` with the `x` displaced from `index`.

---

### User

The original purpose of reindeer_x was to maintain the kmterms-in-kmassets shadow entries to make the Drupal paths for subjects/places/terms easier to manage (they are managed conceptually like assets). Do you understand how that works? Do you think that this is a sustainable architecture?

---

### Claude

Confirmed understanding: shadow entries unify search and routing — subjects/places/terms get kmasset documents so the React front-end and Drupal can treat them identically to content assets without a separate index or document schema.

Assessment: **sustainable as an architecture, not sustainable as currently operated.** The shadow pattern is correct and worth keeping for D11. What needs to change: reliable automatic triggering (not UDP), per-document sync capability, and sync status visible somewhere. The pattern itself is sound; the trigger mechanism is the problem.

---

### User

Yes, at present it sends a UDP ping to inform us that changes have been posted to an S3 bucket (if I remember correctly).

---

### Claude

Read `server/index.js` carefully. The code shows: UDP ping arrives → reindeer_x immediately queries **kmterms Solr** (not S3). S3 is the kmterms update path (KMaps Rails → S3 → ECS → Solr); the UDP is a separate notification. The ping fires at the start of the KMaps Rails upload, before the ECS pipeline has finished updating Solr. reindeer_x wins the race, reads stale data, writes stale kmassets.

---

### User

Oh yes, I was misremembering... it's been a while. And yes, I think that race condition has been a problem.

---

### Claude

Confirmed: fix is to trigger reindeer_x from the **end** of the ECS kmterms Solr update task, not the beginning of the KMaps Rails upload. The HTTP `/post` endpoint is already built and ready — just needs something reliable to call it at the right moment (SNS/SQS from ECS task completion).

---

### User

If done properly, it can be more than a spike: it could be a deployable hotfix, as reindeer_x is living code. Now that is a case for keeping it as an independent codebase.

---

### Claude

Agreed. `reindeer_x` has a different character from the monorepo components: it's living production code with its own release cycle. Coupling it to mandala-navina would force sync fixes to wait on Drupal releases. Proposed reverting the monorepo addition and creating `uvalib/mandala-reindeer_x` as its proper home.

---

### User

Yes, do that. And rename it mandala-reindeer_x in the uvalib repo. Document the transfer and its kmaps-solr-sync heritage.

---

### Claude

Created `github.com/uvalib/mandala-reindeer_x`. Transferred code as clean orphan commit (git history had rotated-but-present credentials; orphan start avoided pushing them to a new org). README documents heritage, architecture, name etymology, and links to mandala-navina spike/deferred docs. Removed `kmaps-solr-sync/` from monorepo. CLAUDE.md updated with Related Services section.

---

### Decisions made this session

| Decision | ADR |
|---|---|
| kmterms-in-kmassets shadow pattern retained for D11 | [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) |
| reindeer_x as independent deployable service | [ADR 007](../adr/007-reindeer-x-independent-service.md) |
| Spike 8 scoped: fold synchandler in, add SQS trigger | [Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) |
| Dave Goldstein conversation needed on pipeline costs | [Deferred](../deferred/solr-pipeline-cost-discussion.md) |
