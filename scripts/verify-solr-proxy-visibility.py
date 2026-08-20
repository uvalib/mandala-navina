#!/usr/bin/env python3
"""Regression test for ADR 014's visibility filtering.

Asks the question that matters: does an authenticated user see exactly the
private content they are entitled to, and nothing more?

WHY A COUNT-BASED SMOKE TEST IS NOT ENOUGH
    Searcher::setVisibility() FAILS CLOSED. If the Redis token is missing, or
    Redis is unreachable, a logged-in user silently falls back to the anonymous
    filter:

        (visibility_i:1 OR asset_type:(places subjects terms))

    That is the safe direction, but it means a broken deployment looks
    identical to a working one from the outside -- public results still come
    back, nothing errors. So this test pins a document the user SHOULD see and
    fails if it is missing, not merely a document they should not.

    It also checks the reverse: a private document belonging to someone else
    must stay invisible even when authenticated. A test that only asserted
    "logged in sees more" would pass on a proxy that had no filter at all.

HOW THE FIXTURES WERE CHOSEN
    No synthetic fixtures. The dev index already contains a discriminating set,
    found by faceting kmassets on visibility_i and intersecting with the test
    user's real Group memberships:

        visibility_i=1  276,924  public
        visibility_i=2    8,816  private          <- the interesting class
        visibility_i=3      452  semi-private
        (no value)      474,108  kmaps taxonomy

    The test user (uid 600) belongs to 4 collections, and 25 of the private
    documents sit inside them -- small and specific enough that a fail-closed
    regression makes them vanish.

    These ids are DATA, not code: if the dev index is rebuilt they may change.
    Rediscover with --discover, which prints the queries to run.

    TRAP: `id` is NOT unique in kmassets. Four separate documents share id
    "1821" -- the places/subjects/terms taxonomy shadows (ADR 006) plus an
    audio-video asset -- so an id alone does not address a document. The first
    draft of this test used it as the "not mine" fixture and reported a false
    visibility leak: the 4 documents anonymous could see were the public
    taxonomy shadows, not the private asset. Choose fixtures whose id resolves
    to exactly one document index-side, and verify that when refreshing them.

See docs/dev-notes/howto-verify-solr-proxy-visibility.md.

EXIT CODES
    0  PASS   1  FAIL (a real visibility defect)   3  SETUP (could not test)
"""

import argparse
import html
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

DEFAULTS = {
    "drupal": "https://mandala-dev.internal.lib.virginia.edu",
    "proxy": "https://mandala-index-dev.internal.lib.virginia.edu",
    "core": "/solr/kmassets",
    # Discovered 2026-08-20 against dev-0; see --discover to refresh.
    "public_id": "1631777",          # visibility_i=1, everyone sees
    "mine_id": "images-11-95599",    # visibility_i=2, inside a uid-600 collection
    "not_mine_id": "images-11-3",    # visibility_i=2, collection images-11-5 (NOT uid 600's)
}

p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
p.add_argument("--drupal", default=os.environ.get("MANDALA_BASE_URL", DEFAULTS["drupal"]))
p.add_argument("--proxy", default=os.environ.get("MANDALA_PROXY_URL", DEFAULTS["proxy"]))
p.add_argument("--core", default=DEFAULTS["core"])
p.add_argument("--user", default=os.environ.get("MANDALA_TEST_USER", "staff"))
p.add_argument("--password", default=os.environ.get("MANDALA_TEST_PASSWORD", "staffpass"))
p.add_argument("--public-id", default=DEFAULTS["public_id"])
p.add_argument("--mine-id", default=DEFAULTS["mine_id"])
p.add_argument("--not-mine-id", default=DEFAULTS["not_mine_id"])
p.add_argument("--discover", action="store_true",
               help="print the queries that regenerate the fixture set, then exit")
ARGS = p.parse_args()

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
jar = CookieJar()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


follow = urllib.request.build_opener(
    urllib.request.HTTPSHandler(context=ctx), urllib.request.HTTPCookieProcessor(jar))

passed = failed = 0


def req(url, data=None, opener=follow):
    r = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode() if data else None)
    try:
        resp = opener.open(r, timeout=40)
        return resp.getcode(), resp.read().decode("utf-8", "replace"), dict(resp.headers), resp.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers), url


def solr(query, sid=None, extra=""):
    url = f"{ARGS.proxy}{ARGS.core}/select?q={query}&rows=0&wt=json{extra}"
    if sid:
        url += f"&sid={sid}"
    code, body, _, _ = req(url)
    try:
        return json.loads(body)["response"]["numFound"]
    except Exception:
        raise RuntimeError(f"non-JSON reply (HTTP {code}) for {url}: {body[:200]}")


def check(label, expected, actual, note=""):
    global passed, failed
    if expected == actual:
        print(f"  PASS  {label}: {actual}{note}")
        passed += 1
    else:
        print(f"  FAIL  {label}: got {actual}, expected {expected}{note}")
        failed += 1


def saml_login():
    code, body, _, url = req(f"{ARGS.drupal}/saml_login")
    if "password" not in body.lower():
        raise RuntimeError("no IdP login form at /saml_login")
    def fields_of(b):
        return {html.unescape(n): html.unescape(v) for n, v in
                re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', b, re.I)}
    def action_of(b, default):
        m = re.search(r'<form[^>]*action="([^"]+)"', b, re.I)
        return html.unescape(m.group(1)) if m else default
    f = fields_of(body)
    f.update({"username": ARGS.user, "password": ARGS.password})
    code, body, _, url = req(urllib.parse.urljoin(url, action_of(body, url)), f)
    hops = 0
    while "SAMLResponse" in body and hops < 4:
        hops += 1
        code, body, _, url = req(urllib.parse.urljoin(url, action_of(body, url)), fields_of(body))
    m = re.search(r"/user/(\d+)", url)
    if not m:
        raise RuntimeError("SAML login did not reach an authenticated Drupal session")
    return m.group(1)


def proxy_login():
    returl = f"{ARGS.proxy}/ping"
    code, body, headers, url = req(
        f"{ARGS.proxy}/auth?returl={urllib.parse.quote(returl, safe='')}")
    q = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)
    if "sid" not in q:
        raise RuntimeError(f"proxy /auth returned no sid; landed on {url}")
    return q["sid"][0], q.get("uid", ["?"])[0]


if ARGS.discover:
    print("Regenerate the fixture set with these queries against the kmassets core:\n")
    print("  1. visibility distribution")
    print("     select?q=*:*&rows=0&facet=true&facet.field=visibility_i&facet.missing=true\n")
    print("  2. the test user's entitlements (on the Drupal node, via drush php:script):")
    print("     \\Drupal::service('mandala_solr_visibility.token_builder')->build($account)")
    print("     -> take the collection_uid_s list out of the token\n")
    print("  3. private docs INSIDE those collections  -> --mine-id")
    print("     select?q=visibility_i:2 AND collection_uid_s:(<uids>)&fl=id\n")
    print("  4. private docs OUTSIDE them              -> --not-mine-id")
    print("     select?q=visibility_i:2 AND -collection_uid_s:(<uids>)"
          " AND -members_uid_ss:user-<uid> AND -node_user_i:<uid>&fl=id\n")
    print("  5. any visibility_i:1 doc                 -> --public-id")
    sys.exit(0)

print(f"drupal: {ARGS.drupal}\nproxy:  {ARGS.proxy}{ARGS.core}")
print(f"fixtures: public={ARGS.public_id}  mine={ARGS.mine_id}  not-mine={ARGS.not_mine_id}\n")

try:
    print("== 1. anonymous (no sid, no cookie) ==")
    anon_total = solr("*:*")
    check("public doc visible", 1, solr(f"id:%22{ARGS.public_id}%22"))
    check("private doc in test user's collection HIDDEN", 0, solr(f"id:%22{ARGS.mine_id}%22"))
    check("private doc of another user HIDDEN", 0, solr(f"id:%22{ARGS.not_mine_id}%22"))
    check("no private docs at all", 0, solr("visibility_i:2"))
    print(f"  (anonymous *:* = {anon_total})")

    print("\n== 2. filter injection while anonymous ==")
    # setVisibility() DELETES any client fq containing "visibility" before
    # applying its own. So a successful block looks like "no effect at all",
    # i.e. exactly the un-injected count -- NOT zero. Expecting zero here was a
    # bug in the first draft of this test: it assumed the client filter would be
    # applied and then intersected, which is not what the proxy does.
    injected_anon = solr("*:*", extra="&fq=visibility_i:2")
    check("fq=visibility_i:2 stripped, no effect", anon_total, injected_anon,
          "  (client visibility filters are deleted, not honoured)")
    check("and still no private docs leak", 0, solr("visibility_i:2", extra="&fq=visibility_i:2"))

    print("\n== 3. authenticate ==")
    uid = saml_login()
    print(f"  drupal session established for uid {uid}")
    sid, puid = proxy_login()
    print(f"  proxy session: uid={puid} sid={sid[:12]}...")
    if puid != uid:
        raise RuntimeError(f"proxy uid {puid} != drupal uid {uid}")

    print("\n== 4. authenticated: entitlement ==")
    auth_total = solr("*:*", sid=sid)
    check("public doc still visible", 1, solr(f"id:%22{ARGS.public_id}%22", sid=sid))
    check("private doc in THEIR collection NOW VISIBLE", 1,
          solr(f"id:%22{ARGS.mine_id}%22", sid=sid),
          "  <- positive discriminator; 0 here means fail-closed")
    check("private doc of ANOTHER user STILL HIDDEN", 0,
          solr(f"id:%22{ARGS.not_mine_id}%22", sid=sid),
          "  <- negative discriminator")
    print(f"  (authenticated *:* = {auth_total}, anonymous was {anon_total})")
    if auth_total > anon_total:
        print(f"  PASS  authenticated sees more than anonymous (+{auth_total - anon_total})")
        passed += 1
    else:
        print(f"  FAIL  authenticated sees no more than anonymous "
              f"({auth_total} vs {anon_total}) -- token missing or fail-closed")
        failed += 1

    print("\n== 5. authenticated: still cannot widen its own filter ==")
    injected = solr("*:*", sid=sid, extra="&fq=visibility_i:2")
    if injected <= auth_total:
        print(f"  PASS  fq injection did not widen results ({injected} <= {auth_total})")
        passed += 1
    else:
        print(f"  FAIL  fq injection widened results ({injected} > {auth_total})")
        failed += 1

    print("\n== 6. forged sid must not grant access ==")
    check("bogus sid falls back to anonymous", 0,
          solr(f"id:%22{ARGS.mine_id}%22", sid="deadbeefdeadbeefdeadbeef00000000"))

except RuntimeError as e:
    print(f"\nSETUP: {e}")
    sys.exit(3)

print(f"\npassed={passed} failed={failed}")
sys.exit(0 if failed == 0 else 1)
