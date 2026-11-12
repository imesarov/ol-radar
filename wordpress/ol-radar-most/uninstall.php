<?php
/**
 * Brisanje dodatka: uklanja tablicu crawla (cache), stanje, ulogu i pravo. Sadržaj weba se ne dira.
 *
 * Postavke, šifrirani ključevi (i TikTok veza) i podaci Radara (tablica ol_radar_docs: povijest, arhiva, plan, prijedlozi)
 * OSTAJU, da se brisanjem i ponovnom instalacijom dodatka (npr. pri prelasku na novu verziju) ništa ne
 * izgubi. Brišu se samo ako je u wp-config.php definirano OLR_DELETE_DATA. Pojedini ključ briše se
 * gumbom „obriši” u OL Radar → Postavke.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$olr_table = $wpdb->prefix . 'ol_radar_pages';
$wpdb->query( "DROP TABLE IF EXISTS {$olr_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'olr_db_version' );
delete_option( 'olr_state' );
foreach ( array( 'olr_status', 'olr_rank_pending', 'olr_parser_version', 'olr_net_ok' ) as $olr_opt ) {
	delete_option( $olr_opt );
}
delete_transient( 'olr_gsc_token' );
wp_clear_scheduled_hook( 'olr_radar_tick' );
wp_clear_scheduled_hook( 'olr_prijedlozi_event' );
wp_clear_scheduled_hook( 'olr_prijedlozi_tekst' );
wp_clear_scheduled_hook( 'olr_ucinak_event' );
if ( defined( 'OLR_DELETE_DATA' ) && OLR_DELETE_DATA ) {
	foreach ( array( 'olr_settings', 'olr_secrets', 'olr_keys_seen', 'olr_upgrade_warning', 'olr_version', 'olr_dfs_cost', 'olr_ai_usage', 'olr_prijedlozi_run', 'olr_prijedlozi_ponovi', 'olr_ucinak_run', 'olr_tt_tokens', 'olr_mreze_run', 'olr_suradnje_run', 'olr_suradnje_seed' ) as $olr_opt ) {
		delete_option( $olr_opt );
	}
	$olr_docs = $wpdb->prefix . 'ol_radar_docs';
	$wpdb->query( "DROP TABLE IF EXISTS {$olr_docs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'olr_store_version' );
}
delete_transient( 'olr_inventory' );
delete_transient( 'olr_lock' );
delete_transient( 'olr_mreze_lock' );
wp_clear_scheduled_hook( 'olr_crawl_tick' );

remove_role( 'ol_radar_reader' );
$olr_admin = get_role( 'administrator' );
if ( $olr_admin ) {
	$olr_admin->remove_cap( 'ol_radar_read' );
}
