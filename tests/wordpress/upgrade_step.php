<?php
/**
 * Koraci testa nadogradnje (vidi upgrade_test.sh). Svaki korak je zaseban PHP proces, da se učita
 * kod one verzije dodatka koja je u tom trenutku u mapi plugins.
 *
 *   seed <verzija>      stara verzija: spremi ključeve, postavke i podatke njezinim kodom
 *   check <verzija>     nova verzija: sve mora biti na mjestu i čitljivo
 *   reactivate          deaktivacija pa aktivacija
 *   uninstall           brisanje dodatka (uninstall.php) pa ponovna aktivacija
 */
$_SERVER['HTTP_HOST']   = '127.0.0.1:8089';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';

$step  = isset( $argv[1] ) ? $argv[1] : '';
$ver   = isset( $argv[2] ) ? $argv[2] : '';
$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

// Vrijednosti koje moraju preživjeti (nisu pravi ključevi).
$keys = array(
	'gsc_sa'        => '{"type":"service_account","client_email":"nadogradnja@test.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n"}',
	'dfs_login'     => 'nadogradnja@test.hr',
	'dfs_password'  => 'lozinka-koja-ostaje-Š-đ-ž',
	'anthropic_key' => 'sk-ant-nadogradnja-9876',
);
$kw   = array( 'riječ jedan', 'riječ dva', 'optika sesvete' );

$check_all = static function ( $label ) use ( $keys, $kw ) {
	foreach ( $keys as $name => $val ) {
		ok( $val === OLR_Settings::secret( $name ), $label . ': ključ ' . $name . ' je isti' );
	}
	$s = OLR_Settings::all();
	ok( $kw === $s['rank_keywords'] && 'claude-opus-5-5' === $s['ai_model'] && true === $s['rank_weekly'] && 'sc-domain:optikaljiljana.hr' === $s['gsc_site'], $label . ': postavke su iste' );
	ok( 'Plan koji ostaje' === OLR_Store::data( 'plan', 'nadogradnja' )['naslov'], $label . ': podaci Radara su isti' );
};

if ( 'seed' === $step ) {
	ok( OLR_VERSION === $ver, 'polazna verzija je ' . $ver . ' (učitano ' . OLR_VERSION . ')' );
	foreach ( array( 'olr_secrets', 'olr_settings', 'olr_status', 'olr_keys_seen', 'olr_upgrade_warning', 'olr_version', 'olr_net_ok' ) as $o ) {
		delete_option( $o );
	}
	foreach ( $keys as $name => $val ) {
		OLR_Settings::set_secret( $name, $val );
	}
	OLR_Settings::update( array( 'rank_keywords' => $kw, 'ai_model' => 'claude-opus-5-5', 'rank_weekly' => true, 'gsc_site' => 'sc-domain:optikaljiljana.hr' ) );
	OLR_Store::set( 'plan', 'nadogradnja', array( 'naslov' => 'Plan koji ostaje' ) );
	update_option( 'olr_test_seed_version', $ver, false );
	ok( $keys['anthropic_key'] === OLR_Settings::secret( 'anthropic_key' ), 'ključevi spremljeni kodom verzije ' . $ver );
} elseif ( 'check' === $step ) {
	ok( get_option( 'olr_test_seed_version' ) === $ver && OLR_VERSION !== $ver, 'nadogradnja ' . $ver . ' → ' . OLR_VERSION );
	$check_all( $ver . ' → ' . OLR_VERSION );
	ok( OLR_VERSION === get_option( 'olr_version' ), 'verzija zabilježena' );
	ok( null === OLR_Settings::upgrade_warning(), 'nema upozorenja o izgubljenom ključu' );
	$seen = (array) get_option( 'olr_keys_seen' );
	sort( $seen );
	ok( array( 'anthropic_key', 'dfs_login', 'dfs_password', 'gsc_sa' ) === $seen, 'popis spremljenih ključeva vodi se od ove verzije' );
	ok( (bool) wp_next_scheduled( 'olr_radar_tick' ), 'satni raspored postoji' );
	ok( false === strpos( wp_json_encode( get_option( 'olr_secrets' ) ), 'sk-ant-nadogradnja' ), 'ključevi su u bazi i dalje šifrirani' );
} elseif ( 'reactivate' === $step ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( 'ol-radar-most/ol-radar-most.php' );
	ok( ! is_plugin_active( 'ol-radar-most/ol-radar-most.php' ), 'dodatak deaktiviran' );
	$r = activate_plugin( 'ol-radar-most/ol-radar-most.php' );
	ok( ! is_wp_error( $r ) && is_plugin_active( 'ol-radar-most/ol-radar-most.php' ), 'dodatak ponovno aktiviran' );
	$check_all( 'deaktivacija i aktivacija' );
} elseif ( 'uninstall' === $step ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( 'ol-radar-most/ol-radar-most.php' );
	define( 'WP_UNINSTALL_PLUGIN', 'ol-radar-most/ol-radar-most.php' );
	require WP_PLUGIN_DIR . '/ol-radar-most/uninstall.php';
	global $wpdb;
	ok( is_array( get_option( 'olr_secrets' ) ) && 4 === count( get_option( 'olr_secrets' ) ), 'brisanje dodatka: šifrirani ključevi ostaju u bazi' );
	ok( is_array( get_option( 'olr_settings' ) ), 'brisanje dodatka: postavke ostaju' );
	ok( null === get_role( 'ol_radar_reader' ), 'brisanje dodatka: uloga uklonjena (ostalo se čisti kao i prije)' );
	$r = activate_plugin( 'ol-radar-most/ol-radar-most.php' );
	ok( ! is_wp_error( $r ), 'ponovna instalacija: aktivacija' );
	$check_all( 'brisanje i ponovna instalacija' );
	ok( null !== get_role( 'ol_radar_reader' ), 'ponovna instalacija: uloga vraćena' );
	delete_option( 'olr_test_seed_version' );
} else {
	echo "Nepoznat korak.\n";
	exit( 2 );
}
exit( $fails ? 1 : 0 );
