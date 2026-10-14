#!/usr/bin/env python3
"""Load (or remove) SYNTHETIC test data for the mandala-av-transcripts core on dev Solr.

Spike 11 / #327. Gives scripts/verify-solr-proxy-visibility.py --transcripts something to
check: four transcripts, two units each, whose kmassets "parent" documents sit in each
visibility class the proxy's join filter has to tell apart.

    trid     kmassets parent                                   anonymous  test user
    990001   visibility_i=1 (public)                           sees       sees
    990002   visibility_i=2, in the test user's collection     hidden     sees
    990003   visibility_i=2, in someone else's collection      hidden     hidden
    990004   NONE (no kmassets doc carries this trid_i)        hidden     hidden  (fails closed)

All text is synthetic. Everything is tagged so --cleanup removes exactly what this script
wrote: transcript units have ids `tcu-test-*`; kmassets parents have ids `test-transcript-*`.

WRITES TO A SHARED INDEX. Default is a dry run that prints what would be sent. Pass --apply
to write. The kmassets parents are real documents in the dev kmassets core until cleaned
up, so:
  * run --cleanup when finished (the reserved trid range 990000-990999 is never real data);
  * a kmassets auditor run with --fix could delete or flag unknown documents; do not leave
    them in place across one;
  * --link-kmassets is off by default. Without it only transcript units are written, which
    is enough if parents with matching trid_i already exist.

Writes go to the MASTER (the replica is read-only and IP-gated writes are master-only).
--replica is optional and only used to time master-to-replica lag.

    ./scripts/load-transcript-test-units.py --master http://<dev-master>:8080 \\
        --mine-collection collection-11-NNN --other-collection collection-11-MMM \\
        --link-kmassets --apply
    ./scripts/load-transcript-test-units.py --master ... --cleanup --apply

Find the collection uids with `verify-solr-proxy-visibility.py --discover`.

EXIT CODES  0 ok   1 a write or lag probe failed   3 setup (bad arguments, core unreachable)
"""

import argparse
import json
import sys
import time
import urllib.error
import urllib.request

TRID_MIN, TRID_MAX = 990000, 990999

p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
p.add_argument("--master", required=True, help="dev Solr master base URL, e.g. http://host:8080")
p.add_argument("--replica", help="dev Solr replica base URL; if given, report replication lag")
p.add_argument("--transcripts-core", default="mandala-av-transcripts")
p.add_argument("--kmassets-core", default="kmassets")
p.add_argument("--mine-collection", help="collection_uid_s of a collection the test user belongs to")
p.add_argument("--other-collection", help="collection_uid_s of a collection the test user does NOT belong to")
p.add_argument("--link-kmassets", action="store_true", help="also write the kmassets parent documents")
p.add_argument("--cleanup", action="store_true", help="delete everything this script wrote")
p.add_argument("--apply", action="store_true", help="actually write (default: dry run)")
ARGS = p.parse_args()


def post(core, body):
    url = f"{ARGS.master}/solr/{core}/update?commit=true&wt=json"
    if not ARGS.apply:
        print(f"  [dry run] POST {url}\n    {json.dumps(body)[:300]}{'...' if len(json.dumps(body)) > 300 else ''}")
        return
    r = urllib.request.Request(url, json.dumps(body).encode(), {"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(r, timeout=60) as resp:
            status = json.load(resp)["responseHeader"]["status"]
    except urllib.error.URLError as e:
        raise SystemExit(f"SETUP/WRITE failed for core {core}: {e}")
    if status != 0:
        raise SystemExit(f"write to {core} returned status {status}")
    print(f"  wrote to {core}")


def numfound(base, core, q):
    url = f"{base}/solr/{core}/select?rows=0&wt=json&q={urllib.request.quote(q, safe='')}"
    with urllib.request.urlopen(url, timeout=30) as resp:
        return json.load(resp)["response"]["numFound"]


def units():
    docs = []
    for trid in (990001, 990002, 990003, 990004):
        for n in (1, 2):
            docs.append({
                "id": f"tcu-test-{trid}-{n}",
                "entity_id": trid * 10 + n,
                "nid": trid,
                "is_trid": trid,
                "fts_start": n * 10.5,
                "fts_end": n * 10.5 + 4.25,
                "fts_duration": 4.25,
                "ss_language": "English",
                "ts_content_eng": f"synthetic transcript unit {n} of {trid}, chanting",
            })
    return docs


def parents():
    return [
        {"id": "test-transcript-1", "uid": "audio-video-11-test-1", "asset_type": "audio-video",
         "visibility_i": 1, "trid_i": 990001},
        {"id": "test-transcript-2", "uid": "audio-video-11-test-2", "asset_type": "audio-video",
         "visibility_i": 2, "collection_uid_s": ARGS.mine_collection, "trid_i": 990002},
        {"id": "test-transcript-3", "uid": "audio-video-11-test-3", "asset_type": "audio-video",
         "visibility_i": 2, "collection_uid_s": ARGS.other_collection, "trid_i": 990003},
    ]


def measure_lag():
    """Write a throwaway unit to the master, time how long until the replica serves it."""
    probe_id = f"tcu-test-lagprobe-{int(time.time())}"
    probe = {"id": probe_id, "is_trid": 990999, "nid": 990999, "entity_id": 9909990}
    t0 = time.time()
    post(ARGS.transcripts_core, [probe])
    deadline = t0 + 120
    while time.time() < deadline:
        if numfound(ARGS.replica, ARGS.transcripts_core, f'id:"{probe_id}"') == 1:
            print(f"  replica lag for {ARGS.transcripts_core}: {time.time() - t0:.1f}s "
                  "(includes the master commit; replication poll interval dominates)")
            break
        time.sleep(1)
    else:
        print(f"  FAIL replica did not serve the probe within 120s "
              "(replica config/index not following? see the probe-write note in "
              "docs/dev-notes/howto-deploy-kmassets-schema-change.md)")
        post(ARGS.transcripts_core, {"delete": {"id": probe_id}})
        return False
    post(ARGS.transcripts_core, {"delete": {"id": probe_id}})
    return True


if ARGS.cleanup:
    print("== cleanup ==")
    post(ARGS.transcripts_core, {"delete": {"query": "id:tcu-test-*"}})
    post(ARGS.kmassets_core, {"delete": {"query": "id:test-transcript-*"}})
    sys.exit(0)

if ARGS.link_kmassets and not (ARGS.mine_collection and ARGS.other_collection):
    p.error("--link-kmassets needs --mine-collection and --other-collection")

if ARGS.apply:
    try:
        numfound(ARGS.master, ARGS.transcripts_core, "*:*")
    except (urllib.error.URLError, KeyError) as e:
        print(f"SETUP: core {ARGS.transcripts_core} not reachable on {ARGS.master}: {e}")
        sys.exit(3)
    if ARGS.link_kmassets:
        # Guard: the reserved range must be empty of real data before we write parents.
        clash = numfound(ARGS.master, ARGS.kmassets_core, f"trid_i:[{TRID_MIN} TO {TRID_MAX}] AND -id:test-transcript-*")
        if clash:
            print(f"SETUP: {clash} kmassets docs outside this script already use trid_i {TRID_MIN}-{TRID_MAX}; refusing")
            sys.exit(3)

print("== transcript units ==")
post(ARGS.transcripts_core, units())
if ARGS.link_kmassets:
    print("== kmassets parents ==")
    post(ARGS.kmassets_core, parents())

ok = True
if ARGS.replica and ARGS.apply:
    print("== replication lag ==")
    ok = measure_lag()

if ARGS.apply:
    print(f"\ntranscript units on master: {numfound(ARGS.master, ARGS.transcripts_core, 'id:tcu-test-*')} (expect 8)")
sys.exit(0 if ok else 1)
