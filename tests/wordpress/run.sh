#!/usr/bin/env bash
# Pokreće lokalni PHP server (4 radnika, zbog loopback dohvata) i MCP testove kao korisnik samo za čitanje.
set -euo pipefail
: "${WPTEST_DIR:?Postavi WPTEST_DIR}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PORT="${WPTEST_PORT:-8089}"
export WPTEST_DIR

( cd /tmp && PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$PORT" -t "$WPTEST_DIR/site" "$HERE/router.php" > "$WPTEST_DIR/server.log" 2>&1 & )
sleep 2
trap 'pkill -f "php -S 127.0.0.1:$PORT" || true' EXIT

php "$HERE/setup2.php"                       # dupli naslovi + korisnik bez prava
python3 "$HERE/mcp_test.py" "$WPTEST_DIR/reader.pass"
python3 "$HERE/test_abilities.py"
php "$HERE/scale.php" && php "$HERE/runcron.php"   # 300 stranica: crawl, audit, admin
echo "--- debug.log (bez očekivanih odbijanja anonimnih zahtjeva):"
grep -v "User ID 0 does not have" "$WPTEST_DIR/debug.log" 2>/dev/null || echo "(prazno)"
