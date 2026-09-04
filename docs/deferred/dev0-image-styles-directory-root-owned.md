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

## Follow-up: persistence mechanism confirmed (2026-09-04)

Checked directly on dev-0 via `docker inspect mandala-drupal-0 --format '{{json .Mounts}}'`:
`sites/default/files` (the whole tree, not `styles/` specifically) is a **bind mount**,
not a Docker volume or part of the image's writable layer:

```
/mnt/data/mandala-drupal-0/sites/default/files → /opt/drupal/app/drupal/web/sites/default/files
```

(alongside separate bind mounts for `keys/` and the SimpleSAMLphp dirs — no named
volumes anywhere in this container's mount list).

This resolves the "is the fix ephemeral" question: it isn't. The `chown` run from
inside the container as root wrote straight through to the host path — confirmed via
`ls -la /mnt/data/mandala-drupal-0/sites/default/files/styles/` on the host, now
`www-data:www-data` (uid/gid 33) — so a container restart or redeploy against the same
bind mount will **not** reintroduce the root ownership; the fix is persisted at the
host filesystem, not the container layer.

## Still open

- **Root cause not investigated** — why was `styles/` root-owned while its sibling
  directories weren't, given the fix persists at the host path? Possibilities: created
  once by a root-context provisioning/deploy step (Ansible, container build) writing to
  `/mnt/data/mandala-drupal-0/` before the app ever ran, or a leftover from some earlier
  `docker exec -u root` on this host. Worth checking `terraform-infrastructure`'s
  Ansible playbooks / the Dockerfile for anywhere `styles/` (or `files/` generally) gets
  explicitly created, to fix it at the source rather than have it silently already-fixed
  on this one host.
- Worth checking whether staging/production have (or will have) the same root-owned
  `styles/` directory on their own `/mnt/data/<container>/sites/default/files/` host
  paths before this becomes a real incident there instead of a caught-early dev-0
  finding — each host's bind-mount source is independent, so dev-0 being fixed says
  nothing about staging/production.
