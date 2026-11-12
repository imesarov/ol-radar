<?php
/**
 * Dnevni prijedlozi izmjena.
 *
 * Nakon novih Google podataka server sam pokrene analizu (OLR_Analiza), odabere najvažnije nalaze koji
 * još nemaju otvoren prijedlog i za svaki posebnim, kratkim pozivom Claudea (Anthropic API) napiše gotov
 * tekst izmjene. Brojke u prijedlogu uvijek su iz podataka, a ne iz odgovora Claudea. Bez API ključa (ili
 * ako poziv ne uspije) prijedlog se sprema s gotovim koracima, a tekst se dopuni pri sljedećem pokušaju
 * (svaki sat, najviše tri pokušaja po prijedlogu).
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
	/** Dopisivanje teksta u pozadini (kad jedan prolaz ne stigne napisati sve). */
	const EVENT_TEXT = 'olr_prijedlozi_tekst';
	const MANUAL_MAX = 6;
	const TOOL       = 'spremi_prijedlog';
	/** Najviše pokušaja pisanja teksta po prijedlogu i vremenski okvir jednog prolaza (sekunde). */
	const AI_TRIES   = 3;
	const AI_BUDGET  = 150;
	/** Ručno pokretanje iz ploče ne smije držati preglednik: kratak okvir, ostatak ide u pozadinu. */
	const MANUAL_BUDGET = 20;
	const STANJA     = array( 'novo', 'prihvaceno', 'provedeno', 'odbijeno' );
	const POLJA      = array( 'seo_naslov', 'meta_opis', 'h1', 'odlomak', 'interni_link', 'novi_clanak', 'tehnicki' );

	public static function init() {
		add_action( 'olr_gsc_collected', array( __CLASS__, 'on_gsc' ) );
		add_action( self::EVENT, array( __CLASS__, 'cron' ) );
		add_action( self::EVENT_TEXT, array( __CLASS__, 'cron_text' ) );
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

	/** Pozadinsko dopisivanje teksta prijedlozima koje prethodni prolaz nije stigao obraditi. */
	public static function cron_text() {
		if ( OLR_AI::available() ) {
			self::run( 'tekst' );
		}
	}

	/** Piše li se tekst upravo ili je dopisivanje zakazano (ploča tada sama osvježava prikaz). */
	public static function writing() {
		return (bool) ( get_transient( self::LOCK ) || wp_next_scheduled( self::EVENT_TEXT ) );
	}

	/**
	 * Vremenski okvir pisanja teksta u jednom prolazu. Ručno pokretanje je kratko; ako hosting ne dopušta
	 * produljenje izvršavanja, okvir se prilagodi njegovoj granici.
	 */
	private static function budget( $how ) {
		$b   = 'rucno' === $how ? self::MANUAL_BUDGET : self::AI_BUDGET;
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$b = min( $b, max( 10, $max - 45 ) );
		}
		return (int) apply_filters( 'olr_prijedlozi_budget', $b, $how );
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
			// Dan provedbe koji je vlasnik upisao (ako se razlikuje od dana kad je kliknuo „provedeno”).
			$p['stanje_dan'] = isset( $st['dan'] ) && is_string( $st['dan'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $st['dan'] ) ? $st['dan'] : null;
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
				if ( isset( $d['kod'] ) && in_array( $d['kod'], array( 'nije_u_indeksu', 'druga_kanonska' ), true ) ) {
					return array(
						'naslov' => $d['opis'] . ': ' . $c['put'],
						'zasto'  => 'Googleova provjera indeksiranosti (' . $d['provjereno'] . '): ' . $d['detalj'] . '.',
						'koraci' => 'nije_u_indeksu' === $d['kod']
							? array( 'Otvori stranicu u Search Consoleu (Pregled URL-a) i pogledaj razlog koji Google navodi.', 'Ukloni prepreku: oznaku noindex, blokadu u robots.txt, preusmjeravanje ili grešku servera.', 'Provjeri da na stranicu vode interni linkovi i da je u sitemapu, pa u Search Consoleu zatraži indeksiranje.' )
							: array( 'Usporedi ovu stranicu s adresom koju je Google odabrao: ako su gotovo iste, spoji ih ili jasno razdvoji sadržaj.', 'Provjeri kanonsku oznaku na obje stranice.', 'Interne linkove usmjeri na adresu koja treba biti u indeksu.' ),
					);
				}
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

	private static function system_prompt( $plain = false ) {
		return "[zadatak: dnevni prijedlozi]\n"
			. "Ti si SEO urednik za optikaljiljana.hr (Optika Ljiljana, obiteljska optika u Sesvetama kod Zagreba, od 1993.; optometrist, provjera vida, dioptrijske i progresivne naočale, kontrola kratkovidnosti kod djece).\n"
			. "U ulazu je jedan nalaz. Napiši gotov prijedlog izmjene koji vlasnik može odmah provesti.\n\n"
			. "Pravila:\n"
			. "- Piši hrvatski, prirodno i konkretno, bez marketinških fraza i bez uskličnika.\n"
			. "- Koristi samo činjenice i brojke iz ulaza. Ne izmišljaj cijene, popuste, rokove, jamstva, medicinske tvrdnje ni podatke o konkurenciji.\n"
			. "- Budi kratak: najviše 3 izmjene i 4 koraka.\n"
			. "- seo_naslov: najviše 60 znakova, glavna fraza što bliže početku, naziv tvrtke najviše jednom.\n"
			. "- meta_opis: 120 do 155 znakova; kaže što posjetitelj dobiva i završava pozivom na radnju.\n"
			. "- interni_link: izvor smije biti samo putanja iz popisa izvori_linka; u polje novo upiši tekst sidra, u polje gdje putanju izvora.\n"
			. "- odlomak: naslov odjeljka (H2) i najviše 60 riječi o tome što treba pisati; ne piši cijeli članak.\n"
			. "- novi_clanak: radni naslov i do 6 podnaslova, sve u jednom polju novo.\n"
			. "- tehnicki: točno što promijeniti i gdje.\n"
			. "- zasto: jedna do dvije rečenice s brojkama iz ulaza.\n"
			. "- Ako se na samoj stranici nema što mijenjati (npr. treba pričekati nove podatke ili je radnja u Search Consoleu), ostavi izmjene prazne i napiši korake. Ne izmišljaj izmjenu teksta samo da je bude.\n"
			. "- Ako je stranica.izmijenjeno_prije_dana manje od 14, prvi korak neka bude provjera je li ta izmjena već riješila nalaz.\n"
			. ( $plain
				? "- Vrati isključivo JSON objekt, bez teksta prije ili poslije i bez ograde koda.\n\n" . 'Oblik: {"naslov":"…","zasto":"…","izmjene":[{"polje":"seo_naslov|meta_opis|h1|odlomak|interni_link|novi_clanak|tehnicki","gdje":"…","novo":"…"}],"koraci":["…"]}'
				: '- Odgovori isključivo pozivom alata ' . self::TOOL . ', točno jednom.' );
	}

	private static function tool() {
		return array(
			'name'         => self::TOOL,
			'description'  => 'Sprema gotov prijedlog izmjene za jedan nalaz.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'naslov'  => array(
						'type'        => 'string',
						'description' => 'Kratak naslov prijedloga, do 90 znakova.',
					),
					'zasto'   => array(
						'type'        => 'string',
						'description' => 'Jedna do dvije rečenice s brojkama iz ulaza.',
					),
					'izmjene' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'polje' => array(
									'type' => 'string',
									'enum' => self::POLJA,
								),
								'gdje'  => array(
									'type'        => 'string',
									'description' => 'Gdje se izmjena radi; za interni_link putanja izvora iz popisa izvori_linka.',
								),
								'novo'  => array(
									'type'        => 'string',
									'description' => 'Gotov novi tekst.',
								),
							),
							'required'   => array( 'polje', 'novo' ),
						),
					),
					'koraci'  => array(
						'type'        => 'array',
						'description' => 'Koraci za vlasnika. Obavezni ako su izmjene prazne.',
						'items'       => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'naslov', 'izmjene' ),
			),
		);
	}

	/** Ulaz za Claudea: jedan nalaz, samo ono što treba za tekst (bez internih polja). */
	private static function ai_input( $doc ) {
		$item = array(
			'vrsta'      => $doc['vrsta'],
			'opis_vrste' => OLR_Analiza::VRSTE[ $doc['vrsta'] ],
			'stranica'   => $doc['stranica'],
			'dokazi'     => $doc['dokazi'],
		);
		foreach ( array( 'izvori_linka', 'druge_stranice' ) as $k ) {
			if ( ! empty( $doc[ $k ] ) ) {
				$item[ $k ] = $doc[ $k ];
			}
		}
		return wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Ima li odgovor sadržaj: barem jednu izmjenu ili barem jedan korak. Odgovor samo s koracima je valjan
	 * (npr. „pričekaj nove podatke”, „zatraži indeksiranje”); do 2.2.1 vodio se kao neuspjeh.
	 */
	private static function usable( $a ) {
		if ( ! is_array( $a ) ) {
			return false;
		}
		if ( ! empty( $a['izmjene'] ) && is_array( $a['izmjene'] ) ) {
			return true;
		}
		foreach ( isset( $a['koraci'] ) && is_array( $a['koraci'] ) ? $a['koraci'] : array() as $k ) {
			if ( is_string( $k ) && '' !== trim( $k ) ) {
				return true;
			}
		}
		return false;
	}

	/** Iz odgovora vadi prijedlog: poziv alata ili, u rezervnom načinu, JSON u tekstu. */
	private static function parse_ai( $res ) {
		$text = '';
		foreach ( isset( $res['content'] ) ? (array) $res['content'] : array() as $b ) {
			if ( isset( $b['type'] ) && 'tool_use' === $b['type'] && isset( $b['input'] ) && self::usable( $b['input'] ) ) {
				return $b['input'];
			}
			if ( isset( $b['type'] ) && 'text' === $b['type'] ) {
				$text .= $b['text'];
			}
		}
		$cut = isset( $res['stop_reason'] ) && 'max_tokens' === $res['stop_reason'] ? ' (odgovor je prekinut zbog duljine)' : '';
		$a   = strpos( $text, '{' );
		$z   = strrpos( $text, '}' );
		if ( false === $a || false === $z || $z <= $a ) {
			return new WP_Error( 'olr_prijedlozi_json', 'Claude nije vratio prijedlog u očekivanom obliku' . $cut . '.' );
		}
		$j = json_decode( substr( $text, $a, $z - $a + 1 ), true );
		if ( is_array( $j ) && isset( $j['prijedlozi'][0] ) && is_array( $j['prijedlozi'][0] ) ) {
			$j = $j['prijedlozi'][0];
		}
		if ( ! self::usable( $j ) ) {
			return new WP_Error( 'olr_prijedlozi_json', 'Claude nije vratio prijedlog u očekivanom obliku' . $cut . '.' );
		}
		return $j;
	}

	/**
	 * Tekst za jedan prijedlog. Prvo prisilnim pozivom alata (odgovor je uvijek strukturiran); ako API to
	 * odbije ili odgovor nije upotrebljiv, jednom i običnim JSON odgovorom.
	 *
	 * @return array|WP_Error array( 'p' => prijedlog, 'model' => string )
	 */
	private static function write_one( $doc ) {
		$msg = array(
			array(
				'role'    => 'user',
				'content' => self::ai_input( $doc ),
			),
		);
		$res = OLR_AI::call(
			array(
				'system'      => self::system_prompt(),
				'messages'    => $msg,
				'tools'       => array( self::tool() ),
				'tool_choice' => array( 'name' => self::TOOL ),
				'max_tokens'  => 2600,
			)
		);
		$p = is_wp_error( $res ) ? $res : self::parse_ai( $res );
		if ( is_wp_error( $p ) && ! in_array( $p->get_error_code(), array( 'olr_ai_limit', 'olr_ai_nema', 'olr_ai_mreza' ), true ) && ! self::fatal_ai( $p ) ) {
			$res = OLR_AI::call(
				array(
					'system'     => self::system_prompt( true ),
					'messages'   => $msg,
					'max_tokens' => 2600,
				)
			);
			$p   = is_wp_error( $res ) ? $res : self::parse_ai( $res );
		}
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		return array(
			'p'     => $p,
			'model' => isset( $res['model'] ) ? $res['model'] : null,
		);
	}

	/** Greška zbog koje nema smisla pokušavati s ostalim prijedlozima (ključ, krediti, limit). */
	private static function fatal_ai( $e ) {
		$m = $e->get_error_message();
		return in_array( $e->get_error_code(), array( 'olr_ai_limit', 'olr_ai_nema', 'olr_ai_mreza' ), true )
			|| false !== strpos( $m, 'ključ nije ispravan' ) || false !== strpos( $m, 'krediti su potrošeni' )
			|| false !== strpos( $m, 'previše zahtjeva' ) || false !== strpos( $m, 'preopterećen' );
	}

	/**
	 * Piše tekst za zadane prijedloge, jedan po jedan; svaki se sprema čim je gotov.
	 *
	 * @param array $docs   Prijedlozi (dokumenti iz zbirke).
	 * @param int   $budget Vremenski okvir prolaza u sekundama.
	 * @return array{napisano:int, pokusano:int, greska:string, prekid:string} prekid: vrijeme, kvar ili prazno.
	 */
	private static function write_texts( $docs, $budget ) {
		$t0   = time();
		$done = 0;
		$n    = 0;
		$err  = '';
		$stop = '';
		foreach ( $docs as $doc ) {
			if ( time() - $t0 > $budget ) {
				$stop = 'vrijeme';
				break;
			}
			++$n;
			$r = self::write_one( $doc );
			if ( is_wp_error( $r ) ) {
				$err              = $r->get_error_message();
				$doc['ai_greska'] = $err;
				if ( self::fatal_ai( $r ) ) {
					// Ključ, krediti, limit ili mreža: nije do ovog prijedloga, pa se ne broji kao pokušaj.
					OLR_Store::set( self::COLL, $doc['id'], $doc );
					$stop = 'kvar';
					break;
				}
				$doc['ai_pokusaja'] = ( isset( $doc['ai_pokusaja'] ) ? (int) $doc['ai_pokusaja'] : 0 ) + 1;
				OLR_Store::set( self::COLL, $doc['id'], $doc );
				continue;
			}
			$a       = $r['p'];
			$raw     = isset( $a['izmjene'] ) && is_array( $a['izmjene'] ) ? $a['izmjene'] : array();
			$changes = self::clean_changes( $raw, $doc );
			$steps   = array();
			foreach ( ! empty( $a['koraci'] ) && is_array( $a['koraci'] ) ? array_slice( $a['koraci'], 0, 4 ) : array() as $st ) {
				if ( is_string( $st ) && '' !== trim( $st ) ) {
					$steps[] = self::cut( $st, 300 );
				}
			}
			// Bez izmjena teksta prijedlog vrijedi ako ima korake. Ako je Claude predložio izmjene, a nijedna
			// nije prošla provjeru, to je neuspio pokušaj.
			if ( ! $changes && ( $raw || ! $steps ) ) {
				$err                = 'Claude nije predložio nijednu valjanu izmjenu.';
				$doc['ai_greska']   = $err;
				$doc['ai_pokusaja'] = ( isset( $doc['ai_pokusaja'] ) ? (int) $doc['ai_pokusaja'] : 0 ) + 1;
				OLR_Store::set( self::COLL, $doc['id'], $doc );
				continue;
			}
			$doc['izmjene'] = $changes;
			$doc['ai']      = true;
			$doc['model']   = $r['model'];
			unset( $doc['ai_greska'] );
			if ( ! empty( $a['naslov'] ) && is_string( $a['naslov'] ) ) {
				$doc['naslov'] = self::cut( $a['naslov'], 140 );
			}
			if ( ! empty( $a['zasto'] ) && is_string( $a['zasto'] ) ) {
				$doc['objasnjenje'] = self::cut( $a['zasto'], 500 );
			}
			if ( $steps ) {
				$doc['koraci'] = $steps;
			}
			if ( true === OLR_Store::set( self::COLL, $doc['id'], $doc ) ) {
				++$done;
			}
		}
		return array(
			'napisano' => $done,
			'pokusano' => $n,
			'greska'   => $err,
			'prekid'   => $stop,
		);
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

	/** Dokument prijedloga iz nalaza analize (tekst složen iz podataka; Claude ga kasnije dopuni). */
	private static function doc_from( $id, $c, $how, $gsc_do, $now ) {
		$fb = self::fallback( $c );
		return array(
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
			'ai_pokusaja'    => 0,
			'izradjeno'      => $now,
			'gsc_do'         => $gsc_do,
			'nacin'          => $how,
			'isteklo'        => false,
		);
	}

	private static function strip_state( $p ) {
		unset( $p['stanje'], $p['stanje_at'], $p['biljeska'] );
		return $p;
	}

	/**
	 * Usklađivanje postojećih prijedloga s današnjom analizom:
	 * - novi prijedlog bez teksta čiji nalaz i dalje vrijedi dobiva svježe brojke;
	 * - novi prijedlog bez teksta čiji nalaz više ne postoji označava se kao istekao (nema ga smisla pisati);
	 * - novi prijedlog s tekstom stariji od 21 dan čiji nalaz više ne postoji također istječe;
	 * - prijedlozi stariji od 180 dana brišu se.
	 * Prijedlozi koje je korisnik prihvatio, proveo ili odbio ne diraju se.
	 *
	 * @return array{isteklo:int, bez_teksta:array} Broj isteklih i otvoreni prijedlozi koji još čekaju tekst.
	 */
	private static function reconcile( $all, $candidates ) {
		$alive = array();
		foreach ( $candidates as $c ) {
			$alive[ $c['kljuc'] ] = $c;
		}
		$expired = 0;
		$pending = array();
		$now     = time();
		foreach ( $all as $p ) {
			$age = $now - (int) ( $p['izradjeno'] / 1000 );
			if ( $age > 180 * DAY_IN_SECONDS ) {
				OLR_Store::delete( self::COLL, $p['id'] );
				OLR_Store::delete( self::STATE_COLL, $p['id'] );
				continue;
			}
			if ( ! empty( $p['isteklo'] ) || ! self::is_open( $p ) ) {
				continue;
			}
			$live = isset( $alive[ $p['kljuc'] ] );
			$doc  = self::strip_state( $p );
			if ( 'novo' === $p['stanje'] && ! $live && ( empty( $p['ai'] ) || $age > 21 * DAY_IN_SECONDS ) ) {
				$doc['isteklo']        = true;
				$doc['isteklo_razlog'] = 'nalaz više ne postoji u podacima';
				OLR_Store::set( self::COLL, $doc['id'], $doc );
				++$expired;
				continue;
			}
			if ( empty( $p['ai'] ) ) {
				if ( 'novo' === $p['stanje'] && $live ) {
					$c  = $alive[ $p['kljuc'] ];
					$fb = self::fallback( $c );
					foreach ( array( 'dokazi', 'stranica', 'ucinak', 'upit', 'url', 'put' ) as $k ) {
						$doc[ $k ] = $c[ $k ];
					}
					$doc['izvori_linka']   = isset( $c['izvori_linka'] ) ? $c['izvori_linka'] : array();
					$doc['druge_stranice'] = isset( $c['druge_stranice'] ) ? $c['druge_stranice'] : array();
					$doc['ucinak_opis']    = isset( $c['ucinak_opis'] ) ? $c['ucinak_opis'] : '';
					$doc['naslov']         = $fb['naslov'];
					$doc['zasto']          = $fb['zasto'];
					$doc['koraci']         = $fb['koraci'];
					OLR_Store::set( self::COLL, $doc['id'], $doc );
				}
				if ( ( isset( $doc['ai_pokusaja'] ) ? (int) $doc['ai_pokusaja'] : 0 ) < self::AI_TRIES ) {
					$pending[ $doc['id'] ] = $doc;
				}
			}
		}
		return array(
			'isteklo'    => $expired,
			'bez_teksta' => $pending,
		);
	}

	/** Ima li otvorenih prijedloga koji čekaju tekst, a zadnji pokušaj pisanja nije bio nedavno. */
	public static function fill_due() {
		if ( ! OLR_AI::available() ) {
			return false;
		}
		// Jednom nakon nadogradnje na 2.3.0: otvoreni prijedlozi koji su ostali bez teksta dobivaju još jedan
		// pokušaj, jer se do 2.2.1 odgovor samo s koracima (bez izmjene teksta) vodio kao neuspjeh.
		if ( '2.3.0' !== get_option( 'olr_prijedlozi_ponovi' ) ) {
			foreach ( self::all() as $p ) {
				if ( self::is_open( $p ) && empty( $p['ai'] ) && isset( $p['ai_pokusaja'] ) && (int) $p['ai_pokusaja'] >= self::AI_TRIES ) {
					$p['ai_pokusaja'] = self::AI_TRIES - 1;
					OLR_Store::set( self::COLL, $p['id'], $p );
				}
			}
			update_option( 'olr_prijedlozi_ponovi', '2.3.0', false );
		}
		$run = self::last_run();
		if ( ! empty( $run['tekst_at'] ) && time() - (int) $run['tekst_at'] < 50 * MINUTE_IN_SECONDS ) {
			return false;
		}
		foreach ( self::all() as $p ) {
			if ( self::is_open( $p ) && empty( $p['ai'] ) && ( isset( $p['ai_pokusaja'] ) ? (int) $p['ai_pokusaja'] : 0 ) < self::AI_TRIES ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Analiza i izrada prijedloga.
	 *
	 * @param string $how auto (cron), rucno (gumb) ili tekst (samo dopuna teksta postojećim prijedlozima).
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
		} elseif ( 'auto' === $how ) {
			$run['auto_at'] = time();
		}
		$run['tekst_at'] = time();
		update_option( self::RUN_OPT, $run, false );
		set_transient( self::LOCK, 1, 6 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$an  = OLR_Analiza::run();
		$rec = self::reconcile( self::all(), $an['kandidati'] );
		$all = self::all();
		$max = max( 1, min( 10, (int) OLR_Settings::get( 'prijedlozi_max' ) ) );

		// Odabir novih: po učinku, najviše 2 po vrsti i 1 po stranici, bez nalaza koji već imaju otvoren ili
		// nedavno riješen prijedlog. „Naslov i opis” i „Blizu vrha” za istu stranicu računaju se kao isti posao.
		$chosen = array();
		if ( 'tekst' !== $how ) {
			$blocked  = self::blocked_keys( $all );
			$page_job = array();
			foreach ( $all as $p ) {
				if ( self::is_open( $p ) && $p['put'] ) {
					$page_job[ $p['put'] ] = true;
				}
			}
			$per_type = array();
			$stamp    = gmdate( 'Ymd' );
			foreach ( $an['kandidati'] as $c ) {
				$t = $c['vrsta'];
				if ( isset( $blocked[ $c['kljuc'] ] ) || ( isset( $per_type[ $t ] ) && $per_type[ $t ] >= 2 ) ) {
					continue;
				}
				if ( $c['put'] && isset( $page_job[ $c['put'] ] ) && 'tehnicki' !== $t ) {
					continue; // ta stranica već ima otvoren prijedlog; jedan po jedan
				}
				$per_type[ $t ]         = ( isset( $per_type[ $t ] ) ? $per_type[ $t ] : 0 ) + 1;
				$blocked[ $c['kljuc'] ] = true;
				if ( $c['put'] && 'tehnicki' !== $t ) {
					$page_job[ $c['put'] ] = true;
				}
				$chosen[ 'p-' . $stamp . '-' . substr( md5( $c['kljuc'] ), 0, 8 ) ] = $c;
				if ( count( $chosen ) >= $max ) {
					break;
				}
			}
		}

		// Novi prijedlozi se spremaju odmah, s tekstom složenim iz podataka. Ako pisanje teksta potraje ili ga
		// hosting prekine, prijedlozi svejedno postoje, a tekst se dopuni pri sljedećem prolazu.
		$now   = time() * 1000;
		$saved = 0;
		$batch = array();
		foreach ( $chosen as $id => $c ) {
			$doc = self::doc_from( $id, $c, $how, $an['gsc_do'], $now );
			if ( true === OLR_Store::set( self::COLL, $id, $doc ) ) {
				++$saved;
				$batch[ $id ] = $doc;
			}
		}
		foreach ( $rec['bez_teksta'] as $id => $doc ) {
			if ( count( $batch ) >= $max + 3 ) {
				break;
			}
			$batch[ $id ] = $doc;
		}

		$w = array(
			'napisano' => 0,
			'pokusano' => 0,
			'greska'   => '',
			'prekid'   => '',
		);
		if ( $batch && OLR_AI::available() ) {
			$w = self::write_texts( $batch, self::budget( $how ) );
		} elseif ( $batch ) {
			$w['greska'] = 'Anthropic ključ nije postavljen; prijedlozi su spremljeni bez napisanog teksta.';
		}
		// Što prolaz nije stigao ni pokušati, dopisuje se u pozadini za nekoliko sekundi (ne čeka satni prolaz).
		$bg = 'vrijeme' === $w['prekid'] ? count( $batch ) - $w['pokusano'] : 0;
		if ( $bg > 0 && ! wp_next_scheduled( self::EVENT_TEXT ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT_TEXT );
		}
		$ai_ok = ! $batch || ( OLR_AI::available() && $w['napisano'] === $w['pokusano'] && $w['pokusano'] + $bg === count( $batch ) );

		$keep = self::last_run();
		$run  = array_merge(
			$keep,
			array(
				'tekst_at'   => time(),
				'tekstova'   => $w['napisano'],
				'ai_ok'      => $ai_ok,
				'ai_poruka'  => $w['greska'],
				'u_pozadini' => $bg,
			),
			'tekst' === $how ? array(
				'isteklo' => ( isset( $keep['isteklo'] ) ? (int) $keep['isteklo'] : 0 ) + $rec['isteklo'],
			) : array(
				'at'        => time(),
				'gsc_do'    => $an['gsc_do'],
				'kandidata' => count( $an['kandidati'] ),
				'novih'     => $saved,
				'isteklo'   => $rec['isteklo'],
				'nacin'     => $how,
				'napomene'  => $an['napomene'],
			)
		);
		update_option( self::RUN_OPT, $run, false );
		delete_transient( self::LOCK );
		$bits = array();
		if ( $saved ) {
			$bits[] = $saved . ' novih prijedloga';
		}
		if ( $w['napisano'] ) {
			$bits[] = 'tekst napisan za ' . $w['napisano'];
		}
		if ( $bg ) {
			$bits[] = 'tekst za još ' . $bg . ' piše se u pozadini';
		}
		if ( $batch && ! $ai_ok ) {
			$bits[] = 'bez teksta ' . ( count( $batch ) - $w['napisano'] - $bg ) . ( $w['greska'] ? ' (' . $w['greska'] . ')' : '' );
		}
		OLR_Settings::set_status(
			'prijedlozi',
			$ai_ok || ! OLR_AI::available(),
			$bits ? implode( '; ', $bits ) : 'nema novih nalaza; otvoreni prijedlozi i dalje vrijede',
			array( 'novih' => $saved )
		);
		return $run;
	}

	/** Podaci za nadzornu ploču i za Claudea. */
	public static function summary( $filter = 'svi', $limit = 200 ) {
		$all  = self::all();
		$open = 0;
		$wait = 0;
		foreach ( $all as $p ) {
			if ( self::is_open( $p ) ) {
				++$open;
				if ( empty( $p['ai'] ) && ( isset( $p['ai_pokusaja'] ) ? (int) $p['ai_pokusaja'] : 0 ) < self::AI_TRIES ) {
					++$wait;
				}
			}
		}
		$items = array();
		$uc    = OLR_Ucinak::docs();
		foreach ( $all as $p ) {
			if ( 'provedeno' === $p['stanje'] ) {
				$p['provedeno_dana'] = OLR_Ucinak::date_of( $p );
				$p['mjerenje']       = isset( $uc[ $p['id'] ] ) ? $uc[ $p['id'] ] : null;
			}
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
			'ceka_tekst' => $wait,
			'pise_se'   => $wait > 0 && self::writing(),
			'ucinak'    => OLR_Ucinak::counts( $uc ),
			'ukupno'    => count( $all ),
			'stavke'    => $items,
			'zadnje'    => $run,
			'auto'      => (bool) OLR_Settings::get( 'prijedlozi_auto' ),
			'claude'    => OLR_AI::available(),
		);
	}
}
