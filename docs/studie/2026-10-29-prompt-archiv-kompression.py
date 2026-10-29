# Aufruf: python3 -I 2026-10-29-prompt-archiv-kompression.py <DB-KOPIE.sqlite> <ausgabe.json>
# Liest die Kopie der Datenbank schreibgeschuetzt (mode=ro), nie die echte Datenbank.
# Misst Huffman (Order 0, Entropiegrenze), gzip (Zeile / Projekt), bz2, xz. Ergebnis: 2026-10-29-prompt-archiv-kompression.json
import sqlite3,sys,zlib,bz2,lzma,json,math,heapq,base64,statistics,time
from collections import Counter
def gz(b,l):
    c=zlib.compressobj(l,zlib.DEFLATED,31); return len(c.compress(b)+c.flush())
def ent_bytes(b):
    n=len(b)
    if n==0: return 0
    cnt=Counter(b); H=-sum(v*math.log2(v/n) for v in cnt.values()); return math.ceil(H/8)
def huff_bytes(b):
    n=len(b)
    if n==0: return 0
    cnt=Counter(b)
    if len(cnt)==1: return math.ceil(n/8)
    h=[(v,i,None) for i,v in enumerate(cnt.values())]; heapq.heapify(h); tot=0; k=len(h)
    # sum of internal node weights = total code bits
    while len(h)>1:
        a=heapq.heappop(h); c=heapq.heappop(h); s=a[0]+c[0]; tot+=s; heapq.heappush(h,(s,k,None)); k+=1
    return math.ceil(tot/8)
c=sqlite3.connect('file:'+sys.argv[1]+'?mode=ro',uri=True)
q="""select coalesce(j.project_id,-1),l.id,l.prompt from prompt_logs l join job_logs j on j.id=l.job_log_id
where l.prompt is not null and l.prompt!='' and l.prompt not like '[prompt removed after%' order by 1,l.id"""
res={}; lens=[]; cur=None; docs={}
row_tot=Counter()
def flush(pid,docs):
    js=json.dumps(docs,ensure_ascii=False).encode()
    r={'n':len(docs),'raw_json':len(js),'raw_text':sum(len(v.encode()) for v in docs.values())}
    for l in (6,9):
        g=gz(js,l); r[f'gz{l}']=g; r[f'gz{l}_b64']=math.ceil(g/3)*4
    r['bz2']=len(bz2.compress(js,9)); r['xz']=len(lzma.compress(js,preset=6)); r['xz9e']=len(lzma.compress(js,preset=9|lzma.PRESET_EXTREME))
    res[pid]=r; print(pid,r,time.strftime('%X'),file=sys.stderr,flush=True)
for pid,i,p in c.execute(q):
    if pid!=cur:
        if cur is not None: flush(cur,docs)
        cur=pid; docs={}
    b=p.encode(); lens.append(len(b)); docs[str(i)]=p
    row_tot['n']+=1; row_tot['raw']+=len(b); row_tot['ent']+=ent_bytes(b); row_tot['huff']+=huff_bytes(b)
    row_tot['gz6']+=gz(b,6); row_tot['gz9']+=gz(b,9)
    if pid==-1: row_tot['n_np']+=1; row_tot['raw_np']+=len(b)
flush(cur,docs)
lens.sort()
out={'rows':dict(row_tot),'len':{'mean':statistics.mean(lens),'median':statistics.median(lens),'min':lens[0],'max':lens[-1],'p10':lens[len(lens)//10],'p90':lens[len(lens)*9//10],'share_le_32k':sum(1 for x in lens if x<=32768)/len(lens)},'projects':res}
json.dump(out,open(sys.argv[2],'w'))
