# dev-0's `files/styles/` directory was root-owned — fixed live, worth understanding why

**Area:** infra / dev-0 filesystem
**Raised during:** Session 2026-09-03 (getting B5's collection-viewing work live on dev-0
after merging [PR #183](https://github.com/uvalib/mandala-navina/pull/183) — see
[docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md),
Workstream B5)
**Priority:** Low — fixed live, not currently blocking anything. Worth a root-cause pass
so it doesn't silently recur (e.g. on the next dev-0 rebuild or in staging/production).

## What happened

After migrating real featured images onto dev-0's Group entities and clearing caches,
every collection card's thumbnail 500'd. `drush watchdog:show` showed:

```
Error: Failed to create style directory: public://styles/medium/public
```

`ls -la web/sites/default/files/` showed every real file `-rw-r--r--` owned by
`www-data:www-data` (correct — that's the PHP-FPM/webserver process user), but
`web/sites/default/files/styles/` itself was `drwxrwxr-x root:root`. Group-write was
set, but `www-data` isn't a member of the `root` group, so the webserver process
couldn't create the `medium/` subdirectory Drupal's image style system needs on first
use of any image style.

This was the **first real usage of an image style anywhere in D11's Group content** —
nothing before this session had a real image field on a Group entity, so nothing had
exercised this path yet. Not necessarily specific to Group content; any *first* use of
*any* image style on this container would have hit the same wall, if node/file image
styles hadn't already been exercised elsewhere (they likely have been, for `shanti_image`
or other content, which is presumably why this wasn't caught earlier).

## Fix applied live (with explicit group agreement — Than, Yuji, Xiaoming)

```
sudo docker exec -u root mandala-drupal-0 chown -R www-data:www-data \
  /opt/drupal/app/drupal/web/sites/default/files/styles
```

Confirmed fixed: the previously-500ing derivative URL now returns `200 image/webp`.
`ls -la` after the fix shows `styles/` as `www-data:www-data`, matching the rest of
`files/`.

## Still open

- **Root cause not investigated** — why is `styles/` root-owned while its sibling
  directories aren't? Possibilities: created once by a root-context provisioning/deploy
  step (Ansible, container build) before the app ever ran, or a leftover from an image
  rebuild that ran as root. Worth checking `terraform-infrastructure`'s Ansible
  playbooks / the Dockerfile for anywhere `styles/` (or `files/` generally) gets
  explicitly created, to see if the ownership should be fixed at the source rather than
  patched live each time.
- **This fix is container-local and ephemeral** — a container restart/redeploy that
  rebuilds the filesystem from the image (rather than reusing the same running
  container's writable layer) could reintroduce the same root-ownership, depending on
  how `files/` is actually persisted (bind mount vs. baked into the image vs. a
  volume). Not confirmed which applies here. If the container gets rebuilt and image
  styles start 500ing again, this is almost certainly why — re-apply the same `chown`,
  and use that recurrence as a reason to actually fix it at the provisioning layer
  instead of live-patching indefinitely.
- Worth checking whether staging/production have (or will have) the same root-owned
  `styles/` directory before this becomes a real incident there instead of a caught-early
  dev-0 finding.
