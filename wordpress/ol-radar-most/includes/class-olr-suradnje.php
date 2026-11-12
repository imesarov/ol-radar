<?php
/**
 * Suradnje i poveznice: tko bi nas mogao spomenuti i povezati, kome se obratiti i što ponuditi.
 *
 *  - Kandidati: (1) domene koje upućuju na konkurente, a ne na nas (snimak Ahrefsa priložen dodatku),
 *    (2) tvrtke i ustanove koje Claude tjedno pronađe web pretragom, (3) ručno dodane domene.
 *  - Za svakog kandidata Claude piše prijedlog: zašto baš on, što ponuditi, koraci i nacrt poruke.
 *    Server prije toga provjeri da web postoji i s njega pročita javni kontakt (stranica, e-pošta, telefon).
 *  - Domain Rating (Ahrefsov besplatni javni API) za nas i konkurente, jednom tjedno, s poviješću.
 *  - Kad vlasnik upiše adresu na kojoj je poveznica objavljena, server provjeri postoji li i je li nofollow.
 *
 * Ništa se ne šalje nikome: poruke su nacrti za vlasnika. Kupnja i razmjena poveznica se ne predlažu.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Suradnje {

	const COLL     = 'suradnje';
	const STATE    = 'suradnje_stanje';
	const DR_COLL  = 'suradnje_dr';
	const RUN_OPT  = 'olr_suradnje_run';
	const SEED_OPT = 'olr_suradnje_seed';
	const TOOL     = 'spremi_suradnje';
	const DR_API   = 'https://api.ahrefs.com/v3/public/domain-rating-free';
	const MANUAL   = 3;
	const VRSTE    = array(
		'medij'              => 'Medij',
		'lokalni_portal'     => 'Lokalni portal',
		'partnerski_program' => 'Partnerski program',
		'imenik'             => 'Imenik ili profil',
		'dobavljac'          => 'Dobavljač ili brend',
		'zdravstvo'          => 'Zdravstvo',
		'obrazovanje'        => 'Obrazovanje',
		'sport'              => 'Sport i rekreacija',
		'tvrtka'             => 'Tvrtka',
		'udruga'             => 'Udruga',
		'ostalo'             => 'Ostalo',
	);
	const STANJA   = array( 'novo', 'kontaktirano', 'dogovoreno', 'dobiveno', 'odbijeno' );

	/* ---------- pomoćno ---------- */

	private static function cut( $s, $n ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	/** Kao cut(), ali čuva prijelome redaka (nacrt poruke). */
	private static function cut_lines( $s, $n ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( (string) $s ) ) as $l ) {
			$lines[] = trim( preg_replace( '/[ \t]+/u', ' ', $l ) );
		}
		$s = trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	/** Domena bez protokola, www i putanje; prazno ako nije valjana. */
	public static function host( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '#^[a-z]+://#', '', $s );
		$s = preg_replace( '#[/?\#].*$#', '', $s );
		$s = preg_replace( '#^www\.#', '', $s );
		return preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $s ) ? $s : '';
	}

	public static function id( $domain ) {
		return 's-' . substr( md5( $domain ), 0, 12 );
	}

	public static function own() {
		return self::host( OLR_Settings::get( 'domain' ) );
	}

	public static function competitors() {
		$out = array();
		foreach ( preg_split( '/[,\s]+/', (string) OLR_Settings::get( 'konkurenti' ) ) as $d ) {
			$d = self::host( $d );
			if ( '' !== $d && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/** Domene koje nisu kandidati: mi, konkurenti, velike platforme i trgovine poveznicama. */
	public static function blocked( $d ) {
		if ( '' === $d || $d === self::own() || in_array( $d, self::competitors(), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(^|\.)(facebook|instagram|youtube|tiktok|google|wikipedia|linkedin|twitter|x|pinterest|reddit|amazon|ebay|whatsapp|viber)\.[a-z.]+$|backlink|seoexpress|link-baron|outrank|rank-forge|guestpost/', $d );
	}

	public static function last_run() {
		$r = get_option( self::RUN_OPT );
		return is_array( $r ) ? $r : array();
	}

	/** Svi kandidati, najnoviji prvi. */
	public static function all() {
		$out = array();
		foreach ( OLR_Store::list_docs( self::COLL, '', 'asc', 2000 ) as $d ) {
			if ( is_array( $d['data'] ) && ! empty( $d['data']['domena'] ) ) {
				$out[] = $d['data'];
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['izradjeno'] <=> (int) $a['izradjeno'];
			}
		);
		return $out;
	}

	/** Novi kandidat (ako domena već postoji, vraća postojećeg). */
	public static function add( $c, $source ) {
		$d = self::host( isset( $c['domena'] ) ? $c['domena'] : '' );
		if ( '' === $d || self::blocked( $d ) ) {
			return new WP_Error( 'olr_suradnje_domena', 'Domena nije valjana ili nije kandidat (naša, konkurentska ili velika platforma).' );
		}
		$id  = self::id( $d );
		$old = OLR_Store::data( self::COLL, $id );
		if ( $old ) {
			return $old;
		}
		$doc = array(
			'id'         => $id,
			'domena'     => $d,
			'naziv'      => self::cut( ! empty( $c['naziv'] ) ? $c['naziv'] : $d, 120 ),
			'vrsta'      => isset( $c['vrsta'], self::VRSTE[ $c['vrsta'] ] ) ? $c['vrsta'] : 'ostalo',
			'izvor'      => $source,
			'dr'         => isset( $c['dr'] ) && is_numeric( $c['dr'] ) ? (float) $c['dr'] : null,
			'konkurenti' => isset( $c['konkurenti'] ) && is_array( $c['konkurenti'] ) ? $c['konkurenti'] : array(),
			'kontakt'    => null,
			'prijedlog'  => null,
			'ai'         => false,
			'veza'       => null,
			'izradjeno'  => time() * 1000,
		);
		OLR_Store::set( self::COLL, $id, $doc );
		return $doc;
	}

	/** Početni kandidati iz snimka Ahrefsa priloženog dodatku; uvozi se jednom po snimku. */
	public static function seed() {
		$file = OLR_DIR . 'data/suradnje-pocetno.json';
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$j = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $j ) || empty( $j['izvor'] ) || get_option( self::SEED_OPT ) === $j['izvor'] ) {
			return 0;
		}
		$n = 0;
		foreach ( isset( $j['kandidati'] ) ? (array) $j['kandidati'] : array() as $c ) {
			$had = OLR_Store::data( self::COLL, self::id( self::host( isset( $c['domena'] ) ? $c['domena'] : '' ) ) );
			$r   = self::add( $c, $j['izvor'] );
			$n  += ! is_wp_error( $r ) && ! $had ? 1 : 0;
		}
		// Polazni DR iz istog snimka, da povijest ima prvu točku i prije prvog tjednog osvježavanja.
		if ( ! empty( $j['dr'] ) && is_array( $j['dr'] ) && ! OLR_Store::data( self::DR_COLL, 'hist' ) ) {
			OLR_Store::set(
				self::DR_COLL,
				'hist',
				array(
					'datumi' => array( substr( $j['izvor'], -10 ) ),
					'po'     => array_map(
						static function ( $v ) {
							return array( (float) $v );
						},
						$j['dr']
					),
					'at'     => time() * 1000,
				)
			);
		}
		update_option( self::SEED_OPT, $j['izvor'], false );
		return $n;
	}

	/* ---------- Domain Rating (Ahrefsov besplatni javni API) ---------- */

	/** @return float|WP_Error */
	public static function dr_fetch( $domain ) {
		$pref = OLR_Net::preferred();
		$args = array(
			'method'      => 'GET',
			'timeout'     => 12,
			'httpversion' => '1.1',
			'headers'     => array( 'Accept' => 'application/json' ),
		);
		$url  = self::DR_API . '?output=json&target=' . rawurlencode( $domain );
		$res  = OLR_Net::post( $url, $args, $pref['nacin'] );
		if ( is_wp_error( $res ) && 'v4h1' !== $pref['nacin'] ) {
			$res = OLR_Net::post( $url, $args, 'v4h1' );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'olr_dr_veza', 'Ahrefs: veza nije uspjela (' . $res->get_error_message() . ')' );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$j    = json_decode( wp_remote_retrieve_body( $res ), true );
		$dr   = null;
		if ( isset( $j['domain_rating']['domain_rating'] ) && is_numeric( $j['domain_rating']['domain_rating'] ) ) {
			$dr = (float) $j['domain_rating']['domain_rating'];
		} elseif ( isset( $j['domain_rating']['targets'][0]['domain_rating'] ) && is_numeric( $j['domain_rating']['targets'][0]['domain_rating'] ) ) {
			$dr = (float) $j['domain_rating']['targets'][0]['domain_rating'];
		}
		if ( 200 !== $code || null === $dr ) {
			$msg = is_array( $j ) && isset( $j['error'] ) ? ( is_string( $j['error'] ) ? $j['error'] : wp_json_encode( $j['error'] ) ) : 'HTTP ' . $code;
			return new WP_Error( 'olr_dr_api', 'Ahrefs nije vratio DR za ' . $domain . ': ' . self::cut( $msg, 160 ) . ( in_array( $code, array( 401, 403 ), true ) ? ' Besplatni pristup možda više ne radi bez ključa.' : '' ) );
		}
		return $dr;
	}

	/** DR za nas i konkurente u povijest (jedna točka po danu) te za kandidate koji ga još nemaju. */
	public static function dr_refresh( $budget = 25 ) {
		$t0   = time();
		$hist = OLR_Store::data( self::DR_COLL, 'hist', array() );
		$hist = array(
			'datumi' => isset( $hist['datumi'] ) ? (array) $hist['datumi'] : array(),
			'po'     => isset( $hist['po'] ) ? (array) $hist['po'] : array(),
		);
		$day  = gmdate( 'Y-m-d' );
		$i    = array_search( $day, $hist['datumi'], true );
		if ( false === $i ) {
			$hist['datumi'][] = $day;
			$i                = count( $hist['datumi'] ) - 1;
		}
		$n   = 0;
		$err = null;
		foreach ( array_merge( array( self::own() ), self::competitors() ) as $d ) {
			if ( '' === $d || time() - $t0 > $budget ) {
				continue;
			}
			$v = self::dr_fetch( $d );
			if ( is_wp_error( $v ) ) {
				$err = $v;
				if ( 'olr_dr_veza' === $v->get_error_code() ) {
					break;
				}
				continue;
			}
			$row       = isset( $hist['po'][ $d ] ) ? (array) $hist['po'][ $d ] : array();
			$row       = array_pad( $row, $i, null );
			$row[ $i ] = $v;
			$hist['po'][ $d ] = $row;
			++$n;
		}
		if ( ! $n ) {
			$err = $err ? $err : new WP_Error( 'olr_dr_prazno', 'Nema domena za provjeru DR-a.' );
			OLR_Settings::set_status( 'suradnje_dr', false, $err->get_error_message() );
			return $err;
		}
		// Najviše 60 točaka; redovi svih domena iste duljine.
		$len            = count( $hist['datumi'] );
		$keep           = array_slice( array_keys( $hist['datumi'] ), -60 );
		$hist['datumi'] = array_values( array_intersect_key( $hist['datumi'], array_flip( $keep ) ) );
		foreach ( $hist['po'] as $d => $row ) {
			$row              = array_pad( array_values( (array) $row ), $len, null );
			$hist['po'][ $d ] = array_values( array_intersect_key( $row, array_flip( $keep ) ) );
		}
		$hist['at'] = time() * 1000;
		OLR_Store::set( self::DR_COLL, 'hist', $hist );
		$m = 0;
		foreach ( self::all() as $c ) {
			if ( $m >= 15 || time() - $t0 > $budget ) {
				break;
			}
			if ( null === $c['dr'] ) {
				$v = self::dr_fetch( $c['domena'] );
				if ( ! is_wp_error( $v ) ) {
					$c['dr'] = $v;
					OLR_Store::set( self::COLL, $c['id'], $c );
					++$m;
				}
			}
		}
		$run          = self::last_run();
		$run['dr_at'] = time();
		update_option( self::RUN_OPT, $run, false );
		$own = isset( $hist['po'][ self::own() ] ) ? end( $hist['po'][ self::own() ] ) : null;
		OLR_Settings::set_status( 'suradnje_dr', true, 'naš DR ' . ( null === $own ? '?' : str_replace( '.', ',', (string) $own ) ) . ' · provjereno domena: ' . $n . ( $m ? ', kandidata: ' . $m : '' ) );
		return $hist;
	}

	/* ---------- javni kontakt s weba kandidata ---------- */

	private static function fetch_page( $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 7,
				'redirection'         => 3,
				'limit_response_size' => 400000,
				'user-agent'          => 'OL-Radar/' . OLR_VERSION . ' (+' . home_url( '/' ) . ')',
				'olr_site'            => 1,
			)
		);
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 400 ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/** E-pošta (samo s domene kandidata ili iz mailto poveznice) i telefoni (tel: poveznice) iz HTML-a. */
	private static function contacts( $html, $domain ) {
		$mail = array();
		$tel  = array();
		if ( preg_match_all( '/mailto:([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $html, $m ) ) {
			$mail = $m[1];
		}
		if ( preg_match_all( '/[A-Za-z0-9._%+-]+@' . preg_quote( $domain, '/' ) . '/i', $html, $m ) ) {
			$mail = array_merge( $mail, $m[0] );
		}
		if ( preg_match_all( '/href=["\']tel:([+0-9 ()\/-]{6,24})["\']/', $html, $m ) ) {
			$tel = array_map( 'trim', $m[1] );
		}
		return array(
			array_slice( array_values( array_unique( array_map( 'strtolower', $mail ) ) ), 0, 3 ),
			array_slice( array_values( array_unique( $tel ) ), 0, 2 ),
		);
	}

	/**
	 * Provjeri da web kandidata postoji i pročitaj javni kontakt (naslovnica i stranica „Kontakt”).
	 *
	 * @return array Kandidat s poljima ziv i kontakt.
	 */
	public static function enrich( $doc ) {
		$base = 'https://' . $doc['domena'] . '/';
		$html = self::fetch_page( $base );
		if ( null === $html ) {
			$base = 'https://www.' . $doc['domena'] . '/';
			$html = self::fetch_page( $base );
		}
		$doc['ziv'] = null !== $html;
		if ( null === $html ) {
			$doc['kontakt'] = array(
				'stranica'   => '',
				'email'      => array(),
				'telefon'    => array(),
				'provjereno' => time() * 1000,
			);
			return $doc;
		}
		$page = '';
		if ( preg_match( '/<a[^>]+href=["\']([^"\'#]*(?:kontakt|contact|impressum|impresum|redakcija|o-nama)[^"\']*)["\']/i', $html, $m ) ) {
			$href = trim( html_entity_decode( $m[1] ) );
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			}
			$page = preg_match( '#^https?://#i', $href ) ? $href : rtrim( $base, '/' ) . '/' . ltrim( $href, '/' );
			// Samo stranice na istoj domeni.
			if ( self::host( $page ) !== $doc['domena'] ) {
				$page = '';
			}
		}
		list( $mail, $tel ) = self::contacts( $html, $doc['domena'] );
		if ( '' !== $page ) {
			$h2 = self::fetch_page( $page );
			if ( null !== $h2 ) {
				list( $m2, $t2 ) = self::contacts( $h2, $doc['domena'] );
				$mail            = array_slice( array_values( array_unique( array_merge( $m2, $mail ) ) ), 0, 3 );
				$tel             = array_slice( array_values( array_unique( array_merge( $t2, $tel ) ) ), 0, 2 );
			}
		}
		$doc['kontakt'] = array(
			'stranica'   => '' !== $page ? $page : $base,
			'email'      => $mail,
			'telefon'    => $tel,
			'provjereno' => time() * 1000,
		);
		return $doc;
	}

	/* ---------- stanje i provjera dobivene poveznice ---------- */

	public static function states() {
		$out = array();
		foreach ( OLR_Store::list_docs( self::STATE, '', 'asc', 2000 ) as $d ) {
			if ( is_array( $d['data'] ) ) {
				$out[ $d['id'] ] = $d['data'];
			}
		}
		return $out;
	}

	/**
	 * Postoji li na zadanoj adresi poveznica na naš web i je li nofollow.
	 *
	 * @return array [ ima, nofollow, url, provjereno, poruka ]
	 */
	public static function check_link( $url ) {
		$out = array(
			'ima'        => false,
			'nofollow'   => false,
			'url'        => esc_url_raw( $url ),
			'provjereno' => time() * 1000,
			'poruka'     => '',
		);
		if ( '' === self::host( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			$out['poruka'] = 'Adresa nije valjana.';
			return $out;
		}
		$html = self::fetch_page( $url );
		if ( null === $html ) {
			$out['poruka'] = 'Stranica se ne može otvoriti.';
			return $out;
		}
		if ( preg_match_all( '/<a\b[^>]*href=["\']https?:\/\/(?:www\.)?' . preg_quote( self::own(), '/' ) . '[^"\']*["\'][^>]*>/i', $html, $m ) ) {
			$out['ima'] = true;
			$nf         = 0;
			foreach ( $m[0] as $tag ) {
				$nf += preg_match( '/rel=["\'][^"\']*(nofollow|sponsored|ugc)/i', $tag ) ? 1 : 0;
			}
			$out['nofollow'] = $nf === count( $m[0] );
			$out['poruka']   = $out['nofollow'] ? 'Poveznica postoji, ali je označena kao nofollow ili sponsored (ne prenosi autoritet).' : 'Poveznica postoji i prenosi autoritet.';
		} else {
			$out['poruka'] = 'Na toj stranici nema poveznice na ' . self::own() . '.';
		}
		return $out;
	}

	/** Provjeri adrese koje je vlasnik upisao uz „dobiveno” (nove odmah, nepotvrđene sutradan, potvrđene jednom mjesečno). */
	public static function verify_links( $max = 5 ) {
		$n = 0;
		foreach ( self::states() as $id => $s ) {
			if ( $n >= $max ) {
				break;
			}
			$doc = OLR_Store::data( self::COLL, $id );
			if ( ! $doc || empty( $s['url'] ) ) {
				continue;
			}
			$old = isset( $doc['veza'] ) && is_array( $doc['veza'] ) ? $doc['veza'] : null;
			$wait = $old && ! empty( $old['ima'] ) ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS;
			if ( $old && $old['url'] === esc_url_raw( $s['url'] ) && time() - (int) ( $old['provjereno'] / 1000 ) < $wait ) {
				continue;
			}
			$doc['veza'] = self::check_link( $s['url'] );
			OLR_Store::set( self::COLL, $id, $doc );
			++$n;
		}
		return $n;
	}

	/* ---------- poveznice koje vidi Google (izvoz iz Search Consolea) ---------- */

	/** Domene koje po nazivu izgledaju kao prodavači poveznica; gruba oznaka, ne presuda. */
	public static function link_shop( $d ) {
		return (bool) preg_match( '/backlink|link-?baron|seo-?express|seo-?link|linkbuild|guest-?post|outrank|rank-?forge|dofollow|buy-?link|pbn/', $d );
	}

	/**
	 * Uvoz izvoza iz Search Consolea (Veze → Vanjske veze → Izvoz). Search Console API nema izvještaj o
	 * vezama, pa je ručni izvoz jedini put do onoga što Google stvarno vidi. Prihvaća oba CSV oblika:
	 * „Najčešće web-lokacije s vezama” (domena, stranice s vezama, ciljne stranice) i „Najnovije veze”
	 * (adresa stranice, zadnje indeksiranje).
	 *
	 * @param string $csv Sadržaj CSV datoteke.
	 * @return array|WP_Error Sažetak uvoza.
	 */
	public static function import_gsc_links( $csv ) {
		$csv = (string) $csv;
		if ( strlen( $csv ) > 3000000 ) {
			return new WP_Error( 'olr_gsc_veze', 'Datoteka je prevelika (više od 3 MB).' );
		}
		$csv  = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $csv ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$sep    = substr_count( $line, "\t" ) ? "\t" : ( substr_count( $line, ';' ) > substr_count( $line, ',' ) ? ';' : ',' );
			$rows[] = array_map( 'trim', str_getcsv( $line, $sep, '"', '\\' ) );
		}
		$by   = array();
		$kind = '';
		foreach ( $rows as $r ) {
			$first = isset( $r[0] ) ? $r[0] : '';
			$d     = self::host( $first );
			if ( '' === $d || $d === self::own() ) {
				continue; // zaglavlje, prazan redak ili naša domena
			}
			$num = static function ( $v ) {
				return (int) preg_replace( '/\D/', '', (string) $v );
			};
			if ( preg_match( '#^https?://#i', $first ) ) {
				// „Najnovije veze”: jedan redak je jedna stranica s vezom.
				$kind = 'stranice';
				if ( ! isset( $by[ $d ] ) ) {
					$by[ $d ] = array( 'd' => $d, 'stranica' => 0, 'ciljnih' => null, 'primjer' => esc_url_raw( $first ), 'zadnje' => '' );
				}
				++$by[ $d ]['stranica'];
				if ( isset( $r[1] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $r[1], $m ) && $m[0] > $by[ $d ]['zadnje'] ) {
					$by[ $d ]['zadnje'] = $m[0];
				}
			} elseif ( isset( $r[1] ) && $num( $r[1] ) > 0 ) {
				// „Najčešće web-lokacije s vezama”.
				$kind     = '' === $kind ? 'domene' : $kind;
				$by[ $d ] = array( 'd' => $d, 'stranica' => $num( $r[1] ), 'ciljnih' => isset( $r[2] ) ? $num( $r[2] ) : null, 'primjer' => '', 'zadnje' => '' );
			}
		}
		if ( ! $by ) {
			return new WP_Error( 'olr_gsc_veze', 'Datoteka ne izgleda kao izvoz veza iz Search Consolea (Veze → Vanjske veze → Izvoz → CSV). Očekujem tablicu „Najčešće web-lokacije s vezama” ili „Najnovije veze”.' );
		}
		$list = array_values( $by );
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['stranica'] <=> $a['stranica'];
			}
		);
		$shops = 0;
		foreach ( $list as &$x ) {
			$x['prodavac'] = self::link_shop( $x['d'] );
			$shops        += $x['prodavac'] ? 1 : 0;
		}
		unset( $x );
		$old  = OLR_Store::data( self::DR_COLL, 'gsc', array() );
		$hist = isset( $old['povijest'] ) && is_array( $old['povijest'] ) ? $old['povijest'] : array();
		$day  = gmdate( 'Y-m-d' );
		$hist = array_values(
			array_filter(
				$hist,
				static function ( $h ) use ( $day ) {
					return isset( $h['datum'] ) && $h['datum'] !== $day;
				}
			)
		);
		// Novo od prošlog uvoza: domene kojih prije nije bilo.
		$before = isset( $old['domene'] ) && is_array( $old['domene'] ) ? wp_list_pluck( $old['domene'], 'd' ) : array();
		$fresh  = $before ? array_values( array_diff( wp_list_pluck( $list, 'd' ), $before ) ) : array();
		$hist[] = array(
			'datum'    => $day,
			'domena'   => count( $list ),
			'ostalih'  => count( $list ) - $shops,
			'stranica' => array_sum( wp_list_pluck( $list, 'stranica' ) ),
		);
		$doc = array(
			'at'       => time() * 1000,
			'vrsta'    => $kind,
			'domene'   => array_slice( $list, 0, 1000 ),
			'nove'     => array_slice( $fresh, 0, 100 ),
			'povijest' => array_slice( $hist, -60 ),
		);
		OLR_Store::set( self::DR_COLL, 'gsc', $doc );
		OLR_Settings::set_status( 'suradnje_gsc', true, 'domena s vezom prema nama: ' . count( $list ) . ' (po nazivu prodavači poveznica: ' . $shops . ')' . ( $fresh ? ', novih od prošlog uvoza: ' . count( $fresh ) : '' ) );
		return array(
			'domena'   => count( $list ),
			'prodavaca' => $shops,
			'novih'    => count( $fresh ),
			'vrsta'    => $kind,
		);
	}

	/** Ručno dodan kandidat: provjera weba i javnog kontakta ide odmah. */
	public static function add_manual( $domain, $name = '', $type = 'ostalo' ) {
		$d = self::host( $domain );
		if ( '' !== $d && OLR_Store::data( self::COLL, self::id( $d ) ) ) {
			return new WP_Error( 'olr_suradnje_postoji', 'Ta domena je već na popisu kandidata.' );
		}
		$c = self::add(
			array(
				'domena' => $d,
				'naziv'  => '' !== trim( (string) $name ) ? $name : $d,
				'vrsta'  => $type,
			),
			'rucno'
		);
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$c = self::enrich( $c );
		OLR_Store::set( self::COLL, $c['id'], $c );
		if ( empty( $c['ziv'] ) ) {
			return new WP_Error( 'olr_suradnje_web', 'Kandidat je dodan, ali mu se web trenutno ne može otvoriti; prijedlog će se napisati kad web proradi.' );
		}
		return $c;
	}

	/** Ponovna provjera poveznice za jednog kandidata (adresa je u stanju koje vodi ploča). */
	public static function recheck( $id ) {
		$doc = OLR_Store::data( self::COLL, (string) $id );
		$st  = OLR_Store::data( self::STATE, (string) $id );
		if ( ! $doc || empty( $st['url'] ) ) {
			return new WP_Error( 'olr_suradnje_veza', 'Za ovog kandidata nije upisana adresa stranice s poveznicom.' );
		}
		$doc['veza'] = self::check_link( $st['url'] );
		OLR_Store::set( self::COLL, $doc['id'], $doc );
		return $doc['veza'];
	}

	/* ---------- Claude: prijedlozi i traženje novih tvrtki ---------- */

	private static function rules() {
		return "Pravila:\n"
			. "- Piši hrvatski, mirno i konkretno, bez marketinških fraza, uskličnika i emojija.\n"
			. "- Nikad ne predlaži kupnju poveznica, razmjenu poveznica ni mreže stranica. Plaćeni članak smije se spomenuti samo uz napomenu da takva poveznica mora biti označena kao sponzorirana i ne diže DR.\n"
			. "- Ne izmišljaj imena osoba, cijene, popuste, brojke ni činjenice o drugoj strani. U polje kome upiši ulogu (npr. urednik rubrike Zdravlje, voditelj kluba), ne ime.\n"
			. "- zasto: jedna do dvije rečenice; kad u ulazu postoji brojka (koliko poveznica konkurent ima s te domene, DR), navedi je.\n"
			. "- ponuda: što točno nudimo drugoj strani (tema i stručni komentar optometrista, edukacija, provjera vida za članove ili zaposlenike, zajednička akcija). Mora biti korisno i njima, a izvedivo za malu obiteljsku optiku.\n"
			. "- koraci: najviše 3, svaki do 20 riječi.\n"
			. "- poruka: nacrt prve poruke, 60 do 90 riječi, na „Vi”, s jednim jasnim prijedlogom i jednim pitanjem na kraju. Potpis: Ivan Mesarov, optometrist, Optika Ljiljana.\n"
			. '- Odgovori samo pozivom alata ' . self::TOOL . ', točno jednom, sa svim prijedlozima.';
	}

	private static function system_prompt( $search ) {
		$base = "[zadatak: suradnje i poveznice]\n"
			. "Radiš za Optiku Ljiljana (obiteljska optika u Sesvetama kod Zagreba, od 1993.; optometrist, provjera vida, dioptrijske i progresivne naočale, kontrola kratkovidnosti kod djece). Tržište su Grad Zagreb, osobito istok grada i Sesvete, te Zagrebačka županija.\n"
			. "Cilj: prave poveznice i spominjanja s tuđih webova (to diže Domain Rating) kroz suradnje koje imaju smisla i bez poveznice.\n\n";
		if ( $search ) {
			return $base
				. "Web pretragom pronađi nove kandidate za suradnju, onoliko koliko piše u trazi_novih: stvarne tvrtke, ustanove, udruge, klubove ili lokalne portale s vlastitim webom, kojima bi suradnja s lokalnom optikom imala smisla. Primjeri: poliklinike i pedijatrijske ordinacije bez vlastite optike, medicina rada, škole i vrtići, sportski klubovi, autoškole, veći poslodavci s uredskim radom, udruge umirovljenika, lokalni portali, dobavljači čije proizvode optika prodaje. Ako je u ulazu fokus, drži ga se.\n"
				. "Ne predlaži domene s popisa vec_poznato, druge optike, društvene mreže ni imenike bez uredništva. Ne izmišljaj: svaki kandidat mora biti iz rezultata pretrage, s domenom koju si vidio. Za svakog napiši prijedlog.\n\n"
				. self::rules();
		}
		return $base
			. "U ulazu su kandidati (domena, vrsta, DR, koliko poveznica imaju konkurenti s te domene, javni kontakt ako je pronađen). Za svakog napiši prijedlog. Ne dodaji domene kojih nema u ulazu.\n\n"
			. self::rules();
	}

	private static function tool() {
		$s = array( 'type' => 'string' );
		return array(
			'name'         => self::TOOL,
			'description'  => 'Sprema prijedloge suradnje.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'prijedlozi' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'domena' => $s,
								'naziv'  => $s,
								'vrsta'  => array(
									'type' => 'string',
									'enum' => array_keys( self::VRSTE ),
								),
								'zasto'  => $s,
								'ponuda' => $s,
								'kome'   => $s,
								'koraci' => array(
									'type'  => 'array',
									'items' => $s,
								),
								'poruka' => $s,
							),
							'required'   => array( 'domena', 'zasto', 'ponuda', 'poruka' ),
						),
					),
				),
				'required'   => array( 'prijedlozi' ),
			),
		);
	}

	/** Kandidati bez prijedloga, po važnosti: koliko konkurenata ima poveznicu, zatim DR. */
	private static function queue( $n ) {
		$st   = self::states();
		$list = array_values(
			array_filter(
				self::all(),
				static function ( $c ) use ( $st ) {
					return empty( $c['ai'] ) && ( ! isset( $st[ $c['id'] ]['stanje'] ) || 'odbijeno' !== $st[ $c['id'] ]['stanje'] ) && ( ! isset( $c['ziv'] ) || false !== $c['ziv'] );
				}
			)
		);
		usort(
			$list,
			static function ( $a, $b ) {
				$x = count( $b['konkurenti'] ) <=> count( $a['konkurenti'] );
				return $x ? $x : ( (float) $b['dr'] <=> (float) $a['dr'] );
			}
		);
		return array_slice( $list, 0, $n );
	}

	private static function clean_proposal( $p ) {
		$steps = array();
		foreach ( isset( $p['koraci'] ) && is_array( $p['koraci'] ) ? array_slice( $p['koraci'], 0, 3 ) : array() as $k ) {
			if ( is_string( $k ) && '' !== trim( $k ) ) {
				$steps[] = self::cut( $k, 300 );
			}
		}
		return array(
			'zasto'  => self::cut( isset( $p['zasto'] ) ? $p['zasto'] : '', 500 ),
			'ponuda' => self::cut( isset( $p['ponuda'] ) ? $p['ponuda'] : '', 600 ),
			'kome'   => self::cut( isset( $p['kome'] ) ? $p['kome'] : '', 120 ),
			'koraci' => $steps,
			'poruka' => self::cut_lines( isset( $p['poruka'] ) ? $p['poruka'] : '', 1500 ),
			'at'     => time() * 1000,
		);
	}

	/** Prijedlozi iz Claudeova odgovora (poziv alata) ili null. */
	private static function items( $res ) {
		$items = null;
		foreach ( is_array( $res['content'] ) ? $res['content'] : array() as $b ) {
			if ( isset( $b['type'], $b['name'] ) && 'tool_use' === $b['type'] && self::TOOL === $b['name'] && isset( $b['input']['prijedlozi'] ) && is_array( $b['input']['prijedlozi'] ) ) {
				$items = $b['input']['prijedlozi'];
			}
		}
		return $items;
	}

	/** Dnevno ograničenje ručnih pokretanja (po vrsti posla) i bilješka pokušaja. */
	private static function begin( $job, $how ) {
		$run   = self::last_run();
		$today = gmdate( 'Y-m-d' );
		if ( 'rucno' === $how ) {
			$n = isset( $run['rucno'][ $job ][ $today ] ) ? (int) $run['rucno'][ $job ][ $today ] : 0;
			if ( $n >= self::MANUAL ) {
				return new WP_Error( 'olr_suradnje_limit', 'Danas je ovo već pokrenuto ' . self::MANUAL . ' puta. Sljedeći prolaz ide sam (jednom tjedno).' );
			}
			if ( ! isset( $run['rucno'] ) || ! is_array( $run['rucno'] ) ) {
				$run['rucno'] = array();
			}
			$run['rucno'][ $job ] = array( $today => $n + 1 );
		}
		$run[ $job . '_pokusaj' ] = time();
		update_option( self::RUN_OPT, $run, false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}

	private static function finish( $job, $how, $result, $status ) {
		$run                  = self::last_run();
		$run[ $job . '_at' ]  = time();
		$run[ $job ]          = array_merge( $result, array( 'nacin' => $how ) );
		update_option( self::RUN_OPT, $run, false );
		OLR_Settings::set_status( 'trazi' === $job ? 'suradnje_trazi' : 'suradnje', true, $status );
		return $result;
	}

	private static function fail( $job, $err ) {
		OLR_Settings::set_status( 'trazi' === $job ? 'suradnje_trazi' : 'suradnje', false, $err->get_error_message() );
		return $err;
	}

	/**
	 * Prijedlozi za kandidate koji ih još nemaju (najviše suradnje_max po prolazu). Prije poziva server
	 * provjeri da web kandidata postoji i pročita javni kontakt.
	 *
	 * @param string $how auto ili rucno.
	 * @return array|WP_Error [ napisano, odbaceno, preostalo ]
	 */
	public static function write( $how = 'rucno' ) {
		self::seed();
		if ( ! OLR_AI::available() ) {
			return new WP_Error( 'olr_ai_nema', 'Anthropic API ključ nije postavljen (OL Radar → Postavke).' );
		}
		$ok = self::begin( 'pisi', $how );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$max   = max( 1, min( 8, (int) OLR_Settings::get( 'suradnje_max' ) ) );
		$queue = array();
		$t0    = time();
		foreach ( self::queue( $max + 4 ) as $c ) {
			if ( count( $queue ) >= $max ) {
				break;
			}
			if ( ! isset( $c['ziv'] ) && time() - $t0 < 40 ) {
				$c = self::enrich( $c );
				OLR_Store::set( self::COLL, $c['id'], $c );
			}
			if ( ! isset( $c['ziv'] ) || $c['ziv'] ) {
				$queue[] = $c;
			}
		}
		if ( ! $queue ) {
			return self::finish( 'pisi', $how, array( 'napisano' => 0, 'odbaceno' => 0, 'preostalo' => 0 ), 'svi kandidati već imaju prijedlog' );
		}
		$input = array(
			'kandidati' => array_map(
				static function ( $c ) {
					return array(
						'domena'                => $c['domena'],
						'naziv'                 => $c['naziv'],
						'vrsta'                 => $c['vrsta'],
						'dr'                    => $c['dr'],
						'poveznice_konkurenata' => $c['konkurenti'],
						'kontakt_stranica'      => ! empty( $c['kontakt']['stranica'] ) ? $c['kontakt']['stranica'] : null,
					);
				},
				$queue
			),
			'nas_dr'    => self::own_dr(),
			'fokus'     => self::cut( (string) OLR_Settings::get( 'suradnje_fokus' ), 400 ),
		);
		$req   = array(
			'system'      => self::system_prompt( false ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			'tools'       => array( self::tool() ),
			'tool_choice' => array( 'name' => self::TOOL ),
			'max_tokens'  => 6000,
			'long'        => true,
		);
		$res   = OLR_AI::call( $req, true );
		if ( is_wp_error( $res ) ) {
			return self::fail( 'pisi', $res );
		}
		$items = self::items( $res );
		if ( ! $items ) {
			return self::fail( 'pisi', new WP_Error( 'olr_suradnje_oblik', 'max_tokens' === $res['stop_reason'] ? 'Claudeov odgovor je prekinut zbog duljine. Smanji broj prijedloga po prolazu u Postavkama.' : 'Claude nije vratio prijedloge u očekivanom obliku. Pokušaj ponovno.' ) );
		}
		$by = array();
		foreach ( $queue as $c ) {
			$by[ $c['domena'] ] = $c;
		}
		$written = 0;
		$drop    = 0;
		foreach ( $items as $p ) {
			$d    = self::host( is_array( $p ) && isset( $p['domena'] ) ? $p['domena'] : '' );
			$prop = is_array( $p ) ? self::clean_proposal( $p ) : null;
			if ( ! isset( $by[ $d ] ) || ! $prop || '' === $prop['zasto'] || '' === $prop['poruka'] ) {
				++$drop;
				continue;
			}
			$c              = $by[ $d ];
			$c['prijedlog'] = $prop;
			$c['ai']        = true;
			$c['model']     = $res['model'];
			OLR_Store::set( self::COLL, $c['id'], $c );
			unset( $by[ $d ] );
			++$written;
		}
		if ( ! $written ) {
			return self::fail( 'pisi', new WP_Error( 'olr_suradnje_oblik', 'Claude nije vratio nijedan potpun prijedlog. Pokušaj ponovno.' ) );
		}
		$left = count( self::queue( 500 ) );
		return self::finish(
			'pisi',
			$how,
			array(
				'napisano'  => $written,
				'odbaceno'  => $drop,
				'preostalo' => $left,
			),
			'napisano prijedloga: ' . $written . ( $left ? ', kandidata koji čekaju: ' . $left : '' )
		);
	}

	/**
	 * Claude web pretragom traži nove tvrtke i ustanove za suradnju i odmah piše prijedlog za svaku.
	 * Kandidat ulazi samo ako mu web stvarno postoji; kontakt čita server, ne Claude.
	 * Web pretraga se naplaćuje po upitu: ručno ide na klik uz potvrdu u ploči, a tjedno samo ako je
	 * uključeno u Postavkama (suradnje_trazi).
	 *
	 * @param string $how auto ili rucno.
	 * @return array|WP_Error [ novih, odbaceno, pretraga ]
	 */
	public static function search( $how = 'rucno' ) {
		self::seed();
		if ( ! OLR_AI::available() ) {
			return new WP_Error( 'olr_ai_nema', 'Anthropic API ključ nije postavljen (OL Radar → Postavke).' );
		}
		$ok = self::begin( 'trazi', $how );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$want  = max( 1, min( 5, (int) OLR_Settings::get( 'suradnje_novih' ) ) );
		$known = wp_list_pluck( self::all(), 'domena' );
		$input = array(
			'trazi_novih' => $want,
			'vec_poznato' => array_values( array_unique( array_merge( $known, self::competitors(), array( self::own() ) ) ) ),
			'fokus'       => self::cut( (string) OLR_Settings::get( 'suradnje_fokus' ), 400 ),
			'vrste'       => array_keys( self::VRSTE ),
		);
		$req   = array(
			'system'     => self::system_prompt( true ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			'tools'      => array( self::tool() ),
			'max_tokens' => 6000,
			'web_search' => min( 6, $want + 2 ),
		);
		$res   = OLR_AI::call( $req, true );
		// Dugi prolaz s pretragom API može prekinuti (pause_turn): nastavlja se jednom, s dosadašnjim sadržajem.
		if ( ! is_wp_error( $res ) && 'pause_turn' === $res['stop_reason'] ) {
			$req['messages'][] = array(
				'role'    => 'assistant',
				'content' => $res['content'],
			);
			$res               = OLR_AI::call( $req, true );
		}
		if ( is_wp_error( $res ) ) {
			if ( 'olr_ai_zahtjev' === $res->get_error_code() ) {
				$res = new WP_Error( 'olr_suradnje_pretraga', 'Web pretraga nije dostupna za ovaj API ključ ili model (' . self::cut( $res->get_error_message(), 160 ) . '). Uključuje se u Claude Consoleu (Settings → Privacy); prijedlozi za postojeće kandidate rade i bez nje.' );
			}
			return self::fail( 'trazi', $res );
		}
		$items = self::items( $res );
		if ( ! $items && 'max_tokens' !== $res['stop_reason'] ) {
			// Pretraga je gotova, ali alat nije pozvan: jedan nastavak s prisilnim pozivom alata.
			$req['messages'][]  = array(
				'role'    => 'assistant',
				'content' => $res['content'],
			);
			$req['messages'][]  = array(
				'role'    => 'user',
				'content' => 'Sada pozovi alat ' . self::TOOL . ' s kandidatima koje si pronašao.',
			);
			$req['tool_choice'] = array( 'name' => self::TOOL );
			$res2               = OLR_AI::call( $req, true );
			$items              = is_wp_error( $res2 ) ? null : self::items( $res2 );
		}
		if ( ! $items ) {
			return self::fail( 'trazi', new WP_Error( 'olr_suradnje_oblik', 'max_tokens' === $res['stop_reason'] ? 'Claudeov odgovor je prekinut zbog duljine. Smanji broj novih tvrtki po prolazu u Postavkama.' : 'Claude nije vratio kandidate u očekivanom obliku. Pokušaj ponovno.' ) );
		}
		$new  = 0;
		$drop = 0;
		$t0   = time();
		foreach ( $items as $p ) {
			$d    = self::host( is_array( $p ) && isset( $p['domena'] ) ? $p['domena'] : '' );
			$prop = is_array( $p ) ? self::clean_proposal( $p ) : null;
			if ( $new >= $want || '' === $d || ! $prop || '' === $prop['zasto'] || '' === $prop['poruka'] || in_array( $d, $known, true ) || self::blocked( $d ) || time() - $t0 > 60 ) {
				++$drop;
				continue;
			}
			// Novi kandidat ulazi samo ako mu web stvarno postoji.
			$probe = self::enrich( array( 'domena' => $d ) );
			if ( empty( $probe['ziv'] ) ) {
				++$drop;
				continue;
			}
			$c = self::add(
				array(
					'domena' => $d,
					'naziv'  => isset( $p['naziv'] ) ? $p['naziv'] : $d,
					'vrsta'  => isset( $p['vrsta'] ) ? $p['vrsta'] : 'ostalo',
				),
				'claude-pretraga'
			);
			if ( is_wp_error( $c ) ) {
				++$drop;
				continue;
			}
			// DR novog kandidata samo ako je tjedna provjera DR-a uključena (inače se Ahrefs koristi samo kao arhiva).
			$dr             = OLR_Settings::get( 'suradnje_dr' ) ? self::dr_fetch( $d ) : null;
			$c['dr']        = null === $dr || is_wp_error( $dr ) ? null : $dr;
			$c['ziv']       = true;
			$c['kontakt']   = $probe['kontakt'];
			$c['prijedlog'] = $prop;
			$c['ai']        = true;
			$c['model']     = $res['model'];
			OLR_Store::set( self::COLL, $c['id'], $c );
			$known[] = $d;
			++$new;
		}
		$used = isset( $res['usage']['server_tool_use']['web_search_requests'] ) ? (int) $res['usage']['server_tool_use']['web_search_requests'] : 0;
		return self::finish(
			'trazi',
			$how,
			array(
				'novih'    => $new,
				'odbaceno' => $drop,
				'pretraga' => $used,
			),
			'novih kandidata s prijedlogom: ' . $new . ( $drop ? ', odbačeno: ' . $drop . ' (web ne postoji, već poznato ili nepotpuno)' : '' ) . ( $used ? ' · web pretraga: ' . $used : '' )
		);
	}

	public static function own_dr() {
		$h = OLR_Store::data( self::DR_COLL, 'hist' );
		$r = $h && isset( $h['po'][ self::own() ] ) ? array_values( array_filter( (array) $h['po'][ self::own() ], 'is_numeric' ) ) : array();
		return $r ? end( $r ) : null;
	}

	/**
	 * Satni prolaz: početni kandidati i provjera upisanih poveznica; jednom tjedno DR, prijedlozi za
	 * kandidate koji ih nemaju i traženje novih tvrtki. Svaki od ta tri posla ide u svom satu.
	 */
	public static function tick() {
		self::seed();
		self::verify_links( 3 );
		if ( ! OLR_Settings::get( 'suradnje_auto' ) ) {
			return;
		}
		$run  = self::last_run();
		$week = 7 * DAY_IN_SECONDS;
		$gap  = 20 * HOUR_IN_SECONDS;
		$at   = static function ( $k ) use ( $run ) {
			return isset( $run[ $k ] ) ? (int) $run[ $k ] : 0;
		};
		if ( OLR_Settings::get( 'suradnje_dr' ) && time() - $at( 'dr_at' ) > $week && time() - $at( 'dr_pokusaj' ) > $gap ) {
			$run['dr_pokusaj'] = time();
			update_option( self::RUN_OPT, $run, false );
			self::dr_refresh();
			return;
		}
		if ( ! OLR_AI::available() ) {
			return;
		}
		if ( time() - $at( 'pisi_at' ) > $week && time() - $at( 'pisi_pokusaj' ) > $gap ) {
			self::write( 'auto' );
			return;
		}
		if ( OLR_Settings::get( 'suradnje_trazi' ) && time() - $at( 'trazi_at' ) > $week && time() - $at( 'trazi_pokusaj' ) > $gap ) {
			self::search( 'auto' );
		}
	}

	/* ---------- sažetak za ploču i MCP ---------- */

	public static function summary( $limit = 200 ) {
		self::seed();
		$st    = self::states();
		$count = array_fill_keys( self::STANJA, 0 );
		$rows  = array();
		$ai    = 0;
		$gsc   = OLR_Store::data( self::DR_COLL, 'gsc' );
		$seen  = $gsc && ! empty( $gsc['domene'] ) ? array_flip( wp_list_pluck( $gsc['domene'], 'd' ) ) : array();
		foreach ( self::all() as $c ) {
			$c['u_gsc']   = isset( $seen[ $c['domena'] ] );
			$s            = isset( $st[ $c['id'] ]['stanje'] ) && in_array( $st[ $c['id'] ]['stanje'], self::STANJA, true ) ? $st[ $c['id'] ]['stanje'] : 'novo';
			$c['stanje']  = $s;
			$c['url']     = isset( $st[ $c['id'] ]['url'] ) ? (string) $st[ $c['id'] ]['url'] : '';
			$c['biljeska'] = isset( $st[ $c['id'] ]['biljeska'] ) ? (string) $st[ $c['id'] ]['biljeska'] : '';
			$ai          += empty( $c['ai'] ) ? 0 : 1;
			++$count[ $s ];
			$rows[] = $c;
		}
		// Prvo oni s prijedlogom, zatim po broju konkurenata s poveznicom i DR-u.
		usort(
			$rows,
			static function ( $a, $b ) {
				$x = array( (int) ! empty( $b['ai'] ) <=> (int) ! empty( $a['ai'] ), count( $b['konkurenti'] ) <=> count( $a['konkurenti'] ), (float) $b['dr'] <=> (float) $a['dr'] );
				return $x[0] ? $x[0] : ( $x[1] ? $x[1] : $x[2] );
			}
		);
		$hist = OLR_Store::data( self::DR_COLL, 'hist' );
		$dr   = array();
		$show = array_merge( array( self::own() ), self::competitors() );
		foreach ( $hist && isset( $hist['po'] ) ? $hist['po'] : array() as $d => $row ) {
			$vals = array_values( array_filter( (array) $row, 'is_numeric' ) );
			if ( $vals && in_array( $d, $show, true ) ) {
				$dr[] = array(
					'domena'   => $d,
					'nasa'     => $d === self::own(),
					'dr'       => end( $vals ),
					'prije'    => count( $vals ) > 1 ? $vals[ count( $vals ) - 2 ] : null,
					'povijest' => $vals,
				);
			}
		}
		usort(
			$dr,
			static function ( $a, $b ) {
				return (float) $b['dr'] <=> (float) $a['dr'];
			}
		);
		$status = OLR_Settings::status();
		return array(
			'kandidati' => array_slice( $rows, 0, $limit ),
			'ukupno'    => count( $rows ),
			's_prijedlogom' => $ai,
			'stanja'    => $count,
			'dr'        => array(
				'nas'      => self::own_dr(),
				'domene'   => $dr,
				'datumi'   => $hist && isset( $hist['datumi'] ) ? $hist['datumi'] : array(),
				'at'       => $hist && isset( $hist['at'] ) ? $hist['at'] : null,
				'izvor'    => 'Domain Rating by Ahrefs',
				'licenca'  => 'https://ahrefs.com/legal/domain-rating-license',
			),
			// Poveznice koje vidi Google (ručni izvoz iz Search Consolea): glavno mjerilo napretka.
			'gsc'       => $gsc ? array(
				'at'       => $gsc['at'],
				'vrsta'    => $gsc['vrsta'],
				'domena'   => count( $gsc['domene'] ),
				'ostalih'  => count( $gsc['domene'] ) - count( array_filter( wp_list_pluck( $gsc['domene'], 'prodavac' ) ) ),
				'domene'   => array_slice( $gsc['domene'], 0, 200 ),
				'nove'     => isset( $gsc['nove'] ) ? $gsc['nove'] : array(),
				'povijest' => isset( $gsc['povijest'] ) ? $gsc['povijest'] : array(),
			) : null,
			'dr_auto'   => (bool) OLR_Settings::get( 'suradnje_dr' ),
			'zadnje'    => array_diff_key( self::last_run(), array( 'rucno' => 1 ) ),
			'auto'      => (bool) OLR_Settings::get( 'suradnje_auto' ),
			'pretraga'  => (bool) OLR_Settings::get( 'suradnje_trazi' ),
			'claude'    => OLR_AI::available(),
			'novih_po_prolazu' => max( 1, min( 5, (int) OLR_Settings::get( 'suradnje_novih' ) ) ),
			'stanje'    => isset( $status['suradnje'] ) ? $status['suradnje'] : null,
			'stanje_trazi' => isset( $status['suradnje_trazi'] ) ? $status['suradnje_trazi'] : null,
			'vrste'     => self::VRSTE,
		);
	}
}
