# OL Radar – plan razvoja

Stanje 12. 11. 2026. Popis je složen po tome koliko koristi lokalnoj optici, a ne po tome što je tehnički zanimljivo. Cijene i ograničenja provjereni su na dan pisanja; izvori su na dnu. Ništa s ovog popisa nije uključeno dok Ivan ne kaže.

## Već radi (dodatak 2.1.0)
- Google Search Console na serveru (servisni račun), pozicije (DataForSEO), crawl i audit weba, model konkurencije, Claude u ploči.
- **Dnevni prijedlozi izmjena:** server sam analizira i priprema gotove prijedloge (modul Prijedlozi, MCP `ol-radar/radar-prijedlozi`).

## Preporučeni redoslijed

| # | Što | Zašto baš nama | Trošak | Što treba od Ivana |
|---|---|---|---|---|
| 1 | **Mjerenje učinka provedenih izmjena** | Zatvara krug: prijedlog → izmjena → je li pomoglo. Za svaku izmjenu označenu „provedeno” Radar sam usporedi Search Console prije i poslije (14 i 28 dana) i kaže vrijedi li ponoviti na drugim stranicama. | 0 | ništa |
| 2 | **Indeksiranost stranica** (URL Inspection API) | Vidimo je li svaka stranica u Googleovu indeksu, koji je kanonski URL Google odabrao i kad ju je zadnji put posjetio. Hvata tihe kvarove (noindex, kriva kanonska adresa) prije nego što padne promet. | 0; ograničenje 2.000 provjera dnevno po svojstvu, a mi imamo oko 40 stranica | ništa (isti servisni račun) |
| 3 | **AI Overview u našim mjerenjima** | Mjerenje pozicija već dobiva i Googleov AI pregled kad ga ima. Zabilježimo za koje ciljane riječi se pojavljuje i citira li nas. | 0 uz postojeće mjerenje; „async” učitavanje AI pregleda naplaćuje se dodatno, pa ga ne uključujemo | ništa |
| 4 | **Kretanje konkurenata** | Top 20 rezultata po riječi već spremamo pri svakom mjerenju. Dodajemo: tko je ušao, tko raste, tko pada i s kojom stranicom. | 0 | uključiti tjedno mjerenje (oko 0,06 $ po mjerenju) |
| 5 | **Rezervacije kao cilj** (GA4 Data API) | Prijedlozi se danas rangiraju po klikovima. S GA4 ih možemo rangirati po rezervacijama termina (`/hvala-na-rezervaciji/`) po ulaznoj stranici. | 0; kvota 200.000 tokena dnevno po svojstvu | dodati servisni račun kao preglednika u GA4 i javiti ID svojstva |
| 6 | **Brzina i Core Web Vitals** (PageSpeed Insights API) | Tjedna provjera 10 najvažnijih stranica na mobitelu; prijedlog nastaje tek kad se nešto pogorša. | 0; 25.000 upita dnevno uz besplatan API ključ | izraditi API ključ u Google Cloudu (ograničen na PageSpeed Insights) |
| 7 | **Lokalni rezultati (Google Maps)** | Za optiku je lokalni paket važniji od plavih linkova. Pratimo mjesto u Mapsu za „optika sesvete” i slično te ocjene i broj recenzija konkurenata. | 0,0006 $ po upitu u standardnom redu; 8 riječi tjedno je oko 0,02 $ mjesečno | odobriti trošak |
| 8 | **Upis odobrenih izmjena jednim klikom** (faza 2 mosta) | „Prihvati” u Prijedlozima bi nakon potvrde sam upisao novi SEO naslov i opis u Rank Math, s dnevnikom i vraćanjem. | 0 | izričita odluka, jer dodatak tada prvi put mijenja web |
| 9 | **Google Business Profile metrike** | Pozivi, upute i klikovi na web s profila tvrtke, po danu, te upiti kojima nas ljudi nalaze. | 0 | zahtjev Googleu za pristup API-ju (ručni pregled, do 14 dana) i OAuth prijava vlasnika profila |
| 10 | **Tjedni e-mail sažetak** | Novi prijedlozi i veće promjene stižu u sandučić bez otvaranja ploče. | 0 | odobriti slanje i adresu |

## Ostalo, kad dođe na red
- **Instagram i YouTube na hostingu** (Meta Graph API, YouTube Data API). Do tada su u Radaru na claude.ai (vidIQ).
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
- Search Console API, ograničenja (URL Inspection 2.000 dnevno i 600 u minuti po svojstvu): https://developers.google.com/webmaster-tools/limits
- Search Console API, pregled metoda: https://developers.google.com/webmaster-tools/v1/api_reference_index
- PageSpeed Insights API, kvote: https://unlighthouse.dev/learn-lighthouse/pagespeed-insights-api/rate-limits
- GA4 Data API sa servisnim računom: https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart
- GA4 Data API, kvote: https://www.owox.com/blog/articles/google-analytics-api-comparison.md
- Google Business Profile API, pristup: https://developers.google.com/my-business/content/faq
- Business Profile Performance API: https://developers.google.com/my-business/reference/performance/rest
- DataForSEO Google Maps SERP, cijena: https://dataforseo.com/google-maps-api
- DataForSEO Google Organic SERP (AI Overview uključen): https://dataforseo.com/google-serp-api-data
- DataForSEO AI Mode: https://dataforseo.com/google-ai-mode-data-api
- DataForSEO, ukidanje mjesečnog minimuma za Backlinks i LLM Mentions: https://diyai.io/ai-tools/seo/reviews/dataforseo-review/
- DataForSEO LLM Mentions, pokrivenost: https://dataforseo.com/update/introducing-llm-mentions-api
