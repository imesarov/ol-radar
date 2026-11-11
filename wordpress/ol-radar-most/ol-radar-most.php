<?php
/**
 * Plugin Name:       OL Radar – most za Claudea
 * Description:       Daje Claudeu (OL Radar) pristup webu samo za čitanje: popis sadržaja, Rank Math podaci, on-site audit iznutra i interni linkovi. Ne mijenja sadržaj weba.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Optika Ljiljana
 * License:           GPL-2.0-or-later
 * Text Domain:       ol-radar-most
 */

defined( 'ABSPATH' ) || exit;

define( 'OLR_VERSION', '1.0.0' );
define( 'OLR_DB_VERSION', '2' );
define( 'OLR_FILE', __FILE__ );
define( 'OLR_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLR_CAP', 'ol_radar_read' );
define( 'OLR_ROLE', 'ol_radar_reader' );
define( 'OLR_CRON', 'olr_crawl_tick' );

require_once OLR_DIR . 'includes/class-olr-crawler.php';
require_once OLR_DIR . 'includes/class-olr-audit.php';
require_once OLR_DIR . 'includes/class-olr-abilities.php';
require_once OLR_DIR . 'includes/class-olr-admin.php';

/**
 * Aktivacija: tablica, uloga, pravo za administratora, cron.
 */
function olr_activate() {
	OLR_Crawler::install_table();

	add_role(
		OLR_ROLE,
		'OL Radar – samo čitanje',
		array(
			'read'  => true,
			OLR_CAP => true,
		)
	);
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( OLR_CAP );
	}

	if ( ! wp_next_scheduled( OLR_CRON ) ) {
		wp_schedule_event( time() + 120, 'hourly', OLR_CRON );
	}
}
register_activation_hook( __FILE__, 'olr_activate' );

/**
 * Deaktivacija: samo gasi cron. Podaci i uloga ostaju dok se dodatak ne obriše.
 */
function olr_deactivate() {
	wp_clear_scheduled_hook( OLR_CRON );
}
register_deactivation_hook( __FILE__, 'olr_deactivate' );

/**
 * Nadogradnja tablice ako se promijeni verzija sheme.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'olr_db_version' ) !== OLR_DB_VERSION ) {
			OLR_Crawler::install_table();
		}
	}
);

/**
 * Pozadinsko osvježavanje cachea: svaki sat obradi malu seriju stranica.
 */
add_action(
	OLR_CRON,
	static function () {
		OLR_Crawler::run_batch( 10, 40 );
	}
);

/**
 * Zahtjevi crawlera dolaze s potpisanim parametrom `olr`: takav prikaz se ne sprema u cache
 * (WP Rocket, LiteSpeed), da cache ne puni varijantama i da audit uvijek vidi svježi HTML.
 */
add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- potpisani token, ne obrazac.
		if ( empty( $_GET['olr'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['olr'] ) );
		if ( ! OLR_Crawler::is_valid_token( $token ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'OL Radar crawl' );
		nocache_headers();
	},
	0
);

/**
 * Registracija kategorije i sposobnosti (Abilities API, WordPress 6.9+).
 */
add_action( 'wp_abilities_api_categories_init', array( 'OLR_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'OLR_Abilities', 'register' ) );

if ( is_admin() ) {
	OLR_Admin::init();
}
