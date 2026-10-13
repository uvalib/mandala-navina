#!/usr/bin/env python3
"""Read-only kmassets baseline, for verifying a schema change (see
docs/dev-notes/howto-deploy-kmassets-schema-change.md). Usage:
  kmassets-baseline.py take <label> <core-url> [ssh-target] > <label>.json
  kmassets-baseline.py diff a.json b.json
<core-url> is e.g. http://HOST:8080/solr/kmassets. With ssh-target, curl runs
on that host (needed for the VPC-only replicas). `take` also writes
<label>.trid.csv (the uid,trid_i list) to the current directory. The canary
queries below are the ones used for the trid_i change; swap in queries that
stand for real traffic when checking a different field."""
import hashlib, json, shlex, subprocess, sys, time, urllib.parse

VIS = '(visibility_i:1 OR asset_type:(places subjects terms))'
CANARIES = {
    'text_lookup_texts-37231': {'q': 'uid:texts-37231', 'fq': VIS},
    'text_lookup_texts-16164': {'q': 'uid:texts-16164', 'fq': VIS},
    'subject_uid_6403': {'q': 'uid:subjects-6403'},
    'subject_grouped_6403': {'q': 'kmapid:subjects-6403', 'group': 'true', 'group.field': 'asset_type',
                             'group.limit': '0', 'group.facet': 'true', 'group.ngroups': 'true',
                             'facets': 'on', 'fq': VIS},
    'place_grouped_13735': {'q': 'kmapid:places-13735', 'group': 'true', 'group.field': 'asset_type',
                            'group.limit': '0', 'group.facet': 'true', 'group.ngroups': 'true',
                            'facets': 'on', 'fq': VIS},
    'place_uid_13735': {'q': 'uid:places-13735'},
    'av_with_transcript_page': {'q': 'asset_type:audio-video', 'fq': ['trid_i:[* TO *]', VIS],
                                'fl': 'uid', 'sort': 'uid asc', 'rows': '100'},
    'av_stats_facets': {'q': 'asset_type:audio-video', 'fq': ['trid_i:[* TO *]', VIS], 'rows': '0',
                        'facet': 'true', 'facet.field': 'creator', 'facet.limit': '15',
                        'stats': 'true', 'stats.field': 'duration_i'},
    'all_facets': {'q': '*:*', 'rows': '0', 'facet': 'true', 'facet.field': ['asset_type', 'service']},
}


def run(base, ssh, path, params, raw=False):
    params = dict(params)
    params.setdefault('wt', 'json')
    url = base + path + '?' + urllib.parse.urlencode(params, doseq=True)
    cmd = ['curl', '-s', '-m', '90', url]
    if ssh:
        cmd = ['ssh', '-i', '~/.ssh/id_rsa'.replace('~', __import__('os').path.expanduser('~')),
               '-o', 'ConnectTimeout=10', '-o', 'BatchMode=yes', ssh,
               'curl -s -m 90 ' + shlex.quote(url)]
    out = subprocess.run(cmd, capture_output=True, text=True, check=True).stdout
    return out if raw else json.loads(out)


def sha(s):
    return hashlib.sha256(s.encode()).hexdigest()


def take(label, base, ssh):
    core = base.rsplit('/', 1)[0]
    r = {'label': label, 'taken_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'base': base}
    st = run(core, ssh, '/admin/cores', {'action': 'STATUS', 'core': 'kmassets'})['status']['kmassets']
    i = st['index']
    r['core'] = {'startTime': st['startTime'], 'numDocs': i['numDocs'], 'maxDoc': i['maxDoc'],
                 'deletedDocs': i['deletedDocs'], 'segmentCount': i['segmentCount'],
                 'indexVersion': i['version'], 'sizeInBytes': i['sizeInBytes']}
    rep = run(base, ssh, '/replication', {'command': 'details'})['details']
    r['replication'] = {'generation': rep.get('generation'), 'isMaster': rep.get('isMaster'),
                        'isSlave': rep.get('isSlave')}
    if rep.get('slave'):
        s = rep['slave']
        r['replication'].update({k: s.get(k) for k in ('timesFailed', 'timesIndexReplicated', 'indexReplicatedAt',
                                                          'confFilesReplicated', 'isPollingDisabled')})
    f = run(base, ssh, '/schema/fields/trid_i', {})
    r['schema_trid_i'] = f.get('field') or {'error': f.get('error', {}).get('msg')}
    csv = run(base, ssh, '/select', {'q': 'trid_i:[* TO *]', 'fl': 'uid,trid_i', 'sort': 'uid asc',
                                      'rows': '20000', 'wt': 'csv'}, raw=True)
    lines = csv.strip().split('\n')
    r['trid_pairs'] = {'count': len(lines) - 1, 'sha256': sha(csv)}
    r['canaries'] = {}
    for name, p in CANARIES.items():
        d = run(base, ssh, '/select', p)
        d.pop('responseHeader', None)
        resp = d.get('response') or {}
        gm = d.get('grouped', {}).get('asset_type', {})
        r['canaries'][name] = {'numFound': resp.get('numFound', gm.get('matches')),
                               'sha256': sha(json.dumps(d, sort_keys=True))}
    return r, csv


def diff(a, b):
    def flat(d, p=''):
        for k, v in d.items():
            if isinstance(v, dict):
                yield from flat(v, p + k + '.')
            else:
                yield p + k, v
    fa, fb = dict(flat(a)), dict(flat(b))
    skip = {'taken_at', 'label', 'base'}
    for k in sorted(set(fa) | set(fb)):
        if k in skip:
            continue
        if fa.get(k) != fb.get(k):
            print(f'DIFF {k}: {fa.get(k)!r} -> {fb.get(k)!r}')


if __name__ == '__main__':
    if sys.argv[1] == 'take':
        res, csv = take(sys.argv[2], sys.argv[3], sys.argv[4] if len(sys.argv) > 4 else None)
        open(f'{sys.argv[2]}.trid.csv', 'w').write(csv)
        print(json.dumps(res, indent=2, sort_keys=True))
    else:
        diff(json.load(open(sys.argv[2])), json.load(open(sys.argv[3])))
