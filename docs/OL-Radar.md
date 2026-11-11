# OL Radar – kako Claude koristi alat

Stanje: 11. 11. 2026., verzija v2, WordPress most v1.0.0 (instaliran i spojen), OpenSEO spojen, tracker aktivan.

**Aplikacija:** https://claude.ai/artifact/FwKJowHqdJUASipjeAkoBT
**Slug za alate (db_get / db_query / db_list):** 78f5630e-c158-4ddc-b32b-b3b711130c3e
**Kod:** GitHub repozitorij `ol-radar` (README, testovi, CHANGELOG)

## Pravilo
Svaka analiza i svaki prijedlog promjene na optikaljiljana.hr kreće od Radara. Prvo pročitaj `export/radar`. Ako ga nema ili je stariji od 7 dana, napomeni da treba otvoriti Radar da se osvježi. Brojke u prijedlozima uzimaj iz baze ili izravno iz izvora, ne napamet.

## Što je u bazi
- **export/radar:** sažetak za Claudea (piše ga aplikacija pri otvaranju, najviše svakih 6 h, i odmah nakon berbe arhive). Sadrži:
  - KPI-jeve;
  - GSC 28 dana;
  - 200 upita;
  - prilike (poz. 4–20);
  - kanibalizaciju;
  - stranice;
  - on-page oznake;
  - nalaze sa stanjem;
  - Instagram i YouTube;
  - plan;
  - stanje Ahrefs arhive.

  Stanje 11. 11.: još ne postoji, jer v2 još nije otvoren.
- **tjedno/<datum>** i **tjedno/zadnje:** tjedni zapis zakazanog zadatka (GSC tjedan prema tjednu, Instagram, audit weba iznutra, pozicije).
- **issues:** nalazi i njihovo stanje.
- **fixes:** AI prijedlozi popravaka.
- **snap:** dnevni snimci KPI-jeva (vlastita povijest).
- **plan:** planer sadržaja.
- **kp:** Google Keyword Planner.
- **arch_kw, arch_hist, arch_serp, arch_comp, arch_misc:** jednokratna arhiva Ahrefsa. Stanje 11. 11.: još nije pokrenuta. Pokrenuti prije ~30. 11.
- **cfg/settings:** konkurenti, trendovi, prekidač „Ahrefs isključen”, postavke arhive.

## Izvori podataka
- **GSC:**
  - Supermetrics (sc-domain:optikaljiljana.hr);
  - rezerva: OpenSEO `get_search_console_performance`, besplatno, ali je spojeno URL-svojstvo `https://www.optikaljiljana.hr/`.
- **Potražnja i sezonalnost:** Google Keyword Planner preko Supermetricsa (Google Ads račun 2686598307).
- **Društvene mreže:** vidIQ (Instagram owner insights uključujući zadržavanje na reelsima; YouTube).
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

## WordPress most (dodatak „OL Radar – most za Claudea”, v1.0.0, samo čitanje)
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
4. Nakon 2–4 tjedna provjeri učinak u GSC-u.

## U pripremi
- **Radar v3:**
  - prikaz WP audita i tjednih zapisa;
  - pozicije iz trackera;
  - procjena prometa konkurenata (volumen iz Keyword Plannera × naša CTR krivulja).
