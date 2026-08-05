# fail2ban: an emergency measure, not an architecture — is it still needed?

**Area:** infrastructure / security / scraper mitigation
**Raised during:** Session 2026-07-14 (1b.1 part 4 — dev-0 drift capture)
**Jira:** (add when available)
**Priority:** Low — explicitly not a D11 blocker. **Updated 2026-08-05:** load did return
(2026-08-04 outage) — but in a shape this measure doesn't address; see the update below
before assuming "load returned" means "revive fail2ban."

## What this actually was (Yuji, 2026-07-14) — read this first

**fail2ban was an emergency measure — a bulwark thrown up against an active load
problem. It was never an architectural decision.** Do not evaluate it as one, and do
not "finish" it on the assumption that someone designed it to be finished.

That framing explains everything odd about its current state: half-built, on an
unmerged branch, with a container-level design forced by circumstance (the ALB
terminates every connection, so iptables cannot see real client IPs). It is what
incident response looks like after the incident passes.

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

The question is **not** "is this the right architecture" — it was never proposed as
one. It is:

1. **Is the load problem still there?** That is the only thing that decides whether
   any bulwark is needed. The measure was aimed at a specific active problem; if that
   problem is gone, so is the reason.
2. **Did it work?** Nobody appears to have established whether the half-built measure
   actually mitigated anything before attention moved on. Worth knowing before
   reaching for it again.
3. **If load returns, is this still the fastest bulwark to hand?** — not "is it
   elegant". `global/waf-v2/` exists and is actively maintained, and may now be the
   quicker lever than reviving a half-finished container-level design. But under
   pressure, the thing that already half-exists has real value; that is why this note
   records the prior art rather than just deleting it.
4. **If the answer is a settled no:** delete the host-side banlist machinery from
   `configure_backend.yml` and close out the `fail2ban-rework` branch — so the next
   person does not mistake an abandoned emergency for an unfinished design.

## Update (2026-08-05) — the load problem returned; here's what actually happened

**Answers the three open questions above with a real incident instead of hypotheticals.**

1. **Is the load problem still there?** Yes — a ~14.7h worker-pool-exhaustion outage on
   2026-08-04→05 (17:15 EDT→08:07 EDT), the second of its kind after 2026-08-02→03. But
   it wasn't the *same* load problem this note was written for.

2. **Did fail2ban (even finished) work? Would it have?** No — checked directly against
   the actual traffic, not assumed. The Aug 4 outage was a large, genuinely distributed
   multi-bot crawl: 2,129 requests to KMaps explorer pages, 86% to distinct paths, 89%
   from distinct source IPs (top single IP: 5 hits across the *whole day*), spanning
   named crawlers (Amazonbot, Applebot, Bytespider, Baiduspider) plus a UA-rotating
   scraper. The existing `sources-scraper-ratelimit` jail's mechanism — `maxretry=30` in
   a 300s window from one IP, banned by `/24` — fundamentally cannot catch this shape:
   there's no per-IP or per-subnet concentration to detect. (It also doesn't cover these
   routes at all — its filter is hard-anchored to `sources-search`/`biblio` only.) This
   is a genuinely *different* traffic shape than the 2026-07 biblio/sources-search
   scraper (`84.75.150.0/24`, `82.38.180.0/24` — a small number of proxy-reseller blocks
   doing high per-IP volume) fail2ban *was* built for. So: fail2ban wasn't tested and
   found wanting here — it was never applicable to this attack surface to begin with.

3. **If load returns, is fail2ban still the fastest bulwark to hand?** Not for *this*
   traffic shape. What actually fixed the Aug 4 outage, on the legacy D7 side:
   - `robots.txt` `Disallow` on the expensive KMaps "explorer" secondary tabs
     (audio-video-node, sources-node, etc. — ~79% of the crawl's server-time), `Allow`
     kept on the overview tab for discoverability. Fits self-identifying, largely
     policy-compliant crawlers (Amazonbot/Applebot/Baidu) — a lever fail2ban has no
     equivalent for.
   - Extended the KMaps explorer cache TTL 1h → 12h (the corpus is genuinely low-churn
     reference data — most recent edit across all 473K entities was 19 days old at
     check time), since a short TTL was defeating caching entirely against a wide,
     sparse, mostly-non-repeating crawl (86% of hit paths were one-time-only).
   - Also hardened several previously un-timed/under-timed upstream HTTP calls in
     `kmaps_explorer` (bounds worst-case cost per request), and added a scoped per-ID
     cache-clear (admin form field + drush command + admin-only link on the overview
     tab) so editors don't have to nuke the whole KMaps cache to propagate one edit.

   None of this is fail2ban, and none of it needed the half-built `fail2ban-rework`
   branch. **Concrete evidence for question 3:** for a distributed, self-identifying
   crawl against a large, low-churn, cacheable corpus, `robots.txt` + cache-TTL tuning
   is the faster and more correctly-targeted bulwark — not IP rate-limiting. fail2ban
   (or `global/waf-v2/`) would likely still be the right tool for a *concentrated*-IP
   repeat of the 2026-07 biblio-scraper pattern specifically — today's finding doesn't
   settle that case, only this one.

**Full technical detail:** `mandala-legacy` repo, memory record
`mandala-outage-2026-08-04-recurrence.md` (CloudWatch Insights evidence trail — exact
request counts/durations, per-tab cost breakdown, Solr corpus-size and edit-recency
checks) and `shanti-uva/mandala-drupal` commits/tags `7.x-1.43.5` through `7.x-1.43.8`.

**Question 4 (delete the dead host-side banlist machinery / close `fail2ban-rework`) is
still open** — the case for keeping fail2ban alive for the *2026-07 biblio-scraper*
pattern specifically (concentrated `/24`s, not this crawl) is unaffected by today's
finding, so this isn't a settled "no" yet. What's settled: "load returned" does not by
itself mean "revive fail2ban" — check the traffic shape first.

## Cross-references

- [dev-0-drift-capture.md](../planning/dev-0-drift-capture.md) — where this was found; fail2ban is explicitly out of scope there
- `terraform-infrastructure/mandala/drupal/staging/ansible/configure_backend.yml` — the host half
- `shanti-uva/mandala_drupal_docker` branch `fail2ban-rework` — the container half
- `terraform-infrastructure/global/waf-v2/` — a possible alternative layer
- `mandala-legacy` memory record `mandala-outage-2026-08-04-recurrence.md` — full 2026-08-04/05 incident detail (2026-08-05 update)
