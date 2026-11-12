#!/usr/bin/env bash
# Izdavanje WordPress dodatka. Zip nastaje SAMO ako prođu svi testovi, uključujući test nadogradnje
# (spremljeni ključevi, postavke i podaci moraju preživjeti prelazak sa svake izdane verzije 2.x).
#
# Upotreba:  bash tools/release.sh            # zip u ../izlaz
#            OUT_DIR=/neka/mapa bash tools/release.sh
#
# Nakon uspjeha i commita: dodaj redak "<verzija> <commit>" u tests/wordpress/izdanja.txt,
# da test nadogradnje sljedećeg izdanja pokrije i ovo.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
VER=$(grep -o "OLR_VERSION', '[0-9.]*'" "$REPO/wordpress/ol-radar-most/ol-radar-most.php" | grep -o "[0-9][0-9.]*")
OUT="${OUT_DIR:-$REPO/../izlaz}"
CACHE="${WPTEST_CACHE:-/tmp/olr-wp-cache}"
WORK="$(mktemp -d /tmp/olr-release.XXXXXX)"
export WPTEST_DIR="$WORK"

echo "=== OL Radar – most za Claudea $VER"
for f in "$REPO/wordpress/ol-radar-most/readme.txt" "$REPO/wordpress/ol-radar-most/README.md" "$REPO/CHANGELOG.md"; do
  grep -q "$VER" "$f" || { echo "FAIL $f ne spominje verziju $VER"; exit 1; }
done

# Svako ranije izdanje 2.x iz dnevnika promjena mora biti u popisu za test nadogradnje.
for v in $(grep -o "^= 2\.[0-9.]* =" "$REPO/wordpress/ol-radar-most/readme.txt" | grep -o "[0-9][0-9.]*"); do
  [ "$v" = "$VER" ] && continue
  grep -q "^$v " "$REPO/tests/wordpress/izdanja.txt" || { echo "FAIL izdanje $v nije u tests/wordpress/izdanja.txt (test nadogradnje ga ne bi pokrio)"; exit 1; }
done

echo "=== izgradnja nadzorne ploče"
python3 "$REPO/tools/build-hosted.py"

echo "=== svježi testni WordPress"
if [ -d "$CACHE/wp" ]; then mkdir -p "$WORK/downloads" && cp -r "$CACHE/." "$WORK/downloads/"; fi
bash "$REPO/tests/wordpress/setup.sh" > "$WORK/setup.log" 2>&1 || { tail -20 "$WORK/setup.log"; exit 1; }
mkdir -p "$CACHE" && cp -rn "$WORK/downloads/." "$CACHE/" 2>/dev/null || true

echo "=== testovi dodatka (nadogradnja, MCP, crawl, Radar, nadzorna ploča)"
bash "$REPO/tests/wordpress/run.sh" > "$WORK/run.log" 2>&1 || { grep -n "FAIL\|NEUSPJELO\|Fatal\|Error" "$WORK/run.log" | head -40; echo "Dnevnik: $WORK/run.log"; exit 1; }
grep -c "^OK" "$WORK/run.log" | sed 's/^/provjera prošlo: /'
grep -q "NADOGRADNJA: SVE PROŠLO" "$WORK/run.log" || { echo "FAIL test nadogradnje nije prošao"; exit 1; }

echo "=== testovi aplikacije (claude.ai način)"
python3 "$REPO/tests/app/run_e2e.py" > "$WORK/app.log" 2>&1 || { grep -n "FAIL\|PAGEERROR\|console.error" "$WORK/app.log" | head -20; echo "Dnevnik: $WORK/app.log"; exit 1; }

echo "=== zip"
mkdir -p "$OUT"
ZIP="$OUT/ol-radar-most-$VER.zip"
rm -f "$ZIP"
( cd "$REPO/wordpress" && zip -qr "$ZIP" ol-radar-most -x "*/.DS_Store" )
unzip -p "$ZIP" ol-radar-most/ol-radar-most.php | grep -q "OLR_VERSION', '$VER'" || { echo "FAIL zip nema verziju $VER"; exit 1; }
echo "GOTOVO: $ZIP"
echo "Dnevnici: $WORK"
