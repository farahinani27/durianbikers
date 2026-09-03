import os,json,base64,urllib.request,csv,collections
from concurrent.futures import ThreadPoolExecutor
B=os.environ["WP_BASE_URL"];A="Basic "+base64.b64encode(f'{os.environ["WP_API_USER"]}:{os.environ["WP_APP_PASSWORD"]}'.encode()).decode()
F="id,name,sku,type,status,price,regular_price,purchasable,stock_status,stock_quantity,catalog_visibility,variations,permalink,date_created"
def g(u,tries=3):
    for i in range(tries):
        try:
            r=urllib.request.Request(u,headers={"Authorization":A})
            with urllib.request.urlopen(r,timeout=120) as f: return json.load(f)
        except Exception as e: err=e
    raise err
first=g(f"{B}/wp-json/wc/v3/products?per_page=100&status=any&page=1&_fields={F}")
prods=list(first); page=2
def pull(p): return g(f"{B}/wp-json/wc/v3/products?per_page=100&status=any&page={p}&_fields={F}")
pages=list(range(2,60))
with ThreadPoolExecutor(max_workers=6) as ex:
    for b in ex.map(pull,pages):
        if b: prods+=b
        else: break
seen=set(); uniq=[]
for p in prods:
    if p["id"] not in seen: seen.add(p["id"]); uniq.append(p)
prods=uniq
print("products pulled:",len(prods),flush=True)
rows=[]
for p in prods:
    r=[]
    if p["status"]!="publish": r.append("not-published")
    if p["type"]=="variable" and not p.get("variations"): r.append("variable-no-variations")
    if not p["price"]: r.append("no-price")
    if p["stock_status"]!="instock": r.append("stock-"+p["stock_status"])
    if p["catalog_visibility"] not in ("visible","catalog"): r.append("vis-"+p["catalog_visibility"])
    if not p["purchasable"]: r.append("not-purchasable-flag")
    rows.append(dict(id=p["id"],sku=p["sku"],name=p["name"],type=p["type"],status=p["status"],
        price=p["price"],purchasable=p["purchasable"],stock=p["stock_status"],
        vis=p["catalog_visibility"],nvar=len(p.get("variations") or []),
        reasons=";".join(r) or "ok",link=p["permalink"]))
skus=collections.Counter(r["sku"] for r in rows if r["sku"])
dupsku={k:v for k,v in skus.items() if v>1}
nosku=[r for r in rows if not r["sku"]]
listsku=[r for r in rows if "," in (r["sku"] or "")]
bad=[r for r in rows if r["reasons"]!="ok"]
with open("unbuyable-products.csv","w",newline="") as f:
    w=csv.DictWriter(f,fieldnames=list(rows[0].keys())); w.writeheader(); w.writerows(bad)
with open("all-products-audit.csv","w",newline="") as f:
    w=csv.DictWriter(f,fieldnames=list(rows[0].keys())); w.writeheader(); w.writerows(rows)
c=collections.Counter()
for r in bad:
    for x in r["reasons"].split(";"): c[x]+=1
print("RESULT",json.dumps({"total":len(rows),"ok":len(rows)-len(bad),"problem":len(bad),
 "reasons":dict(c),"no_sku":len(nosku),"dup_sku_values":len(dupsku),
 "dup_sku_rows":sum(dupsku.values()),"comma_list_sku":len(listsku),
 "by_type":dict(collections.Counter(r["type"] for r in rows))}),flush=True)
json.dump(dupsku,open("dup-skus.json","w"),indent=1)
