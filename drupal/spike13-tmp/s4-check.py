import sys, re
from html.parser import HTMLParser
html_path, tsv_path, mode = sys.argv[1], sys.argv[2], sys.argv[3]  # mode: d7 (compare to D7 published) | none
class P(HTMLParser):
    def __init__(s):
        super().__init__(); s.stack=[]; s.toc=[]; s.sec_ids=[]; s.names=[]; s.in_toc=0; s.root_href=None; s.li_stack=[]; s.sections=0
    def handle_starttag(s,t,a):
        a=dict(a); s.stack.append((t,a))
        if t=='div' and a.get('id')=='shanti-texts-toc': s.in_toc=1
        if t=='div' and a.get('class')=='shanti-texts-section': s.sections+=1
        if t=='div' and (a.get('id') or '').startswith('shanti-texts-') and not a.get('id').startswith('shanti-texts-toc'): s.sec_ids.append(a['id'][13:])
        if t=='a' and 'name' in a and a['name'].startswith('shanti-texts-'): s.names.append(a['name'][13:])
        if s.in_toc:
            if t=='div' and a.get('class')=='shanti-texts-toc-root': s.rootdiv=1
            if t=='li': s.li_stack.append(None)
            if t=='a' and a.get('href','').startswith('#shanti-texts-'):
                nid=a['href'][14:]
                if getattr(s,'rootdiv',0) and not s.li_stack: s.root_href=nid
                else:
                    assert a.get('id')=='shanti-texts-toc-'+nid, ('toc id mismatch',nid,a.get('id'))
                    parent = next((x for x in reversed(s.li_stack[:-1]) if x), None)
                    s.toc.append((nid,parent)); s.li_stack[-1]=nid
    def handle_endtag(s,t):
        if s.in_toc and t=='li': s.li_stack.pop()
        if t=='div' and s.in_toc and not s.li_stack and s.stack and s.stack[-1][1].get('id')=='shanti-texts-toc': s.in_toc=0
        if s.stack: s.stack.pop()
p=P(); p.feed(open(html_path,encoding='utf-8').read())
ok=True
def chk(c,msg):
    global ok; print(('  PASS ' if c else '  FAIL ')+msg); ok = ok and c
n_toc=len(p.toc); toc_ids=[x[0] for x in p.toc]
chk(len(set(toc_ids))==n_toc, f"TOC link ids unique ({n_toc})")
chk(len(set(p.sec_ids))==len(p.sec_ids), f"section anchor ids unique ({len(p.sec_ids)})")
chk(set(p.names)==set(p.sec_ids), "every section has both <a name> and div id")
missing=[i for i in toc_ids if i not in set(p.sec_ids)]
chk(not missing, f"every TOC href resolves to a section anchor (unresolved: {missing[:5]})")
if p.root_href: chk(p.root_href in set(p.sec_ids), "root link resolves")
chk(n_toc==len(p.sec_ids)-(1 if p.root_href else 0), f"TOC links ({n_toc}) == visible non-root sections ({len(p.sec_ids)-(1 if p.root_href else 0)})")
if mode=='d7':
    rows=[l.rstrip('\n').split('\t') for l in open(tsv_path,encoding='utf-8',newline='\n')]
    rows=[dict(mlid=r[0],nid=r[1],plid=r[2],depth=int(r[3]),weight=int(r[4]),status=int(r[5])) for r in rows]
    mlid2nid={r['mlid']:r['nid'] for r in rows}
    res=[r for r in rows if r['status']==1 and r['depth']>1]       # D7 view: published, depth>1
    inres={r['nid'] for r in res}
    for r in res:
        pn=mlid2nid.get(r['plid']); r['parent']= pn if pn in inres else None   # views_tree: parent not in result -> top level
    kids={}
    allkids={}
    for r in rows: allkids.setdefault(r['plid'],[]).append(r)   # SQL order: plid, weight, title, mlid
    order=[]
    def dfs(plid):
        for r in allkids.get(plid,[]):
            order.append(r['nid']); dfs(r['mlid'])
    root=[r for r in rows if r['depth']==1][0]; dfs(root['mlid'])
    d7_toc=[(n, next(r['parent'] for r in res if r['nid']==n)) for n in order if n in inres]
    chk(set(toc_ids)==inres, f"TOC page set equals D7 published depth>1 set ({len(inres)}); only-D11={sorted(set(toc_ids)-inres)[:3]} only-D7={sorted(inres-set(toc_ids))[:3]}")
    chk({x[0]:x[1] for x in p.toc}=={n:pa for n,pa in d7_toc}, "TOC nesting equals D7 (views_tree parent rule)")
    d7o=[x[0] for x in d7_toc]
    diff=[i for i,(a,b) in enumerate(zip(toc_ids,d7o)) if a!=b]
    chk(toc_ids==d7o, f"TOC order equals D7 sort-within-level order (first diff at position {diff[:1]})")
    promoted=sum(1 for n,pa in d7_toc if pa is None and next(r for r in rows if r['nid']==n)['depth']>2)
    print(f"  info: {promoted} promoted-to-top entries (published page whose parent is not in the list)")
print("RESULT", "PASS" if ok else "FAIL")
