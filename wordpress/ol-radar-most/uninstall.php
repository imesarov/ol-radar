<?php
/**
 * Brisanje dodatka: uklanja tablicu, postavke, ulogu i pravo. Sadržaj weba se ne dira.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$olr_table = $wpdb->prefix . 'ol_radar_pages';
$wpdb->query( "DROP TABLE IF EXISTS {$olr_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'olr_db_version' );
delete_option( 'olr_state' );
delete_transient( 'olr_inventory' );
delete_transient( 'olr_lock' );
wp_clear_scheduled_hook( 'olr_crawl_tick' );

remove_role( 'ol_radar_reader' );
$olr_admin = get_role( 'administrator' );
if ( $olr_admin ) {
	$olr_admin->remove_cap( 'ol_radar_read' );
}
