# OL Radar

Vlastiti SEO i društveni radar Optike Ljiljana (optikaljiljana.hr). Mali, ali dublji Ahrefs: mjeri naš web, konkurenciju i trendove iz izvora koje kontroliramo. Claude ga koristi kao podlogu za svaku analizu i promjenu na webu.

**Aplikacija (Claude artefakt):** https://claude.ai/artifact/FwKJowHqdJUASipjeAkoBT

## Što je u repozitoriju

| Mapa | Što je |
|---|---|
| `app/index.html` | Radar: jedna HTML stranica (Claude artefakt, runtime contract 0.2.96) |
| `app/hosted-shim.js` | Zamjena za claude.ai runtime kad ista ploča radi na hostingu (wp-admin) |
| `wordpress/ol-radar-most/` | WordPress dodatak (2.5.0): Radar na hostingu (ploča, Google API, DataForSEO, Claude API) i most za Claudea (MCP, samo čitanje) |
| `tests/app/` | Headless test aplikacije s lažnim `window.claude` (`mock.js`) |
| `tests/wordpress/` | Lokalni WordPress 6.9 + SQLite + MCP Adapter i MCP testovi dodatka |
| `tools/` | `build-hosted.py` (gradi `wordpress/ol-radar-most/app/radar.html`), provjera sintakse za PHP 7.4 |
| `docs/` | Upute za Claudea i bilješke o arhitekturi |

## Arhitektura
Radar postoji u dvije verzije s istim kodom (`app/index.html`):

1. **Radar na hostingu (glavna verzija od 12. 11. 2026.):** WordPress dodatak u wp-adminu. Podatke skuplja server, bez claude.ai.
2. **Radar na claude.ai (artefakt):** podatke čita preko claude.ai konektora.

**Glavni izvori od 13. 11. 2026.:** Search Console za naš web i DataForSEO za tržište i konkurente. Ahrefs je samo arhiva (pretplata ističe 30. 11. 2026.).

| Područje | Na hostingu | Na claude.ai |
|---|---|---|
| Naš web (klikovi, pozicije, CTR) | Google Search Console API, servisni račun | OpenSEO, rezerva Supermetrics |
| Pozicije | DataForSEO (tjedno, mobitel, Zagreb) | OpenSEO tracker |
| Tržište: karta, profili, recenzije, obujam, ključne riječi i poveznice konkurenata, spominjanja, brzina, AI asistenti | DataForSEO, moduli po izboru (od 2.5.0) | čita s hostinga (`ol-radar/radar-trziste`) |
| Web iznutra (audit, linkovi, Rank Math) | izravno iz dodatka | WordPress most preko MCP-a |
| Promet konkurenata | model: SERP-ovi iz mjerenja i arhive × volumen × naša krivulja CTR-a | isto, SERP-ovi iz arhive |
| Društvene mreže | sljedeća faza (Meta API) | vidIQ |
| Povijest konkurenata | arhiva Ahrefsa (uvoz) | arhiva Ahrefsa |
| Pohrana | tablica `ol_radar_docs` | baza artefakta |
| AI | Anthropic API (ključ na serveru) | `claude.use("sample")` |

**Claude izvan ploče** (claude.ai, desktop, mobitel) čita Radar s hostinga preko konektora „Optika Ljiljana web”, sposobnostima `ol-radar/radar-*`.

**Baza** (zbirke): `export/radar` (sažetak za Claudea), `snap`, `tjedno`, `issues`, `fixes`, `plan`, `kp`, `arch_*`, `cfg`. Na hostingu još `gsc`, `rank`, `rank_run`, `serp`, `trziste`, `trziste_run`.

**Tjedni zakazani zadatak „OL Radar – tjedno punjenje”** (ponedjeljak 05:38) puni `tjedno/<datum>` u bazi artefakta.

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

# izdavanje dodatka: svježe okruženje, svi testovi (i test nadogradnje), zip
bash tools/release.sh

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
- **Nova verzija dodatka ne smije dirati spremljene ključeve, postavke ni podatke.** Zip se gradi samo s `tools/release.sh`; on pokreće i `tests/wordpress/upgrade_test.sh` (nadogradnja sa svake izdane verzije). Nakon izdanja dodaj redak „verzija commit” u `tests/wordpress/izdanja.txt`, da sljedeći test pokrije i ovo izdanje. Promjena načina šifriranja ili imena opcija traži migraciju koja čita stari oblik.

## Plan
Cijeli plan s prioritetima, troškovima i izvorima: `docs/Plan-razvoja.md`.
- **v3:**
  - prikaz WP audita i tjednih zapisa u aplikaciji;
  - vlastito praćenje pozicija (OpenSEO);
  - procjena prometa konkurenata (volumen × naša CTR krivulja iz GSC-a).
- **Dodatak, faza 2:** upis Rank Math naslova, opisa i fokus riječi, s dnevnikom i vraćanjem.
- **Dodatak, faza 3:** skice novih tekstova (nikad automatska objava).
