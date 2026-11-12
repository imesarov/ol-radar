# OL Radar – most za Claudea (v2.1.1)

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
- **Pristup:** samo administratori (`manage_options`). Ploča se otvara na `wp-admin/admin.php?page=ol-radar`, u radnom dijelu wp-admina (WP izbornik ostaje lijevo). Svijetla tema je zadana, a tamna je na prekidaču u izborniku ploče; izbor se pamti u pregledniku.
- **Podaci:** u tablici `{prefix}ol_radar_docs` (zbirka + id + JSON), u istom obliku kao baza Radara na claude.ai.
- **Claude u ploči:** Anthropic Messages API preko servera; ključ ne napušta server. Alate (podatke Radara) izvršava preglednik.
- **Društvene mreže** (Instagram, YouTube) na hostingu dolaze u sljedećoj fazi, preko Metinog API-ja.

### Dnevni prijedlozi izmjena (od 2.1.0)
- **Što radi:** kad stignu novi Google podaci, server sam prođe upite, pozicije i crawl weba (`OLR_Analiza`) i za najvažnije nalaze pripremi gotov prijedlog izmjene (`OLR_Prijedlozi`). Modul **Prijedlozi** u ploči.
- **Vrste nalaza:**
  - naslov i opis ne privlače klik (visoka pozicija, nizak CTR);
  - upiti blizu vrha (pozicija 4–15);
  - kanibalizacija (isti upit, dvije stranice u zadnjih 7 dana);
  - tjedni pad upita;
  - ciljana riječ pala, ispala ili u rezultatu stoji kriva stranica;
  - tehnički nalazi crawla s konkretnim popravkom;
  - upiti bez prave stranice (novi sadržaj).
- **Brojke** u prijedlogu uvijek su iz podataka (Search Console, mjerenje pozicija, crawl). „Sada” (sadašnji naslov, opis, H1) dolazi iz crawla, ne iz odgovora Claudea.
- **Tekst izmjene** (novi SEO naslov, meta opis, odlomak, tekst internog linka) piše Claude preko Anthropic API ključa. Od 2.1.1 to je jedan kratak poziv po prijedlogu, s obaveznim oblikom odgovora (alat `spremi_prijedlog`), pa se odgovor ne prekida zbog duljine. Odgovor se provjerava: nepoznata polja i linkovi sa stranica kojih nema u popisu se odbacuju, HTML se uklanja, predug naslov ili opis dobiva upozorenje.
- **Bez ključa ili ako poziv ne uspije:** prijedlog svejedno nastaje, s koracima složenim iz podataka. Tekst se dopisuje pri sljedećem satnom prolazu, najviše tri pokušaja po prijedlogu; u ploči piše „tekst izmjene se još piše”.
- **„Analiziraj sada” ne čeka Claudea:** prijedlozi se pojave odmah, tekst se piše najviše 20 s u samom zahtjevu, a ostatak u pozadini; ploča se za to vrijeme sama osvježava.
- **Što ne ulazi u analizu (od 2.1.1):**
  - upit koji nije o temi stranice (riječi upita moraju se naći u naslovu, opisu, naslovima na stranici, fokus riječi ili adresi; padeži i dijakritika se poklapaju);
  - brend (i po korijenu, npr. „ljiljan”) te upiti s popisa „Zanemari upite” u Postavkama (npr. imena drugih optika);
  - prijedlog naslova i opisa za stranicu mijenjanu u zadnjih 14 dana (brojke još opisuju staro stanje);
  - tjedni pad bez stvarno izgubljenih klikova.
- **Jedan po jedan:** stranica može imati najviše jedan otvoren prijedlog (osim tehničkih).
- **Bez ponavljanja:** nalaz s otvorenim prijedlogom se ne ponavlja; odbijeni se ne vraća 45 dana, provedeni 28 dana. Novi prijedlog čiji nalaz više ne postoji označava se kao istekao: odmah ako još nema Claudeov tekst, inače nakon 21 dan.
- **Procjena učinka** je namjerno oprezna (80 % opće krivulje CTR-a ili prosjek s vlastitom) i uvijek označena kao procjena.
- **Stanja:** novo → prihvaćeno → provedeno, ili odbijeno. Sprema ih ploča (`prijedlozi_stanje`); same prijedloge piše samo server.
- **Web se ne mijenja.** Prijedlozi su za odluku vlasnika.
- **Postavke:** automatska analiza (uključeno), najviše novih dnevno (5), riječi brenda i popis „Zanemari upite” (upiti s njima ne ulaze u analizu). Gumb „Analiziraj sada” u ploči i u Postavkama, najviše 6 ručnih pokretanja dnevno.

### Izvori i trošak

| Izvor | Kako | Trošak |
|---|---|---|
| Google Search Console | servisni račun, JWT RS256, opseg `webmasters.readonly`, svakih 12 h | 0 |
| Pozicije | DataForSEO SERP API, standardni red, mobitel, Zagreb, do 40. mjesta | 0,0006 $ po 10 rezultata po riječi; 27 riječi ≈ 0,065 $ po mjerenju |
| Audit iznutra | crawl dodatka | 0 |
| Claude u ploči | Anthropic API; Ultra plan uključuje mjesečne API kredite | po upotrebi, uz dnevni limit poziva |

- **Mjerenje pozicija** pokreće se samo uz tvoju odluku:
  - gumbom „Izmjeri pozicije sada” u modulu Pozicije (od 2.1.1; prije slanja traži potvrdu troška, a prikaz se sam osvježava dok rezultati ne stignu) ili u Postavkama;
  - uključenim tjednim rasporedom (nedjelja navečer, nadoknada nakon 8 dana).
- **Svako mjerenje** usput sprema top 20 organskih rezultata po riječi, za model konkurencije.
- **Stvarni trošak** svakog mjerenja sprema se iz odgovora DataForSEO-a.
- **Adrese s parametrima** (`?utm_…`, `?rwg_token=…`) u Google podacima pribrajaju se pravoj stranici (od 2.1.1).

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
- **Promjena soli:** ako se promijene WordPress soli, ključe iz baze treba ponovno upisati. Zapis koji se ne može dešifrirati ne briše se sam; Postavke ga jasno označe.

### Ključevi i nadogradnja (pravilo od 2.1.0)
- **Nadogradnja nikad ne dira ključeve.** Ključ se mijenja samo izričitim unosom ili gumbom „obriši” u Postavkama.
- **Brisanje dodatka** (pa ponovna instalacija) također čuva postavke, šifrirane ključeve i podatke Radara. Uklanjaju se samo ako je u `wp-config.php` definirano `OLR_DELETE_DATA`.
- **Provjera nakon nadogradnje:** dodatak vodi popis spremljenih ključeva (`olr_keys_seen`). Ako nakon promjene verzije neki nedostaje ili je nečitljiv, Postavke pokažu crveno upozorenje, a isto vidi i Claude (`stanje.nadogradnja` u `ol-radar/radar-sazetak`).
- **Test prije svakog izdanja:** `tests/wordpress/upgrade_test.sh` instalira svaku izdanu verziju 2.x (iz povijesti repozitorija, prema `tests/wordpress/izdanja.txt`), spremi ključeve njezinim kodom, zamijeni mapu dodatka novom i provjeri da su ključevi, postavke i podaci isti; zatim deaktivacija/aktivacija te brisanje i ponovna instalacija. Zip se gradi samo preko `tools/release.sh`, koji bez tog testa ne prolazi.

### Veza prema Googleu (od 2.1.0)
- Pozivi idu kao HTTP/1.1 (WordPress zadano šalje 1.0), s čekanjem do 30 s.
- Ako poziv ostane bez odgovora, ponavlja se prisilno preko IPv4, a zatim na rezervnoj Googleovoj adresi (`searchconsole.googleapis.com`). Kombinacija koja radi se zapamti.
- Kad ništa ne prođe, poruka navodi svaki pokušaj i gdje je zapelo (spajanje, TLS, čekanje odgovora). Ista dijagnostika, bez ključeva i tokena, vidljiva je Claudeu u `stanje.izvori.gsc.dijagnostika`.
- Nakon neuspjeha cron pokušava ponovno tek za 3 sata. Neuspjelo preuzimanje ne briše postojeće podatke.

### Cron
- **Jednom na sat** (`olr_radar_tick`):
  - Google podaci, ako su stariji od 12 sati;
  - rezultati mjerenja u tijeku;
  - tjedno mjerenje, ako je uključeno;
  - dnevna analiza s prijedlozima, kad ima novih Google podataka.
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
| `ol-radar/radar-stanje` (2.1.1) | kratko stanje bez podataka: verzija, koji su ključevi postavljeni (da/ne), stanje izvora i ishod zadnjih provjera, mjerenje u tijeku, upozorenje nakon nadogradnje |
| `ol-radar/radar-prijedlozi` (2.1.0) | gotovi prijedlozi izmjena iz dnevne analize, s dokazima, tekstom i stanjem |

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
2. **Dodaci → Dodaj novi → Učitaj dodatak**, odaberi `ol-radar-most-2.1.1.zip` → **Zamijeni postojeći**. Ako je dodatak deaktiviran, aktiviraj ga. Spremljeni ključevi i postavke ostaju.
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
- **Brisanje dodatka** uklanja tablicu crawla (cache), ulogu i pravo.
- **Postavke, šifrirani ključevi i podaci Radara** (`ol_radar_docs`: povijest, arhiva, plan, prijedlozi) ostaju, da ponovna instalacija ništa ne izgubi. Za potpuno čišćenje definiraj `OLR_DELETE_DATA` u `wp-config.php` prije brisanja; pojedini ključ briše gumb „obriši” u Postavkama.

## Testirano (lokalno: WordPress 6.9, SQLite, MCP Adapter 0.7.1, PHP 8.3)
- **Postojeći testovi:** MCP spajanje, prava (anonimno 401, pretplatnik odbijen, čitač dopušten), crawl i audit na 311 stranica.
- **Nadogradnja (`tests/wordpress/upgrade_test.sh`):** sa svake izdane verzije 2.x na trenutni kod, deaktivacija/aktivacija, brisanje i ponovna instalacija; ključevi, postavke i podaci moraju ostati isti.
- **Radar na hostingu (`tests/wordpress/radar_test.php`, 105 provjera)** s lažnim Google, DataForSEO i Anthropic odgovorima:
  - veza prema Googleu: rezervni način, rezervna adresa, dijagnostika, cron nakon neuspjeha;
  - provjera ključeva nakon nadogradnje i nečitljiv zapis;
  - dnevna analiza (sva pravila) i prijedlozi: čišćenje odgovora Claudea, rad bez Claudea, naknadna dopuna teksta, stanja, bez ponavljanja, limiti;
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
  - modul Prijedlozi: kartice, prihvaćanje, filtri, „Analiziraj sada”;
  - Postavke ne otkrivaju ključeve; spremanje, Enter i „obriši” ne diraju druge ključeve.

## Plan
Cijeli plan s prioritetima, troškovima i izvorima: `docs/Plan-razvoja.md`.
- Instagram i YouTube na hostingu (Meta Graph API).
- Faza 2 upisa: Rank Math naslovi, opisi i fokus riječi, uz dnevnik promjena, vraćanje i odobrenje za svaki upis.
