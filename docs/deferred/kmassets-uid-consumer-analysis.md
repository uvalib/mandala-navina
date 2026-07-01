# Audit all consumers that store or rely on D7 kmassets `uid` values

**Area:** solr / kmassets / migration / identity / clients
**Raised during:** Sprint 1 (1a.8 uid identity discussion, 2026-07-01)
**Jira:** (add when available)
**Priority:** High — must be completed before final cutover; determines redirect and
compatibility scope

## Why this matters

At final cutover the kmassets index is rebuilt from D11, replacing all D7-era
`uid = images-{d7-nid}` entries with `uid = images-{d11-nid}`. Any client that
has stored, cached, or hard-coded old uids will break silently — returning stale
data or empty results — unless identified and migrated ahead of time.

See [kmassets-uid-identity-across-migration](kmassets-uid-identity-across-migration.md)
for the identity strategy and cutover steps.

## Clients to audit

| Client | Where to look | Risk |
|---|---|---|
| **React KMaps app** (Than) | Asset link generation; saved/bookmarked asset URLs; any place a `uid` or nid is embedded in a route or state | High |
| **reindeer_x** | Does it reference kmassets docs by uid when writing shadow entries? Does it read back by uid? | High |
| **D7 Drupal content** | Body fields, asset-link fields, CKEditor embeds that reference `/node/{d7-nid}` or asset picker selections by nid | Medium |
| **Solr proxy** | Does it rewrite or cache uid-based queries? | Low (likely pass-through) |
| **External services** | emu.shanti.virginia.edu, fox.thdl.org, cicada.shanti.virginia.edu (confirmed in terraform ALB rules as direct Solr consumers) | Medium — unknown what they store |
| **API consumers** | Any service calling `api/json/{nid}` or `api/ajax/{nid}` (out of scope for MVP per sprint doc, but still stores nids) | Medium |
| **Saved search links / bookmarks** | User-facing URLs that encode asset ids | Low (can redirect) |

## What to look for in each client

- Stored `uid` strings (e.g. `images-1028396`)
- Stored raw nids used to construct a uid downstream
- Queries filtered by `uid:images-{nid}` or `id:{nid}`
- Hardcoded nid ranges or nid-based routing logic
- Any database tables or persistent storage that caches asset identity

## Output needed

For each client: (a) does it store old uids/nids, (b) what is the impact at
cutover, (c) what is the remediation (redirect, re-index notification, data
migration, or no action needed).

## Suggested approach

1. Start with reindeer_x and the React app — highest risk, Than and Andres are the
   right people to consult.
2. Grep the D7 codebase for `uid`, `images-`, and asset-link field definitions.
3. Reach out to the external Linode-hosted services (emu, fox, cicada) to understand
   their consumption pattern before cutover.

## Operational tracking via proxy logs

The proxy compatibility shim (see
[kmassets-uid-identity-across-migration](kmassets-uid-identity-across-migration.md))
logs every request that arrives with an old-format uid. Once the shim is live,
these logs become the authoritative view of which clients are still using the legacy
format — use them to drive the remaining consumer update work and to know when the
shim can be retired.
