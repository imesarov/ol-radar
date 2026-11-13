=== OL Radar – most za Claudea ===
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.4.2
License: GPL-2.0-or-later

OL Radar na hostingu (nadzorna ploča: Google Search Console, pozicije, audit, konkurencija, društvene mreže, suradnje i poveznice, AI prijedlozi) i pristup webu samo za čitanje za Claudea preko MCP-a.

== Description ==

Registrira sposobnosti (WordPress Abilities API) u kategoriji „OL Radar”, izložene MCP Adapteru. Sve sposobnosti traže pravo ol_radar_read i samo čitaju; jedina iznimka „osvjezi-crawl” mijenja isključivo interni cache dodatka. Detaljne upute su u README.md.

== Changelog ==

= 2.4.2 =
* Manje lažnih nalaza u prijedlozima izmjena: glavna stranica za upit je ona s najviše klikova (ne s najviše prikazivanja), kanibalizacija se ne javlja kad smo prvi ili su obje stranice u prva tri rezultata, upiti s imenom konkurenta se preskaču, pad klikova i nizak CTR moraju biti statistički značajni, lokalni upiti imaju niže očekivanje CTR-a, stranice mijenjane u zadnjih 14 dana čekaju nove podatke, a novi članak se ne predlaže kad postojeća stranica već pokriva temu.
* Otvoreni prijedlozi napisani prije 2.4.2 koje nova pravila ne potvrđuju istječu pri prvoj sljedećoj analizi (prije su čekali 21 dan). Prihvaćeni, provedeni i odbijeni ostaju kakvi jesu.
* Popravak: web pretraga za nove tvrtke više ne šalje oznaku države HR, koju Anthropic odbija; ako odbije i grad, pokuša jednom bez lokacije.
* Popravak: stranica „Kontakt” kandidata za suradnju više se ne miješa s poveznicom na „kontaktne leće”.

= 2.4.1 =
* Popravak: prijedlozi suradnje na modelu Claude Opus 5.5 više se ne prekidaju zbog duljine. Svaki prijedlog je sada zaseban, kratak poziv i sprema se čim stigne.
* Web pretraga za nove tvrtke vraća samo kandidate, a prijedlog za svakoga piše zaseban poziv.
* Završna arhiva Ahrefsa (13. 11. 2026.): popis kandidata za suradnju proširen s 20 na 74 domene koje povezuju konkurente i srodne tvrtke, a ne nas.
* Uz svakog kandidata sada stoji ocjena izvedivosti (najizvedivije, traži stručni sadržaj, većinom plaćene objave), kako su drugi došli do poveznice i primjer takve stranice. Prijedlozi se pišu tim redom: prvo imenici, programi pogodnosti i lokalni partneri, zadnji mediji u kojima su poveznice većinom plaćene.
* Domain Rating: mjesečna povijest od siječnja 2025. za nas i konkurente, s crtom kretanja.
* Nadogradnja ne dira postojeće prijedloge, stanja, kontakte ni vlastita mjerenja DR-a; arhiva samo dopunjava.

= 2.4.0 =
* Suradnje i poveznice: popis mogućih partnera (mediji, portali, ustanove, tvrtke, klubovi) i za svakoga Claudeov prijedlog: zašto baš on, što ponuditi, koraci i nacrt poruke. Server prije toga provjeri da web postoji i pročita javni kontakt. Ništa se ne šalje nikome.
* Nove tvrtke: Claude ih može tražiti web pretragom (na gumb uz potvrdu troška, ili jednom tjedno ako se uključi u Postavkama).
* Poveznice koje vidi Google: uvoz izvoza iz Search Consolea (Veze → Vanjske veze → Izvoz) kao glavno mjerilo napretka; Search Console API taj izvještaj ne daje.
* Domain Rating nas i konkurenata: arhiva od 12. 11. 2026. ili, ako se uključi, tjedna provjera Ahrefsovim besplatnim javnim servisom (bez pretplate i ključa). Domain Rating by Ahrefs.
* Claude trošak u Postavkama: danas i po mjesecima, iz stvarnih tokena svakog poziva; iznos za mjesec i u zaglavlju ploče.
* Popravak: Claude Opus 5.5 i Sonnet 5.5 odbijaju prisilni poziv alata. Dodatak ga više ne šalje, pa preporuke za društvene mreže rade i na tim modelima, a prijedlozi i popravci više ne troše jedan odbijeni zahtjev po pozivu.
* Nova MCP sposobnost ol-radar/radar-suradnje. Postojeći ključevi i postavke se ne diraju.

= 2.3.0 =
* Društvene mreže na hostingu: Instagram i Facebook (Meta Graph API), YouTube (Data API v3) i TikTok (Display API). Dodatak skuplja vlastite objave i njihove brojke dvaput dnevno. Samo čitanje: ništa se ne objavljuje, ne briše i ne mijenja.
* Što prolazi, a što ne: svaka objava uspoređuje se s medijanom vlastite mreže u zadnjih 180 dana; uz to obrasci po vrsti, trajanju, danu i dobu dana (tek uz barem tri objave i odstupanje od 30 %).
* Uzori: najgledaniji YouTube videi po zadanim temama i objave javnih Instagram profila koje navedeš. TikTok i Facebook ne daju službeni pristup tuđem sadržaju.
* Što raditi: Claude jednom tjedno (ili na gumb) iz tih brojki piše preporuke i ideje za objave.
* Postavke: novi odjeljak „Društvene mreže” (Meta token, YouTube API ključ i kanal, TikTok Client key i secret, „Poveži TikTok”). Novi ključevi spremaju se šifrirano kao i postojeći; postojeći se ne diraju.
* Nova MCP sposobnost ol-radar/radar-mreze; radar-stanje pokazuje koje su mreže povezane.
* Prijedlozi: Claudeov odgovor koji sadrži samo korake (bez izmjene teksta) više se ne vodi kao neuspjeh. Otvoreni prijedlozi koji su ostali bez teksta nakon nadogradnje dobivaju još jedan pokušaj.

= 2.2.1 =
* Popravak: gumbi „Predloži popravak” / „Novi prijedlog” (modul Popravci i AI) i „Brief” (Planer) javljali su „Claude je odgovorio u krivom obliku”. Odgovor sada stiže kao poziv alata, pa je uvijek u dogovorenom obliku; predug odgovor ide ponovno, sažetije.
* Poruke o grešci Claudea sada kažu što se dogodilo (prekinut odgovor, potrošeni krediti, dnevni limit). Zadnji poziv Claudea vidi se u stanju izvora.

= 2.2.0 =
* Učinak provedenih izmjena: za prijedlog označen „provedeno” dodatak sam usporedi Search Console za tu stranicu prije i poslije (7, 14 i 28 dana), uz trend cijelog weba, i da ocjenu. Dan provedbe može se ispraviti na kartici.
* Indeksiranost stranica: tjedna provjera svake stranice u Googleovu indeksu (URL Inspection API, isti servisni račun, besplatno). Panel u modulu Audit weba; stranica koja nije u indeksu dolazi u Prijedloge.
* AI pregled (AI Overview): mjerenje pozicija bilježi za koje se riječi pojavljuje i citira li nas, bez doplate. Po izboru i pregledi koji se učitavaju naknadno (+0,0006 $ po riječi).
* Kretanje konkurenata iz naših mjerenja: udio vidljivosti po domeni, tko je ušao u top 10, tko je ispao i tko nas je pretekao. Panel u modulu Konkurencija.
* Nova MCP sposobnost ol-radar/radar-indeks; radar-pozicije i radar-prijedlozi vraćaju i nove podatke.

= 2.1.1 =
* Prijedlozi: tekst izmjene sada se piše posebno za svaki prijedlog (kratak poziv Claudea s obaveznim oblikom odgovora), pa se odgovor više ne prekida zbog duljine. Ako tekst ne uspije, pokušava se ponovno svaki sat, najviše tri puta; prijedlog do tada vrijedi s koracima iz podataka. „Analiziraj sada” vraća prijedloge odmah, a tekst dopisuje u pozadini.
* Pozicije: gumb „Izmjeri pozicije sada” u modulu Pozicije (uz potvrdu troška), prikaz se sam osvježava dok mjerenje traje. Bez mjerenja ploča više ne pokazuje grešku ni „učitavam”, nego objašnjenje.
* Čišća analiza: upit mora biti o temi stranice; imena drugih optika i brend (i po korijenu riječi) ne ulaze; popis „Zanemari upite” u Postavkama; stranice mijenjane u zadnjih 14 dana ne dobivaju prijedlog naslova; tjedni pad traži stvarno izgubljene klikove; jedan otvoren prijedlog po stranici.
* Google podaci: adrese s parametrima (utm, rwg_token) pribrajaju se pravoj stranici.
* Ishod provjere veze (Google, DataForSEO, Claude) ostaje zapisan u Postavkama. Nova MCP sposobnost ol-radar/radar-stanje.

= 2.1.0 =
* Dnevni prijedlozi izmjena: server sam analizira Google podatke, pozicije i crawl te priprema gotove prijedloge (modul Prijedlozi). Tekst izmjene piše Claude preko Anthropic API ključa; brojke su uvijek iz podataka. Web se ne mijenja.
* Nova MCP sposobnost ol-radar/radar-prijedlozi.
* Ključevi i nadogradnja: nadogradnja ne dira ključeve; ostaju i nakon brisanja pa ponovne instalacije dodatka; upozorenje ako nakon nadogradnje neki ključ nedostaje; nečitljiv zapis se ne briše.
* Veza prema Googleu: HTTP/1.1, kraće čekanje, ponovni pokušaj preko IPv4 i rezervne adrese, točna dijagnostika.

= 2.0.2 =
* Nadzorna ploča je u radnom dijelu wp-admina, a WP izbornik ostaje lijevo (kao kod drugih dodataka).
* Nova svijetla tema kao zadana (topla bijela podloga, zlato samo kao naglasak); tamna tema je na prekidaču u izborniku ploče.

= 2.0.1 =
* Postavke: gumbi za provjeru (Google, DataForSEO, Claude), preuzimanje i mjerenje sada najprije spremaju upisano. Prije su se upisani ključevi gubili ako se kliknula provjera umjesto „Spremi postavke”.
* Upozorenje na nespremljene promjene i potvrda troška prije mjerenja pozicija.
* Popravak: gumb „obriši” bio je ugniježđeni obrazac, pa su „Spremi postavke” i provjere mogli obrisati već spremljeni ključ. Sada je dio glavnog obrasca, s potvrdom.
* Enter u polju = obično spremanje; preglednik više ne upisuje sam lozinku za wp-admin u polja ključeva.

= 2.0.0 =
* OL Radar na hostingu: nadzorna ploča u wp-adminu (isti kod kao Radar na claude.ai), stranica Postavke, šifrirani ključevi.
* Google Search Console preko servisnog računa, pozicije preko DataForSEO-a (tjedno ili ručno), Claude u ploči preko Anthropic API-ja (ključ ostaje na serveru).
* REST ol-radar/v1 za administratore; uvoz podataka iz Radara na claude.ai.
* Nove MCP sposobnosti: radar-sazetak, radar-gsc, radar-pozicije.

= 1.0.1 =
* Popravak: sadržaj <script> i <style> više ne ulazi u parsiranje HTML-a, pa JS predlošci ne stvaraju lažne linkove (npr. lažni „link na preusmjerenje” na naslovnici). JSON-LD se i dalje čita.
* Nakon nadogradnje sve stranice automatski idu na ponovnu obradu.

= 1.0.0 =
* Prva verzija: pregled weba, popis sadržaja, detalji stranice, audit, interni linkovi, osvježavanje crawla.
