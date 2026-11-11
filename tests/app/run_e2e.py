"""Headless test aplikacije (app/index.html) s lažnim window.claude (mock.js): živi način i način bez Ahrefsa.

Upotreba:  pip install playwright && python3 tests/app/run_e2e.py
Preglednik: CHROME=/put/do/chrome (zadano /usr/bin/google-chrome)
"""
import asyncio
import os
import re
import sys

from playwright.async_api import async_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
CHROME = os.environ.get("CHROME", "/usr/bin/google-chrome")


def build_test_page():
    """Kopija aplikacije s ubačenim mock.js prije prvog inline <script>."""
    src = open(os.path.join(REPO, "app", "index.html"), encoding="utf-8").read()
    m = re.search(r"<script(?![^>]*\bsrc=)[^>]*>", src)
    out = src[: m.start()] + '<script src="mock.js"></script>\n' + src[m.start():]
    path = os.path.join(HERE, "test.html")
    open(path, "w", encoding="utf-8").write(out)
    return path


async def main():
    page_path = build_test_page()
    bad = 0
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
        for mode in ["", "?off"]:
            pg = await b.new_page(viewport={"width": 1360, "height": 900})
            errs = []
            pg.on("pageerror", lambda e: errs.append("PAGEERROR " + str(e)))
            pg.on("console", lambda m: errs.append("console.error: " + m.text) if m.type == "error" else None)
            await pg.goto("file://" + page_path + mode + "#pregled")
            await pg.wait_for_timeout(2500)
            if not mode:
                await pg.evaluate("location.hash='#arhiva'"); await pg.wait_for_timeout(300)
                await pg.click("#btn-arch-run"); await pg.wait_for_timeout(6000)
                print("ARHIVA:", (await pg.inner_text("#arch-status"))[:200])
            for v in ["google", "kljucne-rijeci", "planer", "konkurencija", "trendovi", "audit", "drustvene-mreze", "popravci", "metodologija", "pregled"]:
                await pg.evaluate(f"location.hash='#{v}'"); await pg.wait_for_timeout(350)
                if v == "kljucne-rijeci":
                    await pg.click("#kw-form button[type=submit]"); await pg.wait_for_timeout(900)
                if v == "planer":
                    btns = await pg.query_selector_all("[data-padd]")
                    if btns:
                        await btns[0].click(); await pg.wait_for_timeout(300)
                if v == "konkurencija":
                    await pg.click("#btn-compare"); await pg.wait_for_timeout(700)
                if v == "trendovi":
                    await pg.click("#btn-trends"); await pg.wait_for_timeout(1200)
            exp = await pg.evaluate("(()=>{const e=(window.__store||{})['export/radar']; return e? Object.keys(e).length : 0})()")
            print("NAČIN", mode or "uživo", "| export polja:", exp, "| greške:", errs or "nema")
            bad += len(errs) + (0 if exp else 1)
            await pg.close()
        await b.close()
    sys.exit(1 if bad else 0)


asyncio.run(main())
