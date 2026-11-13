# Texts: check the Voyant hand-off once Texts content is on dev-0 (post-migration)

**Category:** migration-texts
**Raised during:** Spike 13 S6, 2026-11-13 (S6 part (c) moved out of the spike)
**Priority:** **Low** — nice to have, not required to close Spike 13 or to start the Texts build.
**Owner:** unassigned
**Issue:** #408

## Context

D7 Texts has a "View in Voyant" link: `shanti_texts/voyant/[nid]` redirects to
`voyant-tools.org` with the book's raw-text URL as `input=`, and Voyant's servers fetch that URL.
[Spike 13](../spikes/spike-13-texts-whole-book.md) S6 proved the raw-text output itself (part (a),
word-for-word against D7), but never got Voyant to fetch it: local DDEV is not public, and a free
ngrok tunnel served its warning page to Voyant instead of the text (details in the spike's S6
section).

On 2026-11-13 Than decided not to keep chasing this with tunnels. It is much easier to test once the
Texts migration has put books and the raw-text route on dev-0, which is reachable from outside.

## Start trigger

Texts content and the production raw-text route are on dev-0.

## The check

1. Open `https://voyant-tools.org/?input=<dev-0>/<raw-text route>/<book nid>` for a large book (D7
   14679, about 203,000 words; resolve its D11 id by `field_legacy_site` + `field_legacy_nid`).
2. Pass: Voyant reports the book's word count, close to D7's.
3. If it fails, the likely cause is the WAF in front of dev-0 (and later staging and production)
   blocking Voyant's server-side fetch. That is the real open question; repeat the check on
   staging and production before launch.
4. Make sure PHP error display cannot leak into the raw-text response (locally DDEV printed
   deprecation notices there; Voyant would count them as words).
