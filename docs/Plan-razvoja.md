# OL Radar – plan razvoja

Stanje 13. 11. 2026. Popis je složen po tome koliko koristi lokalnoj optici, a ne po tome što je tehnički zanimljivo. Cijene i ograničenja provjereni su na dan pisanja; izvori su na dnu. Ništa s ovog popisa nije uključeno dok Ivan ne kaže.

## Već radi (dodatak 2.4.1, na webu od 13. 11. 2026.)
- Google Search Console na serveru (servisni račun), pozicije (DataForSEO), crawl i audit weba, model konkurencije, Claude u ploči.
- **Dnevni prijedlozi izmjena:** server sam analizira i priprema gotove prijedloge (modul Prijedlozi, MCP `ol-radar/radar-prijedlozi`).
- **Mjerenje učinka provedenih izmjena** (2.2.0): za svaki prijedlog označen „provedeno” Radar sam usporedi Search Console prije i poslije (7, 14 i 28 dana), uz trend cijelog weba.
- **Indeksiranost stranica** (2.2.0): tjedna provjera preko URL Inspection API-ja; panel u Auditu, MCP `ol-radar/radar-indeks`.
- **AI Overview u našim mjerenjima** (2.2.0): za koje se ciljane riječi pojavljuje i citira li nas. Pregledi koji se učitavaju naknadno stižu samo uz doplatu od 0,0006 $ po riječi, pa je ta opcija zadano isključena.
- **Kretanje konkurenata** (2.2.0): tko raste, tko pada, tko je ušao u top 10 i tko nas je pretekao, iz prvih 20 rezultata svakog mjerenja. Ima smisla tek uz redovito mjerenje (tjedno, oko 0,07 $ po mjerenju).
- **Društvene mreže na hostingu** (2.3.0): Instagram i Facebook (Meta Graph API), YouTube (Data API v3), TikTok (Display API). Vlastite objave s brojkama, što prolazi prema medijanu vlastite mreže, uzori (YouTube po temama, navedeni Instagram profili) i tjedne Claudeove preporuke. Radi kad Ivan upiše pristupne podatke u Postavke.
- **Suradnje i poveznice** (2.4.0; u 2.4.1 završna arhiva i popravak): kandidati za suradnju (74 domene iz završne arhive Ahrefsa s ocjenom izvedivosti i primjerom kako je poveznica nastala, tvrtke koje Claude nađe web pretragom, ručno dodani), tjedni Claudeovi prijedlozi s nacrtom poruke i javnim kontaktom, stanja, provjera dobivene poveznice, uvoz veza iz Search Consolea, DR nas i konkurenata s mjesečnom poviješću od siječnja 2025. Web pretraga i tjedni DR su zadano isključeni.
  - Popravak u 2.4.1: prvi stvarni prolaz u 2.4.0 bio je prekinut zbog duljine, jer Opus 5.5 uvijek razmišlja i to troši isti okvir tokena kao odgovor. Sada je svaki prijedlog zaseban poziv. Potvrđeno na webu 13. 11.: 3 prijedloga u 49 sekundi, oko 0,04 $ po prijedlogu.
- **Claude trošak** (2.4.0): u Postavkama, po danu i mjesecu, iz stvarnih tokena svakog poziva.

## Odluka: GSC je glavni izvor, Ahrefs samo arhiva (Ivan, 12. 11. 2026.)
- Sve o našem webu u Googleu dolazi iz Search Consolea. Hosting već radi bez Ahrefsa.
- Što GSC ne daje pokrivaju: DataForSEO mjerenja (pozicije, konkurenti), Keyword Planner (obujam pretraga), ručni izvoz veza iz Search Consolea (poveznice prema nama) i arhiva Ahrefsa (poveznice konkurenata, DR).
- **Što iz GSC-a još nije iskorišteno** (besplatno, isti servisni račun; ide na popis ispod):
  - raspodjela po uređaju i državi;
  - vrsta prikaza u rezultatima (`searchAppearance`);
  - Discover i Google vijesti (`type`);
  - stanje sitemapa (Sitemaps API).

## Preporučeni redoslijed (što je još na popisu)

| # | Što | Zašto baš nama | Trošak | Što treba od Ivana |
|---|---|---|---|---|
| 1 | **Rezervacije kao cilj** (GA4 Data API) | Prijedlozi se danas rangiraju po klikovima. S GA4 ih možemo rangirati po rezervacijama termina (`/hvala-na-rezervaciji/`) po ulaznoj stranici. | 0; kvota 200.000 tokena dnevno po svojstvu | dodati servisni račun kao preglednika u GA4 i javiti ID svojstva |
| 2 | **Brzina i Core Web Vitals** (PageSpeed Insights API) | Tjedna provjera 10 najvažnijih stranica na mobitelu; prijedlog nastaje tek kad se nešto pogorša. | 0; 25.000 upita dnevno uz besplatan API ključ | izraditi API ključ u Google Cloudu (ograničen na PageSpeed Insights) |
| 3 | **Lokalni rezultati (Google Maps)** | Za optiku je lokalni paket važniji od plavih linkova. Pratimo mjesto u Mapsu za „optika sesvete” i slično te ocjene i broj recenzija konkurenata. | 0,0006 $ po upitu u standardnom redu; 8 riječi tjedno je oko 0,02 $ mjesečno | odobriti trošak |
| 4 | **Upis odobrenih izmjena jednim klikom** (faza 2 mosta) | „Prihvati” u Prijedlozima bi nakon potvrde sam upisao novi SEO naslov i opis u Rank Math, s dnevnikom i vraćanjem. | 0 | izričita odluka, jer dodatak tada prvi put mijenja web |
| 5 | **Google Business Profile: analiza i prijedlozi** (Ivan ga želi; ide u sljedeću verziju) | Pozivi, upute i klikovi na web s profila tvrtke, po danu, te upiti kojima nas ljudi nalaze; uz to ocjena, recenzije i objave prema konkurentima, i Claudeovi prijedlozi (objave, odgovori na recenzije, popunjenost profila). | službeni dio 0; javni dio preko DataForSEO-a u centima, cijenu treba potvrditi | zahtjev Googleu za pristup API-ju (ručni pregled, do 14 dana) i OAuth prijava vlasnika profila; OK za trošak javnog dijela |
| 5a | **GSC do kraja** | Uređaji, države, vrsta prikaza, Discover, stanje sitemapa: sve iz Search Consolea, bez novih ključeva. | 0 | ništa |
| 6 | **Tjedni e-mail sažetak** | Novi prijedlozi i veće promjene stižu u sandučić bez otvaranja ploče. | 0 | odobriti slanje i adresu |

## Ostalo, kad dođe na red
- **YouTube zadržavanje publike i izvori prometa** (YouTube Analytics API). Traži OAuth prijavu vlasnika kanala (kao TikTok), ne radi s API ključem. Dalo bi prosječno gledanje i krivulju zadržavanja po videu.
- **Praćenje pratitelja kroz vrijeme** (dnevna snimka po mreži) i **najbolje vrijeme objave** kad se skupi dovoljno objava.
- **TikTok u produkcijskom načinu:** Sandbox je dovoljan za vlastiti račun; produkcija traži TikTokov pregled aplikacije i potvrdu vlasništva nad adresama.
- **Poveznice konkurenata uživo:** modul Suradnje polazi od završne arhive Ahrefsa (13. 11. 2026.). DataForSEO je 1. 7. 2026. ukinuo mjesečni minimum od 100 $ za Backlinks API, pa je moguć mjesečni snimak domena koje povezuju 3–5 konkurenata, iz kojeg bi novi kandidati stizali sami. Cijenu po upitu treba provjeriti prije uključivanja.
- **Stranica Kontakt kandidata (greška, ide u sljedeću verziju):** traženje kontakt stranice hvata i poveznice na „kontaktne leće”. Treba prihvaćati samo kontakt, contact, impressum i o-nama.
- **Manje razmišljanja, manji trošak (Claude API):** parametar `output_config.effort` (`low`) smanjio bi broj tokena po prijedlogu. Prije uključivanja treba provjeriti da ga podržavaju sva tri modela koja se mogu odabrati u Postavkama i usporediti kvalitetu prijedloga.
- **Prikaz završne arhive u ploči:** naše poveznice bez spama, kretanje broja domena koje nas povezuju i ključne riječi strukovnih portala zasad su samo u datotekama arhive i u bazi (`arch_misc/zavrsna-2026-11-13`); panel u modulu Arhiva bi ih pokazao.
- **Spominjanja u AI chatovima (LLM Mentions):** dostupno bez mjesečnog minimuma, ali baza za ChatGPT pri pokretanju pokriva samo SAD. Za hrvatsko tržište zasad mala korist; pratimo.
- **Google AI Mode:** poseban DataForSEO endpoint (0,0024–0,004 $ po upitu). Ima smisla tek kad AI Mode zaživi u Hrvatskoj.
- **Novi sadržaj → planer:** gumb „Dodaj u planer” na prijedlozima vrste „Novi sadržaj”.
- **Provjera strukturiranih podataka** (LocalBusiness/Optician, FAQ) uz podatke iz URL Inspection API-ja o bogatim rezultatima.
- **Sezonalnost:** mjesečni volumeni iz Keyword Plannera na hostingu (cijenu treba provjeriti).

## Što namjerno ne radimo
- Vlastiti indeks poveznica i procjene prometa „odoka” (Ahrefsov model): za lokalnu optiku promašuju, a Search Console daje stvarne brojke.
- Automatske izmjene weba bez odobrenja.

## Izvori (provjereno 12. i 13. 11. 2026.)
- Anthropic, razmišljanje je na Opusu 5.5 uvijek uključeno, naplaćuje se kao izlaz i ulazi u `max_tokens`; dubinu određuje `effort` (zadano `medium`): https://platform.claude.com/docs/en/build-with-claude/effort i https://platform.claude.com/docs/en/models/opus-5-5/whats-new-opus-5-5
- Ahrefs, besplatni javni DR (GET bez ključa; obvezna oznaka „Domain Rating by Ahrefs”): https://docs.ahrefs.com/en/api/reference/public/get-domain-rating-free
- Anthropic, cjenik modela i web pretrage: https://platform.claude.com/docs/en/about-claude/pricing
- Anthropic, Opus 5.5 i Sonnet 5.5 ne podržavaju prisilni poziv alata: https://platform.claude.com/docs/en/models/opus-5-5/migration-guide i https://platform.claude.com/docs/en/models/sonnet-5-5/whats-new-sonnet-5-5
- Meta, token sistemskog korisnika i podržana dopuštenja: https://developers.facebook.com/docs/business-management-apis/system-users/install-apps-and-generate-tokens
- Meta, zamjene za ukinute metrike objava (`post_media_view`, `post_total_media_view_unique`): https://developers.facebook.com/docs/graph-api/changelog/version25.0
- TikTok, registracija aplikacije i Sandbox (potvrda adresa u Sandboxu samo za Content Posting API): https://developers.tiktok.com/docs/en/getting-started-create-an-app
- TikTok Login Kit za web (adresa za odobrenje, Redirect URI): https://developers.tiktok.com/doc/login-kit-web
- Search Console API, ograničenja (URL Inspection 2.000 dnevno i 600 u minuti po svojstvu): https://developers.google.com/webmaster-tools/limits
- Search Console API, pregled metoda: https://developers.google.com/webmaster-tools/v1/api_reference_index
- PageSpeed Insights API, kvote: https://unlighthouse.dev/learn-lighthouse/pagespeed-insights-api/rate-limits
- GA4 Data API sa servisnim računom: https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart
- GA4 Data API, kvote: https://www.owox.com/blog/articles/google-analytics-api-comparison.md
- Google Business Profile API, pristup: https://developers.google.com/my-business/content/faq
- Business Profile Performance API: https://developers.google.com/my-business/reference/performance/rest
- DataForSEO Google Maps SERP, cijena: https://dataforseo.com/google-maps-api
- DataForSEO Google Organic SERP (AI Overview uključen): https://dataforseo.com/google-serp-api-data
- DataForSEO, AI Overview u SERP API-ju i doplata za naknadno učitane preglede: https://dataforseo.com/update/ai-overview-in-google-serp-api
- Search Console URL Inspection API, polja odgovora: https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult
- DataForSEO AI Mode: https://dataforseo.com/google-ai-mode-data-api
- DataForSEO, ukidanje mjesečnog minimuma za Backlinks i LLM Mentions: https://diyai.io/ai-tools/seo/reviews/dataforseo-review/
- DataForSEO LLM Mentions, pokrivenost: https://dataforseo.com/update/introducing-llm-mentions-api
