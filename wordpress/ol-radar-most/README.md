# OL Radar – most za Claudea (v2.0.1)

WordPress dodatak koji radi dvije stvari:

1. **OL Radar na hostingu (novo u 2.0):** nadzorna ploča u wp-adminu (OL Radar → Nadzorna ploča). Podatke skuplja sam server, i kad tvoje računalo i claude.ai nisu otvoreni:
   - Google Search Console preko servisnog računa;
   - pozicije preko DataForSEO-a;
   - audit weba iznutra.
2. **Most za Claudea:** pristup webu i Radaru **samo za čitanje** preko MCP-a (konektor „Optika Ljiljana web” u claude.ai, Claude desktop aplikaciji i na mobitelu).

Sadržaj weba (stranice, objave, postavke, dodatke, teme, korisnike) dodatak samo čita i ne mijenja.

## Nadzorna ploča na hostingu
- **Moduli:** isti kao Radar na claude.ai, jer dijele kod. Ploča se gradi iz `app/index.html` + `app/hosted-shim.js` u `app/radar.html` (`tools/build-hosted.py`).
  - Pregled, Tjedno, Google, Pozicije;
  - Ključne riječi, Planer, Konkurencija (model iz SERP-ova), Trendovi;
  - Audit weba, Popravci i AI, Arhiva, Kako radi.
- **Pristup:** samo administratori (`manage_options`). Ploča se otvara preko cijelog zaslona na `wp-admin/admin.php?page=ol-radar`.
- **Podaci:** u tablici `{prefix}ol_radar_docs` (zbirka + id + JSON), u istom obliku kao baza Radara na claude.ai.
- **Claude u ploči:** Anthropic Messages API preko servera; ključ ne napušta server. Alate (podatke Radara) izvršava preglednik.
- **Društvene mreže** (Instagram, YouTube) na hostingu dolaze u sljedećoj fazi, preko Metinog API-ja.

### Izvori i trošak

| Izvor | Kako | Trošak |
|---|---|---|
| Google Search Console | servisni račun, JWT RS256, opseg `webmasters.readonly`, svakih 12 h | 0 |
| Pozicije | DataForSEO SERP API, standardni red, mobitel, Zagreb, do 40. mjesta | 0,0006 $ po 10 rezultata po riječi; 27 riječi ≈ 0,065 $ po mjerenju |
| Audit iznutra | crawl dodatka | 0 |
| Claude u ploči | Anthropic API; Ultra plan uključuje mjesečne API kredite | po upotrebi, uz dnevni limit poziva |

- **Mjerenje pozicija** pokreće se samo na dva načina, oba uz tvoju odluku:
  - gumbom „Izmjeri pozicije sada” u Postavkama;
  - uključenim tjednim rasporedom (nedjelja navečer, nadoknada nakon 8 dana).
- **Svako mjerenje** usput sprema top 20 organskih rezultata po riječi, za model konkurencije.
- **Stvarni trošak** svakog mjerenja sprema se iz odgovora DataForSEO-a.

### Postavke (OL Radar → Postavke)
1. **Google Search Console:**
   - Zalijepi sadržaj JSON ključa servisnog računa.
   - Svojstvo upiši točno kao u Search Consoleu: `https://www.optikaljiljana.hr/` ili `sc-domain:optikaljiljana.hr`.
   - Servisni račun dodaj u Search Consoleu (Postavke → Korisnici i dopuštenja, ograničeni pristup).
   - U Google Cloud projektu uključi „Google Search Console API”.
2. **DataForSEO:** API login i API lozinka (DataForSEO → API Access), ciljane riječi, lokacija, uređaj, dubina, tjedni raspored.
3. **Claude:**
   - Anthropic API ključ iz Claude Console, model i dnevni limit poziva.
   - Za mjesečne kredite Ultra plana poveži Console organizaciju u claude.ai (Postavke → Billing → API credits).
4. **Provjere:** gumbi „Spremi i provjeri…” uz svaki odjeljak (Google, DataForSEO besplatno, Claude). Najprije spremaju upisano, pa provjeravaju; rezultat se vidi na vrhu stranice.
5. **Uvoz iz Radara na claude.ai:** u claude.ai Radaru otvori Arhiva → „Izvezi za hosting”, pa ovdje učitaj datoteku. Uvozi arhivu Ahrefsa, nalaze, plan, snimke i postavke. Podatke izvora (gsc, rank, serp) uvoz ne dira.

### Ključevi
- U bazi se spremaju šifrirano (libsodium, ključ izveden iz WordPressovih soli) i nikad se ne vraćaju u preglednik. Stranica Postavke prikazuje samo adresu servisnog računa ili zadnja 4 znaka.
- **Sigurnije:** konstante u `wp-config.php`, koje imaju prednost:
  ```php
  define( 'OLR_GSC_SA_FILE', '/home/korisnik/olr/servisni-racun.json' ); // izvan public_html
  define( 'OLR_DFS_LOGIN', '...' );
  define( 'OLR_DFS_PASSWORD', '...' );
  define( 'OLR_ANTHROPIC_KEY', '...' );
  ```
- **Promjena soli:** ako se promijene WordPress soli, ključe iz baze treba ponovno upisati.

### Cron
- **Jednom na sat** (`olr_radar_tick`):
  - Google podaci, ako su stariji od 12 sati;
  - rezultati mjerenja u tijeku;
  - tjedno mjerenje, ako je uključeno.
- **Pouzdanije od WordPressovog crona** je cron na hostingu, svakih 15 minuta:
  `wget -q -O - https://www.optikaljiljana.hr/wp-cron.php?doing_wp_cron >/dev/null 2>&1`

### REST (ol-radar/v1, samo administratori, WordPressov nonce)

| Ruta | Što radi |
|---|---|
| `GET /podaci` | sve za ploču (GSC, pozicije, SERP-ovi, popis sadržaja, audit, stanje) |
| `GET /docs/{zbirka}` | dokumenti zbirke |
| `GET\|PUT\|DELETE /doc/{zbirka}/{id}` | jedan dokument; pisanje je dopušteno samo u zbirke ploče: issues, fixes, plan, kp, snap, cfg, trends, export, tjedno |
| `POST /ai` | Claude (Anthropic API) |
| `POST /osvjezi` | `gsc`, `rank` (pokupi rezultate) ili `crawl`; bez troška |

## Most za Claudea (MCP)

### Sposobnosti (samo čitanje)

| Sposobnost | Što vraća |
|---|---|
| `ol-radar/pregled-weba` | verzije, tema, dodaci, vidljivost, stanje crawla |
| `ol-radar/popis-sadrzaja` | stranice, članci, proizvodi s Rank Math podacima i sažetkom crawla |
| `ol-radar/stranica` | jedna stranica: oznake, H1, tekst, slike, linkovi, schema, ulazni linkovi |
| `ol-radar/audit` | nalazi audita iznutra |
| `ol-radar/interni-linkovi` | graf linkova (sadržaj ili navigacija, dofollow ili nofollow) |
| `ol-radar/osvjezi-crawl` | sljedeća serija stranica (mijenja samo cache dodatka) |
| `ol-radar/radar-sazetak` (novo) | sažetak Radara s hostinga (`export/radar`) i stanje izvora |
| `ol-radar/radar-gsc` (novo) | upiti iz Search Consolea za 28 ili 7 dana, s filtrom |
| `ol-radar/radar-pozicije` (novo) | pozicije, povijest i top 10 konkurenata po riječi |

### Crawl
- Radi iznutra: dodatak dohvaća vlastite stranice s potpisanim parametrom, pa WP Rocket i LiteSpeed taj prikaz ne spremaju.
- Svaki sat obradi do 10 novih ili promijenjenih stranica, a ostale ponovno nakon 7 dana.
- **Od 1.0.1:** sadržaj `<script>` i `<style>` prazni se prije parsiranja (lažni linkovi iz JS predložaka); JSON-LD se i dalje čita.

## Zahtjevi
- WordPress 6.9 ili noviji (Abilities API).
- PHP 7.4 ili noviji, s ekstenzijama openssl i sodium.
- MCP Adapter (na optikaljiljana.hr dolazi s WP Rocketom).

## Instalacija i nadogradnja
1. Napravi backup weba u cPanelu.
2. **Dodaci → Dodaj novi → Učitaj dodatak**, odaberi `ol-radar-most-2.0.1.zip` → **Zamijeni postojeći**. Ako je dodatak deaktiviran, aktiviraj ga.
3. U izborniku se pojavljuje **OL Radar** s podstranicama Nadzorna ploča i Postavke. Stara stranica Alati → OL Radar (crawl) ostaje.

## Spajanje s Claudeom (claude.ai, desktop, mobitel)
1. **Postavke → Konektori → Dodaj prilagođeni konektor**:
   - naziv `Optika Ljiljana web`;
   - URL `https://www.optikaljiljana.hr/wp-json/mcp/mcp-oauth-server`.
2. **Prijava:** pri spajanju se prijavi kao `claude-radar` (uloga „OL Radar – samo čitanje”), ne kao administrator. Najsigurnije je u privatnom prozoru.

S tom ulogom Claude može pokretati samo sposobnosti `ol-radar/*`. U nadzornu ploču i Postavke ne može ući, jer one traže administratora.

## Sigurnost
- **Sposobnosti** traže pravo `ol_radar_read`; bez prijave vraćaju 401.
- **Ploča, REST i Postavke** traže `manage_options` i WordPressov nonce.
- **Ključevi** su šifrirani, nikad se ne vraćaju u preglednik i nisu dostupni ni preko MCP-a.
- **Podaci izvora** (gsc, rank, serp) ne mogu se mijenjati iz preglednika ni uvozom.
- **Claude u ploči** ima dnevni limit poziva i bilježi potrošnju tokena po mjesecu.

## Uklanjanje
- **Deaktivacija** gasi cron.
- **Brisanje dodatka** uklanja tablicu crawla, postavke, ključeve, ulogu i pravo.
- **Podaci Radara** (`ol_radar_docs`: povijest, arhiva, plan) ostaju, osim ako je u `wp-config.php` definirano `OLR_DELETE_DATA`.

## Testirano (lokalno: WordPress 6.9, SQLite, MCP Adapter 0.7.1, PHP 8.3)
- **Postojeći testovi:** MCP spajanje, prava (anonimno 401, pretplatnik odbijen, čitač dopušten), crawl i audit na 311 stranica.
- **Radar na hostingu (`tests/wordpress/radar_test.php`, 58 provjera)** s lažnim Google, DataForSEO i Anthropic odgovorima:
  - JWT potpis provjeren javnim ključem;
  - keširanje tokena;
  - poruke za 403 i neuključen API;
  - dva mjerenja s redom čekanja, prethodnom pozicijom i poviješću;
  - trošak;
  - Claude s alatom i dnevni limit;
  - REST prava i zaštićene zbirke;
  - uvoz;
  - MCP sposobnosti kao korisnik samo za čitanje.
- **Nadzorna ploča u headless Chromeu (`tests/wordpress/hosted_e2e.py`):**
  - prijava u wp-admin;
  - svi moduli;
  - spremanje stanja nalaza;
  - AI chat s alatom;
  - `export/radar`;
  - Postavke ne otkrivaju ključeve.

## Plan
- Instagram i YouTube na hostingu (Meta Graph API).
- Faza 2 upisa: Rank Math naslovi, opisi i fokus riječi, uz dnevnik promjena, vraćanje i odobrenje za svaki upis.
