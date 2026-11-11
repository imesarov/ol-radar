"""MCP testovi sposobnosti: prava, zaštita URL-ova, provjera parametara, crawl, audit, detalji stranice."""
import json
import os
import sys
import urllib.request

sys.path.insert(0, os.path.dirname(__file__))
from mcp_test import BASE, Client, payload  # noqa: E402

D = os.environ["WPTEST_DIR"]
fails = []


def check(name, cond, info=""):
    print(("OK   " if cond else "FAIL ") + name + (" — " + info if info and not cond else ""))
    if not cond:
        fails.append(name)


# Bez prijave: 401
req = urllib.request.Request(BASE, data=json.dumps({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "x", "version": "1"}}}).encode(), method="POST")
req.add_header("Content-Type", "application/json")
req.add_header("Accept", "application/json, text/event-stream")
try:
    urllib.request.urlopen(req)
    check("anonimno odbijeno", False, "prošlo bez prijave")
except urllib.error.HTTPError as e:
    check("anonimno odbijeno", e.code == 401, str(e.code))

# Pretplatnik bez prava
c = Client(os.path.join(D, "sub.pass")); c.init()
check("pretplatnik ne može pokrenuti audit", payload(c.ability("ol-radar/audit", {})[1]) == "Permission denied")

# Čitač
c = Client(os.path.join(D, "reader.pass")); c.init()
left, n = 1, 0
while left and n < 20:
    d = payload(c.ability("ol-radar/osvjezi-crawl", {"velicina": 25})[1])["data"]; left = d["preostalo"]; n += 1
check("crawl završen", left == 0)
check("vanjski URL odbijen", payload(c.ability("ol-radar/stranica", {"url": "https://example.com/"})[1]) == "Dopušteni su samo URL-ovi na ovom webu.")
check("nepoznati parametar odbijen", "invalid input" in str(payload(c.ability("ol-radar/audit", {"nepostoji": 1})[1])))
check("granica po_stranici", "must be between" in str(payload(c.ability("ol-radar/popis-sadrzaja", {"po_stranici": 500})[1])))

a = payload(c.ability("ol-radar/audit", {})[1])["data"]
kod = a["po_kodu"]
for k in ["pokvaren_link", "link_na_preusmjerenje", "noindex", "vise_h1", "dupli_naslov", "slike_bez_alt", "slike_bez_dimenzija", "bez_ulaznih_linkova"]:
    check("audit hvata " + k, kod.get(k, 0) > 0, json.dumps(kod))
siroce = [x for x in a["nalazi"] if x["kod"] == "bez_ulaznih_linkova" and x["url"].endswith("/siroce-stranica/")]
check("nofollow se ne računa kao ulazni link", bool(siroce) and siroce[0]["detalj"]["nofollow_iz_sadrzaja"] >= 1)

p = payload(c.ability("ol-radar/stranica", {"url": "/progresivne-naocale/"})[1])["data"]
check("stranica: H1", p["html"]["h1"] == ["Progresivne naočale"], str(p["html"]["h1"]))
check("stranica: tekst", len(p["html"].get("tekst", "")) > 1000)
check("stranica: Rank Math fokus", p["rank_math"]["fokus"] == "progresivne naočale")

print("\nREZULTAT:", "sve prošlo" if not fails else f"{len(fails)} palo: {fails}")
sys.exit(1 if fails else 0)
