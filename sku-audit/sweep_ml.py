import subprocess,json,sys
from concurrent.futures import ThreadPoolExecutor
S=".claude/skills/stock-sync/scripts/read_masterlist.py"
tabs=[l.strip() for l in subprocess.run(["python3",S,"--list"],capture_output=True,text=True,timeout=120).stdout.splitlines()[1:] if l.strip()]
KEYS=["TRACTION","TANK GRIP","TANKGRIP","EZRG","TANK PAD","GRIPS","NINJA 250","NINJA 400","Z400"]
def scan(t):
    try:
        o=subprocess.run(["python3",S,t,"--format","json"],capture_output=True,text=True,timeout=240)
        rows=json.loads(o.stdout)["rows"]
    except Exception as e:
        return (t,None,[f"ERROR {e}"])
    hits=[]
    for r in rows:
        b=" | ".join(c for c in r["cells"] if c).upper()
        for k in KEYS:
            if k in b:
                hits.append(f"    row {r['row']:<6} hidden={r['hidden']}  [{k}]  {b[:120]}")
                break
    return (t,len(rows),hits)
res=list(ThreadPoolExecutor(max_workers=4).map(scan,tabs))
print(f"tabs scanned: {len(res)}")
tot=0
for t,n,hits in res:
    if hits:
        print(f"\n{t}  ({n} rows)")
        for h in hits[:6]: print(h)
        tot+=len(hits)
print(f"\nTOTAL HITS across workbook: {tot}")
