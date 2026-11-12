<?php
/**
 * Test Radara na hostingu (dodatak 2.x) s lažnim Google, DataForSEO i Anthropic odgovorima.
 * Pokretanje: WPTEST_DIR=... php radar_test.php
 */
$_SERVER['HTTP_HOST']   = '127.0.0.1:8089';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'WPTEST_DIR' ) . '/site/wp-load.php';

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

update_option( 'olr_test_mocks', 1 );
delete_option( 'olr_test_dfs_tasks' );
delete_option( 'olr_test_dfs_seen' );
delete_option( 'olr_test_gsc_403' );
delete_option( 'olr_test_gsc_disabled' );
delete_option( 'olr_test_tokens' );
update_option( 'olr_test_dfs_run', 1 );
foreach ( array( 'olr_secrets', 'olr_status', 'olr_rank_pending', 'olr_dfs_cost', 'olr_ai_usage', 'olr_settings' ) as $o ) {
	delete_option( $o );
}
delete_transient( 'olr_gsc_token' );
global $wpdb;
$wpdb->query( 'DELETE FROM ' . OLR_Store::table() );

// 1) Pohrana i šifriranje.
ok( OLR_Store::table() && true === OLR_Store::set( 'issues', 'x1', array( 'status' => 'radim', 'at' => 1 ) ), 'pohrana: spremanje' );
ok( 'radim' === OLR_Store::data( 'issues', 'x1' )['status'], 'pohrana: čitanje' );
ok( is_wp_error( OLR_Store::set( 'issues', '../x', array() ) ), 'pohrana: odbija neispravno ime' );
OLR_Settings::set_secret( 'anthropic_key', 'sk-ant-test-1234' );
$raw = get_option( 'olr_secrets' );
ok( false === strpos( wp_json_encode( $raw ), 'sk-ant-test' ), 'tajne: u bazi nisu čitljive' );
ok( 'sk-ant-test-1234' === OLR_Settings::secret( 'anthropic_key' ), 'tajne: dešifriranje' );
ok( 'postavljen (…1234)' === OLR_Settings::describe_secret( 'anthropic_key' ), 'tajne: opis bez otkrivanja' );

// 2) Google: servisni račun s pravim RSA ključem, JWT provjeren u mocku.
$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $key, $pem );
update_option( 'olr_test_pub', openssl_pkey_get_details( $key )['key'] );
$sa = wp_json_encode( array( 'type' => 'service_account', 'client_email' => 'radar@ol-radar.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'ol-radar' ) );
ok( true === OLR_GSC::validate_json( $sa ), 'gsc: ispravan JSON prolazi' );
ok( true !== OLR_GSC::validate_json( '{"type":"authorized_user"}' ), 'gsc: odbija krivi JSON' );
OLR_Settings::set_secret( 'gsc_sa', $sa );
ok( 'radar@ol-radar.iam.gserviceaccount.com' === OLR_Settings::describe_secret( 'gsc_sa' ), 'gsc: prikazuje samo adresu' );
$t = OLR_GSC::test();
ok( is_string( $t ) && false !== strpos( $t, 'Veza radi' ), 'gsc: test veze (' . ( is_wp_error( $t ) ? $t->get_error_message() : $t ) . ')' );
$meta = OLR_GSC::collect();
ok( ! is_wp_error( $meta ) && $meta['redaka']['dnevno'] === 90, 'gsc: 90 dana po danu' );
$daily = OLR_Store::data( 'gsc', 'daily' );
ok( $daily && $daily['rows'][0][0] < $daily['rows'][89][0], 'gsc: dani sortirani' );
$qp = OLR_Store::data( 'gsc', 'qp' );
ok( 4 === count( $qp['rows'] ) && 6 === count( $qp['rows'][0] ), 'gsc: upit+stranica kompaktno' );
$wk = OLR_Store::data( 'gsc', 'wk' );
ok( $wk && $wk['range']['e'] === $daily['rows'][89][0] && 2 === count( $wk['cur'] ), 'gsc: tjedni prozor poravnat na zadnji dan' );
ok( 1 === (int) get_option( 'olr_test_tokens' ), 'gsc: token se kešira (1 izdavanje za 8 upita)' );
update_option( 'olr_test_gsc_403', 1 );
$e = OLR_GSC::test();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'Korisnici i dopuštenja' ), 'gsc: jasna poruka za 403' );
delete_option( 'olr_test_gsc_403' );
update_option( 'olr_test_gsc_disabled', 1 );
$e = OLR_GSC::test();
ok( is_wp_error( $e ) && 'olr_gsc_api_iskljucen' === $e->get_error_code(), 'gsc: prepoznaje neuključen Search Console API' );
delete_option( 'olr_test_gsc_disabled' );

// 3) DataForSEO: dva mjerenja, red čekanja, povijest i SERP-ovi.
OLR_Settings::update( array( 'rank_keywords' => array( 'cijena progresivnih naočala', 'progresivne naočale cijena', 'optika', 'stellest stakla', 'optika sesvete', 'Optika Sesvete' ) ) );
ok( 5 === count( OLR_DFS::keywords() ), 'dfs: duplikati riječi uklonjeni' );
ok( abs( OLR_DFS::estimate() - 5 * 4 * 0.0006 ) < 1e-9, 'dfs: procjena troška (5 × 4 stranice × 0,0006)' );
ok( is_wp_error( OLR_DFS::test() ), 'dfs: bez ključa nema poziva' );
OLR_Settings::set_secret( 'dfs_login', 'ivan@test.hr' );
OLR_Settings::set_secret( 'dfs_password', 'kriva' );
$e = OLR_DFS::test();
ok( is_wp_error( $e ) && 'olr_dfs_prijava' === $e->get_error_code(), 'dfs: kriva lozinka prepoznata' );
OLR_Settings::set_secret( 'dfs_password', 'tajna-api' );
ok( false !== strpos( (string) OLR_DFS::test(), '0.93' ), 'dfs: test prijave pokazuje stanje računa' );
$r = OLR_DFS::start_run( 'test' );
ok( ! is_wp_error( $r ) && 5 === $r['zadaci'], 'dfs: mjerenje poslano' );
ok( is_wp_error( OLR_DFS::start_run( 'test' ) ), 'dfs: ne pokreće drugo mjerenje dok je prvo u tijeku' );
$p1 = OLR_DFS::poll();
ok( ! empty( $p1['u_tijeku'] ) && 4 === $p1['gotovo'], 'dfs: 4 gotova, 1 još u redu' );
$p2 = OLR_DFS::poll();
ok( empty( $p2['u_tijeku'] ) && ! empty( $p2['gotovo'] ), 'dfs: drugi prolaz završava mjerenje' );
$latest = OLR_Store::data( 'rank', 'latest' );
$by     = array();
foreach ( $latest['rows'] as $row ) {
	$by[ $row['kw'] ] = $row;
}
ok( 1 === $by['cijena progresivnih naočala']['pos'] && null === $by['optika']['pos'] && true === $by['optika']['checked'], 'dfs: pozicije i „nije pronađen”' );
ok( 'https://www.optikaljiljana.hr/journal/' === $by['stellest stakla']['url'], 'dfs: URL koji rangira' );
ok( count( OLR_Store::list_docs( 'serp' ) ) === 5, 'dfs: SERP-ovi spremljeni za model konkurencije' );
$serp = OLR_Store::data( 'serp', OLR_DFS::kw_id( 'optika sesvete' ) );
ok( 'organic' === $serp['p'][0]['type'][0] && 12 === count( $serp['p'] ), 'dfs: SERP bez lokalnog paketa, samo organski' );
update_option( 'olr_test_dfs_run', 2 );
OLR_DFS::start_run( 'test' );
OLR_DFS::poll();
OLR_DFS::poll();
$latest = OLR_Store::data( 'rank', 'latest' );
foreach ( $latest['rows'] as $row ) {
	$by[ $row['kw'] ] = $row;
}
ok( 6 === $by['progresivne naočale cijena']['pos'] && 3 === $by['progresivne naočale cijena']['prev'], 'dfs: prethodna pozicija iz zadnjeg mjerenja' );
$hist = OLR_Store::data( 'rank', 'hist' );
ok( 2 === count( $hist['runs'] ) && array( 6, 3 ) === $hist['by'][ OLR_DFS::kw_id( 'progresivne naočale cijena' ) ] && array( 0, 0 ) === $hist['by'][ OLR_DFS::kw_id( 'optika' ) ], 'dfs: povijest (najnovije prvo, 0 = nije pronađen)' );
$cost = get_option( 'olr_dfs_cost' );
ok( abs( $cost[ gmdate( 'Y-m' ) ] - 2 * 5 * 0.0024 ) < 1e-9, 'dfs: trošak zbrojen po mjesecu' );

// 4) Claude (Anthropic) posrednik.
$res = OLR_AI::call( array( 'messages' => array( array( 'role' => 'user', 'content' => 'a' ), array( 'role' => 'user', 'content' => 'b' ) ), 'tools' => array( array( 'name' => 'pregled', 'description' => 'x' ) ) ) );
$sent = get_option( 'olr_test_ai_last' );
ok( ! is_wp_error( $res ) && 'tool_use' === $res['stop_reason'] && 1 === count( $sent['messages'] ), 'ai: uzastopne korisničke poruke spojene, alat zatražen' );
ok( 'claude-sonnet-5-5' === $sent['model'] && isset( $sent['tools'][0]['input_schema']['type'] ), 'ai: model iz postavki i shema alata' );
ok( false !== strpos( (string) OLR_AI::test(), 'spreman' ), 'ai: test ključa' );
$u = OLR_AI::usage();
ok( 2 === $u['mjeseci'][ gmdate( 'Y-m' ) ]['pozivi'], 'ai: potrošnja zabilježena' );
OLR_Settings::update( array( 'ai_daily_limit' => 2 ) );
$lim = OLR_AI::call( array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ) ) );
ok( is_wp_error( $lim ) && 'olr_ai_limit' === $lim->get_error_code(), 'ai: dnevni limit' );
OLR_Settings::update( array( 'ai_daily_limit' => 150 ) );
OLR_Settings::set_secret( 'anthropic_key', 'sk-ant-kriv' );
$bad = OLR_AI::call( array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ) ) );
ok( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'nije ispravan' ), 'ai: kriv ključ' );
OLR_Settings::set_secret( 'anthropic_key', 'sk-ant-test-1234' );

// 5) REST: prava, podaci, zaštićene zbirke.
wp_set_current_user( 0 );
$resp = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) );
ok( 401 === $resp->get_status(), 'rest: anonimni korisnik odbijen' );
$reader = get_users( array( 'role' => 'ol_radar_reader', 'number' => 1 ) );
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) );
	ok( 403 === $resp->get_status(), 'rest: korisnik samo za čitanje (MCP) nema pristup ploči' );
}
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
$resp = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) );
$d    = $resp->get_data();
ok( 200 === $resp->get_status() && 90 === count( $d['gsc']['daily']['rows'] ) && 5 === count( $d['rank']['latest']['rows'] ) && 5 === count( $d['serp'] ), 'rest: podaci (gsc, pozicije, serp)' );
ok( isset( $d['wp']['list'] ) && count( $d['wp']['list'] ) > 0 && isset( $d['wp']['audit']['nalazi'] ), 'rest: popis sadržaja i audit iz WP mosta' );
ok( true === $d['stanje']['kljucevi']['gsc'] && false === strpos( wp_json_encode( $d ), 'sk-ant-test' ) && false === strpos( wp_json_encode( $d ), 'PRIVATE KEY' ), 'rest: ključevi se ne vraćaju' );
$put = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/issues/abc' );
$put->set_header( 'content-type', 'application/json' );
$put->set_body( wp_json_encode( array( 'data' => array( 'status' => 'gotovo' ) ) ) );
ok( 200 === rest_do_request( $put )->get_status() && 'gotovo' === OLR_Store::data( 'issues', 'abc' )['status'], 'rest: zapis u dopuštenu zbirku' );
$put2 = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/gsc/daily' );
$put2->set_header( 'content-type', 'application/json' );
$put2->set_body( wp_json_encode( array( 'data' => array() ) ) );
ok( 403 === rest_do_request( $put2 )->get_status(), 'rest: podaci izvora zaštićeni od pisanja' );
$g = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/doc/issues/nema' ) );
ok( 200 === $g->get_status() && false === $g->get_data()['exists'], 'rest: nepostojeći dokument (exists: false)' );
$l   = new WP_REST_Request( 'GET', '/ol-radar/v1/docs/issues' );
$l->set_param( 'orderBy', 'at' );
ok( 2 === count( rest_do_request( $l )->get_data()['items'] ), 'rest: popis zbirke' );
$ai = new WP_REST_Request( 'POST', '/ol-radar/v1/ai' );
$ai->set_header( 'content-type', 'application/json' );
$ai->set_body( wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Vrati JSON' ) ) ) ) );
$air = rest_do_request( $ai );
ok( 200 === $air->get_status() && 'end_turn' === $air->get_data()['stop_reason'], 'rest: AI posrednik' );

// 6) Satni cron ne troši novac bez uključenog tjednog mjerenja.
$before = count( (array) get_option( 'olr_test_dfs_tasks' ) );
do_action( 'olr_radar_tick' );
ok( count( (array) get_option( 'olr_test_dfs_tasks' ) ) === $before, 'cron: bez tjednog mjerenja nema novih plaćenih zadataka' );
ok( (bool) wp_next_scheduled( 'olr_radar_tick' ), 'cron: satni raspored postoji (i nakon nadogradnje bez aktivacije)' );

// 7) Mala arhiva (volumeni) kao iz uvoza, za model konkurencije u nadzornoj ploči.
OLR_Store::set( 'arch_kw', 'hr-test', array( 'seed' => 'test', 'country' => 'hr', 'at' => time() * 1000, 'rows' => array(
	array( 'k' => 'optika sesvete', 'v' => 300 ), array( 'k' => 'cijena progresivnih naočala', 'v' => 150 ),
	array( 'k' => 'progresivne naočale cijena', 'v' => 250 ), array( 'k' => 'stellest stakla', 'v' => 90 ), array( 'k' => 'optika', 'v' => 2400 ),
) ) );
ok( 1 === count( OLR_Store::list_docs( 'arch_kw' ) ), 'arhiva: uvezeni volumeni' );
$imp = OLR_Radar_Admin::import_json( wp_json_encode( array( 'zbirke' => array( 'plan' => array( array( 'id' => 'p1', 'data' => array( 'naslov' => 'Test' ) ) ), 'gsc' => array( array( 'id' => 'daily', 'data' => array() ) ) ) ) ) );
ok( 1 === $imp['uvezeno'] && 1 === $imp['preskoceno'] && 'Test' === OLR_Store::data( 'plan', 'p1' )['naslov'] && OLR_Store::data( 'gsc', 'daily' )['rows'], 'uvoz: dopuštene zbirke, podaci izvora se ne mogu pregaziti' );
ok( is_wp_error( OLR_Radar_Admin::import_json( '{"x":1}' ) ), 'uvoz: odbija krivu datoteku' );

// 8) Sposobnosti za Claudea (MCP), kao korisnik samo za čitanje.
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$ab = wp_get_ability( 'ol-radar/radar-gsc' );
	$g  = $ab ? $ab->execute( array( 'filtar' => 'progres', 'limit' => 5 ) ) : null;
	ok( is_array( $g ) && 2 === count( $g['upiti'] ) && 'progresivne naočale cijena' === $g['upiti'][0]['upit'] && '/progresivne-naocalne-lece/' === $g['upiti'][0]['stranica'], 'mcp: radar-gsc s filtrom' );
	$g7 = $ab->execute( array( 'razdoblje' => '7' ) );
	ok( is_array( $g7 ) && $g7['do'] === $wk['range']['e'], 'mcp: radar-gsc za 7 dana' );
	$rk = wp_get_ability( 'ol-radar/radar-pozicije' )->execute( array( 'konkurenti' => true ) );
	ok( is_array( $rk ) && 5 === count( $rk['rijeci'] ) && 10 === count( $rk['rijeci'][0]['top10'] ) && 2 === count( $rk['mjerenja'] ), 'mcp: radar-pozicije s konkurentima' );
	$sz = wp_get_ability( 'ol-radar/radar-sazetak' )->execute( array() );
	ok( is_array( $sz ) && array_key_exists( 'sazetak', $sz ) && isset( $sz['stanje']['kljucevi'] ) && false === strpos( wp_json_encode( $sz ), 'sk-ant' ), 'mcp: radar-sazetak bez ključeva' );
	wp_set_current_user( $admin[0]->ID );
}

delete_option( 'olr_test_mocks' );
echo $fails ? "\nNEUSPJELO: {$fails}\n" : "\nSVE PROŠLO\n";
exit( $fails ? 1 : 0 );
