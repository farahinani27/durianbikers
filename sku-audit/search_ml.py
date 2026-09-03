import subprocess,json,sys,re
S=".claude/skills/stock-sync/scripts/read_masterlist.py"
TABS=["SPAREPARTS","SHINKO","R&G","SCORPION","HELMETS","MT HELMET "]
TARGETS=[
 (19690,"Axxis Gecko Interior Padding",["GECKO"]),
 (19773,"Axxis Storm S Interior Padding",["STORM"]),
 (34781,"Bell Bullitt Interior (Brown)",["BULLITT"]),
 (23331,"Bell Bullitt Interior (Oxblood)",["BULLITT"]),
 (20030,"Bell Bullitt Interior Black",["BULLITT"]),
 (49645,"Bell Eliminator Cheekpads 35MM",["ELIMINATOR"]),
 (49615,"Bell Eliminator Cheekpads 25MM",["ELIMINATOR"]),
 (49605,"Bell Eliminator Cheekpads 30MM",["ELIMINATOR"]),
 (49440,"Bell Eliminator Interior",["ELIMINATOR"]),
 (49360,"Bell MX-9 Adventure Interior",["MX-9","MX9"]),
 (49318,"Bell Pit Boss Neck Curtain",["PIT BOSS","PITBOSS"]),
 (20006,"Bell Qualifier Cheekpads",["QUALIFIER"]),
 (49223,"Bell Qualifier Interior (Grey)",["QUALIFIER"]),
 (49191,"Bell Revolver Evo Interior",["REVOLVER"]),
 (49181,"Bell Rogue Interior (Grey)",["ROGUE"]),
 (18519,"Shinko R010/F010 190/50ZR17",["R010","F010"]),
 (19667,"NHK S1GP Pro Cheekpads",["S1GP","S1 GP"]),
 (21263,"R&G Tank Traction Grips Ninja",["TRACTION"]),
 (18793,"Shinko SR567 110/70-16",["SR567"]),
 (22003,"Scorpion ADX-2 Galane",["ADX-2","ADX2","GALANE"]),
 (18692,"Shinko E270 Zig Zag",["E270","ZIG"]),
]
data={}
for t in TABS:
    try:
        out=subprocess.run(["python3",S,t,"--format","json"],capture_output=True,text=True,timeout=180)
        data[t]=json.loads(out.stdout)["rows"]
        print(f"  loaded {t}: {len(data[t])} rows",file=sys.stderr)
    except Exception as e:
        print(f"  FAILED {t}: {e}",file=sys.stderr)
res={}
for pid,name,keys in TARGETS:
    hits=[]
    for t,rows in data.items():
        for r in rows:
            blob=" | ".join(c for c in r["cells"] if c).upper()
            if any(k.upper() in blob for k in keys):
                hits.append((t,r["row"],r["hidden"],blob[:150]))
    res[pid]=(name,hits)
json.dump({str(k):{"name":v[0],"hits":[list(h) for h in v[1]]} for k,v in res.items()},
          open("sku-audit/masterlist-hits.json","w"),indent=1)
print("\n=== MATCHES ===")
for pid,(name,hits) in res.items():
    vis=[h for h in hits if not h[2]]
    print(f"\n{pid}  {name}   -> {len(hits)} hits ({len(vis)} visible)")
    for h in vis[:4]: print(f"    {h[0]:<12} row {h[1]:<6} {h[3][:110]}")
    if not vis and hits: print(f"    (all {len(hits)} hits are HIDDEN rows = discontinued)")
