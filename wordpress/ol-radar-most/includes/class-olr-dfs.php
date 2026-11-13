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
	/** Doplata po riječi kad se traže i AI pregledi koji se učitavaju naknadno (naplaćuje se samo kad takav postoji). */
	const PRICE_AIO    = 0.0006;
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
		$n     = count( self::keywords() );
		return round( $n * $pages * self::PRICE_PAGE + ( OLR_Settings::get( 'rank_aio_async' ) ? $n * self::PRICE_AIO : 0 ), 4 );
	}

	/**
	 * Poziv DataForSEO API-ja. Javno od 2.5.0 (koristi ga i OLR_Trziste).
	 *
	 * @param int $timeout Sekunde; poziv AI modela (LLM Responses) traje do dvije minute.
	 */
	public static function request( $method, $path, $body = null, $timeout = 45 ) {
		$login = OLR_Settings::secret( 'dfs_login' );
		$pass  = OLR_Settings::secret( 'dfs_password' );
		if ( ! $login || ! $pass ) {
			return new WP_Error( 'olr_dfs_nema', 'DataForSEO pristup nije postavljen (OL Radar → Postavke).' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
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
		if ( OLR_Store::get( 'rank_run', $run_id ) ) {
			$run_id .= '-' . wp_rand( 10, 99 ); // dva mjerenja u istoj sekundi ne smiju pregaziti jedno drugo
		}
		$device = 'mobile' === OLR_Settings::get( 'rank_device' ) ? 'mobile' : 'desktop';
		$tasks  = array();
		$aio    = (bool) OLR_Settings::get( 'rank_aio_async' );
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
			if ( $aio ) {
				// AI pregled koji Google učitava naknadno; bez ovoga stižu samo oni koji su odmah u rezultatu.
				$tasks[ count( $tasks ) - 1 ]['load_async_ai_overview'] = true;
			}
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

	public static function add_cost( $cost ) {
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
	public static function is_ours( $domain ) {
		$own = strtolower( OLR_Settings::get( 'domain' ) );
		$d   = strtolower( preg_replace( '/^www\./', '', (string) $domain ) );
		return $d === $own || substr( $d, -strlen( '.' . $own ) ) === '.' . $own;
	}

	/** Domena bez „www.” iz adrese ili naziva domene. */
	public static function host( $url ) {
		$h = false !== strpos( (string) $url, '/' ) ? wp_parse_url( (string) $url, PHP_URL_HOST ) : (string) $url;
		return $h ? preg_replace( '/^www\./', '', strtolower( $h ) ) : '';
	}

	/**
	 * Googleov AI pregled (AI Overview) iz organskog rezultata: postoji li, je li učitan i koje izvore citira.
	 * Izvori su i na razini cijelog pregleda i uz pojedine odlomke; ovdje se spajaju u popis domena.
	 */
	public static function parse_aio( $it ) {
		$refs = array();
		$add  = static function ( $list ) use ( &$refs ) {
			foreach ( is_array( $list ) ? $list : array() as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$u = isset( $r['url'] ) && is_string( $r['url'] ) ? $r['url'] : '';
				$h = self::host( '' !== $u ? $u : ( isset( $r['domain'] ) ? (string) $r['domain'] : '' ) );
				if ( '' !== $h && ! isset( $refs[ $h ] ) ) {
					$refs[ $h ] = $u;
				}
			}
		};
		$add( isset( $it['references'] ) ? $it['references'] : array() );
		foreach ( isset( $it['items'] ) && is_array( $it['items'] ) ? $it['items'] : array() as $el ) {
			$add( is_array( $el ) && isset( $el['references'] ) ? $el['references'] : array() );
		}
		$ours = null;
		foreach ( $refs as $h => $u ) {
			if ( self::is_ours( $h ) ) {
				$ours = '' !== $u ? $u : $h;
				break;
			}
		}
		return array(
			'ima'         => true,
			'ucitan'      => (bool) $refs || ! empty( $it['markdown'] ) || ! empty( $it['items'] ),
			'mi'          => null !== $ours,
			'nasa_adresa' => $ours,
			'izvori'      => array_slice( array_keys( $refs ), 0, 8 ),
		);
	}

	/** Iz jednog rezultata: naša pozicija, URL, top 20 organskih rezultata i AI pregled. */
	public static function parse_result( $result ) {
		$pos   = null;
		$url   = null;
		$top   = array();
		$local = false;
		$aio   = null;
		foreach ( (array) ( isset( $result['items'] ) ? $result['items'] : array() ) as $it ) {
			$type = isset( $it['type'] ) ? $it['type'] : '';
			if ( 'local_pack' === $type ) {
				$local = true;
			}
			if ( 'ai_overview' === $type && is_array( $it ) ) {
				$aio = self::parse_aio( $it );
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
		// Google pokazuje AI pregled, ali ga nije isporučio odmah (učitava se naknadno): znamo da postoji, ne i izvore.
		if ( null === $aio && isset( $result['item_types'] ) && is_array( $result['item_types'] ) && in_array( 'ai_overview', $result['item_types'], true ) ) {
			$aio = array(
				'ima'         => true,
				'ucitan'      => false,
				'mi'          => false,
				'nasa_adresa' => null,
				'izvori'      => array(),
			);
		}
		return array(
			'pos'        => $pos,
			'url'        => $url,
			'top'        => $top,
			'lokalni'    => $local,
			'aio'        => $aio,
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
				// AI pregled: null = u ovom rezultatu ga nema; inače ima li ga, je li učitan i citira li nas.
				'aio'     => $res && ! empty( $res['aio'] ) ? $res['aio'] : null,
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
			// Povijest AI pregleda: 0 = nema ga, 1 = ima ga, 2 = ima ga i citira nas, null = nije mjereno.
			$olda = isset( $hist['aio'][ $r['id'] ] ) ? $hist['aio'][ $r['id'] ] : array_fill( 0, count( $hist['runs'] ) - 1, null );
			array_unshift( $olda, $r['checked'] ? ( $r['aio'] ? ( ! empty( $r['aio']['mi'] ) ? 2 : 1 ) : 0 ) : null );
			$hist['aio'][ $r['id'] ] = array_slice( $olda, 0, self::MAX_RUNS );
		}
		foreach ( $hist['by'] as $id => $vals ) {
			if ( count( $vals ) < count( $hist['runs'] ) ) {
				array_unshift( $vals, null );
				$hist['by'][ $id ] = array_slice( $vals, 0, self::MAX_RUNS );
			}
		}
		foreach ( isset( $hist['aio'] ) ? $hist['aio'] : array() as $id => $vals ) {
			if ( count( $vals ) < count( $hist['runs'] ) ) {
				array_unshift( $vals, null );
				$hist['aio'][ $id ] = array_slice( $vals, 0, self::MAX_RUNS );
			}
		}
		$hist['runs'] = array_slice( $hist['runs'], 0, self::MAX_RUNS );
		OLR_Store::set( 'rank', 'hist', $hist );
		self::competitors( true );
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

	/** Očekivani udio klikova za poziciju (opća krivulja), za usporedbu vidljivosti domena. */
	private static function ctr( $pos ) {
		$p = (int) $pos;
		if ( $p < 1 ) {
			return 0.0;
		}
		return $p <= 10 ? OLR_Analiza::CTR[ $p ] : ( $p <= 20 ? 0.008 : 0.002 );
	}

	/** Po domeni za jedno mjerenje: broj riječi u top 3, 10 i 20, zbroj očekivanih klikova i najbolja pozicija po riječi. */
	private static function run_domains( $run ) {
		$dom = array();
		foreach ( isset( $run['zadaci'] ) ? (array) $run['zadaci'] : array() as $task_id => $kw ) {
			$res = isset( $run['rezultati'][ $task_id ] ) ? $run['rezultati'][ $task_id ] : null;
			if ( ! $res || empty( $res['top'] ) ) {
				continue;
			}
			$seen = array();
			foreach ( $res['top'] as $t ) {
				$h = self::host( isset( $t['url'] ) ? $t['url'] : '' );
				if ( '' === $h || isset( $seen[ $h ] ) ) {
					continue; // po riječi se računa samo najbolji rezultat domene
				}
				$seen[ $h ] = true;
				$pos        = (int) $t['pos'];
				if ( ! isset( $dom[ $h ] ) ) {
					$dom[ $h ] = array( 'n3' => 0, 'n10' => 0, 'n20' => 0, 'vid' => 0.0, 'kw' => array() );
				}
				$dom[ $h ]['n3']      += $pos <= 3 ? 1 : 0;
				$dom[ $h ]['n10']     += $pos <= 10 ? 1 : 0;
				$dom[ $h ]['n20']     += $pos <= 20 ? 1 : 0;
				$dom[ $h ]['vid']     += self::ctr( $pos );
				$dom[ $h ]['kw'][ $kw ] = array( $pos, $t['url'] );
			}
		}
		return $dom;
	}

	/**
	 * Kretanje konkurenata iz naših mjerenja: po domeni broj riječi u top 3 i top 10 te udio vidljivosti
	 * (procjena udjela klikova među izmjerenim riječima, bez volumena pretraga), sada i u prethodnom mjerenju,
	 * te po riječi tko je ušao u top 10, tko je ispao i tko nas je pretekao.
	 *
	 * @param bool $rebuild Izračunaj iznova (nakon novog mjerenja); inače vrati spremljeno ili izračunaj ako ga nema.
	 * @return array|null
	 */
	public static function competitors( $rebuild = false ) {
		if ( ! $rebuild ) {
			$doc = OLR_Store::data( 'rank', 'konkurenti' );
			if ( $doc ) {
				return $doc;
			}
		}
		$runs = array();
		foreach ( OLR_Store::latest_docs( 'rank_run', 12 ) as $d ) {
			if ( is_array( $d['data'] ) && ! empty( $d['data']['gotovo'] ) && ! empty( $d['data']['rezultati'] ) && count( $runs ) < 8 ) {
				$runs[] = $d['data'];
			}
		}
		if ( ! $runs ) {
			return null;
		}
		$per    = array();
		$total  = array();
		$labels = array();
		foreach ( $runs as $i => $run ) {
			$per[ $i ]   = self::run_domains( $run );
			$total[ $i ] = 0.0;
			foreach ( $per[ $i ] as $d ) {
				$total[ $i ] += $d['vid'];
			}
			$labels[] = gmdate( 'Y-m-d H:i', strtotime( $run['pocetak'] ) );
		}
		$share = static function ( $i, $h ) use ( $per, $total ) {
			return isset( $per[ $i ][ $h ] ) && $total[ $i ] > 0 ? round( 100 * $per[ $i ][ $h ]['vid'] / $total[ $i ], 1 ) : 0.0;
		};
		$now   = $per[0];
		$prev  = isset( $per[1] ) ? $per[1] : null;
		$list  = array();
		$names = array_keys( $now );
		if ( $prev ) {
			foreach ( $prev as $h => $p ) {
				if ( ! isset( $now[ $h ] ) && $p['n10'] > 0 ) {
					$names[] = $h; // bila je u top 10, sada je nema ni u top 20
				}
			}
		}
		foreach ( $names as $h ) {
			$d     = isset( $now[ $h ] ) ? $now[ $h ] : array( 'n3' => 0, 'n10' => 0, 'n20' => 0, 'vid' => 0.0, 'kw' => array() );
			$p     = $prev && isset( $prev[ $h ] ) ? $prev[ $h ] : null;
			$trend = array();
			foreach ( array_keys( $per ) as $i ) {
				$trend[] = $share( $i, $h );
			}
			$words = array();
			foreach ( $d['kw'] as $kw => $v ) {
				$words[] = array(
					'rijec' => $kw,
					'pos'   => $v[0],
					'prije' => $p && isset( $p['kw'][ $kw ] ) ? $p['kw'][ $kw ][0] : null,
					'url'   => $v[1],
				);
			}
			usort(
				$words,
				static function ( $a, $b ) {
					return $a['pos'] <=> $b['pos'];
				}
			);
			$list[] = array(
				'domena'   => $h,
				'nasa'     => self::is_ours( $h ),
				'top3'     => $d['n3'],
				'top10'    => $d['n10'],
				'top20'    => $d['n20'],
				'udio'     => $share( 0, $h ),
				'prije'    => $prev ? array(
					'top3'  => $p ? $p['n3'] : 0,
					'top10' => $p ? $p['n10'] : 0,
					'udio'  => $share( 1, $h ),
				) : null,
				'promjena' => $prev ? round( $share( 0, $h ) - $share( 1, $h ), 1 ) : null,
				'trend'    => $trend,
				'rijeci'   => array_slice( $words, 0, 6 ),
			);
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return ( $b['udio'] <=> $a['udio'] ) ?: ( $b['top10'] <=> $a['top10'] ) ?: strcmp( $a['domena'], $b['domena'] );
			}
		);
		// Po riječi: tko je ušao u top 10, tko je ispao i tko nas je pretekao od prethodnog mjerenja.
		$changes = array();
		if ( $prev ) {
			$top = static function ( $run ) {
				$out = array();
				foreach ( (array) $run['zadaci'] as $task_id => $kw ) {
					$res = isset( $run['rezultati'][ $task_id ] ) ? $run['rezultati'][ $task_id ] : null;
					if ( ! $res || empty( $res['top'] ) ) {
						continue;
					}
					$out[ $kw ] = array( 'nas' => isset( $res['pos'] ) ? $res['pos'] : null, 'dom' => array() );
					foreach ( $res['top'] as $t ) {
						$h = OLR_DFS::host( $t['url'] );
						if ( '' !== $h && ! isset( $out[ $kw ]['dom'][ $h ] ) ) {
							$out[ $kw ]['dom'][ $h ] = (int) $t['pos'];
						}
					}
				}
				return $out;
			};
			$a = $top( $runs[0] );
			$b = $top( $runs[1] );
			foreach ( $a as $kw => $cur ) {
				if ( ! isset( $b[ $kw ] ) ) {
					continue;
				}
				$old = $b[ $kw ];
				$in  = array();
				$out = array();
				$ovt = array();
				foreach ( $cur['dom'] as $h => $pos ) {
					if ( self::is_ours( $h ) ) {
						continue;
					}
					$was = isset( $old['dom'][ $h ] ) ? $old['dom'][ $h ] : null;
					if ( $pos <= 10 && ( null === $was || $was > 10 ) ) {
						$in[] = array( 'domena' => $h, 'pos' => $pos, 'prije' => $was );
					}
					// Pretekli nas: sada su iznad nas, a prije su bili ispod (ili ih nije bilo); vrijedi i kad smo mi ispali.
					if ( null !== $old['nas'] && ( null === $was || $was > $old['nas'] ) && ( null === $cur['nas'] ? $pos <= 10 : $pos < $cur['nas'] ) ) {
						$ovt[] = array( 'domena' => $h, 'pos' => $pos, 'prije' => $was );
					}
				}
				foreach ( $old['dom'] as $h => $was ) {
					if ( self::is_ours( $h ) || $was > 10 ) {
						continue;
					}
					$pos = isset( $cur['dom'][ $h ] ) ? $cur['dom'][ $h ] : null;
					if ( null === $pos || $pos > 10 ) {
						$out[] = array( 'domena' => $h, 'pos' => $pos, 'prije' => $was );
					}
				}
				if ( $in || $out || $ovt ) {
					$changes[] = array(
						'rijec'    => $kw,
						'nas'      => $cur['nas'],
						'nas_prije' => $old['nas'],
						'usli'     => $in,
						'ispali'   => $out,
						'pretekli' => $ovt,
					);
				}
			}
		}
		$doc = array(
			'at'       => time() * 1000,
			'mjerenja' => $labels,
			'rijeci'   => count( isset( $runs[0]['zadaci'] ) ? (array) $runs[0]['zadaci'] : array() ),
			'domene'   => array_slice( $list, 0, 40 ),
			'promjene' => $changes,
			'napomena' => 'Udio je procjena udjela klikova među izmjerenim riječima po poziciji (opća krivulja), bez volumena pretraga. Po riječi se računa najbolji rezultat domene.',
		);
		OLR_Store::set( 'rank', 'konkurenti', $doc );
		return $doc;
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
