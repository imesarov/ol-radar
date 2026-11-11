<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
$t = microtime( true ); $m0 = memory_get_usage();
do_action( 'olr_crawl_tick' );
echo 'cron tick: ' . round( microtime( true ) - $t, 1 ) . "s, stats: " . wp_json_encode( OLR_Crawler::stats() ) . "\n";
for ( $i = 0; $i < 40 && OLR_Crawler::count_stale() > 0; $i++ ) { OLR_Crawler::run_batch( 25, 25 ); }
echo 'after batches: ' . round( microtime( true ) - $t, 1 ) . "s, stale=" . OLR_Crawler::count_stale() . "\n";
$t = microtime( true );
$a = OLR_Audit::run( array() );
echo 'audit: ' . round( microtime( true ) - $t, 2 ) . 's, nalaza=' . $a['ukupno_nalaza'] . ', peak MB=' . round( memory_get_peak_usage() / 1048576, 1 ) . "\n";
$t = microtime( true );
$l = OLR_Abilities::links( array() );
echo 'links: ' . round( microtime( true ) - $t, 2 ) . 's, stranica=' . count( $l['stranice'] ) . "\n";
$t = microtime( true );
$p = OLR_Abilities::page_detail( array( 'url' => '/test-clanak-150/', 'bez_teksta' => true ) );
echo 'detail: ' . round( microtime( true ) - $t, 2 ) . 's, ulazni=' . count( $p['ulazni_linkovi'] ) . ', json KB=' . round( strlen( wp_json_encode( $p ) ) / 1024 ) . "\n";
echo 'audit json KB=' . round( strlen( wp_json_encode( $a ) ) / 1024 ) . ', by code=' . wp_json_encode( $a['po_kodu'] ) . "\n";
// Admin stranica
wp_set_current_user( 1 );
ob_start(); OLR_Admin::render(); $html = ob_get_clean();
echo 'admin render: ' . strlen( $html ) . " bytes, contains table: " . ( false !== strpos( $html, 'Stanje crawla' ) ? 'yes' : 'no' ) . "\n";
wp_set_current_user( 0 );
ob_start(); OLR_Admin::render(); echo 'admin render as anon: ' . strlen( ob_get_clean() ) . " bytes\n";
