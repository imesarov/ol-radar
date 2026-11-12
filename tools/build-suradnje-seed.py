#!/usr/bin/env python3
"""Iz završne arhive Ahrefsa (data/arhiva/ahrefs-2026-11-13) gradi:
  1. wordpress/ol-radar-most/data/suradnje-pocetno.json  (kandidati za modul Suradnje, priloženi dodatku)
  2. data/arhiva/ahrefs-2026-11-13/uvoz-za-hosting.json  (dopuna arhive za Postavke → Uvoz)
Brojke (DR, broj poveznica) dolaze iz CSV datoteka arhive; ovdje su samo naziv, vrsta, prioritet i
kratki opis kako su konkurenti došli do poveznice."""
import csv, glob, json, os, re, time
from urllib.parse import urlparse

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
A = os.path.join(REPO, "data", "arhiva", "ahrefs-2026-11-13")
IZVOR = "ahrefs-2026-11-13"
KONKURENTI = ["optika-anda.com", "jojo-optika.hr", "adrialece.hr", "livision-optika.com", "optika-dado.hr"]

# domena: (naziv, vrsta, prioritet 1-3, kako su konkurenti došli do poveznice, dio adrese primjera)
M = {
 # 1 = najizvedivije za malu optiku: imenici, programi pogodnosti, lokalno, obrazovanje, udruge
 "brzipregled.hr": ("Brzi pregled", "imenik", 1, "upis u imenik (povezuje svih pet konkurenata)", ""),
 "dioptrija.hr": ("Dioptrija.hr", "imenik", 1, "upis u imenik optika", ""),
 "reputacija.hr": ("Reputacija.hr", "imenik", 1, "profil tvrtke s recenzijama", ""),
 "poliklinike.hr": ("Poliklinike.hr", "imenik", 2, "upis u imenik", ""),
 "centarzdravlja.hr": ("Centar zdravlja", "imenik", 1, "adresar optika", "adresar/optike-9/livision"),
 "optometrija.net": ("Optometrija.net", "imenik", 1, "strukovni portal s adresarom optika", ""),
 "poslovniforum.hr": ("Poslovni forum", "imenik", 1, "besplatan upis u poslovni imenik", "poslovniforum"),
 "njuskalo.hr": ("Njuškalo (katalozi trgovina)", "imenik", 1, "stranica trgovine s akcijama", "njuskalo"),
 "mojposao.hr": ("MojPosao", "imenik", 2, "profil poslodavca i oglas za posao (ima smisla kad zapošljavamo)", "mojposao.hr/posao/4263"),
 "dubrava.hr": ("dubrava.hr", "lokalni_portal", 2, "stranica s popisom optika u Dubravi (susjedni kvart)", "dubrava.hr"),
 "zgportal.com": ("ZGportal", "lokalni_portal", 1, "članak na zagrebačkom portalu", ""),
 "sindikatpolicije.hr": ("Sindikat policije Hrvatske", "partnerski_program", 1, "stranica pogodnosti za članove", "sindikatpolicije.hr/pogodnosti/optika-jo-jo"),
 "nsz.hr": ("Nezavisni sindikat znanosti i visokog obrazovanja", "partnerski_program", 1, "prednosti članstva: popust na naočale", "nsz.hr"),
 "sdlsn.hr": ("Sindikat državnih i lokalnih službenika i namještenika", "partnerski_program", 1, "stranica pogodnosti za članove", "sdlsn.hr"),
 "sindikatbiciklista.hr": ("Sindikat biciklista", "partnerski_program", 1, "lista popusta uz članstvo", "sindikatbiciklista"),
 "matica-sindikata.hr": ("Matica hrvatskih sindikata", "partnerski_program", 1, "pogodnosti za članove", ""),
 "hak.hr": ("HAK", "partnerski_program", 1, "popusti za članove", ""),
 "diners.hr": ("Diners Club", "partnerski_program", 1, "stranica s pogodnostima za korisnike kartice", "diners.hr"),
 "pbzcard-premium.hr": ("PBZ Card Premium", "partnerski_program", 2, "pogodnosti uz karticu", ""),
 "americanexpress.com": ("American Express", "partnerski_program", 2, "pogodnosti uz karticu", ""),
 "rba.hr": ("Raiffeisen banka", "partnerski_program", 2, "pogodnosti uz karticu", "rba.hr"),
 "merkur.hr": ("Merkur osiguranje", "partnerski_program", 2, "popis partnera za specijalističke preglede", "merkur.hr"),
 "boxnow.hr": ("BOX NOW", "partnerski_program", 3, "popis partnerskih web trgovina (samo za web trgovine)", "boxnow.hr/en/eshop"),
 "vvg.hr": ("Veleučilište Velika Gorica", "obrazovanje", 1, "stranica „Poslovna suradnja”", "vvg.hr"),
 "asoo.hr": ("ASOO (strukovno obrazovanje)", "obrazovanje", 2, "nastavni materijal za optičare navodi članak s bloga", "asoo.hr"),
 "skole.hr": ("skole.hr (CARNET, mrežne stranice škola)", "obrazovanje", 2, "spominjanje u školskom programu događanja", ""),
 "hck.hr": ("Hrvatski Crveni križ", "udruga", 1, "zajednička akcija „Staro za novo”", "hck.hr"),
 "kvantum-tim.hr": ("Kvantum-tim (medicinska oprema)", "dobavljac", 2, "stranica „Reference” dobavljača opreme (ima smisla samo ako smo njihov kupac; isti obrazac vrijedi za naše dobavljače)", "kvantum-tim"),
 # 2 = portali kojima treba stručan sadržaj (savjet optometrista)
 "bebe.hr": ("Bebe.hr", "medij", 2, "savjeti za kupnju dječjih naočala", "bebe.hr"),
 "pitajmamu.hr": ("Pitaj mamu", "medij", 2, "članci o zdravlju za roditelje", "pitajmamu"),
 "wishmama.hr": ("Wish mama", "medij", 2, "članak o događanju za djecu", "wishmama"),
 "studentski.hr": ("Studentski.hr", "medij", 2, "savjetodavni članak o naočalama", "studentski.hr"),
 "mirovina.hr": ("Mirovina.hr", "medij", 2, "portal za umirovljenike, članci o zdravlju", "mirovina.hr"),
 "slowliving.hr": ("Slowliving", "medij", 2, "savjetodavni članak o zaštiti očiju od sunca", "slowliving"),
 "adiva.hr": ("ADIVA", "zdravstvo", 2, "članci u rubrici Zdravlje, Oči", "adiva.hr/zdravlje/oci/transplantacija"),
 "naturala.hr": ("Naturala", "medij", 2, "intervjui i članci o zdravlju", "naturala.hr/doktori"),
 "monitor.hr": ("Monitor.hr", "medij", 2, "prenesene objave o zdravlju", "monitor.hr"),
 "runcroatia.hr": ("Run Croatia", "sport", 2, "partner na naslovnici trkačkog portala", "runcroatia"),
 "progressive.hr": ("Progressive magazin", "medij", 2, "trgovački stručni magazin", ""),
 "n1info.hr": ("N1", "medij", 2, "članci u rubrici Zdravlje", "n1info.hr"),
 "dnevnik.hr": ("Dnevnik.hr", "medij", 2, "savjetodavni članak o očima i ekranima; vijest o humanitarnoj akciji", "provodite-puno"),
 "slobodnadalmacija.hr": ("Slobodna Dalmacija", "medij", 2, "članak o prvom pregledu vida kod djece", "pravovremeni"),
 "lidermedia.hr": ("Lider", "medij", 2, "poslovni članak o tržištu zdravlja vida", "lidermedia"),
 "poslovni.hr": ("Poslovni dnevnik", "medij", 2, "vijest uz Svjetski dan vida", "svjetski-dan-vida"),
 "total-croatia-news.com": ("Total Croatia News", "medij", 2, "vijest na engleskom", ""),
 # 3 = nacionalni i modni mediji: poveznice su većinom iz plaćenih promo i native članaka
 "24sata.hr": ("24sata", "medij", 3, "plaćeni native i promo članci; nagradne igre na Miss7", "native-sadrzaj"),
 "jutarnji.hr": ("Jutarnji list", "medij", 3, "promo članci", "jutarnji.hr/promo/online"),
 "vecernji.hr": ("Večernji list", "medij", 3, "lifestyle članci o vidu i rubrika Ordinacija", "imate-dioptriju"),
 "index.hr": ("Index.hr", "medij", 3, "PR članci u rubrikama Rouge i Magazin", "rouge"),
 "tportal.hr": ("tportal", "medij", 3, "PR objave (humanitarna akcija, akcije)", "pomozimo-ukrajini"),
 "net.hr": ("Net.hr", "medij", 3, "članci o zdravlju i Žena.hr", "fizioloska"),
 "gloria.hr": ("Gloria", "medij", 3, "promo i native članci", "pametno-rjesenje"),
 "story.hr": ("Story", "medij", 3, "promo članci", "Pandametrom"),
 "telegram.hr": ("Telegram (Super1)", "medij", 3, "shopping vodiči", "super1/life"),
 "novilist.hr": ("Novi list", "medij", 3, "promo objava humanitarne akcije", "caritas"),
 "nacional.hr": ("Nacional", "medij", 3, "PR objava uz početak školske godine", "back-to-school"),
 "bug.hr": ("Bug", "medij", 3, "spominjanja na forumu", "bug.hr/forum/topic/ostalo/black-friday-2023"),
 "fashion.hr": ("Fashion.hr", "medij", 3, "modni članci (naočale prema obliku lica)", "oblik-lica"),
 "ljepotaizdravlje.hr": ("Ljepota & Zdravlje", "medij", 3, "članci o kolekcijama i sniženjima", "ljepotaizdravlje.hr/suncane"),
 "grazia.hr": ("Grazia", "medij", 3, "modni članci", "grazia.hr/severinina"),
 "elle.hr": ("Elle", "medij", 3, "modni članci", "elle.hr"),
 "jolie.hr": ("Jolie", "medij", 3, "modni članak o dioptrijskim naočalama", "jolie.hr/moda"),
 "magme.hr": ("MagMe", "medij", 3, "izvještaj s otvorenja poslovnice", "magme.hr"),
 "wish.hr": ("Wish", "medij", 3, "lifestyle članci", ""),
 "metro-portal.hr": ("Metro portal", "medij", 3, "PR članci", "metro-portal.hr/tri"),
 "extravagant.com.hr": ("Extravagant", "medij", 3, "modni članci", "extravagant"),
 "marieclaire.hr": ("Marie Claire", "medij", 3, "modni članci", ""),
 "gloriaglam.hr": ("Gloria Glam", "medij", 3, "modni članci", ""),
 "itgirl.hr": ("It Girl", "medij", 3, "velik broj poveznica s modnog portala", ""),
 "cromoda.com": ("CroModa", "medij", 3, "modni članci", ""),
 "modnialmanah.com": ("Modni almanah", "medij", 3, "modni članci", ""),
 "budidobro.com": ("Budi.Dobro", "medij", 3, "lifestyle članci", ""),
 "zenskirecenziraj.com": ("Ženski recenziraj", "medij", 3, "recenzije i preporuke", ""),
}

def host(u):
    h = urlparse(u if "://" in u else "https://" + u).netloc.lower()
    return re.sub(r"^www\.", "", h)

# 1) poveznice po domeni: koliko ih ima koji konkurent ili srodna tvrtka
dr, links = {}, {}
for f in sorted(glob.glob(os.path.join(A, "refd-hr-*.csv"))):
    target = os.path.basename(f)[len("refd-hr-"):-4]
    for r in csv.DictReader(open(f, encoding="utf-8")):
        d = r["domain"]; dr[d] = float(r["domain_rating"]); links.setdefault(d, {})[target] = int(r["links_to_target"])
for r in csv.DictReader(open(os.path.join(A, "refd-ostalo-konkurenti.csv"), encoding="utf-8")):
    d = r["domain"]; dr[d] = float(r["domain_rating"]); links.setdefault(d, {})[r["target"]] = int(r["links_to_target"])
for r in csv.DictReader(open(os.path.join(A, "organski-konkurenti-hr.csv"), encoding="utf-8")):
    dr.setdefault(r["competitor_domain"], float(r["domain_rating"]))

# 2) primjeri stranica s poveznicom
primjeri = []
for line in open(os.path.join(A, "primjeri-poveznica.psv"), encoding="utf-8").read().splitlines()[1:]:
    t, u, n = line.split("|", 2)
    primjeri.append({"target": t, "url": u, "naslov": n.strip()})

def primjer(d, pref):
    c = [p for p in primjeri if host(p["url"]) == d or host(p["url"]).endswith("." + d)]
    if pref:
        c = [p for p in c if pref in p["url"]] or c
    c.sort(key=lambda p: 0 if p["target"] in KONKURENTI else 1)
    return c[0] if c else None

kandidati, bez = [], []
for d, (naziv, vrsta, prio, nacin, pref) in M.items():
    k = dict(sorted(links.get(d, {}).items(), key=lambda x: -x[1]))
    c = {"domena": d, "naziv": naziv, "vrsta": vrsta, "prioritet": prio, "dr": dr.get(d), "konkurenti": k}
    if nacin:
        c["nacin"] = nacin
    p = primjer(d, pref)
    if p:
        c["primjer"] = {"kod": p["target"], "url": p["url"], "naslov": p["naslov"]}
    if not k and d != "optometrija.net":
        bez.append(d)
    kandidati.append(c)
# Hrvatska obrtnička komora: poveznica je s weba županijske komore, a partnerstvo je program HOK-a.
kandidati.append({"domena": "hok.hr", "naziv": "Hrvatska obrtnička komora (HOK ZNA, zajednička nabava)", "vrsta": "udruga", "prioritet": 1, "dr": None, "konkurenti": {},
                  "nacin": "partner projekta HOK ZNA s pogodnostima za obrtnike (primjer je s weba Obrtničke komore PGŽ)",
                  "primjer": {"kod": "ghetaldus.hr", "url": "https://okpgz.hr/ghetaldus-optika-novi-partner-projekta-hok-zna-zajednicka-nabava/", "naslov": "Ghetaldus optika novi partner projekta HOK ZNA - Zajednička nabava"}})
kandidati.sort(key=lambda c: (c["prioritet"], -len(c["konkurenti"]), -(c["dr"] or 0)))
for c in kandidati:
    if "primjer" in c:
        c["primjer"]["naslov"] = c["primjer"]["naslov"].replace("@", " ")[:160].strip()
        assert c["primjer"]["url"].startswith("http"), c
    assert "@" not in c.get("nacin", ""), c

# 3) DR: današnje stanje i mjesečna povijest
rows = list(csv.DictReader(open(os.path.join(A, "dr-povijest-mjesecno.csv"), encoding="utf-8")))
doms = [c for c in rows[0].keys() if c != "mjesec"]
povijest = {"datumi": [r["mjesec"] + "-01" for r in rows], "po": {d: [float(r[d]) for r in rows] for d in doms}}
seed = {
    "izvor": IZVOR,
    "opis": "Završna arhiva Ahrefsa od 13. 11. 2026.: domene koje povezuju konkurente i srodne tvrtke (optike, očne klinike, strukovni portal), a ne nas. Uz svaku: DR, broj poveznica po tvrtki, kako je poveznica nastala i primjer stranice. Prioritet 1 je najizvedivije za malu optiku, 3 su mediji u kojima su poveznice većinom iz plaćenih članaka.",
    "dr": {"optikaljiljana.hr": 0, "optika-anda.com": 28, "jojo-optika.hr": 27, "adrialece.hr": 25, "livision-optika.com": 16, "optika-dado.hr": 7},
    "dr_povijest": povijest,
    "kandidati": kandidati,
}
out = os.path.join(REPO, "wordpress", "ol-radar-most", "data", "suradnje-pocetno.json")
json.dump(seed, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("kandidata:", len(kandidati), "| s primjerom:", sum(1 for c in kandidati if "primjer" in c), "| bez podataka o poveznicama:", bez)
print("prioritet:", {p: sum(1 for c in kandidati if c["prioritet"] == p) for p in (1, 2, 3)}, "| bajtova:", os.path.getsize(out))

# 4) dopuna arhive za hosting (Postavke → Uvoz): nove ključne riječi + sažetak poveznica
at = int(time.time() * 1000)
grupe = {}
for line in open(os.path.join(A, "kljucne-rijeci-dopuna.psv"), encoding="utf-8").read().splitlines()[1:]:
    g, k, v, kd, tp = line.split("|")
    grupe.setdefault(g, []).append({"k": k, "v": int(v), "tp": int(tp) if tp else None, "kd": int(kd) if kd else None})
arch_kw = [{"id": "hr-dopuna-%d" % (i + 1), "data": {"seed": g + " (dopuna 13. 11. 2026.)", "country": "hr", "at": at, "rows": r}} for i, (g, r) in enumerate(grupe.items())]
portali = {}
for line in open(os.path.join(A, "kljucne-rijeci-portala.psv"), encoding="utf-8").read().splitlines()[1:]:
    t, k, v, p, tr = line.split("|")
    portali.setdefault(t, []).append({"k": k, "v": int(v), "pos": int(p), "tr": int(tr)})
nase = list(csv.DictReader(open(os.path.join(A, "nase-poveznice-bez-spama.csv"), encoding="utf-8")))
refh = list(csv.DictReader(open(os.path.join(A, "refdomene-povijest-nase.csv"), encoding="utf-8")))
zavrsna = {"at": at, "izvor": IZVOR, "dr": seed["dr"], "dr_povijest": povijest,
           "nase_poveznice_bez_spama": nase, "nase_refdomene_povijest": [[r["mjesec"], int(r["refdomains"])] for r in refh],
           "poveznice_po_domeni": {d: {"dr": dr[d], "tko": links[d]} for d in sorted(links)},
           "primjeri": primjeri, "kljucne_rijeci_portala": portali}
uvoz = {"opis": "OL Radar: završna arhiva Ahrefsa, 13. 11. 2026.", "zbirke": {"arch_kw": arch_kw, "arch_misc": [{"id": "zavrsna-2026-11-13", "data": zavrsna}]}}
o2 = os.path.join(A, "uvoz-za-hosting.json")
json.dump(uvoz, open(o2, "w", encoding="utf-8"), ensure_ascii=False)
print("uvoz: arch_kw", len(arch_kw), "dokumenata,", sum(len(d["data"]["rows"]) for d in arch_kw), "riječi | domena s poveznicama:", len(links), "| bajtova:", os.path.getsize(o2))
