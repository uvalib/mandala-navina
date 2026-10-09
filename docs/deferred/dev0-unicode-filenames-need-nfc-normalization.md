# dev-0's existing Unicode filenames are NFD, D11 expects NFC -- needs a team decision

**Area:** dev-0 infrastructure / file storage
**Raised during:** Session 2026-10-09, while trialing `stage_file_proxy` (see
[local-dev-files-provisioning-mechanism.md](local-dev-files-provisioning-mechanism.md)
and issue #298)
**Issue:** none
**Priority:** Low-Medium -- affects only Unicode-named files (a minority of
the corpus), and only the subset whose normalization form happens to differ
between dev-0's disk and the D11 database. Not known to be user-visible yet
(nothing has reported a 404 in the wild), but it silently breaks any tool
that fetches dev-0 files by exact path.

## The gap

Our D11 `file_managed` rows store Unicode filenames **NFC** (composed --
confirmed via `Normalizer::isNormalized()`). dev-0's on-disk files with the
same names are stored **NFD** (decomposed). The two forms render identically
to a human and are visually indistinguishable, but they are different byte
sequences, so any exact-path fetch against dev-0 -- `stage_file_proxy`,
`mandala:missing-file-audit`'s `findRemoteSource()`, a plain `curl`/`rsync`
-- 404s on the literal (NFC) path even though an NFD-normalized version of
the same URL succeeds.

Confirmed on two distinct filenames during the 2026-10-09 `stage_file_proxy`
trial:

- `transcripts/Tenpé Gyeltsen on the History of Sangdrok Monastery.xml`
- `transcripts/ཞིང་ཁམས།.xml` (Tibetan script)

Both returned 404 at their literal (NFC) path on dev-0; both returned 200
once manually re-encoded to NFD. This generalizes the single
`Rangdrol-Rinpoché.jpg` example already noted in
[local-dev-files-provisioning-mechanism.md](local-dev-files-provisioning-mechanism.md) --
it's a systemic form mismatch, not an isolated file. The scope (how many of
dev-0's files are affected) has not been measured.

## Why this exists

Likely origin: these files were uploaded or migrated from a macOS-backed
path at some point -- HFS+/APFS decomposes certain Unicode filenames (most
visibly Latin diacritics and some Tibetan combining sequences) to NFD at
the filesystem level, regardless of how the name was typed or how the
referencing database row stores it. Not confirmed with certainty, since
dev-0's provisioning history for these specific files wasn't traced in this
session.

**Future recurrence is already prevented, separately:** PR #314 adds
`mandala_file_hygiene` (`hook_file_presave()`), which normalizes every file
entity's filename -- and the physical file on disk -- to NFC at save time,
on every environment that has it installed. That stops *new* uploads from
reintroducing this. This note is only about the Unicode files that already
exist on dev-0 in NFD form today.

## Proposed approach (not started, needs sign-off)

A one-time rename pass on dev-0's `sites/default/files` tree: walk it,
find every path whose basename is not already NFC, rename it to its NFC
form. Since the D11 database already stores these filenames NFC, this is a
disk-only operation -- no `file_managed` rows need to change.

SSH reachability to dev-0
(`mandala-drupal-dev-0.internal.lib.virginia.edu`) was confirmed working in
the 2026-10-09 session.

## Why this needs a group decision, not just doing it

- **Shared, production-adjacent infrastructure.** dev-0 is the team's
  canonical shared environment (see
  [local-dev-files-provisioning-mechanism.md](local-dev-files-provisioning-mechanism.md)'s
  "dev-0 confirmed as a complete, durable source" section) -- a mistake here
  affects everyone, not just one laptop.
- **Scope is unmeasured.** We don't yet know how many files are affected,
  which fields they belong to, or whether any of them are referenced by
  anything outside Drupal (a hardcoded legacy link, a cached CDN path, an
  external citation) that assumes the current NFD path.
- **Needs a dry run first.** The rename list should be reviewed (diffed)
  before any file is actually touched, not executed opportunistically.

## Open questions for the team

1. Who owns running this, and when -- is it worth a short maintenance
   window, or safe to do live?
2. Should the dry-run / rename logic be a one-off script, or a flag on
   `mandala:missing-file-audit` (which already discovers file paths) --
   see the scoping discussion in
   [local-dev-files-provisioning-mechanism.md](local-dev-files-provisioning-mechanism.md)?
3. Does staging/production (once they exist) need the same check before
   their own first file-population pass, or is dev-0 the only place this
   history exists?
