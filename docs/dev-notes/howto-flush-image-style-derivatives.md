# How-To: Flush image style derivatives after replacing a file's content

**Audience:** developers and content managers in the monorepo
**Last reviewed:** 2026-09-21

## Goal

Understand why a managed file's already-generated image style derivatives
(the resized/converted copies under `sites/default/files/styles/...`) don't
update just because the file's own content changed, and how to force them
to regenerate with `drush image:flush`.

## The gotcha

Drupal generates an image style derivative **once**, the first time it's
requested, and caches it on disk under `public://styles/<style>/public/...`.
After that, the derivative route only checks whether that cached file
*exists* — it does not compare it against the source file's content or
modification time. If you replace a managed file's binary data in place
(same file id, same URI — e.g. via `\Drupal::service('file.repository')
->writeData($data, $existing_uri, FileSystemInterface::EXISTS_REPLACE)`),
every image style's derivative that was already generated from the old
content keeps being served unchanged, even though the source file
underneath it is now different. Nothing errors; the page just quietly
keeps showing the old image.

This bit us concretely 2026-09-21: a hero carousel image was re-imported at
a much higher resolution (a Kaltura thumbnail, originally fetched at
Kaltura's low-res default), but the site kept showing the old, tiny
version — because the `wide` image style's derivative had already been
generated (and cached) from the original low-res file during earlier
testing, before the re-import.

## Prerequisites

- DDEV running (`ddev start`), or the equivalent `drush` access on
  dev-0/staging.

## Steps

1. Identify the image style(s) whose derivatives need to be cleared. Check
   the field's display config (`core.entity_view_display.*.yml`) or just
   inspect a rendered `<img>` src for the `/styles/<name>/` segment.
2. Flush that style's cached derivatives:
   ```bash
   ddev drush image:flush wide
   ```
   Comma-separate multiple style names in one call:
   ```bash
   ddev drush image:flush wide,large,thumbnail
   ```
   Run with no arguments to get an interactive picker, or flush every style
   site-wide (heavier, regenerates everything on next view):
   ```bash
   ddev drush image:flush --all
   ```
3. Derivatives regenerate lazily, the next time each one is actually
   requested (by a page view). To eagerly regenerate one specific
   derivative right away instead of waiting on that:
   ```bash
   ddev drush image:derive wide public://path/to/file.jpg
   ```

## Verify

Fetch the derivative directly and check its real dimensions/size, rather
than trusting a browser tab that may itself be caching the old image:

```bash
# Find the current itok query-string token for a given source file --
# grep a fresh page render for the file's own derivative URL rather than
# guessing the token; it's stable for a given file+style, not random.
curl -sk https://mandala.ddev.site:8443/home -o /tmp/page.html
grep -o 'your-file-name.jpg.webp?itok=[^"]*' /tmp/page.html

# Fetch and inspect the actual derivative
curl -sk "https://mandala.ddev.site:8443/sites/default/files/styles/wide/public/your-file-name.jpg.webp?itok=<token>" -o /tmp/check.webp
file /tmp/check.webp   # shows real pixel dimensions and encoding
```

A derivative regenerated from genuinely new source content will differ
noticeably in file size and reported dimensions from the stale one.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Old image still shows after replacing a file's content, even after `drush cache:rebuild` | Image style derivative cache is separate from Drupal's render/config cache; `cache:rebuild` doesn't touch it | `drush image:flush <style>` |
| Still looks the same after `image:flush` | Your own browser cached the derivative at that exact URL (same `itok`, since the token is stable per file+style, not random) | Hard-reload / fetch the derivative URL directly with `curl` to check the actual bytes on the server, independent of browser cache |
| `image:flush` ran, but the derivative still looks low-quality | The *source* image itself may genuinely be low-resolution/low-quality (e.g. a blurry auto-selected video thumbnail frame) — flushing only forces regeneration from whatever the current source actually is | Confirm the source file's own real dimensions/quality first (e.g. `identify` or `file` on the local file) before assuming the style pipeline is at fault |

## Related

- [howto-local-dev.md](howto-local-dev.md) — general DDEV/config workflow
- `drupal/config/sync/image.style.*.yml` — the site's defined image styles
