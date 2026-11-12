# OL Radar – plan razvoja

Stanje 12. 11. 2026. Popis je složen po tome koliko koristi lokalnoj optici, a ne po tome što je tehnički zanimljivo. Cijene i ograničenja provjereni su na dan pisanja; izvori su na dnu. Ništa s ovog popisa nije uključeno dok Ivan ne kaže.

## Već radi (dodatak 2.3.0)
- Google Search Console na serveru (servisni račun), pozicije (DataForSEO), crawl i audit weba, model konkurencije, Claude u ploči.
- **Dnevni prijedlozi izmjena:** server sam analizira i priprema gotove prijedloge (modul Prijedlozi, MCP `ol-radar/radar-prijedlozi`).
- **Mjerenje učinka provedenih izmjena** (2.2.0): za svaki prijedlog označen „provedeno” Radar sam usporedi Search Console prije i poslije (7, 14 i 28 dana), uz trend cijelog weba.
- **Indeksiranost stranica** (2.2.0): tjedna provjera preko URL Inspection API-ja; panel u Auditu, MCP `ol-radar/radar-indeks`.
- **AI Overview u našim mjerenjima** (2.2.0): za koje se ciljane riječi pojavljuje i citira li nas. Pregledi koji se učitavaju naknadno stižu samo uz doplatu od 0,0006 $ po riječi, pa je ta opcija zadano isključena.
- **Kretanje konkurenata** (2.2.0): tko raste, tko pada, tko je ušao u top 10 i tko nas je pretekao, iz prvih 20 rezultata svakog mjerenja. Ima smisla tek uz redovito mjerenje (tjedno, oko 0,07 $ po mjerenju).
- **Društvene mreže na hostingu** (2.3.0): Instagram i Facebook (Meta Graph API), YouTube (Data API v3), TikTok (Display API). Vlastite objave s brojkama, što prolazi prema medijanu vlastite mreže, uzori (YouTube po temama, navedeni Instagram profili) i tjedne Claudeove preporuke. Radi kad Ivan upiše pristupne podatke u Postavke.

## Preporučeni redoslijed (što je još na popisu)

| # | Što | Zašto baš nama | Trošak | Što treba od Ivana |
|---|---|---|---|---|
| 1 | **Rezervacije kao cilj** (GA4 Data API) | Prijedlozi se danas rangiraju po klikovima. S GA4 ih možemo rangirati po rezervacijama termina (`/hvala-na-rezervaciji/`) po ulaznoj stranici. | 0; kvota 200.000 tokena dnevno po svojstvu | dodati servisni račun kao preglednika u GA4 i javiti ID svojstva |
| 2 | **Brzina i Core Web Vitals** (PageSpeed Insights API) | Tjedna provjera 10 najvažnijih stranica na mobitelu; prijedlog nastaje tek kad se nešto pogorša. | 0; 25.000 upita dnevno uz besplatan API ključ | izraditi API ključ u Google Cloudu (ograničen na PageSpeed Insights) |
| 3 | **Lokalni rezultati (Google Maps)** | Za optiku je lokalni paket važniji od plavih linkova. Pratimo mjesto u Mapsu za „optika sesvete” i slično te ocjene i broj recenzija konkurenata. | 0,0006 $ po upitu u standardnom redu; 8 riječi tjedno je oko 0,02 $ mjesečno | odobriti trošak |
| 4 | **Upis odobrenih izmjena jednim klikom** (faza 2 mosta) | „Prihvati” u Prijedlozima bi nakon potvrde sam upisao novi SEO naslov i opis u Rank Math, s dnevnikom i vraćanjem. | 0 | izričita odluka, jer dodatak tada prvi put mijenja web |
| 5 | **Google Business Profile metrike** | Pozivi, upute i klikovi na web s profila tvrtke, po danu, te upiti kojima nas ljudi nalaze. | 0 | zahtjev Googleu za pristup API-ju (ručni pregled, do 14 dana) i OAuth prijava vlasnika profila |
| 6 | **Tjedni e-mail sažetak** | Novi prijedlozi i veće promjene stižu u sandučić bez otvaranja ploče. | 0 | odobriti slanje i adresu |

## Ostalo, kad dođe na red
- **YouTube zadržavanje publike i izvori prometa** (YouTube Analytics API). Traži OAuth prijavu vlasnika kanala (kao TikTok), ne radi s API ključem. Dalo bi prosječno gledanje i krivulju zadržavanja po videu.
- **Praćenje pratitelja kroz vrijeme** (dnevna snimka po mreži) i **najbolje vrijeme objave** kad se skupi dovoljno objava.
- **TikTok u produkcijskom načinu:** Sandbox je dovoljan za vlastiti račun; produkcija traži TikTokov pregled aplikacije i potvrdu vlasništva nad adresama.
- **Poveznice (backlinks):** DataForSEO je 1. 7. 2026. ukinuo mjesečni minimum od 100 $ za Backlinks API, pa je moguć mjesečni snimak domena koje linkaju na nas i na 3–5 konkurenata. Cijenu po upitu treba provjeriti prije uključivanja. Povijest do 30. 11. čuva arhiva Ahrefsa.
- **Spominjanja u AI chatovima (LLM Mentions):** dostupno bez mjesečnog minimuma, ali baza za ChatGPT pri pokretanju pokriva samo SAD. Za hrvatsko tržište zasad mala korist; pratimo.
- **Google AI Mode:** poseban DataForSEO endpoint (0,0024–0,004 $ po upitu). Ima smisla tek kad AI Mode zaživi u Hrvatskoj.
- **Novi sadržaj → planer:** gumb „Dodaj u planer” na prijedlozima vrste „Novi sadržaj”.
- **Provjera strukturiranih podataka** (LocalBusiness/Optician, FAQ) uz podatke iz URL Inspection API-ja o bogatim rezultatima.
- **Sezonalnost:** mjesečni volumeni iz Keyword Plannera na hostingu (cijenu treba provjeriti).

## Što namjerno ne radimo
- Vlastiti indeks poveznica i procjene prometa „odoka” (Ahrefsov model): za lokalnu optiku promašuju, a Search Console daje stvarne brojke.
- Automatske izmjene weba bez odobrenja.

## Izvori (provjereno 12. 11. 2026.)
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
