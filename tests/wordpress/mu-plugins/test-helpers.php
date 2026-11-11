<?php
// Samo za lokalni test: meta opis iz post mete i noindex za stranicu "privatno".
add_action( 'wp_head', function () {
	if ( is_singular() ) {
		$d = get_post_meta( get_queried_object_id(), 'test_desc', true );
		if ( $d ) { echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n"; }
	}
}, 1 );
add_filter( 'wp_robots', function ( $r ) {
	if ( is_page( 'privatno' ) ) { $r['noindex'] = true; }
	return $r;
} );
add_action( 'send_headers', function () { header( 'X-Test-DNC: ' . ( defined( 'DONOTCACHEPAGE' ) ? '1' : '0' ) ); } );
