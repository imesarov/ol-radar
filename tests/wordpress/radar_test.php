<?php
/**
 * Test Radara na hostingu (dodatak 2.x) s lažnim Google, DataForSEO, Anthropic, Meta, YouTube, TikTok i Ahrefs odgovorima.
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
foreach ( array( 'olr_secrets', 'olr_status', 'olr_rank_pending', 'olr_dfs_cost', 'olr_ai_usage', 'olr_settings', 'olr_tt_tokens', 'olr_mreze_run' ) as $o ) {
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
// Odgovor bez izmjene teksta, samo s koracima (npr. treba pričekati), valjan je prijedlog, a ne neuspjeh.
// Prijedlozi koji su prije nadogradnje ostali bez teksta dobivaju još jedan pokušaj.
delete_option( 'olr_prijedlozi_ponovi' );
update_option( 'olr_test_ai_bez_izmjena', 1 );
$run             = get_option( 'olr_prijedlozi_run' );
$run['tekst_at'] = time() - HOUR_IN_SECONDS;
update_option( 'olr_prijedlozi_run', $run );
$w0 = OLR_Prijedlozi::summary()['ceka_tekst'];
ok( 0 === $w0 && true === OLR_Prijedlozi::fill_due() && '2.3.0' === get_option( 'olr_prijedlozi_ponovi' ) && OLR_Prijedlozi::summary()['ceka_tekst'] >= 4, 'prijedlozi: nakon nadogradnje prijedlozi bez teksta dobivaju još jedan pokušaj (jednom)' );
OLR_Prijedlozi::run( 'tekst' );
$a4 = array_values( array_filter( OLR_Prijedlozi::all(), static function ( $p ) { return ! empty( $p['ai'] ) && empty( $p['isteklo'] ); } ) );
ok( count( $a4 ) >= 4 && array() === $a4[0]['izmjene'] && array( 'Prvi korak', 'Drugi korak' ) === $a4[0]['koraci'] && 'Objašnjenje iz ulaza.' === $a4[0]['objasnjenje'] && 0 === OLR_Prijedlozi::summary()['ceka_tekst'] && true === OLR_Settings::status()['prijedlozi']['ok'], 'prijedlozi: Claudeov odgovor samo s koracima (bez izmjene teksta) sprema se kao gotov prijedlog' );
delete_option( 'olr_test_ai_bez_izmjena' );
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
ok( false === strpos( wp_json_encode( $sent ), 'sk-ant' ) && false === strpos( wp_json_encode( $sent ), 'PRIVATE KEY' ) && false !== strpos( $sent['system'], 'Ne izmišljaj' ) && ! isset( $sent['tool_choice'] ) && 'spremi_prijedlog' === $sent['tools'][0]['name'] && false !== strpos( $sent['system'], 'Odgovori isključivo pozivom alata spremi_prijedlog' ) && $sent['max_tokens'] <= 3000 && ! isset( json_decode( $sent['messages'][0]['content'], true )['nalazi'] ), 'prijedlozi: jedan nalaz po pozivu, alat tražen uputom (bez prisile koju novi modeli odbijaju), Claudeu idu samo podaci o stranici i upitima' );
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

// 14) Claude u ploči: JSON odgovor pozivom alata (traženim uputom, bez prisile); zadnji poziv u stanju.
delete_option( 'olr_test_ai_json' );
$jq = array(
	'messages'    => array( array( 'role' => 'user', 'content' => 'NALAZ: test. Vrati SAMO JSON objekt.' ) ),
	'tools'       => array( array( 'name' => 'odgovor', 'description' => 'x', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'dijagnoza' => array( 'type' => 'string' ) ) ) ) ),
	'tool_choice' => array( 'name' => 'odgovor' ),
	'max_tokens'  => 4096,
);
$jr = OLR_AI::call( $jq );
$js = OLR_Settings::status()['ai_poziv'];
ok( ! is_wp_error( $jr ) && 'tool_use' === $jr['stop_reason'] && 'Dijagnoza iz alata.' === $jr['content'][0]['input']['dijagnoza'] && true === $js['ok'] && false !== strpos( $js['msg'], 'odgovor alatom' ) && false !== strpos( $js['msg'], 'izlaz 220' ), 'ai: JSON odgovor stiže kao poziv alata; zadnji poziv je zapisan u stanju (' . $js['msg'] . ')' );
update_option( 'olr_test_ai_json', 'prekid' );
$jr = OLR_AI::call( $jq );
$js = OLR_Settings::status()['ai_poziv'];
ok( ! is_wp_error( $jr ) && 'max_tokens' === $jr['stop_reason'] && false === $js['ok'] && false !== strpos( $js['msg'], 'prekinut zbog duljine' ), 'ai: prekinut odgovor je vidljiv u stanju izvora' );
// Model ne pozove alat nego odgovori tekstom: jedan ponovljeni pokušaj s podsjetnikom, zatim se vraća što je stiglo.
update_option( 'olr_test_ai_json', 'odbij' );
$n0 = (int) get_option( 'olr_test_ai_calls' );
$jr = OLR_AI::call( $jq );
$jl = get_option( 'olr_test_ai_last' );
ok( ! is_wp_error( $jr ) && 'end_turn' === $jr['stop_reason'] && false !== strpos( $jr['content'][0]['text'], 'Dijagnoza iz teksta' ) && 2 === (int) get_option( 'olr_test_ai_calls' ) - $n0 && false !== strpos( $jl['system'], 'u prethodnom pokušaju alat nije pozvan' ), 'ai: kad model ne pozove alat, ide jedan ponovljeni pokušaj s podsjetnikom' );
ok( ! isset( $jl['tool_choice'] ) && 'odgovor' === $jl['tools'][0]['name'], 'ai: prisilni poziv alata se ne šalje (Opus 5.5 i Sonnet 5.5 ga odbijaju s 400)' );
// Zahtjev koji API odbije (ovdje: prisila poslana mimo dodatka) ima svoju oznaku.
$raw = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 'headers' => array( 'x-api-key' => 'sk-ant-test-1234', 'anthropic-version' => '2023-06-01' ), 'body' => wp_json_encode( array( 'model' => 'claude-opus-5-5', 'max_tokens' => 10, 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'tool_choice' => array( 'type' => 'tool', 'name' => 'odgovor' ) ) ) ) );
ok( 400 === wp_remote_retrieve_response_code( $raw ), 'mock: prisilni poziv alata vraća 400 kao pravi API' );
delete_option( 'olr_test_ai_json' );

// 15) Društvene mreže: Instagram i Facebook (Meta Graph API), YouTube (Data API), TikTok (Display API), analiza i preporuke.
foreach ( array( 'olr_test_meta', 'olr_test_meta_calls', 'olr_test_meta_batch', 'olr_test_yt_calls', 'olr_test_yt_kvota', 'olr_test_tt_refresh', 'olr_test_tt_refresh_fail', 'olr_test_ai_mreze', 'olr_test_ai_mreze_calls', OLR_Social::TT_OPT, OLR_Social::RUN_OPT ) as $o ) {
	delete_option( $o );
}
delete_transient( OLR_Social::LOCK );
$secrets_before = get_option( 'olr_secrets' );
ok( ! array_filter( OLR_Social::connected() ) && 4 === count( OLR_Social::summary()['mreze'] ) && false === OLR_Social::summary()['mreze']['ig']['povezano'], 'mreže: bez pristupnih podataka ništa nije povezano' );
delete_option( 'olr_test_log' );
OLR_Social::tick();
ok( ! get_option( 'olr_test_log' ), 'mreže: satni prolaz bez povezanih mreža ne šalje nijedan zahtjev' );
OLR_Settings::set_secret( 'meta_token', 'meta-test-token' );
$after = get_option( 'olr_secrets' );
ok( false === strpos( wp_json_encode( $after ), 'meta-test-token' ) && 'meta-test-token' === OLR_Settings::secret( 'meta_token' ) && 'postavljen (…oken)' === OLR_Settings::describe_secret( 'meta_token' ) && 'baza (šifrirano)' === OLR_Settings::secret_source( 'meta_token' ), 'mreže: Meta token je spremljen šifrirano i ne prikazuje se' );
ok( $after['gsc_sa'] === $secrets_before['gsc_sa'] && $after['anthropic_key'] === $secrets_before['anthropic_key'] && $after['dfs_password'] === $secrets_before['dfs_password'], 'mreže: spremanje novog ključa ne dira postojeće ključeve' );

// Instagram: stranica s povezanim Instagramom, metrike u jednom batch pozivu, uži skup metrika kad širi ne prolazi.
$ctx = OLR_Social::meta_context();
ok( ! is_wp_error( $ctx ) && '111' === $ctx['page_id'] && '17841' === $ctx['ig_id'] && 'page-token-1' === $ctx['page_token'] && 2 === $ctx['stranica'], 'meta: od dviju stranica bira onu s povezanim Instagramom i koristi token stranice' );
OLR_Settings::update( array( 'meta_page' => 'druga' ) );
ok( '222' === OLR_Social::meta_context()['page_id'], 'meta: postavka „Facebook stranica” bira stranicu po nazivu' );
OLR_Settings::update( array( 'meta_page' => '' ) );
$n  = OLR_Social::collect_ig();
$ig = OLR_Store::data( 'mreze', 'ig' );
$by = array();
foreach ( $ig['objave'] as $p ) {
	$by[ $p['id'] ] = $p;
}
ok( 9 === $n && '@optikaljiljana' === $ig['racun']['ime'] && 2140 === $ig['racun']['pratitelji'], 'instagram: račun i 9 objava' );
ok( 9000 === $by['r1']['pregledi'] && 4200 === $by['r1']['doseg'] && 120 === $by['r1']['spremanja'] && 310 === $by['r1']['dijeljenja'] && 400 === $by['r1']['lajkovi'] && 35 === $by['r1']['komentari'] && 9.0 === (float) $by['r1']['gledano_s'] && 'reel' === $by['r1']['vrsta'] && 'karusel' === $by['k1']['vrsta'] && 'objava' === $by['f1']['vrsta'], 'instagram: pregledi, doseg, spremanja, dijeljenja i prosječno gledanje reela (iz milisekundi u sekunde)' );
ok( 1800 === $by['r3']['pregledi'] && null === $by['r3']['gledano_s'] && null === $by['m_stara']['pregledi'] && null === $by['m_stara']['doseg'] && 30 === $by['m_stara']['lajkovi'], 'instagram: kad Meta odbije širi skup metrika, prolazi uži; objava bez metrika ostaje s lajkovima' );
ok( 3 === (int) get_option( 'olr_test_meta_batch' ) && true === OLR_Settings::status()['mreze_ig']['ok'] && false !== strpos( OLR_Settings::status()['mreze_ig']['msg'], 'za 1 Meta nije dala metrike' ), 'instagram: metrike svih objava u tri batch poziva (širi, uži, najuži skup)' );
ok( ! preg_grep( '#access_token=|meta-test-token|page-token#', (array) get_option( 'olr_test_log' ) ), 'meta: token nikad nije u adresi zahtjeva' );
update_option( 'olr_test_meta', 'bez_batcha' );
OLR_Store::delete( 'mreze', 'ig' );
ok( 9 === OLR_Social::collect_ig() && 9000 === OLR_Store::data( 'mreze', 'ig' )['objave'][0]['pregledi'], 'instagram: kad batch ne prođe, metrike se čitaju jedna po jedna' );
update_option( 'olr_test_meta', 'istekao' );
$e = OLR_Social::collect_ig();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'nije valjan ili je istekao' ) && false === OLR_Settings::status()['mreze_ig']['ok'] && 9 === count( OLR_Store::data( 'mreze', 'ig' )['objave'] ), 'instagram: istekao token daje jasnu poruku, a postojeći podaci ostaju' );
update_option( 'olr_test_meta', 'bez_ig' );
$e = OLR_Social::collect_ig();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'nema povezan profesionalni Instagram' ), 'instagram: stranica bez povezanog Instagrama ima svoju poruku' );
// Facebook radi i bez Instagrama (bez Instagrama se stranica bira po nazivu iz postavke).
OLR_Settings::update( array( 'meta_page' => 'Optika Lj' ) );
$n  = OLR_Social::collect_fb();
OLR_Settings::update( array( 'meta_page' => '' ) );
$fb = OLR_Store::data( 'mreze', 'fb' );
$fy = array();
foreach ( $fb['objave'] as $p ) {
	$fy[ $p['id'] ] = $p;
}
ok( 6 === $n && 3350 === $fb['racun']['pratitelji'] && 2400 === $fy['111_p1']['pregledi'] && 1500 === $fy['111_p1']['doseg'] && 90 === $fy['111_p1']['lajkovi'] && 12 === $fy['111_p1']['komentari'] && 25 === $fy['111_p1']['dijeljenja'] && 'video' === $fy['111_p1']['vrsta'], 'facebook: stranica, objave, reakcije, komentari, dijeljenja, pregledi i doseg' );
ok( 480 === $fy['111_p6']['pregledi'] && null === $fy['111_p6']['doseg'] && 'karusel' === $fy['111_p4']['vrsta'] && 'poveznica' === $fy['111_p5']['vrsta'] && null === $fy['111_p2']['dijeljenja'], 'facebook: objava bez metrike dosega dobiva barem preglede' );
update_option( 'olr_test_meta', 'uza_polja' );
delete_option( 'olr_test_log' );
ok( 6 === OLR_Social::collect_fb() && 2400 === OLR_Store::data( 'mreze', 'fb' )['objave'][0]['pregledi'] && 2 === count( preg_grep( '#/111/posts\?#', (array) get_option( 'olr_test_log' ) ) ) && true === OLR_Settings::status()['mreze_fb']['ok'], 'facebook: kad Meta odbije širi popis polja, objave se preuzmu s užim popisom' );
delete_option( 'olr_test_meta' );
OLR_Social::collect_fb();
OLR_Social::collect_ig();

// YouTube: kanal po oznaci, videi, kratki do 60 s; ključ u zaglavlju.
$e = OLR_Social::collect_yt();
ok( is_wp_error( $e ) && 'olr_yt_nema' === $e->get_error_code(), 'youtube: bez ključa i kanala jasna poruka' );
OLR_Settings::set_secret( 'yt_key', 'krivi-kljuc' );
OLR_Settings::update( array( 'yt_channel' => '@optikaljiljana' ) );
$e = OLR_Social::collect_yt();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'API ključ nije valjan' ), 'youtube: neispravan ključ' );
OLR_Settings::set_secret( 'yt_key', 'yt-test-key' );
OLR_Settings::update( array( 'yt_channel' => 'https://www.youtube.com/@nepostojeci' ) );
$e = OLR_Social::collect_yt();
ok( is_wp_error( $e ) && 'olr_yt_kanal' === $e->get_error_code(), 'youtube: kanal koji ne postoji' );
OLR_Settings::update( array( 'yt_channel' => 'https://www.youtube.com/@optikaljiljana' ) );
delete_option( 'olr_test_log' );
$n  = OLR_Social::collect_yt();
$yt = OLR_Store::data( 'mreze', 'yt' );
$yy = array();
foreach ( $yt['objave'] as $p ) {
	$yy[ $p['id'] ] = $p;
}
ok( 6 === $n && 310 === $yt['racun']['pratitelji'] && 'UCown' === $yt['racun']['id'] && 5200 === $yy['own1']['pregledi'] && 45 === $yy['own1']['trajanje'] && 'short' === $yy['own1']['vrsta'] && 250 === $yy['own3']['trajanje'] && 'video' === $yy['own3']['vrsta'] && 360 === OLR_Social::iso_seconds( 'PT6M' ) && 3725 === OLR_Social::iso_seconds( 'PT1H2M5S' ), 'youtube: kanal iz adrese s oznakom, 6 videa, trajanje iz ISO oblika, kratki do 60 s' );
ok( 3 === count( (array) get_option( 'olr_test_log' ) ) && ! preg_grep( '#key=|yt-test-key#', (array) get_option( 'olr_test_log' ) ), 'youtube: tri zahtjeva (kanal, popis, videi), ključ nije u adresi' );
update_option( 'olr_test_yt_kvota', 1 );
$e = OLR_Social::collect_yt();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'dnevna kvota' ) && 6 === count( OLR_Store::data( 'mreze', 'yt' )['objave'] ), 'youtube: potrošena kvota ima jasnu poruku i ne briše podatke' );
delete_option( 'olr_test_yt_kvota' );
delete_option( 'olr_net_ok' );
update_option( 'olr_test_net_fail', 'auto' );
ok( 6 === OLR_Social::collect_yt(), 'youtube: kad zadani način veze ne odgovara, prolazi IPv4 + HTTP/1.1' );
delete_option( 'olr_test_net_fail' );
delete_option( 'olr_net_ok' );

// TikTok: OAuth (state vezan uz administratora, jednokratan), tokeni šifrirano, obnova pristupa.
$e = OLR_Social::tt_authorize_url();
ok( is_wp_error( $e ) && 'olr_tt_nema' === $e->get_error_code(), 'tiktok: bez Client keya i secreta nema povezivanja' );
OLR_Settings::update( array( 'tt_client_key' => 'tt-key' ) );
OLR_Settings::set_secret( 'tt_secret', 'tt-secret' );
$au = OLR_Social::tt_authorize_url();
parse_str( (string) wp_parse_url( $au, PHP_URL_QUERY ), $aq );
ok( 0 === strpos( $au, 'https://www.tiktok.com/v2/auth/authorize/?' ) && 'tt-key' === $aq['client_key'] && 'code' === $aq['response_type'] && 'user.info.basic,user.info.stats,video.list' === $aq['scope'] && rest_url( 'ol-radar/v1/oauth/tiktok' ) === $aq['redirect_uri'] && 32 === strlen( $aq['state'] ) && false === strpos( $au, 'tt-secret' ), 'tiktok: adresa za odobrenje (dopuštenja samo za čitanje, povratna adresa dodatka, bez secreta)' );
ok( false === strpos( OLR_Social::tt_redirect_uri(), '?' ), 'tiktok: povratna adresa nema upit (TikTok ga ne prihvaća)' );
wp_set_current_user( 0 ); // povratak s TikToka dolazi bez WordPressova noncea
$cb = new WP_REST_Request( 'GET', '/ol-radar/v1/oauth/tiktok' );
$cb->set_query_params( array( 'code' => 'kod-123', 'state' => 'izmisljenstate0000000000000000000' ) );
$cr = rest_do_request( $cb );
ok( 302 === $cr->get_status() && false !== strpos( $cr->get_headers()['Location'], 'olr_tt=greska' ) && null === OLR_Social::tt_tokens(), 'tiktok: povratak s nepoznatim stateom ne povezuje ništa' );
$cb->set_query_params( array( 'code' => 'kod-123', 'state' => $aq['state'] ) );
$cr = rest_do_request( $cb );
$tt = OLR_Social::tt_tokens();
$tn = get_transient( 'olr_settings_notice_' . $admin[0]->ID );
ok( 302 === $cr->get_status() && admin_url( 'admin.php?page=ol-radar-postavke' ) === $cr->get_headers()['Location'] && $tt && 'tt-access-1' === $tt['access'] && 'tt-refresh-1' === $tt['refresh'] && $tt['refresh_exp'] > time() + 300 * DAY_IN_SECONDS, 'tiktok: povratak s ispravnim stateom zamijeni kod za tokene i vrati u Postavke' );
ok( false === strpos( (string) get_option( OLR_Social::TT_OPT ), 'tt-access' ) && false === strpos( (string) get_option( OLR_Social::TT_OPT ), 'tt-refresh' ) && get_option( 'olr_secrets' ) === $after + array( 'yt_key' => get_option( 'olr_secrets' )['yt_key'], 'tt_secret' => get_option( 'olr_secrets' )['tt_secret'] ), 'tiktok: tokeni su šifrirani u zasebnoj opciji; spremljeni ključevi nisu dirani' );
$tl = get_option( 'olr_test_tt_token_last' );
ok( 'authorization_code' === $tl['grant_type'] && rest_url( 'ol-radar/v1/oauth/tiktok' ) === $tl['redirect_uri'] && $tn && true === $tn['ok'] && false !== strpos( $tn['msg'], 'preuzeto 6 videa' ), 'tiktok: nakon povezivanja odmah preuzme videe i javi u Postavkama (' . ( $tn ? $tn['msg'] : '' ) . ')' );
$cr = rest_do_request( $cb );
ok( false !== strpos( $cr->get_headers()['Location'], 'olr_tt=greska' ) && 'tt-access-1' === OLR_Social::tt_tokens()['access'], 'tiktok: state vrijedi samo jednom' );
wp_set_current_user( $admin[0]->ID );
$tk = OLR_Store::data( 'mreze', 'tt' );
ok( 6 === count( $tk['objave'] ) && 540 === $tk['racun']['pratitelji'] && 37 === $tk['racun']['objava'] && 31000 === $tk['objave'][0]['pregledi'] && 240 === $tk['objave'][0]['dijeljenja'] && 21 === $tk['objave'][0]['trajanje'] && 't6' === $tk['objave'][5]['id'], 'tiktok: račun i videi s obje stranice popisa (pregledi, lajkovi, komentari, dijeljenja, trajanje)' );
$tt['exp'] = time() + 600; // pristupni token pred istekom: mora se obnoviti
update_option( OLR_Social::TT_OPT, OLR_Settings::encrypt( wp_json_encode( $tt ) ), false );
ok( 6 === OLR_Social::collect_tt() && 1 === (int) get_option( 'olr_test_tt_refresh' ) && 'Bearer tt-access-2' === get_option( 'olr_test_tt_auth_last' ) && 'tt-access-2' === OLR_Social::tt_tokens()['access'] && 'tt-refresh-1' === OLR_Social::tt_tokens()['refresh'], 'tiktok: pristupni token pred istekom obnavlja se sam' );
OLR_Social::collect_tt();
ok( 1 === (int) get_option( 'olr_test_tt_refresh' ), 'tiktok: svjež token se ne obnavlja ponovno' );
$tt2        = OLR_Social::tt_tokens();
$tt2['exp'] = time() - 10;
update_option( OLR_Social::TT_OPT, OLR_Settings::encrypt( wp_json_encode( $tt2 ) ), false );
update_option( 'olr_test_tt_refresh_fail', 1 );
$e = OLR_Social::collect_tt();
ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'Poveži TikTok' ) && false === OLR_Settings::status()['mreze_tt']['ok'] && 6 === count( OLR_Store::data( 'mreze', 'tt' )['objave'] ), 'tiktok: kad obnova ne uspije, poruka upućuje na ponovno povezivanje, a podaci ostaju' );
delete_option( 'olr_test_tt_refresh_fail' );
ok( 6 === OLR_Social::collect_tt(), 'tiktok: nakon toga obnova opet radi' );
$st = new WP_REST_Request( 'GET', '/ol-radar/v1/oauth/tiktok' );
$st->set_query_params( array( 'error' => 'access_denied', 'error_description' => 'User denied', 'state' => 'x' ) );
ok( 302 === rest_do_request( $st )->get_status() && 'tt-access-2' === OLR_Social::tt_tokens()['access'] && true === OLR_Settings::status()['mreze_tt']['ok'], 'tiktok: javni poziv povratne adrese bez valjanog statea ne mijenja ni vezu ni stanje izvora' );
$au2 = OLR_Social::tt_authorize_url();
parse_str( (string) wp_parse_url( $au2, PHP_URL_QUERY ), $aq2 );
$st->set_query_params( array( 'error' => 'access_denied', 'error_description' => 'User denied', 'state' => $aq2['state'] ) );
$sr = rest_do_request( $st );
$tn = get_transient( 'olr_settings_notice_' . $admin[0]->ID );
ok( admin_url( 'admin.php?page=ol-radar-postavke' ) === $sr->get_headers()['Location'] && $tn && false === $tn['ok'] && false !== strpos( $tn['msg'], 'nije odobrio' ) && 'tt-access-2' === OLR_Social::tt_tokens()['access'] && true === OLR_Settings::status()['mreze_tt']['ok'], 'tiktok: odbijeno odobrenje javlja poruku u Postavkama, a postojeća veza ostaje' );

// Analiza: objava prema medijanu vlastite mreže, obrasci tek uz dovoljno objava.
$an = OLR_Social::analyze();
ok( 8 === $an['ig']['objava'] && 1050 == $an['ig']['medijan'] && 'pregledi' === $an['ig']['mjera'] && 'r1' === $an['ig']['top'][0]['id'] && 8.6 === (float) $an['ig']['top'][0]['omjer'] && 9.6 === (float) $an['ig']['top'][0]['angazman'], 'analiza: Instagram medijan 1.050 pregleda, najbolji reel 8,6× (stara objava bez metrika ne ulazi)' );
ok( 'r4' === $an['ig']['slabo'][0]['id'] && 0.3 === (float) $an['ig']['slabo'][0]['omjer'] && 3 === count( $an['ig']['slabo'] ) && 3 === count( $an['ig']['top'] ), 'analiza: ispod uobičajenog, najslabija prva' );
$ob = wp_list_pluck( $an['ig']['obrasci'], 'omjer', 'opis' );
ok( isset( $ob['vrsta: reel'] ) && 2.0 === (float) $ob['vrsta: reel'] && ! isset( $ob['vrsta: karusel'] ) && ! isset( $ob['opis počinje pitanjem'] ), 'analiza: obrazac samo uz barem tri objave i jasno odstupanje (reelovi 2,0×; dva karusela nisu obrazac)' );
ok( 3850 == $an['tt']['medijan'] && 't1' === $an['tt']['top'][0]['id'] && 8.1 === (float) $an['tt']['top'][0]['omjer'] && 't5' === $an['tt']['slabo'][0]['id'] && 750 == $an['yt']['medijan'] && 'own1' === $an['yt']['top'][0]['id'] && 465 == $an['fb']['medijan'] && '111_p1' === $an['fb']['top'][0]['id'], 'analiza: TikTok, YouTube i Facebook imaju svoj medijan i svoje najbolje objave' );
$few = OLR_Social::analyze_net( 'ig', array( 'objave' => array_slice( $ig['objave'], 0, 3 ) ) );
ok( 3 === $few['objava'] && null === $few['medijan'] && false !== strpos( $few['napomena'], 'barem pet' ) && ! $few['top'], 'analiza: s manje od pet objava nema zaključaka, samo napomena' );
ok( 2 === OLR_Social::median( array( 1, 2, 9 ) ) && 2.5 === OLR_Social::median( array( 4, 1, 2, 3 ) ) && null === OLR_Social::median( array() ), 'analiza: medijan' );

// Uzori: YouTube po temama (omjer prema pretplatnicima), Instagram profili koje vlasnik navede.
OLR_Settings::update( array( 'yt_teme' => 'progresivne naočale, progressive lenses', 'ig_uzori' => '@uzor_optika, https://www.instagram.com/privatni_profil/' ) );
delete_option( 'olr_test_yt_calls' );
$uz = OLR_Social::collect_models();
$ub = wp_list_pluck( $uz['yt'], 'omjer', 'id' );
ok( array( 'ext1', 'ext3', 'ext2' ) === wp_list_pluck( $uz['yt'], 'id' ) && 4.0 === (float) $ub['ext1'] && 13.0 === (float) $ub['ext2'] && 0.8 === (float) $ub['ext3'] && 'progressive lenses' === $uz['yt'][0]['tema'] && 120000 === $uz['yt'][0]['pretplatnika'], 'uzori: YouTube videi po temama, poredani po pregledima, s omjerom prema pretplatnicima; vlastiti video ne ulazi' );
ok( 2 === count( array_keys( (array) get_option( 'olr_test_yt_calls' ), 'search' ) ), 'uzori: jedna YouTube pretraga po temi (100 jedinica kvote svaka)' );
ok( 5 === count( $uz['ig'] ) && '@uzor_optika' === $uz['ig'][0]['profil'] && 9.0 === (float) $uz['ig'][0]['omjer'] && 30000 === $uz['ig'][0]['pregledi'] && 12000 === $uz['ig'][0]['pratitelja'] && 1 === count( $uz['napomene'] ) && false !== strpos( $uz['napomene'][0], '@privatni_profil' ), 'uzori: Instagram objave navedenog profila prema medijanu tog profila; profil koji ne daje podatke je u napomeni' );

// Preporuke: Claude dobiva samo brojke i opise, odgovor se čisti i ograničava.
$ai = OLR_Social::ai_input();
ok( 9000 === $ai['nase_mreze']['ig']['najbolje'][0]['pregledi'] && ! isset( $ai['nase_mreze']['ig']['najbolje'][0]['url'] ) && 3 === count( $ai['uzori_youtube'] ) && false === strpos( wp_json_encode( $ai ), 'token' ), 'preporuke: ulaz za Claudea su brojke i opisi objava, bez tokena' );
$pr = OLR_Social::recommend( 'rucno' );
$rq = get_option( 'olr_test_ai_last' );
ok( ! is_wp_error( $pr ) && 2 === count( $pr['preporuke'] ) && 3 === count( $pr['preporuke'][0]['koraci'] ) && 'ig' === $pr['preporuke'][0]['mreza'] && 'sve' === $pr['preporuke'][1]['mreza'] && 'Preporuka s nepoznatom mrežom bez HTML-a' === $pr['preporuke'][1]['naslov'] && 2 === count( $pr['ideje'] ) && false !== strpos( $pr['sazetak'], '9000' ), 'preporuke: Claudeov odgovor je očišćen (najviše 3 koraka, nepoznata mreža → sve, bez HTML-a, stavka bez naslova ispada)' );
ok( ! isset( $rq['tool_choice'] ) && 'spremi_preporuke' === $rq['tools'][0]['name'] && false !== strpos( $rq['system'], 'Odgovori isključivo pozivom alata spremi_preporuke' ) && 4000 === $rq['max_tokens'] && false === strpos( wp_json_encode( $rq ), 'meta-test-token' ) && false === strpos( wp_json_encode( $rq ), 'tt-access' ) && true === OLR_Settings::status()['mreze_preporuke']['ok'], 'preporuke: alat tražen uputom, bez prisile; u zahtjevu nema tokena' );
update_option( 'olr_test_ai_mreze', 'prekid' );
delete_option( 'olr_test_ai_mreze_calls' );
$pr = OLR_Social::recommend( 'rucno' );
ok( ! is_wp_error( $pr ) && 2 === (int) get_option( 'olr_test_ai_mreze_calls' ) && 0 === strpos( $pr['sazetak'], 'Kraće:' ), 'preporuke: odgovor prekinut zbog duljine traži se ponovno, kraće' );
update_option( 'olr_test_ai_mreze', 'prekid2' );
$e = OLR_Social::recommend( 'rucno' );
ok( is_wp_error( $e ) && 'olr_mreze_oblik' === $e->get_error_code() && false === OLR_Settings::status()['mreze_preporuke']['ok'] && 0 === strpos( OLR_Store::data( 'mreze', 'preporuke' )['sazetak'], 'Kraće:' ), 'preporuke: dvaput prekinut odgovor je greška, a zadnje dobre preporuke ostaju' );
delete_option( 'olr_test_ai_mreze' );
OLR_Social::recommend( 'rucno' );
$e = OLR_Social::recommend( 'rucno' );
ok( is_wp_error( $e ) && 'olr_mreze_limit' === $e->get_error_code(), 'preporuke: ručno najviše ' . OLR_Social::MANUAL_MAX . ' puta dnevno' );

// Satni prolaz: podaci dvaput dnevno, uzori i preporuke tjedno.
$run = OLR_Social::last_run();
ok( empty( $run['at'] ) && ! empty( $run['preporuke_at'] ), 'cron: zapis zadnjeg pokretanja' );
delete_option( 'olr_test_log' );
OLR_Social::tick();
$run = OLR_Social::last_run();
$lg  = (array) get_option( 'olr_test_log' );
ok( ! empty( $run['at'] ) && ! empty( $run['uzori_at'] ) && preg_grep( '#graph\.facebook\.com#', $lg ) && preg_grep( '#youtube/v3/search#', $lg ) && preg_grep( '#tiktokapis\.com/v2/video/list#', $lg ) && ! preg_grep( '#anthropic#', $lg ), 'cron: prvi prolaz preuzme sve mreže i uzore' );
delete_option( 'olr_test_log' );
OLR_Social::tick();
ok( ! get_option( 'olr_test_log' ), 'cron: svježi podaci i svježe preporuke: sljedeći prolaz ne radi ništa' );
$run['preporuke_at']      = time() - 8 * DAY_IN_SECONDS;
$run['preporuke_pokusaj'] = time() - 8 * DAY_IN_SECONDS;
update_option( OLR_Social::RUN_OPT, $run, false );
update_option( 'olr_test_ai_mreze', 'prekid2' );
OLR_Social::tick();
delete_option( 'olr_test_log' );
OLR_Social::tick();
ok( ! get_option( 'olr_test_log' ), 'cron: nakon neuspjelih tjednih preporuka novi pokušaj je tek sutradan' );
delete_option( 'olr_test_ai_mreze' );
$run                      = OLR_Social::last_run();
$run['preporuke_pokusaj'] = time() - 21 * HOUR_IN_SECONDS;
$run['at']                = time() - 13 * HOUR_IN_SECONDS;
update_option( OLR_Social::RUN_OPT, $run, false );
delete_option( 'olr_test_log' );
OLR_Social::tick();
$lg = (array) get_option( 'olr_test_log' );
ok( preg_grep( '#graph\.facebook\.com#', $lg ) && ! preg_grep( '#youtube/v3/search#', $lg ) && ! preg_grep( '#anthropic#', $lg ), 'cron: nakon 12 sati opet podaci, uzori tek nakon tjedan dana' );
OLR_Social::tick();
ok( time() - (int) OLR_Social::last_run()['preporuke_at'] < 60 && true === OLR_Settings::status()['mreze_preporuke']['ok'], 'cron: tjedne preporuke nastaju u sljedećem prolazu' );
OLR_Settings::update( array( 'mreze_auto' => false ) );
$run       = OLR_Social::last_run();
$run['at'] = time() - 13 * HOUR_IN_SECONDS;
update_option( OLR_Social::RUN_OPT, $run, false );
delete_option( 'olr_test_log' );
OLR_Social::tick();
ok( ! get_option( 'olr_test_log' ), 'cron: isključeno automatsko osvježavanje ne šalje zahtjeve' );
OLR_Settings::update( array( 'mreze_auto' => true ) );

// REST i MCP: podaci u ploči, osvježavanje, ništa tajno ne izlazi.
$dd = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) )->get_data();
$dj = wp_json_encode( $dd );
ok( 9 === count( $dd['mreze']['mreze']['ig']['objave'] ) && true === $dd['mreze']['mreze']['tt']['povezano'] && 1050 == $dd['mreze']['mreze']['ig']['analiza']['medijan'] && 3 === count( $dd['mreze']['uzori']['yt'] ) && 2 === count( $dd['mreze']['preporuke']['preporuke'] ) && true === $dd['stanje']['kljucevi']['meta'] && true === $dd['stanje']['kljucevi']['youtube'] && true === $dd['stanje']['kljucevi']['tiktok'], 'rest: društvene mreže u podacima nadzorne ploče' );
$leak = false;
foreach ( array( 'meta-test-token', 'page-token', 'yt-test-key', 'tt-access', 'tt-refresh', 'tt-secret' ) as $x ) {
	$leak = $leak || false !== strpos( $dj, $x ) || false !== strpos( wp_json_encode( OLR_Settings::status() ), $x );
}
ok( ! $leak, 'rest: tokeni i ključevi mreža ne izlaze ni u podacima ni u stanju izvora' );
$mo = new WP_REST_Request( 'POST', '/ol-radar/v1/osvjezi' );
$mo->set_param( 'dio', 'mreze' );
$mr = rest_do_request( $mo );
$md = $mr->get_data();
ok( 200 === $mr->get_status() && 9 === $md['rezultat']['ishod']['ig'] && 6 === $md['rezultat']['ishod']['tt'] && isset( $md['rezultat']['mreze']['mreze']['yt']['analiza'] ), 'rest: „Osvježi mreže” iz ploče' );
$mo->set_param( 'dio', 'mreze_uzori' );
ok( 200 === rest_do_request( $mo )->get_status(), 'rest: osvježavanje uzora iz ploče' );
$mo->set_param( 'dio', 'mreze_preporuke' );
$mr = rest_do_request( $mo );
ok( 502 === $mr->get_status() && 'olr_mreze_limit' === $mr->get_data()['code'], 'rest: dnevni limit ručnih preporuka vrijedi i iz ploče' );
update_option( OLR_Social::RUN_OPT, array_diff_key( OLR_Social::last_run(), array( 'rucno' => 1 ) ), false );
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$mm = wp_get_ability( 'ol-radar/radar-mreze' )->execute( array() );
	$m1 = wp_get_ability( 'ol-radar/radar-mreze' )->execute( array( 'mreza' => 'ig', 'objave' => true, 'uzori' => true ) );
	$ms = wp_get_ability( 'ol-radar/radar-stanje' )->execute( array() );
	ok( 4 === count( $mm['mreze'] ) && ! $mm['mreze']['ig']['objave'] && 8.6 === (float) $mm['mreze']['ig']['analiza']['top'][0]['omjer'] && 3 === $mm['uzori']['youtube_videa'] && 2 === count( $mm['preporuke']['preporuke'] ) && strlen( wp_json_encode( $mm ) ) < 30000, 'mcp: radar-mreze (zadano bez popisa objava i uzora)' );
	ok( array( 'ig' ) === array_keys( $m1['mreze'] ) && 9 === count( $m1['mreze']['ig']['objave'] ) && 3 === count( $m1['uzori']['yt'] ), 'mcp: radar-mreze za jednu mrežu, s objavama i uzorima' );
	ok( true === $ms['mreze']['ig']['povezano'] && 2140 === $ms['mreze']['ig']['pratitelji'] && true === $ms['stanje']['kljucevi']['tiktok'] && false === strpos( wp_json_encode( array( $mm, $m1, $ms ) ), 'tt-access' ) && false === strpos( wp_json_encode( array( $mm, $m1, $ms ) ), 'meta-test-token' ), 'mcp: radar-stanje pokazuje povezane mreže; tokeni ne izlaze' );
	wp_set_current_user( $admin[0]->ID );
}
// Prekid TikTok veze briše tokene i podatke te mreže; ostalo ostaje. Za test ploče veza se vraća.
OLR_Social::tt_disconnect();
ok( null === OLR_Social::tt_tokens() && null === OLR_Store::data( 'mreze', 'tt' ) && false === OLR_Social::connected()['tt'] && 9 === count( OLR_Store::data( 'mreze', 'ig' )['objave'] ) && 'tt-secret' === OLR_Settings::secret( 'tt_secret' ), 'tiktok: prekid veze briše tokene i podatke TikToka, ostalo ostaje' );
$au = OLR_Social::tt_authorize_url();
parse_str( (string) wp_parse_url( $au, PHP_URL_QUERY ), $aq );
ok( true === OLR_Social::tt_callback( 'kod-123', $aq['state'] ) && 6 === OLR_Social::collect_tt(), 'tiktok: ponovno povezivanje' );
OLR_Social::analyze();
$names = OLR_Settings::readable_names();
sort( $names );
ok( array( 'anthropic_key', 'dfs_login', 'dfs_password', 'gsc_sa', 'meta_token', 'tt_secret', 'yt_key' ) === $names && get_option( 'olr_keys_seen' ) == OLR_Settings::readable_names(), 'ključevi: novi ključevi mreža ulaze u provjeru nakon nadogradnje' );

// 16) Suradnje i poveznice: kandidati, prijedlozi, traženje novih tvrtki web pretragom, DR, poveznice iz Search Consolea.
$wpdb->query( "DELETE FROM " . OLR_Store::table() . " WHERE coll IN ('suradnje','suradnje_stanje','suradnje_dr')" );
foreach ( array( 'olr_suradnje_run', 'olr_suradnje_seed', 'olr_test_ai_suradnje', 'olr_test_ai_trazi', 'olr_test_dr', 'olr_test_dr_calls', 'olr_test_site_down' ) as $o ) {
	delete_option( $o );
}
OLR_Settings::update( array( 'ai_daily_limit' => 500 ) );
$S = 'OLR_Suradnje';
ok( 'primjer.hr' === $S::host( 'https://www.Primjer.hr/put?x=1' ) && '' === $S::host( 'nije domena' ) && '' === $S::host( 'http://localhost/' ), 'suradnje: domena iz adrese; neispravan unos se odbija' );
ok( $S::blocked( 'optikaljiljana.hr' ) && $S::blocked( 'jojo-optika.hr' ) && $S::blocked( 'facebook.com' ) && $S::blocked( 'seoexpress.xyz' ) && ! $S::blocked( '24sata.hr' ), 'suradnje: naša domena, konkurenti, velike platforme i prodavači poveznica nisu kandidati' );
ok( 20 === $S::seed() && 0 === $S::seed() && 20 === count( $S::all() ), 'suradnje: početni kandidati iz priložene arhive uvoze se jednom' );
$sm = $S::summary();
ok( 20 === $sm['ukupno'] && 0 === $sm['s_prijedlogom'] && 0.0 === (float) $sm['dr']['nas'] && 6 === count( $sm['dr']['domene'] ) && 'optika-anda.com' === $sm['dr']['domene'][0]['domena'] && 28.0 === (float) $sm['dr']['domene'][0]['dr'] && 'Domain Rating by Ahrefs' === $sm['dr']['izvor'] && false === $sm['dr_auto'] && null === $sm['gsc'], 'suradnje: polazni DR iz arhive (naš 0, najjači konkurent 28), s oznakom izvora; tjedna provjera DR-a je isključena' );
$dup = $S::add( array( 'domena' => 'www.24sata.hr' ), 'rucno' );
ok( ! is_wp_error( $dup ) && 'ahrefs-2026-11-12' === $dup['izvor'] && 20 === count( $S::all() ) && is_wp_error( $S::add( array( 'domena' => 'optika-anda.com' ), 'rucno' ) ), 'suradnje: ista domena se ne dodaje dvaput; konkurent se ne može dodati' );

// Prijedlozi: server prvo provjeri web i pročita javni kontakt, zatim jedan Claudeov poziv za najviše 5 kandidata.
$n0 = (int) get_option( 'olr_test_ai_calls' );
$w  = $S::write( 'rucno' );
$rq = get_option( 'olr_test_ai_last' );
$by = array();
foreach ( $S::all() as $c ) {
	$by[ $c['domena'] ] = $c;
}
ok( ! is_wp_error( $w ) && 4 === $w['napisano'] && 2 === $w['odbaceno'] && 15 === $w['preostalo'] && 1 === (int) get_option( 'olr_test_ai_calls' ) - $n0, 'suradnje: 4 prijedloga iz jednog poziva; nepotpun i nepoznata domena odbačeni (' . ( is_wp_error( $w ) ? $w->get_error_message() : wp_json_encode( $w ) ) . ')' );
$p1 = $by['24sata.hr'];
ok( true === $p1['ai'] && false !== strpos( $p1['prijedlog']['zasto'], '287 poveznica' ) && 3 === count( $p1['prijedlog']['koraci'] ) && false === strpos( $p1['prijedlog']['poruka'], '<' ) && false !== strpos( $p1['prijedlog']['poruka'], "\n\n" ) && 'urednik rubrike Zdravlje' === $p1['prijedlog']['kome'], 'suradnje: prijedlog je očišćen (najviše 3 koraka, bez HTML-a, prijelomi redaka u poruci ostaju)' );
ok( true === $p1['ziv'] && 'https://24sata.hr/kontakt/' === $p1['kontakt']['stranica'] && array( 'info@24sata.hr', 'redakcija@24sata.hr' ) === $p1['kontakt']['email'] && array( '+385 1 2345 678' ) === $p1['kontakt']['telefon'], 'suradnje: javni kontakt pročitan s weba kandidata (stranica Kontakt, e-pošta, telefon); tuđa adresa iz teksta ne ulazi' );
ok( false === $by['bug.hr']['ziv'] && empty( $by['bug.hr']['ai'] ) && empty( $by['jutarnji.hr']['ai'] ) && true === $by['jutarnji.hr']['ziv'], 'suradnje: kandidat čiji se web ne otvara ne ide Claudeu; nepotpun prijedlog ostaje za sljedeći prolaz' );
ok( ! isset( $rq['tool_choice'] ) && false !== strpos( $rq['system'], '[zadatak: suradnje i poveznice]' ) && false !== strpos( $rq['system'], 'Nikad ne predlaži kupnju poveznica' ) && false !== strpos( $rq['system'], 'Odgovori isključivo pozivom alata spremi_suradnje' ) && 6000 === $rq['max_tokens'] && 1 === count( $rq['tools'] ) && false === strpos( $rq['messages'][0]['content'], '@' ) && false === strpos( wp_json_encode( $rq ), 'sk-ant' ), 'suradnje: zahtjev Claudeu (bez prisile i web pretrage, dulji odgovor dopušten, bez e-adresa i ključeva)' );
ok( true === OLR_Settings::status()['suradnje']['ok'] && 4 === $S::summary()['s_prijedlogom'] && true === $S::summary()['kandidati'][0]['ai'], 'suradnje: stanje izvora i sažetak (kandidati s prijedlogom su prvi)' );
// Model prvi put odgovori tekstom: ponovljeni pokušaj s podsjetnikom prolazi.
update_option( 'olr_test_ai_suradnje', 'tekst' );
$n0 = (int) get_option( 'olr_test_ai_calls' );
$w2 = $S::write( 'rucno' );
ok( ! is_wp_error( $w2 ) && 3 === $w2['napisano'] && 2 === (int) get_option( 'olr_test_ai_calls' ) - $n0, 'suradnje: odgovor bez poziva alata ide ponovno i prolazi (' . ( is_wp_error( $w2 ) ? $w2->get_error_message() : wp_json_encode( $w2 ) ) . ')' );
update_option( 'olr_test_ai_suradnje', 'prekid' );
$w3 = $S::write( 'rucno' );
ok( is_wp_error( $w3 ) && 'olr_suradnje_oblik' === $w3->get_error_code() && false !== strpos( $w3->get_error_message(), 'prekinut zbog duljine' ) && false === OLR_Settings::status()['suradnje']['ok'], 'suradnje: prekinut odgovor je jasna greška u stanju izvora' );
delete_option( 'olr_test_ai_suradnje' );
$w4 = $S::write( 'rucno' );
ok( is_wp_error( $w4 ) && 'olr_suradnje_limit' === $w4->get_error_code(), 'suradnje: najviše 3 ručna pokretanja dnevno' );

// Traženje novih tvrtki: Anthropicova web pretraga, samo sa servera; kandidat ulazi tek ako mu web postoji.
$dr0 = (int) get_option( 'olr_test_dr_calls' );
$f   = $S::search( 'rucno' );
$rq  = get_option( 'olr_test_ai_last' );
$by  = array();
foreach ( $S::all() as $c ) {
	$by[ $c['domena'] ] = $c;
}
$ws  = end( $rq['tools'] );
ok( ! is_wp_error( $f ) && 2 === $f['novih'] && 4 === $f['odbaceno'] && 3 === $f['pretraga'] && 22 === count( $S::all() ), 'suradnje: web pretraga dodaje 2 nove tvrtke; nepostojeći web, već poznata domena, konkurent i društvena mreža odbačeni (' . ( is_wp_error( $f ) ? $f->get_error_message() : wp_json_encode( $f ) ) . ')' );
$np = isset( $by['poliklinika-test.hr'] ) ? $by['poliklinika-test.hr'] : null;
ok( $np && 'claude-pretraga' === $np['izvor'] && 'zdravstvo' === $np['vrsta'] && 'Poliklinika Test' === $np['naziv'] && true === $np['ai'] && array( 'info@poliklinika-test.hr', 'redakcija@poliklinika-test.hr' ) === $np['kontakt']['email'] && null === $np['dr'] && $dr0 === (int) get_option( 'olr_test_dr_calls' ), 'suradnje: novi kandidat ima prijedlog i kontakt koji je pročitao server; DR se ne provjerava dok je isključen' );
ok( 'web_search_20250305' === $ws['type'] && 5 === $ws['max_uses'] && 'HR' === $ws['user_location']['country'] && ! isset( $rq['tool_choice'] ) && 6000 === $rq['max_tokens'] && in_array( '24sata.hr', json_decode( $rq['messages'][0]['content'], true )['vec_poznato'], true ) && in_array( 'optikaljiljana.hr', json_decode( $rq['messages'][0]['content'], true )['vec_poznato'], true ), 'suradnje: zahtjev s web pretragom (najviše 5 upita, lokacija Hrvatska, poznate domene isključene)' );
ok( 3 === OLR_AI::usage()['mjeseci'][ gmdate( 'Y-m' ) ]['pretrage'] && true === OLR_Settings::status()['suradnje_trazi']['ok'], 'suradnje: web pretrage se broje u potrošnji' );
// Web pretraga nije uključena u Claude Consoleu: jasna poruka, ništa se ne mijenja.
update_option( 'olr_test_ai_trazi', 'iskljuceno' );
$f2 = $S::search( 'rucno' );
ok( is_wp_error( $f2 ) && 'olr_suradnje_pretraga' === $f2->get_error_code() && false !== strpos( $f2->get_error_message(), 'Claude Consoleu' ) && false === OLR_Settings::status()['suradnje_trazi']['ok'] && 22 === count( $S::all() ), 'suradnje: isključena web pretraga daje jasnu poruku' );
// Prekinut prolaz (pause_turn) nastavlja se s dosadašnjim sadržajem, nepromijenjenim.
OLR_Store::delete( 'suradnje', $S::id( 'poliklinika-test.hr' ) );
update_option( 'olr_test_ai_trazi', 'pauza' );
$f3 = $S::search( 'rucno' );
$rq = get_option( 'olr_test_ai_last' );
ok( ! is_wp_error( $f3 ) && 1 === $f3['novih'] && 2 === count( $rq['messages'] ) && 'assistant' === $rq['messages'][1]['role'] && 'server_tool_use' === $rq['messages'][1]['content'][0]['type'] && 'abc' === $rq['messages'][1]['content'][1]['content'][0]['encrypted_content'], 'suradnje: prekinut prolaz pretrage se nastavlja, rezultati pretrage vraćaju se API-ju nepromijenjeni' );
$f4 = $S::search( 'rucno' );
ok( is_wp_error( $f4 ) && 'olr_suradnje_limit' === $f4->get_error_code(), 'suradnje: i pretraga ima dnevno ograničenje ručnih pokretanja' );
// Pretraga bez poziva alata: jedan nastavak s izričitim zahtjevom.
$run = $S::last_run();
unset( $run['rucno'] );
update_option( 'olr_suradnje_run', $run );
OLR_Store::delete( 'suradnje', $S::id( 'klub-test.hr' ) );
update_option( 'olr_test_ai_trazi', 'bez_alata' );
$f5 = $S::search( 'rucno' );
$rq = get_option( 'olr_test_ai_last' );
ok( ! is_wp_error( $f5 ) && 1 === $f5['novih'] && 3 === count( $rq['messages'] ) && false !== strpos( $rq['messages'][2]['content'], 'Sada pozovi alat' ), 'suradnje: pretraga bez poziva alata dobiva jedan nastavak' );
delete_option( 'olr_test_ai_trazi' );
// Ploča ne može uključiti web pretragu ni dulji odgovor (REST posrednik).
$ai = new WP_REST_Request( 'POST', '/ol-radar/v1/ai' );
$ai->set_header( 'content-type', 'application/json' );
$ai->set_body( wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'web_search' => 5, 'long' => true, 'max_tokens' => 8000 ) ) );
rest_do_request( $ai );
$rq = get_option( 'olr_test_ai_last' );
ok( empty( $rq['tools'] ) && 4096 === $rq['max_tokens'], 'rest: AI posrednik ploče ne prihvaća web pretragu ni dulji odgovor' );

// Stanja i provjera dobivene poveznice.
$sid = $S::id( '24sata.hr' );
$put = new WP_REST_Request( 'PUT', '/ol-radar/v1/doc/suradnje_stanje/' . $sid );
$put->set_header( 'content-type', 'application/json' );
$put->set_body( wp_json_encode( array( 'data' => array( 'stanje' => 'dobiveno', 'url' => 'https://portal-test.hr/clanak/', 'at' => time() * 1000 ) ) ) );
ok( 200 === rest_do_request( $put )->get_status(), 'rest: ploča smije zapisati stanje suradnje' );
$gs = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/suradnje' ) )->get_data();
$c1 = array_values( array_filter( $gs['kandidati'], static function ( $c ) use ( $sid ) { return $c['id'] === $sid; } ) )[0];
ok( 'dobiveno' === $c1['stanje'] && true === $c1['veza']['ima'] && false === $c1['veza']['nofollow'] && 1 === $gs['stanja']['dobiveno'], 'suradnje: upisana adresa se provjerava (poveznica postoji i prenosi autoritet)' );
OLR_Store::set( 'suradnje_stanje', $sid, array( 'stanje' => 'dobiveno', 'url' => 'https://portal-test.hr/nofollow/' ) );
$v2 = $S::recheck( $sid );
OLR_Store::set( 'suradnje_stanje', $sid, array( 'stanje' => 'dobiveno', 'url' => 'https://portal-test.hr/bez/' ) );
$v3 = $S::recheck( $sid );
ok( true === $v2['ima'] && true === $v2['nofollow'] && false === $v3['ima'] && false !== strpos( $v3['poruka'], 'nema poveznice' ) && is_wp_error( $S::recheck( $S::id( 'vecernji.hr' ) ) ), 'suradnje: nofollow poveznica i stranica bez poveznice su prepoznate' );

// Poveznice koje vidi Google: uvoz izvoza iz Search Consolea (oba oblika).
$csv = "\xEF\xBB\xBFSite,Linking pages,Target pages\nseoexpress.xyz,120,3\nwaze.com,4,1\n24sata.hr,\"2\",1\noptikaljiljana.hr,50,10\n";
$im  = $S::import_gsc_links( $csv );
$sm  = $S::summary();
$c1  = array_values( array_filter( $sm['kandidati'], static function ( $c ) { return '24sata.hr' === $c['domena']; } ) )[0];
ok( ! is_wp_error( $im ) && 3 === $im['domena'] && 1 === $im['prodavaca'] && 'domene' === $im['vrsta'] && 3 === $sm['gsc']['domena'] && 2 === $sm['gsc']['ostalih'] && 'seoexpress.xyz' === $sm['gsc']['domene'][0]['d'] && true === $sm['gsc']['domene'][0]['prodavac'] && true === $c1['u_gsc'], 'gsc poveznice: uvoz tablice „Najčešće web-lokacije s vezama”; naša domena ne ulazi, kandidat koji nas već povezuje je označen' );
$im2 = $S::import_gsc_links( "Linking page;Last crawled\nhttps://www.24sata.hr/clanak-1;2026-11-01\nhttps://www.24sata.hr/clanak-2;2026-11-05\nhttps://novi-portal.hr/tekst;2026-11-10\n" );
$g2  = OLR_Store::data( 'suradnje_dr', 'gsc' );
ok( ! is_wp_error( $im2 ) && 2 === $im2['domena'] && 'stranice' === $im2['vrsta'] && 2 === $g2['domene'][0]['stranica'] && '2026-11-05' === $g2['domene'][0]['zadnje'] && array( 'novi-portal.hr' ) === $g2['nove'] && 1 === count( $g2['povijest'] ), 'gsc poveznice: uvoz tablice „Najnovije veze” (stranice se zbrajaju po domeni; nova domena od prošlog uvoza)' );
ok( is_wp_error( $S::import_gsc_links( "Upit,Klikovi\nprogresivne naočale,12\n" ) ) && 2 === count( OLR_Store::data( 'suradnje_dr', 'gsc' )['domene'] ), 'gsc poveznice: kriva datoteka se odbija i ne briše prethodni uvoz' );

// Ručno dodan kandidat i DR (Ahrefsov besplatni javni servis).
$run = $S::last_run();
unset( $run['rucno'] );
update_option( 'olr_suradnje_run', $run );
OLR_Store::delete( 'suradnje', $S::id( 'klub-test.hr' ) );
$am = $S::add_manual( 'https://www.klub-test.hr/', 'Klub Test', 'sport' );
ok( ! is_wp_error( $am ) && 'rucno' === $am['izvor'] && 'sport' === $am['vrsta'] && true === $am['ziv'] && is_wp_error( $S::add_manual( 'klub-test.hr' ) ) && is_wp_error( $S::add_manual( 'optikaljiljana.hr' ) ) && is_wp_error( $S::add_manual( 'nema-weba-test.hr' ) ), 'suradnje: ručno dodan kandidat (web provjeren odmah); duplikat i naša domena se odbijaju, web koji se ne otvara je dojavljen' );
$h = OLR_Store::data( 'suradnje_dr', 'hist' );
$h['datumi'] = array( '2026-11-01' );
OLR_Store::set( 'suradnje_dr', 'hist', $h );
$dr = $S::dr_refresh();
$sm = $S::summary();
$me = array_values( array_filter( $sm['dr']['domene'], static function ( $x ) { return $x['nasa']; } ) )[0];
ok( ! is_wp_error( $dr ) && 1.4 === (float) $sm['dr']['nas'] && 0.0 === (float) $me['prije'] && 2 === count( $sm['dr']['datumi'] ) && 29.0 === (float) $sm['dr']['domene'][0]['dr'] && true === OLR_Settings::status()['suradnje_dr']['ok'] && 31.0 === (float) OLR_Store::data( 'suradnje', $S::id( 'poliklinika-test.hr' ) )['dr'], 'dr: provjera za nas i konkurente ulazi u povijest (0 → 1,4); kandidati bez DR-a ga dobivaju' );
update_option( 'olr_test_dr', 'targets' );
ok( 27.0 === (float) $S::dr_fetch( 'jojo-optika.hr' ), 'dr: čita i drugi oblik odgovora' );
update_option( 'olr_test_dr', 'kljuc' );
$dk = $S::dr_refresh();
ok( is_wp_error( $dk ) && false !== strpos( $dk->get_error_message(), 'bez ključa' ) && false === OLR_Settings::status()['suradnje_dr']['ok'] && 1.4 === (float) $S::summary()['dr']['nas'], 'dr: ako servis zatraži ključ, greška je jasna i povijest ostaje' );
delete_option( 'olr_test_dr' );

// Satni prolaz: tjedno prijedlozi; DR i web pretraga samo ako su uključeni.
$run = $S::last_run();
foreach ( array( 'pisi_at', 'pisi_pokusaj', 'trazi_at', 'trazi_pokusaj', 'dr_at', 'dr_pokusaj' ) as $k ) {
	$run[ $k ] = time() - 9 * DAY_IN_SECONDS;
}
update_option( 'olr_suradnje_run', $run );
$n0  = (int) get_option( 'olr_test_ai_calls' );
$dr0 = (int) get_option( 'olr_test_dr_calls' );
$S::tick();
$S::tick();
$run = $S::last_run();
ok( 1 === (int) get_option( 'olr_test_ai_calls' ) - $n0 && $dr0 === (int) get_option( 'olr_test_dr_calls' ) && 'auto' === $run['pisi']['nacin'] && time() - $run['pisi_at'] < 60 && time() - $run['trazi_at'] > 8 * DAY_IN_SECONDS, 'suradnje: satni prolaz jednom tjedno piše prijedloge; web pretraga i DR ne idu dok nisu uključeni' );
OLR_Settings::update( array( 'suradnje_trazi' => true, 'suradnje_dr' => true ) );
$S::tick();
$a1 = (int) get_option( 'olr_test_ai_calls' ) - $n0;
$d1 = (int) get_option( 'olr_test_dr_calls' ) - $dr0;
$S::tick();
ok( 1 === $a1 && $d1 >= 6 && 2 === (int) get_option( 'olr_test_ai_calls' ) - $n0 && time() - $S::last_run()['trazi_at'] < 60, 'suradnje: kad su uključeni, DR i web pretraga idu svaki u svom satu' );
OLR_Settings::update( array( 'suradnje_trazi' => false, 'suradnje_dr' => false ) );

// REST i MCP.
$resp = rest_do_request( new WP_REST_Request( 'GET', '/ol-radar/v1/podaci' ) )->get_data();
ok( isset( $resp['suradnje']['kandidati'] ) && $resp['suradnje']['ukupno'] >= 22 && isset( $resp['stanje']['suradnje']['zadnje']['pisi_at'] ) && ! isset( $resp['stanje']['suradnje']['zadnje']['rucno'] ) && isset( $resp['stanje']['ai']['trosak']['mjeseci'][0]['usd'] ), 'rest: podaci ploče sadrže suradnje i Claudeov trošak' );
$os = new WP_REST_Request( 'POST', '/ol-radar/v1/osvjezi' );
$os->set_param( 'dio', 'suradnje_trazi' );
$or = rest_do_request( $os );
ok( 400 === $or->get_status() && 'olr_potvrda' === $or->get_data()['code'], 'rest: web pretraga iz ploče traži potvrdu troška' );
$os = new WP_REST_Request( 'POST', '/ol-radar/v1/osvjezi' );
$os->set_param( 'dio', 'suradnje_gsc' );
$os->set_param( 'csv', "Site,Linking pages,Target pages\nwaze.com,4,1\n24sata.hr,2,1\nnovi-portal.hr,1,1\n" );
$or = rest_do_request( $os )->get_data();
ok( true === $or['ok'] && 3 === $or['rezultat']['ishod']['domena'] && 3 === $or['rezultat']['suradnje']['gsc']['domena'], 'rest: uvoz poveznica iz Search Consolea iz ploče' );
if ( $reader ) {
	wp_set_current_user( $reader[0]->ID );
	$ms = wp_get_ability( 'ol-radar/radar-suradnje' )->execute( array() );
	$m2 = wp_get_ability( 'ol-radar/radar-suradnje' )->execute( array( 'svi' => true, 'limit' => 100 ) );
	$m3 = wp_get_ability( 'ol-radar/radar-suradnje' )->execute( array( 'stanje' => 'dobiveno' ) );
	$mt = wp_get_ability( 'ol-radar/radar-stanje' )->execute( array() );
	ok( $ms['prikazano'] === $ms['s_prijedlogom'] && $m2['prikazano'] === $m2['ukupno'] && 1 === $m3['prikazano'] && '24sata.hr' === $m3['kandidati'][0]['domena'] && isset( $ms['dr']['nas'] ) && strlen( wp_json_encode( $ms ) ) < 40000, 'mcp: radar-suradnje (zadano kandidati s prijedlogom; svi i po stanju)' );
	ok( $mt['suradnje']['kandidata'] === $m2['ukupno'] && 3 === $mt['suradnje']['google_vidi_domena'] && isset( $mt['stanje']['ai']['trosak']['danas']['usd'] ) && ! isset( $mt['stanje']['ai']['potrosnja'] ), 'mcp: radar-stanje ima sažetak suradnji i Claudeov trošak' );
	wp_set_current_user( $admin[0]->ID );
}

// 17) Claude trošak: iz stvarnih tokena svakog poziva, po cjeniku modela.
ok( 24.02 === round( OLR_AI::cost( 'claude-opus-5-5', 1000000, 1000000, 2 ), 4 ) && 12.0 === round( OLR_AI::cost( 'claude-sonnet-5-5-20260929', 1000000, 1000000 ), 4 ) && 0.015 === round( OLR_AI::cost( 'claude-haiku-5-5', 100000, 10000 ), 6 ) && 0.125 === round( OLR_AI::cost( 'claude-haiku-5-5', 200000, 10000 ), 6 ) && null === OLR_AI::cost( 'nepoznat-model', 10, 10 ), 'trošak: cjenik po modelu (Opus 4/20, Sonnet 2/10, Haiku 0,10/0,50 i skuplje iznad 100.000 tokena, pretraga 0,01); nepoznat model nema cijenu' );
$mon = gmdate( 'Y-m' );
update_option( 'olr_ai_usage', array( 'dani' => array(), 'mjeseci' => array( '2026-01' => array( 'pozivi' => 3, 'ulaz' => 500000, 'izlaz' => 100000 ), $mon => array( 'pozivi' => 10, 'ulaz' => 100000, 'izlaz' => 20000 ) ) ) );
OLR_Settings::update( array( 'ai_model' => 'claude-opus-5-5' ) );
OLR_AI::test();
$cs = OLR_AI::cost_summary();
// Prije: 100.000 × 4 + 20.000 × 20 = 0,80 $ (procjena). Novi poziv: 80 × 4 + 12 × 20 = 0,00056 $.
ok( $mon === $cs['mjeseci'][0]['mjesec'] && 11 === $cs['mjeseci'][0]['pozivi'] && 0.8006 === $cs['mjeseci'][0]['usd'] && true === $cs['mjeseci'][0]['procjena'] && 0.0006 === $cs['danas']['usd'] && 1 === $cs['danas']['pozivi'] && 4.0 === (float) $cs['cijena']['ulaz'], 'trošak: mjesec s pozivima prije 2.4.0 je procjena kojoj se dodaju točni iznosi novih poziva' );
ok( '2026-01' === $cs['mjeseci'][1]['mjesec'] && 4.0 === $cs['mjeseci'][1]['usd'] && true === $cs['mjeseci'][1]['procjena'] && false !== strpos( OLR_Settings::status()['ai_poziv']['msg'], '0,0006 $' ), 'trošak: stariji mjeseci procijenjeni po sadašnjem modelu; iznos zadnjeg poziva je u stanju izvora' );
OLR_Settings::update( array( 'ai_model' => 'claude-sonnet-5-5', 'ai_daily_limit' => 150 ) );
delete_option( 'olr_ai_usage' );

// Vrati osnovne Google podatke (ostali testovi računaju s njima); prijedlozi ostaju za test ploče.
delete_option( 'olr_test_gsc_extra' );
OLR_GSC::collect();
wp_clear_scheduled_hook( OLR_Prijedlozi::EVENT );

delete_option( 'olr_test_mocks' );
echo $fails ? "\nNEUSPJELO: {$fails}\n" : "\nSVE PROŠLO\n";
exit( $fails ? 1 : 0 );
