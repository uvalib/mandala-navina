# solr-proxy: uid=1 (admin) gets the ANONYMOUS filter, not "no filter" — docs say otherwise

**Area:** solr-proxy / ADR 014 / visibility / documentation-vs-behaviour
**Raised during:** Session 2026-08-11 (running the proxy locally to validate the pipeline specs)
**Jira:** (add when available)
**Priority:** ~~Medium~~ — **RESOLVED 2026-08-12**

## What we found

Measured by running the built image against a real Redis and a stub Solr
(2026-08-11): a request from uid=1 has this `fq` injected —

```
(visibility_i:1 OR asset_type:(places subjects terms))
```

— i.e. **the anonymous filter**. Admin sees public content and KMaps taxonomy only,
not "everything".

The cause is a fall-through in `Searcher::setVisibility()`:

```php
if ($this->isLoggedIn && !empty($this->uid) && $this->uid !== 1) {
    $fq = $this->getVisibilityToken();
    if ($fq !== null) { ...; return; }
}
// falls through for uid=1:
$this->params['fq'][] = "(visibility_i:1%20OR%20asset_type:(places%20subjects%20terms))";
```

The `!== 1` guard skips the *token lookup* for admin, but nothing then skips the
restrictive default beneath it.

## NOT a D11 regression — D7 does exactly the same

Checked against the D7 original (`mandala-legacy/mandala-solr-proxy`,
`proxy/Searcher.php`): identical `if (... uid !== 1) { conditions } else { anonymous }`
shape, so uid=1 has always landed in the `else`. The D11 fork reproduced the
behaviour faithfully. **No user-visible change, nothing to roll back.**

## Why it still matters: four places assert the opposite

Every piece of D11 documentation says admin sees everything —

1. `Searcher.php` inline: *"if uid = 1, view everything (no filter) -- matches prior behaviour"*
2. `Searcher::getVisibilityToken()` docblock: *"uid=1 ... meaning no visibility filter is applied"*
3. `solr-proxy/README.md`: *"the proxy applies no visibility filter for uid 1"*
4. `VisibilityTokenBuilder::build()` returns NULL for uid=1, its docblock citing
   *"the D7 proxy's 'view everything' behaviour (Searcher.php explicitly special-cases uid 1)"*

Point 4 is the load-bearing one: Drupal **deliberately writes no Redis token for
uid=1** *because* it believes the proxy applies no filter. Both halves are built on a
premise that is false.

**The concrete risk:** during 1b.1 part 4 validation someone logs in as admin, searches
for private-collection content, sees nothing, and concludes the ADR 014 visibility
path is broken — when it is working correctly for every other user. That is an
expensive false negative to chase.

## ✅ RESOLVED 2026-08-12 — Option B, generalised: Drupal decides

Decision (Yuji): **defer to Drupal to determine uid 1's access.** Both special cases
are removed, so the proxy makes no access decision at all — ADR 013/014's premise
applied consistently.

- `Searcher::getVisibilityToken()` / `setVisibility()` — the `uid === 1` /
  `uid !== 1` short-circuits are gone. Every logged-in user is treated identically:
  read the token, apply it; no token means fail closed to the public filter.
- `VisibilityTokenBuilder::build()` — no longer returns NULL for uid 1. It returns a
  permissive `(*:*)` token for any account holding one of the **three** bypass
  permissions that `_mandala_group_inheritance_node_access()` honours:
  `bypass group access`, `bypass node access`, `bypass mandala group access`.

  **Corrected same day.** The first version checked only core's `bypass node access`.
  That was wrong for ADR 015's `content_editor`, which holds *only*
  `bypass mandala group access` — so an editor could open private content in Drupal
  while search silently hid it. Surfaced when the ADR 015 config was finally imported
  to dev-0. Verified against the real roles there: `content_editor` and
  `administrator` see all; `authenticated` and `anonymous` do not.

**Why a permissive token rather than "no token":** the proxy treats a missing token as
fail-closed. Overloading absence to mean "unrestricted" would make the privileged and
the broken cases indistinguishable — precisely the bug being fixed.

**Verified by running it** (proxy + Redis + stub Solr), all four cases:

| Case | fq applied |
|---|---|
| uid 1, permissive token | `(*:*)` |
| uid 1, **no** token | public filter — **fails closed** |
| ordinary user, membership token | unchanged |
| anonymous | unchanged |

**Consequences worth knowing:**
- This grants full search visibility to the `administrator` role too, not just uid 1 —
  intended, and the point of keying on the permission.
- It is a behaviour change vs. D7, where admin saw public content only.
- Tokens are written on login, so an administrator with a pre-existing session must log
  in again to get one. Until then they fail closed to public — safe, not broken.

## Original options (kept for the record)

- **A — Fix the code to match the docs.** Add an explicit `return` for uid=1 so no
  `fq` is applied. Genuinely changes behaviour: admin would start seeing private
  content through the proxy. Arguably right (admin sees all in Drupal), but it is a
  *behaviour change vs. production D7*, so it belongs to a decision, not a cleanup.
- **B — Fix the docs to match the code** (all four sites above), and have
  `VisibilityTokenBuilder` write a real token for uid=1 like any other user, so admin
  gets access via the normal mechanism rather than a special case.
- **C — Leave both, document the trap.** Cheapest; keeps the mismatch as a live
  tripwire for the next reader.

B is the smallest change that makes the system self-consistent without diverging
from D7's effective behaviour for anonymous/normal users.

## Cross-references

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md)
- `solr-proxy/proxy/Searcher.php` (`setVisibility`, `getVisibilityToken`)
- `drupal/web/modules/custom/mandala_solr_visibility/src/VisibilityTokenBuilder.php`
- [solr-proxy-has-no-cicd-pipeline.md](solr-proxy-has-no-cicd-pipeline.md) — found while validating those specs
