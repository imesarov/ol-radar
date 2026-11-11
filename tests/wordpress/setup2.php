<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';
$a = wp_insert_post( array( 'post_title' => 'Usluge', 'post_name' => 'usluge-a', 'post_content' => '<p>A</p>', 'post_status' => 'publish', 'post_type' => 'page' ) );
$b = wp_insert_post( array( 'post_title' => 'Usluge', 'post_name' => 'usluge-b', 'post_content' => '<p>B</p>', 'post_status' => 'publish', 'post_type' => 'page' ) );
$sub = wp_insert_user( array( 'user_login' => 'pretplatnik', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 's@example.com', 'role' => 'subscriber' ) );
list( $p ) = WP_Application_Passwords::create_new_application_password( $sub, array( 'name' => 't' ) );
file_put_contents( getenv( 'WPTEST_DIR' ) . '/sub.pass', 'pretplatnik:' . $p );
echo "dupe pages $a $b; valid token: " . OLR_Crawler::make_token() . "\n";
