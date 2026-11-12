=== OL Radar – most za Claudea ===
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPL-2.0-or-later

OL Radar na hostingu (nadzorna ploča: Google Search Console, pozicije, audit, konkurencija, AI prijedlozi) i pristup webu samo za čitanje za Claudea preko MCP-a.

== Description ==

Registrira sposobnosti (WordPress Abilities API) u kategoriji „OL Radar”, izložene MCP Adapteru. Sve sposobnosti traže pravo ol_radar_read i samo čitaju; jedina iznimka „osvjezi-crawl” mijenja isključivo interni cache dodatka. Detaljne upute su u README.md.

== Changelog ==

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
