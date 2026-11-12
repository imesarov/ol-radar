#!/usr/bin/env python3
"""Gradi nadzornu ploču za hosting: app/index.html + app/hosted-shim.js -> wordpress/ol-radar-most/app/radar.html.

Isti kod kao Radar na claude.ai; shim zamjenjuje claude.ai runtime, a dodatak pri prikazu
umjesto /*OLR_BOOT*/ ubacuje window.OLR_HOSTED (REST adresa, nonce, poveznice).
"""
import pathlib, sys

root = pathlib.Path(__file__).resolve().parent.parent
src = (root / "app" / "index.html").read_text(encoding="utf-8")
shim = (root / "app" / "hosted-shim.js").read_text(encoding="utf-8")
if "/*OLR_BOOT*/" in src:
    sys.exit("index.html već sadrži /*OLR_BOOT*/")
i = src.rfind("<script>")
if i < 0:
    sys.exit("nema glavne <script> oznake")
out = src[:i] + "<script>/*OLR_BOOT*/</script>\n<script>\n" + shim + "\n</script>\n" + src[i:]
dst = root / "wordpress" / "ol-radar-most" / "app" / "radar.html"
dst.parent.mkdir(parents=True, exist_ok=True)
dst.write_text(out, encoding="utf-8")
print(f"{dst.relative_to(root)}: {len(out):,} znakova")
