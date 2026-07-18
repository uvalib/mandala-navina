# Dev migration is markedly slower than DDEV — cross-AZ latency, not CPU; two "fixes" investigated and ruled out

**Area:** infrastructure / migration / performance
**Raised during:** Session 2026-07-17/18 (dev-0's first live `migrate:import`)
**Jira:** (add when available)
**Priority:** Low — a known, understood limitation the team is consciously living with, not a blocker; revisit only if migration time becomes a recurring real problem

**Decision (2026-07-18, Yuji): live with it for the current Images migration.**
Not worth acting on before vacation, and the two candidate fixes investigated
both turned out to be non-starters (see below). Documented so the next person
who notices "why is this so slow" doesn't have to re-derive it.

**Open discussion topic for when Yuji is back:** a disciplined one-time
"landmark migration" variant of the laptop idea — see below — may be worth
using for the **next** large content migration (Texts, Sources, AV, Home).
Explicitly **not** recommended for the upcoming user migration (PR #45),
which Than will likely tackle next — see the earmarked section below for why.

## Observation

Running the `mandala_images` migration on dev-0 was noticeably slower than
prior DDEV rehearsals of the same migration, and **not uniformly** — the
slowdown was much worse for some migrations than others:

| Migration | Entity type | Rows | Dev-0 pace | DDEV baseline pace | Slowdown |
|---|---|---|---|---|---|
| `d7_images_image_agent` | paragraph | 111,194 | ~1,120/min | ~1,850/min | ~1.6x |
| `d7_images_image_descriptions` | paragraph | 55,041 | ~1,250/min | ~1,850/min | ~1.5x |
| `d7_images_shanti_image` | node (heavily fielded) | 111,340 | ~200/min | ~1,850/min | **~9x** |

(DDEV baseline: historical "~an hour" for a full ~111k-row run, per prior
session logs — i.e. ~1,850 rows/min.)

The gradient — paragraphs only mildly slower, the heavily-fielded node
migration dramatically slower — is the key clue: it points at something that
compounds with the *number of SQL statements per entity save* (more fields =
more field-table writes = more of whatever the per-statement penalty is),
not a flat CPU-speed difference (which would slow every entity type roughly
equally).

## Ruled out: instance CPU / burstable-credit throttling

dev-0 (`uva-mandala-drupal-staging-0`) is a `t3a.medium` — 2 vCPUs, 4GB RAM,
a burstable/credit-based instance type that *can* throttle hard once CPU
credits run out. Checked directly via CloudWatch during the run:

- **CPU credit balance: ~576, essentially maxed out** (not credit-starved)
- **CPU utilization: ~15–24% average**, occasional spikes to 60–80%, never
  sustained near 100%

Plenty of idle CPU headroom the whole time — this rules out "the box is too
small/throttled" as the explanation.

## Most likely actual cause: cross-AZ latency, and it's account-wide

Checked AZ placement for every Mandala app instance and its paired RDS:

| Environment | App instance AZ | RDS AZ | Same AZ? |
|---|---|---|---|
| dev (`staging-0`) | `us-east-1a` | `us-east-1c` (`rds-mysql8-staging`) | ❌ |
| staging (`staging-1`) | `us-east-1b` | `us-east-1c` (`rds-mysql8-staging`) | ❌ |
| production | `us-east-1a` | `us-east-1d` (`rds-mysql8-production`) | ❌ |

**Every environment is cross-AZ between its app instance and its database.**
This is not a dev-specific misconfiguration — it's the account's standing
topology. Two implications:

1. dev-0's slowness is a realistic preview of what a live migration against
   production would also experience, not an artificially pessimistic
   dev-only case.
2. A Drupal content-entity save issues many sequential SQL statements (one
   per field table, plus revisions) — even small per-round-trip latency
   compounds heavily across the tens/hundreds of statements a heavily-fielded
   node like `shanti_image` requires, which matches the observed gradient
   above far better than a CPU-speed explanation would.

DDEV's database is local (same machine, effectively zero-latency socket),
so it never pays this cost at all regardless of DDEV's host machine's raw
CPU power.

## Considered and rejected: migrate on laptops, upload the DB to dev/staging/prod

Would recover the DDEV speed for the compute itself, but:

- **Reintroduces exactly the "laptop drift" problem** `d11-dev-database-
  bootstrap-and-migration-source.md`'s own "principle to argue about first"
  section was written to head off: *"A dump of anyone's laptop is neither
  [reproducible]... it promotes one of them, produces a third state nobody
  can reproduce."* Whoever's laptop produced the dump becomes the de facto
  source of truth, undermining the whole config-is-code / migration-from-
  source design.
- **Risks clobbering target-specific state** — the target DB also holds
  config UUIDs, kmassets sync state, SAML/OAuth setup, and any real
  target-only users; a raw dump/restore doesn't cleanly separate "migrated
  content" from "everything else in the database."
- **Loses migrate_map resumability tied to the live target** — the per-row
  tracking that made this session's crash-and-resume painless lives in the
  target's own database. Migrate on a laptop and copy the result over, and
  that tracking no longer reflects the actual target's real history.
- **Doesn't actually remove the operational complexity** — still requires
  transferring a large dump and solving the same secure-RDS-access problem
  this session already solved the hard way (see `scripts/
  refresh-d7-staging-source.sh` and `docs/dev-notes/howto-access-mandala-
  nodes.md`), just relocated rather than eliminated.

## Reconsidered 2026-07-18: a disciplined "landmark migration" variant might work

The laptop idea above was rejected in the form of *ongoing ad hoc development
against laptop dumps* — that's a real problem (drift, unreproducible state).
But a narrower, one-time variant is worth keeping on the table for the
**next** large migration: run the big, mostly-static historical bulk import
once on fast local hardware (DDEV), validate it against the same row-count
baseline discipline `migration-cycle.sh` already uses, then push that
validated result to dev as a single "landmark" checkpoint — rather than
re-deriving it in place over many slow hours.

This is meaningfully different from the rejected version because the
*process* stays disciplined even though the *hardware* changes:

- Same committed migration code, same source dump — not divergent/ad hoc.
  Validated against baseline counts, so "ran on a laptop" is provably
  identical to "ran on dev," not just assumed to be.
- Transfer must move the migrated **content tables together with their
  `migrate_map_*` tables** as one unit, not a whole-database dump — dev also
  holds config, UUIDs, kmassets sync state, and (eventually) real users that
  a blind restore would clobber. Keeping map and content bundled preserves
  the internal consistency later migrations rely on (e.g. anything doing a
  `migration_lookup` against the landmark migration's map).
- Only works cleanly while the target is **still clean of that content
  type** — straightforward for a dev-0 that's freshly bootstrapped now, but
  would need dev clear of that content again before the *next* landmark
  restore if people have been creating test content in the meantime.
- **Dev/lower-environment pattern only** — not a substitute for a real
  production-migration decision (see `production-migration-planning.md`).

### Earmarked discussion topic: is the user migration a fit? (probably not, and for a different reason than performance)

Than will likely pick up the user migration (PR #45) next while Yuji is out.
It's tempting to slot it into this pattern, but two things cut against it —
one of which is a much harder line than the performance question that
motivated this whole idea:

1. **It's real PII, and there's an existing policy specifically against
   putting it on a laptop.** Decision C(b) (`d7-shared-user-database.md`)
   mandated that the shared user DB — real names, emails, password hashes —
   is deliberately **never replicated to a laptop**; user-migration
   development was designed to happen on dev *specifically* to keep that
   data off laptops. Running the migration *on* a laptop means the raw D7
   source data has to be present there, at least transiently — in direct
   tension with that policy, not just a performance tradeoff to weigh.
2. **The performance case is weak anyway.** The shared user DB is ~1,543
   rows — roughly 70x smaller than `image_agent`. Even at the worst observed
   ratio from this session (~9x slower on dev than DDEV), that's a
   difference of minutes, not hours. The problem this pattern exists to
   solve (multi-hour dev-0 runs) barely applies here.

So: worth discussing explicitly when Yuji is back, but the honest framing
going in is that the user migration is a **weak candidate** for this
pattern — save it for the next genuinely large one (Texts, Sources, AV,
Home), where both the performance case is real and there's no PII-on-laptop
conflict.

## Considered and rejected: relax RDS commit durability for the bulk-import window

The classic MySQL bulk-load trick — temporarily lowering
`innodb_flush_log_at_trx_commit` from its default (`1`, fully durable, fsync
every commit) to `2` (write-behind, fsync ~1x/sec) or `0` (even less
frequent) — was considered and investigated, then rejected on two grounds:

1. **The parameter group is instance-wide, and `rds-mysql8-staging` is a
   shared instance across multiple unrelated projects, not just Mandala's
   own dev/staging databases.** Relaxing durability "for the migration"
   would silently reduce the crash-durability guarantee for every other
   tenant's workload on that instance too. This is not Mandala's call to
   make unilaterally — full stop, not something to revisit without going
   through whoever owns that shared estate.
2. **Even setting the sharing problem aside, it likely wouldn't help much.**
   `rds-mysql8-staging` is `MultiAZ: true` — on RDS (non-Aurora) Multi-AZ, a
   commit isn't acknowledged until the write is synchronously replicated to
   the standby in another AZ, a platform-level guarantee layered *on top of*
   whatever the InnoDB flush setting says. That synchronous cross-AZ
   replication step, not the InnoDB log-flush behavior, may well be the
   dominant source of commit latency — meaning the expected speedup was
   uncertain even before the sharing issue closed it out entirely.

## What's actually left, if this becomes worth revisiting

The one legitimate, non-invasive lever identified: **ask Dave whether the
app-instance/RDS AZ misalignment (uniform across dev, staging, and
production) was ever a deliberate choice.** This is a broader infrastructure
question — realigning it would speed up all live traffic, not just
migrations — and a decision for whoever owns that placement, not something
to change unilaterally. Not being pursued right now.

## Practical implication for planning future migrations

Budget migration time against the **observed dev-0 pace**, not the DDEV
baseline, for any future site migration (Texts, Sources, AV, Home) run
outside DDEV:
- Simple/few-field entities (paragraphs): expect ~1.5–2x the DDEV baseline
  time.
- Heavily-fielded node entities: expect as much as **~9x** the DDEV baseline
  time. A "~1 hour in DDEV" migration can mean most of a day on dev-0.

## Aside: none of the remaining sites are close to Images' scale

While investigating whether AV (raised as "the next biggie") might be a
disproportionately slow future migration, checked actual D7 source sizes for
every remaining site (read-only, `rds-mysql8-production`):

| Site | Nodes | Files | File volume |
|---|---|---|---|
| Images (done) | 287,939 | 55,117 | 1,471.7 GB |
| Sources | 28,599 | 1,541 | 2.20 GB |
| AV | 11,894 | 8,357 | 2.73 GB |
| Texts | 7,763 | 537 | 0.34 GB |
| Home | 1,971 | 21 | 0.004 GB |

AV isn't the biggest by node count (Sources is) or file volume (roughly tied
with Sources) or per-entity field complexity (`video` has 30 fields attached
vs. `shanti_image`'s 50). "Transcripts" turned out to be a file-reference
field, not inline text — the actual files behind it are mostly small WebVTT
caption files (39 MB total across 3,264 files). Notably, **no actual
video/audio media exists in Drupal's `file_managed` table at all** — it must
be hosted externally, outside the scope of a database-level content
migration.

**Conclusion: Images was the outlier.** All four remaining sites combined
(~50,227 nodes, ~5.3 GB of files) are a rounding error next to what Images
alone required. The slow, multi-hour dev-0 experience this weekend is very
likely the worst of it for the whole rebuild, not a preview of four more
rounds like it.

## Related

- `docs/deferred/migrate-large-migration-oom-and-resume-behavior.md` — the
  OOM/resume finding from the same run
- `docs/deferred/d11-dev-database-bootstrap-and-migration-source.md` — the
  overall migration effort this was discovered during
- `docs/dev-notes/howto-long-running-jobs-on-dev-staging.md` — the nightly
  shutdown constraint, a separate but related "budget realistic time"
  finding from the same weekend
