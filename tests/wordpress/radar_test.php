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
foreach ( array( 'olr_test_net_fail', 'olr_test_gsc_extra', 'olr_test_ai_bad_json', 'olr_net_ok', 'olr_keys_seen', 'olr_upgrade_warning', 'olr_prijedlozi_run' ) as $o ) {
	delete_option( $o );
}
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
// AI pregled (AI Overview) iz istog rezultata: ima li ga, citira li nas, koje domene citira.
$aA = $by['cijena progresivnih naočala']['aio'];
$aB = $by['optika sesvete']['aio'];
$aC = $by['stellest stakla']['aio'];
ok( $aA && true === $aA['mi'] && true === $aA['ucitan'] && 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' === $aA['nasa_adresa'] && array( 'konkurent1.hr', 'hr.wikipedia.org', 'optikaljiljana.hr' ) === $aA['izvori'], 'ai pregled: citira nas (izvori iz pregleda i iz odlomaka, bez duplih domena)' );
ok( $aB && false === $aB['mi'] && array( 'konkurent2.hr', 'konkurent4.hr' ) === $aB['izvori'] && null === $by['optika']['aio'], 'ai pregled: postoji, ali nas ne citira; riječ bez pregleda ima null' );
ok( $aC && true === $aC['ima'] && false === $aC['ucitan'] && array() === $aC['izvori'], 'ai pregled: Google ga učitava naknadno, pa bez doplate znamo samo da postoji' );
$e0 = OLR_DFS::estimate();
OLR_Settings::update( array( 'rank_aio_async' => true ) );
ok( abs( OLR_DFS::estimate() - $e0 - 5 * OLR_DFS::PRICE_AIO ) < 0.00001, 'ai pregled: naknadno učitavanje ulazi u procjenu troška (0,0006 $ po riječi)' );
update_option( 'olr_test_dfs_run', 2 );
OLR_DFS::start_run( 'test' );
OLR_DFS::poll();
OLR_DFS::poll();
$latest = OLR_Store::data( 'rank', 'latest' );
foreach ( $latest['rows'] as $row ) {
	$by[ $row['kw'] ] = $row;
}
ok( 6 === $by['progresivne naočale cijena']['pos'] && 3 === $by['progresivne naočale cijena']['prev'], 'dfs: prethodna pozicija iz zadnjeg mjerenja' );
// AI pregled nakon drugog mjerenja: uz uključenu opciju stiže i pregled koji se učitava naknadno.
$sentt = get_option( 'olr_test_dfs_tasks' );
$lastt = end( $sentt );
OLR_Settings::update( array( 'rank_aio_async' => false ) );
ok( true === $lastt['load_async_ai_overview'] && true === $by['stellest stakla']['aio']['ucitan'] && array( 'essilor.com' ) === $by['stellest stakla']['aio']['izvori'], 'ai pregled: s uključenom opcijom stiže i pregled koji se učitava naknadno' );
$hh = OLR_Store::data( 'rank', 'hist' );
ok( array( 2, 2 ) === array_slice( $hh['aio'][ OLR_DFS::kw_id( 'cijena progresivnih naočala' ) ], 0, 2 ) && 1 === $hh['aio'][ OLR_DFS::kw_id( 'optika sesvete' ) ][0] && 0 === $hh['aio'][ OLR_DFS::kw_id( 'optika' ) ][0] && count( $hh['aio'][ OLR_DFS::kw_id( 'optika' ) ] ) === count( $hh['runs'] ), 'ai pregled: povijest po mjerenju (2 = citira nas, 1 = ima, 0 = nema)' );
// Kretanje konkurenata između dva mjerenja (prvo: zadani raspored; od drugog: nova domena na 10. mjestu).
$kk = OLR_DFS::competitors();
$kd = array();
foreach ( $kk['domene'] as $d ) {
	$kd[ $d['domena'] ] = $d;
}
$ch = array();
foreach ( $kk['promjene'] as $c ) {
	$ch[ $c['rijec'] ] = $c;
}
ok( count( $kk['mjerenja'] ) >= 2 && 5 === $kk['rijeci'] && true === $kd['optikaljiljana.hr']['nasa'] && 4 === $kd['optikaljiljana.hr']['top10'] && 2 === $kd['optikaljiljana.hr']['top3'] && count( $kk['mjerenja'] ) === count( $kd['optikaljiljana.hr']['trend'] ), 'konkurenti: naša domena u top 3 za 2 i u top 10 za 4 od 5 riječi, s trendom po mjerenjima' );
ok( 4 === $kd['konkurent1.hr']['top10'] && 4 === $kd['konkurent1.hr']['top3'] && $kd['konkurent1.hr']['udio'] > $kd['optikaljiljana.hr']['udio'] && abs( array_sum( wp_list_pluck( $kk['domene'], 'udio' ) ) - 100 ) < 1.5, 'konkurenti: udio vidljivosti po domeni (zbroj oko 100 %)' );
ok( isset( $kd['novi-igrac.hr'] ) && 0 === $kd['novi-igrac.hr']['prije']['top10'] && 5 === $kd['novi-igrac.hr']['top10'] && $kd['novi-igrac.hr']['promjena'] > 0 && 0 === $kd['konkurent10.hr']['top10'] && 5 === $kd['konkurent10.hr']['prije']['top10'] && $kd['konkurent10.hr']['promjena'] < 0, 'konkurenti: nova domena ušla u top 10, stara ispala' );
$c1 = $ch['progresivne naočale cijena'];
ok( 6 === $c1['nas'] && 3 === $c1['nas_prije'] && array( 'konkurent3.hr', 'konkurent4.hr', 'konkurent5.hr' ) === wp_list_pluck( $c1['pretekli'], 'domena' ) && array( 'konkurent3.hr', 'novi-igrac.hr' ) === wp_list_pluck( $c1['usli'], 'domena' ) && array( 'konkurent6.hr', 'konkurent10.hr' ) === wp_list_pluck( $c1['ispali'], 'domena' ), 'konkurenti: po riječi tko je ušao, tko je ispao i tko nas je pretekao' );
ok( array() === $ch['optika sesvete']['pretekli'] && $kd['optikaljiljana.hr']['promjena'] < 0, 'konkurenti: bez pomaka nema „pretekli”; naš udio je pao' );
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

// 9) Veza prema Googleu: rezervni način (IPv4 + HTTP/1.1) i rezervna adresa.
delete_transient( 'olr_gsc_token' );
delete_option( 'olr_net_ok' );
update_option( 'olr_test_net_fail', 'auto' );
$t = OLR_GSC::test();
ok( is_string( $t ) && false !== strpos( $t, 'Veza radi' ) && false !== strpos( $t, 'IPv4 + HTTP/1.1' ), 'veza: zadani način ne odgovara, IPv4 + HTTP/1.1 prolazi (' . ( is_wp_error( $t ) ? $t->get_error_message() : $t ) . ')' );
ok( 'v4h1' === OLR_Net::preferred()['nacin'], 'veza: kombinacija koja radi je zapamćena' );
delete_option( 'olr_test_log' ); // dnevnik mocka čuva zadnjih 200 zahtjeva, pa se broji od nule
OLR_GSC::test();
ok( 1 === count( (array) get_option( 'olr_test_log' ) ), 'veza: sljedeći poziv ide odmah zapamćenim načinom (1 zahtjev, bez čekanja)' );
delete_option( 'olr_net_ok' );
update_option( 'olr_test_net_fail', 'www' );
$t = OLR_GSC::test();
ok( is_string( $t ) && false !== strpos( $t, 'searchconsole.googleapis.com' ) && 'sc' === OLR_Net::preferred()['host'], 'veza: prva adresa ne odgovara, rezervna Googleova adresa prolazi' );
delete_option( 'olr_net_ok' );
update_option( 'olr_test_net_fail', 'sve' );
$e  = OLR_GSC::collect();
$st = OLR_Settings::status();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'nije odgovorio' ) && false !== strpos( $e->get_error_message(), '3) searchconsole.googleapis.com' ), 'veza: nakon tri pokušaja jasna poruka s popisom pokušaja' );
ok( false === $st['gsc']['ok'] && 'dnevno' === $st['gsc']['dio'] && 3 === count( $st['gsc']['dijagnostika'] ) && false === strpos( wp_json_encode( $st ), 'tok-' ), 'veza: dijagnostika u stanju izvora (bez tokena)' );
ok( 90 === count( OLR_Store::data( 'gsc', 'daily' )['rows'] ), 'veza: neuspjelo preuzimanje ne briše postojeće podatke' );
$m        = OLR_Store::data( 'gsc', 'meta' );
$m['at'] -= 13 * HOUR_IN_SECONDS * 1000; // podaci stariji od 12 sati: cron bi inače preuzimao
OLR_Store::set( 'gsc', 'meta', $m );
delete_option( 'olr_test_log' );
do_action( 'olr_radar_tick' );
ok( ! preg_grep( '#searchAnalytics#', (array) get_option( 'olr_test_log' ) ), 'cron: nakon neuspjeha ne pokušava ponovno prije 3 sata' );
$st2              = OLR_Settings::status();
$st2['gsc']['at'] = time() - 4 * HOUR_IN_SECONDS;
update_option( 'olr_status', $st2 );
delete_option( 'olr_test_log' );
do_action( 'olr_radar_tick' );
ok( (bool) preg_grep( '#searchAnalytics#', (array) get_option( 'olr_test_log' ) ), 'cron: nakon 3 sata pokušava ponovno' );
delete_option( 'olr_test_net_fail' );
delete_option( 'olr_net_ok' );
ok( ! is_wp_error( OLR_GSC::collect() ) && true === OLR_Settings::status()['gsc']['ok'], 'veza: kad Google odgovara, preuzimanje opet radi' );

// 10) Ključevi i nadogradnja: provjera nakon promjene verzije, nečitljiv zapis se ne briše.
$names = OLR_Settings::readable_names();
sort( $names );
ok( array( 'anthropic_key', 'dfs_login', 'dfs_password', 'gsc_sa' ) === $names, 'ključevi: sva četiri čitljiva' );
update_option( 'olr_version', '2.0.2' );
ok( null === OLR_Settings::check_upgrade() && OLR_VERSION === get_option( 'olr_version' ), 'nadogradnja: bez gubitka nema upozorenja' );
$all   = get_option( 'olr_secrets' );
$saved = $all['dfs_password'];
unset( $all['dfs_password'] );
update_option( 'olr_secrets', $all );
update_option( 'olr_version', '2.0.2' );
$w = OLR_Settings::check_upgrade();
ok( $w && array( 'dfs_password' ) === $w['nedostaju'] && '2.0.2' === $w['s'], 'nadogradnja: nestali ključ daje upozorenje' );
ok( array( 'dfs_password' ) === OLR_REST::state_array()['nadogradnja']['nedostaju'], 'nadogradnja: upozorenje vidi i Claude (stanje)' );
$all['dfs_password'] = 'AAAA' . $saved;
update_option( 'olr_secrets', $all );
ok( 'necitljiv' === OLR_Settings::stored_state( 'dfs_password' ) && null === OLR_Settings::secret( 'dfs_password' ) && false !== strpos( OLR_Settings::describe_secret( 'dfs_password' ), 'ne može pročitati' ), 'ključevi: nečitljiv zapis je jasno označen' );
ok( isset( get_option( 'olr_secrets' )['dfs_password'] ), 'ključevi: nečitljiv zapis se ne briše sam' );
OLR_Settings::set_secret( 'dfs_password', 'tajna-api' );
ok( null === OLR_Settings::upgrade_warning() && 'tajna-api' === OLR_Settings::secret( 'dfs_password' ), 'nadogradnja: ponovni unos gasi upozorenje' );

// 11) Dnevna analiza i prijedlozi izmjena.
$wipe = static function () use ( $wpdb ) {
	$wpdb->query( "DELETE FROM " . OLR_Store::table() . " WHERE coll IN ('prijedlozi','prijedlozi_stanje')" );
	delete_option( 'olr_prijedlozi_run' );
	delete_transient( 'olr_prijedlozi_lock' );
};
$wipe();
foreach ( array( 'olr_test_ai_bad_json', 'olr_test_ai_tool_fail' ) as $o ) {
	delete_option( $o );
}
wp_clear_scheduled_hook( OLR_Prijedlozi::EVENT );
// Testne stranice su upravo nastale; „stara” stranica za pravilo o naslovu i opisu je /progresivne-naocale/.
$old_page = get_page_by_path( 'progresivne-naocale' );
$wpdb->update( $wpdb->posts, array( 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'ID' => $old_page->ID ) );
clean_post_cache( $old_page->ID );
update_option( 'olr_test_gsc_extra', 1 );
OLR_GSC::collect();
ok( (bool) wp_next_scheduled( OLR_Prijedlozi::EVENT ), 'prijedlozi: nakon novih Google podataka analiza je zakazana u pozadini' );
$kontakt = null;
$upitni  = 0;
foreach ( OLR_Store::data( 'gsc', 'qp' )['rows'] as $r ) {
	$upitni += false !== strpos( $r[1], '?' ) ? 1 : 0;
	if ( 'kontakt optika test' === $r[0] ) {
		$kontakt = $r;
	}
}
ok( 0 === $upitni && $kontakt && 300 === $kontakt[3] && 3 === $kontakt[2] && abs( $kontakt[5] - 7.03 ) < 0.02, 'gsc: adrese s parametrima (utm, rwg_token) spojene su s pravom stranicom' );
ok( OLR_Analiza::is_brand( 'ljiljan' ) && OLR_Analiza::is_brand( 'Optika Ljiljana Sesvete' ) && ! OLR_Analiza::is_brand( 'optika sesvete' ), 'analiza: brend se prepoznaje po korijenu' );
ok( OLR_Analiza::relevant( 'progresivnih naocala', 'https://x/progresivne-naocale/' ) && ! OLR_Analiza::relevant( 'optika anda', 'https://x/' ) && ! OLR_Analiza::relevant( 'sunčane naočale', 'https://x/kontakt/' ), 'analiza: upit mora biti o onome o čemu je stranica (padeži i dijakritika se poklapaju)' );
$an = OLR_Analiza::run();
$by = array();
foreach ( $an['kandidati'] as $c ) {
	$by[ $c['kljuc'] ] = $c;
}
$ctr = isset( $by['ctr|/progresivne-naocale/'] ) ? $by['ctr|/progresivne-naocale/'] : null;
ok( $ctr && 'progresivne naočale' === $ctr['dokazi']['upiti'][0]['upit'] && 400 === $ctr['dokazi']['upiti'][0]['prikazivanja'] && $ctr['ucinak'] > 20 && 'Progresivne naočale – Optika Test' === $ctr['stranica']['naslov'] && $ctr['stranica']['izmijenjeno_prije_dana'] >= 39, 'analiza: nizak CTR na visokoj poziciji, s činjenicama o stranici' );
ok( ! isset( $by['ctr|/dioptrijske-naocale/'] ) && false !== strpos( implode( ' ', $an['napomene'] ), '/dioptrijske-naocale/' ), 'analiza: svježe mijenjana stranica ne dobiva prijedlog naslova (uz napomenu)' );
$gap = isset( $by['prilika|/kontakt/'] ) ? $by['prilika|/kontakt/'] : null;
ok( $gap && 7.0 === $gap['dokazi']['upiti'][0]['pozicija'] && 300 === $gap['dokazi']['upiti'][0]['prikazivanja'] && count( $gap['izvori_linka'] ) >= 2, 'analiza: upit blizu vrha, s izvorima internih linkova' );
ok( isset( $by['kanibal|naočale test'] ) && '/dioptrijske-naocale/' === $by['kanibal|naočale test']['dokazi']['glavna_stranica'], 'analiza: kanibalizacija s glavnom stranicom' );
ok( isset( $by['pad|progresivne naočale'] ) && 9 === $by['pad|progresivne naočale']['dokazi']['prosli_tjedan']['klikovi'] && 2 === $by['pad|progresivne naočale']['dokazi']['ovaj_tjedan']['klikovi'], 'analiza: tjedni pad (izgubljeni klikovi)' );
ok( isset( $by['novo|mrena na oku simptomi'] ) && '/' === $by['novo|mrena na oku simptomi']['dokazi']['sada_rangira'], 'analiza: upit bez prave stranice' );
ok( isset( $by[ 'pozicije|pad|progresivne naočale cijena' ] ) && isset( $by['pozicije|url|stellest stakla'] ), 'analiza: pad ciljane riječi i kriva stranica u rezultatu' );
$tudje = false;
$noidx = false;
$teh   = 0;
foreach ( $an['kandidati'] as $c ) {
	$j     = wp_json_encode( $c['dokazi'], JSON_UNESCAPED_UNICODE );
	$tudje = $tudje || false !== strpos( $j, 'ljiljan' ) || false !== strpos( $j, 'optika anda' );
	$noidx = $noidx || '/privatno/' === $c['put'];
	$teh  += 'tehnicki' === $c['vrsta'] ? 1 : 0;
}
ok( ! $tudje && ! $noidx, 'analiza: brend, tuđe optike i noindex stranice ne ulaze' );
ok( isset( $by['tehnicki|bez_opisa|*'] ) && $by['tehnicki|bez_opisa|*']['dokazi']['broj'] > 100 && $teh < 20, 'analiza: isti tehnički nalaz na puno stranica je jedan prijedlog' );
OLR_Settings::update( array( 'zanemari' => 'mrena, nešto' ) );
$an2 = OLR_Analiza::run();
ok( ! in_array( 'novo|mrena na oku simptomi', wp_list_pluck( $an2['kandidati'], 'kljuc' ), true ), 'analiza: popis „zanemari” izbacuje upit' );
OLR_Settings::update( array( 'zanemari' => '' ) );

// Claudeov odgovor je prekinut: prijedlozi svejedno nastaju, bez teksta, i čekaju novi pokušaj.
update_option( 'olr_test_ai_bad_json', 1 );
$r = OLR_Prijedlozi::run( 'auto' );
$a = OLR_Prijedlozi::all();
ok( ! is_wp_error( $r ) && 5 === $r['novih'] && false === $r['ai_ok'] && 0 === $r['tekstova'] && 5 === count( $a ) && false === $a[0]['ai'] && 1 === $a[0]['ai_pokusaja'] && count( $a[0]['koraci'] ) >= 2 && '' !== $a[0]['zasto'], 'prijedlozi: prekinut odgovor Claudea ne sprječava prijedloge (koraci iz podataka)' );
ok( false === OLR_Settings::status()['prijedlozi']['ok'] && 5 === OLR_Prijedlozi::summary()['ceka_tekst'], 'prijedlozi: neuspjeh je vidljiv u stanju, 5 čeka tekst' );
$pages = array_filter( wp_list_pluck( array_filter( $a, static function ( $p ) { return 'tehnicki' !== $p['vrsta']; } ), 'put' ) );
ok( count( $pages ) === count( array_unique( $pages ) ) && ! in_array( 'pad|progresivne naočale', wp_list_pluck( $a, 'kljuc' ), true ), 'prijedlozi: najviše jedan po stranici u jednom danu' );
ok( false === OLR_Prijedlozi::fill_due(), 'prijedlozi: novi pokušaj pisanja teksta ne ide odmah (jednom na sat)' );
$run             = get_option( 'olr_prijedlozi_run' );
$run['tekst_at'] = time() - HOUR_IN_SECONDS;
update_option( 'olr_prijedlozi_run', $run );
ok( true === OLR_Prijedlozi::fill_due(), 'prijedlozi: nakon sat vremena tekst je na redu' );
// API odbije prisilni poziv alata: rezervni način (JSON u tekstu, s ogradom koda) mora proći.
delete_option( 'olr_test_ai_bad_json' );
update_option( 'olr_test_ai_tool_fail', 1 );
do_action( 'olr_radar_tick' );
$a2  = OLR_Prijedlozi::all();
$run = get_option( 'olr_prijedlozi_run' );
ok( 5 === count( $a2 ) && 5 === count( array_filter( wp_list_pluck( $a2, 'ai' ) ) ) && 5 === $run['tekstova'] && true === $run['ai_ok'] && 5 === $run['novih'] && 0 === strpos( $a2[0]['naslov'], 'AI (JSON)' ), 'prijedlozi: satni prolaz dopisuje tekst postojećim prijedlozima (rezervni JSON način)' );
ok( true === OLR_Settings::status()['prijedlozi']['ok'] && 0 === OLR_Prijedlozi::summary()['ceka_tekst'], 'prijedlozi: stanje se oporavi kad je tekst napisan' );
delete_option( 'olr_test_ai_tool_fail' );
// Najviše tri pokušaja po prijedlogu.
$wipe();
update_option( 'olr_test_ai_bad_json', 1 );
OLR_Prijedlozi::run( 'auto' );
OLR_Prijedlozi::run( 'tekst' );
OLR_Prijedlozi::run( 'tekst' );
$a3 = OLR_Prijedlozi::all();
ok( 3 === $a3[0]['ai_pokusaja'] && 0 === OLR_Prijedlozi::summary()['ceka_tekst'] && 5 === OLR_Prijedlozi::summary()['otvorenih'], 'prijedlozi: nakon tri neuspjela pokušaja tekst se više ne traži, prijedlog ostaje' );
delete_option( 'olr_test_ai_bad_json' );
// Nalaz koji više ne postoji: novi prijedlog bez teksta istječe i više se ne broji među otvorene.
$had = in_array( 'prilika|/kontakt/', wp_list_pluck( $a3, 'kljuc' ), true );
OLR_Settings::update( array( 'zanemari' => 'kontakt optika' ) );
$rx   = OLR_Prijedlozi::run( 'tekst' );
$gone = array_values( array_filter( OLR_Prijedlozi::all(), static function ( $p ) { return 'prilika|/kontakt/' === $p['kljuc']; } ) );
ok( $had && $gone && true === $gone[0]['isteklo'] && ! is_wp_error( $rx ) && 1 === $rx['isteklo'] && 4 === OLR_Prijedlozi::summary()['otvorenih'], 'prijedlozi: prijedlog bez teksta čiji nalaz više ne vrijedi označen je kao istekao' );
OLR_Settings::update( array( 'zanemari' => '' ) );
$wipe();

// Ručno pokretanje ne drži preglednik: što ne stigne u kratkom okviru, dopisuje se u pozadini.
$kratko = static function ( $b, $how ) {
	return 'rucno' === $how ? -1 : $b;
};
add_filter( 'olr_prijedlozi_budget', $kratko, 10, 2 );
$rb = OLR_Prijedlozi::run( 'rucno' );
remove_filter( 'olr_prijedlozi_budget', $kratko, 10 );
$sb = OLR_Prijedlozi::summary();
ok( ! is_wp_error( $rb ) && 5 === $rb['novih'] && 0 === $rb['tekstova'] && 5 === $rb['u_pozadini'] && true === $rb['ai_ok'] && (bool) wp_next_scheduled( OLR_Prijedlozi::EVENT_TEXT ) && true === $sb['pise_se'] && 5 === $sb['ceka_tekst'] && true === OLR_Settings::status()['prijedlozi']['ok'], 'prijedlozi: ručna analiza vraća prijedloge odmah, a tekst zakazuje u pozadini' );
wp_clear_scheduled_hook( OLR_Prijedlozi::EVENT_TEXT );
do_action( OLR_Prijedlozi::EVENT_TEXT );
$pr = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/prijedlozi' ) );
$pd = $pr->get_data();
ok( 200 === $pr->get_status() && 0 === $pd['ceka_tekst'] && false === $pd['pise_se'] && 5 === count( array_filter( wp_list_pluck( $pd['stavke'], 'ai' ) ) ) && 0 === $pd['zadnje']['u_pozadini'] && ! wp_next_scheduled( OLR_Prijedlozi::EVENT_TEXT ), 'prijedlozi: pozadinski prolaz dopiše tekst svima (lagani REST /prijedlozi za ploču)' );
$wipe();

// Uredan tijek.
$r   = OLR_Prijedlozi::run( 'auto' );
$a   = OLR_Prijedlozi::all();
$pid = array();
foreach ( $a as $p ) {
	$pid[ $p['kljuc'] ] = $p;
}
ok( ! is_wp_error( $r ) && 5 === $r['novih'] && true === $r['ai_ok'] && 5 === $r['tekstova'] && $r['kandidata'] > 5, 'prijedlozi: 5 novih (zadani dnevni maksimum), tekst napisao Claude' );
$types = array_count_values( wp_list_pluck( $a, 'vrsta' ) );
ok( max( $types ) <= 2, 'prijedlozi: najviše 2 po vrsti (' . wp_json_encode( $types ) . ')' );
$pc = isset( $pid['ctr|/progresivne-naocale/'] ) ? $pid['ctr|/progresivne-naocale/'] : null;
ok( $pc && 'seo_naslov' === $pc['izmjene'][0]['polje'] && 'Progresivne naočale – Optika Test' === $pc['izmjene'][0]['sada'] && ! empty( $pc['izmjene'][0]['upozorenje'] ) && empty( $pc['izmjene'][1]['upozorenje'] ), 'prijedlozi: „sada” iz crawla, predug naslov označen, opis u redu' );
ok( $pc && false !== strpos( $pc['zasto'], '400 prikazivanja' ) && 'Objašnjenje iz ulaza.' === $pc['objasnjenje'] && 0 === strpos( $pc['naslov'], 'AI: ' ), 'prijedlozi: „zašto” složen iz podataka, Claudeovo objašnjenje posebno' );
$pg = isset( $pid['prilika|/kontakt/'] ) ? $pid['prilika|/kontakt/'] : null;
$links = $pg ? array_values( array_filter( $pg['izmjene'], static function ( $c ) { return 'interni_link' === $c['polje']; } ) ) : array();
ok( $pg && 1 === count( $links ) && 0 === strpos( $links[0]['gdje'], '/' ) && false === strpos( wp_json_encode( $pg ), 'nepostojeca' ), 'prijedlozi: link s izvora kojeg nema u popisu je odbačen' );
$clean = true;
foreach ( $a as $p ) {
	foreach ( $p['izmjene'] as $c ) {
		$clean = $clean && false === strpos( $c['novo'], '<' ) && in_array( $c['polje'], OLR_Prijedlozi::POLJA, true );
	}
}
ok( $clean, 'prijedlozi: HTML uklonjen, nepoznata polja odbačena' );
$sent = get_option( 'olr_test_ai_last' );
ok( false === strpos( wp_json_encode( $sent ), 'sk-ant' ) && false === strpos( wp_json_encode( $sent ), 'PRIVATE KEY' ) && false !== strpos( $sent['system'], 'Ne izmišljaj' ) && 'spremi_prijedlog' === $sent['tool_choice']['name'] && $sent['max_tokens'] <= 3000 && ! isset( json_decode( $sent['messages'][0]['content'], true )['nalazi'] ), 'prijedlozi: jedan nalaz po pozivu, prisilni poziv alata, Claudeu idu samo podaci o stranici i upitima' );
ok( false === OLR_Prijedlozi::due() && false === OLR_Prijedlozi::fill_due(), 'prijedlozi: za iste Google podatke automatika ne pokreće ponovno' );

// Stanja: odbijeni se ne vraća, otvoreni se ne dupliciraju.
$put = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/prijedlozi_stanje/' . $pc['id'] );
$put->set_header( 'content-type', 'application/json' );
$put->set_body( wp_json_encode( array( 'data' => array( 'stanje' => 'odbijeno', 'at' => time() * 1000 ) ) ) );
ok( 200 === rest_do_request( $put )->get_status(), 'prijedlozi: stanje se sprema iz ploče' );
$put = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/prijedlozi/' . $pc['id'] );
$put->set_header( 'content-type', 'application/json' );
$put->set_body( wp_json_encode( array( 'data' => array( 'x' => 1 ) ) ) );
ok( 403 === rest_do_request( $put )->get_status(), 'prijedlozi: sam prijedlog ploča ne može mijenjati' );
$r2   = OLR_Prijedlozi::run( 'rucno' );
$a2   = OLR_Prijedlozi::all();
$keys = wp_list_pluck( $a2, 'kljuc' );
ok( ! is_wp_error( $r2 ) && count( $keys ) === count( array_unique( $keys ) ) && count( $a2 ) === 5 + $r2['novih'], 'prijedlozi: drugo pokretanje ne ponavlja postojeće nalaze' );
$s = OLR_Prijedlozi::summary( 'otvoreni', 50 );
ok( $s['otvorenih'] === count( $a2 ) - 1 && 1 === count( OLR_Prijedlozi::summary( 'odbijeno', 50 )['stavke'] ), 'prijedlozi: filtri po stanju' );
$run = get_option( 'olr_prijedlozi_run' );
$run['rucno'] = array( gmdate( 'Y-m-d' ) => OLR_Prijedlozi::MANUAL_MAX );
update_option( 'olr_prijedlozi_run', $run );
$lim = OLR_Prijedlozi::run( 'rucno' );
ok( is_wp_error( $lim ) && 'olr_prijedlozi_limit' === $lim->get_error_code(), 'prijedlozi: dnevni limit ručnih pokretanja' );
unset( $run['rucno'] );
update_option( 'olr_prijedlozi_run', $run );
$d = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) )->get_data();
ok( $d['prijedlozi']['otvorenih'] === $s['otvorenih'] && isset( $d['prijedlozi']['stavke'][0]['dokazi'] ), 'prijedlozi: u podacima nadzorne ploče' );
$mj = new WP_REST_Request( 'POST', '/ol-radar/v1/osvjezi' );
$mj->set_param( 'dio', 'mjerenje' );
ok( 400 === rest_do_request( $mj )->get_status() && ! get_option( OLR_DFS::PENDING ), 'pozicije: plaćeno mjerenje se iz ploče ne pokreće bez potvrde' );
// S potvrdom zahtjev stiže do pokretanja mjerenja (ovdje ga zaustavlja „već u tijeku”, da test ne stvara novo mjerenje).
$tasks_before = count( (array) get_option( 'olr_test_dfs_tasks' ) );
update_option( OLR_DFS::PENDING, 'test-u-tijeku' );
$mj->set_param( 'potvrda', 'da' );
$mr = rest_do_request( $mj );
ok( 502 === $mr->get_status() && 'olr_dfs_u_tijeku' === $mr->get_data()['code'] && count( (array) get_option( 'olr_test_dfs_tasks' ) ) === $tasks_before, 'pozicije: s potvrdom zahtjev dolazi do mjerenja, a drugo se ne pokreće dok prvo traje' );
delete_option( OLR_DFS::PENDING );
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$mp = wp_get_ability( 'ol-radar/radar-prijedlozi' )->execute( array( 'limit' => 3 ) );
	ok( is_array( $mp ) && 3 === count( $mp['prijedlozi'] ) && $mp['otvorenih'] === $s['otvorenih'] && 'novo' === $mp['prijedlozi'][0]['stanje'] && false === strpos( wp_json_encode( $mp ), 'sk-ant' ), 'mcp: radar-prijedlozi (otvoreni, s limitom)' );
	$ms = wp_get_ability( 'ol-radar/radar-stanje' )->execute( array() );
	$mj = wp_json_encode( $ms );
	ok( is_array( $ms ) && OLR_VERSION === $ms['stanje']['verzija'] && true === $ms['stanje']['kljucevi']['anthropic'] && $ms['prijedlozi']['otvorenih'] === $s['otvorenih'] && false === $ms['pozicije']['mjerenje_u_tijeku'] && ! empty( $ms['gsc']['podaci_do'] ) && strlen( $mj ) < 6000 && false === strpos( $mj, 'sk-ant' ) && false === strpos( $mj, 'PRIVATE KEY' ), 'mcp: radar-stanje je kratko i bez ključeva (' . strlen( $mj ) . ' znakova)' );
	$sz = wp_get_ability( 'ol-radar/radar-sazetak' )->execute( array() );
	ok( $sz['prijedlozi']['otvorenih'] === $s['otvorenih'], 'mcp: radar-sazetak javlja broj otvorenih prijedloga' );
	wp_set_current_user( $admin[0]->ID );
}
// 12) Učinak provedenih izmjena: Search Console prije i poslije, uz trend cijelog weba.
$wpdb->query( "DELETE FROM " . OLR_Store::table() . " WHERE coll = 'ucinak'" );
delete_option( OLR_Ucinak::RUN_OPT );
wp_clear_scheduled_hook( OLR_Ucinak::EVENT );
$byk = array();
foreach ( OLR_Prijedlozi::all() as $p ) {
	$byk[ $p['kljuc'] ] = $p;
}
$uA  = $byk['ctr|/progresivne-naocale/'];
$uB  = $byk['prilika|/kontakt/'];
$uC  = $byk['tehnicki|bez_opisa|*'];
$uD  = $byk['pozicije|pad|progresivne naočale cijena'];
$ago = static function ( $n ) {
	return wp_date( 'Y-m-d', time() - $n * DAY_IN_SECONDS );
};
update_option( 'olr_test_ucinak', array( '/progresivne-naocale/' => $ago( 35 ), '/kontakt/' => $ago( 12 ) ) );
$mark = static function ( $id, $stanje, $dan = null ) {
	$r = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/prijedlozi_stanje/' . $id );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( array( 'data' => array_filter( array( 'stanje' => $stanje, 'at' => time() * 1000, 'dan' => $dan ) ) ) ) );
	return rest_do_request( $r )->get_status();
};
ok( 200 === $mark( $uA['id'], 'provedeno', $ago( 35 ) ) && (bool) wp_next_scheduled( OLR_Ucinak::EVENT ), 'učinak: oznaka „provedeno” zakazuje mjerenje u pozadini' );
$mark( $uB['id'], 'provedeno', $ago( 12 ) );
$mark( $uC['id'], 'provedeno' );
$mark( $uD['id'], 'provedeno' );
wp_clear_scheduled_hook( OLR_Ucinak::EVENT );
$ur = OLR_Ucinak::sync();
$ud = OLR_Ucinak::docs();
$w  = isset( $ud[ $uA['id'] ]['prozori']['28'] ) ? $ud[ $uA['id'] ]['prozori']['28'] : null;
ok( ! is_wp_error( $ur ) && 3 === $ur['mjereno'] && 4 === count( $ud ), 'učinak: izmjereno za tri izmjene, četvrta se ne mjeri po stranici' );
ok( $w && 'gotovo' === $ud[ $uA['id'] ]['stanje'] && 'bolje' === $ud[ $uA['id'] ]['ocjena'] && 28 === $w['prije']['klikovi'] && 84 === $w['poslije']['klikovi'] && 2.5 === (float) $w['prije']['ctr_posto'] && 6.67 === (float) $w['poslije']['ctr_posto'] && 6.0 === (float) $w['prije']['pozicija'] && 4.0 === (float) $w['poslije']['pozicija'] && 0.0 === (float) $w['web']['promjena_posto'] && 7.0 === (float) $w['upit']['prije']['pozicija'] && isset( $ud[ $uA['id'] ]['prozori']['7'], $ud[ $uA['id'] ]['prozori']['14'] ), 'učinak: 28 dana prije i poslije, CTR 2,5 % → 6,67 %, web bez promjene: bolje' );
ok( false !== strpos( $ud[ $uA['id'] ]['sazetak'], 'klikovi 28 → 84 (+200 %), cijeli web 0 %' ) && false !== strpos( $ud[ $uA['id'] ]['sazetak'], 'Upit „progresivne naočale”: pozicija 7,0 → 5,0' ) && false === strpos( $ud[ $uA['id'] ]['sazetak'], 'Privremeno' ), 'učinak: sažetak s brojkama (' . $ud[ $uA['id'] ]['sazetak'] . ')' );
$b = $ud[ $uB['id'] ];
ok( 'mjeri' === $b['stanje'] && 9 === $b['dana_poslije'] && isset( $b['prozori']['7'] ) && ! isset( $b['prozori']['14'] ) && 'bolje' === $b['ocjena'] && 6.0 === (float) $b['prozori']['7']['prije']['pozicija'] && 4.0 === (float) $b['prozori']['7']['poslije']['pozicija'] && false !== strpos( $b['sazetak'], 'Privremeno' ), 'učinak: nakon 9 dana privremena usporedba 7 dana (pozicija 6,0 → 4,0)' );
ok( 'nemjerljivo' === $ud[ $uC['id'] ]['stanje'] && false !== strpos( $ud[ $uC['id'] ]['razlog'], 'više stranica' ), 'učinak: izmjena na više stranica se ne mjeri po stranici' );
ok( 'ceka' === $ud[ $uD['id'] ]['stanje'] && 0 === $ud[ $uD['id'] ]['dana_poslije'] && null === $ud[ $uD['id'] ]['ocjena'] && in_array( $ud[ $uD['id'] ]['polazno']['klikovi'], array( 25, 26 ), true ) && false !== strpos( $ud[ $uD['id'] ]['sazetak'], 'prošlo je 0 od 7 dana' ), 'učinak: tek provedena izmjena čeka, s polaznim stanjem' );
$lf = get_option( 'olr_test_gsc_last_filter' );
ok( 'includingRegex' === $lf[0]['filters'][0]['operator'] && 'page' === $lf[0]['filters'][0]['dimension'] && false !== strpos( $lf[0]['filters'][0]['expression'], '/?(\?.*)?' ), 'učinak: stranica se filtrira s parametrima i bez njih (' . $lf[0]['filters'][0]['expression'] . ')' );
$n0 = count( (array) get_option( 'olr_test_log' ) );
delete_option( 'olr_test_log' );
$ur2 = OLR_Ucinak::sync();
ok( 0 === $ur2['mjereno'] && count( (array) get_option( 'olr_test_log' ) ) <= 2, 'učinak: bez novih Google podataka nema ponovnih upita po stranici' );
$sm = OLR_Prijedlozi::summary( 'provedeno', 50 );
ok( 4 === count( $sm['stavke'] ) && 1 === $sm['ucinak']['bolje'] && 2 === $sm['ucinak']['mjeri_se'] && 1 === $sm['ucinak']['nemjerljivo'] && isset( $sm['stavke'][0]['mjerenje']['stanje'], $sm['stavke'][0]['provedeno_dana'] ), 'učinak: uz provedene prijedloge u ploči i MCP-u' );
// Promjena dana provedbe počinje mjerenje iznova; vraćanje stanja uklanja zapis.
$mark( $uB['id'], 'provedeno', $ago( 20 ) );
$mark( $uD['id'], 'novo' );
$mark( $uA['id'], 'odbijeno' );
OLR_Ucinak::sync();
$ud = OLR_Ucinak::docs();
ok( $ago( 20 ) === $ud[ $uB['id'] ]['provedeno'] && isset( $ud[ $uB['id'] ]['prozori']['14'] ) && ! isset( $ud[ $uD['id'] ] ) && ! isset( $ud[ $uA['id'] ] ) && 2 === count( $ud ), 'učinak: novi dan provedbe mjeri iznova, a zapis nestaje kad prijedlog više nije proveden' );
ok( 'premalo' === OLR_Ucinak::verdict( 'ctr', array( 'prikazivanja' => 10, 'klikovi' => 1, 'ctr_posto' => 10.0, 'pozicija' => 3.0 ), array( 'prikazivanja' => 12, 'klikovi' => 3, 'ctr_posto' => 25.0, 'pozicija' => 3.0 ), 0.0 )
	&& 'isto' === OLR_Ucinak::verdict( 'ctr', array( 'prikazivanja' => 500, 'klikovi' => 15, 'ctr_posto' => 3.0, 'pozicija' => 3.0 ), array( 'prikazivanja' => 500, 'klikovi' => 16, 'ctr_posto' => 3.2, 'pozicija' => 3.0 ), 0.0 )
	&& 'losije' === OLR_Ucinak::verdict( 'prilika', array( 'prikazivanja' => 300, 'klikovi' => 9, 'ctr_posto' => 3.0, 'pozicija' => 6.0 ), array( 'prikazivanja' => 280, 'klikovi' => 5, 'ctr_posto' => 1.8, 'pozicija' => 8.2 ), 0.0 )
	&& 'isto' === OLR_Ucinak::verdict( 'pad', array( 'prikazivanja' => 300, 'klikovi' => 10, 'ctr_posto' => 3.3, 'pozicija' => 4.0 ), array( 'prikazivanja' => 400, 'klikovi' => 14, 'ctr_posto' => 3.5, 'pozicija' => 4.0 ), 35.0 )
	&& 'bolje' === OLR_Ucinak::verdict( 'pad', array( 'prikazivanja' => 300, 'klikovi' => 10, 'ctr_posto' => 3.3, 'pozicija' => 4.0 ), array( 'prikazivanja' => 400, 'klikovi' => 18, 'ctr_posto' => 4.5, 'pozicija' => 4.0 ), 5.0 ), 'učinak: ocjena traži dovoljno prikazivanja i gleda trend cijelog weba (rast +40 % uz web +35 % nije učinak)' );
ok( 'bolje' === OLR_Ucinak::verdict( 'prilika', array( 'prikazivanja' => 1120, 'klikovi' => 28, 'ctr_posto' => 2.5, 'pozicija' => 6.0 ), array( 'prikazivanja' => 1155, 'klikovi' => 42, 'ctr_posto' => 3.6, 'pozicija' => 5.5 ), 0.0 )
	&& 'isto' === OLR_Ucinak::verdict( 'ctr', array( 'prikazivanja' => 1000, 'klikovi' => 30, 'ctr_posto' => 3.0, 'pozicija' => 3.0 ), array( 'prikazivanja' => 2000, 'klikovi' => 62, 'ctr_posto' => 3.1, 'pozicija' => 3.0 ), 0.0 )
	&& 'premalo' === OLR_Ucinak::verdict( 'ctr', array( 'prikazivanja' => 12, 'klikovi' => 0, 'ctr_posto' => 0.0, 'pozicija' => 9.0 ), array( 'prikazivanja' => 200, 'klikovi' => 9, 'ctr_posto' => 4.5, 'pozicija' => 4.0 ), 0.0 ), 'učinak: bez pomaka pozicije odlučuju klikovi prema trendu weba; naslov i opis sude se samo po CTR-u' );

// 13) Indeksiranost stranica (URL Inspection API).
$wpdb->query( "DELETE FROM " . OLR_Store::table() . " WHERE coll = 'indeks'" );
foreach ( array( OLR_Indeks::DAY_OPT, 'olr_test_indeks_calls', 'olr_test_indeks_403' ) as $o ) {
	delete_option( $o );
}
foreach ( array( '/kontakt/', '/usluge-b/' ) as $path ) {
	// Sadržaj se traži preko crawla: istu adresu može imati stranica ili objava.
	$pid = (int) OLR_Crawler::get_row( home_url( $path ) )->post_id;
	$wpdb->update( $wpdb->posts, array( 'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'ID' => $pid ) );
	clean_post_cache( $pid );
}
$iu    = OLR_Indeks::urls();
$paths = array_map( array( 'OLR_Analiza', 'path' ), array_keys( $iu ) );
ok( count( $iu ) > 100 && ! in_array( '/privatno/', $paths, true ) && in_array( '/kontakt/', $paths, true ) && '/' === $paths[0], 'indeks: provjeravaju se stranice koje trebaju biti u indeksu (bez noindex), najvažnije prve' );
OLR_Settings::update( array( 'indeks_auto' => false ) );
OLR_Indeks::tick();
ok( 0 === (int) get_option( 'olr_test_indeks_calls', 0 ), 'indeks: isključena provjera ne šalje ništa' );
OLR_Settings::update( array( 'indeks_auto' => true ) );
OLR_Indeks::tick();
$ic = (int) get_option( 'olr_test_indeks_calls', 0 );
$iq = get_option( 'olr_test_indeks_last' );
ok( 12 === $ic && OLR_Settings::get( 'gsc_site' ) === $iq['siteUrl'] && 'hr' === $iq['languageCode'] && OLR_Indeks::remaining() === count( $iu ) - 12, 'indeks: satni prolaz provjerava malu seriju (12), s našim svojstvom' );
$ib = OLR_Indeks::run_batch( 500, 120 );
$is = OLR_Indeks::summary();
$ir = array();
foreach ( $is['stranice'] as $row ) {
	$ir[ $row['put'] ] = $row;
}
ok( ! is_wp_error( $ib ) && 0 === $ib['preostalo'] && count( $iu ) === $is['ukupno'] && $is['ukupno'] === $is['provjereno'] && 3 === $is['nije'] && 1 === $is['kanonski'] && 1 === $is['greske'] && $is['ukupno'] - 4 === $is['u_indeksu'] && 0 === $is['preostalo'], 'indeks: sve stranice provjerene (' . $is['u_indeksu'] . ' u indeksu, 3 nisu, 1 druga kanonska, 1 odbijena adresa)' );
ok( 'nije' === $ir['/kontakt/']['problem'] && 'Pretraženo – trenutačno nije indeksirano' === $ir['/kontakt/']['razlog'] && '' !== $ir['/kontakt/']['zadnji_posjet'] && 0 === strpos( $ir['/kontakt/']['link'], 'https://search.google.com/search-console/inspect' ), 'indeks: razlog koji navodi Google, zadnji posjet i poveznica na Search Console' );
ok( 'stranica ima oznaku noindex' === $ir['/usluge-a/']['razlog'] && 'greška servera (5xx)' === $ir['/usluge-b/']['razlog'] && 'kanonski' === $ir['/dioptrijske-naocale/']['problem'] && true === $ir['/dioptrijske-naocale/']['u_indeksu'] && false !== strpos( $ir['/dioptrijske-naocale/']['razlog'], '/progresivne-naocale/' ) && 'greska' === $ir['/sample-page/']['problem'], 'indeks: noindex, greška servera, druga kanonska adresa i odbijena adresa' );
ok( '' !== $is['stranice'][0]['problem'] && '' === $ir['/progresivne-naocale/']['problem'] && true === OLR_Settings::status()['indeks']['ok'], 'indeks: stranice s problemom su prve u popisu' );
delete_option( 'olr_test_indeks_calls' );
OLR_Indeks::tick();
ok( 0 === (int) get_option( 'olr_test_indeks_calls', 0 ), 'indeks: svježe provjerene stranice se ne provjeravaju ponovno' );
// Starenje: stranica s problemom ide na ponovnu provjeru nakon 2 dana, uredna nakon 7.
$age = static function ( $path, $days ) use ( $wpdb ) {
	foreach ( OLR_Store::list_docs( 'indeks', '', 'asc', 2000 ) as $d ) {
		if ( OLR_Analiza::path( $d['data']['url'] ) === $path ) {
			$d['data']['at'] = ( time() - $days * DAY_IN_SECONDS ) * 1000;
			OLR_Store::set( 'indeks', $d['id'], $d['data'] );
		}
	}
};
$age( '/kontakt/', 3 );
$age( '/progresivne-naocale/', 3 );
ok( 1 === OLR_Indeks::remaining(), 'indeks: stranica s problemom se ponovno provjerava nakon 2 dana, uredna tek nakon 7' );
OLR_Indeks::tick();
ok( 1 === (int) get_option( 'olr_test_indeks_calls', 0 ) && 0 === OLR_Indeks::remaining(), 'indeks: satni prolaz osvježi samo zastarjelu stranicu' );
// Greška pristupa: jasna poruka, bez daljnjih pokušaja šest sati.
$age( '/kontakt/', 3 );
update_option( 'olr_test_indeks_403', 1 );
$ie = OLR_Indeks::run_batch( 5, 20 );
delete_option( 'olr_test_indeks_calls' );
OLR_Indeks::tick();
ok( is_wp_error( $ie ) && 'olr_indeks_pristup' === $ie->get_error_code() && false === OLR_Settings::status()['indeks']['ok'] && 0 === (int) get_option( 'olr_test_indeks_calls', 0 ), 'indeks: bez pristupa svojstvu javlja jasnu grešku i čeka prije novog pokušaja' );
delete_option( 'olr_test_indeks_403' );
update_option( OLR_Indeks::DAY_OPT, array( 'dan' => gmdate( 'Y-m-d' ), 'n' => OLR_Indeks::DAILY_MAX ) );
$ie = OLR_Indeks::run_batch( 5, 20 );
ok( is_wp_error( $ie ) && 'olr_indeks_dnevno' === $ie->get_error_code(), 'indeks: dnevni broj provjera je ograničen (ispod Googleove kvote)' );
delete_option( OLR_Indeks::DAY_OPT );
$ib = OLR_Indeks::run_batch( 5, 20 );
ok( ! is_wp_error( $ib ) && 1 === $ib['provjereno_sada'] && true === OLR_Settings::status()['indeks']['ok'], 'indeks: nakon greške provjera se nastavlja' );
// Analiza: stranica koja nije u indeksu i druga kanonska adresa postaju prijedlozi; svježe mijenjana stranica ne.
$an = OLR_Analiza::run();
$ak = array();
foreach ( $an['kandidati'] as $c ) {
	$ak[ $c['kljuc'] ] = $c;
}
ok( isset( $ak['tehnicki|nije_u_indeksu|/kontakt/'], $ak['tehnicki|nije_u_indeksu|/usluge-b/'], $ak['tehnicki|druga_kanonska|/dioptrijske-naocale/'] ) && ! isset( $ak['tehnicki|nije_u_indeksu|/usluge-a/'] ) && false !== strpos( $ak['tehnicki|nije_u_indeksu|/kontakt/']['dokazi']['detalj'], 'zadnji posjet Googlebota' ) && $ak['tehnicki|nije_u_indeksu|/kontakt/']['ucinak'] >= 14 && false !== strpos( implode( ' ', $an['napomene'] ), 'zadnjih 7 dana' ), 'indeks: stranica koja nije u indeksu ulazi u prijedloge, svježe mijenjana ne (uz napomenu)' );
$io = new WP_REST_Request( 'POST', '/ol-radar/v1/osvjezi' );
$io->set_param( 'dio', 'indeks' );
$ior = rest_do_request( $io );
$iod = $ior->get_data();
$dd  = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) )->get_data();
ok( 200 === $ior->get_status() && isset( $iod['rezultat']['preostalo'], $iod['rezultat']['indeks']['stranice'] ) && isset( $dd['indeks']['ukupno'] ), 'indeks: ručna serija iz ploče i sažetak u podacima ploče' );
ok( isset( $dd['rank']['konkurenti']['domene'][0]['udio'] ) && array_key_exists( 'aio', $dd['rank']['latest']['rows'][0] ) && isset( $dd['prijedlozi']['ucinak'] ), 'konkurenti, AI pregled i učinak: u podacima nadzorne ploče' );
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$mi = wp_get_ability( 'ol-radar/radar-indeks' )->execute( array() );
	$ma = wp_get_ability( 'ol-radar/radar-indeks' )->execute( array( 'sve' => true, 'limit' => 20 ) );
	$mz = wp_get_ability( 'ol-radar/radar-pozicije' )->execute( array( 'konkurenti' => true ) );
	$ms = wp_get_ability( 'ol-radar/radar-stanje' )->execute( array() );
	ok( 5 === count( $mi['stranice'] ) && 3 === $mi['sazetak']['nije'] && 20 === count( $ma['stranice'] ) && ! isset( $mi['sazetak']['stranice'] ), 'mcp: radar-indeks (zadano samo stranice s problemom)' );
	ok( isset( $mz['kretanje_konkurenata']['domene'][0]['udio'] ) && true === $mz['rijeci'][0]['ai_pregled']['ima'] && isset( $ms['indeks']['u_indeksu'], $ms['prijedlozi']['ucinak']['mjeri_se'] ) && strlen( wp_json_encode( $ms ) ) < 6000, 'mcp: radar-pozicije s AI pregledom i kretanjem konkurenata; radar-stanje s indeksom i učinkom' );
	wp_set_current_user( $admin[0]->ID );
}

// Vrati osnovne Google podatke (ostali testovi računaju s njima); prijedlozi ostaju za test ploče.
delete_option( 'olr_test_gsc_extra' );
OLR_GSC::collect();
wp_clear_scheduled_hook( OLR_Prijedlozi::EVENT );

delete_option( 'olr_test_mocks' );
echo $fails ? "\nNEUSPJELO: {$fails}\n" : "\nSVE PROŠLO\n";
exit( $fails ? 1 : 0 );
