# OL Radar – kako Claude koristi alat

Stanje 13. 11. 2026. (popodne; dodatak 2.4.2 je spreman, na webu je još 2.4.1):
- Radar na claude.ai: v3.1.
- WordPress dodatak (Radar na hostingu): **na webu je 2.4.1** (provjereno preko `ol-radar/radar-stanje` 13. 11. popodne). **2.4.2 čeka da je Ivan učita** (zip u Driveu, mapa „Claude – datoteke”). Ključevi: Google, DataForSEO, Anthropic i Meta su `true`, YouTube i TikTok `false`; `nadogradnja` je `null`; model u ploči je Sonnet 5.5 (Ivan ga je prebacio s Opusa).
- Što na webu radi sa stvarnim podacima (13. 11. popodne):
  - Google podaci do 10. 11. (zadnjih 7 dana 139 klikova i 5.302 prikazivanja); pozicije izmjerene 12. 11. (12 od 27 riječi u prvih 40); indeksiranost: 40 od 40 provjereno, 39 u indeksu, `/servis-naocala-sesvete/` nije („Otkriveno – trenutačno nije indeksirano”).
  - Prijedlozi izmjena: 13 ukupno, 6 otvorenih, 4 provedena se mjere. Dio otvorenih su lažni nalazi koje 2.4.2 uklanja (vidi ispod).
  - **Suradnje: 74 kandidata, 3 prijedloga** (oko 0,04 $ po prijedlogu). Ivan je uključio web pretragu (`suradnje.pretraga` = `true`), ali ona na 2.4.1 pada: Anthropic odbija oznaku države HR. Popravljeno u 2.4.2.
  - Društvene mreže: Meta je povezana (Instagram @optikaljiljana 302 pratitelja, 40 objava; Facebook 1.211 pratitelja, 30 objava; 5 preporuka i 6 ideja). Postava i plan za Metu: `claude/OL-Radar-Meta.md`.
  - Claude trošak: studeni 1,15 $ (procjena, jer dio poziva potječe iz verzija prije 2.4.0).
  - **Tjedno mjerenje pozicija je uključeno**, s naknadno učitanim AI pregledima; procjena 0,081 $ po mjerenju.
- **Što donosi 2.4.2 (Ivan, 13. 11.: „popravi ako je previše lažnih nalaza … da ne predlaže gluposti”):**
  - **Glavna stranica upita** je ona s najviše klikova, zatim najbolje pozicije, zatim prikazivanja. Prije: po prikazivanjima, pa je za „optika ljiljana” glavna bila rezervacija (sitelinkovi imaju ista prikazivanja kao glavni rezultat, a rezervaciji su se pribrajale adrese s Google profila tvrtke s `utm_*` i `rwg_token`). `ol-radar/radar-gsc` sada ima polje `brend`.
  - **Kanibalizacija** se ne javlja kad je glavna na poziciji do 1,5, kad su obje u prva tri rezultata, kad je druga ispod 20. mjesta ili ispod 20 % prikazivanja, ni za upite s imenom optike ili konkurenta.
  - **Konkurentski upiti** („optika anda”, „optika jo jo”) se preskaču; „anda” se više ne poklapa sa „standard”.
  - **Statistika:** pad klikova i nizak CTR moraju biti značajni po binomnom testu (p < 0,05; 10 → 4 klika nije pad, p ≈ 0,09). Lokalni upiti („optika sesvete”, „optičar”) imaju upola niže očekivanje CTR-a (karta s tvrtkama iznad rezultata).
  - **Svježe stranice:** stranica mijenjana u zadnjih 14 dana ne dobiva nijedan sadržajni prijedlog (prije samo naslov i opis), pa nema prijedloga „pričekaj učinak izmjene”.
  - **Novi sadržaj** se ne predlaže kad postojeća stranica već pokriva temu (npr. „mrena na oku” → `/katarakta/`). **Kriva stranica u rezultatu** tek uz 30 prikazivanja u 7 dana.
  - **Stari lažni prijedlozi istječu odmah:** prijedlog s tekstom napisan po starim pravilima, koji nova pravila ne potvrđuju, istječe pri prvoj analizi s Google podacima (razlog „nalaz ne prolazi pooštrena pravila analize”); inače bi čekao 21 dan. Prihvaćeni, provedeni i odbijeni se ne diraju.
  - **Popravci:** web pretraga bez oznake države (samo Zagreb i vremenska zona; ako i to odbije, jednom bez lokacije); stranica Kontakt kandidata više ne hvata „kontaktne leće”.
- **Provjereno na stvarnim servisima:** Google, DataForSEO, Anthropic (dnevni prijedlozi i prijedlozi suradnje), Meta (Instagram i Facebook). **Nije provjereno:** Anthropicova web pretraga nakon popravka u 2.4.2, YouTube, TikTok i Ahrefsov javni DR. Prvi stvarni prolaz svakoga treba pogledati (`stanje.izvori.suradnje_trazi`, `suradnje_dr`, `mreze_*`). Nije potvrđeno ni je li Ivan uvezao dopunu arhive (`ol-radar-ahrefs-dopuna-2026-11-13.json`) i veliku arhivu od 11. 11.
- Google servisni račun: `optika-ljiljana-seo-radar@api-project-88503755280.optikaljiljana.hr.iam.gserviceaccount.com` (12. 11.).
- DataForSEO: prijava radi (stanje računa 12. 11.: 0,94 $). Mjerenje pokreće samo Ivan (gumb ili tjedna kvačica); Claude ga ne pokreće.
- OpenSEO tracker radi. Arhiva Ahrefsa je gotova: velika 11. 11., završna 13. 11.

## GSC je glavni izvor, Ahrefs je samo arhiva (Ivan, 12. 11. 2026.)
- **Odluka:** Search Console je najtočniji izvor i koristi se za sve što može dati. Od Ahrefsa se koristi samo arhiva koju imamo; pretplata prestaje 30. 11. 2026.
- **Što GSC pokriva potpuno** (i već radi na hostingu bez Ahrefsa): klikovi, prikazivanja, CTR, prosječna pozicija, upiti, stranice, dnevni niz, indeksiranost (URL Inspection).
- **Što GSC nema, i čime se pokriva:**

  | Potreba | Zašto GSC ne može | Čime se pokriva |
  |---|---|---|
  | Pozicija za ciljanu riječ na određeni dan i mjesto | GSC daje samo prosjek za upite s prikazivanjima | DataForSEO mjerenje (plaća se po mjerenju) |
  | Konkurenti (tko je u top 10, tko nas je pretekao) | GSC vidi samo naš web | isto mjerenje (top 20 po riječi) |
  | Obujam pretraga za riječi na kojima se ne prikazujemo | GSC vidi samo upite s našim prikazivanjima | Google Keyword Planner; arhiva Ahrefsa |
  | Poveznice prema nama | izvještaj postoji samo u sučelju; API ga nema | ručni izvoz iz Search Consolea, uvoz u modul Suradnje |
  | Poveznice konkurenata | GSC vidi samo naš web | završna arhiva Ahrefsa (13. 11. 2026.); po potrebi DataForSEO Backlinks, uz Ivanov OK za trošak |
  | DR | Ahrefsova vlastita brojka | arhiva; po izboru Ahrefsov besplatni javni servis (bez pretplate) |

- **Posljedica za Claudea:** kad Ivan pita o našem webu u Googleu, brojke su iz GSC-a (`ol-radar/radar-gsc`), ne iz Ahrefsa. Ahrefs konektor ne koristi za nove podatke osim ako Ivan izričito zatraži. Završna arhiva je povučena 13. 11. na Ivanov zahtjev.

## Završna arhiva Ahrefsa (13. 11. 2026.)
Ivanov zahtjev: „povuci prije nego pretplata istekne što možeš iz ahrefsa”. Pretplata (Lite) vrijedi do 30. 11. 2026.
- **Jedinice:** potrošeno 159.655 od 200.000 (provjereno 13. 11.); ostaje oko 40.000 do 30. 11. ako Ivan zatraži još nešto.
- **Gdje je:**
  - repozitorij: `data/arhiva/ahrefs-2026-11-13/` (CSV i PSV datoteke, `README.md` s opisom svake);
  - Google Drive, mapa „Claude – datoteke”: `ahrefs-zavrsna-arhiva-2026-11-13.zip`;
  - dodatak 2.4.1: `data/suradnje-pocetno.json` (74 kandidata, gradi ga `tools/build-suradnje-seed.py`);
  - hosting: `uvoz-za-hosting.json` iz arhive, kroz Postavke → „Uvoz iz Radara na claude.ai” (pet skupina `arch_kw/hr-dopuna-1` do `-5` s 239 ključnih riječi i `arch_misc/zavrsna-2026-11-13`).
- **Što je povučeno:**
  - domene koje povezuju pet konkurenata i pet srodnih tvrtki (ghetaldus.hr, svjetlost.hr, optometrija.net, eyerim.hr, oculus-zagreb.hr), bez spama, samo dofollow;
  - 83 primjera stranica s poveznicom (kako su do nje došli);
  - naše poveznice bez spama, tekstovi poveznica, pokidane poveznice;
  - DR i broj domena koje nas povezuju, mjesečno od siječnja 2025.;
  - organski konkurenti; 239 ključnih riječi kojih nije bilo u arhivi od 11. 11.; ključne riječi s kojima su optometrija.net i svjetlost.hr u prvih deset.
- **Što namjerno nije:** ponovno povlačenje univerzuma ključnih riječi, SERP-ova i petogodišnje povijesti (to je u arhivi od 11. 11.), poveznice konkurenata izvan Hrvatske ispod DR 25, spam domene.
- **Nalazi:**
  - Naš DR je 0 (0,1 do rujna 2025.). Konkurenti: optika-anda.com 28, jojo-optika.hr 27, adrialece.hr 25, livision-optika.com 16, optika-dado.hr 7.
  - Broj domena koje nas povezuju narastao je s 47 (siječanj 2025.) na 577 (studeni 2026.); gotovo sve su stranice prodavača poveznica s oglasnim tekstom. Nismo ih naručili i ne dižu DR.
  - Bez spama nas povezuje šest domena; dofollow su samo optike.hr i hr.voovuu.com (DR oko 2 do 3). Pokidanih poveznica nema.
  - Konkurenti do poveznica dolaze: plaćenim promo i native člancima u nacionalnim i modnim medijima (najveći dio), objavama uz humanitarne akcije, Svjetski dan vida i otvorenja, stručnim savjetima na portalima, stranicama pogodnosti za članove (sindikati, HAK, kartične kuće, osiguranja), imenicima (brzipregled.hr povezuje svih pet), stranicama „Poslovna suradnja” i referencama dobavljača; ghetaldus.hr je i partner programa HOK ZNA za obrtnike.
  - Ahrefs za našu domenu vidi devet ključnih riječi. Najbliži organski konkurent je optometrija.net (DR 30), prvi za mnoge teme o zdravlju oka.
- **Izvedivost u modulu Suradnje:** 1 najizvedivije (18 kandidata: imenici, programi pogodnosti, lokalni portali, udruge, obrazovanje), 2 traži stručni sadržaj (27: portali za roditelje, studente, umirovljenike, zdravlje), 3 većinom plaćene objave (29: nacionalni i modni mediji). Ocjena, naziv, vrsta i opis načina su urednička procjena; brojke su iz Ahrefsa.
- **Posljedica za Claudea:** kad Ivan pita kome se prvo obratiti, kreni od kandidata s izvedivošću 1 (`ol-radar/radar-suradnje` sa `svi: true` daje `prioritet`, `nacin`, `primjer`). Programi pogodnosti traže stvarnu pogodnost za članove: uvjete određuje Ivan, ne izmišljaj popuste.

**Aplikacija:** https://claude.ai/artifact/FwKJowHqdJUASipjeAkoBT
**Slug za alate (db_get / db_query / db_list):** 78f5630e-c158-4ddc-b32b-b3b711130c3e
**Kod:** GitHub repozitorij `ol-radar` (README, testovi, CHANGELOG)

## Pravilo
Svaka analiza i svaki prijedlog promjene na optikaljiljana.hr kreće od Radara.
- **Kad je dodatak 2.0 postavljen:** preko konektora „Optika Ljiljana web” pozovi `ol-radar/radar-sazetak` (velik odgovor, oko 25 tisuća tokena; za samu provjeru radi li sve od 2.1.1 koristi `ol-radar/radar-stanje`). Za svježe Google brojke koristi `ol-radar/radar-gsc`, a za pozicije `ol-radar/radar-pozicije`.
- **Od 2.1.0 gotovi prijedlozi izmjena čekaju na serveru:** `ol-radar/radar-prijedlozi` (zadano: otvoreni). Kad Ivan pita „što mijenjamo”, kreni od njih: imaju dokaze s brojkama, sadašnji i predloženi tekst i stanje. Stanje (prihvaćeno, provedeno, odbijeno) mijenja Ivan u ploči; konektor je samo za čitanje.
- **Od 2.2.0 učinak izmjena mjeri server:** kad Ivan pita „je li pomoglo”, pozovi `ol-radar/radar-prijedlozi` sa `stanje: provedeno`. Uz svaki provedeni prijedlog je polje `mjerenje` (Search Console prije i poslije za 7, 14 i 28 dana, trend cijelog weba, ocjena, sažetak). Ne računaj to ručno iz GSC-a ako mjerenje postoji.
- **Indeksiranost:** `ol-radar/radar-indeks` (zadano samo stranice s problemom). **Konkurenti i AI pregled:** `ol-radar/radar-pozicije` s `konkurenti: true`.
- **Suradnje i poveznice (od 2.4.0):** `ol-radar/radar-suradnje`. Kad Ivan pita kako povećati DR, kome se obratiti ili s kim surađivati, kreni odavde: kandidati s prijedlogom, kontaktom i stanjem, poveznice koje vidi Google, DR nas i konkurenata.
- **Društvene mreže (od 2.3.0):** `ol-radar/radar-mreze`. Kad Ivan pita što objavljivati ili zašto nešto ne prolazi, kreni odavde: po mreži medijan, objave iznad i ispod uobičajenog, obrasci i zadnje preporuke. `objave: true` daje popis objava, `uzori: true` tuđi gledani sadržaj. Za sadržaj i ton vrijede pravila brenda (premium, bez emojija, bez izmišljenih cijena i tvrdnji).

## Pravilo za nove verzije dodatka (Ivan, 12. 11. 2026.)
**Nova verzija ne smije obrisati već spremljene ključeve.** Zato:
- Zip se gradi samo s `bash tools/release.sh`. Skripta diže svježi testni WordPress i pokreće sve testove, među njima `tests/wordpress/upgrade_test.sh`: nadogradnja sa svake izdane verzije 2.x (popis `tests/wordpress/izdanja.txt`), deaktivacija/aktivacija, brisanje i ponovna instalacija. Ključevi, postavke i podaci moraju ostati isti.
- Nakon izdanja dodaj redak „verzija commit” u `tests/wordpress/izdanja.txt`, da je sljedeći test pokrije (git oznake se iz ovog okruženja ne mogu poslati na GitHub).
- Ne mijenjaj način šifriranja (`OLR_Settings::encrypt`, ključ iz `wp_salt('auth')`) ni imena opcija `olr_secrets` i `olr_settings` bez migracije koja čita stari oblik i testa za nju.
- `set_secret()` se zove samo na izričit unos ili „obriši” u Postavkama. Svaki novi gumb na stranici Postavke mora biti unutar glavnog obrasca (bez ugniježđenih obrazaca) i pokriven e2e testom „Spremi postavke čuva ključeve”.
- Nakon što Ivan učita novu verziju, provjeri `ol-radar/radar-stanje` (do 2.1.0: `ol-radar/radar-sazetak`): `stanje.verzija`, `stanje.kljucevi` (`gsc`, `dataforseo` i `anthropic` moraju biti `true`; `meta`, `youtube` i `tiktok` su `true` tek kad ih Ivan upiše) i `stanje.nadogradnja` (mora biti `null`).
- Popis tajni `OLR_Settings::NAMES` smije se samo dopunjavati (2.3.0 je dodao `meta_token`, `yt_key`, `tt_secret`; 2.4.0, 2.4.1 i 2.4.2 nisu dodali nijednu). TikTok tokeni su u zasebnoj opciji `olr_tt_tokens`, da njihova obnova nikad ne dira `olr_secrets`.
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
- **Pohrana:** tablica `ol_radar_docs`, s istim zbirkama kao artefakt, plus `gsc`, `rank`, `rank_run`, `serp`, `prijedlozi`, `prijedlozi_stanje`, `ucinak`, `indeks`, `mreze`, `suradnje`, `suradnje_stanje` i `suradnje_dr`.
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
- **Suradnje i poveznice (2.4.0, prošireno i popravljeno u 2.4.1):**
  - **Cilj:** prave poveznice i spominjanja (dižu DR) kroz suradnje koje imaju smisla i bez poveznice. Kupnja i razmjena poveznica se ne predlažu. Ništa se ne šalje nikome; poruku šalje Ivan.
  - **Kandidati:** završna arhiva Ahrefsa (74 domene, priložena dodatku 2.4.1; u 2.4.0 ih je bilo 20), tvrtke koje Claude nađe web pretragom, ručno dodane. Uz kandidata iz arhive: tko već ima poveznicu s te domene i koliko, izvedivost (1 do 3), kako su drugi došli do poveznice i primjer stranice.
  - **Prijedlog po kandidatu:** zašto, što ponuditi, do 3 koraka, nacrt poruke, kome (uloga). Javni kontakt čita server s weba kandidata; Claude ga ne dobiva i ne izmišlja. Kad su poveznice na toj domeni većinom plaćene, uputa traži besplatan put (stručni komentar, tema za rubriku, lokalna vijest).
  - **Jedan Claudeov poziv po prijedlogu (od 2.4.1).** Redoslijed: ručno dodani i oni iz web pretrage, zatim po izvedivosti, broju tvrtki koje već imaju poveznicu i DR-u. Satni prolaz piše do `suradnje_max` (5) prijedloga tjedno; klik „Napiši prijedloge” obično dva do tri, najviše tri klika dnevno. Kandidat s tri neuspjela pokušaja više ne ide Claudeu.
  - **Raspored i trošak:** prijedlozi jednom tjedno (postavka `suradnje_auto`, uključeno); po prijedlogu oko 0,04 $ (izmjereno 13. 11. na Opusu 5.5; točan iznos je u „Claude trošak”). Traženje novih tvrtki web pretragom tjedno samo uz kvačicu (`suradnje_trazi`, isključeno; Anthropic naplaćuje 0,01 $ po upitu uz tokene), na gumb uvijek uz potvrdu troška. DR tjedno samo uz kvačicu (`suradnje_dr`, isključeno; besplatno), na gumb uvijek.
  - **Mjerilo napretka:** poveznice koje vidi Google. Ivan jednom mjesečno u Search Consoleu otvori Veze → Vanjske veze → Izvoz i CSV učita u modulu Suradnje. Domene koje po nazivu izgledaju kao prodavači poveznica samo su označene.
  - **Stanja** vodi Ivan u ploči (novo, kontaktirano, dogovoreno, dobiveno, odbijeno). Uz adresu stranice s poveznicom server provjeri postoji li poveznica i je li nofollow.
  - **Claude preko konektora:** `ol-radar/radar-suradnje` (zadano kandidati s prijedlogom; `stanje`, `svi: true`, `limit`). Kad Ivan pita kome se obratiti ili kako dići DR, kreni odavde. Stanja ne mijenjaš (konektor je samo za čitanje).
  - **Claude API i razmišljanje (naučeno 13. 11.):** Opus 5.5 uvijek razmišlja i to se ne može isključiti; razmišljanje se naplaćuje kao izlaz i ulazi u `max_tokens`. Zato svaki serverski posao traži jedan mali rezultat po pozivu i ima širok okvir (8000 tokena je gornja granica, ne potrošnja). Dubina razmišljanja može se smanjiti parametrom `output_config.effort` (zadano `medium`); dodatak ga zasad ne šalje.
- **Claude trošak (2.4.0):** Postavke → „Claude trošak” (danas i po mjesecima), zaglavlje ploče, `stanje.ai.trosak`. Računa se iz stvarnih tokena svakog poziva po cjeniku modela (stanje 12. 11. 2026.: Opus 5.5 4/20 $, Sonnet 5.5 2/10 $, Haiku 5.5 0,10/0,50 $ po milijunu tokena; web pretraga 0,01 $ po upitu). Mjeseci s pozivima prije 2.4.0 su procjena. Mjerodavan je Claude Console.
- **Dnevni prijedlozi izmjena (2.1.0, dorađeno u 2.1.1 i 2.4.2):**
  - Pokreću se sami kad stignu novi Google podaci (jednom dnevno), ili gumbom „Analiziraj sada” (ploča, Postavke; najviše 6 puta dnevno).
  - Analiza je u PHP-u (`OLR_Analiza`): nizak CTR na visokoj poziciji, upiti blizu vrha (4–15), kanibalizacija u zadnjih 7 dana, tjedni pad, ciljane riječi (pad, ispadanje, kriva stranica), tehnički nalazi crawla, upiti bez prave stranice.
  - Ne ulaze: brend (postavka „Riječi brenda”, i po korijenu riječi), upiti s popisa „Zanemari upite”, upiti s imenom konkurenta (postavka „Konkurenti” i optike iz mjerenja, od 2.4.2), noindex stranice, upiti koji nisu o temi stranice, stranice mijenjane u zadnjih 14 dana (od 2.4.2 za sve sadržajne nalaze), tjedni pad bez izgubljenih klikova ili bez statističke značajnosti.
  - **Od 2.4.2:** upit se veže uz glavnu stranicu (klikovi → pozicija → prikazivanja); nizak CTR i pad moraju biti značajni (binomni test, p < 0,05); lokalni upiti imaju upola niže očekivanje CTR-a; kanibalizacija ne kad smo do 1,5, kad su obje u prva tri ili je druga daleko. Napomene u `prijedlozi.zadnje.napomene` kažu što je preskočeno (svježe stranice, kolebanje klikova, prvo mjesto).
  - **Kad Ivan kaže da je prijedlog glup:** prvo pogledaj dokaze u prijedlogu i `ol-radar/radar-gsc` (glavna stranica, `brend`), pa nađi pravilo koje ga je pustilo. Popravak ide u `OLR_Analiza` s testom iz stvarnog slučaja, i povećava `OLR_Analiza::PRAVILA`, da stari lažni prijedlozi odmah isteknu.
  - Tekst izmjene piše Claude (Anthropic ključ na serveru), jedan kratak poziv po prijedlogu; brojke i „sada” uvijek su iz podataka. Ako tekst ne uspije ili nema ključa, prijedlog vrijedi s koracima iz podataka, a tekst se dopisuje u pozadini odmah nakon ručne analize i pri satnom prolazu (najviše tri pokušaja). U odgovoru `radar-prijedlozi` to su polja `ai`, `ai_pokusaja`, `ai_greska` i `ceka_tekst`.
  - Najviše 5 novih dnevno, najviše 2 po vrsti, najviše jedan otvoren po stranici (osim tehničkih). Otvoren nalaz se ne ponavlja; odbijeni se ne vraća 45 dana, provedeni 28 dana. Novi prijedlog čiji nalaz više ne postoji označava se kao istekao (`isteklo`, razlog u `isteklo_razlog`): bez Claudeova teksta odmah, s tekstom nakon 21 dan, a napisan po starijim pravilima analize (polje `pravila` manje od `OLR_Analiza::PRAVILA`) odmah.
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
  - `ol-radar/radar-suradnje` (od 2.4.0; zadano kandidati s prijedlogom; `stanje`: novo, kontaktirano, dogovoreno, dobiveno, odbijeno; `svi: true`; `limit` do 100);
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
- **arch_kw, arch_hist, arch_serp, arch_comp, arch_misc:** jednokratna arhiva Ahrefsa. Gotova 11. 11. u 15:57: 222 zadatka, 0 grešaka, 107.618 jedinica. Dopuna od 13. 11. (239 ključnih riječi i sažetak poveznica) je u datoteci `uvoz-za-hosting.json` i uvozi se na hostingu; u bazi na claude.ai je nema.
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
- **Ahrefs:** samo arhiva (Ivanova odluka 12. 11. 2026.; pretplata prestaje 30. 11. 2026.): velika od 11. 11. i završna od 13. 11. Iznimka po izboru: besplatni javni DR, koji ne traži pretplatu.

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
- **Verzija:** na webu 2.4.1 (13. 11. 2026.); 2.4.2 čeka učitavanje.
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
- **Google Business Profile u Radaru (Ivanov zahtjev 12. 11.; sljedeća verzija):** analiza profila i prijedlozi. Službene brojke (pozivi, upute, klikovi na web, upiti) traže Googleovo odobrenje pristupa API-ju; zahtjev „Application for Basic API Access” podnosi Ivan iz Google Cloud projekta, a odgovor stiže za do dva tjedna. Javni dio (ocjena, recenzije, objave, usporedba s konkurentima) može ranije preko DataForSEO-a, uz Ivanov OK za trošak.
- Mjesečni snimak SERP-ova za konkurente preko OpenSEO-a (oko 135 kredita), uz Ivanov OK.
- Faza 2 WP mosta: upis Rank Math naslova i opisa s dnevnikom i vraćanjem.
