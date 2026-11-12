# OL Radar – kako Claude koristi alat

Stanje 12. 11. 2026.:
- Radar na claude.ai: v3.1.
- WordPress dodatak (Radar na hostingu): **na webu je 2.2.0** (provjereno 12. 11. u 22:30 preko `ol-radar/radar-stanje`). Spremljena su sva tri ključa (Google, DataForSEO, Anthropic), `nadogradnja` je `null`; model u ploči je Opus 5.5.
- Što na webu radi sa stvarnim podacima (12. 11. navečer):
  - Google podaci stižu (do 9. 11.), veza 0,4 s.
  - **Prvo mjerenje pozicija je obavljeno** 12. 11. u 21:35 (DataForSEO, 0,0648 $): 12 od 27 riječi pronađeno u prvih 40. Tjedno mjerenje nije uključeno.
  - **Indeksiranost radi** (servisni račun ima pristup URL Inspection API-ju): 4 od 40 stranica provjereno, sve u indeksu; ostale idu po 12 na sat.
  - **Učinak izmjena:** jedan prijedlog je označen kao proveden i mjeri se.
  - **Prijedlozi:** 5 ukupno, 1 otvoren. Taj jedan je ostao bez Claudeova teksta („Claude nije vratio prijedlog u očekivanom obliku”, odgovor nije bio prekinut zbog duljine). Najvjerojatniji uzrok: Claude je vratio samo korake, bez izmjene teksta, a 2.2.0 je tražio barem jednu izmjenu. Popravljeno u 2.3.0 (odgovor samo s koracima je valjan; nakon nadogradnje takav prijedlog dobiva još jedan pokušaj). Ako se ponovi, `stanje.izvori.ai_poziv` pokazuje kako je zadnji poziv završio.
- **Verzija 2.3.0 je spremna i čeka da je Ivan učita** (izravno preko 2.2.0; sadrži i 2.2.1):
  - **društvene mreže na hostingu:** Instagram i Facebook (Meta Graph API), YouTube (Data API v3), TikTok (Display API); što prolazi prema medijanu vlastite mreže, uzori i tjedne Claudeove preporuke. Radi tek kad Ivan upiše pristupne podatke (vidi „Društvene mreže” niže);
  - popravak prijedloga bez teksta (gore);
  - popravak iz 2.2.1: gumbi „Predloži popravak” / „Novi prijedlog” i „Brief” više ne javljaju „Claude je odgovorio u krivom obliku”.
- Nakon učitavanja provjeri `ol-radar/radar-stanje`: `stanje.verzija` 2.3.0, `kljucevi.gsc`, `kljucevi.dataforseo` i `kljucevi.anthropic` `true`, `nadogradnja` `null`, `prijedlozi.ceka_tekst` (treba pasti na 0 unutar sat vremena), `stanje.izvori.ai_poziv`. Za mreže: `kljucevi.meta`, `kljucevi.youtube`, `kljucevi.tiktok` i `mreze.<mreža>.stanje` (poruka zadnjeg preuzimanja; tu piše i ako tokenu nedostaje dopuštenje).
- **Društvene mreže još nisu provjerene na stvarnim API-jima.** Sve je testirano prema lažnim odgovorima Mete, YouTubea i TikToka. Prvo stvarno preuzimanje treba pogledati zajedno: nazivi metrika i dopuštenja kod Mete mijenjaju se nekoliko puta godišnje.
- Google servisni račun: `optika-ljiljana-seo-radar@api-project-88503755280.optikaljiljana.hr.iam.gserviceaccount.com` (12. 11.).
- DataForSEO: prijava radi (mjerenje je prošlo). AI pregled ima podatke od prvog mjerenja, kretanje konkurenata tek nakon drugog, pa vrijedi uključiti tjedno mjerenje (oko 0,28 $ mjesečno); odluka je Ivanova. Mjerenje pokreće samo Ivan gumbom u modulu Pozicije; Claude ga ne pokreće.
- **Greška u 2.0.0:** gumb „obriši” bio je ugniježđeni obrazac, pa su „Spremi” i provjere brisali već spremljeni ključ (popravljeno u 2.0.1).
- OpenSEO tracker radi i arhiva Ahrefsa je gotova.

**Aplikacija:** https://claude.ai/artifact/FwKJowHqdJUASipjeAkoBT
**Slug za alate (db_get / db_query / db_list):** 78f5630e-c158-4ddc-b32b-b3b711130c3e
**Kod:** GitHub repozitorij `ol-radar` (README, testovi, CHANGELOG)

## Pravilo
Svaka analiza i svaki prijedlog promjene na optikaljiljana.hr kreće od Radara.
- **Kad je dodatak 2.0 postavljen:** preko konektora „Optika Ljiljana web” pozovi `ol-radar/radar-sazetak` (velik odgovor, oko 25 tisuća tokena; za samu provjeru radi li sve od 2.1.1 koristi `ol-radar/radar-stanje`). Za svježe Google brojke koristi `ol-radar/radar-gsc`, a za pozicije `ol-radar/radar-pozicije`.
- **Od 2.1.0 gotovi prijedlozi izmjena čekaju na serveru:** `ol-radar/radar-prijedlozi` (zadano: otvoreni). Kad Ivan pita „što mijenjamo”, kreni od njih: imaju dokaze s brojkama, sadašnji i predloženi tekst i stanje. Stanje (prihvaćeno, provedeno, odbijeno) mijenja Ivan u ploči; konektor je samo za čitanje.
- **Od 2.2.0 učinak izmjena mjeri server:** kad Ivan pita „je li pomoglo”, pozovi `ol-radar/radar-prijedlozi` sa `stanje: provedeno`. Uz svaki provedeni prijedlog je polje `mjerenje` (Search Console prije i poslije za 7, 14 i 28 dana, trend cijelog weba, ocjena, sažetak). Ne računaj to ručno iz GSC-a ako mjerenje postoji.
- **Indeksiranost:** `ol-radar/radar-indeks` (zadano samo stranice s problemom). **Konkurenti i AI pregled:** `ol-radar/radar-pozicije` s `konkurenti: true`.
- **Društvene mreže (od 2.3.0):** `ol-radar/radar-mreze`. Kad Ivan pita što objavljivati ili zašto nešto ne prolazi, kreni odavde: po mreži medijan, objave iznad i ispod uobičajenog, obrasci i zadnje preporuke. `objave: true` daje popis objava, `uzori: true` tuđi gledani sadržaj. Za sadržaj i ton vrijede pravila brenda (premium, bez emojija, bez izmišljenih cijena i tvrdnji).

## Pravilo za nove verzije dodatka (Ivan, 12. 11. 2026.)
**Nova verzija ne smije obrisati već spremljene ključeve.** Zato:
- Zip se gradi samo s `bash tools/release.sh`. Skripta diže svježi testni WordPress i pokreće sve testove, među njima `tests/wordpress/upgrade_test.sh`: nadogradnja sa svake izdane verzije 2.x (popis `tests/wordpress/izdanja.txt`), deaktivacija/aktivacija, brisanje i ponovna instalacija. Ključevi, postavke i podaci moraju ostati isti.
- Nakon izdanja dodaj redak „verzija commit” u `tests/wordpress/izdanja.txt`, da je sljedeći test pokrije (git oznake se iz ovog okruženja ne mogu poslati na GitHub).
- Ne mijenjaj način šifriranja (`OLR_Settings::encrypt`, ključ iz `wp_salt('auth')`) ni imena opcija `olr_secrets` i `olr_settings` bez migracije koja čita stari oblik i testa za nju.
- `set_secret()` se zove samo na izričit unos ili „obriši” u Postavkama. Svaki novi gumb na stranici Postavke mora biti unutar glavnog obrasca (bez ugniježđenih obrazaca) i pokriven e2e testom „Spremi postavke čuva ključeve”.
- Nakon što Ivan učita novu verziju, provjeri `ol-radar/radar-stanje` (do 2.1.0: `ol-radar/radar-sazetak`): `stanje.verzija`, `stanje.kljucevi` (`gsc`, `dataforseo` i `anthropic` moraju biti `true`; `meta`, `youtube` i `tiktok` su `true` tek kad ih Ivan upiše) i `stanje.nadogradnja` (mora biti `null`).
- Popis tajni `OLR_Settings::NAMES` smije se samo dopunjavati (2.3.0 je dodao `meta_token`, `yt_key`, `tt_secret`). TikTok tokeni su u zasebnoj opciji `olr_tt_tokens`, da njihova obnova nikad ne dira `olr_secrets`.
- Uputa Ivanu je uvijek „Dodaci → Dodaj novi → Učitaj dodatak → Zamijeni postojeći”. Od 2.1.0 ključevi preživljavaju i brisanje dodatka pa ponovnu instalaciju.
- **Inače:** pročitaj `export/radar` u bazi artefakta.
- **Zastario ili nepostojeći sažetak:** ako ga nema ili je stariji od 7 dana, napomeni da treba otvoriti Radar (wp-admin → OL Radar).
- Brojke u prijedlozima uzimaj iz Radara ili izravno iz izvora, ne napamet.

## Radar na hostingu (dodatak 2.0)
- **Nadzorna ploča:** `https://www.optikaljiljana.hr/wp-admin/admin.php?page=ol-radar`, samo administrator. Postavke su na `admin.php?page=ol-radar-postavke`.
  - Od 2.0.2 ploča je u okviru u radnom dijelu wp-admina, a WP izbornik ostaje lijevo (Ivanova želja). Odabrani modul ostaje u adresi (`#pozicije` …).
  - Svijetla tema je zadana (Ivan: ne mora sve biti crno-zlatno kao web). Tamna je na prekidaču ispod logotipa.
- **Izvori na serveru:**
  - Google Search Console API (servisni račun, svakih 12 h);
  - DataForSEO (pozicije: tjedno ako je uključeno, ili gumbom u modulu Pozicije odnosno u Postavkama; oko 0,065 $ za 27 riječi; gumb u ploči traži potvrdu troška);
  - crawl dodatka;
  - Anthropic API za Claudea u ploči (Ultra plan: 200 $ API kredita mjesečno uz povezanu Console organizaciju).
- **Ključeve** upisuje samo Ivan na stranici Postavke. Spremaju se šifrirano i nikad se ne lijepe u chat.
- **Pohrana:** tablica `ol_radar_docs`, s istim zbirkama kao artefakt, plus `gsc`, `rank`, `rank_run`, `serp`, `prijedlozi`, `prijedlozi_stanje`, `ucinak`, `indeks` i `mreze`.
- **Uvoz iz claude.ai Radara:** Arhiva → „Izvezi za hosting”, zatim Postavke → Uvoz.
- **Društvene mreže (2.3.0):**
  - Samo čitanje. Dodatak ništa ne objavljuje, ne briše i ne mijenja na mrežama.
  - **Što Ivan mora pripremiti i sam upisati u Postavke → Društvene mreže** (ništa od toga ne ide u chat):
    - **Meta (Instagram i Facebook):** aplikacija vrste Business na developers.facebook.com, sistemski korisnik u poslovnim postavkama (business.facebook.com/settings → Users → System users) kojem su dodijeljeni Facebook stranica i Instagram račun, token s trajanjem „Never” i dopuštenjima `pages_show_list`, `pages_read_engagement`, `read_insights`, `instagram_basic`, `instagram_manage_insights`. Instagram mora biti profesionalni račun povezan sa stranicom. Za vlastite račune ne treba Metin pregled aplikacije.
    - **YouTube:** u postojećem Google Cloud projektu uključiti YouTube Data API v3 i izraditi API ključ ograničen na taj API; u Postavke upisati ključ i kanal (`@oznaka` ili ID).
    - **TikTok:** aplikacija na developers.tiktok.com s Sandboxom, proizvodi Login Kit i Display API, dopuštenja `user.info.basic`, `user.info.stats`, `video.list`, vlastiti račun kao ciljani korisnik (Target users). Redirect URI: `https://www.optikaljiljana.hr/wp-json/ol-radar/v1/oauth/tiktok` (točna adresa piše u Postavkama). Zatim Client key i Client secret u Postavke i „Spremi i poveži TikTok”.
  - **Podaci:** svakih 12 sati (postavka `mreze_auto`), ručno „Osvježi mreže” u ploči ili gumb u Postavkama. Po objavi: pregledi, doseg (Instagram, Facebook), lajkovi, komentari, dijeljenja, spremanja (Instagram), prosječno gledanje reela (Instagram), trajanje (YouTube, TikTok).
  - **Analiza:** objava prema medijanu vlastite mreže u zadnjih 180 dana (`omjer`; 1,5 i više iznad, 0,5 i manje ispod uobičajenog), `angazman` = reakcije na 100 pregleda, `obrasci` tek uz barem tri objave i odstupanje od 30 %. Ispod pet objava samo napomena. Obrazac je naznaka, ne pravilo.
  - **Uzori** (tjedno): YouTube po temama iz postavke `yt_teme` (najgledaniji videi zadnjih 120 dana, omjer pregleda i pretplatnika; 100 jedinica kvote po temi) i Instagram profili iz postavke `ig_uzori` (Business Discovery). **TikTok i Facebook ne daju službeni pristup tuđem sadržaju**; tko to traži, mora preko vidIQ-a ili ručno.
  - **Preporuke:** Claude tjedno ili na gumb (najviše 4 puta dnevno): sažetak, do 5 preporuka s koracima, do 6 ideja za objave. Brojke su iz podataka.
  - **TikTok veza:** pristupni token se obnavlja sam; odobrenje vrijedi godinu dana, zatim ponovno „Poveži TikTok”. Povratna adresa je javna, ali bez jednokratnog `state` koda ne mijenja ništa.
  - **Nema (zasad):** YouTube zadržavanje i izvori prometa (traži YouTube Analytics uz OAuth), preskakanje reela u prvim sekundama. To i dalje daje vidIQ u Radaru na claude.ai.
- **Dnevni prijedlozi izmjena (2.1.0, dorađeno u 2.1.1):**
  - Pokreću se sami kad stignu novi Google podaci (jednom dnevno), ili gumbom „Analiziraj sada” (ploča, Postavke; najviše 6 puta dnevno).
  - Analiza je u PHP-u (`OLR_Analiza`): nizak CTR na visokoj poziciji, upiti blizu vrha (4–15), kanibalizacija u zadnjih 7 dana, tjedni pad, ciljane riječi (pad, ispadanje, kriva stranica), tehnički nalazi crawla, upiti bez prave stranice.
  - Ne ulaze: brend (postavka „Riječi brenda”, i po korijenu riječi), upiti s popisa „Zanemari upite” (npr. imena drugih optika), noindex stranice, upiti koji nisu o temi stranice, prijedlog naslova za stranicu mijenjanu u zadnjih 14 dana, tjedni pad bez izgubljenih klikova.
  - Tekst izmjene piše Claude (Anthropic ključ na serveru), jedan kratak poziv po prijedlogu; brojke i „sada” uvijek su iz podataka. Ako tekst ne uspije ili nema ključa, prijedlog vrijedi s koracima iz podataka, a tekst se dopisuje u pozadini odmah nakon ručne analize i pri satnom prolazu (najviše tri pokušaja). U odgovoru `radar-prijedlozi` to su polja `ai`, `ai_pokusaja`, `ai_greska` i `ceka_tekst`.
  - Najviše 5 novih dnevno, najviše 2 po vrsti, najviše jedan otvoren po stranici (osim tehničkih). Otvoren nalaz se ne ponavlja; odbijeni se ne vraća 45 dana, provedeni 28 dana. Novi prijedlog čiji nalaz više ne postoji označava se kao istekao (`isteklo`).
  - Zbirke: `prijedlozi` (piše samo server), `prijedlozi_stanje` (piše ploča).
- **Učinak provedenih izmjena (2.2.0):**
  - Za prijedlog u stanju „provedeno” koji se odnosi na jednu stranicu server usporedi Search Console za tu stranicu jednak broj dana prije i poslije dana provedbe (7, 14, 28), samo konačne podatke, i uz to promjenu klikova cijelog weba.
  - Ocjena: `bolje`, `isto` (bez jasne promjene), `losije`, `premalo` (ispod 30 prikazivanja). Naslov i opis sude se samo po CTR-u; pozicijske izmjene po poziciji glavnog upita, a ako se ona ne miče po klikovima prema trendu weba.
  - Stanje mjerenja: `ceka` (manje od 7 dana konačnih podataka), `mjeri` (privremena ocjena), `gotovo` (28 dana), `nemjerljivo` (novi sadržaj, skupni tehnički nalaz).
  - Dan provedbe je dan kad je Ivan kliknuo „provedeno”; na kartici ga može ispraviti (polje `dan` u `prijedlozi_stanje`), pa se mjerenje računa iznova.
  - Usporedba ne dokazuje uzrok. Kad prenosiš rezultat, navedi brojke i trend weba, ne samo ocjenu.
- **Indeksiranost (2.2.0):**
  - Search Console URL Inspection API, isti servisni račun, besplatno. 12 stranica po satu; svaka jednom tjedno, problematične svaka 2 dana; najviše 600 provjera dnevno (Google dopušta 2.000).
  - Provjeravaju se stranice koje trebaju biti u indeksu (200, nisu noindex, kanonska pokazuje na sebe). Panel je u modulu Audit weba, s gumbom „Provjeri sada”.
  - `problem`: `nije` (nije u indeksu, s razlogom koji navodi Google), `kanonski` (u indeksu, ali Google je odabrao drugu adresu), `greska` (Google je odbio adresu).
  - „Nije u indeksu” i „druga kanonska” ulaze u prijedloge kao tehnički nalazi; stranice mijenjane u zadnjih 7 dana ne.
  - Postavka `indeks_auto` (zadano uključeno).
- **AI pregled i kretanje konkurenata (2.2.0):**
  - Svako mjerenje pozicija bilježi Googleov AI pregled po riječi: `aio` je `null` (nema ga) ili `{ima, ucitan, mi, nasa_adresa, izvori}`. `ucitan: false` znači da ga Google učitava naknadno; izvori tada stižu samo uz postavku `rank_aio_async` (+0,0006 $ po riječi, zadano isključeno).
  - Nakon svakog mjerenja računa se `rank/konkurenti`: po domeni top 3, top 10 i udio vidljivosti (pozicija × opća krivulja CTR-a, bez volumena) sada i prije, te po riječi tko je ušao u top 10, tko je ispao i tko nas je pretekao. Za promjene trebaju dva mjerenja.
- **MCP (samo čitanje, uloga `ol_radar_reader`):**
  - `ol-radar/radar-stanje` (od 2.1.1; kratko stanje bez podataka: verzija, ključevi da/ne, stanje izvora, ishod zadnjih provjera `provjera_gsc`, `provjera_dfs`, `provjera_ai`, mjerenje u tijeku, broj prijedloga);
  - `ol-radar/radar-sazetak` (export/radar, stanje izvora, starost);
  - `ol-radar/radar-gsc` (filtar, razdoblje 28 ili 7, limit do 500);
  - `ol-radar/radar-pozicije` (uvijek `ai_pregled` po riječi; konkurenti: true daje top 10 po riječi i `kretanje_konkurenata`);
  - `ol-radar/radar-indeks` (od 2.2.0; zadano stranice s problemom; `sve: true`, `limit` do 400);
  - `ol-radar/radar-mreze` (od 2.3.0; zadano sve mreže bez popisa objava; `mreza`: ig, fb, yt, tt; `objave: true`; `uzori: true`);
  - `ol-radar/radar-prijedlozi` (stanje: otvoreni, svi, novo, prihvaceno, provedeno, odbijeno; limit do 50; uz provedene polje `mjerenje`, u korijenu `ucinak` s brojem po ocjeni).
- **Plan razvoja** (što još dodati, s cijenama i izvorima): projektni dokument `claude/OL-Radar-plan-razvoja.md`.

## Što je u bazi
- **export/radar:** sažetak za Claudea. Aplikacija ga piše pri otvaranju: čim stigne GSC, a ostale izvore čeka najviše 2 minute (`potpuno: false` ako neki nije stigao). Potpun sažetak piše se najviše svakih 6 h. Sadrži:
  - `izvori` (odakle je koji dio: GSC preko OpenSEO-a ili Supermetricsa, on-page iz WP mosta ili GSC-a);
  - KPI-jeve;
  - GSC 28 dana;
  - `tjedno`: 7 dana prema prethodnih 7, upiti koji rastu, padaju i novi, promjene pozicija, promijenjene stranice, Instagram tjedna;
  - `pozicije`: tracker s GSC prosjekom i stranicom za isti upit;
  - `audit_web`: nalazi WP mosta po kodu;
  - `model_konkurencije`: udio u Googleu iz arhiviranih SERP-ova;
  - 200 upita;
  - prilike (poz. 4–20);
  - kanibalizaciju;
  - stranice;
  - on-page oznake;
  - nalaze sa stanjem;
  - Instagram i YouTube;
  - plan;
  - stanje Ahrefs arhive.
- **tjedno/<datum>** i **tjedno/zadnje:** tjedni zapis zakazanog zadatka (GSC tjedan prema tjednu, Instagram, audit weba iznutra, pozicije).
- **issues:** nalazi i njihovo stanje.
- **fixes:** AI prijedlozi popravaka.
- **snap:** dnevni snimci KPI-jeva (vlastita povijest).
- **plan:** planer sadržaja.
- **kp:** Google Keyword Planner.
- **arch_kw, arch_hist, arch_serp, arch_comp, arch_misc:** jednokratna arhiva Ahrefsa. Gotova 11. 11. u 15:57: 222 zadatka, 0 grešaka, 107.618 jedinica.
- **cfg/settings:** konkurenti, trendovi, prekidač „Ahrefs isključen”, postavke arhive.

## Izvori podataka
- **GSC (od v3):**
  - glavni izvor: OpenSEO `get_search_console_performance`, bez kredita; spojeno je URL-svojstvo `https://www.optikaljiljana.hr/`. Podaci završavaju oko 3 dana unatrag (pacifičko vrijeme). Odgovor je tekstualna tablica `key | clicks | impressions | CTR | position`; za query+page ključ je `upit / https://url`; stranice idu preko `startRow` („more available”).
  - rezerva: Supermetrics (sc-domain:optikaljiljana.hr). Probno razdoblje isteklo je 8. 5. 2026., pa radi samo na dnevnoj besplatnoj kvoti (inače TRIAL_EXPIRED).
  - zadnja rezerva: Ahrefs GSC uvid (dok traje pretplata).
- **Potražnja i sezonalnost:** Google Keyword Planner preko Supermetricsa (Google Ads račun 2686598307).
- **Društvene mreže:** na claude.ai vidIQ (Instagram owner insights uključujući zadržavanje na reelsima; YouTube). Na hostingu od 2.3.0 izravno Meta Graph API, YouTube Data API i TikTok Display API.
- **Web iznutra:** WordPress most, vidi dolje.
- **Pozicije i SERP:** OpenSEO (DataForSEO).
  - Projekt `e456f622-c40c-4085-b08d-d51a6f22349e`.
  - Paket je 10 $ mjesečno, što je 10.000 kredita; 1.000 kredita je 1 $.
  - Zadano tržište projekta je SAD/en. U svakom pozivu zadaj `locationCode` 2191 (Hrvatska) i `languageCode` `hr`, a za lokalno `locationName` „Zagreb,City of Zagreb,Croatia”.
  - SERP košta oko 5 kredita po riječi (dubina 10).
  - Prije novih troškova traži Ivanovo odobrenje. Za serije veće od 2.000 kredita uvijek pitaj.
- **Ahrefs:** do isteka pretplate (~30. 11. 2026.) radi uživo, a nakon toga samo arhiva.

## Tracker pozicija (OpenSEO)
- **ID:** `7d777ee3-4d5d-4297-9d4a-24c00fa6c248`.
- **Postavke:**
  - optikaljiljana.hr;
  - mobitel;
  - Zagreb;
  - dubina 40;
  - tjedno, u nedjelju u 22:40 po zagrebačkom vremenu.
- **Trošak:** najviše 408 kredita po provjeri (oko 1.632 mjesečno). Odobrio Ivan 11. 11.
- **27 riječi:**
  - **Progresivne (9):**
    - progresivne naočale
    - progresivne naočale cijena
    - cijena progresivnih naočala
    - progresivna stakla cijena
    - progresivne leće cijena
    - koliko koštaju progresivne naočale
    - progresivne naočale iskustva
    - varilux progresivne leće cijena
    - najpovoljnije progresivne naočale
  - **Lokalno i pregled (6):**
    - optika sesvete
    - optičar
    - optika
    - besplatan pregled vida
    - besplatni pregled vida
    - besplatna kontrola vida
  - **Proizvodi (8):**
    - dioptrijske naočale
    - naočale za čitanje
    - naočale za noćnu vožnju
    - bifokalne naočale cijena
    - stellest stakla
    - ray ban dioptrijske naočale
    - sunčane naočale
    - essilor stakla iskustva
  - **Teme (4):**
    - kako vide ljudi s astigmatizmom
    - mrena na oku
    - optometrist
    - okulist
- **Rezultati:** čitaj ih s `get_rank_tracker` i `get_rank_history`; to ne troši kredite.
- **Planirano:** mjesečni SERP snimak top 10 za iste riječi radi konkurenata (oko 135 kredita).

## WordPress most (dodatak „OL Radar – most za Claudea”, samo čitanje)
- **Verzija:** na webu 2.2.0. Verzija 2.3.0 čeka da je Ivan učita (Dodaci → Učitaj → Zamijeni postojeći).
  - Uključuje popravak iz 1.0.1: sadržaj `<script>` i `<style>` prazni se prije parsiranja, pa nestaje lažni „link na preusmjerenje” na naslovnici (JS predložak Journala).
  - Nakon nadogradnje sve stranice same idu na ponovnu obradu. Ubrzanje: `ol-radar/osvjezi-crawl` s `velicina` 25, dok „preostalo” ne bude 0.
- **Vatrozid hostinga** povremeno vrati 403 na pojedinačni poziv. Ponovi ga jednom; aplikacija to radi sama.
- **Konektor u claude.ai:** „Optika Ljiljana web”, URL `https://www.optikaljiljana.hr/wp-json/mcp/mcp-oauth-server`.
  - OAuth je WP Rocketov.
  - Prijavljen je korisnik `claude-radar` (ID 4, uloga `ol_radar_reader`). Provjereno 11. 11.: WP Rocket i Rank Math sposobnosti mu odbijaju pristup („Permission denied”).
- **Alati:** `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability` (ability_name + parameters).
  - Discovery prikazuje i tuđe sposobnosti (WP Rocket, Rank Math, marketplace). Pokreći samo `ol-radar/*`.
- **Sposobnosti:**
  - `ol-radar/pregled-weba`
  - `ol-radar/popis-sadrzaja` (vrsta, trazi, promijenjeno_nakon, stranica, po_stranici)
  - `ol-radar/stranica` (url ili id, svjezi, bez_teksta)
  - `ol-radar/audit` (ozbiljnost, kodovi, samo_url, limit)
  - `ol-radar/interni-linkovi` (url)
  - `ol-radar/osvjezi-crawl` (velicina 1–25; ponavljaj dok „preostalo” nije 0)
- **Web 11. 11.:**
  - WordPress 7.1.4, PHP 8.3, 42 URL-a.
  - Crawl traje oko 1 s po stranici.
  - Audit: 0 visokih nalaza. Srednji (5) su namjerni: `/hvala-na-rezervaciji/` i `/moj-termin/` su noindex i bez linkova.
  - Niski nalazi: fokus riječ nije u H1 (8), nije u opisu (6), naslov predug ili prekratak (3 + 3), slike bez dimenzija (3), link na preusmjerenje (1).
- **Plan:**
  - faza 2: upis Rank Math naslova, opisa i fokus riječi, s dnevnikom i vraćanjem;
  - faza 3: samo skice.

## Zakazani zadaci
- **„OL Radar – tjedno punjenje”** (`trig_018LjugFt65ycmGRWKdsAr14`, ponedjeljak 05:38, ima WP i OpenSEO konektore) puni `tjedno/`.
  - Javlja se samo kod novog visokog nalaza, pada ne-brand klikova od 25 % ili više, ili kvara svih izvora.
- **„Tjedna provjera weba – Ahrefs”** prestaje raditi kad Ahrefs istekne. Prijedlog: prebaciti je na `ol-radar/audit`, čeka Ivanov OK.

## Tijek rada za promjene na webu
1. Pročitaj `export/radar` i relevantne `issues` i `plan`, a za stvarni HTML `ol-radar/stranica`.
2. Predloži promjenu s brojkama: upit, pozicija, prikazi, potencijal.
3. Promjenu na webu radi tek nakon izričitog odobrenja. Zapiši je i označi nalaz kao riješen.
4. Učinak se od 2.2.0 mjeri sam: Ivan u ploči označi prijedlog kao „provedeno” (i po potrebi ispravi dan), a rezultat je u `ol-radar/radar-prijedlozi` (`mjerenje`). Za izmjene koje nisu nastale iz prijedloga i dalje usporedi GSC ručno nakon 2–4 tjedna.

## Radar v3 (11. 11. 2026.)
- **Moduli:**
  - 01 Pregled;
  - 02 Tjedno (novo);
  - 03 Google;
  - 04 Pozicije (novo, tracker + GSC za isti upit; „krivi URL” se uspoređuje sa stranicom iz zadnjih 7 dana);
  - 05–08 Ključne riječi, Planer, Konkurencija (novi panel „Udio u Googleu: vlastiti model”), Trendovi;
  - 09 Audit weba (WP most + on-page + Ahrefs Site Audit);
  - 10–13 Društvene mreže, Popravci i AI, Arhiva, Kako radi.
- **Kanibalizacija u Radaru** broji samo stranice koje i u zadnjih 7 dana imaju prikazivanja za taj upit. Zato se stara adresa nakon 301 ne prijavljuje kao kanibalizacija.
- **Model konkurencije:** za svaki arhivirani SERP (HR) uzima volumen iz arhive, pa za svaku domenu u organskom top 10 računa volumen × CTR za tu poziciju. CTR je naša GSC krivulja kad pozicija ima barem 60 prikazivanja, inače opća krivulja. Panel pokazuje i provjeru: model za naš web prema stvarnim GSC klikovima za iste riječi.
- **Chat alati u Radaru:** pregled, gsc_upiti, nalazi, pozicije, tjedno, onpage, planer, audit_web, arhiva_kljucne, keyword_planner, instagram, ahrefs_*.

## Odluke i nalazi
- **Kanibalizacija progresivnih stranica (11. 11. 2026.):**
  - Cjenovni upiti: riješeno 301 preusmjeravanjem s `/koliko-kostaju-progresivne-naocale/` na `/progresivne-naocalne-lece/` (oko 3.–4. 11.).
    - 14.–31. 10.: „progresivne naočale cijena” bio je podijeljen na 6,1 (cjenovna) i 9,4 (stara adresa); „cijena progresivnih naočala” na 5,3 i 7,9.
    - 4.–8. 11.: sve ide na cjenovnu stranicu (2,8; 3,0; leće 3,6; stakla 3,9).
    - Prikazi: 15–40 dnevno prije, 100–130 dnevno poslije. Klikovi: 0–2 dnevno prije, 4–6 dnevno poslije.
  - „progresivne naočale iskustva” u GSC-u već ide na `/progresivne-naocale-iskustva/` (pozicija 2,7–2,9).
  - „stellest stakla” u GSC-u ide na `/stellest-lece-za-djecu/` (pozicija 3,5); `/journal/` u trackeru bio je jednokratan.
  - Otvoreno, čeka Ivanov OK:
    - 4–6 kontekstualnih internih linkova na cjenovnu stranicu (ima ih 6, a vodeći članak oko 19);
    - pratiti „najpovoljnije” (8,1);
    - naslov dirati tek ako treba, nakon 2–3 tjedna.

## U pripremi
- Mjesečni snimak SERP-ova za konkurente preko OpenSEO-a (oko 135 kredita), uz Ivanov OK.
- Faza 2 WP mosta: upis Rank Math naslova i opisa s dnevnikom i vraćanjem.
