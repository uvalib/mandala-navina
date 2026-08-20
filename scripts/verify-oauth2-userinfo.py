#!/usr/bin/env python3
"""Regression test for ADR 014's OAuth2-authenticated path.

Replays the whole chain a real solr-proxy request takes -- SAML login at the
IdP, OAuth2 authorization_code exchange, then a Bearer-authenticated call to
/oauth/userinfo -- and reports PASS/FAIL on the last hop.

WHY THIS EXISTS
    Four separate defects have blocked this chain, each one only reachable once
    the previous was fixed, and each one invisible to any test that stopped
    short of the final Bearer request:

      1. OAuth2 signing keys not persisted across deploy      (fixed 2026-08-19)
      2. solr-proxy sent no Bearer header to /oauth/userinfo   (fixed 2026-08-19)
      3. the `openid` scope granted zero permissions           (fixed 2026-08-19)
      4. simplesamlphp_auth force-logged-out Bearer requests   (fixed 2026-08-20)

    Defect 4 is the reason step 7 is the assertion: `simple_oauth` authenticated
    the token perfectly and then simplesamlphp_auth's checkAuthStatus() threw
    the session away and redirected to '/'. Steps 1-6 all passed throughout.
    A green step 6 means nothing. Only step 7 is the test.

    See docs/deferred/simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md
    and docs/dev-notes/howto-verify-oauth2-authenticated-path.md.

WHERE IT RUNS
    From inside the target environment's network -- normally the app node
    itself (dev-0 etc.), via scripts/verify-oauth2-userinfo.sh, which reads the
    client credentials straight out of the running solr-proxy container so no
    secret is ever typed, echoed, or passed on a command line.

WHAT IT PRINTS
    Name and email claims from /oauth/userinfo are REDACTED by default: the
    test identity is authmapped to a real user account, so the endpoint
    correctly returns that person's details, and this output routinely gets
    pasted into a public repo. Pass --show-claims if you actually need them.

EXIT CODES
    0  PASS          -- /oauth/userinfo returned JSON
    1  FAIL          -- still redirecting (the defect-4 signature) or an error
    2  INCONCLUSIVE  -- neither a redirect nor JSON; read the dump
    3  SETUP         -- could not get far enough to test (bad creds, no IdP)
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

REDACT_CLAIMS = ("name", "preferred_username", "email", "given_name",
                 "family_name", "nickname", "picture", "profile")


def parse_args():
    p = argparse.ArgumentParser(
        description="Verify the OAuth2-authenticated Solr proxy path end to end.")
    p.add_argument("--base-url", default=os.environ.get(
        "MANDALA_BASE_URL", "https://mandala-dev.internal.lib.virginia.edu"),
        help="Drupal base URL (default: dev-0, or $MANDALA_BASE_URL)")
    p.add_argument("--user", default=os.environ.get("MANDALA_TEST_USER", "staff"),
                   help="test-IdP username (default: staff)")
    p.add_argument("--password", default=os.environ.get("MANDALA_TEST_PASSWORD", "staffpass"),
                   help="test-IdP password (default: staffpass)")
    p.add_argument("--client-id", default=os.environ.get("SOLRPROXY_CLIENT_ID"),
                   help="OAuth2 client id (default: $SOLRPROXY_CLIENT_ID)")
    p.add_argument("--client-secret", default=os.environ.get("SOLRPROXY_CLIENT_SECRET"),
                   help="OAuth2 client secret (default: $SOLRPROXY_CLIENT_SECRET)")
    p.add_argument("--redirect-uri", default=os.environ.get("SOLRPROXY_REDIRECT_URI"),
                   help="registered redirect URI (default: $SOLRPROXY_REDIRECT_URI)")
    p.add_argument("--scope", default="openid", help="scope to request (default: openid)")
    p.add_argument("--show-claims", action="store_true",
                   help="print name/email claims verbatim instead of redacting them")
    p.add_argument("--insecure", action="store_true", default=True,
                   help="skip TLS verification (default on; internal hostnames use internal CAs)")
    args = p.parse_args()
    missing = [n for n in ("client_id", "client_secret", "redirect_uri")
               if not getattr(args, n)]
    if missing:
        p.error("missing " + ", ".join("--" + m.replace("_", "-") for m in missing)
                + " -- run via scripts/verify-oauth2-userinfo.sh, which reads them "
                  "from the solr-proxy container")
    return args


ARGS = parse_args()
BASE = ARGS.base_url.rstrip("/")

ctx = ssl.create_default_context()
if ARGS.insecure:
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
jar = CookieJar()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


follow = urllib.request.build_opener(
    urllib.request.HTTPSHandler(context=ctx), urllib.request.HTTPCookieProcessor(jar))
stop = urllib.request.build_opener(
    urllib.request.HTTPSHandler(context=ctx), urllib.request.HTTPCookieProcessor(jar), NoRedirect())


def request(url, data=None, opener=follow, headers=None):
    req = urllib.request.Request(
        url, data=urllib.parse.urlencode(data).encode() if data else None,
        headers=headers or {})
    try:
        r = opener.open(req, timeout=30)
        return r.getcode(), r.read().decode("utf-8", "replace"), dict(r.headers), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers), url


def form_fields(body):
    return {html.unescape(n): html.unescape(v) for n, v in
            re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', body, re.I)}


def form_action(body, default):
    m = re.search(r'<form[^>]*action="([^"]+)"', body, re.I)
    return html.unescape(m.group(1)) if m else default


def redact(payload):
    if ARGS.show_claims:
        return payload
    return {k: ("<redacted>" if k in REDACT_CLAIMS else v) for k, v in payload.items()}


def die(code, msg, detail=""):
    print(f"\n{msg}")
    if detail:
        print(detail[:800])
    sys.exit(code)


print(f"target: {BASE}   client: {ARGS.client_id}   scope: {ARGS.scope}")

print("\n== 1. GET /saml_login (follows through to the IdP login form) ==")
code, body, _, url = request(f"{BASE}/saml_login")
print(f"   {code}  {url.split('?')[0]}")
if "password" not in body.lower():
    die(3, "SETUP: expected an IdP login form.", body)

print("== 2. POST IdP credentials ==")
fields = form_fields(body)
fields.update({"username": ARGS.user, "password": ARGS.password})
code, body, _, url = request(urllib.parse.urljoin(url, form_action(body, url)), fields)
print(f"   {code}")

hops = 0
while "SAMLResponse" in body and hops < 4:
    hops += 1
    fields = form_fields(body)
    action = urllib.parse.urljoin(url, form_action(body, url))
    print(f"== 3.{hops} auto-POST SAMLResponse -> {action.split('?')[0]} ==")
    code, body, _, url = request(action, fields)
    print(f"   {code}  {url}")

print("== 4. confirm the Drupal session is authenticated ==")
code, body, _, url = request(f"{BASE}/user")
uid = re.search(r"/user/(\d+)", url)
print(f"   {code}  uid={uid.group(1) if uid else 'UNKNOWN'}")
if not uid:
    die(3, "SETUP: SAML login did not establish an authenticated Drupal session.", body)

print("== 5. GET /oauth/authorize ==")
q = urllib.parse.urlencode({
    "response_type": "code", "client_id": ARGS.client_id, "scope": ARGS.scope,
    "redirect_uri": ARGS.redirect_uri, "state": "verify-oauth2-userinfo"})
code, body, headers, url = request(f"{BASE}/oauth/authorize?{q}", opener=stop)
loc = headers.get("Location", "")
print(f"   {code}  -> {loc.split('?')[0] if loc else '(no redirect)'}")
if "code=" not in loc and "form" in body.lower():
    print("   (grant-confirmation form returned; submitting)")
    code, body, headers, url = request(
        urllib.parse.urljoin(url, form_action(body, url)), form_fields(body), opener=stop)
    loc = headers.get("Location", "")
    print(f"   {code}  -> {loc.split('?')[0] if loc else '(no redirect)'}")
if "code=" not in loc:
    die(3, "SETUP: no authorization code returned.", body)
auth_code = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["code"][0]
print(f"   authorization code acquired ({len(auth_code)} chars)")

print("== 6. POST /oauth/token ==")
code, body, _, _ = request(f"{BASE}/oauth/token", {
    "grant_type": "authorization_code", "code": auth_code,
    "client_id": ARGS.client_id, "client_secret": ARGS.client_secret,
    "redirect_uri": ARGS.redirect_uri})
print(f"   {code}")
if code != 200:
    die(3, "SETUP: token exchange failed.", body)
token = json.loads(body)
access = token["access_token"]
print(f"   access_token acquired ({len(access)} chars), expires_in={token.get('expires_in')}")

print("== 7. GET /oauth/userinfo with Bearer   <-- THE ASSERTION ==")
code, body, headers, _ = request(
    f"{BASE}/oauth/userinfo", opener=stop, headers={"Authorization": f"Bearer {access}"})
print(f"   HTTP {code}")
print(f"   Location:      {headers.get('Location', '(none)')}")
print(f"   X-Consumer-ID: {headers.get('X-Consumer-ID', '(none)')}")
print(f"   Content-Type:  {headers.get('Content-Type', '(none)')}")

print()
if code == 200 and "application/json" in headers.get("Content-Type", ""):
    payload = json.loads(body)
    print(f"   claims: {json.dumps(redact(payload))}")
    if not ARGS.show_claims:
        print("   (name/email redacted; pass --show-claims to see them)")
    print(f"\nPASS -- /oauth/userinfo returned JSON, sub={payload.get('sub')}")
    sys.exit(0)

if code in (301, 302, 303, 307):
    print(f"FAIL -- redirecting to {headers.get('Location')}")
    print("        X-Consumer-ID above tells you which defect this is:")
    print("          present -> the token authenticated, then something discarded the")
    print("                     session. Defect 4's signature (checkAuthStatus). Check")
    print("                     watchdog for a 'Session closed' with no page nav.")
    print("          absent  -> the request never authenticated at all; look at the")
    print("                     Bearer header and the signing keys first.")
    sys.exit(1)

print(f"INCONCLUSIVE -- HTTP {code}, neither a redirect nor JSON")
print(body[:600])
sys.exit(2)
