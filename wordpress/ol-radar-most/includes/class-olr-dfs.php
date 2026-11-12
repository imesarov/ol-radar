<?php
/**
 * Pozicije preko DataForSEO API-ja (Google organski rezultati, standardni red: najjeftiniji način).
 * Mjerenje: zadaci se pošalju (task_post), a rezultati se pokupe u sljedećim satnim prolazima (task_get).
 * Svako mjerenje usput sprema i top 20 organskih rezultata po riječi, za model konkurencije.
 *
 * Cijena (dataforseo.com, studeni 2026.): 0,0006 $ po stranici od 10 rezultata u standardnom redu;
 * dubina 40 = 4 stranice. Stvarni trošak svakog mjerenja sprema se iz odgovora API-ja.
 */

defined( 'ABSPATH' ) || exit;

class OLR_DFS {

	const API          = 'https://api.dataforseo.com/v3/';
	const PENDING      = 'olr_rank_pending';
	const PRICE_PAGE   = 0.0006;
	const MAX_KEYWORDS = 100;
	const MAX_RUNS     = 26;

	public static function has_credentials() {
		return OLR_Settings::secret( 'dfs_login' ) && OLR_Settings::secret( 'dfs_password' );
	}

	public static function kw_id( $kw ) {
		return 'hr-' . substr( md5( self::lower( $kw ) ), 0, 12 );
	}

	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $s ), 'UTF-8' ) : strtolower( trim( (string) $s ) );
	}

	public static function keywords() {
		$k   = OLR_Settings::get( 'rank_keywords' );
		$out = array();
		foreach ( (array) $k as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w && ! in_array( self::lower( $w ), array_map( array( __CLASS__, 'lower' ), $out ), true ) ) {
				$out[] = $w;
			}
		}
		return array_slice( $out, 0, self::MAX_KEYWORDS );
	}

	/** Procjena troška jednog mjerenja u USD. */
	public static function estimate() {
		$pages = (int) ceil( max( 10, (int) OLR_Settings::get( 'rank_depth' ) ) / 10 );
		return round( count( self::keywords() ) * $pages * self::PRICE_PAGE, 4 );
	}

	private static function request( $method, $path, $body = null ) {
		$login = OLR_Settings::secret( 'dfs_login' );
		$pass  = OLR_Settings::secret( 'dfs_password' );
		if ( ! $login || ! $pass ) {
			return new WP_Error( 'olr_dfs_nema', 'DataForSEO pristup nije postavljen (OL Radar → Postavke).' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'olr_dfs_mreza', 'DataForSEO: ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code ) {
			return new WP_Error( 'olr_dfs_prijava', 'DataForSEO je odbio prijavu: provjeri API login i lozinku (nije lozinka za web sučelje ako si postavio zasebnu).' );
		}
		if ( 402 === $code || ( isset( $json['status_code'] ) && 40200 === (int) $json['status_code'] ) ) {
			return new WP_Error( 'olr_dfs_saldo', 'DataForSEO: na računu nema dovoljno sredstava.' );
		}
		if ( 200 !== $code || ! is_array( $json ) ) {
			return new WP_Error( 'olr_dfs_api', 'DataForSEO: HTTP ' . $code );
		}
		if ( isset( $json['status_code'] ) && 20000 !== (int) $json['status_code'] ) {
			return new WP_Error( 'olr_dfs_api', 'DataForSEO: ' . ( isset( $json['status_message'] ) ? $json['status_message'] : $json['status_code'] ) );
		}
		return $json;
	}

	/** Provjera pristupa bez troška (podaci o računu). */
	public static function test() {
		$j = self::request( 'GET', 'appendix/user_data' );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$r = isset( $j['tasks'][0]['result'][0] ) ? $j['tasks'][0]['result'][0] : array();
		$b = isset( $r['money']['balance'] ) ? (float) $r['money']['balance'] : null;
		return null === $b ? 'Prijava radi.' : sprintf( 'Prijava radi. Stanje računa: %.2f $.', $b );
	}

	/** Pokreće novo mjerenje (ako nijedno nije u tijeku). Trošak je stvaran: zove se samo na izričit zahtjev ili po uključenom tjednom rasporedu. */
	public static function start_run( $reason = 'rucno' ) {
		$pending = get_option( self::PENDING );
		if ( $pending ) {
			return new WP_Error( 'olr_dfs_u_tijeku', 'Mjerenje je već u tijeku (' . $pending . ').' );
		}
		$kws = self::keywords();
		if ( ! $kws ) {
			return new WP_Error( 'olr_dfs_rijeci', 'Popis ciljanih riječi je prazan.' );
		}
		$run_id = gmdate( 'Ymd-His' );
		$device = 'mobile' === OLR_Settings::get( 'rank_device' ) ? 'mobile' : 'desktop';
		$tasks  = array();
		foreach ( $kws as $i => $kw ) {
			$tasks[] = array(
				'keyword'       => $kw,
				'location_name' => OLR_Settings::get( 'rank_location' ),
				'language_code' => OLR_Settings::get( 'rank_language' ),
				'device'        => $device,
				'os'            => 'mobile' === $device ? 'android' : 'windows',
				'depth'         => max( 10, min( 100, (int) OLR_Settings::get( 'rank_depth' ) ) ),
				'tag'           => 'olr|' . $run_id . '|' . $i,
			);
		}
		$j = self::request( 'POST', 'serp/google/organic/task_post', $tasks );
		if ( is_wp_error( $j ) ) {
			OLR_Settings::set_status( 'rank', false, $j->get_error_message() );
			return $j;
		}
		$map  = array();
		$cost = isset( $j['cost'] ) ? (float) $j['cost'] : 0.0;
		$errs = array();
		foreach ( (array) $j['tasks'] as $t ) {
			if ( isset( $t['status_code'] ) && 20100 === (int) $t['status_code'] && ! empty( $t['id'] ) ) {
				$kw         = isset( $t['data']['keyword'] ) ? $t['data']['keyword'] : '';
				$map[ $t['id'] ] = $kw;
			} else {
				$errs[] = ( isset( $t['data']['keyword'] ) ? $t['data']['keyword'] . ': ' : '' ) . ( isset( $t['status_message'] ) ? $t['status_message'] : 'greška' );
			}
		}
		if ( ! $map ) {
			$e = new WP_Error( 'olr_dfs_zadaci', 'DataForSEO nije prihvatio zadatke: ' . implode( '; ', array_slice( $errs, 0, 3 ) ) );
			OLR_Settings::set_status( 'rank', false, $e->get_error_message() );
			return $e;
		}
		OLR_Store::set(
			'rank_run',
			$run_id,
			array(
				'run'      => $run_id,
				'razlog'   => $reason,
				'pocetak'  => gmdate( 'c' ),
				'zadaci'   => $map,
				'rezultati' => array(),
				'gotovo'   => false,
				'trosak'   => $cost,
				'greske'   => $errs,
				'postavke' => array(
					'lokacija' => OLR_Settings::get( 'rank_location' ),
					'jezik'    => OLR_Settings::get( 'rank_language' ),
					'uredaj'   => $device,
					'dubina'   => max( 10, min( 100, (int) OLR_Settings::get( 'rank_depth' ) ) ),
				),
			)
		);
		update_option( self::PENDING, $run_id, false );
		self::add_cost( $cost );
		OLR_Settings::set_status( 'rank', true, 'mjerenje poslano (' . count( $map ) . ' riječi), rezultati za nekoliko minuta', array( 'u_tijeku' => $run_id ) );
		return array(
			'run'    => $run_id,
			'zadaci' => count( $map ),
			'trosak' => $cost,
		);
	}

	private static function add_cost( $cost ) {
		if ( $cost <= 0 ) {
			return;
		}
		$c = get_option( 'olr_dfs_cost', array() );
		$c = is_array( $c ) ? $c : array();
		$m = gmdate( 'Y-m' );
		$c[ $m ] = round( ( isset( $c[ $m ] ) ? (float) $c[ $m ] : 0 ) + $cost, 5 );
		update_option( 'olr_dfs_cost', $c, false );
	}

	/** Je li domena naša (i poddomene). */
	private static function is_ours( $domain ) {
		$own = strtolower( OLR_Settings::get( 'domain' ) );
		$d   = strtolower( preg_replace( '/^www\./', '', (string) $domain ) );
		return $d === $own || substr( $d, -strlen( '.' . $own ) ) === '.' . $own;
	}

	/** Iz jednog rezultata: naša pozicija, URL i top 20 organskih rezultata. */
	public static function parse_result( $result ) {
		$pos   = null;
		$url   = null;
		$top   = array();
		$local = false;
		foreach ( (array) ( isset( $result['items'] ) ? $result['items'] : array() ) as $it ) {
			$type = isset( $it['type'] ) ? $it['type'] : '';
			if ( 'local_pack' === $type ) {
				$local = true;
			}
			if ( 'organic' !== $type ) {
				continue;
			}
			$rank = isset( $it['rank_group'] ) ? (int) $it['rank_group'] : null;
			$dom  = isset( $it['domain'] ) ? $it['domain'] : '';
			if ( null === $pos && self::is_ours( $dom ) ) {
				$pos = $rank;
				$url = isset( $it['url'] ) ? $it['url'] : null;
			}
			if ( count( $top ) < 20 ) {
				$top[] = array(
					'pos'   => $rank,
					'url'   => isset( $it['url'] ) ? $it['url'] : '',
					'title' => isset( $it['title'] ) ? $it['title'] : '',
					'type'  => array( 'organic' ),
				);
			}
		}
		return array(
			'pos'        => $pos,
			'url'        => $url,
			'top'        => $top,
			'lokalni'    => $local,
			'provjereno' => isset( $result['datetime'] ) ? $result['datetime'] : null,
		);
	}

	/** Pokupi gotove rezultate mjerenja u tijeku; kad su svi tu, izračunaj pozicije i povijest. */
	public static function poll( $max_gets = 40 ) {
		$run_id = get_option( self::PENDING );
		if ( ! $run_id ) {
			return array( 'u_tijeku' => false );
		}
		$run = OLR_Store::data( 'rank_run', $run_id );
		if ( ! $run ) {
			delete_option( self::PENDING );
			return array( 'u_tijeku' => false );
		}
		$gets = 0;
		foreach ( $run['zadaci'] as $task_id => $kw ) {
			if ( isset( $run['rezultati'][ $task_id ] ) || $gets >= $max_gets ) {
				continue;
			}
			$gets++;
			$j = self::request( 'GET', 'serp/google/organic/task_get/advanced/' . rawurlencode( $task_id ) );
			if ( is_wp_error( $j ) ) {
				if ( 'olr_dfs_api' === $j->get_error_code() ) {
					continue; // API je odgovorio greškom za cijeli poziv; pokušaj u sljedećem prolazu.
				}
				OLR_Settings::set_status( 'rank', false, $j->get_error_message(), array( 'u_tijeku' => $run_id ) );
				return $j;
			}
			$t    = isset( $j['tasks'][0] ) ? $j['tasks'][0] : array();
			$code = isset( $t['status_code'] ) ? (int) $t['status_code'] : 0;
			if ( 20000 === $code && isset( $t['result'][0] ) ) {
				$run['rezultati'][ $task_id ] = self::parse_result( $t['result'][0] );
			} elseif ( 40102 === $code ) {
				$run['rezultati'][ $task_id ] = array( 'pos' => null, 'url' => null, 'top' => array(), 'lokalni' => false, 'provjereno' => null, 'napomena' => 'nema rezultata' );
			} elseif ( $code >= 40600 && $code < 40700 ) {
				continue; // još u redu
			} else {
				$run['rezultati'][ $task_id ] = array( 'pos' => null, 'url' => null, 'top' => array(), 'greska' => isset( $t['status_message'] ) ? $t['status_message'] : (string) $code );
			}
		}
		$done    = count( $run['rezultati'] ) >= count( $run['zadaci'] );
		$expired = ( time() - strtotime( $run['pocetak'] ) ) > 2 * DAY_IN_SECONDS;
		if ( $done || $expired ) {
			$run['gotovo'] = true;
			$run['kraj']   = gmdate( 'c' );
			OLR_Store::set( 'rank_run', $run_id, $run );
			delete_option( self::PENDING );
			self::finalize( $run );
			return array(
				'u_tijeku' => false,
				'gotovo'   => $run_id,
			);
		}
		OLR_Store::set( 'rank_run', $run_id, $run );
		OLR_Settings::set_status( 'rank', true, 'mjerenje u tijeku: ' . count( $run['rezultati'] ) . ' od ' . count( $run['zadaci'] ), array( 'u_tijeku' => $run_id ) );
		return array(
			'u_tijeku' => true,
			'gotovo'   => count( $run['rezultati'] ),
			'ukupno'   => count( $run['zadaci'] ),
		);
	}

	/** Zadnje pozicije, povijest (do 26 mjerenja) i SERP-ovi za model konkurencije. */
	public static function finalize( $run ) {
		$prev_latest = OLR_Store::data( 'rank', 'latest', array() );
		$prev        = array();
		foreach ( isset( $prev_latest['rows'] ) ? $prev_latest['rows'] : array() as $r ) {
			$prev[ $r['id'] ] = $r['pos'];
		}
		$rows = array();
		foreach ( $run['zadaci'] as $task_id => $kw ) {
			$res    = isset( $run['rezultati'][ $task_id ] ) ? $run['rezultati'][ $task_id ] : null;
			$id     = self::kw_id( $kw );
			$rows[] = array(
				'id'      => $id,
				'kw'      => $kw,
				'pos'     => $res ? $res['pos'] : null,
				'prev'    => array_key_exists( $id, $prev ) ? $prev[ $id ] : null,
				'url'     => $res ? $res['url'] : null,
				'lokalni' => $res ? ! empty( $res['lokalni'] ) : false,
				'checked' => (bool) $res && empty( $res['greska'] ),
			);
			if ( $res && ! empty( $res['top'] ) ) {
				OLR_Store::set(
					'serp',
					$id,
					array(
						'keyword' => self::lower( $kw ),
						'country' => 'hr',
						'at'      => strtotime( $run['pocetak'] ) * 1000,
						'izvor'   => 'DataForSEO',
						'p'       => $res['top'],
					)
				);
			}
		}
		$label = gmdate( 'Y-m-d H:i', strtotime( $run['pocetak'] ) );
		$hist  = OLR_Store::data( 'rank', 'hist', array( 'runs' => array(), 'by' => array() ) );
		array_unshift( $hist['runs'], $label );
		foreach ( $rows as $r ) {
			$old = isset( $hist['by'][ $r['id'] ] ) ? $hist['by'][ $r['id'] ] : array_fill( 0, count( $hist['runs'] ) - 1, null );
			// 0 = nije pronađen u rezultatima; null = nije mjereno.
			array_unshift( $old, $r['checked'] ? ( null === $r['pos'] ? 0 : $r['pos'] ) : null );
			$hist['by'][ $r['id'] ] = array_slice( $old, 0, self::MAX_RUNS );
		}
		foreach ( $hist['by'] as $id => $vals ) {
			if ( count( $vals ) < count( $hist['runs'] ) ) {
				array_unshift( $vals, null );
				$hist['by'][ $id ] = array_slice( $vals, 0, self::MAX_RUNS );
			}
		}
		$hist['runs'] = array_slice( $hist['runs'], 0, self::MAX_RUNS );
		OLR_Store::set( 'rank', 'hist', $hist );
		OLR_Store::set(
			'rank',
			'latest',
			array(
				'rows'   => $rows,
				'last'   => $run['pocetak'],
				'sched'  => ( OLR_Settings::get( 'rank_weekly' ) ? 'tjedno' : 'ručno' ) . ', ' . $run['postavke']['uredaj'] . ', ' . $run['postavke']['lokacija'] . ', dubina ' . $run['postavke']['dubina'],
				'trosak' => $run['trosak'],
				'run'    => $run['run'],
			)
		);
		$found = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return null !== $r['pos'];
				}
			)
		);
		OLR_Settings::set_status( 'rank', true, 'mjereno ' . $label . ' UTC: ' . $found . ' od ' . count( $rows ) . ' riječi pronađeno', array( 'zadnje' => $run['pocetak'] ) );
	}

	/** Tjedni raspored: nedjelja od 22 h po lokalnom vremenu, ili nadoknada ako je prošlo više od 8 dana. */
	public static function maybe_weekly() {
		if ( ! OLR_Settings::get( 'rank_weekly' ) || ! self::has_credentials() || get_option( self::PENDING ) ) {
			return;
		}
		$latest = OLR_Store::data( 'rank', 'latest' );
		$age    = $latest && ! empty( $latest['last'] ) ? time() - strtotime( $latest['last'] ) : PHP_INT_MAX;
		$now    = new DateTime( 'now', wp_timezone() );
		$sunday = '0' === $now->format( 'w' ) && (int) $now->format( 'G' ) >= 22;
		if ( ( $sunday && $age > 6 * DAY_IN_SECONDS ) || $age > 8 * DAY_IN_SECONDS ) {
			self::start_run( 'tjedno' );
		}
	}
}
