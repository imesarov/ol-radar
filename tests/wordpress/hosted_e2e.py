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

        # ploča je u okviru u radnom dijelu wp-admina; WP izbornik ostaje lijevo
        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar#pregled")
        el = await pg.wait_for_selector("#olr-okvir")
        fr = await el.content_frame()
        await fr.wait_for_function("typeof S !== \"undefined\" && S.done && S.done.gsc", timeout=20000)
        await pg.wait_for_timeout(1500)
        lay = await pg.evaluate("""(()=>{const m=document.querySelector('#adminmenu').getBoundingClientRect(), f=document.querySelector('#olr-okvir').getBoundingClientRect();
            return {meni:m.width>0&&getComputedStyle(document.querySelector('#adminmenuwrap')).display!=='none', lijevo:Math.round(f.left), sirina:Math.round(f.width), dno:Math.round(f.bottom), vis:innerHeight, footer:getComputedStyle(document.querySelector('#wpfooter')).display}})()""")
        okl = lay["meni"] and lay["lijevo"] >= 150 and abs(lay["dno"] - lay["vis"]) <= 2 and lay["footer"] == "none"
        print(("OK  " if okl else "FAIL") + " ploča u radnom dijelu, WP izbornik lijevo:", lay); bad += 0 if okl else 1
        st = await fr.evaluate("""(()=>({gsc:S.gsc.src, daily:(S.gsc.daily||[]).length, qp:(S.gsc.qp||[]).length,
            rank:(S.rank.rows||[]).length, hist:S.rank.hist?S.rank.hist.runs.length:0, wp:(S.wp.list||[]).length,
            onpage:S.onpageSrc, wk:!!(weekData()&&weekData().movers), serpLive:S.serpLive?S.serpLive.size:0,
            model:(compModel()||{}).list?compModel().list.length:0, ai:!!SAMPLE, mcp:!!MCP,
            src:Object.fromEntries(Object.entries(S.src).map(([k,v])=>[k,v.state+': '+v.msg]))}))()""")
        print("stanje:", st)
        ok = st["gsc"] == "Google API" and st["daily"] == 90 and st["qp"] == 4 and st["rank"] == 5 and st["hist"] == 2 \
            and st["wp"] > 0 and st["onpage"] == "wp" and st["wk"] and st["serpLive"] == 5 and st["model"] > 0 and st["ai"] and not st["mcp"]
        print("OK   podaci s hostinga" if ok else "FAIL podaci s hostinga"); bad += 0 if ok else 1

        for v in ["tjedno", "google", "pozicije", "kljucne-rijeci", "planer", "konkurencija", "trendovi", "audit", "drustvene-mreze", "popravci", "arhiva", "metodologija", "pregled"]:
            await fr.evaluate(f"location.hash='#{v}'")
            await pg.wait_for_timeout(400)
            if v in ("tjedno", "pozicije", "audit", "konkurencija"):
                await pg.screenshot(path=f"{OUT}/hosted_{v}.png")

        # stanje nalaza se sprema u bazu dodatka
        iid = await fr.evaluate("S.issues[0] && S.issues[0].id")
        await fr.evaluate(f"setIssueState('{iid}', 'gotovo')")
        await pg.wait_for_timeout(1200)
        saved = await fr.evaluate(f"S.status['{iid}'] && S.status['{iid}'].status")
        print(("OK  " if saved == "gotovo" else "FAIL") + " stanje nalaza spremljeno preko REST-a i vraćeno (onSnapshot)"); bad += 0 if saved == "gotovo" else 1

        # AI chat: alat se izvršava u pregledniku, odgovor stiže preko servera
        await fr.evaluate("location.hash='#popravci'")
        await pg.wait_for_timeout(300)
        await fr.fill("#chat-in", "Kako stojimo ovaj tjedan?")
        await fr.evaluate("document.querySelector('#chat-form').requestSubmit()")
        await fr.wait_for_function("S.chat.length >= 2 && !document.querySelector('#chat-send').disabled", timeout=20000)
        ans = await fr.evaluate("S.chat[S.chat.length-1].content")
        print(("OK  " if "Prema alatu" in ans else "FAIL") + " AI chat s alatom: " + ans[:80].replace("\n", " ")); bad += 0 if "Prema alatu" in ans else 1

        # modul u adresi glavnog prozora; nakon osvježavanja ista stranica ploče
        await fr.evaluate("location.hash='#pozicije'")
        await pg.wait_for_timeout(300)
        okh1 = pg.url.endswith("#pozicije")
        await pg.reload()
        fr = await (await pg.wait_for_selector("#olr-okvir")).content_frame()
        await fr.wait_for_function("typeof S !== \"undefined\" && S.view === 'pozicije'", timeout=20000)
        print(("OK  " if okh1 else "FAIL") + " modul u adresi wp-admina i nakon osvježavanja"); bad += 0 if okh1 else 1
        # tema: svijetla zadana, prekidač pamti izbor
        t0 = await fr.evaluate("document.documentElement.dataset.tema")
        await fr.click("#btn-tema")
        t1 = await fr.evaluate("[document.documentElement.dataset.tema, getComputedStyle(document.body).backgroundColor]")
        await pg.screenshot(path=f"{OUT}/hosted_tamna.png")
        await pg.reload()
        fr = await (await pg.wait_for_selector("#olr-okvir")).content_frame()
        await fr.wait_for_function("typeof S !== \"undefined\"", timeout=20000)
        t2 = await fr.evaluate("document.documentElement.dataset.tema")
        await fr.click("#btn-tema")
        bg = await fr.evaluate("getComputedStyle(document.body).backgroundColor")
        okt = t0 == "svijetla" and t1[0] == "tamna" and t1[1] == "rgb(10, 10, 10)" and t2 == "tamna" and bg == "rgb(246, 245, 241)"
        print(("OK  " if okt else "FAIL") + f" tema: zadana {t0}, prekidač {t1}, zapamćena {t2}, natrag {bg}"); bad += 0 if okt else 1
        tg = await fr.evaluate("[...document.querySelectorAll('#banner a')].map(a=>a.textContent+':'+(a.target||'-')).join(', ')")
        okg2 = "Postavke i ključevi:_top" in tg
        print(("OK  " if okg2 else "FAIL") + " poveznice iz ploče otvaraju se u glavnom prozoru: " + tg); bad += 0 if okg2 else 1
        await fr.evaluate("location.hash='#pregled'")
        await pg.wait_for_timeout(800)
        await pg.screenshot(path=f"{OUT}/hosted_pregled.png")

        # prijedlozi izmjena: modul, dokazi i izmjene, stanje, ručna analiza
        await fr.evaluate("location.hash='#prijedlozi'")
        await pg.wait_for_timeout(500)
        pi = await fr.evaluate("""(()=>({n:document.querySelectorAll('#prop-list .prop').length, nav:document.querySelector('#nav-prijedlozi span').textContent,
            zadnji:[...document.querySelectorAll('.nav a')].filter(a=>!a.hidden).pop().querySelector('span').textContent,
            cnt:+document.querySelector('#cnt-prijedlozi').textContent, chg:document.querySelectorAll('#prop-list .chg').length, tbl:document.querySelectorAll('#prop-list table').length,
            meta:document.querySelector('#prop-meta').textContent, on:document.querySelector('#v-prijedlozi').classList.contains('on')}))()""")
        okp = pi["on"] and pi["n"] >= 4 and pi["nav"] == "02" and pi["zadnji"] == "14" and pi["cnt"] >= 4 and pi["chg"] >= 4 and pi["tbl"] >= 2 and "Zadnja analiza" in pi["meta"]
        print(("OK  " if okp else "FAIL") + " prijedlozi: modul s karticama, dokazima i izmjenama:", {k: pi[k] for k in ("n", "nav", "zadnji", "cnt", "chg", "tbl")}); bad += 0 if okp else 1
        await pg.screenshot(path=f"{OUT}/hosted_prijedlozi.png")
        pid = await fr.evaluate("document.querySelector('#prop-list [data-prop-set$=\"|prihvaceno\"]').dataset.propSet.split('|')[0]")
        await fr.click(f'[data-prop-set="{pid}|prihvaceno"]')
        await pg.wait_for_timeout(900)
        sv = await fr.evaluate(f"OLR_HOSTED.api('doc/prijedlozi_stanje/{pid}').then(d=>d.data.stanje)")
        cls = await fr.evaluate(f"document.querySelector('[data-prop=\"{pid}\"]').className")
        okp2 = sv == "prihvaceno" and "st-prihvaceno" in cls
        print(("OK  " if okp2 else "FAIL") + f" prijedlozi: prihvaćanje spremljeno na serveru ({sv})"); bad += 0 if okp2 else 1
        await fr.click('[data-pf2="odbijeno"]')
        nrej = await fr.evaluate("document.querySelectorAll('#prop-list .prop').length")
        await fr.click('[data-pf2="otvoreni"]')
        print(("OK  " if nrej == 1 else "FAIL") + f" prijedlozi: filtar po stanju (odbijenih {nrej})"); bad += 0 if nrej == 1 else 1
        await fr.click("#btn-prop-run")
        await fr.wait_for_function("!S.prop.busy", timeout=30000)
        okp3 = not await fr.evaluate("document.querySelector('#modal').classList.contains('on')") and await fr.evaluate("S.prop.meta && S.prop.meta.nacin") == "rucno"
        print(("OK  " if okp3 else "FAIL") + " prijedlozi: „Analiziraj sada” pokreće analizu na serveru"); bad += 0 if okp3 else 1
        await fr.evaluate("location.hash='#pregled'")
        await pg.wait_for_timeout(500)
        okp4 = await fr.evaluate("!!document.querySelector('#ov-prop a[href=\"#prijedlozi\"]')")
        print(("OK  " if okp4 else "FAIL") + " pregled: traka s novim prijedlozima"); bad += 0 if okp4 else 1

        # export za Claudea zapisan u bazu dodatka
        await fr.evaluate("maybeExport(true)")
        await pg.wait_for_timeout(1500)
        ex = await fr.evaluate("OLR_HOSTED.api('doc/export/radar').then(d=>({potpuno:d.data.potpuno, gsc:d.data.izvori.gsc, poz:(d.data.pozicije||[]).length}))")
        okx = ex["gsc"] == "Google API" and ex["poz"] == 5
        print(("OK  " if okx else "FAIL") + " export/radar u bazi dodatka:", ex); bad += 0 if okx else 1

        # prijedlozi bez Claudeova teksta: jasno piše da tekst još stiže (nije greška)
        await fr.evaluate("location.hash='#prijedlozi'")
        await pg.wait_for_timeout(400)
        pw = await fr.evaluate("""(()=>{const it=S.prop.items.find(p=>p.stanje==='novo'&&!p.isteklo); const k=[it.ai,it.ai_pokusaja,S.prop.wait];
            it.ai=false; it.ai_pokusaja=1; S.prop.wait=1; renderProp();
            const r={card:document.querySelector('[data-prop="'+it.id+'"] .meta').textContent, meta:document.querySelector('#prop-meta').textContent};
            it.ai_pokusaja=3; renderProp(); r.card3=document.querySelector('[data-prop="'+it.id+'"] .meta').textContent;
            [it.ai,it.ai_pokusaja,S.prop.wait]=k; renderProp(); return r})()""")
        okw = "tekst izmjene se još piše" in pw["card"] and "još se piše za 1 prijedlog" in pw["meta"] and "bez napisanog teksta" in pw["card3"]
        print(("OK  " if okw else "FAIL") + " prijedlozi: bez teksta piše „tekst izmjene se još piše”, nakon 3 pokušaja „bez napisanog teksta”"); bad += 0 if okw else 1

        # dok server dopisuje tekst u pozadini, ploča sama osvježava prijedloge (lagani poziv /prijedlozi)
        pb = await fr.evaluate("""(async()=>{const d=await OLR_HOSTED.api('prijedlozi'); setProp(Object.assign({},d,{pise_se:true,ceka_tekst:2})); renderProp();
            const r={n:d.stavke.length, timer:!!propPollTimer, meta:document.querySelector('#prop-meta').textContent};
            clearTimeout(propPollTimer); propPollTimer=null; setProp(d); renderProp(); r.poslije=!!propPollTimer; return r})()""")
        okb = pb["n"] >= 4 and pb["timer"] and not pb["poslije"] and "dopisuje u pozadini" in pb["meta"]
        print(("OK  " if okb else "FAIL") + " prijedlozi: dok se tekst piše u pozadini, prikaz se osvježava sam"); bad += 0 if okb else 1

        # pozicije: bez mjerenja je to napomena s objašnjenjem troška, ne greška ni vječno „učitavam”
        await fr.evaluate("location.hash='#pozicije'")
        await pg.wait_for_timeout(400)
        em = await fr.evaluate("""(()=>{const keep=S.rank.rows; S.rank.rows=null; renderRank(); const t=document.querySelector('#rk-table'), b=document.querySelector('#btn-rank-run');
            const r={note:t.querySelectorAll('.note').length, err:t.querySelectorAll('.err').length, load:t.querySelectorAll('.loading').length, txt:t.textContent, btn:b.textContent, hidden:b.hidden, dis:b.disabled};
            S.rank.rows=keep; renderRank(); r.btn2=b.textContent; return r})()""")
        oke0 = em["note"] == 1 and em["err"] == 0 and em["load"] == 0 and "još nisu izmjerene" in em["txt"] and "$" in em["txt"] and em["btn"] == "Izmjeri pozicije sada" and not em["hidden"] and not em["dis"] and em["btn2"] == "Novo mjerenje"
        print(("OK  " if oke0 else "FAIL") + " pozicije: prazno stanje je napomena s cijenom i gumbom:", {k: em[k] for k in ("note", "err", "load", "btn", "btn2")}); bad += 0 if oke0 else 1
        # gumb traži potvrdu troška: „Odustani” ne šalje ništa, „U redu” pokreće mjerenje na serveru
        msgs = []
        pg.once("dialog", lambda d: (msgs.append(d.message), asyncio.ensure_future(d.dismiss())))
        await fr.click("#btn-rank-run")
        await pg.wait_for_timeout(700)
        r0 = await fr.evaluate("OLR_HOSTED.api('podaci').then(d=>!!(d.rank&&d.rank.u_tijeku))")
        pg.once("dialog", lambda d: (msgs.append(d.message), asyncio.ensure_future(d.accept())))
        await fr.click("#btn-rank-run")
        await fr.wait_for_function("S.rank.hosted && S.rank.hosted.pending && !S.rank.starting", timeout=20000)
        r1 = await fr.evaluate("(()=>{const b=document.querySelector('#btn-rank-run');return {txt:b.textContent,dis:b.disabled,src:S.src.rank.msg}})()")
        okm = len(msgs) == 2 and "DataForSEO" in msgs[0] and "$" in msgs[0] and not r0 and r1["dis"] and "u tijeku" in r1["txt"] and "u tijeku" in r1["src"]
        print(("OK  " if okm else "FAIL") + " pozicije: mjerenje se pokreće tek nakon potvrde troška: " + msgs[0].split("\n")[0][:90]); bad += 0 if okm else 1
        # rezultati se pokupe sami (ovdje ručno, da test ne čeka 20 s); gumb se vraća
        for _ in range(4):
            await fr.evaluate("hostedRefresh(['rank'])")
            if not await fr.evaluate("S.rank.hosted.pending"):
                break
        r2 = await fr.evaluate("(()=>({pending:S.rank.hosted.pending, rows:(S.rank.rows||[]).length, runs:S.rank.hist?S.rank.hist.runs.length:0, btn:document.querySelector('#btn-rank-run').textContent, dis:document.querySelector('#btn-rank-run').disabled}))()")
        okm2 = not r2["pending"] and r2["rows"] == 5 and r2["runs"] == 3 and r2["btn"] == "Novo mjerenje" and not r2["dis"]
        print(("OK  " if okm2 else "FAIL") + " pozicije: novo mjerenje završeno i prikazano:", r2); bad += 0 if okm2 else 1

        # postavke: gumb "Spremi i provjeri" sprema upisani ključ pa ga provjerava
        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar-postavke")
        await pg.fill("#dfs_login", "ivan@test.hr")
        await pg.fill("#dfs_password", "tajna-api")
        async with pg.expect_navigation():
            await pg.click("button[name=olr_then][value=test_dfs]")
        msg = await pg.inner_text(".notice")
        okd = "spremljen" in msg and "Prijava radi" in msg and "notice-success" in (await pg.get_attribute(".notice", "class"))
        print(("OK  " if okd else "FAIL") + " spremi i provjeri DataForSEO: " + msg[:120]); bad += 0 if okd else 1
        await pg.fill("#anthropic_key", "sk-ant-test-1234")
        async with pg.expect_navigation():
            await pg.click("button[name=olr_then][value=test_ai]")
        msg = await pg.inner_text(".notice")
        oka = "Anthropic ključ spremljen" in msg and "Ključ radi" in msg
        print(("OK  " if oka else "FAIL") + " spremi i provjeri Claude ključ: " + msg[:120]); bad += 0 if oka else 1
        stt = await pg.evaluate("[...document.querySelectorAll('table.widefat tr')].map(r=>r.innerText.replace(/\\s+/g,' ')).filter(t=>t.includes('Zadnja provjera')).join(' | ')")
        okst = "Zadnja provjera DataForSEO prijave ● Prijava radi" in stt and "Zadnja provjera Claude ključa ● Ključ radi" in stt
        print(("OK  " if okst else "FAIL") + " ishod provjere ostaje zapisan u stanju izvora: " + stt[:160]); bad += 0 if okst else 1
        # "Spremi postavke" i Enter u polju ne smiju dirati spremljene ključeve (greška iz 2.0.0: ugniježđeni obrazac "obriši")
        async def kljucevi():
            return await pg.evaluate("[...document.querySelectorAll('button[name=olr_forget]')].map(b=>b.value).sort().join(',')")
        prije = await kljucevi()
        async with pg.expect_navigation():
            await pg.click("#submit")
        msg = await pg.inner_text(".notice")
        poslije = await kljucevi()
        auto = await pg.is_checked("input[name=prijedlozi_auto]")
        oks = msg.strip() == "Postavke spremljene." and prije == poslije and "anthropic_key" in poslije and "dfs_login" in poslije and auto
        print(("OK  " if oks else "FAIL") + f" Spremi postavke čuva ključeve ({poslije}): " + msg[:80]); bad += 0 if oks else 1
        async with pg.expect_navigation():
            await pg.press("#gsc_site", "Enter")
        msg = await pg.inner_text(".notice")
        oke = msg.strip() == "Postavke spremljene." and await kljucevi() == prije
        print(("OK  " if oke else "FAIL") + " Enter u polju = obično spremanje: " + msg[:80]); bad += 0 if oke else 1
        # obriši: traži potvrdu; "Odustani" ne briše, "U redu" briše samo taj ključ
        pg.once("dialog", lambda d: asyncio.ensure_future(d.dismiss()))
        await pg.click("button[name=olr_forget][value=anthropic_key]")
        await pg.wait_for_timeout(500)
        okc = "postavke" in pg.url and await kljucevi() == prije
        pg.once("dialog", lambda d: asyncio.ensure_future(d.accept()))
        async with pg.expect_navigation():
            await pg.click("button[name=olr_forget][value=anthropic_key]")
        msg = await pg.inner_text(".notice")
        ostalo = await kljucevi()
        okf = okc and "Ključ obrisan" in msg and "anthropic_key" not in ostalo and "dfs_login" in ostalo and "dfs_password" in ostalo
        print(("OK  " if okf else "FAIL") + f" obriši briše samo odabrani ključ ({ostalo})"); bad += 0 if okf else 1
        ac = await pg.evaluate("[...document.querySelectorAll('#dfs_password,#anthropic_key')].map(i=>i.autocomplete).join(',')")
        okac = ac == "new-password,new-password"
        print(("OK  " if okac else "FAIL") + " polja ključeva bez automatskog popunjavanja: " + ac); bad += 0 if okac else 1
        # vrati Claude ključ za ostatak testa
        await pg.fill("#anthropic_key", "sk-ant-test-1234")
        async with pg.expect_navigation():
            await pg.click("#submit")
        # nespremljene promjene: drugi obrazac (uvoz) traži potvrdu
        await pg.fill("#anthropic_key", "nesto")
        dialogs = []
        pg.once("dialog", lambda d: (dialogs.append(d.message), asyncio.ensure_future(d.dismiss())))
        await pg.click("input[type=submit][value=Uvezi]")
        await pg.wait_for_timeout(500)
        okg = bool(dialogs) and "nespremljene" in dialogs[0]
        print(("OK  " if okg else "FAIL") + " upozorenje na nespremljene promjene"); bad += 0 if okg else 1
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
