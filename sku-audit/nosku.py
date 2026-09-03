import os,json,base64,urllib.request
from concurrent.futures import ThreadPoolExecutor
B=os.environ["WP_BASE_URL"];A="Basic "+base64.b64encode(f'{os.environ["WP_API_USER"]}:{os.environ["WP_APP_PASSWORD"]}'.encode()).decode()
def g(u):
    for _ in range(3):
        try:
            r=urllib.request.Request(u,headers={"Authorization":A})
            with urllib.request.urlopen(r,timeout=120) as f: return json.load(f)
        except Exception as e: err=e
    raise err
F="id,name,sku,type,status,stock_status,price"
def page(p): return g(f"{B}/wp-json/wc/v3/products?per_page=100&status=any&page={p}&_fields={F}")
prods=[]
with ThreadPoolExecutor(max_workers=6) as ex:
    for b in ex.map(page,range(1,60)):
        if b: prods+=b
seen=set();u=[]
for p in prods:
    if p["id"] not in seen: seen.add(p["id"]);u.append(p)
nosku=[p for p in u if not p["sku"]]
def chk(p):
    if p["type"]!="variable": return (p,0,0)
    v=g(f"{B}/wp-json/wc/v3/products/{p['id']}/variations?per_page=100")
    return (p,len(v),sum(1 for x in v if x["sku"]))
res=list(ThreadPoolExecutor(max_workers=6).map(chk,nosku))
json.dump([{"id":p["id"],"name":p["name"],"type":p["type"],"status":p["status"],
            "stock":p["stock_status"],"price":p["price"],"nvar":n,"coded":c}
           for p,n,c in res],open("sku-audit/nosku.json","w"),indent=1)
print("DONE",len(u),len(nosku))
