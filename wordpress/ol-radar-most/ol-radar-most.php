<?php
/**
 * Plugin Name:       OL Radar – most za Claudea
 * Description:       OL Radar na hostingu: nadzorna ploča (Google Search Console, pozicije, audit weba iznutra, konkurencija, društvene mreže, AI prijedlozi) i most za Claudea preko MCP-a. Sadržaj weba i mreže samo čita, ne mijenja ih.
 * Version:           2.4.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Optika Ljiljana
 * License:           GPL-2.0-or-later
 * Text Domain:       ol-radar-most
 */

defined( 'ABSPATH' ) || exit;

define( 'OLR_VERSION', '2.4.0' );
define( 'OLR_PARSER_VERSION', '2' );
define( 'OLR_DB_VERSION', '2' );
define( 'OLR_FILE', __FILE__ );
define( 'OLR_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLR_CAP', 'ol_radar_read' );
define( 'OLR_ROLE', 'ol_radar_reader' );
define( 'OLR_CRON', 'olr_crawl_tick' );
define( 'OLR_RADAR_CRON', 'olr_radar_tick' );

require_once OLR_DIR . 'includes/class-olr-crawler.php';
require_once OLR_DIR . 'includes/class-olr-audit.php';
require_once OLR_DIR . 'includes/class-olr-abilities.php';
require_once OLR_DIR . 'includes/class-olr-admin.php';
require_once OLR_DIR . 'includes/class-olr-store.php';
require_once OLR_DIR . 'includes/class-olr-settings.php';
require_once OLR_DIR . 'includes/class-olr-net.php';
require_once OLR_DIR . 'includes/class-olr-gsc.php';
require_once OLR_DIR . 'includes/class-olr-dfs.php';
require_once OLR_DIR . 'includes/class-olr-ai.php';
require_once OLR_DIR . 'includes/class-olr-analiza.php';
require_once OLR_DIR . 'includes/class-olr-prijedlozi.php';
require_once OLR_DIR . 'includes/class-olr-ucinak.php';
require_once OLR_DIR . 'includes/class-olr-indeks.php';
require_once OLR_DIR . 'includes/class-olr-social.php';
require_once OLR_DIR . 'includes/class-olr-suradnje.php';
require_once OLR_DIR . 'includes/class-olr-rest.php';
require_once OLR_DIR . 'includes/class-olr-radar-admin.php';

/**
 * Aktivacija: tablica, uloga, pravo za administratora, cron.
 */
function olr_activate() {
	OLR_Crawler::install_table();
	OLR_Store::install();

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
	if ( ! wp_next_scheduled( OLR_RADAR_CRON ) ) {
		wp_schedule_event( time() + 300, 'hourly', OLR_RADAR_CRON );
	}
}
register_activation_hook( __FILE__, 'olr_activate' );

/**
 * Deaktivacija: samo gasi cron. Podaci i uloga ostaju dok se dodatak ne obriše.
 */
function olr_deactivate() {
	wp_clear_scheduled_hook( OLR_CRON );
	wp_clear_scheduled_hook( OLR_RADAR_CRON );
	wp_clear_scheduled_hook( 'olr_prijedlozi_event' );
	wp_clear_scheduled_hook( 'olr_prijedlozi_tekst' );
	wp_clear_scheduled_hook( 'olr_ucinak_event' );
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
		if ( get_option( 'olr_store_version' ) !== OLR_Store::DB_VERSION ) {
			OLR_Store::install();
		}
		// Nakon nadogradnje: spremljeni ključevi moraju ostati čitljivi (samo provjera, ništa se ne briše).
		OLR_Settings::check_upgrade();
		// Nadogradnja s 1.x preko „Zamijeni postojeći” ne pokreće aktivaciju, pa se raspored dodaje ovdje.
		if ( ! wp_next_scheduled( OLR_RADAR_CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', OLR_RADAR_CRON );
		}
		// Novi parser: postojeće stranice idu na ponovnu obradu (cron ih obrađuje u serijama).
		if ( get_option( 'olr_parser_version' ) !== OLR_PARSER_VERSION ) {
			OLR_Crawler::requeue_all();
			update_option( 'olr_parser_version', OLR_PARSER_VERSION, false );
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
 * Radar na hostingu, svaki sat: Google podaci ako su stariji od 12 sati (nakon neuspjeha novi pokušaj
 * tek za 3 sata), rezultati mjerenja u tijeku, tjedno mjerenje pozicija ako je uključeno u postavkama,
 * dnevna analiza s prijedlozima izmjena kad stignu novi Google podaci, učinak provedenih izmjena,
 * mala serija provjera indeksiranosti i društvene mreže (podaci dvaput dnevno, preporuke tjedno).
 */
add_action(
	OLR_RADAR_CRON,
	static function () {
		$t0   = time();
		$meta = OLR_Store::data( 'gsc', 'meta' );
		$age  = $meta && ! empty( $meta['at'] ) ? time() - (int) ( $meta['at'] / 1000 ) : PHP_INT_MAX;
		$st   = OLR_Settings::status();
		$fail = isset( $st['gsc'] ) && empty( $st['gsc']['ok'] ) ? time() - (int) $st['gsc']['at'] : PHP_INT_MAX;
		if ( OLR_Settings::secret( 'gsc_sa' ) && $age > 12 * HOUR_IN_SECONDS && $fail > 3 * HOUR_IN_SECONDS ) {
			OLR_GSC::collect();
		}
		if ( OLR_DFS::has_credentials() ) {
			OLR_DFS::poll();
			OLR_DFS::maybe_weekly();
		}
		if ( OLR_Prijedlozi::due() ) {
			OLR_Prijedlozi::run( 'auto' );
		} elseif ( OLR_Prijedlozi::fill_due() ) {
			// Otvoreni prijedlozi kojima još nije napisan tekst (npr. prekinut poziv): novi pokušaj.
			OLR_Prijedlozi::run( 'tekst' );
		}
		// Ako je prolaz već potrajao (npr. Claude je pisao tekst prijedloga), ostatak ide u sljedećem satu.
		if ( time() - $t0 < 90 ) {
			// Učinak provedenih izmjena: Search Console prije i poslije (redovito ide nakon novih Google podataka).
			OLR_Ucinak::maybe();
		}
		if ( time() - $t0 < 60 ) {
			// Indeksiranost: mala serija stranica po satu (svaka stranica jednom tjedno).
			OLR_Indeks::tick();
		}
		if ( time() - $t0 < 30 ) {
			// Društvene mreže samo ako je prolaz dosad bio kratak; inače u sljedećem satu.
			OLR_Social::tick();
		}
		if ( time() - $t0 < 30 ) {
			// Suradnje i poveznice: jednom tjedno prijedlozi, po želji nove tvrtke i DR.
			OLR_Suradnje::tick();
		}
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

OLR_Net::init();
OLR_Prijedlozi::init();
OLR_Ucinak::init();
OLR_REST::init();

if ( is_admin() ) {
	OLR_Admin::init();
	OLR_Radar_Admin::init();
}
