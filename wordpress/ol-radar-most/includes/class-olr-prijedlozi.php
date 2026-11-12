<?php
/**
 * Dnevni prijedlozi izmjena.
 *
 * Nakon novih Google podataka server sam pokrene analizu (OLR_Analiza), odabere najvažnije nalaze koji
 * još nemaju otvoren prijedlog i za njih jednim pozivom Claudea (Anthropic API) napiše gotov tekst
 * izmjene. Brojke u prijedlogu uvijek su iz podataka, a ne iz odgovora Claudea. Bez API ključa (ili ako
 * poziv ne uspije) prijedlog se sprema s gotovim koracima, ali bez napisanog teksta.
 *
 * Prijedlozi se samo spremaju i prikazuju. Web se ne mijenja.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Prijedlozi {

	const COLL       = 'prijedlozi';
	const STATE_COLL = 'prijedlozi_stanje';
	const RUN_OPT    = 'olr_prijedlozi_run';
	const LOCK       = 'olr_prijedlozi_lock';
	const EVENT      = 'olr_prijedlozi_event';
	const MANUAL_MAX = 6;
	const STANJA     = array( 'novo', 'prihvaceno', 'provedeno', 'odbijeno' );
	const POLJA      = array( 'seo_naslov', 'meta_opis', 'h1', 'odlomak', 'interni_link', 'novi_clanak', 'tehnicki' );

	public static function init() {
		add_action( 'olr_gsc_collected', array( __CLASS__, 'on_gsc' ) );
		add_action( self::EVENT, array( __CLASS__, 'cron' ) );
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	private static function cut( $s, $n ) {
		$s = trim( wp_strip_all_tags( (string) $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	public static function last_run() {
		$r = get_option( self::RUN_OPT );
		return is_array( $r ) ? $r : array();
	}

	/** Treba li automatska analiza: uključena je, ima novih Google podataka i zadnji pokušaj nije bio nedavno. */
	public static function due() {
		if ( ! OLR_Settings::get( 'prijedlozi_auto' ) ) {
			return false;
		}
		$meta = OLR_Store::data( 'gsc', 'meta' );
		if ( ! $meta || empty( $meta['do'] ) ) {
			return false;
		}
		$run = self::last_run();
		if ( ! empty( $run['auto_at'] ) && time() - (int) $run['auto_at'] < 3 * HOUR_IN_SECONDS ) {
			return false;
		}
		return empty( $run['gsc_do'] ) || $run['gsc_do'] !== $meta['do'];
	}

	/** Novi Google podaci: analiza se zakazuje u pozadini, da onaj tko je kliknuo „preuzmi” ne čeka Claudea. */
	public static function on_gsc() {
		if ( self::due() && ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + 20, self::EVENT );
		}
	}

	public static function cron() {
		if ( self::due() ) {
			self::run( 'auto' );
		}
	}

	/** Stanja koja je postavio korisnik: id => array( stanje, at, biljeska ). */
	private static function states() {
		$out = array();
		foreach ( OLR_Store::list_docs( self::STATE_COLL ) as $d ) {
			if ( is_array( $d['data'] ) && ! empty( $d['data']['stanje'] ) && in_array( $d['data']['stanje'], self::STANJA, true ) ) {
				$out[ $d['id'] ] = $d['data'];
			}
		}
		return $out;
	}

	/** Svi prijedlozi sa stanjem, najnoviji prvi. */
	public static function all() {
		$states = self::states();
		$out    = array();
		foreach ( OLR_Store::list_docs( self::COLL, '', 'asc', 2000 ) as $d ) {
			$p = $d['data'];
			if ( ! is_array( $p ) || empty( $p['id'] ) ) {
				continue;
			}
			$st            = isset( $states[ $p['id'] ] ) ? $states[ $p['id'] ] : array();
			$p['stanje']   = isset( $st['stanje'] ) ? $st['stanje'] : 'novo';
			$p['stanje_at'] = isset( $st['at'] ) ? (int) $st['at'] : null;
			$p['biljeska'] = isset( $st['biljeska'] ) ? (string) $st['biljeska'] : '';
			$out[]         = $p;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( $b['izradjeno'] <=> $a['izradjeno'] ) ?: ( $b['ucinak'] <=> $a['ucinak'] );
			}
		);
		return $out;
	}

	public static function is_open( $p ) {
		return empty( $p['isteklo'] ) && in_array( $p['stanje'], array( 'novo', 'prihvaceno' ), true );
	}

	/** Ključevi nalaza za koje sada ne treba novi prijedlog (otvoren je, nedavno odbijen ili nedavno proveden). */
	private static function blocked_keys( $all ) {
		$now = time();
		$out = array();
		foreach ( $all as $p ) {
			$at = ! empty( $p['stanje_at'] ) ? (int) ( $p['stanje_at'] / 1000 ) : (int) ( $p['izradjeno'] / 1000 );
			if ( self::is_open( $p )
				|| ( 'odbijeno' === $p['stanje'] && $now - $at < 45 * DAY_IN_SECONDS )
				|| ( 'provedeno' === $p['stanje'] && $now - $at < 28 * DAY_IN_SECONDS ) ) {
				$out[ $p['kljuc'] ] = true;
			}
		}
		return $out;
	}

	/* ---------- tekst bez Claudea ---------- */

	private static function fallback( $c ) {
		$d = $c['dokazi'];
		$f = $c['stranica'] ? $c['stranica'] : array();
		$q = isset( $d['upiti'][0] ) ? $d['upiti'][0] : array();
		switch ( $c['vrsta'] ) {
			case 'ctr':
				return array(
					'naslov' => 'Novi naslov i opis za ' . $c['put'],
					'zasto'  => sprintf( 'Za „%s” stranica je na poziciji %s s %d prikazivanja u 28 dana, a klikne tek %s %% (uobičajeno je oko %s %%).', $q['upit'], str_replace( '.', ',', (string) $q['pozicija'] ), $q['prikazivanja'], str_replace( '.', ',', (string) $q['ctr_posto'] ), str_replace( '.', ',', (string) $q['ocekivano_posto'] ) ),
					'koraci' => array( 'Napiši novi SEO naslov (do 60 znakova) s glavnom frazom na početku.', 'Napiši meta opis (120–155 znakova) koji kaže što posjetitelj dobiva i poziva na radnju.', 'Spremi u Rank Mathu i za 2–3 tjedna usporedi CTR u Search Consoleu.' ),
				);
			case 'prilika':
				return array(
					'naslov' => 'Pojačati ' . $c['put'] . ' za „' . $q['upit'] . '”',
					'zasto'  => sprintf( '„%s” je na poziciji %s s %d prikazivanja u 28 dana; do 3. mjesta dijeli nas malo.', $q['upit'], str_replace( '.', ',', (string) $q['pozicija'] ), $q['prikazivanja'] ),
					'koraci' => array( 'Dodaj odjeljak (H2) koji izravno odgovara na upit.', 'Provjeri je li fraza u SEO naslovu i H1.', 'Dodaj 2–3 interna linka s povezanih stranica.' ),
				);
			case 'kanibal':
				return array(
					'naslov' => 'Dvije stranice dijele upit „' . $d['upit'] . '”',
					'zasto'  => 'U zadnjih 7 dana za isti upit Google prikazuje više naših stranica. Glavna bi trebala biti ' . $d['glavna_stranica'] . '.',
					'koraci' => array( 'Na sporednoj stranici dodaj istaknut link na glavnu.', 'Sporednoj stranici promijeni naslov tako da ne cilja istu frazu.', 'Ako sadržaj dijelom ponavlja glavnu stranicu, premjesti ga ili skrati.' ),
				);
			case 'pad':
				return array(
					'naslov' => 'Pad za „' . $d['upit'] . '”',
					'zasto'  => sprintf( 'Prošli tjedan %d klikova i %d prikazivanja, ovaj tjedan %d klikova i %d prikazivanja.', $d['prosli_tjedan']['klikovi'], $d['prosli_tjedan']['prikazivanja'], $d['ovaj_tjedan']['klikovi'], $d['ovaj_tjedan']['prikazivanja'] ),
					'koraci' => array( 'Provjeri je li stranica nedavno mijenjana i je li i dalje u indeksu.', 'Pogledaj tko je u rezultatima iznad nas (modul Pozicije / Konkurencija).', 'Ako je pad sezonski (isti tjedan lani), samo prati.' ),
				);
			case 'pozicije':
				return array(
					'naslov' => 'Ciljana riječ „' . $d['rijec'] . '”: ' . $d['nalaz'],
					'zasto'  => 'Mjerenje pozicija: sada ' . ( null === $d['pozicija'] ? 'nije pronađena' : $d['pozicija'] . '. mjesto' ) . ', prije ' . ( null === $d['prethodna_pozicija'] ? 'nije mjerena' : $d['prethodna_pozicija'] . '. mjesto' ) . '.',
					'koraci' => array( 'Provjeri stranicu koja rangira i njezin naslov.', 'Usporedi s rezultatima iznad nas.', 'Pojačaj interne linkove prema stranici koja treba rangirati.' ),
				);
			case 'novo':
				return array(
					'naslov' => 'Nova stranica za „' . $d['upit'] . '”',
					'zasto'  => sprintf( '„%s” ima %d prikazivanja u 28 dana na poziciji %s, a %s.', $d['upit'], $d['prikazivanja'], str_replace( '.', ',', (string) $d['pozicija'] ), $d['razlog'] ),
					'koraci' => array( 'Dodaj temu u planer sadržaja.', 'Napiši stranicu koja izravno odgovara na upit.', 'Poveži je internim linkovima s povezanih stranica.' ),
				);
			default:
				if ( ! empty( $d['broj'] ) ) {
					return array(
						'naslov' => rtrim( $d['opis'], '.' ) . ' – ' . $d['broj'] . ' stranica',
						'zasto'  => 'Nalaz crawla weba na ' . $d['broj'] . ' stranica: ' . $d['opis'] . ' Najposjećenije su navedene u dokazima.',
						'koraci' => array( 'Kreni od stranica s najviše klikova.', 'Nakon izmjene pričekaj sljedeći crawl; riješene stranice nestaju iz nalaza same.' ),
					);
				}
				return array(
					'naslov' => $d['opis'] . ' (' . $c['put'] . ')',
					'zasto'  => 'Nalaz crawla weba: ' . $d['opis'],
					'koraci' => array( 'Ispravi nalaz na stranici ' . $c['put'] . '.', 'Nakon izmjene pričekaj sljedeći crawl; nalaz nestaje sam.' ),
				);
		}
	}

	/* ---------- Claude ---------- */

	private static function system_prompt() {
		return "[zadatak: dnevni prijedlozi]\n"
			. "Ti si SEO urednik za optikaljiljana.hr (Optika Ljiljana, obiteljska optika u Sesvetama kod Zagreba, od 1993.; optometrist, pregled vida, dioptrijske i progresivne naočale, kontrola kratkovidnosti kod djece).\n"
			. "Za svaki nalaz iz ulaza napiši gotov prijedlog izmjene koji vlasnik može odmah provesti.\n\n"
			. "Pravila:\n"
			. "- Piši hrvatski, prirodno i konkretno, bez marketinških fraza i bez uskličnika.\n"
			. "- Koristi samo činjenice i brojke iz ulaza. Ne izmišljaj cijene, popuste, rokove, jamstva, medicinske tvrdnje ni podatke o konkurenciji.\n"
			. "- seo_naslov: najviše 60 znakova, glavna fraza što bliže početku, naziv tvrtke najviše jednom.\n"
			. "- meta_opis: 120 do 155 znakova; kaže što posjetitelj dobiva i završava pozivom na radnju.\n"
			. "- interni_link: izvor smije biti samo putanja iz popisa izvori_linka; u polje novo upiši tekst sidra, u polje gdje putanju izvora.\n"
			. "- odlomak: naslov odjeljka (H2) i 2 do 4 rečenice ili natuknice što treba pisati; ne piši cijeli članak.\n"
			. "- novi_clanak: radni naslov i 4 do 6 podnaslova.\n"
			. "- tehnicki: točno što promijeniti i gdje.\n"
			. "- zasto: jedna do dvije rečenice s brojkama iz ulaza.\n"
			. "- Najviše 4 izmjene i 4 koraka po prijedlogu.\n"
			. "- Vrati isključivo JSON, bez teksta prije ili poslije i bez ograde koda.\n\n"
			. 'Oblik: {"prijedlozi":[{"id":"…","naslov":"…","zasto":"…","izmjene":[{"polje":"seo_naslov|meta_opis|h1|odlomak|interni_link|novi_clanak|tehnicki","gdje":"…","novo":"…"}],"koraci":["…"]}]}';
	}

	/** Ulaz za Claudea: samo ono što treba za tekst (bez internih polja). */
	private static function ai_input( $chosen ) {
		$items = array();
		foreach ( $chosen as $id => $c ) {
			$item = array(
				'id'       => $id,
				'vrsta'    => $c['vrsta'],
				'opis_vrste' => OLR_Analiza::VRSTE[ $c['vrsta'] ],
				'stranica' => $c['stranica'],
				'dokazi'   => $c['dokazi'],
			);
			foreach ( array( 'izvori_linka', 'druge_stranice' ) as $k ) {
				if ( ! empty( $c[ $k ] ) ) {
					$item[ $k ] = $c[ $k ];
				}
			}
			$items[] = $item;
		}
		return wp_json_encode(
			array(
				'datum'  => gmdate( 'Y-m-d' ),
				'nalazi' => $items,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/** Iz odgovora Claudea vadi JSON; vraća polje po id-u ili WP_Error. */
	private static function parse_ai( $res ) {
		$text = '';
		foreach ( isset( $res['content'] ) ? (array) $res['content'] : array() as $b ) {
			if ( isset( $b['type'] ) && 'text' === $b['type'] ) {
				$text .= $b['text'];
			}
		}
		$a = strpos( $text, '{' );
		$z = strrpos( $text, '}' );
		if ( false === $a || false === $z || $z <= $a ) {
			return new WP_Error( 'olr_prijedlozi_json', 'Claude nije vratio JSON.' );
		}
		$j = json_decode( substr( $text, $a, $z - $a + 1 ), true );
		if ( ! is_array( $j ) || empty( $j['prijedlozi'] ) || ! is_array( $j['prijedlozi'] ) ) {
			return new WP_Error( 'olr_prijedlozi_json', 'Odgovor Claudea nije u očekivanom obliku' . ( isset( $res['stop_reason'] ) && 'max_tokens' === $res['stop_reason'] ? ' (prekinut zbog duljine)' : '' ) . '.' );
		}
		$out = array();
		foreach ( $j['prijedlozi'] as $p ) {
			if ( is_array( $p ) && ! empty( $p['id'] ) && is_string( $p['id'] ) ) {
				$out[ $p['id'] ] = $p;
			}
		}
		return $out;
	}

	/** Provjera i čišćenje izmjena koje je napisao Claude; „sada” uvijek dolazi iz crawla, ne iz odgovora. */
	private static function clean_changes( $changes, $c ) {
		$f       = $c['stranica'] ? $c['stranica'] : array();
		$sources = array();
		foreach ( isset( $c['izvori_linka'] ) ? $c['izvori_linka'] : array() as $s ) {
			$sources[ $s['put'] ] = true;
		}
		$out = array();
		foreach ( array_slice( (array) $changes, 0, 4 ) as $ch ) {
			if ( ! is_array( $ch ) || empty( $ch['polje'] ) || ! in_array( $ch['polje'], self::POLJA, true ) || empty( $ch['novo'] ) || ! is_string( $ch['novo'] ) ) {
				continue;
			}
			$polje = $ch['polje'];
			$novo  = self::cut( $ch['novo'], 1500 );
			$gdje  = isset( $ch['gdje'] ) && is_string( $ch['gdje'] ) ? self::cut( $ch['gdje'], 200 ) : '';
			if ( '' === $novo ) {
				continue;
			}
			$item = array(
				'polje' => $polje,
				'gdje'  => $gdje,
				'novo'  => $novo,
			);
			if ( 'seo_naslov' === $polje ) {
				$item['sada'] = isset( $f['naslov'] ) ? $f['naslov'] : '';
				$n            = self::len( $novo );
				if ( $n > 60 ) {
					$item['upozorenje'] = 'Naslov ima ' . $n . ' znakova (preporuka do 60); Google ga može skratiti.';
				}
			} elseif ( 'meta_opis' === $polje ) {
				$item['sada'] = isset( $f['opis'] ) ? $f['opis'] : '';
				$n            = self::len( $novo );
				if ( $n > 160 || $n < 70 ) {
					$item['upozorenje'] = 'Opis ima ' . $n . ' znakova (preporuka 120–155).';
				}
			} elseif ( 'h1' === $polje ) {
				$item['sada'] = isset( $f['h1'] ) ? $f['h1'] : '';
			} elseif ( 'interni_link' === $polje ) {
				$src = OLR_Analiza::path( $gdje );
				if ( ! isset( $sources[ $src ] ) && ! isset( $sources[ $gdje ] ) ) {
					continue; // Claude je predložio stranicu koje nema u popisu izvora.
				}
				$item['gdje'] = isset( $sources[ $gdje ] ) ? $gdje : $src;
			}
			$out[] = $item;
		}
		return $out;
	}

	/* ---------- pokretanje ---------- */

	/**
	 * Analiza i izrada prijedloga.
	 *
	 * @param string $how auto (cron) ili rucno (gumb).
	 * @return array|WP_Error Sažetak pokretanja.
	 */
	public static function run( $how = 'auto' ) {
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'olr_prijedlozi_u_tijeku', 'Analiza je već u tijeku. Pokušaj za minutu.' );
		}
		$run   = self::last_run();
		$today = gmdate( 'Y-m-d' );
		if ( 'rucno' === $how ) {
			$n = isset( $run['rucno'][ $today ] ) ? (int) $run['rucno'][ $today ] : 0;
			if ( $n >= self::MANUAL_MAX ) {
				return new WP_Error( 'olr_prijedlozi_limit', 'Danas je analiza već pokrenuta ručno ' . self::MANUAL_MAX . ' puta. Sljedeća ide automatski s novim Google podacima.' );
			}
			$run['rucno'] = array( $today => $n + 1 );
		} else {
			$run['auto_at'] = time();
		}
		update_option( self::RUN_OPT, $run, false );
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$an      = OLR_Analiza::run();
		$all     = self::all();
		$blocked = self::blocked_keys( $all );
		$max     = max( 1, min( 10, (int) OLR_Settings::get( 'prijedlozi_max' ) ) );

		// Odabir: po učinku, najviše 2 po vrsti, bez nalaza koji već imaju otvoren ili nedavno riješen prijedlog.
		$chosen   = array();
		$per_type = array();
		$stamp    = gmdate( 'Ymd' );
		foreach ( $an['kandidati'] as $c ) {
			if ( isset( $blocked[ $c['kljuc'] ] ) ) {
				continue;
			}
			$t = $c['vrsta'];
			if ( isset( $per_type[ $t ] ) && $per_type[ $t ] >= 2 ) {
				continue;
			}
			$per_type[ $t ]             = ( isset( $per_type[ $t ] ) ? $per_type[ $t ] : 0 ) + 1;
			$id                         = 'p-' . $stamp . '-' . substr( md5( $c['kljuc'] ), 0, 8 );
			$chosen[ $id ]              = $c;
			$blocked[ $c['kljuc'] ]     = true;
			if ( count( $chosen ) >= $max ) {
				break;
			}
		}

		// 1) Novi prijedlozi se spremaju odmah, s tekstom složenim iz podataka. Ako poziv Claudea potraje
		//    predugo ili ga hosting prekine, prijedlozi svejedno postoje, a tekst se dopuni pri sljedećoj analizi.
		$now   = time() * 1000;
		$saved = 0;
		$batch = array();
		foreach ( $chosen as $id => $c ) {
			$fb  = self::fallback( $c );
			$doc = array(
				'id'             => $id,
				'kljuc'          => $c['kljuc'],
				'vrsta'          => $c['vrsta'],
				'url'            => $c['url'],
				'put'            => $c['put'],
				'upit'           => $c['upit'],
				'naslov'         => $fb['naslov'],
				// „Zašto” je uvijek rečenica složena iz podataka; Claudeova se dodaje kao objašnjenje.
				'zasto'          => $fb['zasto'],
				'objasnjenje'    => '',
				'dokazi'         => $c['dokazi'],
				'stranica'       => $c['stranica'],
				'izvori_linka'   => isset( $c['izvori_linka'] ) ? $c['izvori_linka'] : array(),
				'druge_stranice' => isset( $c['druge_stranice'] ) ? $c['druge_stranice'] : array(),
				'izmjene'        => array(),
				'koraci'         => $fb['koraci'],
				'ucinak'         => $c['ucinak'],
				'ucinak_opis'    => isset( $c['ucinak_opis'] ) ? $c['ucinak_opis'] : '',
				'ai'             => false,
				'model'          => null,
				'izradjeno'      => $now,
				'gsc_do'         => $an['gsc_do'],
				'nacin'          => $how,
				'isteklo'        => false,
			);
			if ( true === OLR_Store::set( self::COLL, $id, $doc ) ) {
				++$saved;
				$batch[ $id ] = $doc;
			}
		}
		// 2) Uz nove, tekst dobivaju i otvoreni prijedlozi koji ga još nemaju (npr. ključ je dodan naknadno).
		foreach ( $all as $p ) {
			if ( count( $batch ) >= $max + 3 ) {
				break;
			}
			if ( self::is_open( $p ) && empty( $p['ai'] ) && time() - (int) ( $p['izradjeno'] / 1000 ) < 14 * DAY_IN_SECONDS ) {
				unset( $p['stanje'], $p['stanje_at'], $p['biljeska'] );
				$batch[ $p['id'] ] = $p;
			}
		}

		$ai_ok   = false;
		$ai_msg  = '';
		$written = 0;
		if ( $batch && OLR_AI::available() ) {
			$res = OLR_AI::call(
				array(
					'system'     => self::system_prompt(),
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => self::ai_input( $batch ),
						),
					),
					'max_tokens' => OLR_AI::MAX_TOKENS,
				)
			);
			if ( is_wp_error( $res ) ) {
				$ai_msg = $res->get_error_message();
			} else {
				$parsed = self::parse_ai( $res );
				if ( is_wp_error( $parsed ) ) {
					$ai_msg = $parsed->get_error_message();
				} else {
					$ai_ok = true;
					foreach ( $parsed as $id => $a ) {
						if ( ! isset( $batch[ $id ] ) || ! is_array( $a ) ) {
							continue;
						}
						$doc     = $batch[ $id ];
						$changes = self::clean_changes( isset( $a['izmjene'] ) ? $a['izmjene'] : array(), $doc );
						if ( ! $changes ) {
							continue;
						}
						$steps = array();
						foreach ( ! empty( $a['koraci'] ) && is_array( $a['koraci'] ) ? array_slice( $a['koraci'], 0, 4 ) : array() as $st ) {
							if ( is_string( $st ) && '' !== trim( $st ) ) {
								$steps[] = self::cut( $st, 300 );
							}
						}
						$doc['izmjene'] = $changes;
						$doc['ai']      = true;
						$doc['model']   = isset( $res['model'] ) ? $res['model'] : null;
						if ( ! empty( $a['naslov'] ) && is_string( $a['naslov'] ) ) {
							$doc['naslov'] = self::cut( $a['naslov'], 140 );
						}
						if ( ! empty( $a['zasto'] ) && is_string( $a['zasto'] ) ) {
							$doc['objasnjenje'] = self::cut( $a['zasto'], 500 );
						}
						if ( $steps ) {
							$doc['koraci'] = $steps;
						}
						if ( true === OLR_Store::set( self::COLL, $id, $doc ) ) {
							++$written;
						}
					}
				}
			}
		} elseif ( $batch ) {
			$ai_msg = 'Anthropic ključ nije postavljen; prijedlozi su spremljeni bez napisanog teksta.';
		}

		$expired = self::expire( $all, $an['kandidati'] );
		$run     = array_merge(
			self::last_run(),
			array(
				'at'        => time(),
				'gsc_do'    => $an['gsc_do'],
				'kandidata' => count( $an['kandidati'] ),
				'novih'     => $saved,
				'isteklo'   => $expired,
				'ai_ok'     => $ai_ok,
				'ai_poruka' => $ai_msg,
				'tekstova'  => $written,
				'nacin'     => $how,
				'napomene'  => $an['napomene'],
			)
		);
		update_option( self::RUN_OPT, $run, false );
		delete_transient( self::LOCK );
		$msg = $saved ? ( $saved . ' novih prijedloga' . ( $ai_ok ? '' : ' (bez teksta Claudea: ' . $ai_msg . ')' ) ) : ( $written ? 'nema novih nalaza; dopunjen tekst za ' . $written . ' prijedloga' : 'nema novih nalaza; otvoreni prijedlozi i dalje vrijede' );
		OLR_Settings::set_status( 'prijedlozi', $ai_ok || ! $batch || ! OLR_AI::available(), $msg, array( 'novih' => $saved ) );
		return $run;
	}

	/**
	 * Neobrađeni prijedlozi stariji od 21 dan čiji nalaz više ne postoji označavaju se kao istekli
	 * (ostaju u povijesti, ne brišu se). Prijedlozi stariji od 180 dana brišu se.
	 */
	private static function expire( $all, $candidates ) {
		$alive = array();
		foreach ( $candidates as $c ) {
			$alive[ $c['kljuc'] ] = true;
		}
		$n   = 0;
		$now = time();
		foreach ( $all as $p ) {
			$age = $now - (int) ( $p['izradjeno'] / 1000 );
			if ( $age > 180 * DAY_IN_SECONDS ) {
				OLR_Store::delete( self::COLL, $p['id'] );
				OLR_Store::delete( self::STATE_COLL, $p['id'] );
				continue;
			}
			if ( 'novo' === $p['stanje'] && empty( $p['isteklo'] ) && $age > 21 * DAY_IN_SECONDS && ! isset( $alive[ $p['kljuc'] ] ) ) {
				unset( $p['stanje'], $p['stanje_at'], $p['biljeska'] );
				$p['isteklo'] = true;
				OLR_Store::set( self::COLL, $p['id'], $p );
				++$n;
			}
		}
		return $n;
	}

	/** Podaci za nadzornu ploču i za Claudea. */
	public static function summary( $filter = 'svi', $limit = 200 ) {
		$all  = self::all();
		$open = 0;
		foreach ( $all as $p ) {
			if ( self::is_open( $p ) ) {
				++$open;
			}
		}
		$items = array();
		foreach ( $all as $p ) {
			$keep = 'svi' === $filter
				|| ( 'otvoreni' === $filter && self::is_open( $p ) )
				|| ( in_array( $filter, self::STANJA, true ) && $p['stanje'] === $filter && empty( $p['isteklo'] ) );
			if ( $keep ) {
				$items[] = $p;
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		$run = self::last_run();
		unset( $run['rucno'] );
		return array(
			'otvorenih' => $open,
			'ukupno'    => count( $all ),
			'stavke'    => $items,
			'zadnje'    => $run,
			'auto'      => (bool) OLR_Settings::get( 'prijedlozi_auto' ),
			'claude'    => OLR_AI::available(),
		);
	}
}
