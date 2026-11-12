#!/usr/bin/env bash
# Test nadogradnje: spremljeni ključevi, postavke i podaci moraju preživjeti prelazak sa SVAKE
# izdane verzije 2.x na trenutni kod, deaktivaciju/aktivaciju te brisanje i ponovnu instalaciju.
#
# Stare verzije uzimaju se iz git oznaka (v2.0.0, v2.0.1 …), dakle isti kod koji je bio isporučen.
# Svako izdanje dobiva oznaku (tools/release.sh), pa ga ovaj test ubuduće pokriva automatski.
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

TAGS=$(git -C "$REPO" tag -l 'v2.*' --sort=v:refname)
[ -n "$TAGS" ] || { echo "FAIL nema git oznaka v2.* (stare verzije za test)"; exit 1; }

for tag in $TAGS; do
  ver="${tag#v}"
  cur=$(grep -o "OLR_VERSION', '[0-9.]*'" "$REPO/wordpress/ol-radar-most/ol-radar-most.php" | grep -o "[0-9][0-9.]*")
  [ "$ver" = "$cur" ] && continue   # ista verzija kao trenutni kod
  echo "--- nadogradnja s $ver"
  rm -rf "$TMP" && mkdir -p "$TMP"
  git -C "$REPO" archive "$tag" wordpress/ol-radar-most | tar -x -C "$TMP"
  rm -rf "$P/ol-radar-most" && cp -r "$TMP/wordpress/ol-radar-most" "$P/ol-radar-most"
  php "$HERE/upgrade_step.php" seed "$ver" || fail=1
  # „Zamijeni postojeći”: mapa dodatka se zamijeni novom, bez deaktivacije i bez uninstall.php.
  rm -rf "$P/ol-radar-most" && cp -r "$REPO/wordpress/ol-radar-most" "$P/ol-radar-most"
  php "$HERE/upgrade_step.php" check "$ver" || fail=1
done

echo "--- deaktivacija i aktivacija"
php "$HERE/upgrade_step.php" reactivate || fail=1
echo "--- brisanje dodatka i ponovna instalacija"
php "$HERE/upgrade_step.php" uninstall || fail=1

rm -rf "$TMP"
if [ "$fail" = 0 ]; then echo "NADOGRADNJA: SVE PROŠLO"; else echo "NADOGRADNJA: NEUSPJELO"; exit 1; fi
