# OL Radar – most za Claudea (v2.4.0)

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
  - Audit weba, Društvene mreže, Popravci i AI, Arhiva, Kako radi.
- **Pristup:** samo administratori (`manage_options`). Ploča se otvara na `wp-admin/admin.php?page=ol-radar`, u radnom dijelu wp-admina (WP izbornik ostaje lijevo). Svijetla tema je zadana, a tamna je na prekidaču u izborniku ploče; izbor se pamti u pregledniku.
- **Podaci:** u tablici `{prefix}ol_radar_docs` (zbirka + id + JSON), u istom obliku kao baza Radara na claude.ai.
- **Claude u ploči:** Anthropic Messages API preko servera; ključ ne napušta server. Alate (podatke Radara) izvršava preglednik.
  - Prijedlog popravka i brief traže strukturiran odgovor. Od 2.2.1 Claude ga daje pozivom alata `odgovor` (prisilni poziv), pa nema teksta oko JSON-a. Ako je odgovor prekinut zbog duljine, ide jedan sažetiji pokušaj; ako API odbije poziv alata, radi rezervni način s JSON-om u tekstu.
  - Zadnji poziv (model, kako je odgovor završio, broj tokena; bez sadržaja) vidi se u stanju izvora kao „Zadnji poziv Claudea”.
- **Društvene mreže** (Instagram, Facebook, YouTube, TikTok) skuplja server od 2.3.0; vidi odjeljak „Društvene mreže”.
  - Modul Društvene mreže na hostingu: pločica po mreži, „Što raditi da nas gledaju” (Claudeove preporuke i ideje), „Što prolazi, a što ne” (po mreži) i „Uzori”.
  - Iste podatke koriste Pregled (pločica s pratiteljima), Tjedni pregled, nalazi u Popravcima, izvoz za Claudea i alat `drustvene_mreze` AI analitičara.

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

- **Odgovor samo s koracima (od 2.3.0).** Ako se na stranici nema što mijenjati (treba pričekati nove podatke, radnja je u Search Consoleu), Claude vraća korake bez izmjene teksta i to je valjan prijedlog. Do 2.2.1 takav se odgovor vodio kao neuspjeh, pa je prijedlog nakon tri pokušaja ostajao „bez napisanog teksta”. Nakon nadogradnje na 2.3.0 takvi prijedlozi jednom dobivaju još jedan pokušaj.

### Učinak provedenih izmjena (od 2.2.0)
- **Što radi:** kad prijedlog u ploči označiš kao „provedeno”, dodatak sam usporedi Search Console za tu stranicu prije i poslije (`OLR_Ucinak`). Ne treba ništa pokretati.
- **Usporedba:** jednak broj dana prije i poslije dana provedbe, za 7, 14 i 28 dana. Sam dan provedbe se ne računa. Koriste se samo konačni Google podaci (`dataState: final`), pa prvi rezultat stiže oko 10 dana nakon izmjene.
- **Trend cijelog weba:** uz stranicu stoji i promjena klikova cijelog weba u ista dva razdoblja, da sezona ne izgleda kao učinak izmjene.
- **Ocjena:**
  - naslov i opis: sudi se samo po CTR-u (rast od barem 15 % i pola postotnog boda);
  - upiti blizu vrha, pozicije, kanibalizacija: po poziciji glavnog upita (pomak od barem 1 mjesta), a ako se ona ne miče, po klikovima u odnosu na trend weba;
  - ostalo: klikovi u odnosu na trend weba (razlika od barem 20 postotnih bodova i 3 klika);
  - ispod 30 prikazivanja u oba razdoblja piše „premalo podataka”.
- **Dan provedbe:** zadano je dan kad si kliknuo „provedeno”. Ako je izmjena napravljena ranije, upiši stvarni dan na kartici; mjerenje se tada računa iznova.
- **Ne mjeri se po stranici:** novi sadržaj i skupni tehnički nalazi (isti nalaz na puno stranica).
- **Stranica se traži** s parametrima i bez njih (`?utm_…`, `?rwg_token=…`) te sa završnom kosom crtom i bez nje.
- Usporedba ne dokazuje uzrok; zato su uz ocjenu uvijek i brojke.

### Indeksiranost stranica (od 2.2.0)
- **Što radi:** za svaku stranicu koja bi trebala biti u indeksu pita Google (Search Console URL Inspection API, isti servisni račun): je li u indeksu, razlog ako nije, koju je adresu Google odabrao kao kanonsku i kad ju je Googlebot zadnji put posjetio (`OLR_Indeks`).
- **Koje stranice:** objavljene, odgovaraju s 200, nisu noindex i nemaju kanonsku adresu koja pokazuje drugamo. Najprije one s najviše prikazivanja.
- **Kada:** 12 stranica svaki sat; svaka stranica jednom tjedno, stranice s problemom svaka 2 dana. Gumb „Provjeri sada” u modulu Audit weba provjeri sve odmah.
- **Kvota:** Google dopušta 2.000 provjera dnevno po svojstvu; dodatak ih troši najviše 600. Besplatno.
- **Prijedlozi:** stranica koja nije u indeksu ili ima drugu kanonsku adresu ulazi u dnevnu analizu kao tehnički nalaz. Stranice mijenjane u zadnjih 7 dana se preskaču, jer ih Google još obrađuje.
- **Samo čitanje:** provjera ne traži indeksiranje i ne mijenja web.
- **Ako servisni račun nema pristup svojstvu,** stanje izvora „Indeksiranost stranica” to jasno kaže, a novi pokušaj ide tek za 6 sati.

### AI pregled i kretanje konkurenata (od 2.2.0)
- **Googleov AI pregled (AI Overview)** bilježi se iz istog rezultata koji mjerenje pozicija ionako dohvaća, bez doplate: za koje se riječi pojavljuje, citira li nas i koje domene citira. U modulu Pozicije to je stupac „AI pregled” i pločica.
  - „postoji” znači da ga Google učitava naknadno, pa izvori nisu stigli. Takve preglede DataForSEO isporučuje uz doplatu od 0,0006 $ po riječi, i to samo kad pregled postoji; uključuje se u Postavkama (zadano isključeno).
  - Povijest po mjerenju: 0 = nema ga, 1 = ima ga, 2 = ima ga i citira nas.
- **Kretanje konkurenata** računa se nakon svakog mjerenja iz spremljenih prvih 20 rezultata po riječi (zadnjih do 8 mjerenja), bez dodatnog troška:
  - po domeni: broj riječi u top 3 i top 10 te udio vidljivosti, sada i u prethodnom mjerenju, s trendom;
  - po riječi: tko je ušao u top 10, tko je ispao i tko nas je pretekao.
  - Udio vidljivosti je procjena udjela klikova među izmjerenim riječima po poziciji (opća krivulja CTR-a), bez volumena pretraga. Po riječi se računa najbolji rezultat domene.
  - Panel je u modulu Konkurencija. Za promjene trebaju barem dva mjerenja.

### Društvene mreže (od 2.3.0)
- **Što skuplja** (samo čitanje, dvaput dnevno; ništa se ne objavljuje, ne briše i ne mijenja):

  | Mreža | Kako | Što daje |
  |---|---|---|
  | Instagram | Meta Graph API, token sistemskog korisnika | pratitelji; po objavi pregledi, doseg, lajkovi, komentari, dijeljenja, spremanja, prosječno gledanje reela |
  | Facebook | Meta Graph API, isti token | pratitelji stranice; po objavi pregledi (`post_media_view`), doseg (`post_total_media_view_unique`), reakcije, komentari, dijeljenja |
  | YouTube | Data API v3, API ključ | pretplatnici; po videu pregledi, lajkovi, komentari, trajanje (do 60 s vodi se kao kratki video) |
  | TikTok | Display API, OAuth (Sandbox je dovoljan za vlastiti račun) | pratitelji; po videu pregledi, lajkovi, komentari, dijeljenja, trajanje |

- **Što prolazi, a što ne.** Svaka objava zadnjih 180 dana uspoređuje se s medijanom vlastite mreže („×” = pregledi objave prema tom medijanu). 1,5× i više je iznad uobičajenog, 0,5× i manje ispod. Za manje od pet objava nema zaključaka.
- **Obrasci** (vrsta, trajanje, dan u tjednu, doba dana, pitanje na početku opisa) navode se tek kad skupina ima barem tri objave i odstupa barem 30 %. To je naznaka, ne pravilo.
- **Uzori** (jednom tjedno):
  - YouTube: za svaku temu iz Postavki najgledaniji videi zadnjih 120 dana, uz omjer pregleda i pretplatnika kanala. Jedna tema troši 100 jedinica dnevne kvote (10.000, besplatno).
  - Instagram: zadnje objave javnih profesionalnih profila koje navedeš (Business Discovery), prema medijanu tog profila.
  - TikTok i Facebook ne daju službeni pristup tuđim objavama.
- **Što raditi.** Claude jednom tjedno (ili gumbom „Izradi preporuke”, najviše četiri puta dnevno) dobije brojke i kratke opise objava i vrati do pet preporuka s koracima i do šest ideja za objave. Odgovor stiže pozivom alata (od 2.4.0 traženim uputom, jer Opus 5.5 i Sonnet 5.5 odbijaju prisilu); prekinut odgovor traži se jednom ponovno, kraće.
- **Meta metrike:** traže se u jednom batch pozivu. Objava kojoj Meta odbije širi skup metrika dobiva uži. Ako batch ne prođe, čita se objava po objava.
- **TikTok povezivanje:** „Spremi i poveži TikTok” vodi na TikTokovu stranicu za odobrenje (dopuštenja `user.info.basic`, `user.info.stats`, `video.list`). Povratak je na `…/wp-json/ol-radar/v1/oauth/tiktok`; tu adresu treba upisati kao Redirect URI u TikTok aplikaciji.
  - Povratna adresa je javna, ali bez jednokratnog `state` koda izdanog administratoru (vrijedi 15 minuta) ne mijenja ništa.
  - Pristupni token vrijedi 24 sata i obnavlja se sam; odobrenje vrijedi godinu dana, nakon toga treba ponovno „Poveži TikTok”.
- **Veza:** pozivi prema mrežama koriste isti rezervni način kao Google (ponavljanje preko IPv4).
- **Ne radi (zasad):** YouTube zadržavanje publike i izvori prometa (traže YouTube Analytics uz OAuth), preskakanje reela u prvim sekundama (Meta ga ne daje pouzdano za sve objave).

### Suradnje i poveznice (od 2.4.0)
- **Čemu služi.** Prave poveznice s tuđih webova dižu Domain Rating i vidljivost. Modul vodi popis mogućih partnera i za svakoga priprema prijedlog: zašto baš on, što ponuditi, koraci, nacrt poruke i javni kontakt. Ništa se ne šalje nikome; poruku šalje vlasnik.
- **Odakle kandidati:**
  - priložena arhiva Ahrefsa od 12. 11. 2026. (`data/suradnje-pocetno.json`): 20 domena koje povezuju konkurente, a ne nas, s brojem poveznica po konkurentu;
  - tvrtke i ustanove koje Claude nađe web pretragom (gumb „Potraži nove tvrtke”, uz potvrdu troška; tjedno samo ako se uključi u Postavkama);
  - ručno dodane domene.
- **Što radi server, a što Claude.** Server provjeri da web kandidata postoji i s njega pročita javni kontakt (stranica Kontakt, e-pošta, telefon). Claude piše prijedlog iz domene, vrste, DR-a i broja poveznica konkurenata. Claude ne dobiva e-adrese i ne navodi imena osoba, samo ulogu.
- **Pravila prijedloga:** bez kupnje i razmjene poveznica; plaćeni članak samo uz napomenu da je poveznica sponzorirana i ne diže DR; bez izmišljenih cijena i brojki.
- **Stanja:** novo → kontaktirano → dogovoreno → dobiveno (ili odbijeno). Uz „dogovoreno” i „dobiveno” upisuje se adresa stranice s poveznicom; server provjeri postoji li poveznica i je li `nofollow`/`sponsored`.
- **Poveznice koje vidi Google (glavno mjerilo).** Search Console API ne daje izvještaj o vezama, pa se izvoz iz sučelja (Veze → Vanjske veze → Izvoz, CSV) učitava u modulu. Prihvaća tablice „Najčešće web-lokacije s vezama” i „Najnovije veze”. Svaki uvoz pokazuje nove domene od prošlog; kandidat koji nas već povezuje dobiva oznaku. Domene koje po nazivu izgledaju kao prodavači poveznica samo su označene (gruba oznaka, ne presuda).
- **Domain Rating.** DR je Ahrefsova brojka i ne može se dobiti iz Search Consolea. Zadano se prikazuje arhiva od 12. 11. 2026. (naš DR 0; konkurenti 7 do 28). Tjedna provjera Ahrefsovim besplatnim javnim servisom (bez pretplate i ključa) uključuje se u Postavkama; gumb „Provjeri DR sada” radi uvijek. Oznaka „Domain Rating by Ahrefs” je uvjet licence.
- **Raspored:** prijedlozi jednom tjedno (ako je uključeno, zadano jest), nove tvrtke i DR jednom tjedno samo ako su uključeni; svaki posao u svom satnom prolazu. Ručno najviše tri puta dnevno po poslu.

### Claude trošak (od 2.4.0)
- **Gdje:** Postavke → „Claude trošak” (danas i po mjesecima: pozivi, ulazni i izlazni tokeni, web pretrage, iznos u USD), zaglavlje ploče (iznos za mjesec i danas), stanje izvora (iznos zadnjeg poziva), `ol-radar/radar-stanje`.
- **Kako se računa:** iz stvarnih tokena svakog poziva i cjenika modela na dan 12. 11. 2026.: Opus 5.5 4 $ ulaz i 20 $ izlaz, Sonnet 5.5 2 $ i 10 $, Haiku 5.5 0,10 $ i 0,50 $ po milijunu tokena (zahtjev s više od 100.000 ulaznih tokena 0,50 $ i 2,50 $); web pretraga 0,01 $ po upitu. Cjenik je u `OLR_AI::PRICES`; kad se cijene promijene, mijenja se ondje.
- **Ograničenja:** broje se samo pozivi OL Radara. Mjeseci s pozivima prije 2.4.0 označeni su kao procjena („oko”). Mjerodavan je iznos u Claude Consoleu.

### Claude API: alat se traži uputom, ne prisilom (od 2.4.0)
- Opus 5.5 i Sonnet 5.5 odbijaju `tool_choice` vrste `tool` i `any` (HTTP 400). `OLR_AI::call()` zato šalje zadani način i u sistemsku poruku dodaje uputu da se odgovori pozivom alata. Ako alat nije pozvan, ide jedan ponovljeni pokušaj s podsjetnikom.
- Web pretragu i dulji odgovor (do 8.000 tokena) mogu uključiti samo poslovi na serveru; REST posrednik ploče ne može.

### Izvori i trošak

| Izvor | Kako | Trošak |
|---|---|---|
| Google Search Console | servisni račun, JWT RS256, opseg `webmasters.readonly`, svakih 12 h | 0 |
| Pozicije | DataForSEO SERP API, standardni red, mobitel, Zagreb, do 40. mjesta | 0,0006 $ po 10 rezultata po riječi; 27 riječi ≈ 0,065 $ po mjerenju |
| Audit iznutra | crawl dodatka | 0 |
| Indeksiranost | Search Console URL Inspection API, isti servisni račun | 0 |
| Učinak izmjena | Search Console API (dnevni podaci po stranici) | 0 |
| Društvene mreže | Meta Graph API, YouTube Data API v3, TikTok Display API | 0 (tjedne preporuke: nekoliko centi API kredita) |
| Suradnje i poveznice | arhiva Ahrefsa (priložena), webovi kandidata, Claude; po izboru Anthropicova web pretraga i Ahrefsov javni DR | jedan Claudeov poziv tjedno; web pretraga 0,01 $ po upitu uz tokene; DR 0 |
| Poveznice koje vidi Google | ručni izvoz iz Search Consolea (API ih ne daje) | 0 |
| Claude u ploči | Anthropic API; Ultra plan uključuje mjesečne API kredite | po upotrebi, uz dnevni limit poziva |

- **Mjerenje pozicija** pokreće se samo uz tvoju odluku:
  - gumbom „Izmjeri pozicije sada” u modulu Pozicije (od 2.1.1; prije slanja traži potvrdu troška, a prikaz se sam osvježava dok rezultati ne stignu) ili u Postavkama;
  - uključenim tjednim rasporedom (nedjelja navečer, nadoknada nakon 8 dana).
- **Svako mjerenje** usput sprema top 20 organskih rezultata po riječi (za model konkurencije i kretanje konkurenata) i Googleov AI pregled kad ga ima.
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
4. **Društvene mreže** (od 2.3.0; sve pristupne podatke upisuje vlasnik):
   - **Meta token** sistemskog korisnika s trajanjem „Never” i dopuštenjima `pages_show_list`, `pages_read_engagement`, `read_insights`, `instagram_basic`, `instagram_manage_insights`. Sistemskom korisniku treba dodijeliti Facebook stranicu i Instagram račun. Instagram mora biti profesionalni račun povezan sa stranicom.
   - **YouTube:** API ključ (Google Cloud → YouTube Data API v3) i kanal (`@oznaka` ili ID); teme za uzore.
   - **TikTok:** Client key i Client secret iz TikTok aplikacije (Sandbox), Redirect URI prikazan u Postavkama, zatim „Spremi i poveži TikTok”.
   - „Spremi i preuzmi podatke s mreža sada” i „Spremi i izradi preporuke sada”.
5. **Provjere:** gumbi „Spremi i provjeri…” uz svaki odjeljak (Google, DataForSEO besplatno, Claude). Najprije spremaju upisano, pa provjeravaju; rezultat se vidi na vrhu stranice.
6. **Uvoz iz Radara na claude.ai:** u claude.ai Radaru otvori Arhiva → „Izvezi za hosting”, pa ovdje učitaj datoteku. Uvozi arhivu Ahrefsa, nalaze, plan, snimke i postavke. Podatke izvora (gsc, rank, serp) uvoz ne dira.

### Ključevi
- U bazi se spremaju šifrirano (libsodium, ključ izveden iz WordPressovih soli) i nikad se ne vraćaju u preglednik. Stranica Postavke prikazuje samo adresu servisnog računa ili zadnja 4 znaka.
- **Sigurnije:** konstante u `wp-config.php`, koje imaju prednost:
  ```php
  define( 'OLR_GSC_SA_FILE', '/home/korisnik/olr/servisni-racun.json' ); // izvan public_html
  define( 'OLR_DFS_LOGIN', '...' );
  define( 'OLR_DFS_PASSWORD', '...' );
  define( 'OLR_ANTHROPIC_KEY', '...' );
  define( 'OLR_META_TOKEN', '...' );
  define( 'OLR_YT_KEY', '...' );
  define( 'OLR_TT_SECRET', '...' );
  ```
- **TikTok tokeni** (pristupni i za obnovu) spremaju se šifrirano u zasebnu opciju `olr_tt_tokens`, pa njihova obnova nikad ne dira `olr_secrets`.
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
  - dnevna analiza s prijedlozima, kad ima novih Google podataka;
  - učinak provedenih izmjena (inače ide odmah nakon novih Google podataka);
  - mala serija provjera indeksiranosti.
- **Pouzdanije od WordPressovog crona** je cron na hostingu, svakih 15 minuta:
  `wget -q -O - https://www.optikaljiljana.hr/wp-cron.php?doing_wp_cron >/dev/null 2>&1`

### REST (ol-radar/v1, samo administratori, WordPressov nonce)

| Ruta | Što radi |
|---|---|
| `GET /podaci` | sve za ploču (GSC, pozicije, SERP-ovi, popis sadržaja, audit, stanje) |
| `GET /docs/{zbirka}` | dokumenti zbirke |
| `GET\|PUT\|DELETE /doc/{zbirka}/{id}` | jedan dokument; pisanje je dopušteno samo u zbirke ploče: issues, fixes, plan, kp, snap, cfg, trends, export, tjedno, prijedlozi_stanje, suradnje_stanje |
| `POST /ai` | Claude (Anthropic API) |
| `GET /prijedlozi` | samo prijedlozi (ploča ga čita dok se tekst dopisuje u pozadini) |
| `GET /suradnje` | samo suradnje (ploča ga čita nakon promjene stanja; usput provjeri upisane adrese s poveznicom) |
| `GET /oauth/tiktok` | povratak s TikToka nakon odobrenja; javna ruta, radi samo uz jednokratni `state` izdan administratoru |
| `POST /osvjezi` | `gsc`, `rank` (pokupi rezultate), `crawl`, `prijedlozi`, `ucinak`, `indeks` (jedna serija provjera), `mreze`, `mreze_uzori`, `mreze_preporuke`, `suradnje`, `suradnje_dr`, `suradnje_gsc`, `suradnje_dodaj`, `suradnje_veza`; bez troška (osim Claudeovih poziva). `suradnje_trazi` pokreće web pretragu i traži `potvrda=da`. `mjerenje` pokreće plaćeno mjerenje pozicija i traži `potvrda=da` |

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
| `ol-radar/radar-pozicije` (novo) | pozicije, povijest, AI pregled po riječi; uz `konkurenti: true` top 10 po riječi i kretanje konkurenata (2.2.0) |
| `ol-radar/radar-indeks` (2.2.0) | indeksiranost: brojke i stranice s problemom (razlog iz Googlea, kanonska adresa, zadnji posjet); `sve: true` vraća sve stranice |
| `ol-radar/radar-mreze` (2.3.0) | društvene mreže: račun i pratitelji, analiza (medijan, objave iznad i ispod uobičajenog, obrasci), preporuke; `mreza` za jednu mrežu, `objave: true` za popis objava, `uzori: true` za uzore |
| `ol-radar/radar-suradnje` (2.4.0) | suradnje i poveznice: kandidati s prijedlogom, kontaktom i stanjem, poveznice koje vidi Google, DR nas i konkurenata; `stanje` za jedno stanje, `svi: true` i za kandidate bez prijedloga |
| `ol-radar/radar-stanje` (2.1.1) | kratko stanje bez podataka: verzija, koji su ključevi postavljeni (da/ne), stanje izvora i ishod zadnjih provjera, mjerenje u tijeku, upozorenje nakon nadogradnje |
| `ol-radar/radar-prijedlozi` (2.1.0) | gotovi prijedlozi izmjena iz dnevne analize, s dokazima, tekstom i stanjem; uz provedene i izmjereni učinak (2.2.0) |

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
2. **Dodaci → Dodaj novi → Učitaj dodatak**, odaberi `ol-radar-most-2.2.1.zip` → **Zamijeni postojeći**. Ako je dodatak deaktiviran, aktiviraj ga. Spremljeni ključevi i postavke ostaju.
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
- **Radar na hostingu (`tests/wordpress/radar_test.php`, 283 provjere)** s lažnim Google, DataForSEO, Anthropic, Meta, YouTube, TikTok i Ahrefs odgovorima te lažnim webovima kandidata:
  - suradnje: arhiva, provjera weba i kontakt, prijedlozi (čišćenje, odbacivanje), web pretraga (isključena, prekinuta, bez poziva alata), stanja i provjera poveznice, uvoz iz Search Consolea, DR, satni prolaz, REST, MCP;
  - Claude: prisilni poziv alata se ne šalje (lažni API ga odbija kao pravi), ponovljeni pokušaj, trošak po modelu i po mjesecu;
  - društvene mreže: Meta batch i rezervni načini, istekao token, YouTube kvota i rezervna veza, TikTok OAuth (state, obnova tokena, prekid veze), analiza, uzori, preporuke, cron, REST, MCP, bez curenja tokena;
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
  - Postavke ne otkrivaju ključeve; spremanje, Enter i „obriši” ne diraju druge ključeve;
  - modul Društvene mreže (pločice, preporuke, što prolazi, uzori, „Osvježi mreže”, „Nove preporuke”) i odjeljak u Postavkama (preuzimanje, prekid i povezivanje TikToka);
  - modul Suradnje (kartice, stanja, provjera poveznice, „Napiši prijedloge”, potvrda prije web pretrage, uvoz iz Search Consolea, DR) i u Postavkama odjeljak Suradnje te tablica „Claude trošak”.

## Plan
Cijeli plan s prioritetima, troškovima i izvorima: `docs/Plan-razvoja.md`.
- Google Business Profile: analiza i prijedlozi (sljedeća verzija).
- Search Console do kraja: uređaji, države, vrsta prikaza, Discover, stanje sitemapa.
- Faza 2 upisa: Rank Math naslovi, opisi i fokus riječi, uz dnevnik promjena, vraćanje i odobrenje za svaki upis.
