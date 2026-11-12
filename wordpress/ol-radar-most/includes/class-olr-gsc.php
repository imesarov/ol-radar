<?php
/**
 * Google Search Console preko servisnog računa (JWT RS256 → OAuth token → Search Analytics API).
 * Samo čitanje (opseg webmasters.readonly). Bez kredita i bez posrednika.
 */

defined( 'ABSPATH' ) || exit;

class OLR_GSC {

	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API       = 'https://www.googleapis.com/webmasters/v3/sites/';
	/** Ista usluga na drugoj Googleovoj adresi; rezerva ako prva ne odgovara. */
	const API_ALT   = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';
	const TOKEN_TR  = 'olr_gsc_token';

	/** Dijagnostika pokušaja zadnjeg poziva (bez ključeva): za poruku i za Claudea. */
	public static $attempts = array();

	/**
	 * Redoslijed pokušaja: [ način, adresa ]. Prvo kombinacija koja je zadnji put radila,
	 * zatim zadano, pa IPv4 + HTTP/1.1, pa rezervna adresa.
	 */
	private static function plan( $with_alt = true ) {
		$pref = OLR_Net::preferred();
		$all  = array( array( $pref['nacin'], $pref['host'] ), array( 'auto', 'www' ), array( 'v4h1', 'www' ) );
		if ( $with_alt ) {
			$all[] = array( 'v4h1', 'sc' );
		}
		$out  = array();
		$seen = array();
		foreach ( $all as $p ) {
			if ( ! $with_alt ) {
				$p[1] = 'www';
			}
			$k = $p[0] . '|' . $p[1];
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = 1;
				$out[]      = $p;
			}
		}
		return $out;
	}

	/** Čitljiva poruka kad nijedan pokušaj nije dobio odgovor. */
	private static function network_message( $what ) {
		$parts = array();
		foreach ( self::$attempts as $i => $d ) {
			$parts[] = ( $i + 1 ) . ') ' . OLR_Net::describe( $d );
		}
		$last = self::$attempts ? end( self::$attempts ) : array();
		return $what . ' nije odgovorio. Pokušaji: ' . implode( '; ', $parts ) . '.'
			. ( ! empty( $last['greska'] ) ? ' Zadnja greška: ' . $last['greska'] : '' )
			. ' Ključ i postavke su u redu; radi se o vezi hostinga prema Googleu. Pokušaj ponovno za nekoliko minuta, a ako se ponovi, javi ovu poruku.';
	}

	private static function b64url( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Servisni račun iz postavki ili WP_Error. */
	public static function service_account() {
		$raw = OLR_Settings::secret( 'gsc_sa' );
		if ( ! $raw ) {
			return new WP_Error( 'olr_gsc_nema', 'Google servisni račun nije postavljen (OL Radar → Postavke).' );
		}
		$sa = json_decode( $raw, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_Error( 'olr_gsc_json', 'JSON servisnog računa nije ispravan (nedostaje client_email ili private_key).' );
		}
		return $sa;
	}

	/** Provjera JSON-a prije spremanja; vraća true ili poruku. */
	public static function validate_json( $raw ) {
		$sa = json_decode( $raw, true );
		if ( ! is_array( $sa ) ) {
			return 'To nije ispravan JSON.';
		}
		if ( empty( $sa['type'] ) || 'service_account' !== $sa['type'] ) {
			return 'JSON nije ključ servisnog računa (type mora biti service_account).';
		}
		if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return 'U JSON-u nedostaje client_email ili private_key.';
		}
		if ( ! openssl_pkey_get_private( $sa['private_key'] ) ) {
			return 'Privatni ključ u JSON-u se ne može pročitati.';
		}
		return true;
	}

	public static function token( $force = false ) {
		if ( ! $force ) {
			$c = get_transient( self::TOKEN_TR );
			if ( $c ) {
				$t = OLR_Settings::decrypt( $c );
				if ( $t ) {
					return $t;
				}
			}
		}
		$sa = self::service_account();
		if ( is_wp_error( $sa ) ) {
			return $sa;
		}
		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$key = openssl_pkey_get_private( $sa['private_key'] );
		if ( ! $key ) {
			return new WP_Error( 'olr_gsc_kljuc', 'Privatni ključ servisnog računa se ne može pročitati.' );
		}
		$sig = '';
		if ( ! openssl_sign( $header . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'olr_gsc_potpis', 'Potpisivanje JWT-a nije uspjelo.' );
		}
		self::$attempts = array();
		$res            = null;
		foreach ( self::plan( false ) as $p ) {
			$res              = OLR_Net::post(
				self::TOKEN_URL,
				array(
					'timeout'     => 15,
					// WordPress zadano šalje HTTP/1.0; Googleovi API-ji očekuju 1.1.
					'httpversion' => '1.1',
					'body'        => array(
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $header . '.' . $claims . '.' . self::b64url( $sig ),
					),
				),
				$p[0]
			);
			self::$attempts[] = array_merge( OLR_Net::$last, array( 'korak' => 'token' ) );
			if ( ! is_wp_error( $res ) ) {
				if ( 'auto' !== $p[0] ) {
					OLR_Net::remember( $p[0], OLR_Net::preferred()['host'] );
				}
				break;
			}
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'olr_gsc_mreza', self::network_message( 'Google (izdavanje tokena)' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( 'olr_gsc_token', 'Google nije izdao token: ' . $msg );
		}
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3300;
		set_transient( self::TOKEN_TR, OLR_Settings::encrypt( $body['access_token'] ), $ttl );
		return $body['access_token'];
	}

	/** Jedan upit Search Analytics API-ja; vraća polje redaka ili WP_Error. */
	public static function query( $body, $retry = true ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$site           = OLR_Settings::get( 'gsc_site' );
		$step           = 'upit ' . implode( '+', isset( $body['dimensions'] ) ? (array) $body['dimensions'] : array() );
		self::$attempts = array();
		$res            = null;
		foreach ( self::plan() as $i => $p ) {
			$res              = OLR_Net::post(
				( 'sc' === $p[1] ? self::API_ALT : self::API ) . rawurlencode( $site ) . '/searchAnalytics/query',
				array(
					// Uobičajen odgovor stiže za 1–5 s; dulje čekanje samo produžuje zastoj.
					'timeout'     => 0 === $i ? 30 : 20,
					'httpversion' => '1.1',
					'headers'     => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				),
				$p[0]
			);
			self::$attempts[] = array_merge( OLR_Net::$last, array( 'korak' => $step ) );
			if ( ! is_wp_error( $res ) ) {
				// Zapamti kombinaciju koja radi, da sljedeći upiti ne čekaju na onu koja ne radi.
				OLR_Net::remember( $p[0], $p[1] );
				break;
			}
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'olr_gsc_mreza', self::network_message( 'Search Console' ), array( 'dijagnostika' => self::$attempts ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code && $retry ) {
			delete_transient( self::TOKEN_TR );
			return self::query( $body, false );
		}
		$gmsg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : '';
		if ( 403 === $code && ( false !== stripos( $gmsg, 'has not been used' ) || false !== stripos( $gmsg, 'is disabled' ) || false !== stripos( wp_json_encode( $json ), 'SERVICE_DISABLED' ) ) ) {
			return new WP_Error( 'olr_gsc_api_iskljucen', 'Google Search Console API nije uključen u Google Cloud projektu servisnog računa: APIs & Services → Library → Google Search Console API → Enable. Nakon uključivanja pričekaj nekoliko minuta.' );
		}
		if ( 403 === $code ) {
			$sa    = self::service_account();
			$email = is_wp_error( $sa ) ? 'servisni račun' : $sa['client_email'];
			return new WP_Error( 'olr_gsc_pristup', 'Servisni račun ' . $email . ' nema pristup svojstvu ' . $site . '. Dodaj ga u Search Consoleu: Postavke → Korisnici i dopuštenja.' );
		}
		if ( 200 !== $code ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'olr_gsc_api', 'Search Console: ' . $msg );
		}
		return isset( $json['rows'] ) && is_array( $json['rows'] ) ? $json['rows'] : array();
	}

	/**
	 * Svi retci za dimenzije i razdoblje (straničenje po 25.000).
	 * Kompaktni oblik: [ ...ključevi, klikovi, prikazivanja, ctr, pozicija ].
	 */
	public static function rows( $dims, $start, $end, $max = 25000 ) {
		$out   = array();
		$start_row = 0;
		for ( $page = 0; $page < 8; $page++ ) {
			$rows = self::query(
				array(
					'startDate'  => $start,
					'endDate'    => $end,
					'dimensions' => $dims,
					'type'       => 'web',
					'dataState'  => 'all',
					'rowLimit'   => 25000,
					'startRow'   => $start_row,
				)
			);
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $r ) {
				$keys  = isset( $r['keys'] ) ? $r['keys'] : array();
				$out[] = array_merge(
					$keys,
					array(
						(int) $r['clicks'],
						(int) $r['impressions'],
						round( (float) $r['ctr'], 5 ),
						round( (float) $r['position'], 2 ),
					)
				);
			}
			if ( count( $rows ) < 25000 || count( $out ) >= $max ) {
				break;
			}
			$start_row += 25000;
		}
		return $out;
	}

	private static function day( $base, $delta ) {
		$d = new DateTime( $base, wp_timezone() );
		$d->modify( ( $delta >= 0 ? '+' : '' ) . (int) $delta . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/**
	 * Puni bazu svježim GSC podacima (isti prozori kao Radar na claude.ai):
	 * 90 dana po danu, 28 dana upit+stranica i stranice, prethodnih 28 dana stranice,
	 * zadnjih 7 prema prethodnih 7 dana po upitu, i upit+stranica za zadnjih 7 dana.
	 */
	public static function collect() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$t_start = time();
		$today   = ( new DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$end     = self::day( $today, -3 );
		$s28     = self::day( $end, -27 );
		$pe    = self::day( $end, -28 );
		$ps    = self::day( $end, -55 );
		$s90   = self::day( $end, -89 );

		$daily = self::rows( array( 'date' ), $s90, $end, 2000 );
		if ( is_wp_error( $daily ) ) {
			OLR_Settings::set_status( 'gsc', false, $daily->get_error_message(), self::fail_extra( 'dnevno' ) );
			return $daily;
		}
		if ( ! $daily ) {
			$e = new WP_Error( 'olr_gsc_prazno', 'Search Console nije vratio dnevne podatke za ' . OLR_Settings::get( 'gsc_site' ) . '.' );
			OLR_Settings::set_status( 'gsc', false, $e->get_error_message() );
			return $e;
		}
		usort(
			$daily,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		$last = $daily[ count( $daily ) - 1 ][0];
		$wk   = array(
			's'  => self::day( $last, -6 ),
			'e'  => $last,
			'ps' => self::day( $last, -13 ),
			'pe' => self::day( $last, -7 ),
		);

		$blocks = array(
			'qp'        => array( array( 'query', 'page' ), $s28, $end, 100000 ),
			'pages'     => array( array( 'page' ), $s28, $end, 25000 ),
			'pagesPrev' => array( array( 'page' ), $ps, $pe, 25000 ),
			'wkCur'     => array( array( 'query' ), $wk['s'], $wk['e'], 25000 ),
			'wkPrev'    => array( array( 'query' ), $wk['ps'], $wk['pe'], 25000 ),
			'qp7'       => array( array( 'query', 'page' ), $wk['s'], $wk['e'], 25000 ),
		);
		$data   = array();
		foreach ( $blocks as $name => $b ) {
			$rows = self::rows( $b[0], $b[1], $b[2], $b[3] );
			if ( is_wp_error( $rows ) ) {
				OLR_Settings::set_status( 'gsc', false, $rows->get_error_message(), self::fail_extra( $name ) );
				return $rows;
			}
			$ui = array_search( 'page', $b[0], true );
			if ( false !== $ui ) {
				$rows = self::merge_urls( $rows, count( $b[0] ), $ui );
			}
			$data[ $name ] = $rows;
		}

		$at = time() * 1000;
		OLR_Store::set( 'gsc', 'daily', array( 'cols' => array( 'date', 'clicks', 'impressions', 'ctr', 'position' ), 'rows' => $daily ) );
		OLR_Store::set( 'gsc', 'qp', array( 'cols' => array( 'query', 'page', 'clicks', 'impressions', 'ctr', 'position' ), 'rows' => $data['qp'] ) );
		OLR_Store::set( 'gsc', 'pages', array( 'cols' => array( 'page', 'clicks', 'impressions', 'ctr', 'position' ), 'rows' => $data['pages'] ) );
		OLR_Store::set( 'gsc', 'pagesPrev', array( 'cols' => array( 'page', 'clicks', 'impressions', 'ctr', 'position' ), 'rows' => $data['pagesPrev'] ) );
		OLR_Store::set(
			'gsc',
			'wk',
			array(
				'range' => $wk,
				'cur'   => $data['wkCur'],
				'prev'  => $data['wkPrev'],
				'qp7'   => $data['qp7'],
			)
		);
		$meta = array(
			'at'      => $at,
			'site'    => OLR_Settings::get( 'gsc_site' ),
			'do'      => $last,
			'prozori' => array(
				'dnevno'      => array( $s90, $end ),
				'28_dana'     => array( $s28, $end ),
				'prethodnih'  => array( $ps, $pe ),
				'tjedan'      => $wk,
			),
			'redaka'  => array(
				'dnevno' => count( $daily ),
				'qp'     => count( $data['qp'] ),
				'pages'  => count( $data['pages'] ),
			),
		);
		OLR_Store::set( 'gsc', 'meta', $meta );
		$ok = self::$attempts ? end( self::$attempts ) : array();
		OLR_Settings::set_status(
			'gsc',
			true,
			'podaci do ' . $last,
			array(
				'do'      => $last,
				'trajalo' => time() - $t_start,
				'veza'    => $ok ? OLR_Net::describe( $ok ) : '',
			)
		);
		/**
		 * Novi Google podaci su spremljeni (koristi ih dnevna analiza).
		 *
		 * @param array $meta Opis preuzetih podataka.
		 */
		do_action( 'olr_gsc_collected', $meta );
		return $meta;
	}

	/** Adresa bez parametara i sidra, s kosom crtom na kraju (osim datoteka). */
	public static function clean_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['host'] ) ) {
			return (string) $url;
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		if ( '/' !== substr( $path, -1 ) && false === strpos( basename( $path ), '.' ) ) {
			$path .= '/';
		}
		return ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . $p['host'] . $path;
	}

	/**
	 * Search Console istu stranicu vodi i pod adresama s parametrima (utm_*, rwg_token s Google profila)
	 * ili bez završne kose crte. Takvi se redci spajaju u jedan: klikovi i prikazivanja se zbrajaju,
	 * pozicija je prosjek ponderiran prikazivanjima.
	 *
	 * @param array $rows Kompaktni redci [ključevi…, klikovi, prikazivanja, ctr, pozicija].
	 * @param int   $keys Broj ključeva.
	 * @param int   $ui   Indeks ključa s adresom.
	 */
	public static function merge_urls( $rows, $keys, $ui ) {
		$agg = array();
		foreach ( $rows as $r ) {
			$r[ $ui ] = self::clean_url( $r[ $ui ] );
			$k        = implode( "\n", array_slice( $r, 0, $keys ) );
			$w        = max( 1, (int) $r[ $keys + 1 ] ); // ponder pozicije: prikazivanja, najmanje 1
			if ( ! isset( $agg[ $k ] ) ) {
				$agg[ $k ] = array(
					'keys' => array_slice( $r, 0, $keys ),
					'c'    => 0,
					'i'    => 0,
					'pw'   => 0.0,
					'w'    => 0,
				);
			}
			$agg[ $k ]['c']  += (int) $r[ $keys ];
			$agg[ $k ]['i']  += (int) $r[ $keys + 1 ];
			$agg[ $k ]['pw'] += (float) $r[ $keys + 3 ] * $w;
			$agg[ $k ]['w']  += $w;
		}
		$out = array();
		foreach ( $agg as $a ) {
			$out[] = array_merge(
				$a['keys'],
				array(
					$a['c'],
					$a['i'],
					$a['i'] ? round( $a['c'] / $a['i'], 5 ) : 0,
					round( $a['pw'] / $a['w'], 2 ),
				)
			);
		}
		usort(
			$out,
			static function ( $x, $y ) use ( $keys ) {
				return ( $y[ $keys ] <=> $x[ $keys ] ) ?: ( $y[ $keys + 1 ] <=> $x[ $keys + 1 ] );
			}
		);
		return $out;
	}

	/** Dodatak statusu pri grešci: koji dio je pao i dijagnostika pokušaja. */
	private static function fail_extra( $part ) {
		return array(
			'dio'          => $part,
			'dijagnostika' => array_slice( self::$attempts, -4 ),
		);
	}

	/** Mali test veze: jedan dan, bez spremanja. */
	public static function test() {
		$end   = self::day( ( new DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' ), -3 );
		$rows  = self::query(
			array(
				'startDate'  => self::day( $end, -6 ),
				'endDate'    => $end,
				'dimensions' => array( 'date' ),
				'rowLimit'   => 10,
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$c = 0;
		$i = 0;
		foreach ( $rows as $r ) {
			$c += (int) $r['clicks'];
			$i += (int) $r['impressions'];
		}
		$d = self::$attempts ? end( self::$attempts ) : array();
		return sprintf( 'Veza radi: zadnjih 7 dana %d klikova i %d prikazivanja.', $c, $i ) . ( $d ? ' (' . OLR_Net::describe( $d ) . ')' : '' );
	}
}
