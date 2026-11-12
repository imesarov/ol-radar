#!/usr/bin/env python3
"""Headless test nadzorne ploče na hostingu (wp-admin → OL Radar) u lokalnom WordPressu.

Pretpostavlja da je radar_test.php već napunio bazu (GSC, pozicije, SERP, društvene mreže) i da su lažni
Google/DataForSEO/Anthropic/Meta/YouTube/TikTok odgovori uključeni (opcija olr_test_mocks).
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

        # „Predloži popravak”: odgovor stiže kao poziv alata (preko servera), pa nema „krivog oblika”
        iid2 = await fr.evaluate("S.issues[0].id")
        await fr.evaluate(f"(()=>{{S.fx.sel='{iid2}'; renderFixes();}})()")
        await fr.click("#btn-fix")
        await fr.wait_for_function("!document.querySelector('#btn-fix').disabled", timeout=30000)
        fx = await fr.evaluate("({txt:document.querySelector('#fix-out').textContent, err:document.querySelectorAll('#fix-out .err').length, ready:document.querySelectorAll('#fix-out .ready').length, btn:document.querySelector('#btn-fix').textContent})")
        okfx = fx["err"] == 0 and "Dijagnoza iz alata." in fx["txt"] and fx["ready"] == 1 and fx["btn"] == "Novi prijedlog"
        print(("OK  " if okfx else "FAIL") + " popravak nalaza: Claudeov prijedlog stiže i prikazuje se: " + fx["txt"][:70].replace("\n", " ")); bad += 0 if okfx else 1
        # isti put uz teže odgovore (lažni odgovori servera u pregledniku): prekinut pa sažet, odbijen alat pa tekst s ogradom, dvaput prekinut
        sj = await fr.evaluate("""(async()=>{
            const real = window.fetch, out = {}, S2 = await window.claude.use('sample');
            const reply = b => new Response(JSON.stringify(b), {status: b.__status || 200, headers: {'Content-Type': 'application/json'}});
            const run = async (name, handler) => { const calls = []; window.fetch = async (u, o) => { if (!String(u).includes('/ai')) return real(u, o); const q = JSON.parse(o.body); calls.push(q); return reply(handler(q, calls.length)); };
              try { out[name] = {val: await S2.json('NALAZ: test', {schema: FIX_SCHEMA}), n: calls.length, tool: calls.map(c => !!c.tool_choice), short: calls.map(c => c.messages[0].content.includes('predug'))}; }
              catch (e) { out[name] = {err: e.code, cut: !!e.cut, n: calls.length}; } };
            const use = input => ({stop_reason: 'tool_use', content: [{type: 'tool_use', id: 't', name: 'odgovor', input}]});
            await run('prekid', (q, n) => n === 1 ? {stop_reason: 'max_tokens', content: [{type: 'tool_use', id: 't', name: 'odgovor', input: {}}]} : use({dijagnoza: 'kratko', koraci: ['a']}));
            await run('odbijen', (q, n) => q.tool_choice ? {__status: 400, code: 'olr_ai_zahtjev', message: 'odbijen'} : {stop_reason: 'end_turn', content: [{type: 'text', text: 'Evo prijedloga:\\n```json\\n{"dijagnoza": "iz teksta {s vitičastom} i \\\\"navodnicima\\\\"", "koraci": ["a"]}\\n```\\nNapomena: {ovo nije dio odgovora}'}]});
            await run('dvaput', () => ({stop_reason: 'max_tokens', content: [{type: 'text', text: '{"dijagnoza": "prekinuto'}]}));
            await run('krediti', () => ({__status: 502, code: 'olr_ai_api', message: 'API krediti su potrošeni.'}));
            window.fetch = real; return out; })()""")
        oksj = (sj["prekid"].get("val", {}).get("dijagnoza") == "kratko" and sj["prekid"]["n"] == 2 and sj["prekid"]["short"] == [False, True]
            and "iz teksta" in sj["odbijen"].get("val", {}).get("dijagnoza", "") and sj["odbijen"]["tool"] == [True, False]
            and sj["dvaput"].get("err") == "invalid_json" and sj["dvaput"]["cut"] and sj["dvaput"]["n"] == 2
            and sj["krediti"].get("err") == "olr_ai_api" and sj["krediti"]["n"] == 1)
        print(("OK  " if oksj else "FAIL") + " popravak nalaza: prekinut odgovor ide ponovno sažetije, odbijen alat ide tekstom, greške su jasne:", {k: (v.get("err") or "ok", v["n"]) for k, v in sj.items()}); bad += 0 if oksj else 1
        em = await fr.evaluate("[sampleErr({code:'invalid_json', cut:true}), sampleErr({code:'invalid_json'}), sampleErr({code:'olr_ai_api', message:'API krediti su potrošeni.'})]")
        okem = "predug" in em[0] and "krivom obliku" in em[1] and em[2] == "API krediti su potrošeni."
        print(("OK  " if okem else "FAIL") + " popravak nalaza: poruka kaže što se dogodilo"); bad += 0 if okem else 1

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
        okp = pi["on"] and pi["n"] >= 4 and pi["nav"] == "02" and pi["zadnji"] == "15" and pi["cnt"] >= 4 and pi["chg"] >= 4 and pi["tbl"] >= 1 and "Zadnja analiza" in pi["meta"]
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

        # učinak provedenih izmjena: kartica provedenog prijedloga pokazuje usporedbu prije i poslije
        await fr.evaluate("location.hash='#prijedlozi'")
        await fr.click('[data-pf2="provedeno"]')
        await pg.wait_for_timeout(400)
        ue = await fr.evaluate("""(()=>{const c=[...document.querySelectorAll('#prop-list .prop')]; const withT=c.find(x=>x.querySelector('.ucinak table')); const no=c.find(x=>x.querySelector('.ucinak')&&!x.querySelector('.ucinak table'));
            return {n:c.length, uc:document.querySelectorAll('#prop-list .ucinak').length, id:withT?withT.dataset.prop:null, rows:withT?withT.querySelectorAll('.ucinak tbody tr').length:0,
              txt:withT?withT.querySelector('.ucinak').textContent:'', no:no?no.querySelector('.ucinak').textContent:'', dan:withT?withT.querySelector('[data-prop-dan]').value:'', meta:document.querySelector('#prop-meta').textContent}})()""")
        oku = ue["n"] == 2 and ue["uc"] == 2 and ue["rows"] == 2 and "Učinak izmjene" in ue["txt"] and "cijeli web" in ue["txt"] and "→" in ue["txt"] and "ne mjeri se po stranici" in ue["no"] and "Učinak provedenih izmjena" in ue["meta"]
        print(("OK  " if oku else "FAIL") + " učinak: provedeni prijedlog pokazuje prije i poslije uz trend weba:", {k: ue[k] for k in ("n", "uc", "rows", "dan")}); bad += 0 if oku else 1
        await pg.screenshot(path=f"{OUT}/hosted_ucinak.png")
        # promjena dana provedbe sprema se na server i mjerenje kreće iznova
        import datetime
        novi = (datetime.date.today() - datetime.timedelta(days=33)).isoformat()
        await fr.fill(f'[data-prop-dan="{ue["id"]}"]', novi)
        await fr.dispatch_event(f'[data-prop-dan="{ue["id"]}"]', "change")
        await fr.wait_for_function(f"(()=>{{const p=S.prop.items.find(x=>x.id==='{ue['id']}'); return p&&p.mjerenje&&p.mjerenje.provedeno==='{novi}'}})()", timeout=20000)
        ud = await fr.evaluate(f"(()=>{{const p=S.prop.items.find(x=>x.id==='{ue['id']}'); return {{stanje:p.mjerenje.stanje, prozori:Object.keys(p.mjerenje.prozori), ocjena:p.mjerenje.ocjena, rows:document.querySelectorAll('[data-prop=\"{ue['id']}\"] .ucinak tbody tr').length}}}})()")
        sd = await fr.evaluate(f"OLR_HOSTED.api('doc/prijedlozi_stanje/{ue['id']}').then(d=>d.data.dan)")
        okd2 = sd == novi and ud["stanje"] == "gotovo" and ud["prozori"] == ["7", "14", "28"] and ud["rows"] == 3
        print(("OK  " if okd2 else "FAIL") + " učinak: novi dan provedbe spremljen, usporedba 7, 14 i 28 dana:", ud); bad += 0 if okd2 else 1
        await fr.click('[data-pf2="otvoreni"]')

        # indeksiranost: panel u Auditu s razlogom koji navodi Google; „Provjeri sada” pokreće serije na serveru
        await fr.evaluate("location.hash='#audit'")
        await pg.wait_for_timeout(500)
        ix = await fr.evaluate("""(()=>({hidden:document.querySelector('#ix-panel').hidden, tiles:document.querySelectorAll('#ix-tiles .tile').length, bad:document.querySelectorAll('#ix-table > .tw tbody tr').length,
            txt:document.querySelector('#ix-table').textContent, all:document.querySelectorAll('#ix-table details tbody tr').length, meta:document.querySelector('#ix-meta').textContent, sc:document.querySelectorAll('#ix-table a[href*="search.google.com"]').length}))()""")
        oki = not ix["hidden"] and ix["tiles"] == 4 and ix["bad"] == 5 and "nije u indeksu" in ix["txt"] and "Pretraženo – trenutačno nije indeksirano" in ix["txt"] and "druga kanonska" in ix["txt"] and ix["all"] > 100 and ix["sc"] >= 4 and "provjereno" in ix["meta"]
        print(("OK  " if oki else "FAIL") + " indeksiranost: panel s problemima i razlogom iz Googlea:", {k: ix[k] for k in ("tiles", "bad", "all", "sc")}); bad += 0 if oki else 1
        await pg.screenshot(path=f"{OUT}/hosted_indeks.png")
        await fr.click("#btn-ix-run")
        await fr.wait_for_function("!S.ixBusy", timeout=60000)
        ix2 = await fr.evaluate("({prov:S.indeks.provjereno, uk:S.indeks.ukupno, btn:document.querySelector('#btn-ix-run').textContent, modal:document.querySelector('#modal').classList.contains('on')})")
        oki2 = ix2["prov"] == ix2["uk"] and ix2["btn"] == "Provjeri sada" and not ix2["modal"]
        print(("OK  " if oki2 else "FAIL") + " indeksiranost: „Provjeri sada” završava bez greške:", ix2); bad += 0 if oki2 else 1

        # AI pregled u pozicijama i kretanje konkurenata iz naših mjerenja
        await fr.evaluate("location.hash='#pozicije'")
        await pg.wait_for_timeout(400)
        ai = await fr.evaluate("""(()=>({th:[...document.querySelectorAll('#rk-table th')].map(t=>t.textContent), tiles:[...document.querySelectorAll('#rk-tiles .tile .k')].map(t=>t.textContent), txt:document.querySelector('#rk-table').textContent,
            tile:[...document.querySelectorAll('#rk-tiles .tile')].pop().textContent}))()""")
        okai = "AI pregled" in ai["th"] and "AI pregled" in ai["tiles"] and "citira nas" in ai["txt"] and "ne citira nas" in ai["txt"] and "essilor.com" in ai["txt"] and "citira nas: 1" in ai["tile"]
        print(("OK  " if okai else "FAIL") + " pozicije: stupac i pločica AI pregleda (citira nas / ne citira nas):", ai["tile"]); bad += 0 if okai else 1
        await pg.screenshot(path=f"{OUT}/hosted_pozicije_ai.png")
        await fr.evaluate("location.hash='#konkurencija'")
        await pg.wait_for_timeout(500)
        km = await fr.evaluate("""(()=>({hidden:document.querySelector('#km-panel').hidden, rows:document.querySelectorAll('#km-body tbody tr').length, me:(document.querySelector('#km-body tr.me')||{}).textContent||'',
            txt:document.querySelector('#km-body').textContent, li:document.querySelectorAll('#km-body .kret li').length, meta:document.querySelector('#km-meta').textContent, sp:document.querySelectorAll('#km-body svg.spark').length}))()""")
        okk = not km["hidden"] and km["rows"] >= 10 and "optikaljiljana.hr" in km["me"] and "mi" in km["me"] and "novo u top 10" in km["txt"] and "pretekli nas" in km["txt"] and "konkurent4.hr" in km["txt"] and km["li"] >= 3 and "2 mjerenja" in km["meta"] and km["sp"] >= 10
        print(("OK  " if okk else "FAIL") + " konkurencija: kretanje konkurenata (udio, promjena, tko nas je pretekao):", {k: km[k] for k in ("rows", "li", "sp", "meta")}); bad += 0 if okk else 1
        await pg.screenshot(path=f"{OUT}/hosted_kretanje.png")

        # društvene mreže: pločice po mreži, preporuke, što prolazi, uzori; podaci dolaze sa servera
        await fr.evaluate("location.hash='#drustvene-mreze'")
        await pg.wait_for_timeout(500)
        so = await fr.evaluate("""(()=>({vid:[...document.querySelectorAll('#v-drustvene-mreze .soc-v')].every(e=>e.hidden), host:[...document.querySelectorAll('#v-drustvene-mreze .soc-h')].every(e=>!e.hidden),
            tiles:[...document.querySelectorAll('#sh-tiles .tile')].map(t=>t.querySelector('.k').textContent+':'+t.querySelector('.v').textContent).join(' '),
            recs:document.querySelectorAll('#sh-rec-body .rec').length, ideje:document.querySelectorAll('#sh-rec-body tbody tr').length, rtxt:document.querySelector('#sh-rec-body').textContent, rmeta:document.querySelector('#sh-rec-meta').textContent,
            seg:[...document.querySelectorAll('#sh-seg button')].map(b=>b.textContent).join(','), meta:document.querySelector('#sh-net-meta').textContent, tbl:document.querySelectorAll('#sh-net-body > .tw').length,
            txt:document.querySelector('#sh-net-body').textContent, all:document.querySelectorAll('#sh-net-body details tbody tr').length, links:document.querySelectorAll('#sh-net-body a[href*="instagram.com"]').length,
            uz:document.querySelector('#sh-uz-body').textContent, uzr:document.querySelectorAll('#sh-uz-body tbody tr').length,
            src:['ig','fb','yt','tt'].map(k=>S.src[k].state).join(','), lab:document.querySelector('#srcs').textContent, ig:kpis().igFollowers, yt:S.yt.chan&&S.yt.chan.subscriberCount, igp:igPosts().length, w:document.documentElement.scrollWidth-document.documentElement.clientWidth}))()""")
        oks1 = so["vid"] and so["host"] and "Instagram:2.140" in so["tiles"] and "Facebook:3.350" in so["tiles"] and "YouTube:310" in so["tiles"] and "TikTok:540" in so["tiles"] \
            and so["src"] == "ok,ok,ok,ok" and "Facebook" in so["lab"] and "TikTok" in so["lab"] and so["ig"] == 2140 and so["yt"] == 310 and so["igp"] == 9 and so["w"] <= 0
        print(("OK  " if oks1 else "FAIL") + " društvene mreže: četiri mreže sa servera, stanje u izvorima, bez vodoravnog pomaka:", so["tiles"], so["src"], so["w"]); bad += 0 if oks1 else 1
        oks2 = so["recs"] == 2 and so["ideje"] == 2 and "Više reelova koji odgovaraju na jedno pitanje" in so["rtxt"] and "Prvi korak" in so["rtxt"] and "Vrti vam se u novim naočalama?" in so["rtxt"] and "napisano" in so["rmeta"]
        print(("OK  " if oks2 else "FAIL") + " društvene mreže: Claudeove preporuke i ideje za objave:", {k: so[k] for k in ("recs", "ideje")}); bad += 0 if oks2 else 1
        oks3 = so["seg"] == "Instagram,Facebook,YouTube,TikTok" and "medijan 1.050 pregleda" in so["meta"] and so["tbl"] == 3 and "8,6×" in so["txt"] and "vrsta: reel" in so["txt"] and "Zašto vam se vrti" in so["txt"] and so["all"] == 9 and so["links"] >= 9
        print(("OK  " if oks3 else "FAIL") + " društvene mreže: što prolazi, a što ne (× medijana, obrasci, sve objave):", so["meta"][:70]); bad += 0 if oks3 else 1
        oks4 = "Progressive lenses explained in 60 seconds" in so["uz"] and "@uzor_optika" in so["uz"] and so["uzr"] == 8 and "13,0×" in so["uz"] and "privatni_profil" in so["uz"]
        print(("OK  " if oks4 else "FAIL") + " društvene mreže: uzori s YouTubea i Instagrama:", so["uzr"]); bad += 0 if oks4 else 1
        await pg.screenshot(path=f"{OUT}/hosted_mreze.png", full_page=True)
        await fr.click('[data-soc-net="tt"]')
        tk = await fr.evaluate("({meta:document.querySelector('#sh-net-meta').textContent, txt:document.querySelector('#sh-net-body').textContent, on:document.querySelector('[data-soc-net=\"tt\"]').getAttribute('aria-pressed')})")
        oks5 = "medijan 3.850 pregleda" in tk["meta"] and "8,1×" in tk["txt"] and "ljuljaju" in tk["txt"] and tk["on"] == "true"
        print(("OK  " if oks5 else "FAIL") + " društvene mreže: prebacivanje mreže (TikTok):", tk["meta"][:60]); bad += 0 if oks5 else 1
        at0 = await fr.evaluate("S.mreze.zadnje.at")
        await pg.wait_for_timeout(1100)
        await fr.click('[data-refresh="social"]')
        await fr.wait_for_function("!S.soc.busy", timeout=60000)
        rf = await fr.evaluate("({err:S.soc.err, at:S.mreze.zadnje.at, btn:document.querySelector('[data-refresh=\"social\"]').textContent, uz:(S.mreze.uzori.yt||[]).length, tiles:document.querySelectorAll('#sh-tiles .tile').length})")
        oks6 = rf["err"] is None and rf["at"] > at0 and rf["btn"] == "Osvježi mreže" and rf["uz"] == 3 and rf["tiles"] == 4
        print(("OK  " if oks6 else "FAIL") + " društvene mreže: „Osvježi mreže” preuzima podatke i uzore na serveru:", rf); bad += 0 if oks6 else 1
        await fr.click("#btn-sh-rec")
        await fr.wait_for_function("!S.soc.recBusy", timeout=60000)
        rc = await fr.evaluate("({err:S.soc.recErr, n:document.querySelectorAll('#sh-rec-body .rec').length, btn:document.querySelector('#btn-sh-rec').textContent, nacin:S.mreze.preporuke.nacin, meta:document.querySelector('#sh-rec-meta').textContent})")
        oks7 = rc["err"] is None and rc["n"] == 2 and rc["btn"] == "Nove preporuke" and rc["nacin"] == "rucno" and "upravo" in rc["meta"]
        print(("OK  " if oks7 else "FAIL") + " društvene mreže: „Nove preporuke” piše Claude na serveru:", rc); bad += 0 if oks7 else 1
        await fr.evaluate("location.hash='#pregled'")
        await pg.wait_for_timeout(500)
        ov = await fr.evaluate("[...document.querySelectorAll('#kpis .tile')].map(t=>t.textContent).find(t=>t.includes('Pratitelji')) || ''")
        await fr.evaluate("maybeExport(true)")
        await pg.wait_for_timeout(1500)
        exs = await fr.evaluate("OLR_HOSTED.api('doc/export/radar').then(d=>({m:d.data.drustvene_mreze?d.data.drustvene_mreze.mreze.ig.medijan:null, p:d.data.drustvene_mreze&&d.data.drustvene_mreze.preporuke?d.data.drustvene_mreze.preporuke.naslovi.length:0, ig:(d.data.instagram||[]).length, yt:(d.data.youtube||[]).length}))")
        tool = await fr.evaluate("(()=>{const t=chatTools().find(x=>x.name==='drustvene_mreze'); const r=t?t.execute():null; return r?{top:r.mreze.ig.najbolje[0].omjer, uz:r.uzori_youtube.length}:null})()")
        oks8 = "6.340" in ov and "Instagram 2.140" in ov and exs["m"] == 1050 and exs["p"] == 2 and exs["ig"] == 9 and exs["yt"] == 6 and tool and tool["top"] == 8.6 and tool["uz"] == 3
        print(("OK  " if oks8 else "FAIL") + " društvene mreže: pločica u Pregledu, izvoz za Claudea i alat AI analitičara:", ov[:60], exs, tool); bad += 0 if oks8 else 1

        # suradnje i poveznice: kartice s prijedlogom i kontaktom, stanja, provjera poveznice, uvoz iz Search Consolea, DR
        await fr.evaluate("location.hash='#suradnje'")
        await pg.wait_for_timeout(500)
        su = await fr.evaluate("""(()=>({on:document.querySelector('#v-suradnje').classList.contains('on'), nav:document.querySelector('#nav-suradnje span').textContent,
            n:document.querySelectorAll('#sur-list .prop').length, chg:document.querySelectorAll('#sur-list .chg').length, copy:document.querySelectorAll('#sur-list [data-sur-copy]').length,
            txt:document.querySelector('#sur-list').textContent, tiles:[...document.querySelectorAll('#sur-tiles .tile')].map(t=>t.querySelector('.k').textContent+':'+t.querySelector('.v').textContent).join(' | '),
            meta:document.querySelector('#sur-meta').textContent, cnt:+document.querySelector('#cnt-suradnje').textContent, mail:document.querySelectorAll('#sur-list a[href^="mailto:"]').length,
            dr:document.querySelectorAll('#sur-dr-body tbody tr').length, me:document.querySelectorAll('#sur-dr-body tr.me').length, drm:document.querySelector('#sur-dr-meta').textContent,
            gsc:document.querySelectorAll('#sur-gsc-body tbody tr').length, html:document.querySelector('#sur-list').innerHTML.includes('<b>'), w:document.documentElement.scrollWidth-document.documentElement.clientWidth,
            how:[...document.querySelectorAll('#sur-list .prop')].filter(p=>p.textContent.includes('Kako su drugi došli do poveznice:')).map(p=>({d:p.querySelector('header a').textContent, a:(p.querySelector('a[rel="noopener nofollow"]')||{}).href||'', t:(p.querySelector('a[rel="noopener nofollow"]')||{}).textContent||'', pill:[...p.querySelectorAll('.pill')].map(x=>x.textContent).join('|')})),
            spark:document.querySelectorAll('#sur-dr-body svg.spark').length}))()""")
        oku1 = su["on"] and su["nav"] == "12" and su["n"] >= 6 and su["chg"] >= 2 * su["n"] and su["copy"] == su["n"] and su["cnt"] == su["n"] and su["mail"] >= su["n"] and "Stručni komentar optometrista" in su["txt"] and "urednik rubrike Zdravlje" in su["txt"] and not su["html"] and su["w"] <= 0
        print(("OK  " if oku1 else "FAIL") + " suradnje: kartice s prijedlogom, nacrtom poruke i javnim kontaktom, bez vodoravnog pomaka:", {k: su[k] for k in ("n", "chg", "cnt", "mail", "w")}); bad += 0 if oku1 else 1
        oku2 = "Naš DR:1,4" in su["tiles"] and "Najjači konkurent:29" in su["tiles"] and "Poveznice (Google):3" in su["tiles"] and "Kandidata:" in su["tiles"] and su["dr"] == 6 and su["me"] == 1 and "Domain Rating by Ahrefs" in su["drm"] and "tjedna provjera je isključena" in su["drm"] and su["gsc"] == 3 and "jednom tjedno" in su["meta"]
        print(("OK  " if oku2 else "FAIL") + " suradnje: pločice, DR nas i konkurenata s oznakom izvora, poveznice koje vidi Google:", su["tiles"]); bad += 0 if oku2 else 1
        h0 = su["how"][0] if su["how"] else {}
        oku2b = len(su["how"]) == 1 and h0.get("d") == "gloria.hr" and h0.get("a", "").startswith("https://www.gloria.hr/promo/") and h0.get("t") == "Primjer naslova članka" and "traži stručni sadržaj" in h0.get("pill", "") and "već povezuje:" in su["txt"]
        print(("OK  " if oku2b else "FAIL") + " suradnje: kartica pokazuje kako su drugi došli do poveznice, primjer stranice i ocjenu izvedivosti:", su["how"]); bad += 0 if oku2b else 1
        await pg.screenshot(path=f"{OUT}/hosted_suradnje.png", full_page=True)
        # stanje: poruka poslana → dogovoreno → upis adrese s poveznicom, koju server provjeri
        sid = await fr.evaluate("document.querySelector('#sur-list [data-sur-set$=\"|kontaktirano\"]').dataset.surSet.split('|')[0]")
        await fr.click(f'[data-sur-set="{sid}|kontaktirano"]')
        await fr.wait_for_function(f"S.sur.d.kandidati.find(c=>c.id==='{sid}').stanje==='kontaktirano' && S.sur.d.stanja.kontaktirano>=1", timeout=20000)
        await fr.click('[data-sf="kontaktirano"]')
        await fr.click(f'[data-sur-set="{sid}|dogovoreno"]')
        await fr.wait_for_function("S.sur.d.stanja.dogovoreno>=1", timeout=20000)
        await fr.click('[data-sf="dogovoreno"]')
        await fr.fill(f'[data-sur-url="{sid}"] input', "https://portal-test.hr/clanak/")
        await fr.click(f'[data-sur-url="{sid}"] button')
        await fr.wait_for_function(f"(()=>{{const c=S.sur.d.kandidati.find(c=>c.id==='{sid}'); return c.veza && c.veza.ima===true}})()", timeout=20000)
        sv = await fr.evaluate("({txt:document.querySelector('#sur-list').textContent, n:document.querySelectorAll('#sur-list .prop').length})")
        oku3 = sv["n"] == 1 and "Poveznica postoji i prenosi autoritet" in sv["txt"]
        print(("OK  " if oku3 else "FAIL") + " suradnje: stanja se spremaju, upisana adresa s poveznicom je provjerena na serveru"); bad += 0 if oku3 else 1
        # nacrt poruke se kopira; „Napiši prijedloge” piše Claude na serveru
        await fr.click('[data-sf="otvoreni"]')
        n0 = await fr.evaluate("document.querySelectorAll('#sur-list .prop').length")
        await fr.click("#btn-sur-write")
        await fr.wait_for_function("!S.sur.busy", timeout=90000)
        await pg.wait_for_timeout(300)
        sw = await fr.evaluate("({n:document.querySelectorAll('#sur-list .prop').length, btn:document.querySelector('#btn-sur-write').textContent, meta:document.querySelector('#sur-meta').textContent, modal:document.querySelector('#modal').classList.contains('on'), err:S.sur.err})")
        oku4 = sw["n"] == n0 + 1 and sw["btn"] == "Napiši prijedloge" and "upravo" in sw["meta"] and not sw["modal"] and sw["err"] is None
        print(("OK  " if oku4 else "FAIL") + " suradnje: „Napiši prijedloge” dodaje prijedlog za kandidata koji ga nije imao:", sw); bad += 0 if oku4 else 1
        # web pretraga traži potvrdu troška; odustajanje ne šalje ništa
        dl = []
        pg.once("dialog", lambda d: (dl.append(d.message), asyncio.ensure_future(d.dismiss())))
        await fr.click("#btn-sur-find")
        await pg.wait_for_timeout(400)
        busy0 = await fr.evaluate("S.sur.busy")
        pg.once("dialog", lambda d: (dl.append(d.message), asyncio.ensure_future(d.accept())))
        await fr.click("#btn-sur-find")
        await fr.wait_for_function("!S.sur.busy && document.querySelector('#modal').classList.contains('on')", timeout=90000)
        sf = await fr.evaluate("({m:document.querySelector('#modal-box').textContent, at:S.sur.d.zadnje.trazi_at})")
        await fr.click("#nt-ok")
        oku5 = len(dl) == 2 and "0,01 $" in dl[0] and busy0 is None and ("nije našao" in sf["m"] or "Dodano novih" in sf["m"]) and sf["at"] > 0
        print(("OK  " if oku5 else "FAIL") + " suradnje: „Potraži nove tvrtke” ide tek nakon potvrde troška:", sf["m"][:80]); bad += 0 if oku5 else 1
        # uvoz izvoza iz Search Consolea iz ploče
        await fr.set_input_files("#sur-gsc-file", files=[{"name": "veze.csv", "mimeType": "text/csv", "buffer": "Site,Linking pages,Target pages\nwaze.com,4,1\nvecernji.hr,2,1\nlink-baron.com,300,2\nnovi-portal.hr,1,1\n".encode()}])
        await fr.click("#btn-sur-gsc")
        await fr.wait_for_function("!S.sur.busy && document.querySelector('#modal').classList.contains('on')", timeout=30000)
        sg = await fr.evaluate("({m:document.querySelector('#modal-box').textContent, rows:document.querySelectorAll('#sur-gsc-body tbody tr').length, txt:document.querySelector('#sur-gsc-body').textContent, t:document.querySelector('#sur-tiles .tile .v').textContent, pill:document.querySelector('#sur-list').textContent.includes('Google već vidi poveznicu')})")
        await fr.click("#nt-ok")
        oku6 = "Učitano domena: 4" in sg["m"] and sg["rows"] == 4 and "po nazivu prodavač poveznica" in sg["txt"] and "kandidat s popisa" in sg["txt"] and sg["t"] == "3" and sg["pill"]
        print(("OK  " if oku6 else "FAIL") + " suradnje: uvoz veza iz Search Consolea (prodavači poveznica označeni, kandidat koji nas povezuje označen):", sg["m"][:60], sg["t"]); bad += 0 if oku6 else 1
        # ručno dodavanje: postojeća domena je jasna greška; DR na gumb
        await fr.fill("#sur-add-d", "vecernji.hr")
        await fr.click("#btn-sur-add")
        await fr.wait_for_function("document.querySelector('#modal').classList.contains('on')", timeout=30000)
        sa = await fr.evaluate("document.querySelector('#modal-box').textContent")
        await fr.click("#nt-ok")
        at0 = await fr.evaluate("S.sur.d.dr.at")
        await pg.wait_for_timeout(1100)
        await fr.click("#btn-sur-dr")
        await fr.wait_for_function("!S.sur.busy", timeout=60000)
        sd = await fr.evaluate("({at:S.sur.d.dr.at, btn:document.querySelector('#btn-sur-dr').textContent, modal:document.querySelector('#modal').classList.contains('on')})")
        oku7 = "već na popisu" in sa and sd["at"] > at0 and sd["btn"] == "Provjeri DR sada" and not sd["modal"]
        print(("OK  " if oku7 else "FAIL") + " suradnje: postojeća domena se ne dodaje dvaput; „Provjeri DR sada” osvježava DR:", sa[:50]); bad += 0 if oku7 else 1
        await fr.evaluate("maybeExport(true)")
        await pg.wait_for_timeout(1500)
        exu = await fr.evaluate("OLR_HOSTED.api('doc/export/radar').then(d=>d.data.suradnje)")
        tool = await fr.evaluate("(()=>{const t=chatTools().find(x=>x.name==='suradnje'); const r=t?t.execute():null; return r?{k:r.kandidati.length, dr:r.dr.length, g:r.google_vidi.domena, n:chatTools().length}:null})()")
        await fr.evaluate("loadHosted()")
        await pg.wait_for_timeout(2500)
        bai = await fr.evaluate("document.querySelector('#banner-ai').textContent")
        oku8 = exu and exu["kandidata"] >= 22 and exu["google_vidi_domena"] == 4 and tool and tool["k"] >= 6 and tool["dr"] == 6 and tool["g"] == 4 and tool["n"] <= 20 and "Claude ovaj mjesec:" in bai and "$" in bai
        print(("OK  " if oku8 else "FAIL") + " suradnje: izvoz za Claudea i alat AI analitičara; Claudeov trošak u zaglavlju ploče:", exu, tool, bai); bad += 0 if oku8 else 1

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
        auto = await pg.is_checked("input[name=prijedlozi_auto]") and await pg.is_checked("input[name=indeks_auto]") and not await pg.is_checked("input[name=rank_aio_async]")
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
        leak = "sk-ant-test-1234" in html or "PRIVATE KEY" in html or "tajna-api" in html or any(x in html for x in ("meta-test-token", "page-token", "yt-test-key", "tt-secret", "tt-access", "tt-refresh"))
        print(("FAIL" if leak else "OK  ") + " postavke ne otkrivaju ključeve"); bad += 1 if leak else 0

        # postavke: društvene mreže. Polja i gumbi su u glavnom obrascu; TikTok se povezuje odlaskom na TikTok i povratkom na adresu dodatka.
        sm = await pg.evaluate("""(()=>{const f=document.querySelector('input[name=action][value=olr_save]').form; const inForm=s=>[...document.querySelectorAll(s)].every(e=>e.form===f);
            return {polja:['#meta_token','#meta_page','#ig_uzori','#yt_key','#yt_channel','#yt_teme','#tt_client_key','#tt_secret','input[name=mreze_auto]'].every(s=>document.querySelector(s)&&inForm(s)),
              gumbi:['collect_mreze','mreze_preporuke','tt_connect','tt_disconnect'].every(v=>{const b=document.querySelector('button[name=olr_then][value='+v+']'); return b&&b.form===f}), forme:document.querySelectorAll('form form').length,
              uri:[...document.querySelectorAll('code')].map(c=>c.textContent).find(t=>t.includes('/oauth/tiktok'))||'', tt:document.body.innerText.includes('● povezan'), auto:document.querySelector('input[name=mreze_auto]').checked,
              tip:document.querySelector('#meta_token').type+','+document.querySelector('#yt_key').type+','+document.querySelector('#tt_secret').type, vr:document.querySelector('#meta_token').value+document.querySelector('#yt_key').value+document.querySelector('#tt_secret').value,
              kanal:document.querySelector('#yt_channel').value, stanje:[...document.querySelectorAll('table.widefat tr')].map(r=>r.innerText.replace(/\\s+/g,' ')).filter(t=>/^(Instagram|Facebook|YouTube|TikTok|Uzori|Preporuke)/.test(t)).length}})()""")
        oksm = sm["polja"] and sm["gumbi"] and sm["forme"] == 0 and sm["uri"].endswith("/wp-json/ol-radar/v1/oauth/tiktok") and sm["tt"] and sm["auto"] and sm["tip"] == "password,password,password" and sm["vr"] == "" and "optikaljiljana" in sm["kanal"] and sm["stanje"] == 6
        print(("OK  " if oksm else "FAIL") + " postavke: društvene mreže u glavnom obrascu, ključevi se ne ispisuju, povratna adresa za TikTok:", sm["uri"], sm["stanje"]); bad += 0 if oksm else 1
        prije = await kljucevi()
        await pg.fill("#meta_token", "meta-test-token")
        async with pg.expect_navigation():
            await pg.click("button[name=olr_then][value=collect_mreze]")
        msg = await pg.inner_text(".notice")
        okcm = "Meta token spremljen" in msg and "Podaci s mreža preuzeti" in msg and "Instagram: 9 objava" in msg and "TikTok: 6 objava" in msg and await kljucevi() == prije and "meta_token" in prije and "yt_key" in prije and "tt_secret" in prije
        print(("OK  " if okcm else "FAIL") + " postavke: „Spremi i preuzmi podatke s mreža sada” (ostali ključevi netaknuti): " + msg[:150]); bad += 0 if okcm else 1
        # prekid TikTok veze traži potvrdu
        pg.once("dialog", lambda d: asyncio.ensure_future(d.accept()))
        async with pg.expect_navigation():
            await pg.click("button[name=olr_then][value=tt_disconnect]")
        msg = await pg.inner_text(".notice")
        okdc = "TikTok veza je prekinuta" in msg and "nije povezan" in await pg.inner_text("body") and await kljucevi() == prije
        print(("OK  " if okdc else "FAIL") + " postavke: prekid TikTok veze (ključevi ostaju): " + msg[:80]); bad += 0 if okdc else 1
        # „Spremi i poveži TikTok” preusmjerava na TikTokovu stranicu za odobrenje (test nema internet, pa se čita samo adresa preusmjeravanja); povratak s kodom povezuje
        async with pg.expect_response(lambda r: "admin-post.php" in r.url) as ri:
            await pg.click("button[name=olr_then][value=tt_connect]")
        rsp = await ri.value
        loc = rsp.headers.get("location", "")
        await pg.wait_for_timeout(600)
        from urllib.parse import urlparse, parse_qs
        q = parse_qs(urlparse(loc).query)
        okau = rsp.status == 302 and loc.startswith("https://www.tiktok.com/v2/auth/authorize/") and q.get("client_key") == ["tt-key"] and q.get("scope") == ["user.info.basic,user.info.stats,video.list"] and q.get("redirect_uri") == [BASE + "/wp-json/ol-radar/v1/oauth/tiktok"] and "tt-secret" not in loc
        print(("OK  " if okau else "FAIL") + " postavke: „Spremi i poveži TikTok” šalje na TikTok s dopuštenjima samo za čitanje"); bad += 0 if okau else 1
        await pg.goto(BASE + "/wp-json/ol-radar/v1/oauth/tiktok?code=kod-123&state=" + (q.get("state") or ["x"])[0])
        msg = await pg.inner_text(".notice")
        okcb = "ol-radar-postavke" in pg.url and "TikTok je povezan: preuzeto 6 videa" in msg and "● povezan" in await pg.inner_text("body") and await kljucevi() == prije
        print(("OK  " if okcb else "FAIL") + " postavke: povratak s TikToka povezuje račun i vraća u Postavke: " + msg[:80]); bad += 0 if okcb else 1
        await pg.goto(BASE + "/wp-json/ol-radar/v1/oauth/tiktok?code=kod-123&state=nepoznato")
        msg = await pg.inner_text(".notice")
        okcb2 = "nije dovršeno" in msg and "● povezan" in await pg.inner_text("body")
        print(("OK  " if okcb2 else "FAIL") + " postavke: povratak s nepoznatim stateom ne mijenja vezu: " + msg[:70]); bad += 0 if okcb2 else 1
        # postavke: suradnje u glavnom obrascu, Claude trošak, provjera DR-a i potvrda prije web pretrage
        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar-postavke")
        ss = await pg.evaluate("""(()=>{const f=document.querySelector('input[name=action][value=olr_save]').form; const inForm=s=>[...document.querySelectorAll(s)].every(e=>e.form===f);
            const h=[...document.querySelectorAll('h2')].find(x=>x.textContent==='Claude trošak'); const t=h&&h.nextElementSibling&&h.nextElementSibling.nextElementSibling;
            return {polja:['input[name=suradnje_auto]','input[name=suradnje_trazi]','input[name=suradnje_dr]','input[name=suradnje_max]','input[name=suradnje_novih]','#suradnje_fokus','#konkurenti','button[name=olr_then][value=suradnje_pisi]','button[name=olr_then][value=suradnje_trazi]','button[name=olr_then][value=suradnje_dr]'].every(s=>document.querySelector(s)&&inForm(s)),
                auto:document.querySelector('input[name=suradnje_auto]').checked, trazi:document.querySelector('input[name=suradnje_trazi]').checked, dr:document.querySelector('input[name=suradnje_dr]').checked,
                konk:document.querySelector('#konkurenti').value, danas:h?h.nextElementSibling.textContent:'', red:t&&t.tagName==='TABLE'?t.querySelector('tbody tr').textContent:'', glave:t&&t.tagName==='TABLE'?[...t.querySelectorAll('th')].map(x=>x.textContent).join(','):'',
                stanje:document.body.innerText.includes('Prijedlozi suradnje') && document.body.innerText.includes('Poveznice iz Search Consolea (uvoz)')}})()""")
        okss = ss["polja"] and ss["auto"] and not ss["trazi"] and not ss["dr"] and "optika-anda.com" in ss["konk"] and "Danas:" in ss["danas"] and "$" in ss["danas"] and "dnevnog limita" in ss["danas"] and ss["glave"] == "Mjesec,Poziva,Ulazni tokeni,Izlazni tokeni,Web pretrage,Trošak" and "$" in ss["red"] and ss["stanje"]
        print(("OK  " if okss else "FAIL") + " postavke: suradnje u glavnom obrascu (web pretraga i DR zadano isključeni); tablica „Claude trošak”:", ss["danas"][:90], "|", ss["red"][:60]); bad += 0 if okss else 1
        prije = await kljucevi()
        dl = []
        pg.once("dialog", lambda d: (dl.append(d.message), asyncio.ensure_future(d.dismiss())))
        await pg.click("button[name=olr_then][value=suradnje_trazi]")
        await pg.wait_for_timeout(500)
        okpt = len(dl) == 1 and "0,01 $" in dl[0] and "ol-radar-postavke" in pg.url and not await pg.query_selector(".notice-success")
        print(("OK  " if okpt else "FAIL") + " postavke: web pretraga traži potvrdu troška, odustajanje ne šalje ništa"); bad += 0 if okpt else 1
        async with pg.expect_navigation():
            await pg.click("button[name=olr_then][value=suradnje_dr]")
        msg = await pg.inner_text(".notice")
        okdr = "DR provjeren" in msg and "naš DR 1,4" in msg and await kljucevi() == prije
        print(("OK  " if okdr else "FAIL") + " postavke: „Spremi i provjeri DR sada” (ključevi netaknuti): " + msg[:110]); bad += 0 if okdr else 1
        await pg.screenshot(path=f"{OUT}/hosted_postavke_suradnje.png", full_page=True)

        await pg.goto(BASE + "/wp-admin/admin.php?page=ol-radar-postavke")
        html = await pg.content()
        leak = any(x in html for x in ("meta-test-token", "page-token", "yt-test-key", "tt-secret", "tt-access", "tt-refresh"))
        print(("FAIL" if leak else "OK  ") + " postavke ne otkrivaju tokene društvenih mreža"); bad += 1 if leak else 0
        await pg.screenshot(path=f"{OUT}/hosted_postavke.png", full_page=True)

        print("greške u pregledniku:", errs or "nema"); bad += len(errs)
        await b.close()
    print("SVE PROŠLO" if not bad else f"NEUSPJELO: {bad}")
    sys.exit(1 if bad else 0)

asyncio.run(main())
