# OL Radar

Vlastiti SEO i društveni radar Optike Ljiljana (optikaljiljana.hr). Mali, ali dublji Ahrefs: mjeri naš web, konkurenciju i trendove iz izvora koje kontroliramo. Claude ga koristi kao podlogu za svaku analizu i promjenu na webu.

**Aplikacija (Claude artefakt):** https://claude.ai/artifact/FwKJowHqdJUASipjeAkoBT

## Što je u repozitoriju

| Mapa | Što je |
|---|---|
| `app/index.html` | Radar: jedna HTML stranica (Claude artefakt, runtime contract 0.2.96) |
| `wordpress/ol-radar-most/` | WordPress dodatak „OL Radar – most za Claudea” (samo čitanje, MCP) |
| `tests/app/` | Headless test aplikacije s lažnim `window.claude` (`mock.js`) |
| `tests/wordpress/` | Lokalni WordPress 6.9 + SQLite + MCP Adapter i MCP testovi dodatka |
| `tools/` | Pomoćne skripte (provjera sintakse za PHP 7.4) |
| `docs/` | Upute za Claudea i bilješke o arhitekturi |

## Arhitektura

| Područje | Izvor |
|---|---|
| Naš web (klikovi, pozicije, CTR) | Google Search Console preko OpenSEO-a, bez kredita. Rezerva: Supermetrics (dnevna kvota), zatim Ahrefs GSC uvid |
| Potražnja i sezonalnost | Google Keyword Planner preko Supermetricsa |
| Web iznutra (audit, linkovi, Rank Math) | WordPress dodatak preko MCP-a (OAuth WP Rocketa) |
| Pozicije | OpenSEO (DataForSEO): tjedni tracker, 27 riječi, Zagreb, mobitel |
| Promet konkurenata | Vlastiti model: arhivirani SERP-ovi × volumen × naša GSC krivulja CTR-a |
| Društvene mreže | vidIQ (Instagram, YouTube) |
| Povijest konkurenata | Jednokratna arhiva Ahrefsa (do kraja studenog 2026.) |
| Pohrana | Baza artefakta (`claude.use("db")`) |
| AI prijedlozi | `claude.use("sample")` |

**Baza** (zbirke): `export/radar` (sažetak za Claudea), `snap`, `tjedno`, `issues`, `fixes`, `plan`, `kp`, `arch_*`, `cfg`.

**Tjedni zakazani zadatak „OL Radar – tjedno punjenje”** (ponedjeljak 05:38) puni `tjedno/<datum>`.

## Objava aplikacije
Artefakt se objavljuje alatom za artefakte (slug `78f5630e-c158-4ddc-b32b-b3b711130c3e`). Redoslijed:
1. Ako kod koristi novi konektor ili alat, prvo `declare_capabilities` (uz `base_version` žive verzije).
2. Zatim `publish_files` s `app/index.html` kao `index.html`.

## Testovi
```bash
# aplikacija (treba Playwright i Chrome)
python3 tests/app/run_e2e.py

# WordPress dodatak (treba PHP 8 s pdo_sqlite, dom, mbstring)
WPTEST_DIR=/tmp/wptest bash tests/wordpress/setup.sh
WPTEST_DIR=/tmp/wptest bash tests/wordpress/run.sh

# sintaksa novija od PHP 7.4
php tools/php74-compat.php wordpress/ol-radar-most
```

## Pravila razvoja
- Sve na hrvatskom: sučelje, dokumentacija i poruke.
- Vizualni identitet OL:
  - boje: crna #0a0a0a, svijetla #fafaf8, zlatna #c8a96e kao jedini naglasak;
  - fontovi: Playfair Display i DM Sans;
  - bez emojija i ikona.
- Samo provjereni podaci. Kad izvor ne radi, aplikacija to jasno pokaže i ne izmišlja brojke.
- WordPress dodatak ostaje samo za čitanje. Svako buduće upisivanje (faza 2: Rank Math meta) ide kao zasebna sposobnost s dnevnikom, vraćanjem i odobrenjem.
- Prije objave obavezno: `node --check` na skripti iz `app/index.html`, `tests/app/run_e2e.py` i, za dodatak, `tests/wordpress`.

## Plan
- **v3:**
  - prikaz WP audita i tjednih zapisa u aplikaciji;
  - vlastito praćenje pozicija (OpenSEO);
  - procjena prometa konkurenata (volumen × naša CTR krivulja iz GSC-a).
- **Dodatak, faza 2:** upis Rank Math naslova, opisa i fokus riječi, s dnevnikom i vraćanjem.
- **Dodatak, faza 3:** skice novih tekstova (nikad automatska objava).
