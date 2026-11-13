# Promjene

## 2026-11-13 (manje lažnih nalaza)
- **Dodatak 2.4.2: prijedlozi izmjena bez lažnih nalaza** (Ivan, 13. 11.: „popravi ako je previše lažnih nalaza … da ne predlaže gluposti”). Pregledani su svi prijedlozi na webu i stvarni Search Console podaci; svaki popravak ispod ima uzrok u stvarnom nalazu.
  - **Glavna stranica za upit** je ona s najviše klikova, zatim najbolje pozicije, zatim prikazivanja (`OLR_Analiza::main_of`, `main_pages`). Prije se birala po prikazivanjima, pa je za „optika ljiljana” (28 dana) glavna bila stranica rezervacije: dodatne poveznice ispod rezultata (sitelinkovi) imaju ista prikazivanja kao glavni rezultat (155), a rezervaciji su se pribrojile i adrese s Googleova profila tvrtke (`utm_campaign=gbp_booking`, `rwg_token`), pa je imala 193 prema 159 naslovnice, iako naslovnica ima 46 od 73 klika. Vrijedi za `ol-radar/radar-gsc` (polje `stranica`, novo polje `brend`), pravila analize i ploču.
  - **Kanibalizacija** se ne javlja kad je glavna stranica na poziciji do 1,5 (prvi smo; ostale su dodatne poveznice ili drugi naš rezultat), kad su glavna i druga obje u prva tri rezultata (dvostruki prikaz), kad je druga ispod 20. mjesta, za upite s imenom optike ili konkurenta, ni kad je neka od stranica mijenjana u zadnjih 14 dana. Na ploči (popis za 28 dana) isto, uz oznaku „(glavna)”; glavna se bira samo među stranicama koje Google prikazuje i u zadnjih 7 dana.
  - **Upiti s imenom konkurenta** se preskaču (`is_competitor`): imena iz postavke „Konkurenti” i optika iz mjerenja pozicija, po riječi i spoju dviju riječi („optika jo jo”), uz najviše 2 slova padeža; riječ koju koristimo na vlastitom webu nikad nije ime konkurenta. Uzrok: prijedlog naslova za naslovnicu iz upita „optika anda”.
  - **Usporedba po riječima, ne po dijelu teksta** (`relevant`): „anda” se poklapala sa „standard” na naslovnici, pa je „optika anda” prošla kao upit o našoj stranici.
  - **Statistika:** pad klikova mora biti značajan po binomnom testu (ispod 5 % slučajnosti; 10 → 4 klika je p ≈ 0,09 i nije pad), nizak CTR mora biti značajno ispod 80 % očekivanog (`binom_cdf`). Uzrok: prijedlog „pad” za „progresivne naočale iskustva” (10 → 4 klika, pozicija ista, prikazivanja veća).
  - **Lokalni upiti** (mjesto, blizina ili sama djelatnost: „optika sesvete”, „optičar”) imaju upola niže očekivanje CTR-a, jer iznad organskih rezultata stoji karta s tvrtkama.
  - **Svježe stranice:** za stranice mijenjane u zadnjih 14 dana ne predlažu se blizu vrha, kanibalizacija, novi sadržaj ni kriva stranica u rezultatu (prije samo naslov i opis). Uzrok: šest od osam prijedloga 12. i 13. 11. sam je Claude pretvorio u „pričekati učinak izmjene”. Napomena ispod analize kaže koje su stranice čekale.
  - **Blizu vrha i naslov/opis** uzimaju upit samo za stranicu koja je za njega glavna. Uzrok: „optika sesvete” kao glavni upit u prijedlogu za /besplatan-pregled-vida-sesvete/, iako je za taj upit glavna naslovnica.
  - **Novi sadržaj** se ne predlaže kad postojeća stranica već pokriva temu (npr. „mrena na oku” → /katarakta/); to je dorada, a novi članak bi joj konkurirao.
  - **Kriva stranica u rezultatu** tek uz barem 30 prikazivanja u 7 dana (prije 10).
  - **Verzija pravila analize** (`OLR_Analiza::PRAVILA` = 2, polje `pravila` u prijedlogu). Do sada je prijedlog s Claudeovim tekstom čiji nalaz nestane čekao 21 dan prije isteka (zaštita od kolebanja podataka), pa bi lažni prijedlozi napisani prije 2.4.2 ostali do tri tjedna. Sada prijedlog u stanju „novo” napisan po starijim pravilima istječe pri prvoj analizi s Google podacima ako ga nova pravila ne potvrđuju (razlog „nalaz ne prolazi pooštrena pravila analize”); onaj koji i dalje vrijedi dobiva oznaku novih pravila i zadržava zaštitu od 21 dan. Prihvaćeni, provedeni i odbijeni se ne diraju. Bez Google podataka (`potpuna` = false) ništa ne istječe po ovom pravilu.
- **Popravak web pretrage:** Anthropic odbija `user_location.country` „HR” (HTTP 400, „Country code HR is not supported”; dokumentacija: nepodržana oznaka države daje 400). Lokacija je sada samo Zagreb i vremenska zona; ako pretraga odbije i to, pokuša jednom bez lokacije. Poruka o grešci više ne upućuje na postavke Consolea kad uzrok nije isključena pretraga.
- **Popravak kontakta kandidata:** poveznica mora sadržavati „kontakt”, „contact”, „impressum”, „redakcija” ili „o-nama” kao cijelu riječ; prije je dioptrija.hr dobila /kontaktne-lece kao stranicu Kontakt.
- **Testovi** (`radar_test.php` 308 provjera): novi slučajevi iz stvarnih podataka (sitelinkovi uz upit s imenom optike, prvi na 1,2, dvostruki prikaz u prva tri, druga ispod 20. mjesta, „optika jo jo”, stranica koja već pokriva temu, pad 10 → 4, binomni test, lokacija koju pretraga odbije, poveznica na kontaktne leće) i istek prijedloga po starim pravilima.

## 2026-11-13 (završna arhiva Ahrefsa)
- **Dodatak 2.4.1: završna arhiva Ahrefsa u modulu Suradnje i popravak pisanja prijedloga** (Ivanov zahtjev 13. 11.: „povuci prije nego pretplata istekne što možeš iz ahrefsa”; pretplata vrijedi do 30. 11. 2026.).
  - **Što je povučeno (13. 11., oko 14.000 jedinica; ostalo oko 40.000):** domene koje povezuju pet konkurenata i pet srodnih tvrtki (ghetaldus.hr, svjetlost.hr, optometrija.net, eyerim.hr, oculus-zagreb.hr), bez spama i samo dofollow; 83 primjera stranica s poveznicom; naše poveznice bez spama, sidra i pokidane poveznice; mjesečna povijest DR-a (siječanj 2025. do studeni 2026.) i broja domena koje nas povezuju; organski konkurenti; 239 novih ključnih riječi u pet skupina; ključne riječi s kojima su optometrija.net i svjetlost.hr u prvih deset. Sirovi podaci: `data/arhiva/ahrefs-2026-11-13/`.
  - **Nalazi:** naš DR je 0 od 2025.; broj domena koje nas povezuju narastao je s 47 na 577, gotovo sve su spam prodavača poveznica. Bez spama nas povezuje šest domena, samo dvije dofollow (optike.hr, hr.voovuu.com). Pokidanih poveznica nema. Konkurenti do poveznica dolaze plaćenim promo i native člancima u nacionalnim medijima, objavama uz humanitarne akcije i otvorenja, stručnim savjetima na portalima, stranicama pogodnosti za članove (sindikati, HAK, kartice, osiguranja), imenicima i stranicama „Poslovna suradnja”.
  - **`data/suradnje-pocetno.json`** (izvor `ahrefs-2026-11-13`): 74 kandidata umjesto 20. Nova polja: `prioritet` (1 najizvedivije, 2 traži stručni sadržaj, 3 većinom plaćene objave), `nacin` (kako su drugi došli do poveznice), `primjer` (`kod`, `url`, `naslov`), `dr_povijest` (mjesečno). Gradi ga `tools/build-suradnje-seed.py` iz CSV datoteka arhive; brojke dolaze iz arhive, a naziv, vrsta, prioritet i opis načina su urednički.
  - **`OLR_Suradnje`:** `fields()` čisti i sprema nova polja; `seed()` postojećem kandidatu iz novijeg snimka osvježi brojke, način, primjer i izvedivost, a prijedlog, kontakt, stanje i provjeru poveznice ne dira (ručno dodanom ostaju i naziv i vrsta); `seed_dr()` spaja povijest DR-a po datumu i ne prepisuje točke koje je Radar sam izmjerio; `queue()` i `summary()` idu po izvedivosti (ručno dodani prvi), zatim po broju tvrtki s poveznicom i DR-u; Claude u ulazu dobiva `kako_su_dosli_do_poveznice`, `naslov_primjera` i `izvedivost`, uz uputu da naslov primjera ne prepisuje i ne slijedi kao naputak. Novi filtar `olr_suradnje_seed_file` (putanja snimka; koristi ga test).
  - **Ploča:** kartica pokazuje ocjenu izvedivosti, „već povezuje” (do pet tvrtki) i redak „Kako su drugi došli do poveznice” s poveznicom na primjer; tablica svih kandidata ima stupac Izvedivost; tablica DR-a ima crtu kretanja od prve točke arhive (sve crte na istoj ljestvici, od 0 do najvišeg DR-a na popisu). U modulu Arhiva pločica „Ključne riječi” dopunske skupine broji odvojeno od polaznih pojmova.
  - **Dopuna arhive za hosting:** `data/arhiva/ahrefs-2026-11-13/uvoz-za-hosting.json` (Postavke → „Uvoz iz Radara na claude.ai”): pet novih skupina ključnih riječi u `arch_kw` i dokument `arch_misc/zavrsna-2026-11-13` (poveznice po domeni, primjeri, naše poveznice bez spama, povijest DR-a, ključne riječi portala).
  - **Popravak nakon prvog stvarnog prolaza (13. 11., web je na 2.4.0):** `stanje.izvori.suradnje` je javio „Claudeov odgovor je prekinut zbog duljine”. Uzrok: na Opusu 5.5 razmišljanje je uvijek uključeno i troši isti `max_tokens` kao i odgovor (Anthropicova dokumentacija: Effort, What's new in Claude Opus 5.5), pa pet prijedloga u jednom pozivu nije stalo u 6000 tokena.
    - **Prijedlozi suradnje:** jedan Claudeov poziv po kandidatu (`propose()`), kao kod dnevnih prijedloga koji na webu rade. Svaki prijedlog se sprema čim stigne, pa neuspjeh jednog ne ruši ostale. Okvir po pozivu je 8000 tokena (gornja granica, ne potrošnja). Kandidat s tri neuspjela pokušaja (`ai_pokusaja`) više ne ide Claudeu.
    - **Vremenski okvir:** satni prolaz počinje nove pozive do 150 s, klik u ploči do 35 s (dva do tri prijedloga po kliku); što ne stane, čeka sljedeći prolaz.
    - **Web pretraga:** poziv s pretragom sada vraća samo kandidate (domena, naziv, vrsta, jedna do dvije rečenice zašto), a prijedlog za svakoga piše zaseban poziv koji dobiva i nalaz pretrage (`napomena`). Ako model ipak vrati cijeli prijedlog, prihvaća se. Ishod ima novo polje `prijedloga`.
    - **Redoslijed:** ručno dodani i kandidati iz web pretrage dobivaju prijedlog prvi.
    - **Preporuke za društvene mreže:** okvir odgovora podignut s 4000 na 8000 tokena iz istog razloga (još nije bilo stvarnog prolaza).
    - **Dijagnostika:** stanje `ai_poziv` uz izlazne tokene pokazuje koliko je otišlo na razmišljanje (`usage.output_tokens_details.thinking_tokens`).
  - **Testovi:** test više ne ovisi o priloženom snimku (testni snimak `tests/wordpress/fixtures/suradnje-pocetno.json`, opcija `olr_test_seed_file`); novi testovi za priloženi snimak (broj, polja, redoslijed, povijest DR-a), uvoz dopune arhive, nadogradnju s 2.4.0 (prijedlog, stanje, kontakt i vlastito mjerenje DR-a ostaju) i prikaz na kartici.

## 2026-11-13 (00:15)
- **Dodatak 2.4.0: suradnje i poveznice, Claude trošak, GSC kao glavni izvor** (Ivanovi zahtjevi 12. 11. navečer: automatizirati prijedloge kako povećati DR i kome se obratiti, analizirati tvrtke za suradnju, GSC kao najtočniji izvor a Ahrefs samo kao arhiva, Claude trošak u Postavkama).
  - **Novi razred `OLR_Suradnje`** (`includes/class-olr-suradnje.php`; zbirke `suradnje`, `suradnje_stanje`, `suradnje_dr`; opcije `olr_suradnje_run`, `olr_suradnje_seed`).
    - **Kandidati:** (1) priložena arhiva `data/suradnje-pocetno.json` (Ahrefs, 12. 11. 2026.: 20 domena koje povezuju konkurente, a ne nas, s brojem poveznica po konkurentu i DR-om; uvozi se jednom), (2) tvrtke koje Claude nađe web pretragom, (3) ručno dodane. Naša domena, konkurenti (postavka `konkurenti`), velike platforme i prodavači poveznica ne mogu biti kandidati.
    - **Provjera prije Claudea:** server otvori web kandidata (`wp_safe_remote_get`, kratki rokovi) i s naslovnice i stranice Kontakt pročita javni kontakt (e-pošta s njihove domene ili iz `mailto:`, telefon iz `tel:`). Web koji se ne otvara ne ide Claudeu. Claude ne dobiva e-adrese i ne smije izmišljati kontakt (polje `kome` je uloga).
    - **Prijedlozi (`write`)**: jedan poziv tjedno za najviše `suradnje_max` (zadano 5) kandidata, alat `spremi_suradnje` (zašto, što ponuditi, do 3 koraka, nacrt poruke, kome). Odgovor se čisti (bez HTML-a, ograničenja duljine); domena koje nije bilo u ulazu i nepotpun prijedlog se odbacuju.
    - **Nove tvrtke (`search`)**: Anthropicov alat za web pretragu (`web_search_20250305`, lokacija Zagreb, najviše `suradnje_novih` + 2 upita). Novi kandidat ulazi samo ako mu web postoji; kontakt čita server. Prekinut prolaz (`pause_turn`) nastavlja se s nepromijenjenim sadržajem; odgovor bez poziva alata dobiva jedan nastavak. Tjedno samo ako je uključeno (`suradnje_trazi`, zadano isključeno, jer se pretraga naplaćuje po upitu); na gumb uvijek, uz potvrdu troška.
    - **Stanja** (novo → kontaktirano → dogovoreno → dobiveno, odbijeno) vodi ploča u zbirci `suradnje_stanje`. Kad se upiše adresa stranice s poveznicom, server provjeri postoji li poveznica na nas i je li `nofollow`/`sponsored` (odmah, nepotvrđene sutradan, potvrđene mjesečno).
    - **Poveznice koje vidi Google:** Search Console API nema izvještaj o vezama (ima samo Search Analytics, Sitemaps, Sites i URL Inspection), pa se izvoz iz sučelja (Veze → Vanjske veze → Izvoz) učitava u ploči. Prihvaća „Najčešće web-lokacije s vezama” i „Najnovije veze”; pamti povijest i nove domene od prošlog uvoza; domene koje po nazivu izgledaju kao prodavači poveznica samo označava.
    - **DR:** Ahrefsov besplatni javni servis (`GET /v3/public/domain-rating-free`, bez ključa), za nas i konkurente, s poviješću. Zadano isključeno (`suradnje_dr`): Ahrefs je inače samo arhiva. Na gumb uvijek. U sučelju stoji obvezna oznaka „Domain Rating by Ahrefs”.
    - **Pravila u uputi Claudeu:** nikad kupnja ni razmjena poveznica; plaćeni članak samo uz napomenu da je poveznica sponzorirana i ne diže DR; bez izmišljenih imena, cijena i brojki; ponuda mora biti korisna drugoj strani i izvediva za malu optiku.
  - **Claude API bez prisilnog poziva alata (popravak).** Opus 5.5 i Sonnet 5.5 odbijaju `tool_choice` vrste `tool` i `any` s HTTP 400. Posljedice dosad: svaki dnevni prijedlog i svaki „Predloži popravak” prvo je dobio 400 pa prešao na rezervni JSON u tekstu, a preporuke za društvene mreže (2.3.0) nisu imale rezervu i ne bi radile na tim modelima. `OLR_AI::call()` sada nikad ne šalje prisilu: alat traži uputom u sistemskoj poruci, a ako ga odgovor ne sadrži, ide jedan ponovljeni pokušaj s podsjetnikom. Lažni API u testovima sada vraća 400 na prisilu, kao pravi.
  - **`OLR_AI::call( $req, $server )`:** samo pozivi sa servera mogu uključiti web pretragu (`web_search`) i dulji odgovor (`long`, do 8.000 tokena); REST posrednik ploče to ne može.
  - **Claude trošak:** svaki poziv bilježi iznos iz stvarnih tokena i cjenika modela (`OLR_AI::PRICES`: Opus 5.5 4/20 $, Sonnet 5.5 2/10 $, Haiku 5.5 0,10/0,50 $ po milijunu tokena, iznad 100.000 ulaznih 0,50/2,50 $; web pretraga 0,01 $ po upitu). Postavke imaju tablicu „Claude trošak” (danas i po mjesecima: pozivi, tokeni, web pretrage, iznos), stanje izvora iznos zadnjeg poziva, zaglavlje ploče iznos za mjesec. Mjeseci s pozivima prije 2.4.0 označeni su kao procjena. Trošak je i u `ol-radar/radar-stanje`.
  - **Postavke:** odjeljak „Suradnje i poveznice” u glavnom obrascu (tjedni prijedlozi, nove tvrtke, fokus, konkurenti, DR; tri gumba, web pretraga uz potvrdu), četiri nova retka u stanju izvora.
  - **Ploča:** novi modul Suradnje (samo na hostingu, 12. u izborniku): pločice, kartice s prijedlogom, nacrtom poruke (Kopiraj), javnim kontaktom i stanjem; upis adrese s poveznicom; uvoz veza iz Search Consolea; DR nas i konkurenata; ručno dodavanje. Novi alat chata `suradnje`, izvoz za Claudea dobiva `suradnje`.
  - **REST:** `GET /suradnje`; `/podaci` vraća `suradnje`; `/osvjezi` dijelovi `suradnje`, `suradnje_trazi` (traži `potvrda=da`), `suradnje_dr`, `suradnje_gsc`, `suradnje_dodaj`, `suradnje_veza`; ploča smije pisati u `suradnje_stanje`.
  - **MCP:** nova sposobnost `ol-radar/radar-suradnje`; `radar-stanje` dobiva sažetak suradnji i Claudeov trošak.
  - **Ključevi:** bez novih tajni. `OLR_Settings::NAMES`, šifriranje i imena opcija nisu dirani.
  - Testovi: `radar_test.php` 283 provjere (45 novih), `hosted_e2e.py` 62 (11 novih). Sve prema lažnim odgovorima; stvarni Anthropic, Ahrefs i webovi kandidata nisu pozivani.

## 2026-11-12 (22:30)
- **Dodatak 2.3.0: društvene mreže na hostingu** (Ivanov zahtjev: Instagram i Facebook preko Metinog API-ja, TikTok ako ima API, YouTube, analiza viralnog sadržaja i što raditi da nas ljudi gledaju).
  - **Novi razred `OLR_Social`** (`includes/class-olr-social.php`, zbirka `mreze`: `ig`, `fb`, `yt`, `tt`, `analiza`, `uzori`, `preporuke`). Samo čitanje.
    - **Instagram i Facebook:** Meta Graph API `v26.0`. Token sistemskog korisnika → `me/accounts` → stranica s povezanim Instagramom (ili po postavci `meta_page`) i token stranice. Metrike objava idu jednim batch pozivom (do 50 upita); objava kojoj Meta odbije širi skup metrika ide u sljedeći krug s užim, a ako batch ne prođe, čita se objava po objava (do 25 s). Popisi objava i podaci računa također imaju uži rezervni popis polja (`graph_first()`), pa jedno nedostupno polje ne ruši cijelo preuzimanje. Facebook koristi `post_media_view` i `post_total_media_view_unique` (zamjene za ukinute `impressions` metrike).
    - **YouTube:** Data API v3 s ključem u zaglavlju `X-goog-api-key` (kanal po oznaci ili ID-ju, popis prijenosa, videi). Kratki video = do 60 s, jer API ne razlikuje Shorts.
    - **TikTok:** Login Kit + Display API. OAuth s jednokratnim `state` (transient vezan uz administratora, 15 min), povratna ruta `GET /wp-json/ol-radar/v1/oauth/tiktok` (javna, bez valjanog `state` ne mijenja ništa). Tokeni šifrirano u zasebnoj opciji `olr_tt_tokens`; pristupni se obnavlja kad mu ostane manje od 2 sata.
    - **Analiza:** objava prema medijanu vlastite mreže u 180 dana (`omjer`), angažman na 100 pregleda, obrasci (vrsta, trajanje, dan, doba dana, pitanje na početku opisa) tek uz barem tri objave i odstupanje od 30 %. Ispod pet objava samo napomena.
    - **Uzori:** YouTube `search.list` po temama (`order=viewCount`, zadnjih 120 dana, 100 jedinica kvote po temi) s omjerom pregleda i pretplatnika; Instagram Business Discovery za navedene profile, prema medijanu profila.
    - **Preporuke:** Claude prisilnim pozivom alata `spremi_preporuke` (do 5 preporuka s 3 koraka, do 6 ideja); prekinut odgovor traži se jednom ponovno, kraće. Ručno najviše 4 puta dnevno.
    - **Raspored:** podaci svakih 12 sati, uzori i preporuke tjedno (nakon neuspjelih preporuka novi pokušaj sutradan). Ide zadnje u satnom prolazu i samo ako je prolaz dotad bio kratak.
    - **Veza:** svi pozivi idu kroz `OLR_Net::post()` (HTTP/1.1, ponavljanje preko IPv4); poslužitelj koji dvaput ne odgovori preskače se do kraja zahtjeva.
  - **Ključevi:** nova imena tajni `meta_token`, `yt_key`, `tt_secret` (konstante `OLR_META_TOKEN`, `OLR_YT_KEY`, `OLR_TT_SECRET`). Popis `OLR_Settings::NAMES` je samo dopunjen; šifriranje i imena opcija nisu dirani. `secret_source()` više ne puca na nepoznatom imenu.
  - **Postavke:** odjeljak „Društvene mreže” u glavnom obrascu (bez ugniježđenih obrazaca): polja, „Spremi i poveži TikTok”, „prekini vezu” (uz potvrdu), „Spremi i preuzmi podatke s mreža sada”, „Spremi i izradi preporuke sada”; šest novih redaka u stanju izvora.
  - **Ploča:** modul Društvene mreže na hostingu više ne ovisi o vidIQ-u: pločice po mreži, „Što raditi da nas gledaju”, „Što prolazi, a što ne” s prebacivanjem mreže, „Uzori”. Podaci se preslikavaju i u stari oblik (`S.ig`, `S.yt`), pa rade Pregled, Tjedni pregled, nalazi, izvoz i alati chata. Izvori lijevo dobivaju Facebook i TikTok. Novi alat chata `drustvene_mreze`; limit alata u hosted načinu 16 → 20.
  - **Prijedlozi bez teksta (nalaz s weba).** Na webu je 12. 11. navečer bila 2.2.0 s jednim otvorenim prijedlogom bez Claudeova teksta: „Claude nije vratio prijedlog u očekivanom obliku”, bez oznake da je odgovor prekinut. `parse_ai()` je tražio barem jednu izmjenu teksta, pa je odgovor samo s koracima (npr. „pričekaj nove podatke”) bio neuspjeh i nakon tri pokušaja prijedlog je ostajao bez teksta. Sada je odgovor s koracima, a bez izmjena, valjan (`usable()`); uputa Claudeu to izričito dopušta. Ako Claude predloži izmjene, a nijedna ne prođe provjeru, to je i dalje neuspio pokušaj. Jednom nakon nadogradnje (`olr_prijedlozi_ponovi`) otvoreni prijedlozi koji su iscrpili pokušaje dobivaju još jedan. Uzrok na webu nije potvrđen dnevnikom (2.2.0 ga nema); od 2.2.1 zadnji poziv je u `ai_poziv`.
  - **Usput popravljeno:** dnevna snimka KPI-ja na hostingu se nije spremala dok Instagram nije učitan (uvjet `igFollowers`); sada ne čeka Instagram.
  - **REST:** `/podaci` vraća `mreze`; `/osvjezi` dijelovi `mreze`, `mreze_uzori`, `mreze_preporuke`; stanje ima `kljucevi.meta|youtube|tiktok` i `mreze`.
  - **MCP:** nova sposobnost `ol-radar/radar-mreze`; `radar-stanje` dobiva `mreze`.
  - **Brisanje dodatka:** `olr_tt_tokens` i `olr_mreze_run` ostaju kao i ostali podaci (brišu se samo uz `OLR_DELETE_DATA`).
  - Testovi: `radar_test.php` 238 provjera (74 nove), `hosted_e2e.py` 51 (15 novih). Sve prema lažnim odgovorima Mete, YouTubea i TikToka; stvarni API-ji nisu pozivani.

## 2026-11-12 (kasno u noć)
- **Dodatak 2.2.1.** Ivan je javio da gumb „Novi prijedlog” u modulu Popravci i AI već dvije verzije javlja „Claude je odgovorio u krivom obliku”.
  - **Uzrok:** `sample.json` u `app/hosted-shim.js` tražio je JSON u običnom tekstu odgovora i vadio ga jednim regularnim izrazom. Odgovor prekinut zbog duljine (`max_tokens`) ili tekst oko JSON-a rušili su parsiranje. Test to nije hvatao jer je lažni odgovor uvijek bio čist JSON, a gumb nije imao e2e provjeru.
  - **Popravak:** odgovor se traži prisilnim pozivom alata `odgovor` sa shemom (`FIX_SCHEMA`, `BRIEF_SCHEMA`). Prekinut ili prazan odgovor ide još jednom, uz uputu da bude sažet. Ako API odbije poziv alata (server tada vraća novu oznaku `olr_ai_zahtjev`, HTTP 400), radi rezervni način s JSON-om u tekstu i parserom koji podnosi ogradu koda i tekst oko objekta.
  - **Poruke:** ploča razlikuje prekinut odgovor od krivog oblika i prikazuje poruku servera (potrošeni krediti, dnevni limit).
  - **Dijagnostika:** `OLR_AI::call()` zapisuje zadnji poziv u stanje izvora (`ai_poziv`: model, završetak, tokeni), vidljivo u Postavkama i u `ol-radar/radar-stanje`.
  - Testovi: e2e sada klikne „Predloži popravak” i provjerava četiri teža slučaja (prekinut, odbijen alat, dvaput prekinut, potrošeni krediti).

## 2026-11-12 (noć)
- **Dodatak 2.2.0** (četiri dodatka koja je Ivan odabrao iz plana razvoja).
  - **Učinak provedenih izmjena** (`OLR_Ucinak`, zbirka `ucinak`). Za prijedlog u stanju „provedeno” koji se odnosi na jednu stranicu dohvaća dnevne podatke Search Consolea za tu stranicu (filtar `includingRegex`, pa adresa s parametrima i bez završne kose crte ulazi u isto) i za glavni upit, samo konačne (`dataState: final`). Uspoređuje jednak broj dana prije i poslije dana provedbe za 7, 14 i 28 dana i uz to promjenu klikova cijelog weba. Ocjena: bolje, bez jasne promjene, lošije ili premalo podataka. Naslov i opis sude se samo po CTR-u; pozicijske izmjene po poziciji glavnog upita, a ako se ona ne miče po klikovima prema trendu weba. Pokreće se nakon novih Google podataka, nakon promjene stanja prijedloga i u satnom prolazu. Dan provedbe može se ispraviti u ploči (`dan` u `prijedlozi_stanje`).
  - **Indeksiranost** (`OLR_Indeks`, zbirka `indeks`). Search Console URL Inspection API istim servisnim računom: za svaku stranicu koja treba biti u indeksu sprema stanje, razlog, Googleovu kanonsku adresu, zadnji posjet i poveznicu na Search Console. 12 stranica po satu, svaka jednom tjedno, problematične svaka 2 dana, najviše 600 provjera dnevno. Novo pravilo analize `rule_index()`: „nije u indeksu” i „druga kanonska adresa” postaju tehnički prijedlozi (stranice mijenjane u zadnjih 7 dana se preskaču).
  - **AI pregled.** `OLR_DFS::parse_aio()` iz stavke `ai_overview` organskog rezultata vadi izvore (s razine pregleda i odlomaka) i provjerava je li među njima naša domena. Redak pozicije dobiva polje `aio`, a povijest `hist.aio` (0, 1, 2). Nova postavka `rank_aio_async` (zadano isključeno) šalje `load_async_ai_overview` i ulazi u procjenu troška.
  - **Kretanje konkurenata.** `OLR_DFS::competitors()` nakon svakog mjerenja iz zadnjih do 8 zapisa `rank_run` računa po domeni top 3, top 10 i udio vidljivosti (pozicija × opća krivulja CTR-a) te po riječi tko je ušao, ispao ili nas pretekao. Sprema se u `rank/konkurenti`.
  - **Ploča:** blok „Učinak izmjene” na kartici provedenog prijedloga (s poljem za dan provedbe), panel „Indeksiranost u Googleu” u Auditu s gumbom „Provjeri sada”, stupac i pločica „AI pregled” u Pozicijama, panel „Kretanje konkurenata” u Konkurenciji, novi odjeljak u „Kako radi”. Sažetak za Claudea (`export/radar`) dobiva `indeksiranost`, `kretanje_konkurenata` i `ucinak_izmjena`.
  - **MCP:** nova sposobnost `ol-radar/radar-indeks`; `radar-pozicije` vraća `ai_pregled` po riječi i `kretanje_konkurenata`, `radar-prijedlozi` polje `mjerenje` uz provedene, `radar-stanje` brojke indeksa i učinka.
  - **Sitno:** dva mjerenja pozicija u istoj sekundi više ne pregaze jedno drugo; satni prolaz ne nastavlja s provjerama ako je već potrajao.
  - Testovi: `radar_test.php` 161 provjera, `hosted_e2e.py` 33.

## 2026-11-12 (kasno navečer)
- **Dodatak 2.1.1** (popravci nakon prvog dana rada 2.1.0 na webu).
  - **Claudeov tekst prijedloga nije stizao.** Jedan poziv za svih pet prijedloga prekinut je zbog duljine (`max_tokens`), pa odgovor nije bio čitljiv. Sada je to jedan kratak poziv po prijedlogu s prisilnim pozivom alata `spremi_prijedlog` (`tool_choice`); ako API takav poziv odbije ili vrati nečitljivo, slijedi jedan pokušaj s običnim JSON odgovorom. Svaki se prijedlog sprema odmah. Što ne uspije, pokušava se ponovno pri satnom prolazu (`fill_due()`), najviše tri puta po prijedlogu. Kvar mreže ili ključa prekida prolaz bez trošenja pokušaja.
  - **„Analiziraj sada” više ne drži preglednik.** Ručna analiza vraća prijedloge odmah i piše tekst najviše 20 s; ostatak se dopisuje u pozadini (`olr_prijedlozi_tekst`), a ploča se sama osvježava laganim pozivom `GET /prijedlozi`. Ako hosting prekine dugi zahtjev, ploča provjeri je li analiza ipak završila prije nego što javi grešku.
  - **DataForSEO „ne radi”.** Mjerenje nikad nije bilo pokrenuto, a ploča je pokazivala „učitavam…” i crvenu poruku. Sada je stanje bez mjerenja napomena s objašnjenjem i cijenom, u modulu Pozicije je gumb „Izmjeri pozicije sada” (potvrda troška u pregledniku, server traži `potvrda=da`), a prikaz se sam osvježava svakih 20 s dok mjerenje traje.
  - **Kvaliteta analize.** Upit mora biti o temi stranice (`OLR_Analiza::relevant()`: riječi upita po korijenu u naslovu, opisu, naslovima, fokus riječi ili adresi). Brend se prepoznaje po korijenu („ljiljan”). Nova postavka „Zanemari upite” (npr. „optika anda”). Stranica mijenjana u zadnjih 14 dana ne dobiva prijedlog naslova i opisa. Tjedni pad traži stvarno izgubljene klikove ili jasan pad pozicije uz dovoljno prikazivanja. Naslovnica i blog kao „stranica bez prave teme” samo kad je pozicija stvarno slaba. Najviše jedan otvoren prijedlog po stranici.
  - **Usklađivanje postojećih prijedloga.** Novi prijedlog bez Claudeova teksta čiji nalaz više ne postoji označava se kao istekao; onaj čiji nalaz i dalje vrijedi dobiva svježe brojke.
  - **Google podaci:** adrese s parametrima (`utm_*`, `rwg_token`) pribrajaju se pravoj stranici (klikovi i prikazivanja se zbrajaju, pozicija je prosjek po prikazivanjima).
  - **Provjere veze:** ishod „Spremi i provjeri…” (Google, DataForSEO, Claude) ostaje zapisan u stanju izvora (`provjera_gsc`, `provjera_dfs`, `provjera_ai`).
  - **MCP:** nova sposobnost `ol-radar/radar-stanje` (kratko stanje bez podataka, oko 1 kB), za provjeru nakon nadogradnje umjesto velikog sažetka.
  - Testovi: `radar_test.php` 119 provjera, `hosted_e2e.py` 27.

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
