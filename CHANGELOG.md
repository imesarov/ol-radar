# Promjene

## 2026-11-12 (navečer)
- **Dodatak 2.1.0.**
  - **Dnevni prijedlozi izmjena.** `OLR_Analiza` iz spremljenih podataka izdvaja nalaze (nizak CTR na visokoj poziciji, upiti blizu vrha, kanibalizacija, tjedni pad, ciljane riječi, tehnički nalazi, upiti bez prave stranice). `OLR_Prijedlozi` bira najvažnije koji nemaju otvoren prijedlog, sprema ih i jednim pozivom Claudea dopunjava gotovim tekstom izmjene. Pokreće se sam nakon novih Google podataka, ručno gumbom „Analiziraj sada”. Modul Prijedlozi u ploči (stanja: novo, prihvaćeno, provedeno, odbijeno), traka na Pregledu, alat `prijedlozi` u chatu, MCP `ol-radar/radar-prijedlozi`.
  - **Ključevi i nadogradnja (Ivanov zahtjev).** Brisanje dodatka više ne briše postavke i ključeve (osim uz `OLR_DELETE_DATA`). Nakon promjene verzije dodatak provjerava da su svi prije spremljeni ključevi čitljivi i upozorava ako nisu. Nečitljiv zapis se ne briše. Novi test `tests/wordpress/upgrade_test.sh` provjerava nadogradnju sa svake izdane verzije (popis `tests/wordpress/izdanja.txt`), a `tools/release.sh` gradi zip samo ako prođe.
  - **Veza prema Googleu.** Na webu je 12. 11. u 16:26 upit prema Search Consoleu ostao 60 s bez odgovora (`cURL error 28`), iako je token izdan. Pozivi sada idu kao HTTP/1.1 s čekanjem do 30 s, uz ponovni pokušaj preko IPv4 i rezervne adrese `searchconsole.googleapis.com`, pamćenje kombinacije koja radi i dijagnostiku po pokušaju. Cron nakon neuspjeha čeka 3 sata.
  - Claude API pozivi također idu kao HTTP/1.1.
- **Plan razvoja:** `docs/Plan-razvoja.md` (istraženo što još vrijedi dodati, s cijenama i izvorima).

## 2026-11-12 (popodne)
- **Dodatak 2.0.2:** ploča je u radnom dijelu wp-admina (okvir), a WP izbornik ostaje lijevo. Odabrani modul ostaje u adresi glavnog prozora. Svijetla tema je zadana (kontrast teksta ≥ 4,5:1), tamna je na prekidaču. U okviru wp-admina izbornik ploče je uži, a tablice gušće, pa sve stane od 1280 px naviše. Kod je zajednički, pa Radar na claude.ai dobiva temu pri sljedećoj objavi.
- **Dodatak 2.0.1:** gumbi za provjeru, preuzimanje i mjerenje na stranici Postavke sada najprije spremaju upisano (prije su se ključevi gubili ako se kliknula provjera prije „Spremi postavke”). Dodano je upozorenje na nespremljene promjene i potvrda troška prije mjerenja.
  - **Važan popravak:** gumbi „obriši” bili su ugniježđeni obrasci unutar glavnog obrasca, a preglednik ih spaja s njim. Zato su „Spremi postavke” i gumbi za provjeru, čim je neki ključ već bio spremljen, zapravo pokretali brisanje ključa. „Obriši” je sada običan gumb u glavnom obrascu, s potvrdom.
  - Enter u polju radi obično spremanje. Polja za lozinku i API ključ imaju `autocomplete="new-password"`, da preglednik u njih ne upiše spremljenu lozinku za wp-admin.

## 2026-11-12
- **WordPress dodatak 2.0.0: OL Radar na hostingu.**
  - Nadzorna ploča u wp-adminu, samo za administratore (od 2.0.2 u radnom dijelu, uz WP izbornik). Isti kod kao Radar na claude.ai (`app/hosted-shim.js`, `tools/build-hosted.py`).
  - Google Search Console preko servisnog računa (JWT RS256, bez posrednika i bez kredita), svakih 12 h.
  - Pozicije preko DataForSEO-a: standardni red, tjedno ili ručno, s poviješću i top 20 rezultata po riječi za model konkurencije. Trošak se bilježi.
  - Claude u ploči preko Anthropic API-ja: ključ ostaje na serveru, alati se izvršavaju u pregledniku, uz dnevni limit i zapis potrošnje.
  - Postavke sa šifriranim ključevima (libsodium) ili konstantama u `wp-config.php`, provjerama veza i uvozom iz Radara na claude.ai.
  - REST `ol-radar/v1`; nove MCP sposobnosti `radar-sazetak`, `radar-gsc` i `radar-pozicije`.
  - Brisanje dodatka uklanja ključeve, a povijest Radara čuva (osim uz `OLR_DELETE_DATA`).
  - Testovi: `radar_test.php` (58 provjera) i `hosted_e2e.py` (ploča u headless Chromeu), uz postojeće MCP i crawl testove.
- **Radar na claude.ai:** gumb „Izvezi za hosting” u Arhivi (sposobnost `downloads`).

## 2026-11-11 (večer)
- **Aplikacija v3:**
  - GSC sada dolazi preko OpenSEO-a, bez kredita. Supermetrics je samo rezerva, pa nema više greške TRIAL_EXPIRED;
  - novi modul Tjedno: 7 dana prema prethodnih 7, upiti koji rastu, padaju i novi, pozicije, promjene na webu, Instagram;
  - novi modul Pozicije: OpenSEO tracker s poviješću i GSC prosjekom za isti upit;
  - on-page i audit iznutra iz WordPress mosta (sve objavljene stranice, Rank Math fokus, broj riječi);
  - vlastiti model udjela konkurenata u Googleu iz arhiviranih SERP-ova;
  - kanibalizacija i „krivi URL” gledaju stranice sa zadnjih 7 dana, pa se adrese nakon 301 ne prijavljuju;
  - `export/radar` se piše i kad neki izvor kasni (polja `potpuno` i `izvori`), uz nova polja `tjedno`, `pozicije`, `audit_web` i `model_konkurencije`;
  - testovi: tri načina rada (uživo, bez Ahrefsa, bez OpenSEO-a i WP mosta).
- **WordPress dodatak 1.0.1:**
  - sadržaj `<script>` i `<style>` se prazni prije parsiranja, pa JS predlošci ne stvaraju lažne linkove;
  - JSON-LD se i dalje čita;
  - nakon nadogradnje sve stranice same idu na ponovnu obradu.

## 2026-11-11
- **Aplikacija v2:**
  - jednokratna arhiva Ahrefsa: može se pauzirati i nastaviti, a potrošnja jedinica je ograničena;
  - planer sadržaja s kalendarom i briefovima;
  - Google Keyword Planner;
  - on-page oznake iz GSC-a;
  - sažetak `export/radar` za Claudea;
  - rad bez Ahrefsa iz arhive.
- **WordPress dodatak „OL Radar – most za Claudea” 1.0.0:**
  - šest sposobnosti samo za čitanje;
  - crawl iznutra bez cachea;
  - audit;
  - graf internih linkova (sadržaj ili navigacija, dofollow ili nofollow);
  - uloga „OL Radar – samo čitanje”.

  Instaliran na optikaljiljana.hr i spojen preko OAuth-a WP Rocketa.
- **OpenSEO tracker:** 27 riječi, Zagreb, mobitel, dubina 40, tjedno.
- **Tjedni zakazani zadatak** „OL Radar – tjedno punjenje”.

## 2026-11-10
- **Aplikacija v1:**
  - pregled;
  - Google (GSC);
  - ključne riječi;
  - konkurencija;
  - trendovi;
  - audit;
  - društvene mreže;
  - popravci s AI prijedlozima;
  - metodologija.
