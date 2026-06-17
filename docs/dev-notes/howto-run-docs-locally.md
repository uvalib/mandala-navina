# How-To: Run the docs site locally

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-06-17

## Goal

Preview this documentation site (Material for MkDocs) on your machine with live
reload, so you can check formatting and navigation before pushing.

## Prerequisites

- Python 3.x with `pip`.
- A virtual environment is recommended to keep dependencies isolated.

## Steps

1. From the repo root, create and activate a virtualenv (one-time):
   ```bash
   python3 -m venv .venv
   source .venv/bin/activate
   ```
2. Install the docs dependencies:
   ```bash
   pip install -r mkdocs/requirements.txt
   ```
3. Serve the site with live reload. The config lives in `mkdocs/`, and
   `docs_dir` points at `../docs`, so run from the `mkdocs/` directory:
   ```bash
   cd mkdocs && mkdocs serve
   ```
4. Open <http://127.0.0.1:8000/> in a browser. Edits to anything under `docs/`
   reload automatically.

## Verify

- The terminal prints `Serving on http://127.0.0.1:8000/`.
- The left navigation shows collapsible sections (Architecture Decisions,
  Spikes, Developer Notes, …).
- To confirm a production-equivalent build with no warnings:
  ```bash
  cd mkdocs && mkdocs build --strict
  ```

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| A new page is missing from the nav | Not listed in that directory's `.pages` | Add it to the section's `.pages` (see [session rituals](rituals-session-workflow.md)) |
| `mkdocs: command not found` | venv not active / deps not installed | `source .venv/bin/activate` then re-run the `pip install` step |
| Build fails on a broken link | `--strict` treats warnings as errors | Fix the link the warning names, or drop `--strict` while drafting |

## How publishing works

Pushing to `main` with changes under `docs/**` or `mkdocs/**` triggers the
`Publish docs` GitHub Action (`.github/workflows/docs.yml`), which runs
`mkdocs build` and deploys to GitHub Pages at
<https://uvalib.github.io/mandala-navina/>. There is no manual deploy step.

## Related

- [Session rituals](rituals-session-workflow.md) — the `.pages` discipline
- [Developer Notes overview](README.md)
