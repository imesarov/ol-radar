#!/usr/bin/env bash
# Lokalni testni WordPress (6.9 + SQLite + MCP Adapter) za dodatak „OL Radar – most za Claudea”.
# Upotreba:  WPTEST_DIR=/tmp/wptest bash tests/wordpress/setup.sh
# Zatim:     WPTEST_DIR=/tmp/wptest bash tests/wordpress/run.sh
set -euo pipefail

: "${WPTEST_DIR:?Postavi WPTEST_DIR (npr. /tmp/wptest)}"
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
DL="$WPTEST_DIR/downloads"
PORT="${WPTEST_PORT:-8089}"

mkdir -p "$DL" "$WPTEST_DIR/db"

# 1) Izvori (službeni GitHub repozitoriji)
[ -d "$DL/wp" ]     || git clone -q --depth 1 --branch 6.9 https://github.com/WordPress/WordPress.git "$DL/wp"
[ -d "$DL/sqlite" ] || git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration.git "$DL/sqlite"
[ -f "$DL/mcp-adapter.zip" ] || curl -sL -o "$DL/mcp-adapter.zip" https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip

# 2) Web
rm -rf "$WPTEST_DIR/site"
cp -r "$DL/wp" "$WPTEST_DIR/site" && rm -rf "$WPTEST_DIR/site/.git"
P="$WPTEST_DIR/site/wp-content/plugins"
cp -rL "$DL/sqlite/packages/plugin-sqlite-database-integration" "$P/sqlite-database-integration"
unzip -q -o "$DL/mcp-adapter.zip" -d "$P"
cp -r "$REPO/wordpress/ol-radar-most" "$P/ol-radar-most"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$P/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$P/sqlite-database-integration/db.copy" > "$WPTEST_DIR/site/wp-content/db.php"
mkdir -p "$WPTEST_DIR/site/wp-content/mu-plugins"
cp "$HERE/mu-plugins/test-helpers.php" "$WPTEST_DIR/site/wp-content/mu-plugins/"

cat > "$WPTEST_DIR/site/wp-config.php" <<EOF
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', 'wp' ); define( 'DB_PASSWORD', 'wp' ); define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', '$WPTEST_DIR/db/' ); define( 'DB_FILE', 'test.sqlite' );
define( 'AUTH_KEY', 'test-a' ); define( 'SECURE_AUTH_KEY', 'test-b' ); define( 'LOGGED_IN_KEY', 'test-c' ); define( 'NONCE_KEY', 'test-d' );
define( 'AUTH_SALT', 'test-e' ); define( 'SECURE_AUTH_SALT', 'test-f' ); define( 'LOGGED_IN_SALT', 'test-g' ); define( 'NONCE_SALT', 'test-h' );
\$table_prefix = 'wp_';
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$WPTEST_DIR/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://127.0.0.1:$PORT' ); define( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF

# 3) Instalacija i testni sadržaj
export WPTEST_DIR
php "$HERE/install.php"
php "$HERE/setup.php"
echo "Gotovo. Pokreni server i testove: WPTEST_DIR=$WPTEST_DIR bash tests/wordpress/run.sh"
