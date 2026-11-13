<?php
/**
 * REST API nadzorne ploče (ol-radar/v1). Samo za administratore weba (manage_options) uz WordPressov nonce.
 */

defined( 'ABSPATH' ) || exit;

class OLR_REST {

	const NS = 'ol-radar/v1';

	/** Zbirke koje nadzorna ploča smije mijenjati; podatke izvora (gsc, rank, serp…) piše samo server. */
	const WRITABLE = array( 'issues', 'fixes', 'plan', 'kp', 'snap', 'cfg', 'trends', 'export', 'tjedno', 'prijedlozi_stanje', 'suradnje_stanje' );

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
			'/prijedlozi',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'prijedlozi' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/suradnje',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'suradnje' ),
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
		// Povratak s TikTokove stranice za odobrenje. Preglednik dolazi bez WordPressova noncea, pa pristup
		// štiti jednokratni „state” koji je netom prije izdan administratoru u Postavkama (vrijedi 15 minuta).
		register_rest_route(
			self::NS,
			'/oauth/tiktok',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'oauth_tiktok' ),
				'permission_callback' => '__return_true',
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
					// Kretanje konkurenata iz naših mjerenja (top 20 po riječi, zadnjih do 8 mjerenja).
					'konkurenti' => OLR_Store::data( 'rank', 'latest' ) ? OLR_DFS::competitors() : null,
				),
				'serp'   => $serp,
				'wp'     => array(
					'list'  => $wp_list,
					'audit' => OLR_Abilities::audit( array( 'limit' => 1000 ) ),
				),
				'ig'     => OLR_Store::data( 'ig', 'zadnje' ),
				'prijedlozi' => OLR_Prijedlozi::summary( 'svi', 300 ),
				'indeks' => OLR_Indeks::summary( 400 ),
				// Društvene mreže: računi, objave s brojkama, analiza, uzori i Claudeove preporuke.
				'mreze'  => OLR_Social::summary( 40 ),
				// Suradnje i poveznice: kandidati s prijedlozima, DR, poveznice koje vidi Google.
				'suradnje' => OLR_Suradnje::summary( 300 ),
				// Tržište (DataForSEO, od 2.5.0): karta, profili, recenzije, obujam, ključne riječi, poveznice, spominjanja, brzina, AI asistenti.
				'trziste' => OLR_Trziste::summary( '', 300 ),
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
				'meta'      => (bool) OLR_Settings::secret( 'meta_token' ),
				'youtube'   => (bool) OLR_Settings::secret( 'yt_key' ),
				'tiktok'    => (bool) OLR_Social::tt_tokens(),
			),
			'mreze'    => array(
				'auto'     => (bool) OLR_Settings::get( 'mreze_auto' ),
				'povezano' => OLR_Social::connected(),
				'zadnje'   => array_diff_key( OLR_Social::last_run(), array( 'rucno' => 1 ) ),
			),
			'ai'       => array(
				'model'   => OLR_Settings::get( 'ai_model' ),
				'potrosnja' => OLR_AI::usage(),
				// Trošak u USD: danas i po mjesecima (iz stvarnih tokena svakog poziva).
				'trosak'  => OLR_AI::cost_summary(),
			),
			'suradnje' => array(
				'auto'     => (bool) OLR_Settings::get( 'suradnje_auto' ),
				'pretraga' => (bool) OLR_Settings::get( 'suradnje_trazi' ),
				'dr_auto'  => (bool) OLR_Settings::get( 'suradnje_dr' ),
				'zadnje'   => array_diff_key( OLR_Suradnje::last_run(), array( 'rucno' => 1 ) ),
			),
			'dfs'      => array(
				'trosak_po_mjesecima' => is_array( $cost ) ? $cost : array(),
				'procjena_mjerenja'   => OLR_DFS::estimate(),
				'tjedno'              => (bool) OLR_Settings::get( 'rank_weekly' ),
				'ai_pregled_naknadno' => (bool) OLR_Settings::get( 'rank_aio_async' ),
			),
			'prijedlozi' => array(
				'auto'   => (bool) OLR_Settings::get( 'prijedlozi_auto' ),
				'zadnje' => array_diff_key( OLR_Prijedlozi::last_run(), array( 'rucno' => 1 ) ),
			),
			// Tržište: po modulu uključeno, stanje, zadnji prolaz i procjena; trošak ovaj mjesec i limit.
			'trziste'  => array(
				'moduli'   => OLR_Trziste::modules_state(),
				'trosak'   => array(
					'mjesec'            => round( OLR_Trziste::month_spent(), 4 ),
					'limit'             => OLR_Trziste::limit(),
					'procjena_mjesecno' => OLR_Trziste::estimate_month( true ),
				),
				'u_tijeku' => OLR_Trziste::progress(),
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

	/** Samo prijedlozi: ploča ovo čita dok se tekst izmjena dopisuje u pozadini. */
	public static function prijedlozi() {
		return rest_ensure_response( OLR_Prijedlozi::summary( 'svi', 300 ) );
	}

	/** Samo suradnje: ploča ovo čita nakon promjene stanja ili upisa adrese s poveznicom. */
	public static function suradnje() {
		OLR_Suradnje::verify_links( 2 );
		return rest_ensure_response( OLR_Suradnje::summary( 300 ) );
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
		if ( 'prijedlozi_stanje' === $r['coll'] ) {
			// Promjena stanja prijedloga (npr. „provedeno”): mjerenje učinka se uskladi u pozadini.
			OLR_Ucinak::schedule( 3 );
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
		// Poziv iz ploče: bez web pretrage i duljih odgovora (to smiju samo poslovi na serveru).
		$res = OLR_AI::call( (array) $r->get_json_params(), false );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/**
	 * Povratak s TikToka nakon odobrenja: kod se zamijeni za tokene (spremaju se šifrirano), preuzmu se
	 * prvi podaci i preglednik se vrati u Postavke. Tokeni se nikad ne ispisuju.
	 */
	public static function oauth_tiktok( WP_REST_Request $r ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $r->get_param( 'state' ) );
		$uid   = '' !== $state ? (int) get_transient( 'olr_tt_state_' . $state ) : 0;
		$back  = admin_url( 'admin.php?page=ol-radar-postavke' );
		if ( ! $uid ) {
			// Adresa je javna: bez valjanog statea ne mijenja se ništa (ni veza, ni stanje izvora, ni poruke).
			$resp = new WP_REST_Response( null, 302 );
			$resp->header( 'Location', $back . '&olr_tt=greska' );
			$resp->header( 'Cache-Control', 'no-store' );
			return $resp;
		}
		$err = (string) $r->get_param( 'error' );
		if ( '' !== $err ) {
			delete_transient( 'olr_tt_state_' . $state );
			$d   = (string) $r->get_param( 'error_description' );
			$res = new WP_Error( 'olr_tt_odbijeno', 'TikTok nije odobrio povezivanje: ' . sanitize_text_field( '' !== $d ? $d : $err ) );
		} else {
			$res = OLR_Social::tt_callback( (string) $r->get_param( 'code' ), $state );
		}
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( ! OLR_Social::tt_tokens() ) {
				// Postojeća veza koja radi ostaje „u redu”; greška se bilježi samo ako veze nema.
				OLR_Settings::set_status( 'mreze_tt', false, $msg );
			}
		} else {
			$n = OLR_Social::collect_tt();
			OLR_Social::analyze();
			$msg = is_wp_error( $n ) ? 'TikTok je povezan, ali podaci još nisu preuzeti: ' . $n->get_error_message() : 'TikTok je povezan: preuzeto ' . (int) $n . ' videa.';
		}
		set_transient(
			'olr_settings_notice_' . $uid,
			array(
				'ok'  => ! is_wp_error( $res ),
				'msg' => $msg,
			),
			300
		);
		$resp = new WP_REST_Response( null, 302 );
		$resp->header( 'Location', $back );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	/**
	 * Osvježavanje: gsc (Search Console), rank (pokupi gotove rezultate mjerenja), crawl (serija stranica)
	 * prijedlozi (analiza + tekst izmjena od Claudea, najviše nekoliko puta dnevno) i mjerenje
	 * (novo plaćeno mjerenje pozicija; samo uz potvrda=da, koju ploča šalje nakon korisnikove potvrde troška).
	 */
	public static function osvjezi( WP_REST_Request $r ) {
		$dio = (string) $r->get_param( 'dio' );
		if ( 'gsc' === $dio ) {
			$res = OLR_GSC::collect();
		} elseif ( 'rank' === $dio ) {
			$res = OLR_DFS::poll();
		} elseif ( 'crawl' === $dio ) {
			$res = OLR_Crawler::run_batch( 15, 25 );
		} elseif ( 'mjerenje' === $dio ) {
			// Plaćeno mjerenje pozicija: samo uz izričitu potvrdu korisnika u ploči.
			if ( 'da' !== (string) $r->get_param( 'potvrda' ) ) {
				return new WP_Error( 'olr_potvrda', 'Mjerenje pozicija se naplaćuje (DataForSEO); potrebna je potvrda.', array( 'status' => 400 ) );
			}
			$res = OLR_DFS::start_run( 'rucno' );
		} elseif ( 'indeks' === $dio ) {
			// Jedna serija provjera indeksiranosti; ploča ponavlja dok „preostalo” ne padne na nulu.
			$res = OLR_Indeks::run_batch( 15, 22, true );
			if ( ! is_wp_error( $res ) ) {
				$res['indeks'] = OLR_Indeks::summary( 400 );
				unset( $res['sazetak'] );
			}
		} elseif ( 'ucinak' === $dio ) {
			$res = OLR_Ucinak::sync();
			if ( ! is_wp_error( $res ) ) {
				$res = OLR_Prijedlozi::summary( 'svi', 300 );
			}
		} elseif ( 'prijedlozi' === $dio ) {
			$res = OLR_Prijedlozi::run( 'rucno' );
			if ( ! is_wp_error( $res ) ) {
				$res = OLR_Prijedlozi::summary( 'svi', 300 );
			}
		} elseif ( 'mreze' === $dio ) {
			// Podaci svih povezanih mreža (bez uzora, koji idu zasebnim pozivom da zahtjev ostane kratak).
			$res = OLR_Social::collect_all( false );
			if ( ! is_wp_error( $res ) ) {
				$res = array(
					'ishod' => $res,
					'mreze' => OLR_Social::summary( 40 ),
				);
			}
		} elseif ( 'mreze_uzori' === $dio ) {
			OLR_Social::collect_models();
			OLR_Social::analyze();
			$res = array( 'mreze' => OLR_Social::summary( 40 ) );
		} elseif ( 'mreze_preporuke' === $dio ) {
			$res = OLR_Social::recommend( 'rucno' );
			if ( ! is_wp_error( $res ) ) {
				$res = array( 'mreze' => OLR_Social::summary( 40 ) );
			}
		} elseif ( 0 === strpos( $dio, 'suradnje' ) ) {
			if ( 'suradnje' === $dio ) {
				$res = OLR_Suradnje::write( 'rucno' );
			} elseif ( 'suradnje_trazi' === $dio ) {
				// Web pretraga se naplaćuje po upitu: samo uz potvrdu korisnika u ploči.
				if ( 'da' !== (string) $r->get_param( 'potvrda' ) ) {
					return new WP_Error( 'olr_potvrda', 'Web pretraga se naplaćuje (Anthropic API); potrebna je potvrda.', array( 'status' => 400 ) );
				}
				$res = OLR_Suradnje::search( 'rucno' );
			} elseif ( 'suradnje_dr' === $dio ) {
				$res = OLR_Suradnje::dr_refresh();
			} elseif ( 'suradnje_gsc' === $dio ) {
				$res = OLR_Suradnje::import_gsc_links( (string) $r->get_param( 'csv' ) );
			} elseif ( 'suradnje_dodaj' === $dio ) {
				$res = OLR_Suradnje::add_manual( (string) $r->get_param( 'domena' ), sanitize_text_field( (string) $r->get_param( 'naziv' ) ), sanitize_key( (string) $r->get_param( 'vrsta' ) ) );
			} elseif ( 'suradnje_veza' === $dio ) {
				$res = OLR_Suradnje::recheck( sanitize_text_field( (string) $r->get_param( 'id' ) ) );
			} else {
				return new WP_Error( 'olr_dio', 'Nepoznat dio suradnji.', array( 'status' => 400 ) );
			}
			if ( ! is_wp_error( $res ) ) {
				$res = array(
					'ishod'    => $res,
					'suradnje' => OLR_Suradnje::summary( 300 ),
				);
			}
		} elseif ( 'trziste' === $dio ) {
			// Tržište: pokretanje modula se plaća (DataForSEO), pa samo uz potvrdu korisnika u ploči.
			if ( 'da' !== (string) $r->get_param( 'potvrda' ) ) {
				return new WP_Error( 'olr_potvrda', 'Moduli Tržišta se naplaćuju (DataForSEO); potrebna je potvrda.', array( 'status' => 400 ) );
			}
			$req = OLR_Trziste::request( sanitize_key( (string) $r->get_param( 'modul' ) ), 'rucno' );
			if ( is_wp_error( $req ) ) {
				$req->add_data( array( 'status' => 400 ) );
				return $req;
			}
			$res = array(
				'pokrenuto' => $req,
				'napredak'  => OLR_Trziste::step( 20 ),
				'trziste'   => OLR_Trziste::summary( '', 300 ),
			);
		} elseif ( 'trziste_korak' === $dio ) {
			// Nastavak već potvrđenih poslova (ploča ponavlja dok ima koraka) i rezultati iz reda.
			$res = array(
				'napredak' => OLR_Trziste::step( 20 ),
				'trziste'  => OLR_Trziste::summary( '', 300 ),
			);
		} else {
			return new WP_Error( 'olr_dio', 'Nepoznat dio (gsc, rank, crawl, prijedlozi, mjerenje, ucinak, indeks, mreze, mreze_uzori, mreze_preporuke, suradnje, suradnje_trazi, suradnje_dr, suradnje_gsc, suradnje_dodaj, suradnje_veza, trziste, trziste_korak).', array( 'status' => 400 ) );
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
