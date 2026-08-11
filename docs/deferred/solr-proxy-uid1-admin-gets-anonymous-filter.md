# solr-proxy: uid=1 (admin) gets the ANONYMOUS filter, not "no filter" — docs say otherwise

**Area:** solr-proxy / ADR 014 / visibility / documentation-vs-behaviour
**Raised during:** Session 2026-08-11 (running the proxy locally to validate the pipeline specs)
**Jira:** (add when available)
**Priority:** Medium — not a regression and not a data-exposure bug, but a **trap for
the upcoming 1b.1 part 4 validation**, which is exactly when someone will log in as
admin and misread the result

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

## Options (decide, don't drift)

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
