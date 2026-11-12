#!/usr/bin/env bash
# Test nadogradnje: spremljeni ključevi, postavke i podaci moraju preživjeti prelazak sa SVAKE
# izdane verzije 2.x na trenutni kod, deaktivaciju/aktivaciju te brisanje i ponovnu instalaciju.
#
# Stare verzije uzimaju se iz povijesti repozitorija prema popisu tests/wordpress/izdanja.txt
# (verzija i commit iz kojeg je građen zip), dakle isti kod koji je bio isporučen.
# Svako izdanje dobiva redak u tom popisu (provjerava tools/release.sh), pa ga test ubuduće pokriva.
#
# Upotreba: WPTEST_DIR=/tmp/wptest bash tests/wordpress/upgrade_test.sh
set -euo pipefail
: "${WPTEST_DIR:?Postavi WPTEST_DIR}"
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
P="$WPTEST_DIR/site/wp-content/plugins"
TMP="$WPTEST_DIR/upgrade_tmp"
export WPTEST_DIR
fail=0

LIST="$HERE/izdanja.txt"
cur=$(grep -o "OLR_VERSION', '[0-9.]*'" "$REPO/wordpress/ol-radar-most/ol-radar-most.php" | grep -o "[0-9][0-9.]*")
n=0
while read -r ver ref; do
  case "$ver" in ''|\#*) continue ;; esac
  [ "$ver" = "$cur" ] && continue   # ista verzija kao trenutni kod
  n=$((n + 1))
  echo "--- nadogradnja s $ver ($ref)"
  rm -rf "$TMP" && mkdir -p "$TMP"
  git -C "$REPO" archive "$ref" wordpress/ol-radar-most | tar -x -C "$TMP" || { echo "FAIL commit $ref (verzija $ver) nije dostupan; treba puna povijest repozitorija"; exit 1; }
  rm -rf "$P/ol-radar-most" && cp -r "$TMP/wordpress/ol-radar-most" "$P/ol-radar-most"
  php "$HERE/upgrade_step.php" seed "$ver" || fail=1
  # „Zamijeni postojeći”: mapa dodatka se zamijeni novom, bez deaktivacije i bez uninstall.php.
  rm -rf "$P/ol-radar-most" && cp -r "$REPO/wordpress/ol-radar-most" "$P/ol-radar-most"
  php "$HERE/upgrade_step.php" check "$ver" || fail=1
done < "$LIST"
[ "$n" -gt 0 ] || { echo "FAIL u $LIST nema starijih izdanja za test"; exit 1; }

echo "--- deaktivacija i aktivacija"
php "$HERE/upgrade_step.php" reactivate || fail=1
echo "--- brisanje dodatka i ponovna instalacija"
php "$HERE/upgrade_step.php" uninstall || fail=1

rm -rf "$TMP"
if [ "$fail" = 0 ]; then echo "NADOGRADNJA: SVE PROŠLO"; else echo "NADOGRADNJA: NEUSPJELO"; exit 1; fi
