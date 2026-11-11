<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
update_option( 'permalink_structure', '/%postname%/' );
switch_theme( 'twentytwentyfive' );
foreach ( array( 'mcp-adapter/mcp-adapter.php', 'ol-radar-most/ol-radar-most.php' ) as $p ) {
	$r = activate_plugin( $p );
	echo $p . ': ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'ok' ) . "\n";
}
flush_rewrite_rules();
$words = str_repeat( 'Progresivne leće omogućuju oštar vid na svim udaljenostima bez mijenjanja naočala. ', 40 );
$mk = function ( $title, $content, $type = 'page', $meta = array() ) {
	$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => $type ) );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	return $id;
};
$dio = $mk( 'Dioptrijske naočale', '<!-- wp:paragraph --><p>Kratki tekst. <a href="/progresivne-naocale/">progresivne naočale</a></p><!-- /wp:paragraph --><!-- wp:html --><img src="/wp-includes/images/w-logo-blue.png" alt="Logo"><!-- /wp:html -->' );
$prog = $mk( 'Progresivne naočale', '<!-- wp:paragraph --><p>' . $words . '</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Kako se priviknuti</h2><!-- /wp:heading --><!-- wp:html --><p><a href="/dioptrijske-naocale/">dioptrijske naočale</a> · <a href="/kontaktne-lece/">kontaktne leće</a> · <a href="/?p=' . 1 . '">stari link</a> · <a href="https://www.essilor.com/">Essilor</a></p><img src="/wp-includes/images/w-logo-blue.png" width="80" height="80" alt="Varilux"><img src="/wp-includes/images/wpicons.png" width="10" height="10"><!-- /wp:html -->', 'page', array( 'test_desc' => 'Progresivne naočale u Sesvetama: pregled vida 60 minuta, Varilux leće i besplatna prilagodba nakon kupnje.', 'rank_math_focus_keyword' => 'progresivne naočale,varilux', 'rank_math_title' => '%title% %sep% %sitename%' ) );
$sir = $mk( 'Siroče stranica', '<!-- wp:html --><h1>Drugi H1 u sadržaju</h1><p>Nitko ne linka ovamo.</p><!-- /wp:html -->', 'page', array( 'rank_math_focus_keyword' => 'kontaktne leće' ) );
$k1 = $mk( 'Kontakt', '<p>Kontakt jedan</p>' );
$k2 = $mk( 'Kontakt', '<p>Kontakt dva</p>', 'post' );
$pr = $mk( 'Privatno', '<p>Ova stranica ima noindex.</p>' );
$st = $mk( 'Stellest leće za djecu', '<!-- wp:paragraph --><p>Stellest leće usporavaju razvoj kratkovidnosti kod djece. <a href="/siroce-stranica/" rel="nofollow">siroče</a></p><!-- /wp:paragraph -->', 'post', array( 'rank_math_focus_keyword' => 'stellest leće', 'rank_math_description' => 'Stellest leće za djecu u Sesvetama.' ) );
echo "ids: dio=$dio prog=$prog sir=$sir k1=$k1 k2=$k2 pr=$pr st=$st\n";
$uid = wp_insert_user( array( 'user_login' => 'claude-radar', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'radar@example.com', 'role' => 'ol_radar_reader' ) );
list( $pass ) = WP_Application_Passwords::create_new_application_password( $uid, array( 'name' => 'test' ) );
file_put_contents( getenv( 'WPTEST_DIR' ) . '/reader.pass', 'claude-radar:' . $pass );
list( $apass ) = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'test' ) );
file_put_contents( getenv( 'WPTEST_DIR' ) . '/admin.pass', 'admin:' . $apass );
$u = get_user_by( 'id', $uid );
echo 'reader caps: ' . implode( ',', array_keys( array_filter( $u->allcaps ) ) ) . "\n";
echo 'admin has cap: ' . ( user_can( 1, 'ol_radar_read' ) ? 'yes' : 'no' ) . "\n";
echo 'table: ' . $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_ol_radar_pages" ) . " rows\n";
echo 'cron: ' . ( wp_next_scheduled( 'olr_crawl_tick' ) ? 'scheduled' : 'NOT' ) . "\n";
