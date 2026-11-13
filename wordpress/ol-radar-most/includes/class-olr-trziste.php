<?php
/**
 * Tržište (od 2.5.0): sve što DataForSEO daje za lokalnu optiku, uz Search Console kao glavni izvor o našem webu.
 *
 * Moduli (svaki se uključuje zasebno u Postavkama; zadano su isključeni jer se plaćaju):
 *  - lokalno      Google Maps za lokalne upite: tko je na karti, naše mjesto, ocjene i broj recenzija (tjedno)
 *  - profili      Google profili tvrtki: mi i konkurenti s karte (ocjena, recenzije, kategorije, fotografije, teme)
 *  - recenzije    najnovije recenzije za nas i najjače konkurente, odgovori vlasnika (tjedno)
 *  - volumen      obujam pretraga iz Google Adsa (do 4 godine mjesečno) i nove ideje (mjesečno)
 *  - kljucne      DataForSEO Labs: za što rangiramo mi i konkurenti, praznine, vidljivost domena (mjesečno)
 *  - poveznice    rank domena, naše domene s poveznicom, domene koje povezuju konkurente a nas ne (mjesečno)
 *  - spominjanja  gdje se na webu spominje Optika Ljiljana i postoji li poveznica (mjesečno)
 *  - brzina       Lighthouse na mobitelu za najvažnije stranice (mjesečno)
 *  - ai           što ChatGPT, Gemini, Perplexity (i po želji Claude) odgovaraju na pitanja kupaca (mjesečno)
 *
 * Posao se izvodi u koracima: satni prolaz izvrši koliko stane u kratki vremenski okvir, a zadaci u
 * standardnom redu (karta, profili, recenzije, Google Ads, Lighthouse) čekaju rezultat u sljedećim prolazima.
 * Svaki plaćeni korak provjerava mjesečni limit troška (zajednički s mjerenjem pozicija).
 *
 * Cijene (dataforseo.com, provjereno 13. 11. 2026.) su u konstantama P_*; stvarni trošak se uvijek uzima iz
 * odgovora API-ja.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Trziste {

	const COLL   = 'trziste';
	const RUN    = 'trziste_run';
	const JOBS   = 'olr_trziste_jobs';
	const QUEUE  = 'olr_trziste_q';
	const LOC    = 2191; // Hrvatska
	const LANG   = 'hr';
	/** Sekunde rada u jednom prolazu (satni prolaz i klik u ploči). */
	const BUDGET = 25;
	/** Posao koji nije gotov za tri dana završava s onim što je stiglo. */
	const MAX_DANA = 3;

	const P_MAPS     = 0.0006;   // po rezultatu karte (standardni red, do 100 mjesta)
	const P_PROFIL   = 0.0015;   // po profilu tvrtke (standardni red)
	const P_REC10    = 0.00075;  // po 10 recenzija (standardni red)
	const P_ADS      = 0.06;     // po zadatku Google Ads (do 1.000 riječi, standardni red)
	const P_LABS     = 0.012;    // Labs: po zahtjevu
	const P_LABS_RED = 0.00012;  // Labs: po retku
	const P_BL       = 0.024;    // Backlinks i Content Analysis: po zahtjevu
	const P_BL_RED   = 0.000036; // Backlinks i Content Analysis: po retku
	const P_LH       = 0.005;    // Lighthouse: po stranici
	const P_AI       = 0.03;     // procjena po odgovoru AI modela s web pretragom (stvarni iznos je u odgovoru)

	/** Moduli redom kojim se izvode kad se traže svi (profili trebaju kartu, recenzije profile). */
	const MODULI = array(
		'lokalno'     => array( 'naziv' => 'Lokalno: Google karta', 'ritam' => 'tjedno', 'treba' => '' ),
		'profili'     => array( 'naziv' => 'Google profili: mi i konkurenti', 'ritam' => 'tjedno', 'treba' => 'lokalno' ),
		'recenzije'   => array( 'naziv' => 'Recenzije na Googleu', 'ritam' => 'tjedno', 'treba' => 'profili' ),
		'volumen'     => array( 'naziv' => 'Obujam pretraga (Google Ads)', 'ritam' => 'mjesecno', 'treba' => '' ),
		'kljucne'     => array( 'naziv' => 'Ključne riječi: mi i konkurenti', 'ritam' => 'mjesecno', 'treba' => '' ),
		'poveznice'   => array( 'naziv' => 'Poveznice: mi i konkurenti', 'ritam' => 'mjesecno', 'treba' => '' ),
		'spominjanja' => array( 'naziv' => 'Spominjanja na webu', 'ritam' => 'mjesecno', 'treba' => '' ),
		'brzina'      => array( 'naziv' => 'Brzina stranica (Lighthouse)', 'ritam' => 'mjesecno', 'treba' => '' ),
		'ai'          => array( 'naziv' => 'AI asistenti: spominju li nas', 'ritam' => 'mjesecno', 'treba' => '' ),
	);

	const AI_PLATFORME = array(
		'chat_gpt'   => 'ChatGPT',
		'gemini'     => 'Gemini',
		'perplexity' => 'Perplexity',
		'claude'     => 'Claude',
	);

	/* ---------- postavke i pomoćno ---------- */

	public static function enabled( $m ) {
		return isset( self::MODULI[ $m ] ) && (bool) OLR_Settings::get( 'dfs_' . $m );
	}

	private static function lines( $key, $max ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n|,/', (string) OLR_Settings::get( $key ) ) as $l ) {
			$l = trim( $l );
			if ( '' !== $l && ! in_array( OLR_DFS::lower( $l ), array_map( array( 'OLR_DFS', 'lower' ), $out ), true ) ) {
				$out[] = $l;
			}
		}
		return array_slice( $out, 0, $max );
	}

	public static function local_keywords() {
		return self::lines( 'dfs_lokalne_rijeci', 20 );
	}

	public static function ai_prompts() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) OLR_Settings::get( 'dfs_ai_pitanja' ) ) as $l ) {
			$l = trim( $l );
			if ( '' !== $l ) {
				$out[] = function_exists( 'mb_substr' ) ? mb_substr( $l, 0, 480, 'UTF-8' ) : substr( $l, 0, 480 );
			}
		}
		return array_slice( $out, 0, 10 );
	}

	public static function ai_platforms() {
		$out = array();
		foreach ( preg_split( '/[,\s]+/', (string) OLR_Settings::get( 'dfs_ai_platforme' ) ) as $p ) {
			$p = sanitize_key( $p );
			if ( isset( self::AI_PLATFORME[ $p ] ) && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function cut( $s, $n ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	private static function num( $v ) {
		return is_numeric( $v ) ? $v + 0 : null;
	}

	private static function own() {
		return OLR_Suradnje::own();
	}

	/** Riječi brenda (postavka „Riječi brenda”), za prepoznavanje nas na karti i u odgovorima AI asistenata. */
	private static function brand_words() {
		$out = array();
		foreach ( preg_split( '/[,]+/', (string) OLR_Settings::get( 'brand' ) ) as $b ) {
			$b = OLR_DFS::lower( $b );
			if ( strlen( $b ) >= 4 ) {
				$out[] = $b;
			}
		}
		return $out ? $out : array( 'ljiljana' );
	}

	private static function is_brand_text( $s ) {
		$t = OLR_DFS::lower( $s );
		foreach ( self::brand_words() as $b ) {
			// korijen riječi (ljiljana → ljiljan), da se prepoznaju i padeži
			if ( false !== strpos( $t, substr( $b, 0, max( 4, strlen( $b ) - 1 ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Domena bez www iz adrese ili naziva. */
	private static function host( $u ) {
		return OLR_Suradnje::host( (string) $u );
	}

	/** Domene koje nisu konkurentske tvrtke: mreže, forumi, mediji, imenici, proizvođači. */
	private static function not_rival( $d ) {
		return OLR_Suradnje::blocked( $d ) || (bool) preg_match( '/(^|\.)(forum|reddit|wikipedia|mojkvart|moja-djelatnost|tvrtke|obrtnici|index|jutarnji|vecernji|telegram|dnevnik|tportal|net|24sata|rtl|hzz|mojposao|njuskalo|dm|lentiamo|essilor|zeiss|hoya|rodenstock|webmd|trustpilot|ponudadana|dajsve|waze|benefitsystems|kvantum-tim|pejdah-pharmacia|krenizdravo|mef|vvg|tsrb|adorio|klix|istra24|unimedpharma|cvikeri|badu|deminas|layoners)\./', $d );
	}

	/**
	 * Konkurenti za usporedbu: ručno upisani (Postavke → Konkurenti), zatim domene koje su u našim mjerenjima
	 * pozicija najčešće u prvih 10 (barem 3 riječi), bez mreža, medija i imenika. Najviše 8.
	 */
	public static function rivals() {
		$out = OLR_Suradnje::competitors();
		$k   = OLR_Store::data( 'rank', 'konkurenti' );
		foreach ( $k && ! empty( $k['domene'] ) ? $k['domene'] : array() as $d ) {
			$h = self::host( isset( $d['domena'] ) ? $d['domena'] : '' );
			if ( '' === $h || ! empty( $d['nasa'] ) || (int) $d['top10'] < 3 || in_array( $h, $out, true ) || self::not_rival( $h ) ) {
				continue;
			}
			$out[] = $h;
		}
		$own = self::own();
		return array_values( array_slice( array_diff( $out, array( $own ) ), 0, 8 ) );
	}

	/* ---------- stanje poslova ---------- */

	public static function jobs() {
		$j = get_option( self::JOBS, array() );
		return is_array( $j ) ? $j : array();
	}

	private static function save_jobs( $j ) {
		update_option( self::JOBS, $j, false );
	}

	private static function queue() {
		$q = get_option( self::QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	private static function save_queue( $q ) {
		update_option( self::QUEUE, $q, false );
	}

	/** Potrošeno na DataForSEO ovaj mjesec (pozicije i Tržište zajedno). */
	public static function month_spent() {
		$c = get_option( 'olr_dfs_cost', array() );
		$m = gmdate( 'Y-m' );
		return is_array( $c ) && isset( $c[ $m ] ) ? (float) $c[ $m ] : 0.0;
	}

	public static function limit() {
		$l = (float) OLR_Settings::get( 'dfs_limit' );
		return $l > 0 ? $l : 5.0;
	}

	/* ---------- procjena troška ---------- */

	/** Procjena jednog prolaza modula u USD. */
	public static function estimate( $m ) {
		$r = count( self::rivals() );
		switch ( $m ) {
			case 'lokalno':
				return round( count( self::local_keywords() ) * self::P_MAPS, 4 );
			case 'profili':
				return round( ( 1 + min( 8, max( 4, $r ) ) ) * self::P_PROFIL, 4 );
			case 'recenzije':
				return round( 5 * 2 * self::P_REC10, 4 );
			case 'volumen':
				return round( 2 * self::P_ADS, 4 );
			case 'kljucne':
				return round( ( 1 + $r ) * ( self::P_LABS + self::P_LABS_RED ) + ( self::P_LABS + 1000 * self::P_LABS_RED ) + $r * ( self::P_LABS + 500 * self::P_LABS_RED ), 4 );
			case 'poveznice':
				return round( ( 1 + $r ) * ( self::P_BL + self::P_BL_RED ) + ( self::P_BL + 200 * self::P_BL_RED ) + ( self::P_BL + 500 * self::P_BL_RED ) + min( 5, $r ) * ( self::P_BL + 50 * self::P_BL_RED ), 4 );
			case 'spominjanja':
				return round( self::P_BL + 100 * self::P_BL_RED, 4 );
			case 'brzina':
				return round( 9 * self::P_LH, 4 );
			case 'ai':
				return round( count( self::ai_prompts() ) * count( self::ai_platforms() ) * self::P_AI, 4 );
		}
		return 0.0;
	}

	/** Procjena mjesečno: tjedni moduli × 4,35, mjesečni jednom. */
	public static function estimate_month( $only_enabled = true ) {
		$sum = 0.0;
		foreach ( self::MODULI as $m => $d ) {
			if ( $only_enabled && ! self::enabled( $m ) ) {
				continue;
			}
			$sum += self::estimate( $m ) * ( 'tjedno' === $d['ritam'] ? 4.35 : 1 );
		}
		return round( $sum, 2 );
	}

	/* ---------- pokretanje ---------- */

	/**
	 * Zahtjev za izvođenje modula (ili 'sve' uključenih). Posao kreće u sljedećem prolazu (ili odmah u istom zahtjevu
	 * preko step()). Ne pokreće se ako je mjesečni limit potrošen.
	 */
	public static function request( $m, $how = 'rucno' ) {
		if ( ! OLR_DFS::has_credentials() ) {
			return new WP_Error( 'olr_dfs_nema', 'DataForSEO pristup nije postavljen (OL Radar → Postavke).' );
		}
		$list = 'sve' === $m ? array_keys( array_filter( array_combine( array_keys( self::MODULI ), array_map( array( __CLASS__, 'enabled' ), array_keys( self::MODULI ) ) ) ) ) : array( $m );
		if ( 'sve' === $m && ! $list ) {
			return new WP_Error( 'olr_trziste_iskljuceno', 'Nijedan modul Tržišta nije uključen (Postavke → Tržište).' );
		}
		$jobs = self::jobs();
		$est  = 0.0;
		foreach ( $list as $x ) {
			if ( ! isset( self::MODULI[ $x ] ) ) {
				return new WP_Error( 'olr_trziste_modul', 'Nepoznat modul: ' . $x );
			}
			$est += self::estimate( $x );
		}
		if ( self::month_spent() + $est > self::limit() ) {
			return new WP_Error( 'olr_trziste_limit', sprintf( 'Mjesečni limit DataForSEO troška je %.2f $, a ovaj mjesec je potrošeno %.2f $. Procjena traženog je %.2f $. Limit se mijenja u Postavkama.', self::limit(), self::month_spent(), $est ) );
		}
		$added = array();
		foreach ( $list as $x ) {
			if ( isset( $jobs[ $x ] ) && in_array( $jobs[ $x ]['stanje'], array( 'ceka', 'radi', 'rezultati' ), true ) ) {
				continue;
			}
			$jobs[ $x ] = array_merge(
				isset( $jobs[ $x ] ) ? $jobs[ $x ] : array(),
				array(
					'stanje'  => 'ceka',
					'nacin'   => $how,
					'trazeno' => time(),
					'koraci'  => array(),
					'trosak'  => 0.0,
					'poruka'  => '',
				)
			);
			$added[] = $x;
		}
		self::save_jobs( $jobs );
		return array(
			'moduli'   => $added,
			'procjena' => round( $est, 4 ),
		);
	}

	/** Je li modul zbog rasporeda na redu (tjedno 7 dana, mjesečno 30 dana od zadnjeg uspješnog prolaza). */
	private static function due( $m, $job ) {
		if ( ! self::enabled( $m ) ) {
			return false;
		}
		if ( $job && in_array( $job['stanje'], array( 'ceka', 'radi', 'rezultati' ), true ) ) {
			return false;
		}
		$last  = $job && ! empty( $job['zadnje_ok'] ) ? (int) $job['zadnje_ok'] : 0;
		$tried = $job && ! empty( $job['pokusaj'] ) ? (int) $job['pokusaj'] : 0;
		$every = 'tjedno' === self::MODULI[ $m ]['ritam'] ? 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS : 30 * DAY_IN_SECONDS;
		// Nakon neuspjelog pokušaja (pokušaj noviji od zadnjeg uspjeha) novi tek za dan.
		return time() - $last > $every && ! ( $tried > $last && time() - $tried < DAY_IN_SECONDS );
	}

	/** Satni prolaz: raspored, rezultati iz reda, pa koraci aktivnog posla u kratkom vremenskom okviru. */
	public static function tick( $budget = self::BUDGET ) {
		if ( ! OLR_DFS::has_credentials() ) {
			return null;
		}
		$jobs = self::jobs();
		$auto = array();
		foreach ( array_keys( self::MODULI ) as $m ) {
			if ( self::due( $m, isset( $jobs[ $m ] ) ? $jobs[ $m ] : null ) ) {
				$auto[] = $m;
			}
		}
		foreach ( $auto as $m ) {
			$r = self::request( $m, 'auto' );
			if ( is_wp_error( $r ) ) {
				$jobs              = self::jobs();
				$jobs[ $m ]        = array_merge( isset( $jobs[ $m ] ) ? $jobs[ $m ] : array(), array( 'pokusaj' => time(), 'poruka' => $r->get_error_message() ) );
				$jobs[ $m ]['stanje'] = isset( $jobs[ $m ]['stanje'] ) ? $jobs[ $m ]['stanje'] : 'gotovo';
				self::save_jobs( $jobs );
				OLR_Settings::set_status( 'trziste_' . $m, false, $r->get_error_message() );
			}
		}
		return self::step( $budget );
	}

	/**
	 * Jedan prolaz rada: pokupi gotove zadatke iz reda, pokreni posao koji čeka (ako mu ne treba drugi koji još radi)
	 * i izvrši njegove korake dok ne istekne vremenski okvir.
	 *
	 * @return array Kratko stanje (za ploču koja ponavlja poziv dok ima posla).
	 */
	public static function step( $budget = self::BUDGET ) {
		$t0 = time();
		self::poll_queue( 30 );
		$jobs = self::jobs();
		foreach ( array_keys( self::MODULI ) as $m ) {
			if ( time() - $t0 >= $budget ) {
				break;
			}
			$j = isset( $jobs[ $m ] ) ? $jobs[ $m ] : null;
			if ( ! $j ) {
				continue;
			}
			if ( 'ceka' === $j['stanje'] ) {
				$need = self::MODULI[ $m ]['treba'];
				if ( $need && isset( $jobs[ $need ] ) && in_array( $jobs[ $need ]['stanje'], array( 'ceka', 'radi', 'rezultati' ), true ) ) {
					continue; // npr. profili čekaju da karta završi
				}
				self::begin( $m );
				$jobs = self::jobs();
				$j    = $jobs[ $m ];
			}
			if ( 'radi' === $j['stanje'] ) {
				self::run_steps( $m, $t0, $budget );
				$jobs = self::jobs();
				$j    = $jobs[ $m ];
			}
			if ( in_array( $j['stanje'], array( 'radi', 'rezultati' ), true ) ) {
				self::maybe_finish( $m );
				$jobs = self::jobs();
			}
		}
		return self::progress();
	}

	public static function progress() {
		$jobs  = self::jobs();
		$open  = array();
		foreach ( $jobs as $m => $j ) {
			if ( isset( $j['stanje'] ) && in_array( $j['stanje'], array( 'ceka', 'radi', 'rezultati' ), true ) ) {
				$open[ $m ] = $j['stanje'];
			}
		}
		$steps = 0;
		foreach ( $jobs as $j ) {
			$steps += isset( $j['koraci'] ) ? count( (array) $j['koraci'] ) : 0;
		}
		return array(
			'u_tijeku' => $open,
			'koraka'   => $steps,
			'u_redu'   => count( self::queue() ),
			// ploča ponavlja klik dok ima koraka koje može odmah izvršiti; rezultati iz reda stižu sami
			'jos'      => $steps > 0,
		);
	}

	/** Pripremi korake posla. */
	private static function begin( $m ) {
		$jobs  = self::jobs();
		$steps = self::plan( $m );
		OLR_Store::set( self::RUN, $m, array( 'dijelovi' => array() ) );
		$jobs[ $m ] = array_merge(
			$jobs[ $m ],
			array(
				'stanje'  => $steps ? 'radi' : 'rezultati',
				'pocetak' => time(),
				'pokusaj' => time(),
				'koraci'  => $steps,
				'trosak'  => 0.0,
				'poruka'  => $steps ? '' : 'nema što izvršiti (provjeri postavke modula)',
			)
		);
		self::save_jobs( $jobs );
		OLR_Settings::set_status( 'trziste_' . $m, true, 'u tijeku: ' . count( $steps ) . ' koraka' );
	}

	/** Koraci posla. Svaki korak: vrsta (live, post, get), putanja, tijelo, procjena i oznaka za obradu. */
	private static function plan( $m ) {
		$st     = array();
		$own    = self::own();
		$rivals = self::rivals();
		$coord  = self::map_coordinate();
		switch ( $m ) {
			case 'lokalno':
				foreach ( self::local_keywords() as $i => $kw ) {
					$t = array(
						'keyword'       => $kw,
						'language_code' => self::LANG,
						'device'        => 'mobile',
						'os'            => 'android',
						'depth'         => 20,
						'tag'           => 'olr|lokalno|' . $i,
					);
					if ( $coord ) {
						$t['location_coordinate'] = $coord;
					} else {
						$t['location_name'] = OLR_Settings::get( 'rank_location' );
					}
					$st[] = array( 'k' => 'post', 'path' => 'serp/google/maps/task_post', 'get' => 'serp/google/maps/task_get/advanced/', 'body' => array( $t ), 'cijena' => self::P_MAPS, 'obrada' => 'maps', 'meta' => array( 'rijec' => $kw ) );
				}
				break;
			case 'profili':
				$name = trim( (string) OLR_Settings::get( 'dfs_profil' ) );
				$own_cid = self::own_cid();
				$st[] = self::profile_step( $own_cid ? 'cid:' . $own_cid : $name, true );
				foreach ( self::map_rivals( 8 ) as $c ) {
					$st[] = self::profile_step( 'cid:' . $c['cid'], false, $c['naziv'] );
				}
				break;
			case 'recenzije':
				$p = OLR_Store::data( self::COLL, 'profili' );
				$list = array();
				foreach ( $p && ! empty( $p['profili'] ) ? $p['profili'] : array() as $x ) {
					if ( ! empty( $x['cid'] ) ) {
						$list[] = $x;
					}
				}
				usort(
					$list,
					static function ( $a, $b ) {
						return ( (int) ! empty( $b['nas'] ) <=> (int) ! empty( $a['nas'] ) ) ?: ( (int) $b['glasova'] <=> (int) $a['glasova'] );
					}
				);
				foreach ( array_slice( $list, 0, 5 ) as $x ) {
					$t = array(
						'cid'           => (string) $x['cid'],
						'language_code' => self::LANG,
						'depth'         => 20,
						'sort_by'       => 'newest',
						'tag'           => 'olr|recenzije',
					);
					$t['location_code'] = self::LOC; // recenzije se traže po oznaci tvrtke (CID)
					$st[] = array( 'k' => 'post', 'path' => 'business_data/google/reviews/task_post', 'get' => 'business_data/google/reviews/task_get/', 'body' => array( $t ), 'cijena' => 2 * self::P_REC10, 'obrada' => 'recenzije', 'meta' => array( 'cid' => (string) $x['cid'], 'naziv' => $x['naziv'], 'nas' => ! empty( $x['nas'] ) ) );
				}
				break;
			case 'volumen':
				$kws = self::volume_keywords();
				if ( $kws ) {
					$st[] = array( 'k' => 'post', 'path' => 'keywords_data/google_ads/search_volume/task_post', 'get' => 'keywords_data/google_ads/search_volume/task_get/', 'body' => array( array( 'keywords' => $kws, 'location_code' => self::LOC, 'language_code' => self::LANG, 'date_from' => gmdate( 'Y-m-01', strtotime( '-47 months' ) ), 'tag' => 'olr|volumen' ) ), 'cijena' => self::P_ADS, 'obrada' => 'ads_vol' );
				}
				$seeds = array_slice( array_values( array_filter( OLR_DFS::keywords(), array( __CLASS__, 'ads_ok' ) ) ), 0, 20 );
				if ( $seeds ) {
					$st[] = array( 'k' => 'post', 'path' => 'keywords_data/google_ads/keywords_for_keywords/task_post', 'get' => 'keywords_data/google_ads/keywords_for_keywords/task_get/', 'body' => array( array( 'keywords' => array_map( array( 'OLR_DFS', 'lower' ), $seeds ), 'location_code' => self::LOC, 'language_code' => self::LANG, 'sort_by' => 'search_volume', 'tag' => 'olr|ideje' ) ), 'cijena' => self::P_ADS, 'obrada' => 'ads_ideje' );
				}
				break;
			case 'kljucne':
				$st[] = array( 'k' => 'get', 'path' => 'dataforseo_labs/locations_and_languages', 'cijena' => 0, 'obrada' => 'labs_loc' );
				foreach ( array_merge( array( $own ), $rivals ) as $d ) {
					$st[] = array( 'k' => 'live', 'path' => 'dataforseo_labs/google/domain_rank_overview/live', 'body' => array( array( 'target' => $d, 'location_code' => self::LOC, 'language_code' => self::LANG ) ), 'cijena' => self::P_LABS + self::P_LABS_RED, 'obrada' => 'labs_overview', 'meta' => array( 'domena' => $d ), 'labs' => true );
				}
				foreach ( array_merge( array( $own ), $rivals ) as $i => $d ) {
					$lim  = 0 === $i ? 1000 : 500;
					$body = array(
						'target'        => $d,
						'location_code' => self::LOC,
						'language_code' => self::LANG,
						'item_types'    => array( 'organic' ),
						'limit'         => $lim,
						'order_by'      => array( 'keyword_data.keyword_info.search_volume,desc' ),
					);
					if ( $i ) {
						$body['filters'] = array( 'ranked_serp_element.serp_item.rank_group', '<=', 20 );
					}
					$st[] = array( 'k' => 'live', 'path' => 'dataforseo_labs/google/ranked_keywords/live', 'body' => array( $body ), 'cijena' => self::P_LABS + $lim * self::P_LABS_RED, 'obrada' => 'labs_ranked', 'meta' => array( 'domena' => $d, 'nasa' => 0 === $i ), 'labs' => true );
				}
				break;
			case 'poveznice':
				foreach ( array_merge( array( $own ), $rivals ) as $d ) {
					$st[] = array( 'k' => 'live', 'path' => 'backlinks/summary/live', 'body' => array( array( 'target' => $d, 'include_subdomains' => true, 'rank_scale' => 'one_hundred', 'internal_list_limit' => 1 ) ), 'cijena' => self::P_BL + self::P_BL_RED, 'obrada' => 'bl_summary', 'meta' => array( 'domena' => $d ) );
				}
				$st[] = array( 'k' => 'live', 'path' => 'backlinks/referring_domains/live', 'body' => array( array( 'target' => $own, 'limit' => 200, 'order_by' => array( 'first_seen,desc' ), 'rank_scale' => 'one_hundred', 'internal_list_limit' => 1 ) ), 'cijena' => self::P_BL + 200 * self::P_BL_RED, 'obrada' => 'bl_nase' );
				$top = array_slice( $rivals, 0, 5 );
				if ( count( $top ) >= 2 ) {
					$targets = array();
					foreach ( $top as $i => $d ) {
						$targets[ (string) ( $i + 1 ) ] = $d;
					}
					$st[] = array( 'k' => 'live', 'path' => 'backlinks/domain_intersection/live', 'body' => array( array( 'targets' => $targets, 'exclude_targets' => array( $own ), 'intersection_mode' => 'partial', 'limit' => 500, 'rank_scale' => 'one_hundred', 'internal_list_limit' => 1 ) ), 'cijena' => self::P_BL + 500 * self::P_BL_RED, 'obrada' => 'bl_praznina', 'meta' => array( 'ciljevi' => $targets ) );
				}
				foreach ( $top as $d ) {
					$st[] = array( 'k' => 'live', 'path' => 'backlinks/referring_domains/live', 'body' => array( array( 'target' => $d, 'limit' => 50, 'order_by' => array( 'first_seen,desc' ), 'rank_scale' => 'one_hundred', 'internal_list_limit' => 1 ) ), 'cijena' => self::P_BL + 50 * self::P_BL_RED, 'obrada' => 'bl_novo', 'meta' => array( 'domena' => $d ) );
				}
				break;
			case 'spominjanja':
				$name = trim( (string) OLR_Settings::get( 'dfs_brend_naziv' ) );
				if ( '' !== $name ) {
					$st[] = array( 'k' => 'live', 'path' => 'content_analysis/search/live', 'body' => array( array( 'keyword' => $name, 'search_mode' => 'as_is', 'limit' => 100, 'order_by' => array( 'fetch_time,desc' ) ) ), 'cijena' => self::P_BL + 100 * self::P_BL_RED, 'obrada' => 'ca_search', 'meta' => array( 'naziv' => $name ) );
				}
				break;
			case 'brzina':
				foreach ( self::speed_urls() as $u ) {
					$st[] = array( 'k' => 'post', 'path' => 'on_page/lighthouse/task_post', 'get' => 'on_page/lighthouse/task_get/json/', 'body' => array( array( 'url' => $u, 'for_mobile' => true, 'categories' => array( 'performance' ), 'tag' => 'olr|brzina' ) ), 'cijena' => self::P_LH, 'obrada' => 'lighthouse', 'meta' => array( 'url' => $u ) );
				}
				break;
			case 'ai':
				foreach ( self::ai_platforms() as $p ) {
					$st[] = array( 'k' => 'get', 'path' => 'ai_optimization/' . $p . '/llm_responses/models', 'cijena' => 0, 'obrada' => 'ai_modeli', 'meta' => array( 'platforma' => $p ) );
					foreach ( self::ai_prompts() as $q ) {
						$st[] = array( 'k' => 'ai', 'path' => 'ai_optimization/' . $p . '/llm_responses/live', 'cijena' => self::P_AI, 'obrada' => 'ai_odgovor', 'meta' => array( 'platforma' => $p, 'pitanje' => $q ) );
					}
				}
				break;
		}
		return $st;
	}

	private static function profile_step( $keyword, $nas, $naziv = '' ) {
		$t     = array(
			'keyword'       => $keyword,
			'language_code' => self::LANG,
			'tag'           => 'olr|profil',
		);
		// Profil se traži na razini države: oznaka (CID) je jednoznačna, a polumjer u koordinatama ovog API-ja
		// dokumentacija ne opisuje jasno (navodi milimetre), pa se ne koristi.
		$t['location_code'] = self::LOC;
		return array( 'k' => 'post', 'path' => 'business_data/google/my_business_info/task_post', 'get' => 'business_data/google/my_business_info/task_get/', 'body' => array( $t ), 'cijena' => self::P_PROFIL, 'obrada' => 'profil', 'meta' => array( 'nas' => $nas, 'naziv' => $naziv ) );
	}

	/** Naša lokacija s Google profila (kad je poznata): središte pretraga na karti. */
	private static function map_coordinate() {
		$p = OLR_Store::data( self::COLL, 'profili' );
		foreach ( $p && ! empty( $p['profili'] ) ? $p['profili'] : array() as $x ) {
			if ( ! empty( $x['nas'] ) && is_numeric( $x['lat'] ) && is_numeric( $x['lng'] ) ) {
				return sprintf( '%.6f,%.6f,14z', $x['lat'], $x['lng'] );
			}
		}
		return null;
	}

	private static function own_cid() {
		$p = OLR_Store::data( self::COLL, 'profili' );
		foreach ( $p && ! empty( $p['profili'] ) ? $p['profili'] : array() as $x ) {
			if ( ! empty( $x['nas'] ) && ! empty( $x['cid'] ) ) {
				return (string) $x['cid'];
			}
		}
		$l = OLR_Store::data( self::COLL, 'lokalno' );
		foreach ( $l && ! empty( $l['rijeci'] ) ? $l['rijeci'] : array() as $r ) {
			foreach ( (array) $r['top'] as $t ) {
				if ( ! empty( $t['nas'] ) && ! empty( $t['cid'] ) ) {
					return (string) $t['cid'];
				}
			}
		}
		return null;
	}

	/** Konkurenti s karte: tvrtke koje se najčešće pojavljuju u prvih 5 za naše lokalne upite (bez nas). */
	public static function map_rivals( $max ) {
		$l     = OLR_Store::data( self::COLL, 'lokalno' );
		$count = array();
		foreach ( $l && ! empty( $l['rijeci'] ) ? $l['rijeci'] : array() as $r ) {
			foreach ( array_slice( (array) $r['top'], 0, 5 ) as $t ) {
				if ( empty( $t['nas'] ) && ! empty( $t['cid'] ) ) {
					$k = (string) $t['cid'];
					if ( ! isset( $count[ $k ] ) ) {
						$count[ $k ] = array( 'cid' => $k, 'naziv' => $t['naziv'], 'domena' => isset( $t['domena'] ) ? $t['domena'] : '', 'n' => 0, 'pos' => 0 );
					}
					++$count[ $k ]['n'];
					$count[ $k ]['pos'] += (int) $t['pos'];
				}
			}
		}
		$list = array_values( $count );
		usort(
			$list,
			static function ( $a, $b ) {
				return ( $b['n'] <=> $a['n'] ) ?: ( $a['pos'] <=> $b['pos'] );
			}
		);
		return array_slice( $list, 0, $max );
	}

	/** Google Ads ne prima neke znakove; riječ do 80 znakova i 10 riječi. */
	public static function ads_ok( $kw ) {
		$kw = trim( (string) $kw );
		return '' !== $kw && strlen( $kw ) <= 80 && count( preg_split( '/\s+/', $kw ) ) <= 10 && (bool) preg_match( '/^[\p{L}\p{N} \'\-\.]+$/u', $kw );
	}

	/** Riječi za obujam: ciljane, lokalne i najvažniji upiti iz Search Consolea (do 300). */
	private static function volume_keywords() {
		$out = array();
		$add = static function ( $kw ) use ( &$out ) {
			$k = OLR_DFS::lower( $kw );
			if ( self::ads_ok( $k ) && ! isset( $out[ $k ] ) ) {
				$out[ $k ] = true;
			}
		};
		foreach ( OLR_DFS::keywords() as $k ) {
			$add( $k );
		}
		foreach ( self::local_keywords() as $k ) {
			$add( $k );
		}
		$qp  = OLR_Store::data( 'gsc', 'qp', array() );
		$agg = array();
		foreach ( isset( $qp['rows'] ) ? (array) $qp['rows'] : array() as $r ) {
			$q         = (string) $r[0];
			$agg[ $q ] = ( isset( $agg[ $q ] ) ? $agg[ $q ] : 0 ) + (int) $r[3];
		}
		arsort( $agg );
		foreach ( array_keys( $agg ) as $q ) {
			if ( count( $out ) >= 300 ) {
				break;
			}
			$add( $q );
		}
		return array_slice( array_keys( $out ), 0, 300 );
	}

	/** Stranice za Lighthouse: naslovnica i najposjećenije iz Search Consolea (ukupno do 9). */
	private static function speed_urls() {
		$home = trailingslashit( home_url() );
		if ( ! OLR_DFS::is_ours( self::host( $home ) ) ) {
			$site = (string) OLR_Settings::get( 'gsc_site' );
			$home = 0 === strpos( $site, 'http' ) ? trailingslashit( $site ) : 'https://' . self::own() . '/';
		}
		$out = array( $home );
		$p    = OLR_Store::data( 'gsc', 'pages', array() );
		$rows = isset( $p['rows'] ) ? (array) $p['rows'] : array();
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b[1] <=> (int) $a[1];
			}
		);
		foreach ( $rows as $r ) {
			$u = strtok( (string) $r[0], '?#' );
			if ( $u && OLR_DFS::is_ours( self::host( $u ) ) && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
			if ( count( $out ) >= 9 ) {
				break;
			}
		}
		return $out;
	}

	/* ---------- izvršavanje koraka ---------- */

	private static function cost_of( $j ) {
		return isset( $j['cost'] ) && is_numeric( $j['cost'] ) ? (float) $j['cost'] : 0.0;
	}

	private static function add_job_cost( $m, $cost ) {
		if ( $cost <= 0 ) {
			return;
		}
		OLR_DFS::add_cost( $cost );
		$jobs = self::jobs();
		if ( isset( $jobs[ $m ] ) ) {
			$jobs[ $m ]['trosak'] = round( (float) $jobs[ $m ]['trosak'] + $cost, 5 );
			self::save_jobs( $jobs );
		}
	}

	private static function put_part( $m, $key, $data ) {
		$run = OLR_Store::data( self::RUN, $m, array( 'dijelovi' => array() ) );
		$run['dijelovi'][ $key ] = $data;
		OLR_Store::set( self::RUN, $m, $run );
	}

	private static function parts( $m ) {
		$run = OLR_Store::data( self::RUN, $m, array( 'dijelovi' => array() ) );
		return isset( $run['dijelovi'] ) ? (array) $run['dijelovi'] : array();
	}

	private static function run_steps( $m, $t0, $budget ) {
		$jobs = self::jobs();
		$job  = $jobs[ $m ];
		$i    = 0;
		while ( ! empty( $job['koraci'] ) && time() - $t0 < $budget ) {
			$s = array_shift( $job['koraci'] );
			// AI odgovor traje i do dvije minute: samo jedan po prolazu, i samo ako prolaz tek počinje.
			if ( 'ai' === $s['k'] && ( $i > 0 || time() - $t0 > 5 ) ) {
				array_unshift( $job['koraci'], $s );
				break;
			}
			if ( $s['cijena'] > 0 && self::month_spent() + $s['cijena'] > self::limit() ) {
				$job['koraci'] = array();
				$job['poruka'] = sprintf( 'zaustavljeno: dosegnut mjesečni limit %.2f $', self::limit() );
				break;
			}
			if ( ! empty( $s['labs'] ) ) {
				$parts = self::parts( $m );
				if ( isset( $parts['labs_loc'] ) && empty( $parts['labs_loc']['podrzano'] ) ) {
					$job['koraci'] = array();
					$job['poruka'] = 'DataForSEO Labs nema podatke za Hrvatsku na hrvatskom';
					break;
				}
			}
			// Spremi stanje prije poziva: ako PHP prekine izvođenje, korak se ne ponavlja (i ne plaća dvaput).
			$jobs[ $m ] = $job;
			self::save_jobs( $jobs );
			$extra = self::exec_step( $m, $s );
			$jobs  = self::jobs();
			$job   = $jobs[ $m ];
			if ( is_array( $extra ) && $extra ) {
				$job['koraci'] = array_merge( $extra, $job['koraci'] );
			}
			++$i;
		}
		$job['stanje'] = $job['koraci'] ? 'radi' : 'rezultati';
		$jobs          = self::jobs();
		$jobs[ $m ]    = array_merge( $jobs[ $m ], array_intersect_key( $job, array_flip( array( 'koraci', 'stanje', 'poruka' ) ) ) );
		self::save_jobs( $jobs );
	}

	/**
	 * Izvrši jedan korak. Vraća dodatne korake (npr. provjere poveznica nakon pretrage spominjanja) ili null.
	 */
	private static function exec_step( $m, $s ) {
		$key = isset( $s['meta'] ) ? md5( wp_json_encode( $s['meta'] ) . $s['path'] ) : md5( $s['path'] );
		if ( 'link' === $s['k'] ) {
			$r = OLR_Suradnje::check_link( $s['meta']['url'] );
			self::put_part( $m, 'link_' . md5( $s['meta']['url'] ), $r );
			return null;
		}
		if ( 'get' === $s['k'] ) {
			$j = OLR_DFS::request( 'GET', $s['path'] );
			if ( is_wp_error( $j ) ) {
				self::put_part( $m, $s['obrada'] . '_' . $key, array( 'greska' => $j->get_error_message(), 'meta' => isset( $s['meta'] ) ? $s['meta'] : array() ) );
				return null;
			}
			$t = isset( $j['tasks'][0] ) && is_array( $j['tasks'][0] ) ? $j['tasks'][0] : array();
			self::put_part( $m, 'labs_loc' === $s['obrada'] ? 'labs_loc' : $s['obrada'] . '_' . $key, self::parse( $s['obrada'], $t, isset( $s['meta'] ) ? $s['meta'] : array() ) );
			return null;
		}
		if ( 'post' === $s['k'] ) {
			$j = OLR_DFS::request( 'POST', $s['path'], $s['body'] );
			if ( is_wp_error( $j ) ) {
				self::put_part( $m, 'greska_' . $key, array( 'greska' => $j->get_error_message(), 'meta' => isset( $s['meta'] ) ? $s['meta'] : array() ) );
				return null;
			}
			self::add_job_cost( $m, self::cost_of( $j ) );
			$q = self::queue();
			foreach ( isset( $j['tasks'] ) ? (array) $j['tasks'] : array() as $t ) {
				if ( isset( $t['status_code'] ) && 20100 === (int) $t['status_code'] && ! empty( $t['id'] ) ) {
					$q[ $t['id'] ] = array(
						'modul'  => $m,
						'get'    => $s['get'],
						'obrada' => $s['obrada'],
						'meta'   => isset( $s['meta'] ) ? $s['meta'] : array(),
						'at'     => time(),
					);
				} else {
					self::put_part( $m, 'greska_' . $key, array( 'greska' => isset( $t['status_message'] ) ? $t['status_message'] : 'zadatak nije prihvaćen', 'meta' => isset( $s['meta'] ) ? $s['meta'] : array() ) );
				}
			}
			self::save_queue( $q );
			return null;
		}
		if ( 'ai' === $s['k'] ) {
			return self::exec_ai( $m, $s, $key );
		}
		// live
		$j = OLR_DFS::request( 'POST', $s['path'], $s['body'], 60 );
		if ( is_wp_error( $j ) ) {
			self::put_part( $m, $s['obrada'] . '_' . $key, array( 'greska' => $j->get_error_message(), 'meta' => isset( $s['meta'] ) ? $s['meta'] : array() ) );
			return null;
		}
		self::add_job_cost( $m, self::cost_of( $j ) );
		$t = isset( $j['tasks'][0] ) ? $j['tasks'][0] : array();
		if ( ! isset( $t['status_code'] ) || 20000 !== (int) $t['status_code'] ) {
			$msg = isset( $t['status_message'] ) ? $t['status_message'] : 'nema rezultata';
			self::put_part( $m, $s['obrada'] . '_' . $key, array( 'greska' => $msg, 'meta' => isset( $s['meta'] ) ? $s['meta'] : array() ) );
			return null;
		}
		$parsed = self::parse( $s['obrada'], $t, isset( $s['meta'] ) ? $s['meta'] : array() );
		self::put_part( $m, $s['obrada'] . '_' . $key, $parsed );
		if ( 'ca_search' === $s['obrada'] ) {
			$more = array();
			foreach ( array_slice( (array) $parsed['stavke'], 0, 15 ) as $x ) {
				$more[] = array( 'k' => 'link', 'path' => 'link', 'cijena' => 0, 'obrada' => 'link', 'meta' => array( 'url' => $x['url'] ) );
			}
			return $more;
		}
		return null;
	}

	/** Odgovor AI modela; ako model odbije parametre web pretrage, jednom bez njih. */
	private static function exec_ai( $m, $s, $key ) {
		$p     = $s['meta']['platforma'];
		$parts = self::parts( $m );
		$model = null;
		foreach ( $parts as $k => $v ) {
			if ( 0 === strpos( $k, 'ai_modeli_' ) && isset( $v['platforma'] ) && $p === $v['platforma'] && ! empty( $v['model'] ) ) {
				$model = $v['model'];
			}
		}
		$model = $model ? $model : self::default_model( $p );
		$body  = array(
			'user_prompt'       => $s['meta']['pitanje'],
			'model_name'        => $model,
			'max_output_tokens' => 1024,
			'tag'               => 'olr|ai',
		);
		if ( 'perplexity' !== $p ) {
			$body['web_search'] = true;
		}
		if ( 'chat_gpt' === $p ) {
			$body['web_search_country_iso_code'] = 'HR';
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$j = OLR_DFS::request( 'POST', $s['path'], array( $body ), 150 );
		$t = ! is_wp_error( $j ) && isset( $j['tasks'][0] ) ? $j['tasks'][0] : array();
		if ( ! is_wp_error( $j ) ) {
			self::add_job_cost( $m, self::cost_of( $j ) );
		}
		$bad = is_wp_error( $j ) || ! isset( $t['status_code'] ) || 20000 !== (int) $t['status_code'];
		if ( $bad && ( isset( $body['web_search'] ) || isset( $body['web_search_country_iso_code'] ) ) && preg_match( '/web_search|country|invalid field|not supported/i', is_wp_error( $j ) ? $j->get_error_message() : ( isset( $t['status_message'] ) ? $t['status_message'] : '' ) ) ) {
			unset( $body['web_search'], $body['web_search_country_iso_code'] );
			$j = OLR_DFS::request( 'POST', $s['path'], array( $body ), 150 );
			$t = ! is_wp_error( $j ) && isset( $j['tasks'][0] ) ? $j['tasks'][0] : array();
			if ( ! is_wp_error( $j ) ) {
				self::add_job_cost( $m, self::cost_of( $j ) );
			}
			$bad = is_wp_error( $j ) || ! isset( $t['status_code'] ) || 20000 !== (int) $t['status_code'];
		}
		if ( $bad ) {
			self::put_part( $m, 'ai_odgovor_' . $key, array( 'greska' => is_wp_error( $j ) ? $j->get_error_message() : ( isset( $t['status_message'] ) ? $t['status_message'] : 'nema odgovora' ), 'meta' => $s['meta'] ) );
			return null;
		}
		$res                = self::parse( 'ai_odgovor', $t, $s['meta'] );
		$res['model']       = $res['model'] ? $res['model'] : $model;
		self::put_part( $m, 'ai_odgovor_' . $key, $res );
		return null;
	}

	private static function default_model( $p ) {
		$d = array(
			'chat_gpt'   => 'gpt-4.1-mini',
			'gemini'     => 'gemini-2.5-flash',
			'perplexity' => 'sonar',
			'claude'     => 'claude-haiku-4-5',
		);
		return isset( $d[ $p ] ) ? $d[ $p ] : '';
	}

	/** Pokupi gotove zadatke iz standardnog reda. */
	public static function poll_queue( $max = 30 ) {
		$q = self::queue();
		if ( ! $q ) {
			return 0;
		}
		$n = 0;
		foreach ( $q as $id => $e ) {
			if ( $n >= $max ) {
				break;
			}
			++$n;
			$j = OLR_DFS::request( 'GET', $e['get'] . rawurlencode( $id ) );
			if ( is_wp_error( $j ) ) {
				if ( time() - (int) $e['at'] > self::MAX_DANA * DAY_IN_SECONDS ) {
					unset( $q[ $id ] );
				}
				continue;
			}
			$t    = isset( $j['tasks'][0] ) ? $j['tasks'][0] : array();
			$code = isset( $t['status_code'] ) ? (int) $t['status_code'] : 0;
			if ( $code >= 40600 && $code < 40700 && time() - (int) $e['at'] < self::MAX_DANA * DAY_IN_SECONDS ) {
				continue; // još u redu
			}
			unset( $q[ $id ] );
			$key = $e['obrada'] . '_' . md5( $id );
			if ( 20000 === $code ) {
				self::put_part( $e['modul'], $key, self::parse( $e['obrada'], $t, $e['meta'] ) );
			} else {
				self::put_part( $e['modul'], $key, array( 'greska' => 40102 === $code ? 'nema rezultata' : ( isset( $t['status_message'] ) ? $t['status_message'] : (string) $code ), 'meta' => $e['meta'] ) );
			}
		}
		self::save_queue( $q );
		return $n;
	}

	private static function maybe_finish( $m ) {
		$jobs = self::jobs();
		$job  = $jobs[ $m ];
		$wait = 0;
		foreach ( self::queue() as $e ) {
			$wait += $m === $e['modul'] ? 1 : 0;
		}
		$old = ! empty( $job['pocetak'] ) && time() - (int) $job['pocetak'] > self::MAX_DANA * DAY_IN_SECONDS;
		if ( ( empty( $job['koraci'] ) && 0 === $wait ) || $old ) {
			if ( $old ) {
				$q = self::queue();
				foreach ( $q as $id => $e ) {
					if ( $m === $e['modul'] ) {
						unset( $q[ $id ] );
					}
				}
				self::save_queue( $q );
			}
			self::finish( $m, $old );
		} elseif ( empty( $job['koraci'] ) ) {
			$jobs[ $m ]['stanje'] = 'rezultati';
			self::save_jobs( $jobs );
			OLR_Settings::set_status( 'trziste_' . $m, true, 'čeka rezultate (' . $wait . ' zadataka u redu kod DataForSEO-a)' );
		}
	}

	/* ---------- obrada odgovora ---------- */

	private static function parse( $kind, $t, $meta ) {
		$res = isset( $t['result'] ) && is_array( $t['result'] ) ? $t['result'] : array();
		$r0  = isset( $res[0] ) && is_array( $res[0] ) ? $res[0] : array();
		switch ( $kind ) {
			case 'maps':
				$top = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $it ) {
					if ( ! is_array( $it ) || ( isset( $it['type'] ) && 'maps_search' !== $it['type'] ) ) {
						continue;
					}
					$dom   = self::host( isset( $it['domain'] ) ? $it['domain'] : ( isset( $it['url'] ) ? $it['url'] : '' ) );
					$title = isset( $it['title'] ) ? (string) $it['title'] : '';
					$top[] = array(
						'pos'       => isset( $it['rank_group'] ) ? (int) $it['rank_group'] : count( $top ) + 1,
						'naziv'     => self::cut( $title, 120 ),
						'domena'    => $dom,
						'nas'       => ( '' !== $dom && OLR_DFS::is_ours( $dom ) ) || self::is_brand_text( $title ),
						'ocjena'    => isset( $it['rating']['value'] ) ? self::num( $it['rating']['value'] ) : null,
						'glasova'   => isset( $it['rating']['votes_count'] ) ? (int) $it['rating']['votes_count'] : null,
						'cid'       => isset( $it['cid'] ) ? (string) $it['cid'] : '',
						'kategorija' => isset( $it['category'] ) ? self::cut( $it['category'], 80 ) : '',
						'adresa'    => isset( $it['address'] ) ? self::cut( $it['address'], 140 ) : '',
						'preuzet'   => isset( $it['is_claimed'] ) ? (bool) $it['is_claimed'] : null,
					);
					if ( count( $top ) >= 20 ) {
						break;
					}
				}
				$ours = null;
				foreach ( $top as $x ) {
					if ( $x['nas'] ) {
						$ours = $x['pos'];
						break;
					}
				}
				return array(
					'rijec' => $meta['rijec'],
					'nasa'  => $ours,
					'top'   => $top,
					'at'    => time() * 1000,
				);
			case 'profil':
				$it = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					if ( is_array( $x ) && ( ! isset( $x['type'] ) || 'google_business_info' === $x['type'] ) ) {
						$it = $x;
						break;
					}
				}
				if ( ! $it ) {
					return array( 'greska' => 'profil nije pronađen', 'meta' => $meta );
				}
				$topics = array();
				foreach ( isset( $it['place_topics'] ) && is_array( $it['place_topics'] ) ? $it['place_topics'] : array() as $k => $v ) {
					if ( is_numeric( $v ) ) {
						$topics[ self::cut( $k, 40 ) ] = (int) $v;
					}
				}
				arsort( $topics );
				$dist = array();
				foreach ( isset( $it['rating_distribution'] ) && is_array( $it['rating_distribution'] ) ? $it['rating_distribution'] : array() as $k => $v ) {
					$dist[ (string) $k ] = (int) $v;
				}
				$dom = self::host( isset( $it['domain'] ) ? $it['domain'] : ( isset( $it['url'] ) ? $it['url'] : '' ) );
				return array(
					'nas'        => ! empty( $meta['nas'] ) || ( '' !== $dom && OLR_DFS::is_ours( $dom ) ),
					'naziv'      => self::cut( isset( $it['title'] ) ? $it['title'] : $meta['naziv'], 120 ),
					'kategorija' => self::cut( isset( $it['category'] ) ? $it['category'] : '', 80 ),
					'dodatne'    => array_slice( array_map( 'strval', isset( $it['additional_categories'] ) && is_array( $it['additional_categories'] ) ? $it['additional_categories'] : array() ), 0, 10 ),
					'cid'        => isset( $it['cid'] ) ? (string) $it['cid'] : '',
					'place_id'   => isset( $it['place_id'] ) ? (string) $it['place_id'] : '',
					'adresa'     => self::cut( isset( $it['address'] ) ? $it['address'] : '', 140 ),
					'telefon'    => self::cut( isset( $it['phone'] ) ? $it['phone'] : '', 40 ),
					'web'        => isset( $it['url'] ) ? esc_url_raw( (string) $it['url'] ) : '',
					'domena'     => $dom,
					'lat'        => isset( $it['latitude'] ) ? self::num( $it['latitude'] ) : null,
					'lng'        => isset( $it['longitude'] ) ? self::num( $it['longitude'] ) : null,
					'preuzet'    => isset( $it['is_claimed'] ) ? (bool) $it['is_claimed'] : null,
					'ocjena'     => isset( $it['rating']['value'] ) ? self::num( $it['rating']['value'] ) : null,
					'glasova'    => isset( $it['rating']['votes_count'] ) ? (int) $it['rating']['votes_count'] : 0,
					'raspodjela' => $dist,
					'fotografija' => isset( $it['total_photos'] ) ? (int) $it['total_photos'] : null,
					'opis'       => self::cut( isset( $it['description'] ) ? $it['description'] : '', 400 ),
					'teme'       => array_slice( $topics, 0, 12, true ),
					'at'         => time() * 1000,
				);
			case 'recenzije':
				$items = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					if ( ! is_array( $x ) ) {
						continue;
					}
					$items[] = array(
						'ocjena'   => isset( $x['rating']['value'] ) ? self::num( $x['rating']['value'] ) : null,
						'tekst'    => self::cut( isset( $x['review_text'] ) ? $x['review_text'] : '', 600 ),
						'vrijeme'  => isset( $x['timestamp'] ) ? (string) $x['timestamp'] : '',
						'odgovor'  => ! empty( $x['owner_answer'] ),
						'url'      => isset( $x['review_url'] ) ? esc_url_raw( (string) $x['review_url'] ) : '',
					);
				}
				return array(
					'cid'     => $meta['cid'],
					'naziv'   => isset( $r0['title'] ) ? self::cut( $r0['title'], 120 ) : $meta['naziv'],
					'nas'     => ! empty( $meta['nas'] ),
					'ocjena'  => isset( $r0['rating']['value'] ) ? self::num( $r0['rating']['value'] ) : null,
					'ukupno'  => isset( $r0['reviews_count'] ) ? (int) $r0['reviews_count'] : null,
					'stavke'  => $items,
					'at'      => time() * 1000,
				);
			case 'ads_vol':
			case 'ads_ideje':
				$rows = array();
				foreach ( $res as $x ) {
					if ( ! is_array( $x ) || empty( $x['keyword'] ) ) {
						continue;
					}
					$months = array();
					foreach ( isset( $x['monthly_searches'] ) && is_array( $x['monthly_searches'] ) ? $x['monthly_searches'] : array() as $ms ) {
						if ( isset( $ms['year'], $ms['month'] ) ) {
							$months[ sprintf( '%04d-%02d', $ms['year'], $ms['month'] ) ] = isset( $ms['search_volume'] ) ? (int) $ms['search_volume'] : null;
						}
					}
					ksort( $months );
					$row = array(
						'k'    => OLR_DFS::lower( $x['keyword'] ),
						'v'    => isset( $x['search_volume'] ) ? self::num( $x['search_volume'] ) : null,
						'cpc'  => isset( $x['cpc'] ) ? self::num( $x['cpc'] ) : null,
						'konk' => isset( $x['competition'] ) ? (string) $x['competition'] : '',
						'ki'   => isset( $x['competition_index'] ) ? self::num( $x['competition_index'] ) : null,
					);
					if ( 'ads_vol' === $kind ) {
						$row['m'] = array();
						foreach ( $months as $ym => $v ) {
							$row['m'][] = array( $ym, $v );
						}
					} else {
						$row['m12'] = array_slice( array_values( $months ), -12 );
					}
					$rows[] = $row;
				}
				return array( 'redovi' => $rows );
			case 'labs_loc':
				$ok = false;
				foreach ( $res as $x ) {
					if ( is_array( $x ) && isset( $x['location_code'] ) && self::LOC === (int) $x['location_code'] ) {
						foreach ( isset( $x['available_languages'] ) ? (array) $x['available_languages'] : array() as $l ) {
							if ( isset( $l['language_code'] ) && self::LANG === $l['language_code'] ) {
								$ok = true;
							}
						}
					}
				}
				return array( 'podrzano' => $ok );
			case 'labs_overview':
				$o = isset( $r0['items'][0]['metrics']['organic'] ) ? (array) $r0['items'][0]['metrics']['organic'] : array();
				return array(
					'domena' => $meta['domena'],
					'rijeci' => isset( $o['count'] ) ? (int) $o['count'] : 0,
					'top1'   => isset( $o['pos_1'] ) ? (int) $o['pos_1'] : 0,
					'top3'   => ( isset( $o['pos_1'] ) ? (int) $o['pos_1'] : 0 ) + ( isset( $o['pos_2_3'] ) ? (int) $o['pos_2_3'] : 0 ),
					'top10'  => ( isset( $o['pos_1'] ) ? (int) $o['pos_1'] : 0 ) + ( isset( $o['pos_2_3'] ) ? (int) $o['pos_2_3'] : 0 ) + ( isset( $o['pos_4_10'] ) ? (int) $o['pos_4_10'] : 0 ),
					'top20'  => ( isset( $o['pos_1'] ) ? (int) $o['pos_1'] : 0 ) + ( isset( $o['pos_2_3'] ) ? (int) $o['pos_2_3'] : 0 ) + ( isset( $o['pos_4_10'] ) ? (int) $o['pos_4_10'] : 0 ) + ( isset( $o['pos_11_20'] ) ? (int) $o['pos_11_20'] : 0 ),
					'etv'    => isset( $o['etv'] ) ? round( (float) $o['etv'], 1 ) : null,
					'novih'  => isset( $o['is_new'] ) ? (int) $o['is_new'] : null,
					'izgubljenih' => isset( $o['is_lost'] ) ? (int) $o['is_lost'] : null,
				);
			case 'labs_ranked':
				$rows = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					if ( ! is_array( $x ) || empty( $x['keyword_data']['keyword'] ) ) {
						continue;
					}
					$si = isset( $x['ranked_serp_element']['serp_item'] ) ? (array) $x['ranked_serp_element']['serp_item'] : array();
					if ( ! isset( $si['rank_group'] ) ) {
						foreach ( $si as $v ) {
							if ( is_array( $v ) && isset( $v['rank_group'] ) ) {
								$si = $v;
								break;
							}
						}
					}
					$kd     = $x['keyword_data'];
					$rows[] = array(
						'k'      => OLR_DFS::lower( $kd['keyword'] ),
						'v'      => isset( $kd['keyword_info']['search_volume'] ) ? self::num( $kd['keyword_info']['search_volume'] ) : null,
						'pos'    => isset( $si['rank_group'] ) ? (int) $si['rank_group'] : null,
						'prije'  => isset( $si['rank_changes']['previous_rank_absolute'] ) ? self::num( $si['rank_changes']['previous_rank_absolute'] ) : null,
						'novo'   => ! empty( $si['rank_changes']['is_new'] ),
						'url'    => isset( $si['url'] ) ? (string) $si['url'] : ( isset( $si['relative_url'] ) ? (string) $si['relative_url'] : '' ),
						'namjera' => isset( $kd['search_intent_info']['main_intent'] ) ? (string) $kd['search_intent_info']['main_intent'] : '',
						'kd'     => isset( $kd['keyword_properties']['keyword_difficulty'] ) ? self::num( $kd['keyword_properties']['keyword_difficulty'] ) : null,
						'etv'    => isset( $si['etv'] ) ? round( (float) $si['etv'], 2 ) : null,
					);
				}
				return array(
					'domena' => $meta['domena'],
					'nasa'   => ! empty( $meta['nasa'] ),
					'ukupno' => isset( $r0['total_count'] ) ? (int) $r0['total_count'] : count( $rows ),
					'redovi' => $rows,
				);
			case 'bl_summary':
				return array(
					'domena'  => $meta['domena'],
					'rank'    => isset( $r0['rank'] ) ? self::num( $r0['rank'] ) : null,
					'poveznica' => isset( $r0['backlinks'] ) ? (int) $r0['backlinks'] : null,
					'domena_n' => isset( $r0['referring_domains'] ) ? (int) $r0['referring_domains'] : null,
					'nofollow' => isset( $r0['referring_domains_nofollow'] ) ? (int) $r0['referring_domains_nofollow'] : null,
					'glavnih' => isset( $r0['referring_main_domains'] ) ? (int) $r0['referring_main_domains'] : null,
					'spam'    => isset( $r0['backlinks_spam_score'] ) ? self::num( $r0['backlinks_spam_score'] ) : null,
				);
			case 'bl_nase':
			case 'bl_novo':
				$rows = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					$d = is_array( $x ) ? self::host( isset( $x['domain'] ) ? $x['domain'] : '' ) : '';
					if ( '' === $d ) {
						continue;
					}
					$rows[] = array(
						'd'        => $d,
						'rank'     => isset( $x['rank'] ) ? self::num( $x['rank'] ) : null,
						'poveznica' => isset( $x['backlinks'] ) ? (int) $x['backlinks'] : null,
						'prvi'     => isset( $x['first_seen'] ) ? substr( (string) $x['first_seen'], 0, 10 ) : '',
						'izgubljeno' => ! empty( $x['lost_date'] ) ? substr( (string) $x['lost_date'], 0, 10 ) : '',
						'spam'     => isset( $x['backlinks_spam_score'] ) ? self::num( $x['backlinks_spam_score'] ) : null,
						'prodavac' => OLR_Suradnje::link_shop( $d ),
					);
				}
				return array(
					'domena' => isset( $meta['domena'] ) ? $meta['domena'] : self::own(),
					'ukupno' => isset( $r0['total_count'] ) ? (int) $r0['total_count'] : count( $rows ),
					'redovi' => $rows,
				);
			case 'bl_praznina':
				// Ključevi u domain_intersection su redni brojevi traženih ciljeva (konkurenata); polje „target”
				// u svakom je domena koja povezuje (primjer iz dokumentacije: ciljevi moz.com i ahrefs.com, target www.xroxy.com).
				$rows = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					$di    = is_array( $x ) && isset( $x['domain_intersection'] ) && is_array( $x['domain_intersection'] ) ? $x['domain_intersection'] : array();
					$links = array();
					$d     = '';
					$rank  = null;
					$spam  = null;
					foreach ( $di as $n => $v ) {
						if ( ! is_array( $v ) ) {
							continue;
						}
						if ( '' === $d && ! empty( $v['target'] ) ) {
							$d = self::host( $v['target'] );
						}
						$tgt = isset( $meta['ciljevi'][ (string) $n ] ) ? $meta['ciljevi'][ (string) $n ] : (string) $n;
						$links[ $tgt ] = isset( $v['backlinks'] ) ? (int) $v['backlinks'] : 1;
						$rank = max( (float) $rank, isset( $v['rank'] ) ? (float) $v['rank'] : 0 );
						$spam = max( (float) $spam, isset( $v['backlinks_spam_score'] ) ? (float) $v['backlinks_spam_score'] : 0 );
					}
					if ( '' === $d || ! $links ) {
						continue;
					}
					$rows[] = array(
						'd'          => $d,
						'konkurenti' => $links,
						'n'          => isset( $x['summary']['intersections_count'] ) ? (int) $x['summary']['intersections_count'] : count( $links ),
						'rank'       => $rank,
						'spam'       => $spam,
						'prodavac'   => OLR_Suradnje::link_shop( $d ),
					);
				}
				return array( 'redovi' => $rows );
			case 'ca_search':
				$rows = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $x ) {
					if ( ! is_array( $x ) || empty( $x['url'] ) ) {
						continue;
					}
					$d = self::host( isset( $x['domain'] ) ? $x['domain'] : $x['url'] );
					if ( '' === $d || OLR_DFS::is_ours( $d ) ) {
						continue;
					}
					$ci     = isset( $x['content_info'] ) && is_array( $x['content_info'] ) ? $x['content_info'] : array();
					$rows[] = array(
						'url'    => esc_url_raw( (string) $x['url'] ),
						'd'      => $d,
						'naslov' => self::cut( isset( $ci['title'] ) ? $ci['title'] : ( isset( $ci['main_title'] ) ? $ci['main_title'] : '' ), 160 ),
						'odlomak' => self::cut( isset( $ci['snippet'] ) ? $ci['snippet'] : '', 300 ),
						'datum'  => isset( $ci['date_published'] ) ? substr( (string) $ci['date_published'], 0, 10 ) : ( isset( $x['fetch_time'] ) ? substr( (string) $x['fetch_time'], 0, 10 ) : '' ),
						'rank'   => isset( $x['domain_rank'] ) ? self::num( $x['domain_rank'] ) : null,
					);
				}
				return array(
					'ukupno' => isset( $r0['total_count'] ) ? (int) $r0['total_count'] : count( $rows ),
					'stavke' => $rows,
				);
			case 'lighthouse':
				$lh  = $r0;
				$aud = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();
				$val = static function ( $id ) use ( $aud ) {
					foreach ( array( $id, 'metrics/' . $id ) as $k ) {
						if ( isset( $aud[ $k ]['numericValue'] ) && is_numeric( $aud[ $k ]['numericValue'] ) ) {
							return (float) $aud[ $k ]['numericValue'];
						}
					}
					return null;
				};
				$score = isset( $lh['categories']['performance']['score'] ) && is_numeric( $lh['categories']['performance']['score'] ) ? (int) round( 100 * $lh['categories']['performance']['score'] ) : null;
				$lcp   = $val( 'largest-contentful-paint' );
				$fcp   = $val( 'first-contentful-paint' );
				$si    = $val( 'speed-index' );
				$tbt   = $val( 'total-blocking-time' );
				$cls   = $val( 'cumulative-layout-shift' );
				return array(
					'url'   => $meta['url'],
					'ocjena' => $score,
					'lcp'   => null === $lcp ? null : round( $lcp / 1000, 1 ),
					'fcp'   => null === $fcp ? null : round( $fcp / 1000, 1 ),
					'si'    => null === $si ? null : round( $si / 1000, 1 ),
					'tbt'   => null === $tbt ? null : (int) round( $tbt ),
					'cls'   => null === $cls ? null : round( $cls, 3 ),
					'at'    => time() * 1000,
				);
			case 'ai_modeli':
				$names = array();
				foreach ( $res as $x ) {
					if ( is_array( $x ) && ! empty( $x['model_name'] ) ) {
						$names[] = (string) $x['model_name'];
					} elseif ( is_array( $x ) && isset( $x['items'] ) ) {
						foreach ( (array) $x['items'] as $y ) {
							if ( is_array( $y ) && ! empty( $y['model_name'] ) ) {
								$names[] = (string) $y['model_name'];
							}
						}
					}
				}
				return array(
					'platforma' => $meta['platforma'],
					'model'     => self::pick_model( $meta['platforma'], $names ),
				);
			case 'ai_odgovor':
				$text  = array();
				$urls  = array();
				foreach ( isset( $r0['items'] ) ? (array) $r0['items'] : array() as $it ) {
					if ( ! is_array( $it ) || ( isset( $it['type'] ) && 'message' !== $it['type'] ) ) {
						continue;
					}
					foreach ( isset( $it['sections'] ) ? (array) $it['sections'] : array() as $sec ) {
						if ( isset( $sec['text'] ) ) {
							$text[] = (string) $sec['text'];
						}
						foreach ( isset( $sec['annotations'] ) && is_array( $sec['annotations'] ) ? $sec['annotations'] : array() as $a ) {
							if ( ! empty( $a['url'] ) ) {
								$urls[] = array( 'url' => esc_url_raw( (string) $a['url'] ), 'naslov' => self::cut( isset( $a['title'] ) ? $a['title'] : '', 120 ) );
							}
						}
					}
				}
				foreach ( isset( $r0['search_results'] ) && is_array( $r0['search_results'] ) ? $r0['search_results'] : array() as $a ) {
					if ( ! empty( $a['url'] ) ) {
						$urls[] = array( 'url' => esc_url_raw( (string) $a['url'] ), 'naslov' => self::cut( isset( $a['title'] ) ? $a['title'] : '', 120 ) );
					}
				}
				$full  = implode( "\n", $text );
				$hosts = array();
				foreach ( $urls as $u ) {
					$h = self::host( $u['url'] );
					if ( '' !== $h ) {
						$hosts[ $h ] = true;
					}
				}
				$hosts = array_keys( $hosts );
				$cites = false;
				foreach ( $hosts as $h ) {
					$cites = $cites || OLR_DFS::is_ours( $h );
				}
				return array(
					'platforma' => $meta['platforma'],
					'pitanje'   => $meta['pitanje'],
					'model'     => isset( $r0['model_name'] ) ? (string) $r0['model_name'] : '',
					'spominje'  => self::is_brand_text( $full ),
					'citira'    => $cites,
					'konkurenti' => self::rivals_in_text( $full, $hosts ),
					'izvori'    => array_slice( $hosts, 0, 12 ),
					'odgovor'   => function_exists( 'mb_substr' ) ? mb_substr( trim( $full ), 0, 2500, 'UTF-8' ) : substr( trim( $full ), 0, 2500 ),
					'trosak'    => isset( $t['cost'] ) ? self::num( $t['cost'] ) : null,
					'at'        => time() * 1000,
				);
		}
		return array();
	}

	private static function pick_model( $p, $names ) {
		$pref = array(
			'chat_gpt'   => array( 'gpt-4.1-mini', 'gpt-4o-mini', 'gpt-5-mini', 'mini' ),
			'gemini'     => array( 'gemini-2.5-flash', 'flash' ),
			'perplexity' => array( 'sonar' ),
			'claude'     => array( 'haiku', 'sonnet' ),
		);
		foreach ( isset( $pref[ $p ] ) ? $pref[ $p ] : array() as $want ) {
			foreach ( $names as $n ) {
				if ( $n === $want ) {
					return $n;
				}
			}
			foreach ( $names as $n ) {
				if ( false !== strpos( $n, $want ) && ! preg_match( '/lite|pro|reason|deep|search-preview|audio|realtime|nano/', $n ) ) {
					return $n;
				}
			}
		}
		return $names ? $names[0] : self::default_model( $p );
	}

	/** Konkurenti spomenuti u odgovoru AI asistenta: po nazivu s karte i po domeni (u tekstu ili među izvorima). */
	private static function rivals_in_text( $text, $hosts ) {
		$t    = OLR_DFS::lower( $text );
		$out  = array();
		$skip = array( 'optika', 'optike', 'opticar', 'optičar', 'sesvete', 'zagreb', 'naočale', 'vision', 'centar', 'očna', 'ocna', 'studio', 'shop', 'd.o.o.', 'obrt' );
		$known = array();
		foreach ( self::map_rivals( 10 ) as $c ) {
			$words = array_values( array_filter( preg_split( '/[\s,\-–\.]+/u', OLR_DFS::lower( $c['naziv'] ) ), static function ( $w ) use ( $skip ) {
				return strlen( $w ) >= 4 && ! in_array( $w, $skip, true );
			} ) );
			$dom = isset( $c['domena'] ) ? (string) $c['domena'] : '';
			if ( ( $words && false !== strpos( $t, $words[0] ) ) || ( '' !== $dom && in_array( $dom, $hosts, true ) ) ) {
				$out[] = $c['naziv'];
				if ( '' !== $dom ) {
					$known[ $dom ] = true; // ista tvrtka po domeni se ne broji dvaput
				}
			}
		}
		foreach ( self::rivals() as $d ) {
			$base = preg_replace( '/\.[a-z.]+$/', '', $d );
			if ( ! isset( $known[ $d ] ) && ( in_array( $d, $hosts, true ) || ( strlen( $base ) >= 5 && false !== strpos( $t, $base ) ) ) ) {
				$out[] = $d;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ---------- završetak posla: gotovi podaci ---------- */

	private static function finish( $m, $partial ) {
		$parts = self::parts( $m );
		$errs  = array();
		$ok    = array();
		foreach ( $parts as $k => $v ) {
			if ( is_array( $v ) && isset( $v['greska'] ) ) {
				$errs[] = $v['greska'];
			} else {
				$ok[ $k ] = $v;
			}
		}
		$prev = OLR_Store::data( self::COLL, $m );
		$fn   = 'build_' . $m;
		$doc  = self::$fn( $ok, $prev, $parts );
		$jobs = self::jobs();
		$job  = $jobs[ $m ];
		$good = $doc && empty( $doc['prazno'] );
		if ( $good ) {
			$doc['at']     = time() * 1000;
			$doc['trosak'] = round( (float) $job['trosak'], 4 );
			$doc['greske'] = array_slice( array_values( array_unique( $errs ) ), 0, 5 );
			OLR_Store::set( self::COLL, $m, $doc );
		}
		$msg = $good ? ( isset( $doc['sazetak'] ) ? $doc['sazetak'] : 'gotovo' ) : ( $job['poruka'] ? $job['poruka'] : ( $errs ? 'nije uspjelo: ' . $errs[0] : 'nema podataka' ) );
		if ( $good && $partial ) {
			$msg .= ' (dio rezultata nije stigao na vrijeme)';
		}
		if ( $good && $job['poruka'] ) {
			$msg .= ' · ' . $job['poruka'];
		}
		$msg .= sprintf( ' · %.4f $', (float) $job['trosak'] );
		$jobs[ $m ] = array_merge(
			$job,
			array(
				'stanje'    => $good ? 'gotovo' : 'greska',
				'kraj'      => time(),
				'koraci'    => array(),
				'zadnje_ok' => $good ? time() : ( isset( $job['zadnje_ok'] ) ? $job['zadnje_ok'] : 0 ),
				'poruka'    => $msg,
			)
		);
		self::save_jobs( $jobs );
		OLR_Store::delete( self::RUN, $m );
		OLR_Settings::set_status( 'trziste_' . $m, $good, $msg );
		if ( $good && 'poveznice' === $m ) {
			self::to_suradnje( $doc );
		}
	}

	private static function of_kind( $parts, $kind ) {
		$out = array();
		foreach ( $parts as $k => $v ) {
			if ( 0 === strpos( $k, $kind . '_' ) || $k === $kind ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	private static function build_lokalno( $ok, $prev ) {
		$rows = self::of_kind( $ok, 'maps' );
		if ( ! $rows ) {
			return array( 'prazno' => true );
		}
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		foreach ( $rows as $r ) {
			$h = isset( $hist[ $r['rijec'] ] ) ? (array) $hist[ $r['rijec'] ] : array();
			$h = array_values(
				array_filter(
					$h,
					static function ( $p ) use ( $day ) {
						return $p[0] !== $day;
					}
				)
			);
			$h[]                   = array( $day, $r['nasa'] );
			$hist[ $r['rijec'] ] = array_slice( $h, -26 );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['rijec'], $b['rijec'] );
			}
		);
		$found = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return null !== $r['nasa'];
				}
			)
		);
		$top3  = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return null !== $r['nasa'] && $r['nasa'] <= 3;
				}
			)
		);
		return array(
			'rijeci'   => $rows,
			'povijest' => $hist,
			'sredina'  => self::map_coordinate(),
			'sazetak'  => sprintf( 'karta: %d upita, na karti smo za %d, u prva 3 za %d', count( $rows ), $found, $top3 ),
		);
	}

	private static function build_profili( $ok, $prev ) {
		$list = self::of_kind( $ok, 'profil' );
		if ( ! $list ) {
			return array( 'prazno' => true );
		}
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		$seen = array();
		$out  = array();
		foreach ( $list as $p ) {
			$k = '' !== $p['cid'] ? $p['cid'] : md5( $p['naziv'] );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$h          = isset( $hist[ $k ] ) ? (array) $hist[ $k ] : array();
			$h          = array_values(
				array_filter(
					$h,
					static function ( $x ) use ( $day ) {
						return $x[0] !== $day;
					}
				)
			);
			$h[]        = array( $day, $p['ocjena'], $p['glasova'] );
			$hist[ $k ] = array_slice( $h, -26 );
			$p['prije'] = count( $hist[ $k ] ) > 1 ? $hist[ $k ][ count( $hist[ $k ] ) - 2 ] : null;
			$out[]      = $p;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( (int) ! empty( $b['nas'] ) <=> (int) ! empty( $a['nas'] ) ) ?: ( (int) $b['glasova'] <=> (int) $a['glasova'] );
			}
		);
		$nas = null;
		foreach ( $out as $p ) {
			if ( ! empty( $p['nas'] ) ) {
				$nas = $p;
				break;
			}
		}
		return array(
			'profili'  => $out,
			'povijest' => $hist,
			'sazetak'  => $nas ? sprintf( 'profili: %d; mi %s ★ uz %d recenzija', count( $out ), null === $nas['ocjena'] ? '?' : number_format( (float) $nas['ocjena'], 1, ',', '' ), (int) $nas['glasova'] ) : sprintf( 'profili: %d (naš profil nije pronađen: provjeri naziv u Postavkama)', count( $out ) ),
		);
	}

	private static function build_recenzije( $ok, $prev ) {
		$list = self::of_kind( $ok, 'recenzije' );
		if ( ! $list ) {
			return array( 'prazno' => true );
		}
		$unanswered = 0;
		foreach ( $list as $i => $r ) {
			if ( ! empty( $r['nas'] ) ) {
				foreach ( $r['stavke'] as $x ) {
					$unanswered += $x['odgovor'] ? 0 : 1;
				}
			}
			// prosjek zadnjih recenzija (pokazuje smjer, a ne ukupnu ocjenu)
			$vals                   = array_filter( wp_list_pluck( $r['stavke'], 'ocjena' ), 'is_numeric' );
			$list[ $i ]['nedavno'] = $vals ? round( array_sum( $vals ) / count( $vals ), 2 ) : null;
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return (int) ! empty( $b['nas'] ) <=> (int) ! empty( $a['nas'] );
			}
		);
		return array(
			'tvrtke'      => $list,
			'bez_odgovora' => $unanswered,
			'sazetak'     => sprintf( 'recenzije: %d tvrtki; naših zadnjih bez odgovora: %d', count( $list ), $unanswered ),
		);
	}

	private static function build_volumen( $ok, $prev ) {
		$vol = array();
		foreach ( self::of_kind( $ok, 'ads_vol' ) as $p ) {
			foreach ( $p['redovi'] as $r ) {
				$vol[ $r['k'] ] = $r;
			}
		}
		$ideas = array();
		foreach ( self::of_kind( $ok, 'ads_ideje' ) as $p ) {
			foreach ( $p['redovi'] as $r ) {
				if ( ! isset( $vol[ $r['k'] ] ) ) {
					$ideas[ $r['k'] ] = $r;
				}
			}
		}
		if ( ! $vol && ! $ideas ) {
			return array( 'prazno' => true );
		}
		$gsc = self::gsc_by_query();
		foreach ( $vol as $k => $r ) {
			$vol[ $k ]['imp'] = isset( $gsc[ $k ] ) ? $gsc[ $k ]['imp'] : 0;
			$vol[ $k ]['pos'] = isset( $gsc[ $k ] ) ? $gsc[ $k ]['pos'] : null;
		}
		foreach ( $ideas as $k => $r ) {
			$ideas[ $k ]['imp'] = isset( $gsc[ $k ] ) ? $gsc[ $k ]['imp'] : 0;
		}
		$byv = static function ( $a, $b ) {
			return (int) $b['v'] <=> (int) $a['v'];
		};
		$vol   = array_values( $vol );
		$ideas = array_values( $ideas );
		usort( $vol, $byv );
		usort( $ideas, $byv );
		return array(
			'rijeci'  => $vol,
			'ideje'   => array_slice( $ideas, 0, 300 ),
			'izvor'   => 'Google Ads (DataForSEO), Hrvatska, hrvatski',
			'sazetak' => sprintf( 'obujam: %d riječi, %d novih ideja', count( $vol ), count( $ideas ) ),
		);
	}

	/** Upiti iz Search Consolea (28 dana): prikazivanja i ponderirana pozicija po upitu. */
	private static function gsc_by_query() {
		$qp  = OLR_Store::data( 'gsc', 'qp', array() );
		$out = array();
		foreach ( isset( $qp['rows'] ) ? (array) $qp['rows'] : array() as $r ) {
			$q = OLR_DFS::lower( $r[0] );
			if ( ! isset( $out[ $q ] ) ) {
				$out[ $q ] = array( 'imp' => 0, 'pw' => 0.0, 'clk' => 0 );
			}
			$out[ $q ]['imp'] += (int) $r[3];
			$out[ $q ]['clk'] += (int) $r[2];
			$out[ $q ]['pw']  += (float) $r[5] * (int) $r[3];
		}
		foreach ( $out as $q => $x ) {
			$out[ $q ]['pos'] = $x['imp'] ? round( $x['pw'] / $x['imp'], 1 ) : null;
		}
		return $out;
	}

	private static function build_kljucne( $ok, $prev, $all ) {
		if ( isset( $all['labs_loc'] ) && empty( $all['labs_loc']['podrzano'] ) ) {
			return array( 'prazno' => true );
		}
		$over = array();
		foreach ( self::of_kind( $ok, 'labs_overview' ) as $o ) {
			$over[ $o['domena'] ] = $o;
		}
		$lists = array();
		foreach ( self::of_kind( $ok, 'labs_ranked' ) as $l ) {
			$lists[ $l['domena'] ] = $l;
		}
		if ( ! $over && ! $lists ) {
			return array( 'prazno' => true );
		}
		$own  = self::own();
		$ours = isset( $lists[ $own ] ) ? $lists[ $own ]['redovi'] : array();
		$mine = array();
		foreach ( $ours as $r ) {
			if ( ! isset( $mine[ $r['k'] ] ) || ( null !== $r['pos'] && $r['pos'] < $mine[ $r['k'] ] ) ) {
				$mine[ $r['k'] ] = $r['pos'];
			}
		}
		OLR_Analiza::reset();
		// Praznine: konkurent je u prvih 10, a mi nismo u prvih 20 (ili nas nema); bez imena tvrtki.
		$gaps = array();
		foreach ( $lists as $d => $l ) {
			if ( $d === $own ) {
				continue;
			}
			foreach ( $l['redovi'] as $r ) {
				if ( null === $r['pos'] || $r['pos'] > 10 || OLR_Analiza::skip( $r['k'] ) ) {
					continue;
				}
				$base = preg_replace( '/\.[a-z.]+$/', '', $d );
				if ( strlen( $base ) >= 4 && false !== strpos( str_replace( array( ' ', '-' ), '', $r['k'] ), str_replace( '-', '', $base ) ) ) {
					continue; // upit s imenom te domene
				}
				$m = isset( $mine[ $r['k'] ] ) ? $mine[ $r['k'] ] : null;
				if ( null !== $m && $m <= 20 ) {
					continue;
				}
				if ( ! isset( $gaps[ $r['k'] ] ) ) {
					$gaps[ $r['k'] ] = array( 'k' => $r['k'], 'v' => $r['v'], 'nasa' => $m, 'namjera' => $r['namjera'], 'kd' => $r['kd'], 'konkurenti' => array() );
				}
				$gaps[ $r['k'] ]['konkurenti'][ $d ] = $r['pos'];
				$gaps[ $r['k'] ]['v']                = max( (int) $gaps[ $r['k'] ]['v'], (int) $r['v'] );
			}
		}
		$gaps = array_values( $gaps );
		usort(
			$gaps,
			static function ( $a, $b ) {
				return ( count( $b['konkurenti'] ) <=> count( $a['konkurenti'] ) ) ?: ( (int) $b['v'] <=> (int) $a['v'] );
			}
		);
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		foreach ( $over as $d => $o ) {
			$h          = isset( $hist[ $d ] ) ? (array) $hist[ $d ] : array();
			$h          = array_values(
				array_filter(
					$h,
					static function ( $x ) use ( $day ) {
						return $x[0] !== $day;
					}
				)
			);
			$h[]        = array( $day, $o['top10'], $o['rijeci'] );
			$hist[ $d ] = array_slice( $h, -24 );
		}
		$dom = array_values( $over );
		usort(
			$dom,
			static function ( $a, $b ) {
				return (int) $b['top10'] <=> (int) $a['top10'];
			}
		);
		$comp = array();
		foreach ( $lists as $d => $l ) {
			if ( $d !== $own ) {
				$comp[ $d ] = array_slice( $l['redovi'], 0, 100 );
			}
		}
		return array(
			'domene'     => $dom,
			'nase'       => array_slice( $ours, 0, 1000 ),
			'nase_ukupno' => isset( $lists[ $own ] ) ? $lists[ $own ]['ukupno'] : 0,
			'praznine'   => array_slice( $gaps, 0, 300 ),
			'konkurenti' => $comp,
			'povijest'   => $hist,
			'izvor'      => 'DataForSEO Labs (Google, Hrvatska, hrvatski)',
			'sazetak'    => sprintf( 'ključne riječi: naših %d, praznina %d, domena %d', count( $ours ), count( $gaps ), count( $dom ) ),
		);
	}

	private static function build_poveznice( $ok, $prev ) {
		$sum = array();
		foreach ( self::of_kind( $ok, 'bl_summary' ) as $s ) {
			$sum[ $s['domena'] ] = $s;
		}
		$ours  = self::of_kind( $ok, 'bl_nase' );
		$gaps  = array();
		foreach ( self::of_kind( $ok, 'bl_praznina' ) as $g ) {
			foreach ( $g['redovi'] as $r ) {
				if ( $r['n'] < 2 || $r['prodavac'] || ( null !== $r['spam'] && $r['spam'] >= 40 ) || OLR_Suradnje::blocked( $r['d'] ) ) {
					continue;
				}
				$gaps[] = $r;
			}
		}
		usort(
			$gaps,
			static function ( $a, $b ) {
				return ( $b['n'] <=> $a['n'] ) ?: ( (float) $b['rank'] <=> (float) $a['rank'] );
			}
		);
		$new    = array();
		$cutoff = gmdate( 'Y-m-d', time() - 45 * DAY_IN_SECONDS );
		foreach ( self::of_kind( $ok, 'bl_novo' ) as $l ) {
			foreach ( $l['redovi'] as $r ) {
				if ( $r['prvi'] >= $cutoff && ! $r['prodavac'] && ( null === $r['spam'] || $r['spam'] < 40 ) && ! OLR_Suradnje::blocked( $r['d'] ) ) {
					$r['konkurent'] = $l['domena'];
					$new[]          = $r;
				}
			}
		}
		if ( ! $sum && ! $ours && ! $gaps ) {
			return array( 'prazno' => true );
		}
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		foreach ( $sum as $d => $s ) {
			$h          = isset( $hist[ $d ] ) ? (array) $hist[ $d ] : array();
			$h          = array_values(
				array_filter(
					$h,
					static function ( $x ) use ( $day ) {
						return $x[0] !== $day;
					}
				)
			);
			$h[]        = array( $day, $s['rank'], $s['domena_n'] );
			$hist[ $d ] = array_slice( $h, -24 );
		}
		$dom = array_values( $sum );
		usort(
			$dom,
			static function ( $a, $b ) {
				return (float) $b['rank'] <=> (float) $a['rank'];
			}
		);
		$own_list = $ours ? $ours[0]['redovi'] : array();
		$clean    = count(
			array_filter(
				$own_list,
				static function ( $r ) {
					return ! $r['prodavac'] && ( null === $r['spam'] || $r['spam'] < 40 ) && '' === $r['izgubljeno'];
				}
			)
		);
		$own = self::own();
		return array(
			'domene'    => $dom,
			'nase'      => $own_list,
			'nase_cistih' => $clean,
			'praznina'  => array_slice( $gaps, 0, 200 ),
			'novo_kod_konkurenata' => array_slice( $new, 0, 100 ),
			'povijest'  => $hist,
			'ljestvica' => 'DataForSEO rank 0–100 (nije Ahrefsov DR)',
			'sazetak'   => sprintf( 'poveznice: rank %s, domena bez spama %d, praznina %d', isset( $sum[ $own ]['rank'] ) ? (string) $sum[ $own ]['rank'] : '?', $clean, count( $gaps ) ),
		);
	}

	/** Domene koje povezuju barem dva konkurenta, a nas ne: novi kandidati u Suradnjama (najviše 20 po prolazu). */
	private static function to_suradnje( $doc ) {
		$n = 0;
		foreach ( isset( $doc['praznina'] ) ? $doc['praznina'] : array() as $r ) {
			if ( $n >= 20 ) {
				break;
			}
			$had = OLR_Store::data( OLR_Suradnje::COLL, OLR_Suradnje::id( $r['d'] ) );
			if ( $had ) {
				continue;
			}
			$res = OLR_Suradnje::add(
				array(
					'domena'     => $r['d'],
					'naziv'      => $r['d'],
					'konkurenti' => $r['konkurenti'],
					'nacin'      => 'Povezuje ' . $r['n'] . ' konkurenta (DataForSEO, ' . gmdate( 'j. n. Y.' ) . ').',
				),
				'dataforseo'
			);
			$n += is_wp_error( $res ) ? 0 : 1;
		}
		return $n;
	}

	private static function build_spominjanja( $ok, $prev, $all ) {
		$list = self::of_kind( $ok, 'ca_search' );
		if ( ! $list ) {
			return array( 'prazno' => true );
		}
		$rows = $list[0]['stavke'];
		foreach ( $rows as $i => $r ) {
			$k = 'link_' . md5( $r['url'] );
			$rows[ $i ]['veza'] = isset( $all[ $k ] ) ? array( 'ima' => ! empty( $all[ $k ]['ima'] ), 'nofollow' => ! empty( $all[ $k ]['nofollow'] ), 'poruka' => $all[ $k ]['poruka'] ) : null;
		}
		$unlinked = count(
			array_filter(
				$rows,
				static function ( $r ) {
					return $r['veza'] && ! $r['veza']['ima'];
				}
			)
		);
		return array(
			'stavke'      => $rows,
			'ukupno'      => $list[0]['ukupno'],
			'bez_poveznice' => $unlinked,
			'naziv'       => (string) OLR_Settings::get( 'dfs_brend_naziv' ),
			'sazetak'     => sprintf( 'spominjanja: %d, provjereno bez poveznice %d', count( $rows ), $unlinked ),
		);
	}

	private static function build_brzina( $ok, $prev ) {
		$list = self::of_kind( $ok, 'lighthouse' );
		if ( ! $list ) {
			return array( 'prazno' => true );
		}
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		foreach ( $list as $i => $r ) {
			$k        = $r['url'];
			$h        = isset( $hist[ $k ] ) ? (array) $hist[ $k ] : array();
			$list[ $i ]['prije'] = $h ? end( $h ) : null;
			$h        = array_values(
				array_filter(
					$h,
					static function ( $x ) use ( $day ) {
						return $x[0] !== $day;
					}
				)
			);
			$h[]      = array( $day, $r['ocjena'], $r['lcp'] );
			$hist[ $k ] = array_slice( $h, -12 );
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return (int) $a['ocjena'] <=> (int) $b['ocjena'];
			}
		);
		$slow = count(
			array_filter(
				$list,
				static function ( $r ) {
					return ( null !== $r['lcp'] && $r['lcp'] > 4 ) || ( null !== $r['ocjena'] && $r['ocjena'] < 50 );
				}
			)
		);
		return array(
			'stranice' => $list,
			'sporih'   => $slow,
			'povijest' => $hist,
			'uredaj'   => 'mobitel',
			'sazetak'  => sprintf( 'brzina: %d stranica, sporih %d', count( $list ), $slow ),
		);
	}

	private static function build_ai( $ok, $prev ) {
		$list = self::of_kind( $ok, 'ai_odgovor' );
		if ( ! $list ) {
			return array( 'prazno' => true );
		}
		$by = array();
		foreach ( $list as $r ) {
			$p = $r['platforma'];
			if ( ! isset( $by[ $p ] ) ) {
				$by[ $p ] = array( 'platforma' => $p, 'naziv' => self::AI_PLATFORME[ $p ], 'odgovora' => 0, 'spominje' => 0, 'citira' => 0, 'model' => $r['model'] );
			}
			++$by[ $p ]['odgovora'];
			$by[ $p ]['spominje'] += $r['spominje'] ? 1 : 0;
			$by[ $p ]['citira']   += $r['citira'] ? 1 : 0;
		}
		$rivals = array();
		foreach ( $list as $r ) {
			foreach ( $r['konkurenti'] as $c ) {
				$rivals[ $c ] = ( isset( $rivals[ $c ] ) ? $rivals[ $c ] : 0 ) + 1;
			}
		}
		arsort( $rivals );
		$hist = $prev && isset( $prev['povijest'] ) ? (array) $prev['povijest'] : array();
		$n    = count( $list );
		$men  = count( array_filter( wp_list_pluck( $list, 'spominje' ) ) );
		$hist[] = array( gmdate( 'Y-m-d' ), $men, $n );
		return array(
			'odgovori'   => $list,
			'platforme'  => array_values( $by ),
			'konkurenti' => $rivals,
			'povijest'   => array_slice( $hist, -12 ),
			'sazetak'    => sprintf( 'AI asistenti: spominju nas u %d od %d odgovora', $men, $n ),
		);
	}

	/* ---------- sažetak za ploču, MCP i stanje ---------- */

	public static function modules_state() {
		$jobs = self::jobs();
		$out  = array();
		foreach ( self::MODULI as $m => $d ) {
			$j         = isset( $jobs[ $m ] ) ? $jobs[ $m ] : array();
			$out[ $m ] = array(
				'naziv'     => $d['naziv'],
				'ukljuceno' => self::enabled( $m ),
				'ritam'     => $d['ritam'],
				'stanje'    => isset( $j['stanje'] ) ? $j['stanje'] : 'nikad',
				'zadnje'    => ! empty( $j['zadnje_ok'] ) ? (int) $j['zadnje_ok'] * 1000 : null,
				'poruka'    => isset( $j['poruka'] ) ? (string) $j['poruka'] : '',
				'procjena'  => self::estimate( $m ),
			);
		}
		return $out;
	}

	/**
	 * Podaci za ploču i Claudea. $dio ograničava na jedan modul; $limit skraćuje duge popise.
	 */
	public static function summary( $dio = '', $limit = 300 ) {
		$out = array(
			'moduli'   => self::modules_state(),
			'trosak'   => array(
				'mjesec'   => round( self::month_spent(), 4 ),
				'limit'    => self::limit(),
				'procjena_mjesecno' => self::estimate_month( true ),
				'procjena_sve'      => self::estimate_month( false ),
			),
			'konkurenti' => self::rivals(),
			'u_tijeku' => self::progress(),
		);
		$cut = static function ( $a ) use ( $limit ) {
			return is_array( $a ) ? array_slice( $a, 0, $limit ) : $a;
		};
		foreach ( array_keys( self::MODULI ) as $m ) {
			if ( '' !== $dio && $dio !== $m ) {
				continue;
			}
			$d = OLR_Store::data( self::COLL, $m );
			if ( ! $d ) {
				$out[ $m ] = null;
				continue;
			}
			foreach ( array( 'nase', 'praznine', 'praznina', 'ideje', 'rijeci', 'stavke', 'novo_kod_konkurenata', 'odgovori' ) as $k ) {
				if ( isset( $d[ $k ] ) && 'lokalno' !== $m ) {
					$d[ $k ] = $cut( $d[ $k ] );
				}
			}
			if ( 'kljucne' === $m && isset( $d['konkurenti'] ) ) {
				foreach ( $d['konkurenti'] as $k => $l ) {
					$d['konkurenti'][ $k ] = array_slice( $l, 0, min( 100, $limit ) );
				}
			}
			$out[ $m ] = $d;
		}
		return $out;
	}
}
