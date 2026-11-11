<?php
$root = getenv( 'WPTEST_DIR' ) . '/site';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && file_exists( $root . $path ) && ! is_dir( $root . $path ) ) {
	if ( substr( $path, -4 ) === '.php' ) { $_SERVER['SCRIPT_NAME'] = $path; require $root . $path; return true; }
	return false;
}
if ( is_dir( $root . $path ) && file_exists( $root . rtrim( $path, '/' ) . '/index.php' ) && $path !== '/' ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php'; require $root . rtrim( $path, '/' ) . '/index.php'; return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
