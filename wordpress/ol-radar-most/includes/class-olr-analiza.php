<?php
/**
 * Dnevna analiza: iz spremljenih podataka (Google Search Console, pozicije, crawl weba) izdvaja
 * nalaze koji vode do konkretne izmjene na webu. Sve brojke ovdje dolaze izravno iz podataka;
 * ništa se ne procjenjuje „odoka” osim polja `ucinak`, koje je jasno označena gruba procjena.
 *
 * Analiza samo čita. Tekst prijedloga piše OLR_Prijedlozi (uz Claudea ili bez njega).
 */

defined( 'ABSPATH' ) || exit;

class OLR_Analiza {

	/** Opća krivulja udjela klikova po poziciji (koristi se dok nema dovoljno vlastitih podataka). */
	const CTR = array( 1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018 );

	const VRSTE = array(
		'ctr'      => 'Naslov i opis',
		'prilika'  => 'Blizu vrha',
		'kanibal'  => 'Kanibalizacija',
		'pad'      => 'Pad',
		'pozicije' => 'Pozicije',
		'tehnicki' => 'Tehnički',
		'novo'     => 'Novi sadržaj',
	);

	/** Kodovi audita iz kojih nastaje prijedlog (ostali su sitnice ili namjerne postavke). */
	const AUDIT_KODOVI = array( 'http_greska', 'pokvaren_link', 'bez_naslova', 'bez_opisa', 'bez_h1', 'dupli_naslov', 'dupli_opis', 'canonical_drugi', 'mijesani_sadrzaj', 'bez_ulaznih_linkova' );

	/** Stranica mijenjana u zadnjih toliko dana: brojke još opisuju stari naslov i opis. */
	const SVJEZE_DANA = 14;

	/**
	 * Verzija pravila analize. Povećava se kad se pravila pooštre: otvoreni prijedlozi napisani prema
	 * starijim pravilima, čiji nalaz nova pravila više ne potvrđuju, tad istječu odmah (inače čekaju 21 dan).
	 * 2 = 2.4.2 (glavna stranica po klikovima, testovi značajnosti, konkurentski i lokalni upiti).
	 */
	const PRAVILA = 2;

	/** Prag značajnosti za statističke provjere (pad klikova, nizak CTR): ispod 5 % slučajnosti. */
	const ZNACAJNO = 0.05;

	/**
	 * Lokalni upiti: iznad organskih rezultata Google prikazuje kartu s tvrtkama, pa organski rezultat
	 * dobiva manje klikova nego na općoj krivulji. Korijeni mjesta i riječi blizine; upit od same
	 * djelatnosti („optika”, „optičar”) također je lokalan.
	 */
	const LOKALNO     = array( 'sesvet', 'zagreb', 'dubrav', 'blizu', 'blizin', 'kraj mene', 'u mom kvartu' );
	const DJELATNOST  = array( 'optika', 'optike', 'opticar', 'opticari', 'opticarna', 'okulist', 'oftalmolog', 'optometrist' );
	const LOKALNO_CTR = 0.5;

	/** Riječi iz domena konkurenata koje nisu ime tvrtke (ne smiju isključiti obične upite). */
	const OPCE_RIJECI = array( 'optika', 'optike', 'optik', 'optic', 'optics', 'optical', 'opticar', 'naocale', 'naocal', 'lece', 'leca', 'kontaktne', 'vision', 'eyewear', 'shop', 'store', 'online', 'centar', 'center', 'poliklinika', 'klinika', 'ordinacija', 'studio', 'zagreb', 'sesvete', 'hrvatska', 'croatia', 'www' );

	private static $pages = null;
	private static $curve = null;
	private static $sigs  = array();
	private static $words = array();
	private static $vocab = null;
	private static $comp  = null;
	private static $notes = array();

	public static function reset() {
		self::$pages = null;
		self::$curve = null;
		self::$sigs  = array();
		self::$words = array();
		self::$vocab = null;
		self::$comp  = null;
		self::$notes = array();
		self::$fresh = array();
	}

	/* ---------- pomoćne ---------- */

	public static function path( $url ) {
		$p = wp_parse_url( (string) $url, PHP_URL_PATH );
		return ( is_string( $p ) && '' !== $p ) ? $p : '/';
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	/** Mala slova bez dijakritike: „naočale” i „naocale” su isto. */
	public static function norm( $s ) {
		return strtolower( remove_accents( OLR_DFS::lower( (string) $s ) ) );
	}

	/** Korijen riječi (bez zadnja dva slova kod duljih), da se poklope padeži: naočale, naočala, naočalama. */
	private static function stem( $w ) {
		$n = strlen( $w );
		return $n > 5 ? substr( $w, 0, $n - 2 ) : $w;
	}

	private static function terms( $key, $default = array() ) {
		$t = array_filter( array_map( 'trim', array_map( array( __CLASS__, 'norm' ), explode( ',', (string) OLR_Settings::get( $key ) ) ) ) );
		return $t ? array_values( $t ) : $default;
	}

	/** Upit s imenom tvrtke; uspoređuje se korijen, pa „ljiljana” pokriva i „ljiljan”. */
	public static function is_brand( $query ) {
		$q = self::norm( $query );
		foreach ( self::terms( 'brand', array( 'ljiljana' ) ) as $t ) {
			$t = strlen( $t ) >= 6 ? substr( $t, 0, strlen( $t ) - 1 ) : $t;
			if ( '' !== $t && false !== strpos( $q, $t ) ) {
				return true;
			}
		}
		return false;
	}

	/** Upit s popisa „zanemari” (tuđe optike i slično). */
	public static function is_ignored( $query ) {
		$q = self::norm( $query );
		foreach ( self::terms( 'zanemari' ) as $t ) {
			if ( false !== strpos( $q, $t ) ) {
				return true;
			}
		}
		return false;
	}

	private static function skip( $query ) {
		return self::is_brand( $query ) || self::is_ignored( $query ) || self::is_competitor( $query );
	}

	/**
	 * Imena konkurenata iz njihovih domena: postavka „Konkurenti” i optike iz naših mjerenja pozicija.
	 * Riječ ulazi samo ako je duga barem 4 slova, nije opća riječ i ne koristimo je na vlastitom webu
	 * (tako „optika-sesvete.hr” ne bi isključila upit „optika sesvete”).
	 */
	private static function competitor_tokens() {
		if ( null !== self::$comp ) {
			return self::$comp;
		}
		$hosts = preg_split( '/[,\s]+/', (string) OLR_Settings::get( 'konkurenti' ) );
		$rk    = OLR_Store::data( 'rank', 'konkurenti' );
		foreach ( isset( $rk['domene'] ) ? (array) $rk['domene'] : array() as $d ) {
			$h = is_array( $d ) && isset( $d['domena'] ) ? (string) $d['domena'] : '';
			if ( preg_match( '/optik|optic|naocal|lece/i', $h ) ) {
				$hosts[] = $h;
			}
		}
		$own   = self::vocabulary();
		$brand = self::terms( 'brand', array( 'ljiljana' ) );
		$out   = array();
		foreach ( $hosts as $h ) {
			$h = preg_replace( '#^(https?://)?(www\.)?#i', '', strtolower( trim( (string) $h ) ) );
			$h = preg_replace( '#[/:].*$#', '', $h );
			$h = preg_replace( '/\.[a-z]{2,6}$/', '', $h );
			foreach ( preg_split( '/[^a-z0-9]+/', self::norm( $h ) ) as $t ) {
				if ( strlen( $t ) < 4 || in_array( $t, self::OPCE_RIJECI, true ) || isset( $own[ $t ] ) || in_array( $t, $brand, true ) ) {
					continue;
				}
				$out[ $t ] = 1;
			}
		}
		self::$comp = array_keys( $out );
		return self::$comp;
	}

	/**
	 * Upit s imenom konkurenta („optika anda”, „jo jo optika”). Riječ upita mora počinjati imenom
	 * (dopušteno do 2 slova padeža), a gleda se i spoj dviju susjednih riječi.
	 */
	public static function is_competitor( $query ) {
		$tokens = self::competitor_tokens();
		if ( ! $tokens ) {
			return false;
		}
		$w    = array_values( array_filter( preg_split( '/[^a-z0-9]+/', self::norm( $query ) ) ) );
		$cand = $w;
		for ( $i = 0; $i + 1 < count( $w ); $i++ ) {
			$cand[] = $w[ $i ] . $w[ $i + 1 ];
		}
		foreach ( $cand as $c ) {
			foreach ( $tokens as $t ) {
				if ( 0 === strpos( $c, $t ) && strlen( $c ) - strlen( $t ) <= 2 ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Lokalni upit (mjesto, blizina ili sama djelatnost): očekivani CTR organskog rezultata je niži. */
	public static function is_local( $query ) {
		$q = self::norm( $query );
		if ( in_array( trim( $q ), self::DJELATNOST, true ) ) {
			return true;
		}
		foreach ( self::LOKALNO as $t ) {
			if ( preg_match( '/(^|[^a-z])' . preg_quote( $t, '/' ) . '/', $q ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vjerojatnost da slučajno bude najviše $k uspjeha u $n pokušaja uz vjerojatnost $p (binomna razdioba).
	 * Računa se u logaritmima, pa radi i za tisuće prikazivanja.
	 */
	public static function binom_cdf( $k, $n, $p ) {
		$k = (int) $k;
		$n = (int) $n;
		if ( $k < 0 ) {
			return 0.0;
		}
		if ( $k >= $n || $p <= 0 ) {
			return 1.0;
		}
		if ( $p >= 1 ) {
			return 0.0;
		}
		$lt  = $n * log( 1 - $p );
		$sum = exp( $lt );
		for ( $i = 0; $i < $k; $i++ ) {
			$lt  += log( $n - $i ) - log( $i + 1 ) + log( $p ) - log( 1 - $p );
			$sum += exp( $lt );
		}
		return min( 1.0, $sum );
	}

	/**
	 * Glavna stranica za upit: najviše klikova, zatim najbolja pozicija, zatim prikazivanja. Po samim
	 * prikazivanjima bi za upit s imenom optike pobijedila stranica iz dodatnih poveznica ispod rezultata
	 * (sitelinkovi imaju ista prikazivanja kao glavni rezultat) ili stranica s Googleova profila tvrtke.
	 *
	 * @param array $list Redci s ključevima klikovi, pozicija, prikazivanja.
	 */
	public static function main_of( $list ) {
		$best = null;
		foreach ( $list as $l ) {
			if ( null === $best ) {
				$best = $l;
				continue;
			}
			$x = ( $l['klikovi'] <=> $best['klikovi'] ) ?: ( $best['pozicija'] <=> $l['pozicija'] ) ?: ( $l['prikazivanja'] <=> $best['prikazivanja'] );
			if ( $x > 0 ) {
				$best = $l;
			}
		}
		return $best;
	}

	/**
	 * Glavna stranica po upitu iz redaka [upit, stranica, klikovi, prikazivanja, ctr, pozicija].
	 *
	 * @return array upit => [url, put, klikovi, prikazivanja, pozicija, udio (prikazivanja)]
	 */
	public static function main_pages( $rows ) {
		$by = array();
		foreach ( $rows as $r ) {
			$by[ $r[0] ][] = array(
				'url'          => $r[1],
				'put'          => self::path( $r[1] ),
				'klikovi'      => (int) $r[2],
				'prikazivanja' => (int) $r[3],
				'pozicija'     => (float) $r[5],
			);
		}
		$out = array();
		foreach ( $by as $q => $list ) {
			$m   = self::main_of( $list );
			$tot = 0;
			foreach ( $list as $l ) {
				$tot += $l['prikazivanja'];
			}
			$m['udio']    = $tot ? $m['prikazivanja'] / $tot : 0;
			$m['stranica'] = count( $list );
			$out[ $q ]    = $m;
		}
		return $out;
	}

	/** Je li stranica mijenjana u zadnjih SVJEZE_DANA dana (brojke iz Googlea tada opisuju staro stanje). */
	private static function is_fresh( $url ) {
		$dm = self::days_since_modified( self::page_row( $url ) );
		return null !== $dm && $dm < self::SVJEZE_DANA;
	}

	/** Bilježi stranicu preskočenu zbog nedavne izmjene, po vrsti nalaza (za napomenu ispod analize). */
	private static $fresh = array();

	private static function skip_fresh( $vrsta, $url ) {
		self::$fresh[ $vrsta ][ self::path( $url ) ] = 1;
	}

	/** Riječi potpisa stranice (za usporedbu po početku riječi, ne po bilo kojem dijelu teksta). */
	private static function sig_words( $url ) {
		$path = self::path( $url );
		if ( ! isset( self::$words[ $path ] ) ) {
			self::$words[ $path ] = array_values( array_unique( array_filter( preg_split( '/[^a-z0-9]+/', self::signature( $url ) ) ) ) );
		}
		return self::$words[ $path ];
	}

	/** Sve riječi koje koristimo na webu (naslovi, opisi, podnaslovi, adrese). */
	private static function vocabulary() {
		if ( null === self::$vocab ) {
			self::$vocab = array();
			foreach ( array_keys( self::pages() ) as $p ) {
				foreach ( self::sig_words( $p ) as $w ) {
					self::$vocab[ $w ] = 1;
				}
			}
		}
		return self::$vocab;
	}

	/** O čemu je stranica: naslov, opis, svi naslovi na stranici, fokus riječ i riječi iz adrese. */
	private static function signature( $url ) {
		$path = self::path( $url );
		if ( isset( self::$sigs[ $path ] ) ) {
			return self::$sigs[ $path ];
		}
		$row = self::page_row( $url );
		$sig = '';
		if ( $row ) {
			$d   = is_array( $row->data ) ? $row->data : array();
			$sig = ( isset( $d['naslov'] ) ? $d['naslov'] : '' ) . ' ' . ( isset( $d['opis'] ) ? $d['opis'] : '' ) . ' ' . ( ! empty( $d['h1'] ) ? implode( ' ', (array) $d['h1'] ) : '' );
			foreach ( isset( $d['naslovi'] ) ? (array) $d['naslovi'] : array() as $h ) {
				$sig .= ' ' . ( isset( $h['t'] ) ? $h['t'] : '' );
			}
			$rm   = OLR_Audit::rank_math( (int) $row->post_id );
			$sig .= ' ' . ( $rm ? $rm['fokus'] : '' ) . ' ' . str_replace( array( '-', '/' ), ' ', $path );
			$sig  = self::norm( $sig );
		}
		self::$sigs[ $path ] = $sig;
		return $sig;
	}

	/**
	 * Je li upit o onome o čemu je stranica: svaka riječ upita od 4 ili više slova mora se (korijenom)
	 * naći na početku neke riječi u potpisu stranice. Tako „optika anda” (tuđa optika) ili „sunčane
	 * naočale” na naslovnici ne postaju razlog za promjenu naslova. Usporedba je po riječima: „anda”
	 * se ne smije poklopiti s „standard”.
	 */
	public static function relevant( $query, $url ) {
		$words = self::sig_words( $url );
		if ( ! $words ) {
			return false;
		}
		$n = 0;
		foreach ( preg_split( '/[^a-z0-9]+/', self::norm( $query ) ) as $w ) {
			if ( strlen( $w ) < 4 ) {
				continue;
			}
			++$n;
			$st = self::stem( $w );
			$ok = false;
			foreach ( $words as $sw ) {
				if ( 0 === strpos( $sw, $st ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return $n > 0;
	}

	/** Dana od zadnje izmjene stranice u WordPressu (null ako se ne zna). */
	private static function days_since_modified( $row ) {
		if ( ! $row || ! $row->post_id ) {
			return null;
		}
		$t = get_post_modified_time( 'U', true, (int) $row->post_id );
		return $t ? (int) floor( ( time() - (int) $t ) / DAY_IN_SECONDS ) : null;
	}

	private static function note( $key, $text ) {
		self::$notes[ $key ] = $text;
	}

	/** Stranice iz crawla, po putanji (GSC i crawl mogu se razlikovati u www/http). */
	private static function pages() {
		if ( null === self::$pages ) {
			self::$pages = array();
			foreach ( OLR_Crawler::all_rows() as $u => $r ) {
				if ( 'page' !== $r->kind ) {
					continue;
				}
				self::$pages[ self::path( $u ) ] = $r;
			}
		}
		return self::$pages;
	}

	private static function page_row( $url ) {
		$pages = self::pages();
		$p     = self::path( $url );
		if ( isset( $pages[ $p ] ) ) {
			return $pages[ $p ];
		}
		$alt = '/' === substr( $p, -1 ) ? rtrim( $p, '/' ) : $p . '/';
		return isset( $pages[ $alt ] ) ? $pages[ $alt ] : null;
	}

	private static function is_noindex( $row ) {
		return $row && ! empty( $row->data['robots'] ) && false !== strpos( (string) $row->data['robots'], 'noindex' );
	}

	/** Činjenice o stranici za prijedlog (sadašnji naslov, opis, H1, podnaslovi, broj riječi, fokus riječ). */
	public static function facts( $url ) {
		$row = self::page_row( $url );
		$out = array(
			'url'     => (string) $url,
			'put'     => self::path( $url ),
			'poznata' => (bool) $row,
		);
		if ( ! $row ) {
			return $out;
		}
		$d  = is_array( $row->data ) ? $row->data : array();
		$h2 = array();
		foreach ( isset( $d['naslovi'] ) ? (array) $d['naslovi'] : array() as $h ) {
			if ( isset( $h['r'], $h['t'] ) && 2 === (int) $h['r'] && '' !== $h['t'] && count( $h2 ) < 8 ) {
				$h2[] = $h['t'];
			}
		}
		$rm            = OLR_Audit::rank_math( (int) $row->post_id );
		$out['naslov'] = isset( $d['naslov'] ) ? (string) $d['naslov'] : '';
		$out['opis']   = isset( $d['opis'] ) ? (string) $d['opis'] : '';
		$out['h1']     = ! empty( $d['h1'] ) ? (string) $d['h1'][0] : '';
		$out['h2']     = $h2;
		$out['rijeci'] = isset( $d['rijeci'] ) ? (int) $d['rijeci'] : 0;
		$out['fokus']  = $rm ? (string) $rm['fokus'] : '';
		$out['status'] = (int) $row->status;
		$dm            = self::days_since_modified( $row );
		if ( null !== $dm ) {
			$out['izmijenjeno_prije_dana'] = $dm;
		}
		return $out;
	}

	/**
	 * Očekivani udio klikova za poziciju, namjerno oprezan (da procjene ne obećavaju previše):
	 * bez dovoljno vlastitih podataka 80 % opće krivulje; s njima prosjek vlastite i opće krivulje,
	 * ali nikad ispod pola ni iznad jedne i pol opće. Vlastita krivulja vrijedi tek kad pozicija ima
	 * barem 400 prikazivanja iz barem 5 različitih upita, da je jedan veliki upit ne odredi sam.
	 */
	public static function expected_ctr( $pos ) {
		$p = max( 1, (int) round( $pos ) );
		if ( $p > 10 ) {
			return $p <= 20 ? 0.008 : 0.002;
		}
		$g = self::CTR[ $p ];
		$c = self::curve();
		if ( ! isset( $c[ $p ] ) ) {
			return 0.8 * $g;
		}
		return max( 0.5 * $g, min( 1.5 * $g, ( $c[ $p ] + $g ) / 2 ) );
	}

	private static function curve() {
		if ( null !== self::$curve ) {
			return self::$curve;
		}
		$qp  = OLR_Store::data( 'gsc', 'qp', array() );
		$agg = array();
		foreach ( isset( $qp['rows'] ) ? $qp['rows'] : array() as $r ) {
			if ( self::skip( $r[0] ) ) {
				continue;
			}
			$p = max( 1, (int) round( $r[5] ) );
			if ( $p > 10 ) {
				continue;
			}
			if ( ! isset( $agg[ $p ] ) ) {
				$agg[ $p ] = array( 0, 0, array() );
			}
			$agg[ $p ][0]           += (int) $r[2];
			$agg[ $p ][1]           += (int) $r[3];
			$agg[ $p ][2][ $r[0] ]   = 1;
		}
		self::$curve = array();
		foreach ( $agg as $p => $a ) {
			if ( $a[1] >= 400 && count( $a[2] ) >= 5 ) {
				self::$curve[ $p ] = $a[0] / $a[1];
			}
		}
		return self::$curve;
	}

	private static function pct( $x ) {
		return round( $x * 100, 1 );
	}

	/** Stranice koje spominju riječi upita u naslovu ili podnaslovima, a još ne linkaju na cilj. */
	private static function link_sources( $target_url, $query, $max = 5 ) {
		$words = array();
		foreach ( preg_split( '/\s+/u', OLR_DFS::lower( $query ) ) as $w ) {
			if ( self::len( $w ) >= 4 ) {
				$words[] = $w;
			}
		}
		if ( ! $words ) {
			return array();
		}
		$tpath = self::path( $target_url );
		$out   = array();
		foreach ( self::pages() as $path => $r ) {
			if ( $path === $tpath || 200 !== (int) $r->status || self::is_noindex( $r ) ) {
				continue;
			}
			$d     = is_array( $r->data ) ? $r->data : array();
			$links = false;
			foreach ( isset( $d['linkovi'] ) ? (array) $d['linkovi'] : array() as $l ) {
				if ( isset( $l['u'] ) && self::path( $l['u'] ) === $tpath && ( ! isset( $l['k'] ) || 's' === $l['k'] ) && empty( $l['nf'] ) ) {
					$links = true;
					break;
				}
			}
			if ( $links ) {
				continue;
			}
			$hay = OLR_DFS::lower( ( isset( $d['naslov'] ) ? $d['naslov'] : '' ) . ' ' . ( ! empty( $d['h1'] ) ? implode( ' ', (array) $d['h1'] ) : '' ) );
			foreach ( isset( $d['naslovi'] ) ? (array) $d['naslovi'] : array() as $h ) {
				$hay .= ' ' . OLR_DFS::lower( isset( $h['t'] ) ? $h['t'] : '' );
			}
			$score = 0;
			foreach ( $words as $w ) {
				// Korijen riječi (bez zadnja 2 slova) hvata padeže: naočale / naočala / naočalama.
				$stem = self::len( $w ) > 5 ? ( function_exists( 'mb_substr' ) ? mb_substr( $w, 0, self::len( $w ) - 2, 'UTF-8' ) : substr( $w, 0, -2 ) ) : $w;
				if ( false !== strpos( $hay, $stem ) ) {
					++$score;
				}
			}
			if ( $score > 0 ) {
				$out[] = array(
					'put'    => $path,
					'naslov' => isset( $d['naslov'] ) ? (string) $d['naslov'] : '',
					's'      => $score,
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['s'] - $a['s'];
			}
		);
		$out = array_slice( $out, 0, $max );
		foreach ( $out as &$o ) {
			unset( $o['s'] );
		}
		return $out;
	}

	private static function cand( $vrsta, $key, $url, $upit, $ucinak, $dokazi, $extra = array() ) {
		return array_merge(
			array(
				'kljuc'    => $vrsta . '|' . $key,
				'vrsta'    => $vrsta,
				'url'      => (string) $url,
				'put'      => $url ? self::path( $url ) : '',
				'upit'     => (string) $upit,
				'ucinak'   => round( max( 0, (float) $ucinak ), 1 ),
				'dokazi'   => $dokazi,
				'stranica' => $url ? self::facts( $url ) : null,
			),
			$extra
		);
	}

	/* ---------- pravila ---------- */

	/**
	 * Svi kandidati, poredani po procijenjenom učinku.
	 *
	 * @return array{kandidati: array, gsc_do: ?string, napomene: string[]}
	 */
	public static function run() {
		self::reset();
		$meta  = OLR_Store::data( 'gsc', 'meta' );
		$notes = array();
		$out   = array();
		if ( $meta ) {
			$qp  = OLR_Store::data( 'gsc', 'qp', array() );
			$wk  = OLR_Store::data( 'gsc', 'wk', array() );
			$out = array_merge(
				self::rule_ctr_and_gap( isset( $qp['rows'] ) ? $qp['rows'] : array() ),
				self::rule_cannibal( isset( $wk['qp7'] ) ? $wk['qp7'] : array() ),
				self::rule_drop( $wk, isset( $qp['rows'] ) ? $qp['rows'] : array() ),
				self::rule_new_content( isset( $qp['rows'] ) ? $qp['rows'] : array() )
			);
		} else {
			$notes[] = 'Google podaci još nisu preuzeti, pa analiza koristi samo crawl weba i pozicije.';
		}
		$out = array_merge( $out, self::rule_rank(), self::rule_audit(), self::rule_index() );
		if ( self::$fresh ) {
			$paths = array();
			foreach ( self::$fresh as $list ) {
				$paths += $list;
			}
			$names = array(
				'ctr'      => 'naslov i opis',
				'prilika'  => 'blizu vrha',
				'kanibal'  => 'kanibalizacija',
				'novo'     => 'novi sadržaj',
				'pozicije' => 'kriva stranica u rezultatu',
			);
			$kinds = array();
			foreach ( array_keys( self::$fresh ) as $k ) {
				$kinds[] = isset( $names[ $k ] ) ? $names[ $k ] : $k;
			}
			self::note( 'svjeze', 'Za ' . count( $paths ) . ' stranica mijenjanih u zadnjih ' . self::SVJEZE_DANA . ' dana (' . implode( ', ', array_slice( array_keys( $paths ), 0, 4 ) ) . ( count( $paths ) > 4 ? ' i druge' : '' ) . ') nalazi se ne predlažu (' . implode( ', ', $kinds ) . '): Googleove brojke još opisuju staro stanje. Vraćaju se sami ako i dalje vrijede.' );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['ucinak'] <=> $a['ucinak'];
			}
		);
		return array(
			'kandidati' => $out,
			'potpuna'   => (bool) $meta, // bez Google podataka nalazi iz GSC-a nedostaju, pa se ne smiju gasiti
			'gsc_do'    => $meta && ! empty( $meta['do'] ) ? $meta['do'] : null,
			'napomene'  => array_merge( $notes, array_values( self::$notes ) ),
		);
	}

	/**
	 * Naslov/opis ne privlači klik (visoka pozicija, nizak CTR) i upiti blizu vrha (pozicija 4–15).
	 * Upit se uzima samo za stranicu koja je za njega glavna: niži CTR sporedne stranice je očekivan,
	 * a guranje sporedne stranice za tuđi upit stvara kanibalizaciju. Nizak CTR mora biti statistički
	 * značajan, a kod lokalnih upita (karta s tvrtkama iznad rezultata) očekivanje je niže.
	 */
	private static function rule_ctr_and_gap( $rows ) {
		$ctr   = array();
		$gap   = array();
		$main  = self::main_pages( $rows );
		foreach ( $rows as $r ) {
			list( $q, $page, $c, $i, $ratio, $pos ) = $r;
			// Samo upiti o onome o čemu je stranica; ostalo nije razlog za promjenu te stranice.
			if ( self::skip( $q ) || $i < 30 || ! self::relevant( $q, $page ) ) {
				continue;
			}
			if ( isset( $main[ $q ] ) && $main[ $q ]['put'] !== self::path( $page ) ) {
				continue; // za taj upit glavna je druga naša stranica
			}
			$local = self::is_local( $q );
			$exp   = self::expected_ctr( $pos ) * ( $local ? self::LOKALNO_CTR : 1 );
			$fresh = self::is_fresh( $page );
			// Nizak CTR: ispod pola očekivanog i statistički ispod 80 % očekivanog (ne slučajno malo klikova).
			$low = $pos <= 6.0 && $ratio < 0.5 * $exp && self::binom_cdf( $c, $i, 0.8 * $exp ) < self::ZNACAJNO;
			if ( $low && $fresh ) {
				self::skip_fresh( 'ctr', $page );
			} elseif ( $low ) {
				$missed = $i * ( 0.8 * $exp - $ratio );
				if ( $missed >= 1.5 ) {
					$ctr[ $page ][] = array(
						'upit'            => $q,
						'pozicija'        => round( $pos, 1 ),
						'prikazivanja'    => (int) $i,
						'klikovi'         => (int) $c,
						'ctr_posto'       => self::pct( $ratio ),
						'ocekivano_posto' => self::pct( $exp ),
						'lokalni_upit'    => $local,
						'_g'              => $missed,
					);
				}
			}
			if ( $pos > 3.5 && $pos <= 15.0 && $i >= 40 ) {
				$gain = 0.5 * $i * max( 0, self::expected_ctr( 3 ) * ( $local ? self::LOKALNO_CTR : 1 ) - $ratio );
				if ( $gain >= 1 && $fresh ) {
					self::skip_fresh( 'prilika', $page );
				} elseif ( $gain >= 1 ) {
					$gap[ $page ][] = array(
						'upit'         => $q,
						'pozicija'     => round( $pos, 1 ),
						'prikazivanja' => (int) $i,
						'klikovi'      => (int) $c,
						'_g'           => $gain,
					);
				}
			}
		}
		$out = array();
		foreach ( array( 'ctr' => $ctr, 'prilika' => $gap ) as $vrsta => $by_page ) {
			foreach ( $by_page as $page => $list ) {
				$row = self::page_row( $page );
				if ( self::is_noindex( $row ) ) {
					continue;
				}
				usort(
					$list,
					static function ( $a, $b ) {
						return $b['_g'] <=> $a['_g'];
					}
				);
				$sum = 0;
				foreach ( $list as $l ) {
					$sum += $l['_g'];
				}
				if ( $sum < 3 ) {
					continue;
				}
				$top = array_slice( $list, 0, 4 );
				$f   = self::facts( $page );
				foreach ( $top as &$t ) {
					unset( $t['_g'] );
					if ( 'prilika' === $vrsta && ! empty( $f['poznata'] ) ) {
						$t['u_naslovu'] = OLR_Audit::phrase_in( $t['upit'], $f['naslov'] );
						$t['u_h1']      = OLR_Audit::phrase_in( $t['upit'], $f['h1'] );
					}
				}
				unset( $t );
				$extra = array( 'ucinak_opis' => 'ctr' === $vrsta ? 'procjena dodatnih klikova u 28 dana kad bi CTR bio blizu uobičajenog za tu poziciju' : 'procjena dodatnih klikova u 28 dana kad bi ovi upiti došli do 3. mjesta (uzeta polovica razlike)' );
				if ( 'prilika' === $vrsta ) {
					$extra['izvori_linka'] = self::link_sources( $page, $top[0]['upit'] );
				}
				$out[] = self::cand( $vrsta, self::path( $page ), $page, $top[0]['upit'], $sum, array( 'upiti' => $top ), $extra );
			}
		}
		return $out;
	}

	/**
	 * Isti upit u zadnjih 7 dana dijele dvije ili više stranica, i to šteti. Ne javlja se kad:
	 * - je glavna stranica (najviše klikova, zatim najbolja pozicija) na poziciji do 1,5: prvi smo, a ostale
	 *   stranice su dodatne poveznice ispod rezultata (sitelinkovi) ili drugi rezultat ispod našeg;
	 * - su glavna i druga stranica obje u prva tri rezultata: to je dvostruki prikaz, više mjesta za nas;
	 * - je druga stranica ispod 20. mjesta (ne konkurira glavnoj);
	 * - je upit s imenom optike ili konkurenta, ili je neka od stranica mijenjana u zadnjih 14 dana.
	 */
	private static function rule_cannibal( $rows ) {
		$by = array();
		foreach ( $rows as $r ) {
			list( $q, $page, $c, $i, , $pos ) = $r;
			if ( self::skip( $q ) ) {
				continue;
			}
			$by[ $q ][] = array(
				'put'          => self::path( $page ),
				'url'          => $page,
				'prikazivanja' => (int) $i,
				'klikovi'      => (int) $c,
				'pozicija'     => round( $pos, 1 ),
			);
		}
		$out  = array();
		$top1 = 0;
		foreach ( $by as $q => $list ) {
			$total = 0;
			foreach ( $list as $l ) {
				$total += $l['prikazivanja'];
			}
			if ( $total < 25 || count( $list ) < 2 ) {
				continue;
			}
			$main = self::main_of( $list );
			if ( $main['pozicija'] <= 1.5 ) {
				++$top1;
				continue;
			}
			$rivals = array();
			foreach ( $list as $l ) {
				if ( $l['put'] === $main['put'] || $l['prikazivanja'] < 0.2 * $total || $l['pozicija'] > 20 || self::is_noindex( self::page_row( $l['url'] ) ) ) {
					continue;
				}
				if ( $main['pozicija'] <= 3.5 && $l['pozicija'] <= 3.5 ) {
					continue; // obje u prva tri: dvostruki prikaz
				}
				$rivals[] = $l;
			}
			if ( ! $rivals || self::is_noindex( self::page_row( $main['url'] ) ) ) {
				continue;
			}
			if ( ! self::relevant( $q, $main['url'] ) ) {
				continue; // upit nije o onome o čemu je glavna stranica (npr. tuđa optika)
			}
			$fresh = false;
			foreach ( array_merge( array( $main ), $rivals ) as $l ) {
				if ( self::is_fresh( $l['url'] ) ) {
					self::skip_fresh( 'kanibal', $l['url'] );
					$fresh = true;
				}
			}
			if ( $fresh ) {
				continue;
			}
			usort(
				$rivals,
				static function ( $a, $b ) {
					return $b['prikazivanja'] <=> $a['prikazivanja'];
				}
			);
			$live = array_merge( array( $main ), $rivals );
			$out[] = self::cand(
				'kanibal',
				$q,
				$main['url'],
				$q,
				$total * 0.03,
				array(
					'upit'            => $q,
					'razdoblje'       => 'zadnjih 7 dana',
					'stranice'        => array_map(
						static function ( $l ) {
							unset( $l['url'] );
							return $l;
						},
						$live
					),
					'glavna_stranica' => $main['put'],
					'kako_je_odabrana' => 'najviše klikova, zatim najbolja pozicija',
				),
				array(
					'ucinak_opis'    => 'gruba procjena; kanibalizacija najčešće drži obje stranice niže nego što bi bila jedna',
					'druge_stranice' => array_map(
						static function ( $l ) {
							return OLR_Analiza::facts( $l['url'] );
						},
						array_slice( $rivals, 0, 2 )
					),
				)
			);
		}
		if ( $top1 ) {
			self::note( 'kanibal_prvi', 'Za ' . $top1 . ' upita s više naših stranica glavna je na prvom mjestu (do 1,5): to nije kanibalizacija nego dodatne poveznice ili drugi naš rezultat ispod prvog.' );
		}
		return $out;
	}

	/** Upit koji je u zadnjih 7 dana pao prema prethodnih 7. */
	private static function rule_drop( $wk, $qp_rows ) {
		if ( empty( $wk['cur'] ) || empty( $wk['prev'] ) ) {
			return array();
		}
		$cur = array();
		foreach ( $wk['cur'] as $r ) {
			$cur[ $r[0] ] = $r;
		}
		// Glavna stranica: prvo iz zadnjih 7 dana, inače iz 28 dana.
		$top = self::main_pages( $qp_rows );
		foreach ( self::main_pages( isset( $wk['qp7'] ) ? $wk['qp7'] : array() ) as $q => $m ) {
			$top[ $q ] = $m;
		}
		$out   = array();
		$noise = 0;
		foreach ( $wk['prev'] as $p ) {
			list( $q, $pc, $pi, , $ppos ) = $p;
			if ( self::skip( $q ) || $pi < 30 ) {
				continue;
			}
			$c    = isset( $cur[ $q ] ) ? $cur[ $q ] : array( $q, 0, 0, 0, null );
			$lost = $pc - $c[1];
			$slip = null !== $c[4] ? $c[4] - $ppos : null;
			// Pad je ili stvarno izgubljeni klikovi (barem 3, i statistički više od slučajnog kolebanja) ili osjetan
			// pad pozicije na upitu s dovoljno prikazivanja. Klikovi se uspoređuju binomnim testom: ako su oba tjedna
			// ista, svaki klik je s jednakom vjerojatnošću u jednom ili drugom tjednu.
			$p_val     = self::binom_cdf( (int) $c[1], (int) $pc + (int) $c[1], 0.5 );
			$by_clicks = $pc >= 3 && $lost >= 3 && $p_val < self::ZNACAJNO;
			$by_pos    = $pi >= 60 && null !== $slip && $slip >= 3 && $c[2] >= 20;
			if ( $pc >= 3 && $lost >= 3 && ! $by_clicks && ! $by_pos ) {
				++$noise;
			}
			if ( ! $by_clicks && ! $by_pos ) {
				continue;
			}
			$page = isset( $top[ $q ] ) ? $top[ $q ]['url'] : '';
			if ( $page && self::page_row( $page ) && ! self::relevant( $q, $page ) ) {
				continue;
			}
			$out[] = self::cand(
				'pad',
				$q,
				$page,
				$q,
				max( 1, $lost ) * 4,
				array(
					'upit'      => $q,
					'razdoblje' => isset( $wk['range'] ) ? $wk['range'] : null,
					'ovaj_tjedan'   => array( 'klikovi' => (int) $c[1], 'prikazivanja' => (int) $c[2], 'pozicija' => null !== $c[4] ? round( $c[4], 1 ) : null ),
					'prosli_tjedan' => array( 'klikovi' => (int) $pc, 'prikazivanja' => (int) $pi, 'pozicija' => round( $ppos, 1 ) ),
					'slucajnost'    => $by_clicks ? round( $p_val, 3 ) : null,
				),
				array( 'ucinak_opis' => 'izgubljeni klikovi tjedno pomnoženi s 4 (ako se pad nastavi)' )
			);
		}
		if ( $noise ) {
			self::note( 'pad_sum', 'Za ' . $noise . ' upita manje klikova nego prošli tjedan je u granicama slučajnog kolebanja (premalo klikova za siguran zaključak), pa nisu prijedlog.' );
		}
		return $out;
	}

	/** Upiti s potražnjom za koje nemamo pravu stranicu (rangira naslovnica/Journal ili smo ispod 20. mjesta). */
	private static function rule_new_content( $rows ) {
		$by = array();
		$ri = array();
		foreach ( $rows as $r ) {
			if ( ! self::skip( $r[0] ) ) {
				$ri[] = $r;
			}
		}
		foreach ( self::main_pages( $ri ) as $q => $m ) {
			$tot      = $m['udio'] > 0 ? (int) round( $m['prikazivanja'] / $m['udio'] ) : $m['prikazivanja'];
			$by[ $q ] = array( 'page' => $m['url'], 'i' => $tot, 'c' => $m['klikovi'], 'pos' => $m['pozicija'] );
		}
		$out = array();
		foreach ( $by as $q => $b ) {
			$path = self::path( $b['page'] );
			$home = '/' === $path;
			$blog = (bool) preg_match( '#^/(journal|blog|novosti)/?$#', $path );
			// Naslovnica je legitiman cilj za lokalne upite: do 15. mjesta to je „blizu vrha”, a ne nova stranica.
			$generic = ( $home && $b['pos'] > 15 ) || ( $blog && $b['pos'] > 8 );
			if ( $b['i'] < 50 || ! ( $generic || $b['pos'] > 20 ) ) {
				continue;
			}
			// Postojeća stranica već je o toj temi: nova bi joj konkurirala. To je posao za doradu, ne novi članak.
			if ( ! $generic && self::relevant( $q, $b['page'] ) ) {
				continue;
			}
			if ( ! $generic && self::is_fresh( $b['page'] ) ) {
				self::skip_fresh( 'novo', $b['page'] );
				continue;
			}
			$out[] = self::cand(
				'novo',
				$q,
				$b['page'],
				$q,
				0.5 * $b['i'] * self::expected_ctr( 5 ),
				array(
					'upit'          => $q,
					'prikazivanja'  => $b['i'],
					'klikovi'       => $b['c'],
					'pozicija'      => round( $b['pos'], 1 ),
					'sada_rangira'  => $path,
					'razlog'        => $generic ? ( $home ? 'za upit rangira naslovnica, a ne stranica o toj temi' : 'za upit rangira popis članaka, a ne stranica o toj temi' ) : 'postojeća stranica je ispod 20. mjesta',
				),
				array(
					'ucinak_opis'  => 'procjena klikova u 28 dana kad bi nova stranica došla do 5. mjesta (uzeta polovica)',
					'izvori_linka' => self::link_sources( 'https://x/__nova__/', $q ),
				)
			);
		}
		return $out;
	}

	/** Ciljane riječi iz mjerenja pozicija: pad, ispadanje ili kriva stranica u rezultatima. */
	private static function rule_rank() {
		$latest = OLR_Store::data( 'rank', 'latest' );
		if ( ! $latest || empty( $latest['rows'] ) ) {
			return array();
		}
		$wk  = OLR_Store::data( 'gsc', 'wk', array() );
		$top = array();
		$imp = array();
		$q7  = isset( $wk['qp7'] ) ? $wk['qp7'] : array();
		foreach ( $q7 as $r ) {
			$k         = OLR_DFS::lower( $r[0] );
			$imp[ $k ] = ( isset( $imp[ $k ] ) ? $imp[ $k ] : 0 ) + (int) $r[3];
		}
		foreach ( self::main_pages( $q7 ) as $q => $m ) {
			$top[ OLR_DFS::lower( $q ) ] = array( $q, $m['url'] );
		}
		$out = array();
		foreach ( $latest['rows'] as $r ) {
			if ( empty( $r['checked'] ) ) {
				continue;
			}
			$k    = OLR_DFS::lower( $r['kw'] );
			$i7   = isset( $imp[ $k ] ) ? $imp[ $k ] : 0;
			$gurl = isset( $top[ $k ] ) ? $top[ $k ][1] : '';
			$d    = array(
				'rijec'              => $r['kw'],
				'pozicija'           => $r['pos'],
				'prethodna_pozicija' => $r['prev'],
				'stranica_u_rezultatu' => $r['url'] ? self::path( $r['url'] ) : null,
				'gsc_stranica_7_dana'  => $gurl ? self::path( $gurl ) : null,
				'gsc_prikazivanja_7_dana' => $i7,
				'mjereno'            => ! empty( $latest['last'] ) ? substr( (string) $latest['last'], 0, 10 ) : null,
			);
			if ( null === $r['pos'] && null !== $r['prev'] ) {
				$d['nalaz'] = 'riječ više nije pronađena u izmjerenoj dubini';
				$out[]      = self::cand( 'pozicije', 'ispao|' . $k, $gurl, $r['kw'], 3 + 4 * $i7 * self::expected_ctr( $r['prev'] ), $d, array( 'ucinak_opis' => 'gruba procjena iz prikazivanja u Search Consoleu i prethodne pozicije' ) );
			} elseif ( null !== $r['pos'] && null !== $r['prev'] && $r['pos'] - $r['prev'] >= 3 ) {
				$d['nalaz'] = 'pad za ' . ( $r['pos'] - $r['prev'] ) . ' mjesta';
				$out[]      = self::cand( 'pozicije', 'pad|' . $k, $r['url'] ? $r['url'] : $gurl, $r['kw'], 2 + 4 * $i7 * max( 0, self::expected_ctr( $r['prev'] ) - self::expected_ctr( $r['pos'] ) ), $d, array( 'ucinak_opis' => 'gruba procjena iz prikazivanja u Search Consoleu i razlike pozicija' ) );
			} elseif ( null !== $r['pos'] && $r['url'] && $gurl && self::path( $r['url'] ) !== self::path( $gurl ) && $i7 >= 30 ) {
				// Tek uz barem 30 prikazivanja u 7 dana; s manje je glavna stranica u Search Consoleu slučajna.
				// Mjerenje pozicija je svježe; staro može biti samo ono što Search Console pokazuje za glavnu stranicu.
				if ( self::is_fresh( $gurl ) ) {
					self::skip_fresh( 'pozicije', $gurl );
					continue;
				}
				$d['nalaz'] = 'u rezultatu je druga stranica nego ona koju Search Console pokazuje za isti upit';
				$out[]      = self::cand( 'pozicije', 'url|' . $k, $gurl, $r['kw'], 2, $d, array( 'ucinak_opis' => 'nije procjena klikova; stavka je označena jer Google koleba između dviju stranica' ) );
			}
		}
		return $out;
	}

	/**
	 * Indeksiranost (Googleov URL Inspection): stranica koja bi trebala biti u indeksu, a nije, ili je Google
	 * kao kanonsku odabrao drugu adresu. Stranice mijenjane u zadnjih 7 dana se preskaču (Google ih još obrađuje).
	 */
	private static function rule_index() {
		$out  = array();
		$impr = array();
		$pg   = OLR_Store::data( 'gsc', 'pages', array() );
		foreach ( isset( $pg['rows'] ) ? $pg['rows'] : array() as $r ) {
			$impr[ self::path( $r[0] ) ] = (int) $r[2];
		}
		foreach ( OLR_Indeks::docs() as $d ) {
			if ( ! empty( $d['greska'] ) || empty( $d['problem'] ) ) {
				continue;
			}
			$row = self::page_row( $d['url'] );
			if ( ! $row || self::is_noindex( $row ) ) {
				continue;
			}
			$none = 'nije' === $d['problem'];
			$days = self::days_since_modified( $row );
			if ( $none && null !== $days && $days < 7 ) {
				self::note( 'indeks_svjeze', 'Stranice mijenjane u zadnjih 7 dana koje još nisu u indeksu ne ulaze u prijedloge; Google ih obično obradi za nekoliko dana.' );
				continue;
			}
			$path  = self::path( $d['url'] );
			$kod   = $none ? 'nije_u_indeksu' : 'druga_kanonska';
			$visit = ! empty( $d['zadnji_posjet'] ) ? substr( $d['zadnji_posjet'], 0, 10 ) : '';
			$out[] = self::cand(
				'tehnicki',
				$kod . '|' . $path,
				$d['url'],
				'',
				( $none ? 14 : 5 ) + min( 10, ( isset( $impr[ $path ] ) ? $impr[ $path ] : 0 ) * 0.01 ),
				array(
					'kod'            => $kod,
					'ozbiljnost'     => $none ? 'visoka' : 'srednja',
					'opis'           => $none ? 'Stranica nije u Googleovu indeksu' : 'Google je kao kanonsku odabrao drugu adresu',
					'detalj'         => $d['razlog'] . ( '' !== $visit ? ' · zadnji posjet Googlebota ' . $visit : ' · Googlebot stranicu još nije posjetio' ),
					'google_stanje'  => isset( $d['stanje_google'] ) ? $d['stanje_google'] : '',
					'provjereno'     => gmdate( 'Y-m-d', (int) ( $d['at'] / 1000 ) ),
					'search_console' => isset( $d['link'] ) ? $d['link'] : '',
				),
				array( 'ucinak_opis' => 'nije procjena klikova; stranica koje nema u indeksu ne može dobiti promet s Googlea' )
			);
		}
		return $out;
	}

	/** Tehnički nalazi crawla koji imaju konkretan popravak. */
	private static function rule_audit() {
		$audit = OLR_Audit::run( array( 'limit' => 1000 ) );
		if ( empty( $audit['nalazi'] ) ) {
			return array();
		}
		$clicks = array();
		$pg     = OLR_Store::data( 'gsc', 'pages', array() );
		foreach ( isset( $pg['rows'] ) ? $pg['rows'] : array() as $r ) {
			$clicks[ self::path( $r[0] ) ] = (int) $r[1];
		}
		$out   = array();
		$by    = array();
		$items = array();
		foreach ( $audit['nalazi'] as $n ) {
			if ( ! in_array( $n['kod'], self::AUDIT_KODOVI, true ) || self::is_noindex( self::page_row( $n['url'] ) ) ) {
				continue;
			}
			$by[ $n['kod'] ][] = $n;
		}
		// Isti nalaz na četiri ili više stranica ide u jedan prijedlog (inače bi svaki dan stizao novi za isto).
		foreach ( $by as $kod => $list ) {
			if ( count( $list ) < 4 ) {
				$items = array_merge( $items, $list );
				continue;
			}
			$pages = array();
			$sum   = 0;
			foreach ( $list as $n ) {
				$p       = self::path( $n['url'] );
				$c       = isset( $clicks[ $p ] ) ? $clicks[ $p ] : 0;
				$sum    += $c;
				$pages[] = array( 'put' => $p, 'klikovi_28_dana' => $c );
			}
			usort(
				$pages,
				static function ( $a, $b ) {
					return $b['klikovi_28_dana'] <=> $a['klikovi_28_dana'];
				}
			);
			$out[] = self::cand(
				'tehnicki',
				$kod . '|*',
				$list[0]['url'],
				'',
				( 'visoka' === $list[0]['ozbiljnost'] ? 6 : 3 ) + min( 10, $sum * 0.1 ),
				array(
					'kod'        => $kod,
					'ozbiljnost' => $list[0]['ozbiljnost'],
					'opis'       => $list[0]['opis'],
					'broj'       => count( $list ),
					'stranice'   => array_slice( $pages, 0, 8 ),
				),
				array( 'ucinak_opis' => 'nije procjena klikova; redoslijed po ozbiljnosti nalaza i prometu stranica' )
			);
		}
		foreach ( $items as $n ) {
			$row  = self::page_row( $n['url'] );
			$path = self::path( $n['url'] );
			$base = 'visoka' === $n['ozbiljnost'] ? 6 : 3;
			$c28  = isset( $clicks[ $path ] ) ? $clicks[ $path ] : 0;
			$det  = $n['detalj'];
			if ( is_array( $det ) ) {
				$det = array_slice( $det, 0, 8 );
			} elseif ( is_string( $det ) && self::len( $det ) > 400 ) {
				$det = substr( $det, 0, 400 );
			}
			$extra = array( 'ucinak_opis' => 'nije procjena klikova; redoslijed po ozbiljnosti nalaza i prometu stranice' );
			if ( 'bez_ulaznih_linkova' === $n['kod'] && $row ) {
				$f                     = self::facts( $n['url'] );
				$extra['izvori_linka'] = self::link_sources( $n['url'], $f['fokus'] ? $f['fokus'] : ( $f['h1'] ? $f['h1'] : $f['naslov'] ) );
			}
			$out[] = self::cand(
				'tehnicki',
				$n['kod'] . '|' . $path,
				$n['url'],
				'',
				$base + min( 10, $c28 * 0.1 ),
				array(
					'kod'            => $n['kod'],
					'ozbiljnost'     => $n['ozbiljnost'],
					'opis'           => $n['opis'],
					'detalj'         => $det,
					'klikovi_28_dana' => $c28,
				),
				$extra
			);
		}
		return $out;
	}
}
