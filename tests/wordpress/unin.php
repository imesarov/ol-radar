<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
deactivate_plugins( 'ol-radar-most/ol-radar-most.php' );
echo 'cron after deactivate: ' . ( wp_next_scheduled( 'olr_crawl_tick' ) ? 'still' : 'cleared' ) . "\n";
uninstall_plugin( 'ol-radar-most/ol-radar-most.php' );
$tables = $wpdb->get_col( "SHOW TABLES LIKE 'wp_ol_radar_pages'" );
echo 'table: ' . ( $tables ? 'EXISTS' : 'dropped' ) . "\n";
echo 'role: ' . ( get_role( 'ol_radar_reader' ) ? 'EXISTS' : 'removed' ) . "\n";
echo 'admin cap: ' . ( get_role( 'administrator' )->has_cap( 'ol_radar_read' ) ? 'EXISTS' : 'removed' ) . "\n";
echo 'options: ' . var_export( get_option( 'olr_state', 'none' ), true ) . ' / ' . var_export( get_option( 'olr_db_version', 'none' ), true ) . "\n";
echo 'posts untouched: ' . wp_count_posts( 'post' )->publish . " posts\n";
// ponovna aktivacija
$r = activate_plugin( 'ol-radar-most/ol-radar-most.php' );
echo 'reactivate: ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'ok' ) . ', table: ' . ( $wpdb->get_col( "SHOW TABLES LIKE 'wp_ol_radar_pages'" ) ? 'yes' : 'no' ) . "\n";
