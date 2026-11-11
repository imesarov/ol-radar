<?php
/**
 * On-site audit iz spremljenog crawla: nalazi po stranicama, dupli naslovi i opisi, pokvareni linkovi, siročad.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Audit {

	const SEV_ORDER = array(
		'visoka'  => 0,
		'srednja' => 1,
		'niska'   => 2,
	);

	/** Opisi kodova nalaza (za Claudea i admin stranicu). */
	public static function codes() {
		return array(
			'http_greska'          => array( 'visoka', 'Stranica iz popisa objava ne vraća 200.' ),
			'preusmjerenje'        => array( 'srednja', 'Objavljena stranica se preusmjerava (permalink nije konačni URL).' ),
			'pokvaren_link'        => array( 'visoka', 'Interni link vodi na stranicu koja vraća grešku (4xx/5xx ili nedostupno).' ),
			'link_na_preusmjerenje' => array( 'niska', 'Interni link vodi na preusmjerenje; bolje je linkati izravno na konačni URL.' ),
			'noindex'              => array( 'srednja', 'Stranica ima noindex (meta robots ili X-Robots-Tag).' ),
			'canonical_drugi'      => array( 'srednja', 'Canonical pokazuje na drugi URL.' ),
			'bez_canonical'        => array( 'niska', 'Nema canonical oznake.' ),
			'bez_naslova'          => array( 'visoka', 'Nema <title>.' ),
			'naslov_predug'        => array( 'niska', 'SEO naslov je dulji od 60 znakova.' ),
			'naslov_prekratak'     => array( 'niska', 'SEO naslov je kraći od 30 znakova.' ),
			'dupli_naslov'         => array( 'srednja', 'Isti SEO naslov ima više stranica.' ),
			'bez_opisa'            => array( 'srednja', 'Nema meta opisa.' ),
			'opis_predug'          => array( 'niska', 'Meta opis je dulji od 160 znakova.' ),
			'opis_prekratak'       => array( 'niska', 'Meta opis je kraći od 70 znakova.' ),
			'dupli_opis'           => array( 'srednja', 'Isti meta opis ima više stranica.' ),
			'bez_h1'               => array( 'srednja', 'Stranica nema H1.' ),
			'vise_h1'              => array( 'niska', 'Stranica ima više od jednog H1.' ),
			'tanak_sadrzaj'        => array( 'niska', 'Manje od 300 riječi sadržaja (bez zaglavlja i podnožja).' ),
			'slike_bez_alt'        => array( 'srednja', 'Slike bez alt atributa.' ),
			'slike_bez_dimenzija'  => array( 'niska', 'Slike bez width/height (rizik pomaka izgleda, CLS).' ),
			'bez_ulaznih_linkova'  => array( 'srednja', 'Nijedna druga stranica ne linka ovamo iz sadržaja dofollow linkom (samo izbornik/podnožje, nofollow ili ništa).' ),
			'bez_fokus_rijeci'     => array( 'niska', 'Rank Math fokus riječ nije postavljena.' ),
			'fokus_nije_u_naslovu' => array( 'niska', 'Fokus riječ nije u SEO naslovu.' ),
			'fokus_nije_u_opisu'   => array( 'niska', 'Fokus riječ nije u meta opisu.' ),
			'fokus_nije_u_h1'      => array( 'niska', 'Fokus riječ nije u H1.' ),
			'mijesani_sadrzaj'     => array( 'srednja', 'HTTPS stranica učitava resurse preko http://.' ),
			'bez_schema'           => array( 'niska', 'Nema strukturiranih podataka (JSON-LD).' ),
			'nije_dohvaceno'       => array( 'niska', 'Stranica još nije obrađena; pokreni osvježavanje.' ),
		);
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/**
	 * Gruba provjera prisutnosti fraze uz hrvatske padeže: svaka riječ fraze (3+ znaka) mora se pojaviti
	 * kao početak neke riječi u tekstu, uz dopušteno odstupanje nastavka (zadnja 2 znaka).
	 */
	public static function phrase_in( $phrase, $text ) {
		$phrase = trim( self::lower( $phrase ) );
		$text   = self::lower( $text );
		if ( '' === $phrase || '' === $text ) {
			return false;
		}
		if ( false !== strpos( $text, $phrase ) ) {
			return true;
		}
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $words as $w ) {
			$l = self::len( $w );
			if ( $l < 3 ) {
				continue;
			}
			$stem = function_exists( 'mb_substr' ) ? mb_substr( $w, 0, max( 3, $l - 2 ) ) : substr( $w, 0, max( 3, $l - 2 ) );
			if ( ! preg_match( '/(^|[^\p{L}\p{N}])' . preg_quote( $stem, '/' ) . '/u', $text ) ) {
				return false;
			}
		}
		return true;
	}

	/** Rank Math podaci iz baze za objavu. */
	public static function rank_math( $post_id ) {
		if ( ! $post_id ) {
			return null;
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$focus  = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		$all    = array_values( array_filter( array_map( 'trim', explode( ',', $focus ) ) ) );
		return array(
			'naslov'      => (string) get_post_meta( $post_id, 'rank_math_title', true ),
			'opis'        => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'fokus'       => $all ? $all[0] : '',
			'dodatne'     => array_slice( $all, 1 ),
			'robots'      => is_array( $robots ) ? array_values( $robots ) : array(),
			'canonical'   => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
			'pillar'      => 'on' === get_post_meta( $post_id, 'rank_math_pillar_content', true ),
			'schema_tip'  => (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true ),
		);
	}

	/** Pronađi red za URL uz varijante kose crte. */
	private static function lookup( $rows, $url ) {
		foreach ( OLR_Crawler::variants( $url ) as $v ) {
			if ( isset( $rows[ $v ] ) ) {
				return $rows[ $v ];
			}
		}
		return null;
	}

	/**
	 * Graf internih linkova: [ cilj => [ [od, sidro, kontekst], ... ] ].
	 */
	public static function inbound_map( $rows ) {
		$in = array();
		foreach ( $rows as $url => $r ) {
			if ( 'page' !== $r->kind || empty( $r->data['linkovi'] ) ) {
				continue;
			}
			$seen = array();
			foreach ( $r->data['linkovi'] as $l ) {
				$target = $l['u'];
				$hit    = self::lookup( $rows, $target );
				if ( $hit ) {
					$target = $hit->url;
				}
				if ( $target === $url ) {
					continue; // link na samu sebe
				}
				$key = $target . '|' . $l['k'] . '|' . ( empty( $l['nf'] ) ? 'f' : 'n' );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]    = true;
				$in[ $target ][] = array(
					'od'       => $url,
					'sidro'    => $l['a'],
					'kontekst' => 's' === $l['k'] ? 'sadrzaj' : 'navigacija',
					'nofollow' => ! empty( $l['nf'] ),
				);
			}
		}
		return $in;
	}

	/**
	 * Izračun nalaza.
	 *
	 * @param array $args { samo_url?: string, ozbiljnost?: string, kodovi?: string[], limit?: int }
	 */
	public static function run( $args = array() ) {
		$rows  = OLR_Crawler::all_rows();
		$pages = array_filter(
			$rows,
			static function ( $r ) {
				return 'page' === $r->kind;
			}
		);
		$home  = OLR_Crawler::normalize( home_url( '/' ) );
		$in    = self::inbound_map( $rows );
		$codes = self::codes();

		// Dupli naslovi i opisi.
		$titles = array();
		$descs  = array();
		foreach ( $pages as $u => $r ) {
			if ( 200 !== (int) $r->status ) {
				continue;
			}
			if ( ! empty( $r->data['naslov'] ) ) {
				$titles[ self::lower( $r->data['naslov'] ) ][] = $u;
			}
			if ( ! empty( $r->data['opis'] ) ) {
				$descs[ self::lower( $r->data['opis'] ) ][] = $u;
			}
		}

		$items = array();
		$add   = static function ( $code, $url, $post_id, $detail = null ) use ( &$items, $codes ) {
			$items[] = array(
				'kod'        => $code,
				'ozbiljnost' => $codes[ $code ][0],
				'opis'       => $codes[ $code ][1],
				'url'        => $url,
				'post_id'    => (int) $post_id,
				'detalj'     => $detail,
			);
		};

		foreach ( $pages as $u => $r ) {
			$pid = (int) $r->post_id;
			$d   = $r->data;
			if ( null === $r->fetched_at ) {
				$add( 'nije_dohvaceno', $u, $pid );
				continue;
			}
			$st = (int) $r->status;
			if ( $st >= 300 && $st < 400 ) {
				$add( 'preusmjerenje', $u, $pid, array( 'status' => $st, 'na' => isset( $d['lokacija'] ) ? $d['lokacija'] : null ) );
				continue;
			}
			if ( 200 !== $st ) {
				$add( 'http_greska', $u, $pid, array( 'status' => $st, 'greska' => isset( $d['greska'] ) ? $d['greska'] : null ) );
				continue;
			}

			$robots = trim( ( isset( $d['robots'] ) ? $d['robots'] : '' ) . ' ' . ( isset( $d['x_robots'] ) ? strtolower( $d['x_robots'] ) : '' ) );
			if ( false !== strpos( $robots, 'noindex' ) ) {
				$add( 'noindex', $u, $pid, array( 'robots' => $robots ) );
			}
			if ( empty( $d['canonical'] ) ) {
				$add( 'bez_canonical', $u, $pid );
			} elseif ( ! in_array( $d['canonical'], OLR_Crawler::variants( $u ), true ) ) {
				$add( 'canonical_drugi', $u, $pid, array( 'canonical' => $d['canonical'] ) );
			}

			$title = isset( $d['naslov'] ) ? (string) $d['naslov'] : '';
			if ( '' === $title ) {
				$add( 'bez_naslova', $u, $pid );
			} else {
				$tl = self::len( $title );
				if ( $tl > 60 ) {
					$add( 'naslov_predug', $u, $pid, array( 'naslov' => $title, 'znakova' => $tl ) );
				} elseif ( $tl < 30 ) {
					$add( 'naslov_prekratak', $u, $pid, array( 'naslov' => $title, 'znakova' => $tl ) );
				}
				$dup = $titles[ self::lower( $title ) ];
				if ( count( $dup ) > 1 ) {
					$add( 'dupli_naslov', $u, $pid, array( 'naslov' => $title, 'isto_na' => array_values( array_diff( $dup, array( $u ) ) ) ) );
				}
			}

			$desc = isset( $d['opis'] ) ? (string) $d['opis'] : '';
			if ( '' === $desc ) {
				$add( 'bez_opisa', $u, $pid );
			} else {
				$dl = self::len( $desc );
				if ( $dl > 160 ) {
					$add( 'opis_predug', $u, $pid, array( 'znakova' => $dl ) );
				} elseif ( $dl < 70 ) {
					$add( 'opis_prekratak', $u, $pid, array( 'opis' => $desc, 'znakova' => $dl ) );
				}
				$dup = $descs[ self::lower( $desc ) ];
				if ( count( $dup ) > 1 ) {
					$add( 'dupli_opis', $u, $pid, array( 'isto_na' => array_values( array_diff( $dup, array( $u ) ) ) ) );
				}
			}

			$h1 = isset( $d['h1'] ) ? $d['h1'] : array();
			if ( 0 === count( $h1 ) ) {
				$add( 'bez_h1', $u, $pid );
			} elseif ( count( $h1 ) > 1 ) {
				$add( 'vise_h1', $u, $pid, array( 'h1' => $h1 ) );
			}

			if ( $u !== $home && isset( $d['rijeci'] ) && (int) $d['rijeci'] < 300 ) {
				$add( 'tanak_sadrzaj', $u, $pid, array( 'rijeci' => (int) $d['rijeci'] ) );
			}

			$no_alt = array();
			$no_dim = array();
			foreach ( isset( $d['slike'] ) ? $d['slike'] : array() as $img ) {
				if ( null === $img['alt'] ) {
					$no_alt[] = $img['src'];
				}
				if ( '' === (string) $img['w'] || '' === (string) $img['h'] ) {
					if ( ! preg_match( '#\.svg(\?|$)#i', $img['src'] ) ) {
						$no_dim[] = $img['src'];
					}
				}
			}
			if ( $no_alt ) {
				$add( 'slike_bez_alt', $u, $pid, array( 'broj' => count( $no_alt ), 'slike' => array_slice( array_values( array_unique( $no_alt ) ), 0, 15 ) ) );
			}
			if ( $no_dim ) {
				$add( 'slike_bez_dimenzija', $u, $pid, array( 'broj' => count( $no_dim ), 'slike' => array_slice( array_values( array_unique( $no_dim ) ), 0, 15 ) ) );
			}

			// Linkovi s ove stranice.
			$broken = array();
			$redir  = array();
			$seen   = array();
			foreach ( isset( $d['linkovi'] ) ? $d['linkovi'] : array() as $l ) {
				if ( isset( $seen[ $l['u'] ] ) ) {
					continue;
				}
				$seen[ $l['u'] ] = true;
				$t               = self::lookup( $rows, $l['u'] );
				if ( ! $t || null === $t->fetched_at ) {
					continue; // još nije provjereno
				}
				$ts = (int) $t->status;
				if ( 0 === $ts || $ts >= 400 ) {
					$broken[] = array(
						'na'     => $l['u'],
						'sidro'  => $l['a'],
						'status' => $ts,
					);
				} elseif ( $ts >= 300 ) {
					$redir[] = array(
						'na'     => $l['u'],
						'sidro'  => $l['a'],
						'status' => $ts,
						'konacno' => isset( $t->data['lokacija'] ) ? $t->data['lokacija'] : null,
					);
				}
			}
			if ( $broken ) {
				$add( 'pokvaren_link', $u, $pid, array( 'linkovi' => array_slice( $broken, 0, 20 ) ) );
			}
			if ( $redir ) {
				$add( 'link_na_preusmjerenje', $u, $pid, array( 'linkovi' => array_slice( $redir, 0, 20 ) ) );
			}

			// Ulazni linkovi iz sadržaja drugih stranica.
			if ( $u !== $home ) {
				$ctx = 0;
				$nav = 0;
				$nf  = 0;
				foreach ( isset( $in[ $u ] ) ? $in[ $u ] : array() as $link ) {
					if ( 'sadrzaj' !== $link['kontekst'] ) {
						++$nav;
					} elseif ( $link['nofollow'] ) {
						++$nf;
					} else {
						++$ctx;
					}
				}
				if ( 0 === $ctx ) {
					$add( 'bez_ulaznih_linkova', $u, $pid, array( 'iz_navigacije' => $nav, 'nofollow_iz_sadrzaja' => $nf ) );
				}
			}

			// Rank Math fokus riječ.
			if ( $pid ) {
				$rm = self::rank_math( $pid );
				if ( '' === $rm['fokus'] ) {
					$add( 'bez_fokus_rijeci', $u, $pid );
				} else {
					if ( '' !== $title && ! self::phrase_in( $rm['fokus'], $title ) ) {
						$add( 'fokus_nije_u_naslovu', $u, $pid, array( 'fokus' => $rm['fokus'], 'naslov' => $title ) );
					}
					if ( '' !== $desc && ! self::phrase_in( $rm['fokus'], $desc ) ) {
						$add( 'fokus_nije_u_opisu', $u, $pid, array( 'fokus' => $rm['fokus'] ) );
					}
					if ( $h1 && ! self::phrase_in( $rm['fokus'], implode( ' ', $h1 ) ) ) {
						$add( 'fokus_nije_u_h1', $u, $pid, array( 'fokus' => $rm['fokus'], 'h1' => $h1 ) );
					}
				}
			}

			if ( ! empty( $d['mijesani_sadrzaj'] ) ) {
				$add( 'mijesani_sadrzaj', $u, $pid, array( 'resursa' => (int) $d['mijesani_sadrzaj'] ) );
			}
			if ( empty( $d['schema'] ) ) {
				$add( 'bez_schema', $u, $pid );
			}
		}

		// Filtri.
		if ( ! empty( $args['samo_url'] ) ) {
			$want  = OLR_Crawler::variants( $args['samo_url'] );
			$items = array_values(
				array_filter(
					$items,
					static function ( $i ) use ( $want ) {
						return in_array( $i['url'], $want, true );
					}
				)
			);
		}
		if ( ! empty( $args['ozbiljnost'] ) && isset( self::SEV_ORDER[ $args['ozbiljnost'] ] ) ) {
			$max   = self::SEV_ORDER[ $args['ozbiljnost'] ];
			$items = array_values(
				array_filter(
					$items,
					static function ( $i ) use ( $max ) {
						return self::SEV_ORDER[ $i['ozbiljnost'] ] <= $max;
					}
				)
			);
		}
		if ( ! empty( $args['kodovi'] ) && is_array( $args['kodovi'] ) ) {
			$ok    = $args['kodovi'];
			$items = array_values(
				array_filter(
					$items,
					static function ( $i ) use ( $ok ) {
						return in_array( $i['kod'], $ok, true );
					}
				)
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				$s = self::SEV_ORDER[ $a['ozbiljnost'] ] - self::SEV_ORDER[ $b['ozbiljnost'] ];
				return 0 !== $s ? $s : strcmp( $a['kod'] . $a['url'], $b['kod'] . $b['url'] );
			}
		);

		$summary = array();
		foreach ( $items as $i ) {
			$summary[ $i['kod'] ] = isset( $summary[ $i['kod'] ] ) ? $summary[ $i['kod'] ] + 1 : 1;
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 1000, (int) $args['limit'] ) ) : 300;

		return array(
			'stanje_crawla' => OLR_Crawler::stats(),
			'ukupno_nalaza' => count( $items ),
			'po_kodu'       => $summary,
			'nalazi'        => array_slice( $items, 0, $limit ),
			'skraceno'      => count( $items ) > $limit,
		);
	}
}
