# ADR 015 shipped with three questions unanswered — including whether `content_editor` applies to anybody

**Area:** access / users / migration / ADR 015
**Raised during:** PR #75 merge, 2026-08-06 (verification by Than 2026-07-30 and Xiaoming 2026-08-06)
**Jira:** (add when available)
**Priority:** Question 1 **RESOLVED** (1(a) data 2026-08-06, 1(b) policy 2026-08-07); its High-priority follow-through moved to the prerequisite [`authenticated-contributor-crud-not-wired-in-d11.md`](authenticated-contributor-crud-not-wired-in-d11.md). Questions 2 and 3 still open (Medium)

## Context

[ADR 015](../adr/015-editorial-access-model-global-content-editor.md) was Accepted and its
implementation ([PR #75](https://github.com/uvalib/mandala-navina/pull/75)) merged on
2026-08-06 (`main` @ `29f546c`).

The implementation is **verified and correct**. It was checked twice, independently, and both
runs agree:

- **Than, 2026-07-30** — full run against the scrubbed `mandala_shared` (1,538 users).
- **Xiaoming, 2026-08-06** — independent second run on a synthetic non-PII fixture.

Both confirmed: the role map behaves as specified (rid 3 → `administrator`, rid 6 →
`content_editor`, rids 4/5 → authenticated only, and a user holding both 4 and 6 collapses to
`content_editor` alone); `content_editor` gets full CRUD on public *and* private collections
without group membership, while a plain authenticated user cannot even view private content;
no permission-wipe regression (13 permissions held across the run); `cim`/`cex` round-trips
clean; the non-admin floor holds.

**Nothing here is a defect.** What follows are three questions that were raised on the PR and
merged without answers. They are recorded so they are not lost — the PR thread is closed and
these will otherwise only resurface when something behaves unexpectedly on dev-0.

> Note for future readers: PR #75's *body* said DDEV was down at authoring time, which led to a
> claim circulating on 2026-08-06 that the code had never been verified. That was wrong — the
> verification was posted as a PR *comment*. Read the comments, not just the body.

---

## Question 1 (High) — zero `shanti editor`s in the scrubbed DB, so the role may apply to nobody

Raised by Than on PR #75, never answered.

Role distribution in the scrubbed `mandala_shared.users_roles`:

| D7 rid | Role | Count |
|---|---|---|
| 3 | administrator | 23 |
| 4 | editor | 142 |
| 5 | workflow editor | 2 |
| **6** | **shanti editor** | **0** |

ADR 015 grants `content_editor` to **rid 6 only**. Against this data, a real migration run
assigns `content_editor` to **nobody**, while all **142 real editors** migrate as plain
authenticated users whose editing capability does not return until the Phase B group-role
migration.

This is faithful to ADR 015 as written — the ADR deliberately declines to promote group-scoped
rid-4 editors to a global role, since that would be a privilege escalation. But the practical
consequence is that **Phase A may deliver editorial capability to zero migrated users**, which
is very likely not what anyone pictured when accepting the ADR.

ADR 015's own Consequences section already names this as a guardrail:

> *"the former-`shanti_editor` population must be confirmed on dev-0 before running, so the
> global grant lands only where intended."*

**That guardrail's data half is now met** (see (a) below — confirmed via the same live dump
loaded on `rds-mysql8-staging` that dev-0's own migration source uses); its policy half, (b),
is **now decided** (2026-08-07, Than — see (b)).

**Both parts are now settled — Question 1 is resolved:**

- **(a)** ✅ **Answered 2026-08-06.** Confirmed the rid-6 count against dev-0's live
  `mandala_d7_shared` (loaded on `rds-mysql8-staging`, 1,543 users — not the 1,538-user
  scrubbed dump Than checked) via `SELECT r.rid, r.name, COUNT(ur.uid) FROM role r LEFT JOIN
  users_roles ur ON ur.rid = r.rid WHERE r.rid IN (4,5,6) GROUP BY r.rid, r.name`. **Identical
  distribution: rid 4 (editor) = 142, rid 5 (workflow editor) = 2, rid 6 (shanti editor) = 0.**
  The scrubbed dump was representative — this is not a scrubbing artifact. A legacy-codebase
  grep (`mandala-legacy/mandala-drupal/docroot`, all custom modules + Features exports, all 5
  sites) additionally found **zero references to `shanti editor` anywhere in the D7 codebase**
  — no Features export, no hardcoded `rid` check. It wasn't just unassigned; nothing in the
  code ever granted it anything, on any site. (As a side finding, `workflow editor` — rid 5 —
  turned out to have one real, narrow grant, but only on the **AV site**:
  `mediabase/features/audio_video` exports `edit`/`view field_workflow` via the
  `field_permissions` module, a single workflow-state field, not general node edit rights.
  Doesn't change this question's answer, since it's still only 2 users, but may be relevant
  context whenever AV's migration hits ADR 015's per-site `content_editor` CRUD checklist item.)
- **(b)** ✅ **Decided 2026-08-07 (Than).** `content_editor` stays reserved for the
  `shanti editor` equivalent and **migrates empty** — since rid 6 = 0 users, no one is
  auto-assigned it. It is granted **by hand**, per person, going forward. The **142 rid-4
  `editor` users migrate as plain authenticated users**; their per-collection editing returns
  with the Phase B Group-role migration, not before.

  This **confirms ADR 015's model rather than changing it**, so it needs **no superseding ADR
  and no code change** — the merged implementation (PR #75) already produces exactly this
  outcome (rid 6 → `content_editor`, rid 4 → authenticated). This note simply records that the
  team examined the "Phase A grants editorial access to zero users" consequence with the data
  in hand and accepted it deliberately.

  **Consequence — why "142 → authenticated" is non-destructive, and its one prerequisite.**
  Migrating the 142 editors as authenticated is *not* a loss of authoring, **provided the
  authenticated contributor tier is wired**. In D7, authenticated users hold core, site-wide
  `create` / `edit own` / `delete own` on every asset **and** collection content type (verified
  against the Images per-site dump), so as authenticated they keep full CRUD on **their own**
  content and lose only the ability to edit **others'** content until Phase B — a small blast
  radius. **But D11's committed `authenticated` role grants none of this** (it is view-only
  today). So this decision is only safe once that tier is implemented; against D11 as-is, "142
  → authenticated" means those users — and every other authenticated user — can author
  **nothing**. Wiring the contributor tier is therefore a **prerequisite gate** for the next
  real user-migration cutover, tracked in
  [`authenticated-contributor-crud-not-wired-in-d11.md`](authenticated-contributor-crud-not-wired-in-d11.md).

---

## Question 2 (Medium) — `create` is group-scoped only; bare `/node/add` is denied

Raised by Xiaoming on PR #75, never answered. This resolves the PR's own open checklist
question ("does Group's create path also need core `create shanti_image content`?").

Verified route access for a `content_editor` with no group membership:

- `entity.group_relationship.create_form` for `group_node:shanti_image` → **ALLOWED**, purely
  off the synchronized group role permission
- `node.add` for `shanti_image` (bare `/node/add/shanti_image`) → **DENIED** — the role holds
  neither core `create shanti_image content` nor core create access

So **no core permission needs adding** for the in-group path to work. But a `content_editor`
**cannot create an image outside a collection context**.

**✅ Decided 2026-08-07 (Than, team present — Yuji & Xiaoming).** Creation stays **group-scoped
only** — the current behavior (group create form ALLOWED, bare `/node/add` DENIED) is **correct
as-is**, and **no** core `create X content` is granted to any role. This is now a **universal
D11 rule:**

> **No content may be created outside a group — not authenticated users, not `content_editor`,
> not administrators.** All asset content must be created within a collection/subcollection
> group, through the group create path.

This is **faithful to D7's design intent, not a deviation** (so it is *not* an ADR 008/010
"improvement" that needs justifying). D7's permission config technically granted site-wide core
`create` to *authenticated*, but Mandala's intended model was always collection-based: content
lives in a collection. The **36 orphan `shanti_image`s** found with no collection at all
(verified in the Images prod dump — see
[`authenticated-contributor-crud-not-wired-in-d11.md`](authenticated-contributor-crud-not-wired-in-d11.md))
are **anomalies — data-entry mistakes or nodes created before collections existed — not a
supported feature.** D11 enforces the invariant D7 intended but never technically constrained.

**Consequences / follow-through:**

- The [contributor tier](authenticated-contributor-crud-not-wired-in-d11.md) create is likewise
  group-scoped, **not** core site-wide — that note is revised accordingly.
- **Admin enforcement caveat:** administrators normally hold core `bypass node access`, which
  would let them create via `/node/add` regardless of Group scoping. Truly enforcing "not even
  admins" requires either **withholding `bypass node access`** or ensuring **no collection-less
  create route is exposed**. Flagged for the wiring — do not assume the blanket rule holds for
  admins for free.
- **Migration:** pre-existing orphan content (the 36 Images `shanti_image`s + any orphans of
  other asset types, **per site**) migrates into a **temporary review group**, not dropped and
  not force-fit — see [`orphaned-content-temp-group-on-migration.md`](orphaned-content-temp-group-on-migration.md).
- **Bootstrap / container — RESOLVED 2026-08-07 (same session):** **groups are the sole content
  type exempt** from the in-a-group rule. **Any authenticated user can create a group**
  (collections/subcollections) — and since `content_editor`s and admins are also authenticated,
  the capability is universal. So the create permission `create <group_type> group` is granted
  on the **`authenticated` role** (site-wide, no containing group required); every other content
  type is group-content-only. This bootstraps the model: create a collection, then create assets
  inside it. Also D7-faithful — rid 2 held core `create collection content` /
  `create subcollection content` site-wide.
- **Precedent for every per-site migration checklist** (Texts, Sources, AV, Mandala Home): asset
  content is group-content-only; grant no core create; sweep orphans into the review group.

---

## Question 3 (Medium) — `/admin` and `/admin/config` are reachable

Raised by Xiaoming on PR #75, never answered.

PR #75's checklist item read *"admin-surface lockout verified (`/admin/config`, views UI, user
admin)"*. Strictly, `/admin/config` is **not** locked out:

- `system.admin` → **ALLOWED**
- `system.admin_config` → **ALLOWED**

Both derive from `access administration pages`, which `content_editor` **already held before
PR #75** — the PR retained it rather than introducing it.

Everything beneath is denied, which is what actually matters. Verified denied: site
information, performance, cron, people, users admin, permissions, modules list, node types,
views UI, group admin, and the URL-alias overview. `is_admin` is `false` and the role holds no
`administer *` permissions. So the landing page renders as an empty shell and there is **no
privilege leak** — but behavior and the checklist's wording disagree.

**To resolve (open):** two directions, both with a real cost —

- **Drop `access administration pages`** — cleanest match to "no admin surface", but it also
  removes the toolbar admin menu, which editors may rely on to reach `/admin/content`. That is
  a UX change to editors' navigation that ADR 015 did not ask for.
- **Keep it and reword the item** to "no admin *functionality* reachable" — accepts a reachable
  but empty page.

---

## Suggested sequencing

Question 1 is now fully resolved — 1(a) (data) closed 2026-08-06, 1(b) (policy) decided
2026-08-07. What now gates the next real migration run against dev-0 is **not** this question
but its prerequisite: wiring the authenticated contributor tier
([`authenticated-contributor-crud-not-wired-in-d11.md`](authenticated-contributor-crud-not-wired-in-d11.md)),
without which the accepted "142 editors → authenticated" outcome leaves the whole user base
unable to author anything. Questions 2 and 3 are not urgent but should be decided before the
next per-site migration (Texts/Sources/AV) inherits question 2's precedent unexamined.
