<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$r = wp_install( 'Optika Test', 'admin', 'admin@example.com', true, '', 'admin-pass-123' );
echo "installed user " . $r['user_id'] . "\n";
