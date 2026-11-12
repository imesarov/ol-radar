<?php
/**
 * Mrežni pozivi s rezervnim načinom i dijagnostikom.
 *
 * Na dijeljenom hostingu zahtjev prema Googleu zna ostati bez odgovora (IPv6 ruta koja ne radi,
 * stara verzija HTTP-a). Zato pozivi idu kao HTTP/1.1, a onaj koji istekne ponavlja se prisilno preko
 * IPv4 i zatim na rezervnoj adresi. Za svaki pokušaj bilježi se gdje je zapelo (spajanje, TLS, čekanje
 * odgovora). Ništa od toga ne sadrži ključeve.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Net {

	/** Zadnja kombinacija koja je radila: array( 'nacin' => auto|v4h1, 'host' => www|sc ). */
	const OPT = 'olr_net_ok';

	private static $mode   = 'auto';
	private static $handle = null;
	private static $info   = null;

	/** Dijagnostika zadnjeg zahtjeva (vidi diag()). */
	public static $last = array();

	public static function init() {
		add_action( 'http_api_curl', array( __CLASS__, 'on_curl' ), 10, 3 );
		add_action( 'requests-curl.after_request', array( __CLASS__, 'on_after' ), 10, 2 );
	}

	/** Način trenutnog zahtjeva (koriste ga i testni mockovi). */
	public static function mode() {
		return self::$mode;
	}

	public static function on_curl( $handle, $args = array(), $url = '' ) {
		if ( empty( $args['olr_net'] ) ) {
			return;
		}
		self::$handle = $handle;
		if ( 'v4h1' === $args['olr_net'] ) {
			if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
				curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
			if ( defined( 'CURL_HTTP_VERSION_1_1' ) ) {
				curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		}
	}

	public static function on_after( $headers = null, $info = null ) {
		if ( null !== self::$handle && is_array( $info ) ) {
			self::$info = $info;
		}
	}

	/**
	 * POST u zadanom načinu. Vraća isto što i wp_remote_post; dijagnostika je u self::$last.
	 *
	 * @param string $url  Adresa.
	 * @param array  $args Argumenti za wp_remote_post.
	 * @param string $mode auto (zadano ponašanje) ili v4h1 (IPv4 + HTTP/1.1).
	 */
	public static function post( $url, $args, $mode = 'auto' ) {
		self::$handle    = null;
		self::$info      = null;
		self::$mode      = $mode;
		$args['olr_net'] = $mode;
		$t0              = microtime( true );
		$res             = wp_remote_post( $url, $args );
		$ms              = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$info            = self::$info;
		if ( ! $info && null !== self::$handle && ( is_resource( self::$handle ) || is_object( self::$handle ) ) && function_exists( 'curl_getinfo' ) ) {
			$info = @curl_getinfo( self::$handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		}
		self::$handle = null;
		self::$mode   = 'auto';
		self::$last   = self::diag( $url, $mode, $ms, is_array( $info ) ? $info : array(), $res );
		return $res;
	}

	/** Je li greška mrežna (timeout, spajanje), a ne odgovor poslužitelja. */
	public static function is_network_error( $res ) {
		return is_wp_error( $res );
	}

	private static function ms( $info, $key ) {
		return isset( $info[ $key ] ) && $info[ $key ] > 0 ? (int) round( $info[ $key ] * 1000 ) : null;
	}

	private static function diag( $url, $mode, $ms, $info, $res ) {
		$ip = isset( $info['primary_ip'] ) ? (string) $info['primary_ip'] : '';
		$hv = isset( $info['http_version'] ) ? (int) $info['http_version'] : 0;
		$d  = array(
			'host'   => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'nacin'  => 'v4h1' === $mode ? 'IPv4 + HTTP/1.1' : 'zadano (HTTP/1.1)',
			'ms'     => $ms,
			'ok'     => ! is_wp_error( $res ),
			'status' => is_wp_error( $res ) ? null : (int) wp_remote_retrieve_response_code( $res ),
		);
		if ( is_wp_error( $res ) ) {
			$d['greska'] = substr( $res->get_error_message(), 0, 200 );
		}
		if ( '' !== $ip ) {
			$d['ip']  = $ip;
			$d['ipv'] = false !== strpos( $ip, ':' ) ? 6 : 4;
		}
		if ( $hv ) {
			$d['http'] = 3 === $hv ? '2' : ( 2 === $hv ? '1.1' : ( 1 === $hv ? '1.0' : (string) $hv ) );
		}
		foreach ( array( 'dns_ms' => 'namelookup_time', 'spajanje_ms' => 'connect_time', 'tls_ms' => 'appconnect_time', 'prvi_bajt_ms' => 'starttransfer_time' ) as $k => $src ) {
			$v = self::ms( $info, $src );
			if ( null !== $v ) {
				$d[ $k ] = $v;
			}
		}
		return $d;
	}

	/** Kratak čitljiv opis jednog pokušaja. */
	public static function describe( $d ) {
		if ( ! $d ) {
			return '';
		}
		$p = array( $d['host'], $d['nacin'] );
		if ( ! empty( $d['ipv'] ) ) {
			$p[] = 'IPv' . $d['ipv'];
		}
		if ( ! empty( $d['http'] ) ) {
			$p[] = 'HTTP/' . $d['http'];
		}
		if ( ! empty( $d['ok'] ) ) {
			$p[] = 'odgovor za ' . number_format( $d['ms'] / 1000, 1, ',', '' ) . ' s';
		} else {
			if ( ! isset( $d['spajanje_ms'] ) && isset( $d['ipv'] ) ) {
				$where = 'nije se spojilo';
			} elseif ( isset( $d['spajanje_ms'] ) && ! isset( $d['tls_ms'] ) ) {
				$where = 'spojeno, ali sigurna veza (TLS) nije uspostavljena';
			} elseif ( isset( $d['tls_ms'] ) && ! isset( $d['prvi_bajt_ms'] ) ) {
				$where = 'veza uspostavljena, zahtjev poslan, odgovor nije stigao';
			} else {
				$where = 'bez odgovora';
			}
			$p[] = $where . ' nakon ' . number_format( $d['ms'] / 1000, 0, ',', '' ) . ' s';
		}
		return implode( ', ', $p );
	}

	/** Zapamćena kombinacija koja je zadnji put radila. */
	public static function preferred() {
		$o = get_option( self::OPT );
		return is_array( $o ) && isset( $o['nacin'], $o['host'] ) ? $o : array( 'nacin' => 'auto', 'host' => 'www' );
	}

	public static function remember( $mode, $host ) {
		$cur = self::preferred();
		if ( $cur['nacin'] !== $mode || $cur['host'] !== $host ) {
			update_option(
				self::OPT,
				array(
					'nacin' => $mode,
					'host'  => $host,
					'at'    => time(),
				),
				false
			);
		}
	}
}
