#!/usr/bin/env python3
"""Headless test nadzorne ploče na hostingu (wp-admin → OL Radar) u lokalnom WordPressu.

Pretpostavlja da je radar_test.php već napunio bazu (GSC, pozicije, SERP) i da su lažni
Google/DataForSEO/Anthropic odgovori uključeni (opcija olr_test_mocks).
"""
import asyncio, os, sys
from playwright.async_api import async_playwright

BASE = f"http://127.0.0.1:{os.environ.get('WPTEST_PORT', '8089')}"
CHROME = os.environ.get("CHROME", "/usr/bin/google-chrome")
OUT = os.environ.get("SHOTS", "/tmp")


async def main():
    bad = 0
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
        pg = await b.new_page(viewport={"width": 1360, "height": 1000})
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        # bez interneta u testu: Google Fonts se ne učitavaju (ERR_TUNNEL), to nije greška aplikacije
        pg.on("console", lambda m: errs.append(m.text) if m.type == "error" and "favicon" not in m.text and "ERR_TUNNEL" not in m.text else None)
        await pg.goto(BASE + "/wp-login.php")
        await pg.fill("#user_login", "admin")
        await pg.fill("#user_pass", os.environ.get("WPTEST_ADMIN_PASS", "admin-pass-123"))
        async with pg.expect_navigation():
            await pg.click("#wp-submit")

        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar#pregled")
        await pg.wait_for_function("typeof S !== \"undefined\" && S.done && S.done.gsc", timeout=20000)
        await pg.wait_for_timeout(1500)
        st = await pg.evaluate("""(()=>({gsc:S.gsc.src, daily:(S.gsc.daily||[]).length, qp:(S.gsc.qp||[]).length,
            rank:(S.rank.rows||[]).length, hist:S.rank.hist?S.rank.hist.runs.length:0, wp:(S.wp.list||[]).length,
            onpage:S.onpageSrc, wk:!!(weekData()&&weekData().movers), serpLive:S.serpLive?S.serpLive.size:0,
            model:(compModel()||{}).list?compModel().list.length:0, ai:!!SAMPLE, mcp:!!MCP,
            src:Object.fromEntries(Object.entries(S.src).map(([k,v])=>[k,v.state+': '+v.msg]))}))()""")
        print("stanje:", st)
        ok = st["gsc"] == "Google API" and st["daily"] == 90 and st["qp"] == 4 and st["rank"] == 5 and st["hist"] == 2 \
            and st["wp"] > 0 and st["onpage"] == "wp" and st["wk"] and st["serpLive"] == 5 and st["model"] > 0 and st["ai"] and not st["mcp"]
        print("OK   podaci s hostinga" if ok else "FAIL podaci s hostinga"); bad += 0 if ok else 1

        for v in ["tjedno", "google", "pozicije", "kljucne-rijeci", "planer", "konkurencija", "trendovi", "audit", "drustvene-mreze", "popravci", "arhiva", "metodologija", "pregled"]:
            await pg.evaluate(f"location.hash='#{v}'")
            await pg.wait_for_timeout(400)
            if v in ("tjedno", "pozicije", "audit", "konkurencija"):
                await pg.screenshot(path=f"{OUT}/hosted_{v}.png", full_page=True)

        # stanje nalaza se sprema u bazu dodatka
        iid = await pg.evaluate("S.issues[0] && S.issues[0].id")
        await pg.evaluate(f"setIssueState('{iid}', 'gotovo')")
        await pg.wait_for_timeout(1200)
        saved = await pg.evaluate(f"S.status['{iid}'] && S.status['{iid}'].status")
        print(("OK  " if saved == "gotovo" else "FAIL") + " stanje nalaza spremljeno preko REST-a i vraćeno (onSnapshot)"); bad += 0 if saved == "gotovo" else 1

        # AI chat: alat se izvršava u pregledniku, odgovor stiže preko servera
        await pg.evaluate("location.hash='#popravci'")
        await pg.wait_for_timeout(300)
        await pg.fill("#chat-in", "Kako stojimo ovaj tjedan?")
        await pg.evaluate("document.querySelector('#chat-form').requestSubmit()")
        await pg.wait_for_function("S.chat.length >= 2 && !document.querySelector('#chat-send').disabled", timeout=20000)
        ans = await pg.evaluate("S.chat[S.chat.length-1].content")
        print(("OK  " if "Prema alatu" in ans else "FAIL") + " AI chat s alatom: " + ans[:80].replace("\n", " ")); bad += 0 if "Prema alatu" in ans else 1

        # export za Claudea zapisan u bazu dodatka
        await pg.evaluate("maybeExport(true)")
        await pg.wait_for_timeout(1500)
        ex = await pg.evaluate("OLR_HOSTED.api('doc/export/radar').then(d=>({potpuno:d.data.potpuno, gsc:d.data.izvori.gsc, poz:(d.data.pozicije||[]).length}))")
        okx = ex["gsc"] == "Google API" and ex["poz"] == 5
        print(("OK  " if okx else "FAIL") + " export/radar u bazi dodatka:", ex); bad += 0 if okx else 1

        # postavke se otvaraju i ne prikazuju ključeve
        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar-postavke")
        html = await pg.content()
        leak = "sk-ant-test-1234" in html or "PRIVATE KEY" in html or "tajna-api" in html
        print(("FAIL" if leak else "OK  ") + " postavke ne otkrivaju ključeve"); bad += 1 if leak else 0
        await pg.screenshot(path=f"{OUT}/hosted_postavke.png", full_page=True)

        print("greške u pregledniku:", errs or "nema"); bad += len(errs)
        await b.close()
    print("SVE PROŠLO" if not bad else f"NEUSPJELO: {bad}")
    sys.exit(1 if bad else 0)

asyncio.run(main())
