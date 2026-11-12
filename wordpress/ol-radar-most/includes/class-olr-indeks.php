<?php
/**
 * Indeksiranost stranica u Googleu (Search Console URL Inspection API, isti servisni račun kao za Search Console).
 *
 * Za svaku stranicu koja bi trebala biti u indeksu pita Google: je li u indeksu, koju je adresu odabrao kao
 * kanonsku, smije li je dohvatiti i kad ju je zadnji put posjetio. Samo čitanje; ne traži indeksiranje i ništa
 * ne mijenja. Googleovo ograničenje je 2.000 provjera dnevno po svojstvu; ovdje se troši najviše 600.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Indeks {

	const API        = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
	const COLL       = 'indeks';
	const DAY_OPT    = 'olr_indeks_dan';
	const DAILY_MAX  = 600;
	/** Stranica se ponovno provjerava nakon ovoliko dana; problematične češće. */
	const REFRESH    = 7;
	const REFRESH_BAD = 2;
	const MAX_URLS   = 400;

	const FETCH = array(
		'SOFT_404'             => 'Google stranicu vidi kao praznu ili nepostojeću (soft 404)',
		'BLOCKED_ROBOTS_TXT'   => 'dohvat je blokiran u robots.txt',
		'NOT_FOUND'            => 'stranica vraća 404',
		'ACCESS_DENIED'        => 'pristup odbijen (401)',
		'SERVER_ERROR'         => 'greška servera (5xx)',
		'REDIRECT_ERROR'       => 'greška preusmjeravanja',
		'ACCESS_FORBIDDEN'     => 'pristup zabranjen (403)',
		'BLOCKED_4XX'          => 'stranica vraća grešku 4xx',
		'INTERNAL_CRAWL_ERROR' => 'Googleova interna greška pri dohvatu',
		'INVALID_URL'          => 'neispravna adresa',
	);

	private static function id( $url ) {
		return substr( md5( self::norm( $url ) ), 0, 20 );
	}

	/** Adresa bez sheme, „www.” i završne kose crte, za usporedbu kanonskih adresa. */
	public static function norm( $url ) {
		$u = preg_replace( '#^https?://(www\.)?#i', '', strtolower( trim( (string) $url ) ) );
		$u = preg_replace( '/[?#].*$/', '', $u );
		return rtrim( $u, '/' );
	}

	/** Dnevni brojač provjera (Googleova kvota je po danu). */
	private static function used_today() {
		$o = get_option( self::DAY_OPT );
		return is_array( $o ) && isset( $o['dan'], $o['n'] ) && gmdate( 'Y-m-d' ) === $o['dan'] ? (int) $o['n'] : 0;
	}

	private static function bump() {
		update_option(
			self::DAY_OPT,
			array(
				'dan' => gmdate( 'Y-m-d' ),
				'n'   => self::used_today() + 1,
			),
			false
		);
	}

	/**
	 * Stranice koje bi trebale biti u indeksu: objavljene, odgovaraju s 200, nisu noindex i nemaju kanonsku
	 * adresu koja pokazuje drugamo. Poredane po prikazivanjima u Search Consoleu (najvažnije prve).
	 *
	 * @return array url => prikazivanja u 28 dana
	 */
	public static function urls() {
		$impr = array();
		$pg   = OLR_Store::data( 'gsc', 'pages', array() );
		foreach ( isset( $pg['rows'] ) ? $pg['rows'] : array() as $r ) {
			$k          = OLR_Analiza::path( $r[0] );
			$impr[ $k ] = ( isset( $impr[ $k ] ) ? $impr[ $k ] : 0 ) + (int) $r[2];
		}
		$out = array();
		foreach ( OLR_Crawler::all_rows() as $url => $r ) {
			if ( 'page' !== $r->kind || 200 !== (int) $r->status ) {
				continue;
			}
			$d = is_array( $r->data ) ? $r->data : array();
			if ( ! empty( $d['robots'] ) && false !== strpos( (string) $d['robots'], 'noindex' ) ) {
				continue;
			}
			if ( ! empty( $d['canonical'] ) && self::norm( $d['canonical'] ) !== self::norm( $url ) ) {
				continue;
			}
			$path        = OLR_Analiza::path( $url );
			$out[ $url ] = isset( $impr[ $path ] ) ? $impr[ $path ] : 0;
		}
		arsort( $out );
		return array_slice( $out, 0, self::MAX_URLS, true );
	}

	/** Spremljeni rezultati: normalizirana adresa => zapis. */
	public static function docs() {
		$out = array();
		foreach ( OLR_Store::list_docs( self::COLL, '', 'asc', 2000 ) as $d ) {
			if ( is_array( $d['data'] ) && ! empty( $d['data']['url'] ) ) {
				$out[ self::norm( $d['data']['url'] ) ] = $d['data'];
			}
		}
		return $out;
	}

	/**
	 * Jedna provjera kod Googlea. Vraća zapis za pohranu ili WP_Error (greška veze, pristupa ili kvote).
	 */
	public static function inspect( $url ) {
		$token = OLR_GSC::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$pref = OLR_Net::preferred();
		$args = array(
			'timeout'     => 25,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'        => wp_json_encode(
				array(
					'inspectionUrl' => $url,
					'siteUrl'       => OLR_Settings::get( 'gsc_site' ),
					'languageCode'  => 'hr',
				)
			),
		);
		$res  = OLR_Net::post( self::API, $args, $pref['nacin'] );
		if ( is_wp_error( $res ) && 'v4h1' !== $pref['nacin'] ) {
			$res = OLR_Net::post( self::API, $args, 'v4h1' );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'olr_indeks_mreza', 'Google (URL Inspection) nije odgovorio: ' . $res->get_error_message() );
		}
		self::bump();
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		$gmsg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : '';
		if ( 429 === $code ) {
			return new WP_Error( 'olr_indeks_kvota', 'Dosegnuta je Googleova dnevna kvota provjera indeksiranosti. Nastavlja se sutra.' );
		}
		if ( 403 === $code ) {
			if ( false !== stripos( $gmsg, 'has not been used' ) || false !== stripos( $gmsg, 'is disabled' ) ) {
				return new WP_Error( 'olr_indeks_api', 'Google Search Console API nije uključen u Google Cloud projektu servisnog računa.' );
			}
			return new WP_Error( 'olr_indeks_pristup', 'Servisni račun nema pristup svojstvu ' . OLR_Settings::get( 'gsc_site' ) . ' za provjeru indeksiranosti (treba biti korisnik svojstva u Search Consoleu).' );
		}
		if ( 400 === $code ) {
			// Adresa ne pripada svojstvu ili je neispravna: to je podatak o toj stranici, ne kvar veze.
			return array(
				'url'    => $url,
				'at'     => time() * 1000,
				'greska' => '' !== $gmsg ? $gmsg : 'Google je odbio adresu (ne pripada svojstvu u Search Consoleu).',
			);
		}
		if ( 200 !== $code || ! isset( $json['inspectionResult'] ) ) {
			return new WP_Error( 'olr_indeks_odgovor', 'URL Inspection: ' . ( '' !== $gmsg ? $gmsg : 'HTTP ' . $code ) );
		}
		$ir = $json['inspectionResult'];
		$is = isset( $ir['indexStatusResult'] ) && is_array( $ir['indexStatusResult'] ) ? $ir['indexStatusResult'] : array();
		$g  = static function ( $k ) use ( $is ) {
			return isset( $is[ $k ] ) && is_string( $is[ $k ] ) ? $is[ $k ] : '';
		};
		return self::classify(
			array(
				'url'             => $url,
				'at'              => time() * 1000,
				'verdict'         => $g( 'verdict' ),
				'stanje_google'   => $g( 'coverageState' ),
				'robots'          => $g( 'robotsTxtState' ),
				'indeksiranje'    => $g( 'indexingState' ),
				'dohvat'          => $g( 'pageFetchState' ),
				'zadnji_posjet'   => $g( 'lastCrawlTime' ),
				'google_kanonski' => $g( 'googleCanonical' ),
				'nas_kanonski'    => $g( 'userCanonical' ),
				'crawlan_kao'     => $g( 'crawledAs' ),
				'u_sitemapu'      => ! empty( $is['sitemap'] ),
				'link'            => isset( $ir['inspectionResultLink'] ) && is_string( $ir['inspectionResultLink'] ) ? $ir['inspectionResultLink'] : '',
			)
		);
	}

	/**
	 * Iz Googleova odgovora: je li stranica u indeksu, postoji li problem i kako ga opisati.
	 * problem: '' (sve u redu), kanonski (u indeksu, ali Google je odabrao drugu adresu), nije (nije u indeksu).
	 */
	public static function classify( $d ) {
		$d['u_indeksu'] = 'PASS' === $d['verdict'];
		$d['problem']   = '';
		$d['razlog']    = '';
		$other          = '' !== $d['google_kanonski'] && self::norm( $d['google_kanonski'] ) !== self::norm( $d['url'] );
		if ( $d['u_indeksu'] ) {
			if ( $other ) {
				$d['problem'] = 'kanonski';
				$d['razlog']  = 'Google je kao kanonsku odabrao drugu adresu: ' . $d['google_kanonski'];
			}
			return $d;
		}
		$d['problem'] = 'nije';
		if ( 'DISALLOWED' === $d['robots'] ) {
			$d['razlog'] = 'dohvat je blokiran u robots.txt';
		} elseif ( in_array( $d['indeksiranje'], array( 'BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER' ), true ) ) {
			$d['razlog'] = 'stranica ima oznaku noindex' . ( 'BLOCKED_BY_HTTP_HEADER' === $d['indeksiranje'] ? ' (HTTP zaglavlje)' : '' );
		} elseif ( 'BLOCKED_BY_ROBOTS_TXT' === $d['indeksiranje'] ) {
			$d['razlog'] = 'indeksiranje je blokirano u robots.txt';
		} elseif ( '' !== $d['dohvat'] && 'SUCCESSFUL' !== $d['dohvat'] && 'PAGE_FETCH_STATE_UNSPECIFIED' !== $d['dohvat'] ) {
			$d['razlog'] = isset( self::FETCH[ $d['dohvat'] ] ) ? self::FETCH[ $d['dohvat'] ] : 'dohvat nije uspio (' . $d['dohvat'] . ')';
		} elseif ( $other ) {
			$d['razlog'] = 'Google ovu adresu smatra kopijom i indeksira drugu: ' . $d['google_kanonski'];
		} elseif ( '' !== $d['stanje_google'] ) {
			$d['razlog'] = $d['stanje_google'];
		} else {
			$d['razlog'] = 'Google nije naveo razlog';
		}
		return $d;
	}

	/** Treba li stranicu (ponovno) provjeriti. */
	private static function stale( $doc ) {
		if ( ! $doc || empty( $doc['at'] ) ) {
			return true;
		}
		$age = time() - (int) ( $doc['at'] / 1000 );
		$bad = ! empty( $doc['greska'] ) || ( isset( $doc['problem'] ) && '' !== $doc['problem'] );
		return $age > ( $bad ? self::REFRESH_BAD : self::REFRESH ) * DAY_IN_SECONDS;
	}

	/** Koliko stranica čeka provjeru. */
	public static function remaining() {
		$docs = self::docs();
		$n    = 0;
		foreach ( self::urls() as $url => $i ) {
			$k = self::norm( $url );
			$n += self::stale( isset( $docs[ $k ] ) ? $docs[ $k ] : null ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Provjeri seriju stranica (najprije one koje nikad nisu provjerene, zatim najstarije).
	 *
	 * @param int  $max    Najviše provjera u ovoj seriji.
	 * @param int  $budget Vremenski okvir u sekundama.
	 * @param bool $force  Ručno pokretanje: ponovno provjeri i svježe rezultate.
	 * @return array|WP_Error Sažetak serije.
	 */
	public static function run_batch( $max = 12, $budget = 25, $force = false ) {
		if ( ! OLR_Settings::secret( 'gsc_sa' ) ) {
			return new WP_Error( 'olr_indeks_gsc', 'Za provjeru indeksiranosti treba Google servisni račun (Postavke).' );
		}
		$t0   = time();
		$docs = self::docs();
		$urls = self::urls();
		$todo = array();
		foreach ( $urls as $url => $i ) {
			$k   = self::norm( $url );
			$doc = isset( $docs[ $k ] ) ? $docs[ $k ] : null;
			if ( $force ? ( ! $doc || time() - (int) ( $doc['at'] / 1000 ) > HOUR_IN_SECONDS ) : self::stale( $doc ) ) {
				$todo[ $url ] = $doc ? (int) $doc['at'] : 0;
			}
		}
		asort( $todo ); // nikad provjerene (0) pa najstarije; unutar istog redoslijed po važnosti ostaje
		// Zapisi stranica kojih više nema (obrisane, sada noindex) se uklanjaju.
		$known = array();
		foreach ( $urls as $url => $i ) {
			$known[ self::norm( $url ) ] = true;
		}
		foreach ( $docs as $k => $d ) {
			if ( ! isset( $known[ $k ] ) ) {
				OLR_Store::delete( self::COLL, self::id( $d['url'] ) );
			}
		}
		$done = 0;
		$err  = null;
		foreach ( $todo as $url => $at ) {
			if ( $done >= $max || time() - $t0 > $budget ) {
				break;
			}
			if ( self::used_today() >= self::DAILY_MAX ) {
				$err = new WP_Error( 'olr_indeks_dnevno', 'Današnji broj provjera je potrošen (' . self::DAILY_MAX . '). Nastavlja se sutra.' );
				break;
			}
			$r = self::inspect( $url );
			if ( is_wp_error( $r ) ) {
				$err = $r;
				break;
			}
			OLR_Store::set( self::COLL, self::id( $url ), $r );
			++$done;
		}
		$left = max( 0, count( $todo ) - $done );
		$sum  = self::summary( 0 );
		if ( $err && ! $done ) {
			OLR_Settings::set_status( 'indeks', false, $err->get_error_message() );
			return $err;
		}
		OLR_Settings::set_status(
			'indeks',
			true,
			$sum['provjereno'] . ' od ' . $sum['ukupno'] . ' stranica provjereno: ' . $sum['u_indeksu'] . ' u indeksu, ' . $sum['nije'] . ' nije'
				. ( $sum['kanonski'] ? ', ' . $sum['kanonski'] . ' s drugom kanonskom adresom' : '' )
				. ( $left ? '; preostalo ' . $left : '' )
				. ( $err ? '; ' . $err->get_error_message() : '' )
		);
		return array(
			'provjereno_sada' => $done,
			'preostalo'       => $left,
			'sazetak'         => $sum,
		);
	}

	/** Satni prolaz: mala serija ako je provjera uključena i ima stranica koje čekaju. */
	public static function tick() {
		if ( ! OLR_Settings::get( 'indeks_auto' ) || ! OLR_Settings::secret( 'gsc_sa' ) ) {
			return;
		}
		$st = OLR_Settings::status();
		// Nakon greške (pristup, kvota, veza) novi pokušaj tek za 6 sati.
		if ( isset( $st['indeks'] ) && empty( $st['indeks']['ok'] ) && time() - (int) $st['indeks']['at'] < 6 * HOUR_IN_SECONDS ) {
			return;
		}
		if ( self::remaining() ) {
			self::run_batch( 12, 25 );
		}
	}

	/** Zapis jedne stranice za prikaz (bez sirovih polja koja ploči ne trebaju). */
	private static function row( $d, $impr ) {
		return array(
			'url'             => $d['url'],
			'put'             => OLR_Analiza::path( $d['url'] ),
			'u_indeksu'       => ! empty( $d['u_indeksu'] ),
			'problem'         => isset( $d['problem'] ) ? $d['problem'] : ( ! empty( $d['greska'] ) ? 'greska' : '' ),
			'razlog'          => ! empty( $d['greska'] ) ? $d['greska'] : ( isset( $d['razlog'] ) ? $d['razlog'] : '' ),
			'stanje_google'   => isset( $d['stanje_google'] ) ? $d['stanje_google'] : '',
			'zadnji_posjet'   => isset( $d['zadnji_posjet'] ) ? $d['zadnji_posjet'] : '',
			'google_kanonski' => isset( $d['google_kanonski'] ) ? $d['google_kanonski'] : '',
			'crawlan_kao'     => isset( $d['crawlan_kao'] ) ? $d['crawlan_kao'] : '',
			'u_sitemapu'      => ! empty( $d['u_sitemapu'] ),
			'link'            => isset( $d['link'] ) ? $d['link'] : '',
			'provjereno'      => (int) $d['at'],
			'prikazivanja_28' => (int) $impr,
		);
	}

	/**
	 * Sažetak za ploču i MCP.
	 *
	 * @param int $limit Najviše stranica u popisu (0 = samo brojke); problematične su uvijek prve.
	 */
	public static function summary( $limit = 400 ) {
		$docs = self::docs();
		$urls = self::urls();
		$rows = array();
		$out  = array(
			'ukupno'     => count( $urls ),
			'provjereno' => 0,
			'u_indeksu'  => 0,
			'nije'       => 0,
			'kanonski'   => 0,
			'greske'     => 0,
			'preostalo'  => 0,
			'zadnja'     => null,
			'ukljuceno'  => (bool) OLR_Settings::get( 'indeks_auto' ),
			'danas'      => self::used_today(),
		);
		foreach ( $urls as $url => $impr ) {
			$k = self::norm( $url );
			if ( ! isset( $docs[ $k ] ) ) {
				++$out['preostalo'];
				continue;
			}
			$d = $docs[ $k ];
			++$out['provjereno'];
			$out['preostalo'] += self::stale( $d ) ? 1 : 0;
			$out['zadnja']     = max( (int) $out['zadnja'], (int) $d['at'] );
			if ( ! empty( $d['greska'] ) ) {
				++$out['greske'];
			} elseif ( ! empty( $d['u_indeksu'] ) ) {
				++$out['u_indeksu'];
				$out['kanonski'] += 'kanonski' === $d['problem'] ? 1 : 0;
			} else {
				++$out['nije'];
			}
			$rows[] = self::row( $d, $impr );
		}
		if ( $limit > 0 ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					$pa = '' === $a['problem'] ? 1 : 0;
					$pb = '' === $b['problem'] ? 1 : 0;
					return ( $pa <=> $pb ) ?: ( $b['prikazivanja_28'] <=> $a['prikazivanja_28'] ) ?: strcmp( $a['put'], $b['put'] );
				}
			);
			$out['stranice'] = array_slice( $rows, 0, $limit );
		}
		return $out;
	}
}
