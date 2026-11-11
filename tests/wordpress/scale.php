<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
$t = microtime( true );
$ids = array();
for ( $i = 1; $i <= 300; $i++ ) {
	$prev = $i > 1 ? '<a href="/test-clanak-' . ( $i - 1 ) . '/">prethodni članak</a>' : '';
	$img  = '<img src="/wp-includes/images/w-logo-blue.png" width="80" height="80" alt="Slika ' . $i . '">';
	$ids[] = wp_insert_post( array( 'post_title' => 'Test članak ' . $i, 'post_name' => 'test-clanak-' . $i, 'post_status' => 'publish', 'post_type' => 'post',
		'post_content' => '<!-- wp:html --><h2>Podnaslov</h2><p>' . str_repeat( 'Tekst o naočalama i lećama za bolji vid. ', 60 ) . '</p>' . $prev . $img . '<!-- /wp:html -->' ) );
}
echo 'inserted ' . count( $ids ) . ' in ' . round( microtime( true ) - $t, 1 ) . "s\n";
