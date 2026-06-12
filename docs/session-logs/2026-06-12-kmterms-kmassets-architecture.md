# Session Log: kmterms/kmassets Architecture Investigation

**Date:** 2026-06-12  
**Participants:** Yuji Shinozaki, Claude Sonnet 4.6  
**Outcome:** Architecture documented; `kmaps-solr-sync` consolidated into monorepo; deferred note filed at [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md)

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

### Code now in monorepo

- `kmaps-solr-sync/` — the Node.js service that syncs kmterms→kmassets for subjects/places/terms
- `kmaps-solr-sync/docker/reindeer_x/files/usr/local/bin/synch` — clsync launcher
- `kmaps-solr-sync/docker/reindeer_x/files/usr/local/bin/synchandler` — Perl S3 uploader

### D11 implications

See [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md) for full detail. Key open questions:
1. What replaces the `clsync`/`synchandler` pipeline for content assets in D11 (no Aegir)?
2. How does `kmaps-solr-sync` get modernized and integrated into ECS?
3. Does Drupal post directly to S3/ECS, or via a sidecar service?
