<?php
/**
 * Mjerenje učinka provedenih izmjena.
 *
 * Za svaki prijedlog koji je vlasnik označio kao „provedeno” (i koji se odnosi na jednu stranicu) uspoređuje
 * Search Console prije i poslije: isti broj dana prije dana provedbe i poslije njega (7, 14 i 28 dana).
 * Uz stranicu se računa i trend cijelog weba u istim razdobljima, da sezona ne izgleda kao učinak izmjene.
 * Koriste se samo konačni Google podaci (dataState: final). Ništa se ne mijenja na webu.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Ucinak {

	const COLL     = 'ucinak';
	const EVENT    = 'olr_ucinak_event';
	const RUN_OPT  = 'olr_ucinak_run';
	const WINDOWS  = array( 7, 14, 28 );
	/** Ispod ovoliko prikazivanja u oba razdoblja usporedba ne govori ništa. */
	const MIN_IMPR = 30;
	const BUDGET   = 40;

	const OCJENE = array(
		'bolje'   => 'bolje',
		'isto'    => 'bez jasne promjene',
		'losije'  => 'lošije',
		'premalo' => 'premalo podataka',
	);

	public static function init() {
		add_action( 'olr_gsc_collected', array( __CLASS__, 'on_gsc' ) );
		add_action( self::EVENT, array( __CLASS__, 'sync' ) );
	}

	public static function on_gsc() {
		self::schedule( 45 );
	}

	/** Zakazuje mjerenje u pozadini (nakon novih Google podataka ili promjene stanja prijedloga). */
	public static function schedule( $delay = 5 ) {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::EVENT );
		}
	}

	public static function last_run() {
		$r = get_option( self::RUN_OPT );
		return is_array( $r ) ? $r : array();
	}

	/** Satni prolaz: ako ima posla, a zadnje mjerenje je starije od 12 sati. */
	public static function maybe() {
		$run = self::last_run();
		if ( ! empty( $run['at'] ) && time() - (int) $run['at'] < 12 * HOUR_IN_SECONDS ) {
			return;
		}
		if ( OLR_Settings::secret( 'gsc_sa' ) && self::pending() ) {
			self::sync();
		}
	}

	/** Ima li provedenih prijedloga čije mjerenje nije završeno. */
	private static function pending() {
		$docs = self::docs();
		foreach ( OLR_Prijedlozi::all() as $p ) {
			if ( 'provedeno' !== $p['stanje'] ) {
				continue;
			}
			if ( ! isset( $docs[ $p['id'] ] ) || in_array( $docs[ $p['id'] ]['stanje'], array( 'ceka', 'mjeri' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Svi zapisi učinka: id prijedloga => zapis. */
	public static function docs() {
		$out = array();
		foreach ( OLR_Store::list_docs( self::COLL, '', 'asc', 2000 ) as $d ) {
			if ( is_array( $d['data'] ) && ! empty( $d['data']['id'] ) ) {
				$out[ $d['data']['id'] ] = $d['data'];
			}
		}
		return $out;
	}

	private static function shift( $date, $days ) {
		$d = new DateTime( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		$d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' day' );
		return $d->format( 'Y-m-d' );
	}

	private static function days_between( $from, $to ) {
		return (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	/** Dan provedbe: datum koji je vlasnik upisao u ploči ili dan kad je prijedlog označen kao proveden. */
	public static function date_of( $p ) {
		$today = wp_date( 'Y-m-d' );
		if ( ! empty( $p['stanje_dan'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['stanje_dan'] ) && $p['stanje_dan'] <= $today && $p['stanje_dan'] >= self::shift( $today, -480 ) ) {
			return $p['stanje_dan'];
		}
		return ! empty( $p['stanje_at'] ) ? wp_date( 'Y-m-d', (int) ( $p['stanje_at'] / 1000 ) ) : $today;
	}

	/** Zašto se učinak ne može mjeriti po stranici (ili null ako može). */
	private static function unmeasurable( $p ) {
		if ( 'novo' === $p['vrsta'] ) {
			return 'Novi sadržaj još nema svoju povijest u Googleu; kad se stranica objavi, prati je u modulu Google.';
		}
		// Skupni tehnički nalazi (isti nalaz na puno stranica) imaju ključ koji završava s „|*”.
		if ( empty( $p['put'] ) || '*' === $p['put'] || empty( $p['url'] ) || '|*' === substr( (string) $p['kljuc'], -2 ) ) {
			return 'Izmjena se odnosi na više stranica, pa se učinak vidi u ukupnim brojkama weba, a ne po stranici.';
		}
		return null;
	}

	/**
	 * Dnevni podaci Search Consolea (samo konačni) uz filtre; vraća datum => [klikovi, prikazivanja, pozicija].
	 *
	 * @return array|WP_Error
	 */
	private static function daily( $from, $to, $filters = array() ) {
		$body = array(
			'startDate'  => $from,
			'endDate'    => $to,
			'dimensions' => array( 'date' ),
			'type'       => 'web',
			'dataState'  => 'final',
			'rowLimit'   => 1000,
		);
		if ( $filters ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'groupType' => 'and',
					'filters'   => $filters,
				),
			);
		}
		$rows = OLR_GSC::query( $body );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['keys'][0] ) ) {
				$out[ $r['keys'][0] ] = array( (int) $r['clicks'], (int) $r['impressions'], (float) $r['position'] );
			}
		}
		ksort( $out );
		return $out;
	}

	/** Filtar za stranicu: ista adresa sa ili bez završne kose crte i s bilo kojim parametrima (utm, rwg_token). */
	public static function page_filter( $url ) {
		$u = preg_replace( '/[?#].*$/', '', (string) $url );
		$u = rtrim( $u, '/' );
		return array(
			'dimension'  => 'page',
			'operator'   => 'includingRegex',
			// Googleov RE2: bježe se samo posebni znakovi izraza.
			'expression' => '^' . preg_replace( '/[\\.+*?()\[\]{}^$|]/', '\\\\$0', $u ) . '/?(\?.*)?(#.*)?$',
		);
	}

	/** Zbroj razdoblja iz dnevnih podataka. */
	private static function agg( $daily, $from, $to ) {
		$c = 0;
		$i = 0;
		$w = 0.0;
		foreach ( $daily as $day => $v ) {
			if ( $day < $from || $day > $to ) {
				continue;
			}
			$c += $v[0];
			$i += $v[1];
			$w += $v[2] * $v[1];
		}
		return array(
			'od'           => $from,
			'do'           => $to,
			'klikovi'      => $c,
			'prikazivanja' => $i,
			'ctr_posto'    => $i ? round( 100 * $c / $i, 2 ) : 0.0,
			'pozicija'     => $i ? round( $w / $i, 1 ) : null,
		);
	}

	/** Promjena u postocima (null kad nema polazne vrijednosti). */
	private static function pct( $after, $before ) {
		if ( $before <= 0 ) {
			return null;
		}
		return round( 100 * ( $after - $before ) / $before, 1 );
	}

	private static function nf( $x, $dec = 0 ) {
		return number_format( (float) $x, $dec, ',', '.' );
	}

	private static function signed( $pct ) {
		return null === $pct ? 'bez polazne vrijednosti' : ( $pct > 0 ? '+' : ( $pct < 0 ? '−' : '' ) ) . self::nf( abs( $pct ), 0 ) . ' %';
	}

	/**
	 * Ocjena jednog razdoblja. Glavna mjera ovisi o vrsti izmjene: CTR za naslov i opis, pozicija za upite blizu
	 * vrha, pozicije i kanibalizaciju. Naslov i opis sude se samo po CTR-u. Za ostale vrste, kad glavna mjera ne
	 * pokazuje jasan pomak, gledaju se klikovi stranice u odnosu na trend cijelog weba.
	 */
	public static function verdict( $vrsta, $b, $a, $site_pct, $qb = null, $qa = null ) {
		$min = self::MIN_IMPR;
		if ( $b['prikazivanja'] < $min && $a['prikazivanja'] < $min ) {
			return 'premalo';
		}
		$site = null === $site_pct ? 0.0 : (float) $site_pct;
		if ( $b['prikazivanja'] >= $min && $a['prikazivanja'] < $min ) {
			// Stranica je gotovo nestala iz rezultata: loše je ako web u cjelini nije pao slično.
			$ip = self::pct( $a['prikazivanja'], $b['prikazivanja'] );
			return null !== $ip && $ip - $site <= -40 ? 'losije' : 'premalo';
		}
		$use_q = $qb && $qa && $qb['prikazivanja'] >= $min && $qa['prikazivanja'] >= $min;
		$B     = $use_q ? $qb : $b;
		$A     = $use_q ? $qa : $a;
		// 1) Glavna mjera za tu vrstu izmjene.
		$main = 'isto';
		if ( 'ctr' === $vrsta && $B['prikazivanja'] >= $min ) {
			$d   = $A['ctr_posto'] - $B['ctr_posto'];
			$rel = $d / max( $B['ctr_posto'], 0.5 );
			if ( $rel >= 0.15 && $d >= 0.5 ) {
				$main = 'bolje';
			} elseif ( $rel <= -0.15 && $d <= -0.5 ) {
				$main = 'losije';
			}
		} elseif ( in_array( $vrsta, array( 'prilika', 'pozicije', 'kanibal' ), true ) && null !== $B['pozicija'] && null !== $A['pozicija'] ) {
			$d = $B['pozicija'] - $A['pozicija']; // + = bliže vrhu
			if ( $d >= 1.0 || ( $d >= 0.5 && $B['pozicija'] <= 5 ) ) {
				$main = 'bolje';
			} elseif ( $d <= -1.0 || ( $d <= -0.5 && $B['pozicija'] <= 5 ) ) {
				$main = 'losije';
			}
		}
		if ( 'ctr' === $vrsta ) {
			// Naslov i opis sude se samo po CTR-u: više klikova zbog više prikazivanja nije zasluga naslova.
			return $B['prikazivanja'] >= $min ? $main : 'premalo';
		}
		if ( 'isto' !== $main ) {
			return $main;
		}
		// 2) Ako glavna mjera ne pokazuje jasan pomak: klikovi stranice u odnosu na trend cijelog weba
		// (razlika u postotnim bodovima) uz stvarnu razliku od barem tri klika.
		$cp   = self::pct( $a['klikovi'], $b['klikovi'] );
		$diff = $a['klikovi'] - $b['klikovi'];
		if ( null === $cp ) {
			return $diff >= 3 ? 'bolje' : 'isto';
		}
		if ( $cp - $site >= 20 && $diff >= 3 ) {
			return 'bolje';
		}
		if ( $cp - $site <= -20 && $diff <= -3 ) {
			return 'losije';
		}
		return 'isto';
	}

	/** Rečenica s brojkama za jedno razdoblje. */
	private static function sentence( $n, $b, $a, $site_pct, $upit, $qb, $qa ) {
		$t = $n . ' dana prije i poslije: klikovi ' . self::nf( $b['klikovi'] ) . ' → ' . self::nf( $a['klikovi'] ) . ' (' . self::signed( self::pct( $a['klikovi'], $b['klikovi'] ) ) . ')';
		if ( null !== $site_pct ) {
			$t .= ', cijeli web ' . self::signed( $site_pct );
		}
		$t .= '. CTR ' . self::nf( $b['ctr_posto'], 1 ) . ' % → ' . self::nf( $a['ctr_posto'], 1 ) . ' %';
		if ( null !== $b['pozicija'] && null !== $a['pozicija'] ) {
			$t .= ', pozicija ' . self::nf( $b['pozicija'], 1 ) . ' → ' . self::nf( $a['pozicija'], 1 );
		}
		$t .= '.';
		if ( $upit && $qb && $qa && ( $qb['prikazivanja'] || $qa['prikazivanja'] ) ) {
			$t .= ' Upit „' . $upit . '”: ';
			if ( null !== $qb['pozicija'] && null !== $qa['pozicija'] ) {
				$t .= 'pozicija ' . self::nf( $qb['pozicija'], 1 ) . ' → ' . self::nf( $qa['pozicija'], 1 ) . ', ';
			}
			$t .= 'klikovi ' . self::nf( $qb['klikovi'] ) . ' → ' . self::nf( $qa['klikovi'] ) . '.';
		}
		return $t;
	}

	/** Zadnji dan s konačnim Google podacima (ili WP_Error). */
	private static function final_date() {
		$today = wp_date( 'Y-m-d' );
		$rows  = self::daily( self::shift( $today, -12 ), $today );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! $rows ) {
			return new WP_Error( 'olr_ucinak_prazno', 'Search Console još nema konačnih podataka za zadnjih 12 dana.' );
		}
		$keys = array_keys( $rows );
		return end( $keys );
	}

	/**
	 * Izračun svih razdoblja za jedan prijedlog iz dnevnih podataka.
	 *
	 * @param array      $doc   Zapis (id, vrsta, provedeno, upit…).
	 * @param array      $page  Dnevni podaci stranice.
	 * @param array|null $query Dnevni podaci stranice za glavni upit.
	 * @param array      $site  Dnevni podaci cijelog weba.
	 * @param string     $final Zadnji dan s konačnim podacima.
	 */
	public static function compute( $doc, $page, $query, $site, $final ) {
		$d     = $doc['provedeno'];
		$after = max( 0, self::days_between( $d, $final ) );
		$doc['dana_poslije'] = $after;
		$doc['gsc_do']       = $final;
		$doc['polazno']      = self::agg( $page, self::shift( $d, -28 ), self::shift( $d, -1 ) );
		$doc['prozori']      = array();
		$best                = null;
		foreach ( self::WINDOWS as $n ) {
			if ( $after < $n ) {
				continue;
			}
			$bf = array( self::shift( $d, -$n ), self::shift( $d, -1 ) );
			$af = array( self::shift( $d, 1 ), self::shift( $d, $n ) );
			$b  = self::agg( $page, $bf[0], $bf[1] );
			$a  = self::agg( $page, $af[0], $af[1] );
			$sb = self::agg( $site, $bf[0], $bf[1] );
			$sa = self::agg( $site, $af[0], $af[1] );
			$sp = self::pct( $sa['klikovi'], $sb['klikovi'] );
			$qb = is_array( $query ) ? self::agg( $query, $bf[0], $bf[1] ) : null;
			$qa = is_array( $query ) ? self::agg( $query, $af[0], $af[1] ) : null;
			$w  = array(
				'dana'    => $n,
				'prije'   => $b,
				'poslije' => $a,
				'web'     => array(
					'klikovi_prije'   => $sb['klikovi'],
					'klikovi_poslije' => $sa['klikovi'],
					'promjena_posto'  => $sp,
				),
				'ocjena'  => self::verdict( $doc['vrsta'], $b, $a, $sp, $qb, $qa ),
				'sazetak' => self::sentence( $n, $b, $a, $sp, isset( $doc['upit'] ) ? $doc['upit'] : '', $qb, $qa ),
			);
			if ( $qb && $qa ) {
				$w['upit'] = array(
					'prije'   => $qb,
					'poslije' => $qa,
				);
			}
			$doc['prozori'][ (string) $n ] = $w;
			$best                          = $w;
		}
		if ( $after >= 28 ) {
			$doc['stanje'] = 'gotovo';
		} elseif ( $best ) {
			$doc['stanje'] = 'mjeri';
		} else {
			$doc['stanje'] = 'ceka';
		}
		$doc['ocjena']    = $best ? $best['ocjena'] : null;
		$doc['sazetak']   = $best
			? $best['sazetak'] . ( 'gotovo' === $doc['stanje'] ? '' : ' Privremeno: konačna usporedba je nakon 28 dana.' )
			: 'Mjeri se: prošlo je ' . $after . ' od 7 dana potrebnih za prvu usporedbu. Polazno stanje (28 dana prije): ' . self::nf( $doc['polazno']['klikovi'] ) . ' klikova, ' . self::nf( $doc['polazno']['prikazivanja'] ) . ' prikazivanja.';
		$doc['azurirano'] = time() * 1000;
		return $doc;
	}

	/**
	 * Uskladi zapise s prijedlozima i izračunaj što je novo. Poziva se u pozadini.
	 *
	 * @return array|WP_Error Sažetak prolaza.
	 */
	public static function sync() {
		$t0   = time();
		$docs = self::docs();
		$todo = array();
		$seen = array();
		foreach ( OLR_Prijedlozi::all() as $p ) {
			if ( 'provedeno' !== $p['stanje'] ) {
				continue;
			}
			$seen[ $p['id'] ] = true;
			$day              = self::date_of( $p );
			$doc              = isset( $docs[ $p['id'] ] ) && $docs[ $p['id'] ]['provedeno'] === $day ? $docs[ $p['id'] ] : array();
			$doc              = array_merge(
				$doc,
				array(
					'id'        => $p['id'],
					'kljuc'     => $p['kljuc'],
					'vrsta'     => $p['vrsta'],
					'put'       => $p['put'],
					'url'       => $p['url'],
					'upit'      => isset( $p['upit'] ) ? (string) $p['upit'] : '',
					'provedeno' => $day,
				)
			);
			$why = self::unmeasurable( $p );
			if ( $why ) {
				$doc['stanje']  = 'nemjerljivo';
				$doc['razlog']  = $why;
				$doc['ocjena']  = null;
				$doc['sazetak'] = $why;
				if ( ! isset( $docs[ $p['id'] ] ) || $docs[ $p['id'] ] != $doc ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- usporedba sadržaja polja
					OLR_Store::set( self::COLL, $p['id'], $doc );
				}
				continue;
			}
			if ( isset( $doc['stanje'] ) && 'gotovo' === $doc['stanje'] ) {
				continue;
			}
			$todo[ $p['id'] ] = $doc;
		}
		// Prijedlog više nije „provedeno”: zapis učinka se uklanja.
		foreach ( $docs as $id => $d ) {
			if ( ! isset( $seen[ $id ] ) ) {
				OLR_Store::delete( self::COLL, $id );
			}
		}
		$run = array(
			'at'      => time(),
			'mjereno' => 0,
			'ceka'    => count( $todo ),
		);
		if ( ! $todo ) {
			update_option( self::RUN_OPT, $run, false );
			return $run;
		}
		if ( ! OLR_Settings::secret( 'gsc_sa' ) ) {
			$e = new WP_Error( 'olr_ucinak_gsc', 'Za mjerenje učinka treba Google servisni račun (Postavke).' );
			OLR_Settings::set_status( 'ucinak', false, $e->get_error_message() );
			return $e;
		}
		$final = self::final_date();
		if ( is_wp_error( $final ) ) {
			OLR_Settings::set_status( 'ucinak', false, $final->get_error_message() );
			update_option( self::RUN_OPT, $run, false );
			return $final;
		}
		$min = $final;
		foreach ( $todo as $doc ) {
			$min = min( $min, $doc['provedeno'] );
		}
		$site = self::daily( self::shift( $min, -28 ), $final );
		if ( is_wp_error( $site ) ) {
			OLR_Settings::set_status( 'ucinak', false, $site->get_error_message() );
			update_option( self::RUN_OPT, $run, false );
			return $site;
		}
		$err = '';
		foreach ( $todo as $id => $doc ) {
			if ( time() - $t0 > self::BUDGET ) {
				break;
			}
			if ( isset( $doc['gsc_do'], $doc['polazno'] ) && $doc['gsc_do'] === $final ) {
				continue; // nema novih konačnih podataka od zadnjeg izračuna
			}
			$from = self::shift( $doc['provedeno'], -28 );
			$pf   = self::page_filter( $doc['url'] );
			$page = self::daily( $from, $final, array( $pf ) );
			if ( is_wp_error( $page ) ) {
				$err = $page->get_error_message();
				break;
			}
			$query = null;
			if ( '' !== $doc['upit'] ) {
				$query = self::daily(
					$from,
					$final,
					array(
						$pf,
						array(
							'dimension'  => 'query',
							'operator'   => 'equals',
							'expression' => OLR_DFS::lower( $doc['upit'] ),
						),
					)
				);
				if ( is_wp_error( $query ) ) {
					$err = $query->get_error_message();
					break;
				}
			}
			OLR_Store::set( self::COLL, $id, self::compute( $doc, $page, $query, $site, $final ) );
			++$run['mjereno'];
		}
		$run['gsc_do'] = $final;
		update_option( self::RUN_OPT, $run, false );
		OLR_Settings::set_status(
			'ucinak',
			'' === $err,
			'' === $err
				? ( $run['mjereno'] ? 'učinak osvježen (izmjena: ' . $run['mjereno'] . '; Google podaci do ' . $final . ')' : 'nema novih konačnih Google podataka (do ' . $final . ')' )
				: $err
		);
		return $run;
	}

	/** Broj izmjena po ocjeni, za sažetak u ploči i MCP-u. */
	public static function counts( $docs = null ) {
		$out = array(
			'bolje'       => 0,
			'isto'        => 0,
			'losije'      => 0,
			'premalo'     => 0,
			'mjeri_se'    => 0,
			'nemjerljivo' => 0,
		);
		foreach ( null === $docs ? self::docs() : $docs as $d ) {
			if ( 'nemjerljivo' === $d['stanje'] ) {
				++$out['nemjerljivo'];
			} elseif ( 'gotovo' !== $d['stanje'] ) {
				++$out['mjeri_se'];
			} elseif ( isset( $out[ $d['ocjena'] ] ) ) {
				++$out[ $d['ocjena'] ];
			}
		}
		return $out;
	}
}
