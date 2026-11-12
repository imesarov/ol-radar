# Završna arhiva Ahrefsa, 13. 11. 2026.

Pretplata na Ahrefs (Lite) vrijedi do 30. 11. 2026. Ovo je zadnje povlačenje podataka koje OL Radaru trebaju i nakon toga, uz veliku arhivu od 11. 11. (univerzum ključnih riječi, SERP-ovi, petogodišnja povijest potražnje), koja je u Radaru na claude.ai.

Potrošeno je oko 14.000 jedinica; do 30. 11. ostaje oko 40.000 (od 200.000).

## Što je unutra

| Datoteka | Sadržaj |
|---|---|
| `refd-hr-<domena>.csv` | Domene `.hr` koje povezuju tu tvrtku: bez spama, samo dofollow. Stupci: domena, DR, broj poveznica. Pet konkurenata (optika-anda.com, jojo-optika.hr, adrialece.hr, livision-optika.com, optika-dado.hr) i pet srodnih tvrtki (ghetaldus.hr s DR-om 15 i više, svjetlost.hr, optometrija.net, eyerim.hr, oculus-zagreb.hr). |
| `refd-ostalo-konkurenti.csv` | Isto za domene izvan `.hr` s DR-om 25 i više, za pet konkurenata. |
| `primjeri-poveznica.psv` | 83 stranice s poveznicom (tko je povezan, adresa, naslov): kako su drugi došli do poveznice. |
| `stranice-po-poveznicama-optika-anda.com.csv` | Stranice najjačeg konkurenta na koje vodi najviše poveznica. |
| `refd-nase-bez-spama.csv`, `nase-poveznice-bez-spama.csv` | Domene i stranice koje povezuju nas, bez spama. |
| `nasa-sidra-sazetak.csv` | Tekstovi poveznica prema nama po skupinama; pokazuje val spama prodavača poveznica. |
| `pokidane-poveznice.txt` | Pokidanih poveznica prema nama nema. |
| `dr-povijest-mjesecno.csv` | DR po mjesecima, siječanj 2025. do studeni 2026., za nas i četiri konkurenta. |
| `refdomene-povijest-nase.csv` | Broj domena koje nas povezuju, po mjesecima. |
| `organski-konkurenti-hr.csv` | Domene s kojima dijelimo ključne riječi u Hrvatskoj. |
| `kljucne-rijeci-dopuna.psv` | 239 ključnih riječi kojih nije bilo u arhivi od 11. 11., u pet skupina (pregledi i dioptrija, stanja oka, naočale i stakla, leće i kapi, lokalno Zagreb): potražnja, težina, potencijal prometa. |
| `kljucne-rijeci-portala.psv` | Ključne riječi s kojima su optometrija.net i svjetlost.hr u prvih deset. |
| `uvoz-za-hosting.json` | Dopuna arhive za Radar na hostingu (Postavke → „Uvoz iz Radara na claude.ai”): nove ključne riječi i sažetak poveznica. |

## Glavni nalazi

- **Naš DR je 0** (0,1 do rujna 2025., zatim 0). Konkurenti: optika-anda.com 28, jojo-optika.hr 27, adrialece.hr 25, livision-optika.com 16, optika-dado.hr 7. Trima od četiri praćena konkurenta DR je pao u listopadu 2025. (optika-anda.com s 27 na 21, adrialece.hr s 25 na 16, livision-optika.com s 27 na 24); prva dva su se otad vratila, treći nije. Uzrok pada iz ovih podataka nije vidljiv.
- **Val spama.** Broj domena koje nas povezuju narastao je s 47 (siječanj 2025.) na 577 (studeni 2026.). Gotovo sve su stranice prodavača poveznica s oglasnim tekstom poveznice. Nismo ih naručili i ne dižu DR.
- **Pravih poveznica gotovo nema.** Bez spama nas povezuje šest domena; dofollow su samo optike.hr i hr.voovuu.com (DR oko 2 do 3). Ostale (waze.com, siteprice.org, forum.lovac.info, obrtnici-sesvete.hr) su nofollow.
- **Kako konkurenti dolaze do poveznica:**
  - plaćeni promo i native članci u nacionalnim i modnim medijima (najveći dio njihovih poveznica);
  - objave uz humanitarne akcije, Svjetski dan vida, otvorenja poslovnica i nagradne igre;
  - stručni savjeti na portalima (djeca i naočale, ekrani i oči, zaštita od sunca);
  - stranice pogodnosti za članove: sindikati, HAK, kartične kuće, osiguranja;
  - imenici i adresari (brzipregled.hr povezuje svih pet konkurenata);
  - stranice „Poslovna suradnja” i reference dobavljača; program HOK ZNA za obrtnike.
- **Ključne riječi.** Ahrefs za našu domenu vidi devet ključnih riječi. Najbliži organski konkurent je optometrija.net (DR 30), prvi za mnoge teme o zdravlju oka.

## Kako se koristi

- `tools/build-suradnje-seed.py` iz ovih datoteka gradi popis kandidata priložen dodatku (`wordpress/ol-radar-most/data/suradnje-pocetno.json`, 74 domene) i `uvoz-za-hosting.json`.
- Brojke (DR, broj poveznica) su iz Ahrefsa. Naziv, vrsta, ocjena izvedivosti i opis načina su urednička procjena.
- Od 30. 11. glavni izvor je Search Console; poveznice koje vidi Google uvoze se ručnim izvozom u modulu Suradnje.
