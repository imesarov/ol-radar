=== OL Radar – most za Claudea ===
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later

Pristup webu samo za čitanje za OL Radar (Claude preko MCP-a): popis sadržaja, Rank Math podaci, on-site audit iznutra i interni linkovi.

== Description ==

Registrira sposobnosti (WordPress Abilities API) u kategoriji „OL Radar”, izložene MCP Adapteru. Sve sposobnosti traže pravo ol_radar_read i samo čitaju; jedina iznimka „osvjezi-crawl” mijenja isključivo interni cache dodatka. Detaljne upute su u README.md.

== Changelog ==

= 1.0.1 =
* Popravak: sadržaj <script> i <style> više ne ulazi u parsiranje HTML-a, pa JS predlošci ne stvaraju lažne linkove (npr. lažni „link na preusmjerenje” na naslovnici). JSON-LD se i dalje čita.
* Nakon nadogradnje sve stranice automatski idu na ponovnu obradu.

= 1.0.0 =
* Prva verzija: pregled weba, popis sadržaja, detalji stranice, audit, interni linkovi, osvježavanje crawla.
