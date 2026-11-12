<?php
/**
 * Postavke Radara na hostingu i šifrirano spremanje ključeva.
 *
 * Ključevi (Google servisni račun, DataForSEO, Anthropic) spremaju se šifrirano (libsodium,
 * ključ izveden iz WordPressovih soli) i nikad se ne vraćaju u preglednik. Ako je u wp-config.php
 * definirana konstanta, ona ima prednost:
 *   OLR_GSC_SA_FILE (putanja do JSON datoteke, najbolje izvan public_html) ili OLR_GSC_SA_JSON,
 *   OLR_DFS_LOGIN, OLR_DFS_PASSWORD, OLR_ANTHROPIC_KEY.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Settings {

	const OPT     = 'olr_settings';
	const SECRETS = 'olr_secrets';
	const STATUS  = 'olr_status';

	/** Ciljane riječi trackera (isti popis kao u OpenSEO-u, 11. 11. 2026.). */
	public static function default_keywords() {
		return array(
			'progresivne naočale', 'progresivne naočale cijena', 'cijena progresivnih naočala', 'progresivna stakla cijena',
			'progresivne leće cijena', 'koliko koštaju progresivne naočale', 'progresivne naočale iskustva',
			'varilux progresivne leće cijena', 'najpovoljnije progresivne naočale',
			'optika sesvete', 'optičar', 'optika', 'besplatan pregled vida', 'besplatni pregled vida', 'besplatna kontrola vida',
			'dioptrijske naočale', 'naočale za čitanje', 'naočale za noćnu vožnju', 'bifokalne naočale cijena', 'stellest stakla',
			'ray ban dioptrijske naočale', 'sunčane naočale', 'essilor stakla iskustva',
			'kako vide ljudi s astigmatizmom', 'mrena na oku', 'optometrist', 'okulist',
		);
	}

	public static function defaults() {
		return array(
			'gsc_site'       => 'https://www.optikaljiljana.hr/',
			'domain'         => 'optikaljiljana.hr',
			'rank_keywords'  => self::default_keywords(),
			'rank_location'  => 'Zagreb,City of Zagreb,Croatia',
			'rank_language'  => 'hr',
			'rank_device'    => 'mobile',
			'rank_depth'     => 40,
			'rank_weekly'    => false,
			'ai_model'       => 'claude-sonnet-5-5',
			'ai_daily_limit' => 150,
		);
	}

	public static function all() {
		$s = get_option( self::OPT, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function get( $key ) {
		$a = self::all();
		return isset( $a[ $key ] ) ? $a[ $key ] : null;
	}

	public static function update( $values ) {
		$cur = get_option( self::OPT, array() );
		$cur = is_array( $cur ) ? $cur : array();
		update_option( self::OPT, array_merge( $cur, $values ), false );
	}

	/* ---------- šifriranje ---------- */

	private static function box_key() {
		return sodium_crypto_generichash( 'olr-secrets|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::box_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $blob ) {
		$raw = base64_decode( (string) $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::box_key() );
		return false === $plain ? null : $plain;
	}

	/** Vraća tajnu (konstanta ima prednost) ili null. */
	public static function secret( $name ) {
		$const = array(
			'dfs_login'     => 'OLR_DFS_LOGIN',
			'dfs_password'  => 'OLR_DFS_PASSWORD',
			'anthropic_key' => 'OLR_ANTHROPIC_KEY',
		);
		if ( 'gsc_sa' === $name ) {
			if ( defined( 'OLR_GSC_SA_FILE' ) && is_readable( OLR_GSC_SA_FILE ) ) {
				return (string) file_get_contents( OLR_GSC_SA_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			if ( defined( 'OLR_GSC_SA_JSON' ) ) {
				return (string) OLR_GSC_SA_JSON;
			}
		} elseif ( isset( $const[ $name ] ) && defined( $const[ $name ] ) ) {
			return (string) constant( $const[ $name ] );
		}
		$all = get_option( self::SECRETS, array() );
		if ( ! is_array( $all ) || empty( $all[ $name ] ) ) {
			return null;
		}
		return self::decrypt( $all[ $name ] );
	}

	public static function secret_source( $name ) {
		$map = array(
			'gsc_sa'        => array( 'OLR_GSC_SA_FILE', 'OLR_GSC_SA_JSON' ),
			'dfs_login'     => array( 'OLR_DFS_LOGIN' ),
			'dfs_password'  => array( 'OLR_DFS_PASSWORD' ),
			'anthropic_key' => array( 'OLR_ANTHROPIC_KEY' ),
		);
		foreach ( $map[ $name ] as $c ) {
			if ( defined( $c ) ) {
				return 'wp-config.php';
			}
		}
		$all = get_option( self::SECRETS, array() );
		return ( is_array( $all ) && ! empty( $all[ $name ] ) ) ? 'baza (šifrirano)' : '';
	}

	public static function set_secret( $name, $value ) {
		$all = get_option( self::SECRETS, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $value || '' === $value ) {
			unset( $all[ $name ] );
		} else {
			$all[ $name ] = self::encrypt( $value );
		}
		update_option( self::SECRETS, $all, false );
	}

	/* ---------- stanje izvora (za ploču i Claudea) ---------- */

	public static function set_status( $key, $ok, $msg, $extra = array() ) {
		$st = get_option( self::STATUS, array() );
		$st = is_array( $st ) ? $st : array();
		$st[ $key ] = array_merge(
			array(
				'ok'  => (bool) $ok,
				'msg' => (string) $msg,
				'at'  => time(),
			),
			$extra
		);
		update_option( self::STATUS, $st, false );
	}

	public static function status() {
		$st = get_option( self::STATUS, array() );
		return is_array( $st ) ? $st : array();
	}

	/** Čitljiv opis tajne bez otkrivanja: npr. adresa servisnog računa ili zadnja 4 znaka. */
	public static function describe_secret( $name ) {
		$v = self::secret( $name );
		if ( ! $v ) {
			return '';
		}
		if ( 'gsc_sa' === $name ) {
			$j = json_decode( $v, true );
			return is_array( $j ) && ! empty( $j['client_email'] ) ? $j['client_email'] : 'postavljeno, ali JSON nije ispravan';
		}
		if ( 'dfs_password' === $name ) {
			return 'postavljena';
		}
		if ( 'dfs_login' === $name ) {
			return $v;
		}
		return 'postavljen (…' . substr( $v, -4 ) . ')';
	}
}
