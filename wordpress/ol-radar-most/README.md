# OL Radar – most za Claudea (v1.0.0)

WordPress dodatak koji Claudeu daje pristup webu optikaljiljana.hr **samo za čitanje**. To je izvor podataka za OL Radar: Radar vidi web iznutra, točno kako ga vide posjetitelji i Google.

## Što radi
- **Pregled weba:** verzije, tema, aktivni dodaci, vidljivost tražilicama, broj objava, stanje crawla.
- **Popis sadržaja:** stranice, članci i proizvodi s Rank Math podacima (SEO naslov, opis, fokus riječ, robots, canonical) i stvarnim naslovom i H1 iz zadnjeg crawla.
- **Detalji stranice:**
  - status, `<title>`, meta opis, canonical, robots;
  - H1 i struktura naslova;
  - broj riječi i tekst sadržaja;
  - slike s alt tekstom;
  - interni i vanjski linkovi;
  - schema tipovi;
  - ulazni linkovi s drugih stranica.
- **On-site audit:**
  - greške i preusmjerenja;
  - pokvareni interni linkovi;
  - noindex i canonical;
  - duljina i duplikati naslova i opisa;
  - H1 i tanki sadržaj;
  - slike bez alt teksta i dimenzija;
  - stranice bez ulaznih linkova iz sadržaja (pravilo protiv siročadi);
  - Rank Math fokus riječ u naslovu, opisu i H1.
- **Interni linkovi:** graf linkova. Razlikuje linkove iz sadržaja od izbornika i podnožja te dofollow od nofollow.
- **Osvježi crawl:** obrađuje sljedeću seriju stranica. Mijenja samo vlastiti cache dodatka.

Crawl radi iznutra:
- Dodatak dohvaća vlastite stranice (loopback) s potpisanim parametrom, pa WP Rocket i LiteSpeed taj prikaz ne spremaju u cache, a audit uvijek vidi svježi HTML.
- Svaki sat obradi do 10 novih ili promijenjenih stranica.
- Ostale stranice ponovno obrađuje nakon 7 dana.

## Što NE radi
- Ne mijenja stranice, objave, postavke, dodatke, teme ni korisnike.
- Ne šalje podatke nikamo. Claude ih čita tek kad se spoji preko MCP-a.
- Ne dohvaća tuđe URL-ove, samo vlastitu domenu.

## Zahtjevi
- WordPress 6.9 ili noviji (Abilities API).
- PHP 7.4 ili noviji.
- MCP Adapter. Na optikaljiljana.hr već postoji:
  - `/wp-json/mcp/mcp-adapter-default-server`;
  - OAuth server `/wp-json/mcp/mcp-oauth-server`, koji dolazi s WP Rocketom (WP Media).

## Instalacija
1. Napravi backup weba u cPanelu.
2. **Dodaci → Dodaj novi → Učitaj dodatak**, odaberi `ol-radar-most-1.0.0.zip` pa **Aktiviraj**.
3. **Korisnici → Dodaj novog**:
   - korisničko ime `claude-radar`;
   - uloga **OL Radar – samo čitanje**;
   - jaka lozinka, spremi je.
4. **Alati → OL Radar** → nekoliko puta „Obradi sljedećih 15 stranica”. Može se i pričekati, cron sam obrađuje 10 na sat.

## Spajanje s Claudeom (claude.ai)
1. **Postavke → Konektori → Dodaj prilagođeni konektor**:
   - naziv: `Optika Ljiljana web`;
   - URL: `https://www.optikaljiljana.hr/wp-json/mcp/mcp-oauth-server`.
2. Klikni **Poveži**. Otvorit će se prijava na webu.
3. **Važno:**
   - Prijavi se kao `claude-radar`, ne kao administrator.
   - Najsigurnije je to napraviti u privatnom prozoru preglednika.
   - Ako si u tom pregledniku prijavljen kao admin, Claude bi dobio administratorska prava.
4. Na ekranu za odobrenje Claude ima oznaku provjerenog izdavača. Odobri.

S ulogom „OL Radar – samo čitanje” Claude može pokrenuti samo ove sposobnosti. Sve ostalo na webu traži prava koja ta uloga nema.

**Zamjenski put**, ako OAuth ne prođe:
1. Za korisnika `claude-radar` napravi aplikacijsku lozinku (Korisnici → profil → Aplikacijske lozinke).
2. Konektor spoji na `https://www.optikaljiljana.hr/wp-json/mcp/mcp-adapter-default-server`.
3. Pod **Request headers** dodaj zaglavlje `authorization` s vrijednošću `Basic <base64 od claude-radar:lozinka>`. Ta opcija je u beta fazi i možda još nije vidljiva.

## Sigurnost
- Svaka sposobnost traži pravo `ol_radar_read`. Ima ga samo uloga „OL Radar – samo čitanje” i administrator.
- Bez prijave server vraća 401.
- Prijavljeni korisnik bez prava dobije „Permission denied”.
- URL-ovi izvan vlastite domene se odbijaju.
- Ulazni parametri se provjeravaju prema shemi.

## Uklanjanje
- **Deaktivacija** gasi cron.
- **Brisanje dodatka** uklanja njegovu tablicu, postavke, ulogu i pravo. Sadržaj weba ostaje netaknut.

## Testirano (lokalno, WordPress 6.9 + MCP Adapter 0.7.1, PHP 8.3)
- MCP spajanje.
- Prava: anonimno 401, pretplatnik odbijen, čitač dopušten.
- Crawl i audit na 311 stranica:
  - crawl oko 20 s;
  - audit 0,3 s;
  - oko 12 MB memorije.
- Zaštita od vanjskih URL-ova i provjera parametara.
- Preskakanje cachea s potpisanim tokenom.
- Nadogradnja sheme baze.
- Potpuno čišćenje pri brisanju.

## Plan
- **Faza 2:** pisanje Rank Math naslova, opisa i fokus riječi, uz dnevnik promjena i vraćanje.
- **Faza 3:** samo skice novih tekstova.

Svaki upis bit će zasebna sposobnost i tražit će odobrenje.
