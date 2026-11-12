<?php
/**
 * REST API nadzorne ploče (ol-radar/v1). Samo za administratore weba (manage_options) uz WordPressov nonce.
 */

defined( 'ABSPATH' ) || exit;

class OLR_REST {

	const NS = 'ol-radar/v1';

	/** Zbirke koje nadzorna ploča smije mijenjati; podatke izvora (gsc, rank, serp…) piše samo server. */
	const WRITABLE = array( 'issues', 'fixes', 'plan', 'kp', 'snap', 'cfg', 'trends', 'export', 'tjedno', 'prijedlozi_stanje' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' );
	}

	public static function routes() {
		$name = '(?P<coll>[A-Za-z0-9_\-\.|]{1,64})';
		register_rest_route(
			self::NS,
			'/podaci',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'podaci' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/stanje',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stanje' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/docs/' . $name,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'docs' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/doc/' . $name . '/(?P<id>[A-Za-z0-9_\-\.|]{1,191})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'doc_get' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'doc_put' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'doc_delete' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ai' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/osvjezi',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'osvjezi' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
	}

	/** Svi podaci za nadzornu ploču u jednom odgovoru. */
	public static function podaci() {
		$wp_list = array();
		$page    = 1;
		do {
			$res     = OLR_Abilities::list_content(
				array(
					'po_stranici' => 100,
					'stranica'    => $page,
				)
			);
			$wp_list = array_merge( $wp_list, isset( $res['stavke'] ) ? $res['stavke'] : array() );
			$pages   = isset( $res['stranica_ukupno'] ) ? (int) $res['stranica_ukupno'] : 1;
			$page++;
		} while ( $page <= min( $pages, 20 ) );

		$serp = array();
		foreach ( OLR_Store::list_docs( 'serp' ) as $d ) {
			$serp[] = $d['data'];
		}
		return rest_ensure_response(
			array(
				'gsc'    => array(
					'meta'      => OLR_Store::data( 'gsc', 'meta' ),
					'daily'     => OLR_Store::data( 'gsc', 'daily' ),
					'qp'        => OLR_Store::data( 'gsc', 'qp' ),
					'pages'     => OLR_Store::data( 'gsc', 'pages' ),
					'pagesPrev' => OLR_Store::data( 'gsc', 'pagesPrev' ),
					'wk'        => OLR_Store::data( 'gsc', 'wk' ),
				),
				'rank'   => array(
					'latest'   => OLR_Store::data( 'rank', 'latest' ),
					'hist'     => OLR_Store::data( 'rank', 'hist' ),
					'u_tijeku' => get_option( OLR_DFS::PENDING ) ? true : false,
				),
				'serp'   => $serp,
				'wp'     => array(
					'list'  => $wp_list,
					'audit' => OLR_Abilities::audit( array( 'limit' => 1000 ) ),
				),
				'ig'     => OLR_Store::data( 'ig', 'zadnje' ),
				'prijedlozi' => OLR_Prijedlozi::summary( 'svi', 300 ),
				'stanje' => self::state_array(),
			)
		);
	}

	public static function state_array() {
		$cost = get_option( 'olr_dfs_cost', array() );
		return array(
			'izvori'   => OLR_Settings::status(),
			'kljucevi' => array(
				'gsc'       => (bool) OLR_Settings::secret( 'gsc_sa' ),
				'dataforseo' => OLR_DFS::has_credentials(),
				'anthropic' => OLR_AI::available(),
			),
			'ai'       => array(
				'model'   => OLR_Settings::get( 'ai_model' ),
				'potrosnja' => OLR_AI::usage(),
			),
			'dfs'      => array(
				'trosak_po_mjesecima' => is_array( $cost ) ? $cost : array(),
				'procjena_mjerenja'   => OLR_DFS::estimate(),
				'tjedno'              => (bool) OLR_Settings::get( 'rank_weekly' ),
			),
			'prijedlozi' => array(
				'auto'   => (bool) OLR_Settings::get( 'prijedlozi_auto' ),
				'zadnje' => array_diff_key( OLR_Prijedlozi::last_run(), array( 'rucno' => 1 ) ),
			),
			// Upozorenje ako je nakon nadogradnje neki prije spremljeni ključ postao nečitljiv ili nestao.
			'nadogradnja' => OLR_Settings::upgrade_warning(),
			'postavke' => admin_url( 'admin.php?page=ol-radar-postavke' ),
			'verzija'  => OLR_VERSION,
		);
	}

	public static function stanje() {
		return rest_ensure_response( self::state_array() );
	}

	public static function docs( WP_REST_Request $r ) {
		$coll = $r['coll'];
		return rest_ensure_response(
			array(
				'items' => OLR_Store::list_docs(
					$coll,
					sanitize_key( (string) $r->get_param( 'orderBy' ) ),
					'desc' === $r->get_param( 'dir' ) ? 'desc' : 'asc',
					$r->get_param( 'limit' ) ? (int) $r->get_param( 'limit' ) : 1000
				),
			)
		);
	}

	public static function doc_get( WP_REST_Request $r ) {
		$d = OLR_Store::get( $r['coll'], $r['id'] );
		// Nepostojeći dokument nije greška (ploča ga često samo provjerava), pa 200 s exists: false.
		return rest_ensure_response( $d ? array_merge( array( 'exists' => true ), $d ) : array( 'exists' => false ) );
	}

	public static function doc_put( WP_REST_Request $r ) {
		if ( ! in_array( $r['coll'], self::WRITABLE, true ) ) {
			return new WP_Error( 'olr_zabranjeno', 'Ova zbirka se ne mijenja iz nadzorne ploče.', array( 'status' => 403 ) );
		}
		$body = $r->get_json_params();
		if ( ! is_array( $body ) || ! array_key_exists( 'data', $body ) ) {
			return new WP_Error( 'olr_tijelo', 'Nedostaje polje data.', array( 'status' => 400 ) );
		}
		$ok = OLR_Store::set( $r['coll'], $r['id'], $body['data'] );
		if ( is_wp_error( $ok ) ) {
			$ok->add_data( array( 'status' => 400 ) );
			return $ok;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function doc_delete( WP_REST_Request $r ) {
		if ( ! in_array( $r['coll'], self::WRITABLE, true ) ) {
			return new WP_Error( 'olr_zabranjeno', 'Ova zbirka se ne mijenja iz nadzorne ploče.', array( 'status' => 403 ) );
		}
		OLR_Store::delete( $r['coll'], $r['id'] );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function ai( WP_REST_Request $r ) {
		$res = OLR_AI::call( (array) $r->get_json_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/**
	 * Osvježavanje: gsc (Search Console), rank (pokupi gotove rezultate mjerenja), crawl (serija stranica)
	 * i prijedlozi (analiza + jedan poziv Claudea, najviše nekoliko puta dnevno).
	 * Novo plaćeno mjerenje pozicija pokreće se samo na stranici Postavke.
	 */
	public static function osvjezi( WP_REST_Request $r ) {
		$dio = (string) $r->get_param( 'dio' );
		if ( 'gsc' === $dio ) {
			$res = OLR_GSC::collect();
		} elseif ( 'rank' === $dio ) {
			$res = OLR_DFS::poll();
		} elseif ( 'crawl' === $dio ) {
			$res = OLR_Crawler::run_batch( 15, 25 );
		} elseif ( 'prijedlozi' === $dio ) {
			$res = OLR_Prijedlozi::run( 'rucno' );
			if ( ! is_wp_error( $res ) ) {
				$res = OLR_Prijedlozi::summary( 'svi', 300 );
			}
		} else {
			return new WP_Error( 'olr_dio', 'Nepoznat dio (gsc, rank, crawl, prijedlozi).', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 502 ) );
			return $res;
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'rezultat' => $res,
			)
		);
	}
}
