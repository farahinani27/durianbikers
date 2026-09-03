import os,json,base64,urllib.request,urllib.parse
from concurrent.futures import ThreadPoolExecutor
B=os.environ["WP_BASE_URL"];A="Basic "+base64.b64encode(f'{os.environ["WP_API_USER"]}:{os.environ["WP_APP_PASSWORD"]}'.encode()).decode()
def g(u):
    for _ in range(3):
        try:
            r=urllib.request.Request(u,headers={"Authorization":A})
            with urllib.request.urlopen(r,timeout=90) as f: return json.load(f)
        except Exception as e: err=e
    raise err
items=json.load(open("sku-audit/rg_master.json"))
def look(it):
    q=urllib.parse.quote(it["sku"],safe="")
    try:
        r=g(f"{B}/wp-json/wc/v3/products?status=any&sku={q}")
    except Exception as e:
        return {**it,"found":None,"err":str(e)}
    if not r: return {**it,"found":False}
    p=r[0]
    return {**it,"found":True,"wc_id":p["id"],"wc_sku":p["sku"],"wc_name":p["name"],
            "wc_type":p.get("type"),"wc_status":p.get("status"),
            "wc_stock":p.get("stock_quantity"),"wc_stock_status":p.get("stock_status"),
            "wc_price":p.get("price"),"wc_manage":p.get("manage_stock"),
            "n_hits":len(r)}
res=list(ThreadPoolExecutor(max_workers=6).map(look,items))
json.dump(res,open("sku-audit/rg_matched.json","w"),indent=1)
print("DONE",len(res),"found:",sum(1 for r in res if r.get("found")),
      "missing:",sum(1 for r in res if r.get("found") is False),
      "errors:",sum(1 for r in res if r.get("found") is None))
