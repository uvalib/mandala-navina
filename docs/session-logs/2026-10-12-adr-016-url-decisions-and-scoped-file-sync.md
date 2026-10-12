# Session Log: ADR 016 URL Decisions and Scoped File Sync

**Date:** 2026-10-12  
**Participants:** Than Grove (driving), Yuji Shinozaki (joined partway); Claude Code  
**Outcome:** ADR 016 items 2 and 3 decided; item 1 and the D7 alias satellite-scope question written up as [issue #316](https://github.com/uvalib/mandala-navina/issues/316) and
[`legacy-url-host-aware-redirect-mechanism.md`](../deferred/legacy-url-host-aware-redirect-mechanism.md), assigned to Yuji; local dev file-parity scope ([issue #298](https://github.com/uvalib/mandala-navina/issues/298)) resolved and implemented in [PR #317](https://github.com/uvalib/mandala-navina/pull/317), open for Yuji's review.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-12 14:03

Let's start a session. It's going to be a group session with just me and Yuji, not Xiaoming. PUll the latest code and do all the session starting ritual

---

## Claude — 2026-10-12 14:03

I'll run the session start script to handle the git sync and parity checks.

---

## Claude — 2026-10-12 14:04

Pulled cleanly (new PR #290 branch landed, spike-11 docs updated). dev-0 is unreachable (VPN appears off) — timed out on SSH, so I skipped the DB/content parity comparison. Let me check if you're on VPN, and in the meantime read the orientation docs.

---

## Claude — 2026-10-12 14:04

Now let me read the most recent session log and check PR #290 (the new branch that just appeared).

---

## Claude — 2026-10-12 14:04

There's an open draft PR #315 (Yuji's work, issue #290, "join-based access filter for the transcript core" half b) that's unmerged — this is live context for today since Yuji's in the session.

---

## Claude — 2026-10-12 14:04

Now let me check the other deferred issues' recent activity and whether anything is newly assigned/stale, plus check my memory for Mandala state before summarizing.

---

## Claude — 2026-10-12 14:05

Good, that's a clear picture. All automated checks are done. Here's the session orientation summary:

## Session start complete

**Git sync:** Fast-forwarded cleanly (`cb5f16a` → `a6868a4`). Brought in: PR #285 merged (Spike 11 solrconfig + join prototype), plus a new branch `feat/290-trid-writer-and-join-filter`.

**dev-0:** Unreachable (SSH timeout — VPN looks off). Local-only config:status is clean; `config/sync` matches origin. If you want the DB/content parity check, hop on VPN and I'll re-run step 3b/3d.

**Live/relevant threads for today (you + Yuji):**
- **Issue #290 (Spike 11 T9)** — Yuji's own open work: half (b), the solr-proxy join-based access filter, is in **draft PR #315**, offline-checked only, needs a live transcript core to verify end-to-end. Half (a), the kmassets `trid_i` writer, hasn't started — blocked on a decision: build against the D7 mapping now, or wait for the T2 transcript entity to exist. That decision is exactly the kind of thing to raise with Yuji in the room.
- **Issue #291** — verify kmassets `trid_i` has docValues on the *deployed* schema (needs dev-0/VPN — currently blocked by the same connectivity issue).
- Both of those plus core creation on dev Solr and join-cost-at-scale are listed as "needs dev-0/VPN" blockers in the issue thread.
- **Issues #296, #295, #294** are all Yuji-owned, waiting on Dave — not actionable today without him, but worth a status check-in since he's not in the room.
- **#304** (Jira project) also Yuji-owned, no update since assignment.
- Unassigned: **#300** (periodic deferred-doc review, free pickup) and **#298** (local files provisioning — needs group decision, agenda item only).

Given it's just you and Yuji, the natural focus is **Spike 11 / #290** — PR #315's join filter and the half-(a) writer decision. Want me to pull up PR #315's diff, or get on VPN first to unblock the dev-0-dependent pieces?

---

## User — 2026-10-12 14:12

Spike 11 is owned by Yuji. Are there other more collaborative things we would work on?

---

## Claude — 2026-10-12 14:13

I found a strong candidate: **ADR 016** (public URL structure) is still in **Proposed** status, not Accepted — and explicitly can't close without both of you:

## ADR 016: Public URL structure — still open, needs you both

Three items are blocking acceptance, and they're genuinely joint, not single-owner:

1. **Host-awareness mechanism** — `field_legacy_nid` alone isn't a unique key once a second collection migrates (bare nid collides across D7 sites). Needs deciding: bundle-scoped lookup vs. a new `field_legacy_site` field. This is Yuji's architecture call but affects every migration you (Than) write next.
2. **Canonical path in D11** — preserved D7 alias vs. `/images/{nid}` — affects what gets emitted as `url_html`.
3. **`mandala-om` coordination on `url_html`** — the doc literally says *"Needs Than"* — `MandalaMarkup.js`'s reverse Solr lookup (`q: url_html:"…"`) depends on whatever D11 emits, both forward links and reverse lookup.

The doc flags item 1 as **must be settled "before the next collection migrates"** — and Texts/Sources/AV are exactly what's coming next per ADR 018. So this isn't abstract cleanup; it's a blocker sitting in front of the next migration tracks, and it's stale (proposed 2026-08-25, no resolution since).

This fits a two-person session much better than Spike 11: it needs Yuji's architectural judgment call on the redirect/lookup mechanism and your knowledge of the React consumer (`mandala-om`/`MandalaMarkup.js`) in the same conversation.

Want me to pull up the relevant `mandala-om` code (`MandalaMarkup.js`'s `url_html` usage) so you two have concrete ground to decide item 2/3 against, before you start the discussion?

---

## User — 2026-10-12 14:21

Let's settle #1. Can you explain in more detail?

---

## Claude — 2026-10-12 14:21

ADR 016 and ADR 017 overlap. Let me check whether ADR 017 already resolved part of this.

---

## Claude — 2026-10-12 14:21

I'm checking current implementation state (whether the `redirect` module, `field_legacy_site`, or any host-aware logic already exist) before explaining further — one sec.

---

## Claude — 2026-10-12 14:21

Here's the full picture on open item 1 while I wait on the implementation-state check.

## What open item 1 actually is

Two sub-questions, bundled together in the ADR text — and they're separable:

**Sub-question A: which discriminator resolves the per-site nid collision?**
Bundle-scoped lookup (infer site from content type) vs. a new `field_legacy_site` field. **This is already decided** — ADR 017 (accepted 2026-09-02) chose `field_legacy_site` + `field_legacy_nid` as the composite key, with `audio-video` as a shared token for both AV bundles. ADR 016 just hasn't been updated to point at it — the open-item text at line 218 still frames this as unresolved, which is now stale. That's a quick doc fix, not a decision to make today.

**Sub-question B: how is host-awareness actually implemented in the redirect path?**
This is the real unresolved piece. Context from the ADR's decision 6/7 split:

- D7 had two URL forms per node: the bookmarked slug alias (`/image/{slug}`) and the bare canonical (`/node/{d7nid}`).
- The **slug form needs no host-awareness at all** — it's migrated as a real D11 path alias (decision 7), already proven end-to-end for Images (111,304/111,304, 0 mismatches). Drupal resolves it natively; no lookup, no legacy host involved.
- The **bare-nid form is the hard half**. `/node/1631632` means a different asset depending on whether it came from `images…` or `av…`, because D7 nids are only unique per-site, not globally. A path-only redirect rule can't tell those apart — only the legacy *host* disambiguates it.
- The ADR already decided redirects must happen **in Drupal** (contrib `redirect` module), not at the edge/ALB, because the edge can't translate a D7 nid to a D11 nid.
- What's **not yet decided**: the actual mechanism for getting host context into that Drupal-side redirect logic — e.g., does the edge tag each legacy host onto a distinct internal path prefix that Drupal then strips/translates, or does a request subscriber read the `Host`/`X-Forwarded-Host` header directly and branch the lookup on it. Neither `redirect` nor `pathauto` is installed yet; this is greenfield.

I'm pulling current repo state (whether `redirect`/`field_legacy_site` already shipped, any existing redirect code) so you and Yuji aren't deciding against stale information — that check is running now, back in a moment.

---

## User — 2026-10-12 14:21

<task-notification>
<task-id>a4068d3eaf8959ef7</task-id>
<tool-use-id>toolu_01A4wwbLUNM1r3eLkGrjy21v</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/20997305-5550-4c85-af0f-354a287162eb/tasks/a4068d3eaf8959ef7.output</output-file>
<status>completed</status>
<summary>Agent "Check redirect module and legacy URL implementation status" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>Confirmed still wrong — the committed `url_html` templates still hardcode the wrong D7 host + bare `__NID__` substitution. Nothing has moved since ADR 016 was written. I have everything needed now.

## Open item 1, in detail

**What's already decided (ADR 017, accepted 2026-09-02):** the composite-key half. `field_legacy_site` exists now, live in `config/sync` on both `node` and `group` — confirmed in `field.storage.node.field_legacy_site.yml` etc. It's populated per migration (`images`, `audio-video`, …), and the team explicitly rejected the "derive site from bundle" alternative in favor of this explicit field. **So you don't need to re-decide that part today** — it's settled, and it's why ADR 016 can finally be unblocked.

**What's still open — the actual mechanism, not the data model:**

The problem: a legacy D7 URL like `av.mandala.library.virginia.edu/node/1631632` needs to redirect to the right D11 node. But the *host* (`av.mandala...` vs `images.mandala...`) is half the identity — the bare nid `1631632` is ambiguous across sites. Drupal's `redirect` module (not yet installed — I checked, it's absent from `composer.json` and `config/sync`) matches on **path only**, not host. So something has to translate "this host + this path" into the `field_legacy_site` + `field_legacy_nid` lookup before `redirect` can do its job.

Two ways to get there:

1. **Edge-tag approach** — have the edge (ALB/CloudFront/whatever sits in front of Drupal) rewrite `av.mandala...` → something like `/legacy/audio-video/node/1631632` on the single D11 host, and Drupal's redirect logic reads the site token out of that internal path prefix.
2. **Host-aware request subscriber** — a custom Symfony event subscriber inside Drupal that reads the incoming `Host` header directly (no edge rewrite needed), maps host → `field_legacy_site` token, and does the composite lookup itself before/instead of core's path-based redirect system.

This is a real architecture trade-off: edge-tagging pushes logic (and a dependency on routing config) outside Drupal into infrastructure Yuji owns; the subscriber keeps it all in Drupal/CMI but means `redirect` module doesn't do the lookup unaided — you'd likely still need custom code either way, just at a different layer.

Nothing has been built yet in either direction — I checked `drupal/web/modules/custom/` for any existing subscriber or redirect logic and found none, and the `mandala_kmassets_sync.settings.yml` URL templates are still exactly as wrong as ADR 016 described (hardcoded legacy host + bare D11 nid, still live in config/sync).

That's the actual decision in front of you two: **edge rewrite vs. in-Drupal host-aware subscriber** for resolving `(host, path) → (field_legacy_site, field_legacy_nid) → D11 entity`.</result>
<usage><subagent_tokens>109163</subagent_tokens><tool_uses>4</tool_uses><duration_ms>29539</duration_ms></usage>
</task-notification>

---

## Claude — 2026-10-12 14:22

Confirmed and up to date. Here's the full state:

## Settled already (ADR 017, 2026-09-02)
The data-model half of open item 1 — `field_legacy_site` is live in `config/sync` now (confirmed on both `node` and `group`), populated per migration with values like `images`, `audio-video`. The "derive site from bundle" alternative was explicitly rejected in favor of this explicit field. **ADR 016's open-item text is just stale** — it still frames this as a live choice. Worth fixing as a trivial doc edit once you close the remaining piece.

## What's actually still open
Nothing has been built. `redirect` module isn't installed (absent from `composer.json` and `config/sync`), there's no existing subscriber or redirect logic in `drupal/web/modules/custom/`, and `mandala_kmassets_sync.settings.yml` still hardcodes the wrong D7 host + bare D11 nid exactly as the ADR described — zero drift since 2026-08-25.

The real decision: a D7 bare-nid URL like `av.mandala…/node/1631632` needs its **host** to disambiguate which site's nid it is, but core `redirect` matches path only, not host. Two ways to supply that host context before the `(field_legacy_site, field_legacy_nid)` lookup runs:

1. **Edge-tag approach** — the ALB/edge rewrites `av.mandala…` → an internal prefix like `/legacy/audio-video/node/1631632` on the single D11 host; Drupal reads the site token out of that path. Pushes logic into infrastructure Yuji owns.
2. **Host-aware request subscriber** — a custom Symfony event subscriber inside Drupal reads the `Host`/`X-Forwarded-Host` header directly, maps it to the `field_legacy_site` token, and does the composite lookup itself. Keeps everything in Drupal/CMI, but means you're writing custom redirect logic either way — `redirect` module's own matching doesn't do this unaided under either option.

That's the trade-off for you and Yuji to settle: infrastructure-layer rewrite vs. in-Drupal subscriber for turning `(host, path)` into `(field_legacy_site, field_legacy_nid)` → the D11 entity. Want me to sketch what each would concretely look like (e.g. ALB rule shape vs. subscriber code skeleton) to make the trade-off easier to weigh?

---

## User — 2026-10-12 14:27

Yes, sketch both options

---

## Claude — 2026-10-12 14:28

Researching current infra and this repo's code conventions so the sketches are grounded, not generic — one moment.

---

## User — 2026-10-12 14:28

yuji is here

---

## Claude — 2026-10-12 14:28

Welcome, Yuji. I'm pulling concrete grounding (current ALB/edge routing setup and this repo's event-subscriber conventions) for the two redirect options before sketching — should be done shortly. Quick recap for context: we're deciding ADR 016 open item 1 — how a legacy D7 bare-nid URL (`av.mandala…/node/1631632`) gets its host disambiguated before the `field_legacy_site` + `field_legacy_nid` lookup runs, since `redirect` module matches path only, not host. Two candidates: edge-tag rewrite (host → internal path prefix) vs. an in-Drupal host-aware request subscriber.

---

## User — 2026-10-12 14:29

<task-notification>
<task-id>a520c916f845bb98a</task-id>
<tool-use-id>toolu_01K8onRKgF9cxnhkTwXasLHd</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/20997305-5550-4c85-af0f-354a287162eb/tasks/a520c916f845bb98a.output</output-file>
<status>completed</status>
<summary>Agent "Research infra and code patterns for redirect sketch" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>All three items confirmed. Now I have enough grounding to write both sketches.

**1. ALB/edge state:** No evidence that legacy hosts (`images.mandala…`, `av.mandala…`, etc.) are already consolidated behind a shared D11 ALB target group — host-based routing for the legacy domains is unreconciled infra (`docs/planning/1b1-part4-d11-backend-deploy-scope.md` shows ALB cleanup/target-port work still postponed/forwarded to Dave as of 2026-07-14). This is greenfield on both the infra and Drupal sides.

**2. Event-subscriber convention:** Tag is `{ name: event_subscriber }` (not `kernel.event_subscriber`), e.g. in `mandala_kmassets_sync.services.yml`. Request-aware subscribers extend/implement `Symfony\Component\HttpKernel\Event\RequestEvent` (see `mandala_saml_oauth/src/EventSubscriber/OauthAwareSimplesamlSubscriber.php`).

**3. `field_legacy_site` allowed values** (confirmed verbatim, `field.storage.node.field_legacy_site.yml`): `images`, `texts`, `sources`, `audio-video`, `visuals`, `mandala`.

Here are the two sketches:

---

## Option 1: Edge-tag approach

Infra rewrites the legacy host into an internal path prefix before it ever reaches Drupal; Drupal only ever sees one host.

```
# Illustrative ALB listener rule (conceptual — actual mechanism TBD with Dave,
# since infra ownership here isn't settled per docs/planning/1b1-part4-d11-backend-deploy-scope.md)

Listener rule (host: images.mandala.library.virginia.edu):
  → rewrite path: /node/1631632  =&gt;  /legacy/images/node/1631632
  → forward to: D11 target group

Listener rule (host: av.mandala.library.virginia.edu):
  → rewrite path: /node/1631632  =&gt;  /legacy/audio-video/node/1631632
  → forward to: D11 target group
```

```php
// drupal/web/modules/custom/mandala_legacy_redirect/mandala_legacy_redirect.routing.yml
mandala_legacy_redirect.legacy_node:
  path: '/legacy/{legacy_site}/node/{legacy_nid}'
  defaults:
    _controller: '\Drupal\mandala_legacy_redirect\Controller\LegacyNodeRedirectController::redirect'
  requirements:
    legacy_site: 'images|texts|sources|audio-video|visuals|mandala'
    legacy_nid: '\d+'
```

```php
// Controller: looks up (legacy_site, legacy_nid) -&gt; node, issues a real 301.
public function redirect(string $legacy_site, int $legacy_nid): RedirectResponse {
  $nids = $this-&gt;entityTypeManager-&gt;getStorage('node')-&gt;getQuery()
    -&gt;condition('field_legacy_site', $legacy_site)
    -&gt;condition('field_legacy_nid', $legacy_nid)
    -&gt;accessCheck(TRUE)
    -&gt;execute();
  if (!$nids) {
    throw new NotFoundHttpException();
  }
  $node = $this-&gt;entityTypeManager-&gt;getStorage('node')-&gt;load(reset($nids));
  return new RedirectResponse($node-&gt;toUrl()-&gt;toString(), 301);
}
```

**Trade-offs:** No request subscriber needed — a plain route + controller. ALB config change lives in `terraform-infrastructure`, outside this repo's deploy cadence, and needs Dave/Yuji infra time (echoes the already-postponed ALB cleanup item). Four extra listener rules (one per legacy host) must stay in sync with the `field_legacy_site` vocabulary if a sixth source ever appears.

---

## Option 2: Host-aware request subscriber

No edge changes. Drupal inspects the incoming `Host` header itself and does the translation before routing even resolves.

```yaml
# drupal/web/modules/custom/mandala_legacy_redirect/mandala_legacy_redirect.services.yml
services:
  mandala_legacy_redirect.host_subscriber:
    class: Drupal\mandala_legacy_redirect\EventSubscriber\LegacyHostRedirectSubscriber
    arguments:
      - '@entity_type.manager'
      - '@config.factory'
    tags:
      - { name: event_subscriber }
```

```php
// drupal/web/modules/custom/mandala_legacy_redirect/src/EventSubscriber/LegacyHostRedirectSubscriber.php
namespace Drupal\mandala_legacy_redirect\EventSubscriber;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LegacyHostRedirectSubscriber implements EventSubscriberInterface {

  // host -&gt; field_legacy_site token, from mandala_legacy_redirect.settings.
  protected array $hostMap;

  public static function getSubscribedEvents(): array {
    // Early, before routing resolves /node/{nid} against the D11 id space.
    return [KernelEvents::REQUEST =&gt; ['onRequest', 250]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event-&gt;getRequest();
    $host = $request-&gt;getHost(); // honors X-Forwarded-Host if trusted proxies configured
    if (!isset($this-&gt;hostMap[$host])) {
      return; // not a legacy host; let normal routing proceed
    }
    if (!preg_match('#^/node/(\d+)$#', $request-&gt;getPathInfo(), $m)) {
      return; // only the bare-nid form needs this; aliases resolve natively
    }
    $nids = $this-&gt;entityTypeManager-&gt;getStorage('node')-&gt;getQuery()
      -&gt;condition('field_legacy_site', $this-&gt;hostMap[$host])
      -&gt;condition('field_legacy_nid', (int) $m[1])
      -&gt;accessCheck(TRUE)
      -&gt;execute();
    if ($nids) {
      $node = $this-&gt;entityTypeManager-&gt;getStorage('node')-&gt;load(reset($nids));
      $event-&gt;setResponse(new RedirectResponse($node-&gt;toUrl()-&gt;toString(), 301));
    }
  }
}
```

**Trade-offs:** Everything stays in Drupal/CMI — no infra PR, no dependency on Yuji/Dave's ALB timeline. Correctness depends on `Host` (or `X-Forwarded-Host`) arriving intact through whatever sits in front of Drupal today — needs verifying against the current (unreconciled) ALB setup, since nothing confirms the legacy hostnames even reach the D11 container yet. Runs on every request to a legacy host, including non-redirect paths, so it needs a cheap early-exit (the regex check above) to avoid overhead on normal traffic.

---

**The crux for you and Yuji:** Option 1 needs an infra change (and touches the already-stalled ALB cleanup item, likely pulling Dave in); Option 2 is self-contained in this repo but is only as good as whatever host header actually survives to Drupal under today's edge setup — which isn't yet confirmed either way in the docs. That unknown (does `Host`/`X-Forwarded-Host` reach Drupal correctly for the legacy domains right now) might be worth a 10-minute live check before deciding, since it's a hard blocker for Option 2 specifically.</result>
<usage><subagent_tokens>112988</subagent_tokens><tool_uses>8</tool_uses><duration_ms>55265</duration_ms></usage>
</task-notification>

---

## Claude — 2026-10-12 14:29

Grounding confirmed. Here's both options, sketched concretely against this codebase's conventions (`event_subscriber` tag, not `kernel.event_subscriber`; `field_legacy_site` tokens are `images`, `texts`, `sources`, `audio-video`, `visuals`, `mandala`). One important finding up front: **the ALB/host-routing layer for the legacy domains is itself unreconciled** — `docs/planning/1b1-part4-d11-backend-deploy-scope.md` shows that work still postponed to Dave as of 2026-07-14. Both options are greenfield; this isn't a case where infra is already there for one and not the other.

## Option 1: Edge-tag rewrite

Infra rewrites the legacy host into an internal path prefix; Drupal only ever sees one host.

```
# Conceptual ALB listener rule — actual mechanism TBD with Dave
Listener rule (host: images.mandala.library.virginia.edu):
  rewrite path: /node/1631632 → /legacy/images/node/1631632
  forward to: D11 target group

Listener rule (host: av.mandala.library.virginia.edu):
  rewrite path: /node/1631632 → /legacy/audio-video/node/1631632
  forward to: D11 target group
```

```yaml
# mandala_legacy_redirect.routing.yml
mandala_legacy_redirect.legacy_node:
  path: '/legacy/{legacy_site}/node/{legacy_nid}'
  defaults:
    _controller: '\Drupal\mandala_legacy_redirect\Controller\LegacyNodeRedirectController::redirect'
  requirements:
    legacy_site: 'images|texts|sources|audio-video|visuals|mandala'
    legacy_nid: '\d+'
```

```php
public function redirect(string $legacy_site, int $legacy_nid): RedirectResponse {
  $nids = $this->entityTypeManager->getStorage('node')->getQuery()
    ->condition('field_legacy_site', $legacy_site)
    ->condition('field_legacy_nid', $legacy_nid)
    ->accessCheck(TRUE)
    ->execute();
  if (!$nids) {
    throw new NotFoundHttpException();
  }
  $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));
  return new RedirectResponse($node->toUrl()->toString(), 301);
}
```

Plain route + controller, no subscriber needed. Cost: an infra PR in `terraform-infrastructure`, one listener rule per legacy host, and it pulls Yuji/Dave's ALB timeline into the critical path — the same ALB cleanup item that's already been sitting postponed.

## Option 2: Host-aware request subscriber

No edge change. Drupal reads the `Host` header itself and intercepts before routing resolves.

```yaml
# mandala_legacy_redirect.services.yml
services:
  mandala_legacy_redirect.host_subscriber:
    class: Drupal\mandala_legacy_redirect\EventSubscriber\LegacyHostRedirectSubscriber
    arguments: ['@entity_type.manager', '@config.factory']
    tags:
      - { name: event_subscriber }
```

```php
class LegacyHostRedirectSubscriber implements EventSubscriberInterface {
  protected array $hostMap; // host -> field_legacy_site token, from module config

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 250]]; // before routing
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    $host = $request->getHost(); // honors X-Forwarded-Host if trusted proxies configured
    if (!isset($this->hostMap[$host])) {
      return; // not a legacy host
    }
    if (!preg_match('#^/node/(\d+)$#', $request->getPathInfo(), $m)) {
      return; // only the bare-nid form needs this; aliases already resolve natively
    }
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('field_legacy_site', $this->hostMap[$host])
      ->condition('field_legacy_nid', (int) $m[1])
      ->accessCheck(TRUE)
      ->execute();
    if ($nids) {
      $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));
      $event->setResponse(new RedirectResponse($node->toUrl()->toString(), 301));
    }
  }
}
```

Self-contained in this repo, no infra PR, no Dave dependency. But it's only as good as whatever `Host`/`X-Forwarded-Host` actually survives to the D11 container under today's edge setup — and that isn't confirmed either way for the legacy domains yet, per the same unreconciled-ALB doc.

## The actual crux

- **Option 1** costs an infra change and couples this decision to the stalled ALB cleanup item.
- **Option 2** stays entirely in Drupal/CMI, but has a hard precondition that hasn't been verified: does the legacy hostname even reach Drupal intact right now? That's a ~10-minute live check (hit `av.mandala…` or a DDEV equivalent and log `$request->getHost()`) worth doing before committing to Option 2, since if the host gets stripped or rewritten upstream already, the subscriber can't see it and you're back to Option 1 by necessity.

Want me to pull up the actual current ALB config (if accessible) to settle that precondition before you two decide?

---

## User — 2026-10-12 14:29

go ahead, show us the sketches

---

## Claude — 2026-10-12 14:30

Here's the side-by-side sketch for you both: **https://claude.ai/artifact/JgyA9CjHNMhWzXqgWxtmRT**

It lays out Option 1 (edge-tag rewrite) and Option 2 (host-aware subscriber) with the actual ALB rule shape, routing/services YAML, and controller/subscriber code for each, plus the trade-offs we discussed. The callout at the bottom flags the thing worth checking before you commit: whether `Host`/`X-Forwarded-Host` actually survives to the D11 container today — that's a hard precondition for Option 2 and nobody's verified it yet either way.

---

## User — 2026-10-12 14:35

Write up this issue and these options as unresolved and then assign it to Yuji so he can have all the relevant information locally.

---

## Claude — 2026-10-12 14:35

Now I'll write the deferred note documenting the decision as unresolved with both options, following the repo's naming/header convention.

---

## Claude — 2026-10-12 14:35

Now let's add it to the deferred README's Open items table and the `.pages` nav, then promote it to a GitHub issue assigned to Yuji.

---

## Claude — 2026-10-12 14:35

Now add a row to the README's Open items table.

---

## Claude — 2026-10-12 14:36

Now let's commit this doc, then promote it to a GitHub issue assigned to Yuji (`ys2n`).

---

## Claude — 2026-10-12 14:36

Now promote to a GitHub issue, assigned to Yuji (`ys2n`).

---

## Claude — 2026-10-12 14:36

Issue #316 created and assigned to Yuji. Committing the header update now.

---

## Claude — 2026-10-12 14:36

Done. Summary:

- **Note:** `docs/deferred/legacy-url-host-aware-redirect-mechanism.md` — writes up the two sketched options as unresolved, flags the already-settled discriminator (ADR 017), and lists what would close it (the live `Host` header check, a decision, and an ADR 016 doc correction).
- **Issue:** [#316](https://github.com/uvalib/mandala-navina/issues/316), labeled `deferred`, assigned to `ys2n` (Yuji).
- Indexed in `docs/deferred/README.md` and `docs/deferred/.pages`, both committed and pushed to `main`.

Yuji now has the full context locally whenever he picks it up — the note, not just the issue, carries the detail.

---

## User — 2026-10-12 14:38

What are the remaining two group decisions to make?

---

## Claude — 2026-10-12 14:38

These are the two items sitting in the deferred README's "Awaiting a team decision" table — both explicitly blocked on a group conversation, not implementation. Let me pull the actual open questions from each note.

---

## Claude — 2026-10-12 14:38

Good catch to check — one of these is actually stale in the README. Here's the real state:

## 1. Local dev file-binary parity — **genuinely still open**
([local-dev-files-provisioning-mechanism.md](docs/deferred/local-dev-files-provisioning-mechanism.md), issue #298)

The mechanism question (how files get synced) is settled: `stage_file_proxy`, adopted 2026-10-09, trialed live. What's left is **scope**: does local dev need *full* file-binary parity with dev-0, or only the fields real features actually render (e.g. the carousel's images)? Evidence suggests the real need is much narrower — only 0.5% of `shanti_image` nodes even carry a local `field_image` by design, since Images display is IIIF-driven, not local-file-driven. Scoping this down could make it far cheaper than the raw "8,400+ files missing" number suggests. This is the one to put on your agenda.

## 2. dev-0 NFD→NFC filename rename — **actually already resolved, README is stale**
([dev0-unicode-filenames-need-nfc-normalization.md](docs/deferred/dev0-unicode-filenames-need-nfc-normalization.md))

This was resolved and executed on 2026-10-09 (Than, with Xiaoming and Yuji) — `scripts/normalize-unicode-filenames-nfc.sh` ran against dev-0 live, 37 affected paths found and renamed, re-verified clean. It's even folded into production-migration-planning as a pre-cutover step. The note's own header says "resolved," but `docs/deferred/README.md`'s "Awaiting a team decision" table still lists it as open — that's a stale entry that should be moved down or removed.

Want me to clean up that stale README row now, since it's a quick accuracy fix?

---

## User — 2026-10-12 14:39

I mean the two decisions from above.

---

## Claude — 2026-10-12 14:39

Got it — the other two open items under ADR 016 itself (item 1, host-awareness, is now the deferred note/issue #316 we just wrote up). Here are the remaining two:

---

## Claude — 2026-10-12 14:39

## Remaining two ADR 016 decisions

**Item 2 — Which path is canonical in D11: the preserved D7 alias, or the new `/images/{nid}` form?**

Decision 7 only settles that legacy paths must *resolve* (both the slug alias and `/node/{nid}` work) — it doesn't say which one D11 *generates* when it renders a link, emits `url_html`, or sets the canonical URL for search engines. Both can legitimately coexist (Drupal serves a node at its alias and at `/node/{nid}` simultaneously), so there's no technical conflict — but leaving it unstated means different subsystems (the theme layer, the Solr doc-builder, `mandala-om`) will each pick their own default, and they'll disagree. The new `/images/{nid}` form (plural, id-based) departs from D7's actual shape (singular, slug-based) on both axes, so this is a fresh design choice, not something inherited from D7 — nothing says it has to win over keeping the alias canonical.

**Item 3 — `mandala-om` coordination on `url_html`**

Per ADR 013, client compatibility is an *active* requirement, not a courtesy — so this isn't optional cleanup. Spike 6 established `url_html` has two live consumers in the React app: forward links (`FeatureCard`, `TextsViewer`, `SourcesViewer`, legacy `searchui`) and a **reverse Solr lookup** — `MandalaMarkup.js` queries `q: url_html:"…"` to find an asset *by* its page URL. Whatever item 2 decides D11 emits as `url_html` is exactly what that reverse lookup has to match, so these two items are coupled: item 2 can't really be decided in isolation from item 3, and item 3 explicitly needs you (Than) in the room since it's your React/`mandala-om` territory.

Practically: item 2 is Yuji's architectural call (with input from you on what's cheap/hard to support client-side), and item 3 is you walking Yuji through the actual `MandalaMarkup.js` reverse-lookup code so the decision accounts for it. Want me to pull up that code now so you have it in front of you while you two settle item 2?

---

## User — 2026-10-12 14:40

For Item 2, the group decision is just to use the content-type + nid form, e.g. /images/{nid}.

---

## Claude — 2026-10-12 14:41

Updated ADR 016 with the decision. Let's commit and push.

---

## Claude — 2026-10-12 14:41

Diff looks right. Committing and pushing.

---

## Claude — 2026-10-12 14:41

Recorded and pushed. ADR 016 now shows:

- **Item 2 — decided**: `/images/{nid}` (and siblings `/texts/{nid}`, `/sources/{nid}`, `/audio/{nid}`, `/video/{nid}`) is canonical for generated links, `url_html`, and SEO canonicalization. The D7 alias still resolves per decision 7, but D11 no longer generates it.
- **Item 1** — updated to note the discriminator half is settled (ADR 017) and points to the deferred note/issue #316 for the still-open redirect mechanism.
- **Item 3** — now explicitly scoped: `MandalaMarkup.js`'s reverse lookup needs to match the `/images/{nid}`-style form going forward, not the D7 alias.

Item 3 is the one left genuinely open and still needs you to walk through the `MandalaMarkup.js` reverse-lookup code with Yuji — want to pull that up now?

---

## User — 2026-10-12 14:43

yes, pull that up

---

## Claude — 2026-10-12 14:43

Clean working tree, on `release/v1.2.0-rc`, last commit 2026-08-24 — a bit stale but fine for reading. Let's pull it current and read the file.

---

## Claude — 2026-10-12 14:43

Now reading the file.

---

## Claude — 2026-10-12 14:44

I pulled it up (`kmaps-app/src/views/common/MandalaMarkup.js` in `Standalone/mandala-om`, fast-forwarded to current). Here's what's actually in the code, mapped against item 2's decision:

## Two separate paths handle internal links, and they behave differently

**1. Direct-match path (line 146-160) — already compatible with today's decision**

```js
let patt = /(places|subjects|terms|audio-video|images|sources|texts|visuals)\/(\d+)/;
let mtch = linkurl.match(patt);
if (mtch) {
  let newurl = `/${mtch[1]}/${mtch[2]}`;
  ...
  return <Link key={newurl + ts} to={newurl}>{reactkids}</Link>;
}
```

Any href that already contains `{content-type}/{nid}` anywhere in the string gets rewritten directly, **no Solr involved at all**. This already expects exactly the plural, content-type + nid shape you just decided on (`images`, `audio-video`, etc.) — this code path needs no change for item 2.

Worth flagging to Yuji directly: this regex expecting the **plural** form is actually a live cross-check on the bug ADR 016 opened with — the currently-committed `mandala_kmassets_sync.settings.yml` template hardcodes the **singular** `/image/__NID__`. So the client was already written assuming the shape you just formally decided, and the Drupal-side config is the thing that's been wrong. Good confirmation, not a new problem.

**2. Reverse lookup — `MandalaPathDecoder` (line 460-514) — this is where item 3 actually lives**

```js
const qobj = {
  index: 'assets',
  params: {
    q: 'url_html:"' + asset_path + '"',
    fl: 'asset_type,id',
  },
};
const asset = useSolr(asset_path, qobj);
if (asset?.docs?.length > 0) {
  const { asset_type, id } = asset.docs[0];
  const newpath = process.env.PUBLIC_URL + '/' + asset_type + '/' + id;
  return <a href={newpath} ...>{props.contents}</a>;
}
```

This fires for any absolute Mandala link that **doesn't** already match the clean `{type}/{nid}` pattern — i.e. a D7-era alias or bare `/node/{nid}` still embedded in old migrated body HTML. It does an **exact-string Solr match** on `url_html`, then builds its output in the same `/${asset_type}/{id}` canonical shape as the direct path.

The output side is already fine — both paths converge on the same shape you decided. **The open question is the input side**: `url_html` has to be indexed with whatever literal path string actually appears inside old embedded `<a href>`s for this exact-match query to hit. That's a Solr doc-builder/indexing decision, not a mandala-om code change — and it's unresolved whether that's the D7 alias string, the bare `/node/{d7nid}` form, or something else, since old content wasn't re-authored during migration.

One more loose end: line 162's `// TODO: Develop way to look up a path alias and get node ID to make aliased paths internal` — suggests even the original author didn't consider this fully solved.

**What this means for item 3's decision**: it's narrower than "coordinate with mandala-om" in the abstract — it's specifically "what does the kmassets `url_html` field get indexed as for migrated content, so `MandalaPathDecoder`'s exact-match query still resolves legacy in-body links." Want me to check what's actually in the migrated body HTML (sample a few `shanti_image` nodes) to see what link forms this lookup needs to match in practice?

---

## User — 2026-10-12 14:50

For this issue #2 the reverse lookup, that was just a way of auto correcting old URLs in the description of the node in Drupal that then got indexed and ended up in the React display. This is not something we need to continue to do, but the URLs should be fixed in the Drupal records. So we can drop this item and add a TODO to search for old url syntax in the Drupal content once the migration is fully done.

---

## Claude — 2026-10-12 14:50

Got it — that reframes item 3 significantly. Let me write that up: drop the reverse-lookup as an ongoing requirement, record the decision to fix embedded URLs at the source instead, and create the follow-up audit TODO.

---

## Claude — 2026-10-12 14:50

Now let's create the follow-up deferred note for the content audit TODO.

---

## Claude — 2026-10-12 14:50

Now update ADR 016's item 3 to reflect the resolution, and add the new note to the README/`.pages` index.

---

## Claude — 2026-10-12 14:51

Now add the new deferred note to the README's open items and `.pages`.

---

## Claude — 2026-10-12 14:51

Now let's commit and push all of this.

---

## Claude — 2026-10-12 14:51

Done. Status now:

- **ADR 016 item 2** — decided (content-type + nid canonical form), and confirmed it already matches `mandala-om`'s existing code with no client change needed.
- **ADR 016 item 3** — resolved: the `url_html` reverse-lookup shim is dropped as a going-forward mechanism; old embedded D7 URLs get fixed directly in Drupal content instead. Follow-up tracked as `docs/deferred/legacy-url-syntax-audit-drupal-content.md` — doc-only for now (not promoted to an issue), triggered once migration is fully complete.
- **ADR 016 item 1** — the only one still genuinely open, tracked as issue #316, assigned to Yuji.

ADR 016 stays "Proposed" until item 1 closes — two of three open items are now settled.

---

## User — 2026-10-12 14:52

Are there other group decisions that we need to look at. You mentioned some above?

---

## Claude — 2026-10-12 14:52

There are two separate buckets worth distinguishing:

## The formal "Awaiting a team decision" table — one item left open
- **Local dev file-binary parity scope** (issue #298) — still open: full parity vs. only the fields real features render. This is the one we already covered.
- The NFC filename rename was actually already resolved 2026-10-09 — that README entry is stale (I flagged this earlier; still haven't cleaned it up if you want me to).

## A decision that's recommended but never formally recorded — worth surfacing now

**[`d7-alias-preservation-scope-beyond-shanti-image.md`](docs/deferred/d7-alias-preservation-scope-beyond-shanti-image.md)** — directly related to the ADR 016 work you're doing today, but sitting outside that formal table. Three unresolved items:

1. **Satellite aliases (174,430 rows — half of D7's entire alias table).** `image_agent` and `image_descriptions` node aliases have no D11 destination at all — those entities became paragraphs, which have no canonical route. The note's own conclusion is "likely drop these," but explicitly flags that this should be a **recorded** decision, not something that happens by default/omission. Nobody has actually signed off on dropping them yet.
2. **`file/*` aliases (64,933 rows) — unassessed, with a real collision risk.** These share the same `image/{slug}` namespace as the 111,304 node aliases already migrated (e.g. `file/1` → `image/morven-landscape-ecology-1937`). Whatever gets decided here can't collide with that namespace — hasn't been looked at yet.
3. **The long tail** — `user`, `page`, `asset_link`, `external_classification*`: 80 rows total, cheap to decide, flagged as "easy to forget."

This matters now specifically because it's the same ADR 016 family you're already in, and it repeats per-site as Texts/Sources/AV migrate — so like item 1 of ADR 016, it's a "decide before the next collection migrates" problem, not a someday item.

Want me to add this to today's agenda alongside item 1, or is the satellite-alias drop (#1 above) simple enough that you and Yuji want to just decide it right now the way you did with ADR 016 items 2 and 3?

---

## User — 2026-10-12 14:53

Above I asked what were the remaining two decisions and you came up with something completely different. What was that?

---

## Claude — 2026-10-12 14:53

That first time, before you clarified you meant the ADR 016 items, I'd answered from a different source entirely: the deferred README's **"Awaiting a team decision"** table (the one explicitly marked "blocked on a group conversation, not implementation"). It had two entries:

1. **Local dev file-binary parity scope** ([`local-dev-files-provisioning-mechanism.md`](docs/deferred/local-dev-files-provisioning-mechanism.md), issue #298) — whether local dev needs full file-binary parity with dev-0, or only the fields real features actually render. Still genuinely open.
2. **dev-0 NFD→NFC filename rename** ([`dev0-unicode-filenames-need-nfc-normalization.md`](docs/deferred/dev0-unicode-filenames-need-nfc-normalization.md)) — but I flagged that this one was actually *already resolved* on 2026-10-09 (executed live, 37 files renamed, verified clean); the README table just hadn't been updated to reflect that, so it was a stale listing rather than a real open decision.

You then said "I mean the two decisions from above," which I took as referring to ADR 016's open items 2 and 3 instead (since item 1 had just been written up as its own deferred note/issue) — that's the thread we've been working through since.

So to be clear about where things stand across both threads:
- **ADR 016 items 2 and 3**: now decided (canonical path form; dropped the `url_html` reverse-lookup mechanism).
- **ADR 016 item 1**: still open, issue #316, assigned to Yuji.
- **Local dev file parity scope**: still open, unresolved, issue #298.
- **dev-0 NFC rename**: resolved; README entry is stale and could use a cleanup.

Want me to fix that stale README table now, or move on to something else?

---

## User — 2026-10-12 14:54

Can you explain 'Local dev file-binary parity scope' fully and teh pros and cons of each?

---

## Claude — 2026-10-12 14:55

## Full context

The gap: nothing in this project's local-dev tooling ever bulk-populates `sites/default/files`. `git pull` + `config:import` sync code/config; `update-db-from-remote.sh` syncs the database. Neither touches a single file binary. So a `file_managed` DB row can exist correctly on every machine while the actual bytes only exist wherever someone happened to fetch them by hand.

Confirmed independently on two machines: Yuji's DDEV had 126/210 `group.field_featured_image` references missing; Xiaoming's, checked for the first time, had **8,417 of 8,433 managed files (99.8%) missing sitewide**. That sounds catastrophic, but a DB-only breakdown showed it resolves almost entirely to just three fields, each near-100% missing:

| Field | Missing | Where it's used |
|---|---|---|
| `node.field_transcript` | 5,379/5,379 (100%) | AV transcript files |
| `node.field_thumbnail_image` | 2,839/2,843 (99.9%) | Video/audio node thumbnails |
| `group.field_featured_image` | 206/206 (100%) | Collection/subcollection hero images (the carousel) |

dev-0 was confirmed as a complete, durable source for all of these (0 missing, full sitewide audit clean). The **mechanism** question is already settled: `stage_file_proxy`, adopted 2026-10-09, fetches lazily from dev-0 on request and writes the file in place — no sync script, already wired into every session via `session-start-check.sh`.

**What's still open is scope** — and because the mechanism is lazy/on-demand, the two options aren't really "different plumbing," they're different answers to *how proactively* you guarantee a developer never hits a missing file.

## Option A: Full parity — proactively fetch everything

Run `drush mandala:missing-file-audit --fix` against the complete backlog (all ~8,400 files, and keep doing so as the corpus grows) so every DDEV mirrors dev-0's file tree exactly, upfront.

**Pros**
- No developer is ever surprised mid-task by a missing file, regardless of which feature they touch — including corners nobody's actively working on.
- Matches the precedent already set for DB/config (`update-db-from-remote.sh`, ADR 012's "DDEV runs the same DB engine as production" philosophy) — one consistent "local mirrors dev-0" story instead of a special case for files.
- An audit can report "0 missing" as a clean baseline, the same way `config:status` does — easy to reason about.
- Avoids `stage_file_proxy`'s lazy-fetch latency on first page load for large or first-opened assets.

**Cons**
- Most of it is unnecessary: per 1a.7's own finding, only ~0.5% of `shanti_image` nodes even carry a local `field_image` by design — Images display is IIIF-driven, not local-file-driven. Bulk-fetching binaries for content nobody's local-file-dependent on buys nothing.
- 5,379 transcript files only matter to whoever's actually working on AV transcript/Spike 11 features — most developers would carry that weight for zero benefit.
- Cost scales with the corpus. 8,400 files is the *Images-only* baseline; once Texts, Sources, and AV are fully migrated this grows substantially, and "sync everything" gets proportionally more expensive and slower on every `ddev start`/session refresh.
- Disk and bandwidth cost on every developer's machine for files they may never open.

## Option B: Narrower scope — only what local features actually render

Only guarantee parity for the fields real local features render (e.g. `group.field_featured_image` for the carousel), and let `stage_file_proxy`'s existing lazy fetch cover everything else on-demand, as needed.

**Pros**
- Matches actual functional need rather than the raw "99.8% missing" number, which the breakdown shows is misleading — it's 3 fields, not "everything."
- Much cheaper and scales better as Texts/Sources/AV land — you're not re-running a bulk fetch against an ever-growing corpus.
- `stage_file_proxy` already does this transparently with no additional engineering — the "narrow" option is close to just formalizing what's already happening by default.
- Avoids bloating every developer's local disk with content (like 5,379 transcripts) they'll likely never look at.

**Cons**
- Inconsistent experience across developers: whoever first stumbles into an untested corner (someone picks up AV transcript work without realizing `field_transcript` isn't pre-populated) hits `stage_file_proxy`'s on-demand fetch cold, which is slower than an already-local file and, if dev-0 itself ever has a gap for a newer field, fails silently as an ordinary 404 rather than being caught by a proactive audit.
- Harder to answer "is my local environment representative of production" with confidence without actually running the audit command — there's no standing "0 missing" guarantee the way Option A gives you.
- The "narrow" set needs active maintenance: every new file-referencing feature (the note's own words — "gets worse as more file-referencing features like the carousel get built") requires someone to remember to add its field to the guaranteed-parity list. Option A needs no such list.

**The crux**: Option A buys predictability and a clean audit baseline at a real and growing cost; Option B keeps costs flat as the corpus grows but trades that for occasional first-touch latency and a maintenance burden to keep the "guaranteed" field list current. Given `stage_file_proxy` is already live either way, this decision mostly comes down to whether you also want a proactive bulk `--fix` pass as a belt-and-suspenders baseline, or are comfortable relying purely on lazy fetch going forward.

---

## User — 2026-10-12 14:55

Didn't we decide to use Stage File Proxy for this?

---

## Claude — 2026-10-12 14:55

Yes — that's the mechanism question (question 3 in the note), and it's already decided: `stage_file_proxy`, adopted 2026-10-09 by you, Yuji, and Xiaoming, trialed live against dev-0, and it's the thing already wired into `session-start-check.sh` step 3a-pre on every session. No on-demand sync script was built; that path was explicitly rejected in favor of `stage_file_proxy`.

What's *not* decided is a separate, narrower question (question 1): **scope** — does local dev need `stage_file_proxy` (or anything else) to guarantee parity for *every* file field, or only the ones real local features actually render?

Why this is still open even with the mechanism settled: `stage_file_proxy` fetches lazily, per-request, whatever a page happens to ask for. It doesn't by itself answer "should we also proactively run `--fix` against the full ~8,400-file backlog so nothing is ever missing on first touch" vs. "just let it fetch on demand and never pre-populate anything." That's the Option A vs. Option B tradeoff I laid out — it's about whether to supplement the already-decided lazy mechanism with a proactive bulk pass, not about which mechanism to use.

So: mechanism = decided (`stage_file_proxy`). Scope = still open. Want to settle that one now too, since you're on a roll?

---

## User — 2026-10-12 14:59

How about a modified Option A where we could run it when we want to sync a specific range of files such as transcripts etc. but not where it would automatically sync everything unless we asked it to.

---

## Claude — 2026-10-12 14:59

Confirmed: that scoping doesn't exist yet. `MissingFileAuditCommands.php` has `--check-remote-source` and `--fix`, but no field/range filter — `audit()` always discovers every real file/image field generically (`realFileFields()`) and runs `--fix` against the whole set. Your proposal needs a small addition, not a new mechanism.

## Option C: scoped, on-demand bulk pre-fetch

Add a filter (e.g. `--field=field_transcript` or `--bundle=audio,video`) to the existing `drush mandala:missing-file-audit --fix` command, so a developer can pull a specific field/range in bulk *when they're about to work on it* — "I'm starting on AV transcripts, pre-fetch all 5,379 `field_transcript` files now" — rather than either (A) bulk-fetching the full ~8,400-file corpus for everyone, or (B) relying purely on `stage_file_proxy`'s one-file-at-a-time lazy fetch.

**Pros**
- Matches actual intent directly: the developer who's about to need a whole field's worth of files says so explicitly, instead of either over-fetching (A) or discovering gaps one `stage_file_proxy` request at a time (B).
- Cheap to build — it's a filter clause on a query the command already runs (`realFileFields()` → `findMissingFiles()`), not new fetch/restore logic. The `--check-remote-source`/dev-0-first logic, byte-verification, and in-place restore are already built and validated.
- Avoids Option A's core waste (every developer carrying 5,379 transcript files they'll never open) without Option B's downside of cold, one-at-a-time latency when someone *does* need a whole corpus at once — e.g. working through many transcripts in a session, where `stage_file_proxy`'s per-request fetch would mean a slow first load on every single file.
- Composes with `stage_file_proxy` rather than replacing it: `stage_file_proxy` stays the safety net for anything not explicitly pre-fetched; this just gives an escape hatch for "I know I need a whole category right now."
- No standing "guaranteed fields" list to maintain (Option B's maintenance burden) — scope is decided per-developer, per-session, by whoever actually needs it.

**Cons**
- It's new work, however small — someone has to add the flag, decide its exact shape (by field name? by content type/bundle, since `field_thumbnail_image` spans both `audio` and `video`? by a named preset like `--profile=transcripts`?), and verify it against `realFileFields()`'s existing field-discovery logic.
- No audit-wide "0 missing" guarantee the way Option A gives — an untouched field can still 404 silently through `stage_file_proxy` if nobody remembers to pre-fetch it before relying on it being there.
- Relies on developer memory/discipline ("did I remember to pre-fetch before starting this task?") rather than being automatic — closer to Option B's trust-based model than Option A's blanket guarantee.
- Doesn't change the underlying truth that the corpus keeps growing as Texts/Sources/AV land — it just lets each developer opt into the slice they need instead of paying for all of it.

This reads like a genuinely good middle ground — cheap to build, avoids both of the extremes' real downsides, and reuses code that's already proven. The main thing left to pin down with Yuji is the filter's exact interface (field name vs. bundle vs. named presets). Want me to sketch the actual Drush option addition so you have something concrete to review together?

---

## User — 2026-10-12 15:00

yes, sketch it out

---

## Claude — 2026-10-12 15:00

Here's the sketch — a minimal `--field` filter on the existing command, reusing all of its fetch/verify/restore logic untouched.

---

## Claude — 2026-10-12 15:01

Here it is: **https://claude.ai/artifact/89QaDMczbvyKaCZSj3qDWK**

The whole change is one new `--field` option (comma-separated field names, optionally `entity_type.field_name` to disambiguate) plus a ~15-line `filterFields()` helper that narrows `realFileFields()`'s discovery before the existing pipeline runs. Everything downstream — `findMissingFiles()`, `findUsages()`, the dev-0-first `findRemoteSource()`, the byte-verified `restoreFile()` — is reused exactly as-is, since they already operate on whatever `$fields` resolves to.

Resulting usage:
- `drush mandala:missing-file-audit` — unchanged, full report, no filter.
- `--field=field_transcript --fix` — pre-fetch all 5,379 transcript files before starting AV work.
- `--field=node.field_thumbnail_image,group.field_featured_image --fix` — pre-fetch exactly what a given feature renders.
- `--fix` with no `--field` — still available as the full Option A bulk path, if ever genuinely wanted.

Want me to actually implement this in a branch, or leave it as the sketch for Yuji to review/build when #298's scope question comes up for real?

---

## User — 2026-10-12 15:05

Yes, we want Option C. Go ahead and implement this in a branch.

---

## Claude — 2026-10-12 15:05

Now let's check for existing tests for this command before editing.

---

## Claude — 2026-10-12 15:06

Now implementing the change in `MissingFileAuditCommands.php`.

---

## Claude — 2026-10-12 15:06

Let's also update the class docblock to note this extension, then run a PHP lint check.

---

## Claude — 2026-10-12 15:06

Now let's lint the PHP syntax and check DDEV availability for a live test.

---

## Claude — 2026-10-12 15:06

DDEV is running. Let's test live.

---

## Claude — 2026-10-12 15:08

Good catch to verify with a live run — I found a real bug before shipping: `findMissingFiles()` scans the entire `file_managed` table unconditionally, so `--field` only affected the usage-label text, not which files actually get checked/fixed. A `--field=field_transcript --fix` would silently restore *every* missing file site-wide, not just transcripts. Let me fix this properly.

---

## Claude — 2026-10-12 15:08

Now add `referencedFids()` and update `findMissingFiles()`'s signature to accept the optional filter.

---

## Claude — 2026-10-12 15:08

Now let's lint and test live with a real gap — removing a sample transcript file and confirming scoped detection and restore.

---

## Claude — 2026-10-12 15:09

Found it. Removing it and confirming scoped detection + restore.

---

## Claude — 2026-10-12 15:09

Correctly scoped away — `field_featured_image`'s 206 files are all present, and it correctly excludes the missing transcript. Now confirm `field_transcript` scope *does* catch it, and `--fix` restores it.

---

## Claude — 2026-10-12 15:09

First, restore the orphaned test file, then remove a genuinely-referenced transcript.

---

## Claude — 2026-10-12 15:09

Restored. Now the real scoping test: remove a genuinely field-referenced transcript (t987.xml) and an unrelated featured-image file, then confirm `--field` correctly isolates and `--fix` only restores what's scoped.

---

## Claude — 2026-10-12 15:10

Good. Now remove both test files (t987.xml for transcripts, the jpg for featured images) and verify scoping end-to-end.

---

## Claude — 2026-10-12 15:10

Correctly caught only the jpg (fid 161), not the missing transcript. Now confirm `field_transcript` scope catches only the transcript.

---

## Claude — 2026-10-12 15:10

Both scoped correctly, isolated from each other. Now confirm `--fix` with a field filter restores only the scoped file and leaves the other missing.

---

## Claude — 2026-10-12 15:10

Exactly as intended — `--fix` restored only the scoped transcript, left the jpg untouched. Now let's restore the jpg to leave the environment clean, and re-run the baseline unknown-field warning test.

---

## Claude — 2026-10-12 15:10

All verified end-to-end and the environment is back to clean (8447/8447 present). Now let's update the deferred note recording this decision, and also handle the dev-0 NFC documentation request.

---

## Claude — 2026-10-12 15:10

Now let me make the edits: resolve question 1, add the scoped `--field` section, and fix the stale "Not done" dev-0 NFC references.

---

## Claude — 2026-10-12 15:11

Let's also update the title/priority header since all four open questions are now resolved.

---

## Claude — 2026-10-12 15:11

Now let's update the deferred README to move this from "Awaiting a team decision" to resolved, and also fully clean up the dev-0 NFC entry per your request.

---

## Claude — 2026-10-12 15:11

Now add both as resolved rows in the Open items table, near the top where related entries already sit.

---

## Claude — 2026-10-12 15:12

Now let's close issue #298 since the decision it tracked is fully resolved.

---

## Claude — 2026-10-12 15:12

Now let's review everything, commit, and open a PR.

---

## Claude — 2026-10-12 15:13

Implementation done: **PR #317**, branch `feat/298-scoped-file-audit-fix`.

Summary of everything from this round:

- **Option C implemented**: `drush mandala:missing-file-audit --field=field_transcript --fix` now pre-fetches a specific field's files on demand, scoped correctly — both the report and `--fix` respect the filter (caught and fixed a real bug where the first draft only scoped the labels, not what `--fix` actually touched).
- **Verified live in DDEV** against the real corpus: unknown field warns cleanly, two simultaneously-missing files in different fields are correctly isolated, scoped `--fix` only restores what's in scope, an orphaned row is correctly excluded, unscoped behavior unchanged. Environment left clean afterward.
- **Issue #298 closed** with a comment summarizing all four resolved questions.
- **`local-dev-files-provisioning-mechanism.md`** updated: title changed to "resolved," priority dropped to Low, question 1 marked answered, and a full write-up of the `--field` decision and verification added.
- **dev-0 NFC rename fully reflected as decided**, per your earlier request: moved out of the README's "Awaiting a team decision" table (which is now empty) and into Open items with a RESOLVED status line, matching the note's own "Resolved — 2026-10-09" content.

PR #317 is open for Yuji to review before merging.

---

## User — 2026-10-12 15:13

Yuji is here and aware of what he needs to do. Let's close out the session for today.

---

## Claude — 2026-10-12 15:13

Running the session log save script, then I'll update memory.

---
