# ADR 015 shipped with three questions unanswered — including whether `content_editor` applies to anybody

**Area:** access / users / migration / ADR 015
**Raised during:** PR #75 merge, 2026-08-06 (verification by Than 2026-07-30 and Xiaoming 2026-08-06)
**Jira:** (add when available)
**Priority:** **High for question 1** (the role may currently grant editorial access to zero users, and ADR 015's own guardrail is unmet) · Medium for questions 2 and 3

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
is still open.

**To resolve — both parts are open:**

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
- **(b)** Still fully open — a policy call, not a data question. Given (a) is now settled with
  two independent confirmations (scrubbed + live dump, plus the codebase grep), the team can
  decide this without further data collection: does the team still want `content_editor`
  reserved for `shanti editor` — accepting that Phase A delivers editorial access to zero
  migrated users until Phase B — or does that change the Phase A/Phase B split? A new ADR
  superseding 015 would be the vehicle if the answer changes the model; ADRs are immutable once
  accepted.

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

That may well be correct and desirable for Mandala, where every image belongs to a collection,
and it is the tighter default. But ADR 015's wording ("may create / edit / delete any content
item of any content type in any collection or subcollection") does not settle whether
collection-less creation should be possible.

**To resolve (open):** leave `create` group-scoped only, or additionally grant core
`create shanti_image content` so `/node/add` works? Whichever way it lands, it should be
written down — ADR 015 makes granting `content_editor` full CRUD a **required checklist item
for every subsequent per-site migration** (Texts, Sources, AV, Mandala Home), so this choice
becomes a precedent each of those inherits. Leaving it undocumented guarantees it gets
re-litigated per site.

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

Question 1(b) should be settled before the next real migration run against dev-0, since it
determines whether Phase A delivers any editorial access at all — 1(a)'s data question is now
closed (2026-08-06), so this is purely a team policy call, not blocked on further investigation.
Questions 2 and 3 are not urgent but should be decided before the next per-site migration
(Texts/Sources/AV) inherits question 2's precedent unexamined.
