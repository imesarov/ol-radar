<?php
/**
 * Sposobnosti (Abilities API) koje Claude vidi preko MCP-a. Sve su samo za čitanje sadržaja weba.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Abilities {

	const CATEGORY = 'ol-radar';

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'OL Radar',
				'description' => 'Podaci o webu Optike Ljiljana za analizu u OL Radaru: sadržaj, SEO oznake, audit i interni linkovi. Samo čitanje.',
			)
		);
	}

	public static function can_read() {
		return current_user_can( OLR_CAP );
	}

	/** Zajednički meta podaci: samo čitanje, izloženo MCP-u. */
	private static function meta() {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
			'mcp'          => array( 'public' => true ),
		);
	}

	private static function obj_schema( $props = array(), $required = array() ) {
		$s = array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => $props ? $props : new stdClass(),
			'additionalProperties' => false,
		);
		if ( $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$out = array( 'type' => 'object' );

		wp_register_ability(
			'ol-radar/pregled-weba',
			array(
				'label'               => 'Pregled weba',
				'description'         => 'Osnovno stanje weba: verzije WordPressa i PHP-a, tema, aktivni dodaci, je li web vidljiv tražilicama, broj objava po vrsti i stanje internog crawla. Pozovi prvo.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'site_overview' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ol-radar/popis-sadrzaja',
			array(
				'label'               => 'Popis sadržaja',
				'description'         => 'Popis objavljenih stranica, članaka i proizvoda s Rank Math podacima (SEO naslov, opis, fokus riječ, robots, canonical) i sažetkom zadnjeg crawla (stvarni naslov, H1, broj riječi, status). Straničenje po 50.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'vrsta'             => array(
							'type'        => 'string',
							'description' => 'Vrsta sadržaja, npr. page, post, product. Prazno = sve javne vrste.',
						),
						'trazi'             => array(
							'type'        => 'string',
							'description' => 'Traži u naslovu i sadržaju.',
						),
						'promijenjeno_nakon' => array(
							'type'        => 'string',
							'description' => 'Datum (YYYY-MM-DD): samo sadržaj mijenjan nakon tog datuma.',
						),
						'stranica'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'po_stranici'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'list_content' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ol-radar/stranica',
			array(
				'label'               => 'Detalji stranice',
				'description'         => 'Sve o jednoj stranici kako je vidi posjetitelj i Google: status, <title>, meta opis, canonical, robots, H1 i struktura naslova, broj riječi i tekst sadržaja, slike s alt tekstom, interni i vanjski linkovi, schema tipovi, Rank Math podaci te ulazni interni linkovi s drugih stranica. Zadaj url ili id. svjezi=true ponovno dohvaća stranicu.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'url'   => array(
							'type'        => 'string',
							'description' => 'Puni URL ili putanja (npr. /progresivne-naocale/) na ovom webu.',
						),
						'id'    => array(
							'type'        => 'integer',
							'description' => 'ID objave.',
						),
						'svjezi' => array(
							'type'        => 'boolean',
							'description' => 'Ponovno dohvati stranicu umjesto spremljenog stanja.',
						),
						'bez_teksta' => array(
							'type'        => 'boolean',
							'description' => 'Ne vraćaj puni tekst sadržaja (kraći odgovor).',
						),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'page_detail' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ol-radar/audit',
			array(
				'label'               => 'On-site audit',
				'description'         => 'Tehnički i on-page audit cijelog weba iz internog crawla: greške i preusmjerenja, pokvareni interni linkovi, noindex, canonical, naslovi i opisi (duljina, duplikati), H1, tanki sadržaj, slike bez alt i dimenzija, stranice bez ulaznih linkova iz sadržaja, Rank Math fokus riječ. Filtri: ozbiljnost (visoka|srednja|niska = do te razine), kodovi, samo_url.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'ozbiljnost' => array(
							'type' => 'string',
							'enum' => array( 'visoka', 'srednja', 'niska' ),
						),
						'kodovi'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'samo_url'   => array( 'type' => 'string' ),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 1000,
						),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'audit' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ol-radar/interni-linkovi',
			array(
				'label'               => 'Interni linkovi',
				'description'         => 'Graf internih linkova. S url: ulazni linkovi (od kuda, sidro, sadržaj ili navigacija) i izlazni linkovi te stranice. Bez url: sve stranice poredane po broju ulaznih linkova iz sadržaja, siročad prva. Za prijedloge internog linkanja.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'url' => array( 'type' => 'string' ),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'links' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'ol-radar/osvjezi-crawl',
			array(
				'label'               => 'Osvježi crawl',
				'description'         => 'Obradi sljedeću seriju stranica (nove, promijenjene ili starije od 7 dana) i provjeri ciljeve internih linkova. Mijenja samo interni cache ovog dodatka, ne sadržaj weba. Ponavljaj dok "preostalo" ne bude 0.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'velicina' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 25,
						),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'refresh' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => array_merge( self::meta(), array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ) ),
			)
		);

		/* ---------- Radar na hostingu: podaci koje skuplja server ---------- */
		wp_register_ability(
			'ol-radar/radar-sazetak',
			array(
				'label'               => 'Radar: sažetak',
				'description'         => 'Sažetak OL Radara s hostinga (export/radar): KPI-jevi, Google 28 dana, tjedni pregled, pozicije, audit, nalazi, plan. Uz to stanje izvora (Google, DataForSEO, Claude API) i starost sažetka. Sažetak piše nadzorna ploča kad se otvori; svježi Google podaci su u ol-radar/radar-gsc. Pozovi prvo za svaku analizu weba.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'radar_summary' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);
		wp_register_ability(
			'ol-radar/radar-gsc',
			array(
				'label'               => 'Radar: Google Search Console',
				'description'         => 'Google Search Console s hostinga (servisni račun, osvježava se svakih 12 h): upiti s klikovima, prikazivanjima, CTR-om, pozicijom i glavnom stranicom, za 28 ili zadnjih 7 dana, uz zbroj razdoblja. Filtar po tekstu upita ili stranice.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'filtar'    => array( 'type' => 'string', 'description' => 'Dio upita ili adrese stranice.' ),
						'razdoblje' => array( 'type' => 'string', 'enum' => array( '28', '7' ), 'description' => '28 (zadano) ili 7 dana.' ),
						'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'radar_gsc' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);
		wp_register_ability(
			'ol-radar/radar-pozicije',
			array(
				'label'               => 'Radar: pozicije',
				'description'         => 'Pozicije ciljanih riječi iz tjednog mjerenja (DataForSEO, Google, mobitel, Zagreb): sadašnja i prethodna pozicija, stranica koja rangira, povijest do 26 mjerenja (0 = nije pronađen u izmjerenoj dubini, null = nije mjereno) i top 10 konkurenata po riječi.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'konkurenti' => array( 'type' => 'boolean', 'description' => 'Uključi top 10 rezultata po riječi.' ),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'radar_rank' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);
		wp_register_ability(
			'ol-radar/radar-prijedlozi',
			array(
				'label'               => 'Radar: prijedlozi izmjena',
				'description'         => 'Gotovi prijedlozi izmjena iz dnevne analize na hostingu (naslov i opis, upiti blizu vrha, kanibalizacija, pad, pozicije, tehnički nalazi, novi sadržaj). Svaki ima dokaze s brojkama iz Search Consolea, sadašnji i predloženi tekst, korake i stanje (novo, prihvaceno, provedeno, odbijeno). Samo čitanje; web se mijenja tek nakon vlasnikova odobrenja.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::obj_schema(
					array(
						'stanje' => array( 'type' => 'string', 'enum' => array( 'otvoreni', 'svi', 'novo', 'prihvaceno', 'provedeno', 'odbijeno' ), 'description' => 'Zadano: otvoreni (novo i prihvaceno).' ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Najviše prijedloga (zadano 20).' ),
					)
				),
				'output_schema'       => $out,
				'execute_callback'    => array( __CLASS__, 'radar_proposals' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => self::meta(),
			)
		);
	}

	public static function radar_proposals( $input = array() ) {
		$f = isset( $input['stanje'] ) && in_array( $input['stanje'], array( 'otvoreni', 'svi', 'novo', 'prihvaceno', 'provedeno', 'odbijeno' ), true ) ? $input['stanje'] : 'otvoreni';
		$n = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 20;
		$s = OLR_Prijedlozi::summary( $f, $n );
		return array(
			'otvorenih'  => $s['otvorenih'],
			'ukupno'     => $s['ukupno'],
			'zadnja_analiza' => $s['zadnje'],
			'automatski' => $s['auto'],
			'prijedlozi' => $s['stavke'],
			'napomena'   => 'Prijedlozi su za odluku vlasnika. Izmjenu na webu radi tek nakon izričitog odobrenja.',
		);
	}

	public static function radar_summary( $input = array() ) {
		$ex  = OLR_Store::get( 'export', 'radar' );
		$age = $ex ? round( ( time() - strtotime( $ex['updated_at'] . ' UTC' ) ) / 3600, 1 ) : null;
		$st  = OLR_REST::state_array();
		unset( $st['postavke'] );
		return array(
			'sazetak'         => $ex ? $ex['data'] : null,
			'starost_sati'    => $age,
			'napomena'        => $ex ? ( $age > 168 ? 'Sažetak je stariji od 7 dana: otvori nadzornu ploču (wp-admin → OL Radar) da se osvježi.' : null ) : 'Sažetak još ne postoji: otvori nadzornu ploču jednom (wp-admin → OL Radar).',
			'gsc'             => OLR_Store::data( 'gsc', 'meta' ),
			'prijedlozi'      => array(
				'otvorenih' => OLR_Prijedlozi::summary( 'otvoreni', 1 )['otvorenih'],
				'napomena'  => 'Detalji: ol-radar/radar-prijedlozi.',
			),
			'stanje'          => $st,
		);
	}

	public static function radar_gsc( $input = array() ) {
		$days  = isset( $input['razdoblje'] ) && '7' === $input['razdoblje'] ? 7 : 28;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 100;
		$f     = isset( $input['filtar'] ) ? OLR_DFS::lower( $input['filtar'] ) : '';
		$meta  = OLR_Store::data( 'gsc', 'meta' );
		if ( ! $meta ) {
			return array( 'greska' => 'Google podaci još nisu preuzeti na hostingu (OL Radar → Postavke).' );
		}
		if ( 7 === $days ) {
			$wk   = OLR_Store::data( 'gsc', 'wk', array() );
			$rows = isset( $wk['qp7'] ) ? $wk['qp7'] : array();
			$od   = isset( $wk['range']['s'] ) ? $wk['range']['s'] : null;
			$do   = isset( $wk['range']['e'] ) ? $wk['range']['e'] : null;
		} else {
			$qp   = OLR_Store::data( 'gsc', 'qp', array() );
			$rows = isset( $qp['rows'] ) ? $qp['rows'] : array();
			$od   = $meta['prozori']['28_dana'][0];
			$do   = $meta['prozori']['28_dana'][1];
		}
		$agg = array();
		foreach ( $rows as $r ) {
			list( $q, $page, $c, $i, , $pos ) = $r;
			if ( '' !== $f && false === strpos( OLR_DFS::lower( $q ), $f ) && false === strpos( OLR_DFS::lower( $page ), $f ) ) {
				continue;
			}
			if ( ! isset( $agg[ $q ] ) ) {
				$agg[ $q ] = array( 'q' => $q, 'c' => 0, 'i' => 0, 'pw' => 0, 'top' => $page, 'top_i' => -1, 'stranica' => 0 );
			}
			$a            = &$agg[ $q ];
			$a['c']      += $c;
			$a['i']      += $i;
			$a['pw']     += $pos * $i;
			$a['stranica']++;
			if ( $i > $a['top_i'] ) {
				$a['top']   = $page;
				$a['top_i'] = $i;
			}
			unset( $a );
		}
		usort(
			$agg,
			static function ( $x, $y ) {
				return $y['i'] - $x['i'];
			}
		);
		$out = array();
		$tc  = 0;
		$ti  = 0;
		foreach ( $agg as $a ) {
			$tc += $a['c'];
			$ti += $a['i'];
		}
		foreach ( array_slice( $agg, 0, $limit ) as $a ) {
			$out[] = array(
				'upit'          => $a['q'],
				'klikovi'       => $a['c'],
				'prikazivanja'  => $a['i'],
				'ctr'           => $a['i'] ? round( $a['c'] / $a['i'], 4 ) : 0,
				'pozicija'      => $a['i'] ? round( $a['pw'] / $a['i'], 1 ) : null,
				'stranica'      => wp_parse_url( $a['top'], PHP_URL_PATH ),
				'broj_stranica' => $a['stranica'],
			);
		}
		return array(
			'od'           => $od,
			'do'           => $do,
			'svojstvo'     => $meta['site'],
			'preuzeto'     => gmdate( 'c', (int) ( $meta['at'] / 1000 ) ),
			'ukupno_upita' => count( $agg ),
			'zbroj'        => array( 'klikovi' => $tc, 'prikazivanja' => $ti ),
			'upiti'        => $out,
			'napomena'     => 'Zbroj je zbroj redaka upit+stranica; Google skriva rijetke (anonimne) upite, pa je manji od ukupnog prometa.',
		);
	}

	public static function radar_rank( $input = array() ) {
		$latest = OLR_Store::data( 'rank', 'latest' );
		if ( ! $latest ) {
			return array( 'greska' => get_option( OLR_DFS::PENDING ) ? 'Prvo mjerenje je u tijeku.' : 'Pozicije još nisu izmjerene na hostingu (OL Radar → Postavke).' );
		}
		$hist = OLR_Store::data( 'rank', 'hist', array( 'runs' => array(), 'by' => array() ) );
		$rows = array();
		foreach ( $latest['rows'] as $r ) {
			$row = array(
				'rijec'    => $r['kw'],
				'pozicija' => $r['pos'],
				'prije'    => $r['prev'],
				'stranica' => $r['url'] ? wp_parse_url( $r['url'], PHP_URL_PATH ) : null,
				'povijest' => isset( $hist['by'][ $r['id'] ] ) ? $hist['by'][ $r['id'] ] : array(),
			);
			if ( ! empty( $input['konkurenti'] ) ) {
				$serp            = OLR_Store::data( 'serp', $r['id'] );
				$row['top10']    = $serp ? array_map(
					static function ( $p ) {
						return array( 'p' => $p['pos'], 'url' => $p['url'] );
					},
					array_slice( $serp['p'], 0, 10 )
				) : array();
			}
			$rows[] = $row;
		}
		return array(
			'mjereno'   => $latest['last'],
			'postavke'  => $latest['sched'],
			'mjerenja'  => $hist['runs'],
			'u_tijeku'  => (bool) get_option( OLR_DFS::PENDING ),
			'rijeci'    => $rows,
		);
	}

	/* ---------------------------------------------------------------------
	 * Izvršavanje
	 * ------------------------------------------------------------------- */

	public static function site_overview( $input = array() ) {
		global $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		$all     = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = array(
					'naziv'   => $all[ $file ]['Name'],
					'verzija' => $all[ $file ]['Version'],
				);
			}
		}
		$theme  = wp_get_theme();
		$counts = array();
		foreach ( OLR_Crawler::post_types() as $pt ) {
			$c               = wp_count_posts( $pt );
			$counts[ $pt ] = isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return array(
			'web'          => array(
				'naziv'            => get_bloginfo( 'name' ),
				'url'              => home_url( '/' ),
				'wordpress'        => $wp_version,
				'php'              => PHP_VERSION,
				'jezik'            => get_locale(),
				'vremenska_zona'   => wp_timezone_string(),
				'permalinks'       => (string) get_option( 'permalink_structure' ),
				'vidljiv_trazilicama' => '1' === (string) get_option( 'blog_public' ),
				'naslovnica'       => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 'zadnje objave',
			),
			'tema'         => array(
				'naziv'    => $theme->get( 'Name' ),
				'verzija'  => $theme->get( 'Version' ),
				'roditelj' => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'dodaci'       => $plugins,
			'rank_math'    => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
			'sitemap'      => defined( 'RANK_MATH_VERSION' ) ? home_url( '/sitemap_index.xml' ) : home_url( '/wp-sitemap.xml' ),
			'objave'       => $counts,
			'crawl'        => OLR_Crawler::stats(),
			'most_verzija' => OLR_VERSION,
		);
	}

	public static function list_content( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$per      = isset( $input['po_stranici'] ) ? (int) $input['po_stranici'] : 50;
		$page     = isset( $input['stranica'] ) ? (int) $input['stranica'] : 1;
		$types    = ! empty( $input['vrsta'] ) ? array( sanitize_key( $input['vrsta'] ) ) : OLR_Crawler::post_types();
		$qargs    = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $per,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['trazi'] ) ) {
			$qargs['s'] = sanitize_text_field( $input['trazi'] );
		}
		if ( ! empty( $input['promijenjeno_nakon'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input['promijenjeno_nakon'] ) ) {
			$qargs['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $input['promijenjeno_nakon'],
				),
			);
		}
		$q     = new WP_Query( $qargs );
		$items = array();
		foreach ( $q->posts as $p ) {
			$url  = OLR_Crawler::normalize( get_permalink( $p ) );
			$row  = OLR_Crawler::get_row( $url );
			$cr   = null;
			if ( $row && $row->fetched_at ) {
				$d  = $row->data;
				$cr = array(
					'status'      => (int) $row->status,
					'dohvaceno'   => OLR_Crawler::fetched_label( $row->fetched_at ),
					'naslov_html' => isset( $d['naslov'] ) ? $d['naslov'] : null,
					'opis_html'   => isset( $d['opis'] ) ? $d['opis'] : null,
					'h1'          => isset( $d['h1'] ) ? $d['h1'] : array(),
					'rijeci'      => isset( $d['rijeci'] ) ? (int) $d['rijeci'] : null,
					'robots'      => isset( $d['robots'] ) ? $d['robots'] : null,
				);
			}
			$items[] = array(
				'id'          => $p->ID,
				'vrsta'       => $p->post_type,
				'url'         => $url,
				'naslov'      => get_the_title( $p ),
				'objavljeno'  => $p->post_date,
				'promijenjeno' => $p->post_modified,
				'elementor'   => 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true ),
				'predlozak'   => (string) get_page_template_slug( $p ),
				'roditelj'    => (int) $p->post_parent,
				'rank_math'   => OLR_Audit::rank_math( $p->ID ),
				'crawl'       => $cr,
			);
		}
		return array(
			'ukupno'     => (int) $q->found_posts,
			'stranica'   => $page,
			'stranica_ukupno' => (int) $q->max_num_pages,
			'stavke'     => $items,
		);
	}

	/** Pretvara url/putanju/id u normalizirani URL na ovom webu. */
	private static function resolve_url( $input ) {
		if ( ! empty( $input['id'] ) ) {
			$p = get_post( (int) $input['id'] );
			if ( ! $p || 'publish' !== $p->post_status ) {
				return new WP_Error( 'olr_not_found', 'Objava s tim ID-om ne postoji ili nije objavljena.' );
			}
			return array( OLR_Crawler::normalize( get_permalink( $p ) ), (int) $p->ID );
		}
		if ( empty( $input['url'] ) ) {
			return new WP_Error( 'olr_missing', 'Zadaj url ili id.' );
		}
		$u = trim( (string) $input['url'] );
		if ( '/' === substr( $u, 0, 1 ) ) {
			$u = home_url( $u );
		} elseif ( ! preg_match( '#^https?://#i', $u ) ) {
			$u = home_url( '/' . ltrim( $u, '/' ) );
		}
		if ( ! OLR_Crawler::is_internal( $u ) ) {
			return new WP_Error( 'olr_external', 'Dopušteni su samo URL-ovi na ovom webu.' );
		}
		$n   = OLR_Crawler::normalize( $u );
		$inv = OLR_Crawler::inventory();
		$pid = 0;
		foreach ( OLR_Crawler::variants( $n ) as $v ) {
			if ( isset( $inv[ $v ] ) ) {
				$n   = $v;
				$pid = (int) $inv[ $v ];
				break;
			}
		}
		if ( ! $pid ) {
			$pid = (int) url_to_postid( $n );
		}
		return array( $n, $pid );
	}

	public static function page_detail( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$res   = self::resolve_url( $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		list( $url, $pid ) = $res;

		$row = OLR_Crawler::get_row( $url );
		$inv = OLR_Crawler::inventory();
		$in_inventory = false;
		foreach ( OLR_Crawler::variants( $url ) as $v ) {
			if ( isset( $inv[ $v ] ) ) {
				$in_inventory = true;
			}
		}
		$stale = ! $row || null === $row->fetched_at || 'page' !== $row->kind
			|| strtotime( $row->fetched_at . ' UTC' ) < time() - OLR_Crawler::MAX_AGE_DAYS * DAY_IN_SECONDS
			|| ( $pid && strtotime( $row->fetched_at . ' UTC' ) < strtotime( get_post_field( 'post_modified_gmt', $pid ) . ' UTC' ) );

		if ( ! empty( $input['svjezi'] ) || $stale ) {
			OLR_Crawler::crawl_url( $url, $pid, 'page' );
			if ( ! $in_inventory ) {
				// URL izvan popisa objava (npr. arhiva, stari slug): puni dohvat, ali se vodi kao cilj linka, ne kao stranica.
				global $wpdb;
				$wpdb->update( OLR_Crawler::table(), array( 'kind' => 'target' ), array( 'url_hash' => md5( $url ) ) );
			}
			$row = OLR_Crawler::get_row( $url );
		}
		if ( ! $row ) {
			return new WP_Error( 'olr_fetch', 'Stranicu nije moguće dohvatiti.' );
		}

		$d = $row->data;
		if ( ! empty( $input['bez_teksta'] ) ) {
			unset( $d['tekst'] );
		}

		$rows    = OLR_Crawler::all_rows();
		$inbound = OLR_Audit::inbound_map( $rows );
		$in      = array();
		foreach ( OLR_Crawler::variants( $row->url ) as $v ) {
			if ( isset( $inbound[ $v ] ) ) {
				$in = array_merge( $in, $inbound[ $v ] );
			}
		}

		return array(
			'url'        => $row->url,
			'post_id'    => (int) $row->post_id ? (int) $row->post_id : $pid,
			'u_popisu_objava' => $in_inventory,
			'status'     => (int) $row->status,
			'vrijeme_ms' => (int) $row->ms,
			'dohvaceno'  => OLR_Crawler::fetched_label( $row->fetched_at ),
			'html'       => $d,
			'rank_math'  => OLR_Audit::rank_math( $pid ),
			'ulazni_linkovi' => $in,
			'nalazi'     => OLR_Audit::run( array( 'samo_url' => $row->url, 'limit' => 100 ) )['nalazi'],
		);
	}

	public static function audit( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$stats = OLR_Crawler::stats();
		if ( 0 === $stats['stranica_u_popisu'] ) {
			OLR_Crawler::sync_inventory();
		}
		$res = OLR_Audit::run( $input );
		if ( $res['stanje_crawla']['zastarjelo'] > 0 ) {
			$res['napomena'] = 'Dio stranica još nije obrađen ili je zastario. Pozovi ol-radar/osvjezi-crawl dok "preostalo" ne bude 0 pa ponovi audit.';
		}
		return $res;
	}

	public static function links( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$rows    = OLR_Crawler::all_rows();
		$inbound = OLR_Audit::inbound_map( $rows );

		if ( ! empty( $input['url'] ) ) {
			$res = self::resolve_url( $input );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$url = $res[0];
			$row = null;
			foreach ( OLR_Crawler::variants( $url ) as $v ) {
				if ( isset( $rows[ $v ] ) ) {
					$row = $rows[ $v ];
					$url = $v;
					break;
				}
			}
			$out = array();
			if ( $row && ! empty( $row->data['linkovi'] ) ) {
				foreach ( $row->data['linkovi'] as $l ) {
					$out[] = array(
						'na'       => $l['u'],
						'sidro'    => $l['a'],
						'kontekst' => 's' === $l['k'] ? 'sadrzaj' : 'navigacija',
						'nofollow' => (bool) $l['nf'],
					);
				}
			}
			return array(
				'url'      => $url,
				'ulazni'   => isset( $inbound[ $url ] ) ? $inbound[ $url ] : array(),
				'izlazni'  => $out,
				'napomena' => $row ? null : 'Stranica još nije obrađena; pozovi ol-radar/osvjezi-crawl.',
			);
		}

		$list = array();
		foreach ( $rows as $u => $r ) {
			if ( 'page' !== $r->kind ) {
				continue;
			}
			$ctx = 0;
			$nav = 0;
			$from = array();
			foreach ( isset( $inbound[ $u ] ) ? $inbound[ $u ] : array() as $l ) {
				if ( 'sadrzaj' !== $l['kontekst'] ) {
					++$nav;
				} elseif ( ! $l['nofollow'] ) {
					++$ctx;
					$from[ $l['od'] ] = true;
				}
			}
			$out_ctx = 0;
			foreach ( isset( $r->data['linkovi'] ) ? $r->data['linkovi'] : array() as $l ) {
				if ( 's' === $l['k'] ) {
					++$out_ctx;
				}
			}
			$list[] = array(
				'url'                => $u,
				'post_id'            => (int) $r->post_id,
				'ulazni_iz_sadrzaja' => $ctx, // samo dofollow
				'stranica_koje_linkaju' => count( $from ),
				'ulazni_iz_navigacije' => $nav,
				'izlazni_iz_sadrzaja' => $out_ctx,
			);
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return $a['ulazni_iz_sadrzaja'] - $b['ulazni_iz_sadrzaja'];
			}
		);
		return array(
			'stranice'      => $list,
			'stanje_crawla' => OLR_Crawler::stats(),
		);
	}

	public static function refresh( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$size  = isset( $input['velicina'] ) ? (int) $input['velicina'] : 10;
		return OLR_Crawler::run_batch( $size, 25 );
	}
}
