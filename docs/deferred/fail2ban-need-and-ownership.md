# fail2ban: is it needed at all? — separate track, decoupled from D11

**Area:** infrastructure / security / scraper mitigation
**Raised during:** Session 2026-07-14 (1b.1 part 4 — dev-0 drift capture)
**Jira:** (add when available)
**Priority:** Low for now — explicitly **not** a D11 blocker; revisit as its own piece of work

## Decision (Yuji, 2026-07-14)

**Completely separate the fail2ban work from the D11 work.** Whether fail2ban is
needed at all is a future question, to be addressed on its own terms rather than
carried along by the rebuild.

**Consequence: it does not gate the D11 cutover.** Do not treat it as a component to
account for in scope doc §6, and do not let it hold up
[`dev-0-drift-capture.md`](../planning/dev-0-drift-capture.md).

## What exists today (so the future decision starts from facts, not archaeology)

The work is **half-built and split across two halves that never met**:

**Host half — already committed, already in D11's IaC.**
`terraform-infrastructure/mandala/drupal/staging/ansible/configure_backend.yml`
builds `mandala-banlist-reload.sh` + a `mandala-banlist-reload.timer`, which batches
Apache reloads rather than firing one per ban (to survive a reload storm during a
scraper burst). Its comment explains the design:

> *"fail2ban runs inside the app container and only edits an Apache-level deny list
> (`/etc/apache2/banned-ips.conf`) — iptables can't block by real client IP here since
> the ALB terminates every connection itself. Reload is batched on the host, not fired
> per-ban, to avoid a reload storm during a scraper burst. See mandala_drupal_docker's
> fail2ban `action.d/apache-deny*.conf` and
> `build/files/etc/apache2/conf-available/scraper-blocklist.conf`."*

**Container half — on a branch, possibly only on dev-0.**
`shanti-uva/mandala_drupal_docker`, branch **`fail2ban-rework`** (not `main`), checked
out at `/usr/local/dockerfiles`. Holds the fail2ban `action.d/apache-deny*.conf` and
`scraper-blocklist.conf` the host half names.

**And the two halves do not connect on D11.** The design assumes an app container
running fail2ban. **D11's `package/Dockerfile` installs no fail2ban at all**
(mentions: 0), and under this decoupling it is not expected to. So on a D11 box the
host-side banlist script and timer are **dead code**.

## Immediate hygiene — not a dependency, just don't lose the work

If `fail2ban-rework` was never pushed and dev-0 is replaced in place (§5.1), the work
is **gone**. Independent of whether fail2ban is ever adopted:

```bash
git -C /usr/local/dockerfiles log --oneline @{u}..HEAD   # unpushed?
git -C /usr/local/dockerfiles push origin fail2ban-rework
```

Also on that checkout: an untracked `docker-compose-dev.yml`. Triage with it.

## Housekeeping this implies for D11

When scope doc §4 item 2 adapts `configure_backend.yml` for D11 (dropping the
Aegir/hostmaster bits), **drop the banlist script + timer with them** rather than
porting them forward. Leaving them installs a timer that reloads Apache in response
to a file nothing writes.

## The actual question, for whenever this is picked up

1. **Is scraper mitigation needed at all** for D11, and at what layer? The ALB
   terminates every connection, which is what pushed the original design into the
   container in the first place.
2. **If yes, is fail2ban-in-the-container the right shape**, or does it belong at the
   ALB / WAF layer? Note `global/waf-v2/` exists and is actively maintained — that may
   be the natural home, and would make the container-level design moot.
3. **If no**, delete the host-side banlist machinery from `configure_backend.yml` and
   close out the `fail2ban-rework` branch.

## Cross-references

- [dev-0-drift-capture.md](../planning/dev-0-drift-capture.md) — where this was found; fail2ban is explicitly out of scope there
- `terraform-infrastructure/mandala/drupal/staging/ansible/configure_backend.yml` — the host half
- `shanti-uva/mandala_drupal_docker` branch `fail2ban-rework` — the container half
- `terraform-infrastructure/global/waf-v2/` — a possible alternative layer
