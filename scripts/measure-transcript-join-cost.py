#!/usr/bin/env python3
"""Measure the cross-core join cost for the transcript core at realistic scale.

Spike 11 / T9 go/no-go checkpoint (#290). The solr-proxy filters the transcript core by
joining to kmassets: {!join from=trid_i to=is_trid fromIndex=kmassets}<kmassets filter>.
Real transcript units do not exist on D11 until the T5 migration, so this loads SYNTHETIC
units with D7's real shape (one unit set per real trid, D7's units-per-transcript counts,
243,005 in all), times the join against the same queries without it, then removes them.

    # 1. counts file: trid<TAB>units, from the D7 dump:
    #    ddev mysql d7_av -N -e "SELECT t.trid, COUNT(*) FROM tcu c JOIN
    #      transcripts_apachesolr_transcript t ON t.trid=c.trid GROUP BY t.trid" > counts.tsv
    ./scripts/measure-transcript-join-cost.py load    --master http://<master>:8080 --counts counts.tsv --apply
    ./scripts/measure-transcript-join-cost.py measure --replica https://<replica> [--proxy https://<proxy>]
    ./scripts/measure-transcript-join-cost.py cleanup --master http://<master>:8080 --apply

WRITES TO A SHARED INDEX (the transcript core, replicated to the replica). Default is a dry
run. Every unit has id `tcu-scale-*`, so cleanup deletes exactly what load wrote. Only trids
that a real kmassets document already carries in trid_i are loaded, so the join matches the
way it will in production. No kmassets documents are written.

measure reports median / p95 / max milliseconds (Solr QTime and wall clock) per scenario. It
states no pass/fail: the threshold is the team's call.
"""

import argparse
import json
import random
import statistics
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
p.add_argument("mode", choices=["load", "measure", "cleanup"])
p.add_argument("--master")
p.add_argument("--replica", help="replica base URL (https://host or http://host:8080)")
p.add_argument("--proxy", help="proxy base URL; anonymous queries only")
p.add_argument("--counts", help="trid<TAB>units file")
p.add_argument("--core", default="mandala-av-transcripts")
p.add_argument("--kmassets", default="kmassets")
p.add_argument("--batch", type=int, default=5000)
p.add_argument("--runs", type=int, default=30, help="repetitions per scenario")
p.add_argument("--apply", action="store_true")
A = p.parse_args()

VOCAB = ("chanting teaching lineage monastery debate scripture ritual mountain lama "
         "retreat translation commentary offering prayer wisdom compassion").split()
random.seed(290)


def get(url, timeout=120):
    t0 = time.time()
    with urllib.request.urlopen(url, timeout=timeout) as r:
        body = json.load(r)
    return body, (time.time() - t0) * 1000


def post(base, body, commit=False):
    url = f"{base}/solr/{A.core}/update?wt=json" + ("&commit=true" if commit else "")
    if not A.apply:
        print(f"  [dry run] POST {url} ({len(json.dumps(body))} bytes)")
        return
    r = urllib.request.Request(url, json.dumps(body).encode(), {"Content-Type": "application/json"})
    with urllib.request.urlopen(r, timeout=600) as resp:
        if json.load(resp)["responseHeader"]["status"] != 0:
            raise SystemExit("write returned non-zero status")


def kmassets_trids(base):
    """Every trid_i carried by a real D11 AV kmassets document."""
    q = urllib.parse.quote("uid:audio-video-11-* AND trid_i:[* TO *]", safe="")
    body, _ = get(f"{base}/solr/{A.kmassets}/select?rows=10000&fl=trid_i&wt=json&q={q}")
    return {int(d["trid_i"]) for d in body["response"]["docs"]}


def do_load():
    if not (A.master and A.counts):
        p.error("load needs --master and --counts")
    counts = {}
    for line in open(A.counts):
        t, n = line.split()
        counts[int(t)] = int(n)
    have = kmassets_trids(A.master)
    use = {t: n for t, n in counts.items() if t in have}
    print(f"counts file: {len(counts)} trids; kmassets carries trid_i for {len(have)}; "
          f"loading {len(use)} trids, {sum(use.values())} units "
          f"({len(counts) - len(use)} trids have no kmassets parent and are skipped)")
    batch, sent = [], 0
    for trid, n in sorted(use.items()):
        for i in range(1, n + 1):
            start = i * 4.0
            batch.append({
                "id": f"tcu-scale-{trid}-{i}", "entity_id": trid * 10000 + i, "nid": trid,
                "is_trid": trid, "fts_start": start, "fts_end": start + 3.5, "fts_duration": 3.5,
                "ss_language": "English",
                "ts_content_eng": " ".join(random.choices(VOCAB, k=12)),
            })
            if len(batch) >= A.batch:
                post(A.master, batch)
                sent += len(batch)
                batch = []
                print(f"  sent {sent}", flush=True)
    if batch:
        post(A.master, batch)
        sent += len(batch)
    post(A.master, [], commit=True)
    print(f"done: {sent} units" + ("" if A.apply else " (dry run, nothing written)"))


def do_cleanup():
    if not A.master:
        p.error("cleanup needs --master")
    post(A.master, {"delete": {"query": "id:tcu-scale-*"}}, commit=True)
    print("deleted id:tcu-scale-*" + ("" if A.apply else " (dry run)"))


JOIN = "{!join from=trid_i to=is_trid fromIndex=%s}visibility_i:1"


def timed(base, params, runs):
    qt, wall, found = [], [], None
    for _ in range(runs):
        url = f"{base}/solr/{A.core}/select?wt=json&" + urllib.parse.urlencode(params)
        body, ms = get(url)
        qt.append(body["responseHeader"]["QTime"])
        wall.append(ms)
        found = body["response"]["numFound"]
    return qt, wall, found


def row(label, qt, wall, found):
    qs = sorted(qt)
    p95 = qs[min(len(qs) - 1, int(len(qs) * 0.95))]
    print(f"  {label:<46} found={found:<7} QTime med={statistics.median(qt):>6.0f} p95={p95:>5} "
          f"max={max(qt):>5} ms | wall med={statistics.median(wall):>6.0f} ms")


def do_measure():
    if not A.replica:
        p.error("measure needs --replica")
    trids = sorted(kmassets_trids(A.replica))
    sample = random.sample(trids, min(len(trids), A.runs))
    total, _ = get(f"{A.replica}/solr/{A.core}/select?rows=0&wt=json&q=id:tcu-scale-*")
    print(f"scale units on replica: {total['response']['numFound']}")
    jf = JOIN % A.kmassets
    scenarios = [
        ("A. whole-core text query", {"q": "ts_content_eng:chanting", "rows": 10}),
        ("B. whole-core match-all (count only)", {"q": "*:*", "rows": 0}),
    ]
    targets = [(A.replica, "replica direct")]
    if A.proxy:
        targets.append((A.proxy, "proxy anonymous (join applied by proxy)"))
    for base, name in targets:
        print(f"\n== {name}: {base}")
        for label, params in scenarios:
            if base == A.replica:
                row(label + "  [no join]", *timed(base, params, A.runs))
                row(label + "  [JOIN]", *timed(base, dict(params, fq=jf), A.runs))
            else:
                row(label, *timed(base, params, A.runs))
        # The React client's real pattern: all units of one transcript, up to 1000 rows.
        runs_wall, runs_qt, found = [], [], 0
        for t in sample:
            for fq in ([None, jf] if base == A.replica else [None]):
                pr = {"q": f"is_trid:{t}", "rows": 1000, "fl": "id,fts_start"}
                if fq:
                    pr["fq"] = fq
                body, ms = get(f"{base}/solr/{A.core}/select?wt=json&" + urllib.parse.urlencode(pr))
                (runs_qt if not fq else runs_wall).append((body["responseHeader"]["QTime"], ms))
        if base == A.replica:
            qn = [x[0] for x in runs_qt]; qj = [x[0] for x in runs_wall]
            print(f"  C. one transcript by is_trid, rows=1000 ({len(sample)} random trids)")
            print(f"      no join: QTime med={statistics.median(qn):.0f} max={max(qn)} ms")
            print(f"      JOIN:    QTime med={statistics.median(qj):.0f} max={max(qj)} ms")
        else:
            qn = [x[0] for x in runs_qt]
            print(f"  C. one transcript by is_trid, rows=1000 ({len(sample)} random trids): "
                  f"QTime med={statistics.median(qn):.0f} max={max(qn)} ms")
    print("\nNo verdict: the pass/fail threshold is the team's call.")


{"load": do_load, "measure": do_measure, "cleanup": do_cleanup}[A.mode]()
