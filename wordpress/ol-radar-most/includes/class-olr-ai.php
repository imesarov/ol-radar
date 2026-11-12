<?php
/**
 * Posrednik prema Anthropic Messages API-ju za AI dio nadzorne ploče.
 * Ključ ostaje na serveru; preglednik šalje poruke i definicije alata, a alate izvršava sam.
 * Troši API kredite (Ultra plan uključuje mjesečne API kredite u povezanoj Claude Console organizaciji).
 */

defined( 'ABSPATH' ) || exit;

class OLR_AI {

	const URL         = 'https://api.anthropic.com/v1/messages';
	const VERSION     = '2023-06-01';
	const MAX_TOKENS  = 4096;
	const MAX_REQUEST = 400000; // znakova JSON-a po zahtjevu
	/** Pozadinski poslovi servera (ne ploča): dulji odgovor i veći zahtjev (rezultati web pretrage se vraćaju API-ju). */
	const MAX_TOKENS_JOB  = 8000;
	const MAX_REQUEST_JOB = 3000000;
	/** Anthropicov alat za web pretragu (izvršava ga Anthropic; naplaćuje se po pretrazi). */
	const WEB_SEARCH = 'web_search_20250305';
	/** USD po milijunu tokena [ulaz, izlaz]; izvor: platform.claude.com/docs/en/about-claude/pricing. */
	const PRICES     = array(
		'claude-opus-5-5'   => array( 4.0, 20.0 ),
		'claude-sonnet-5-5' => array( 2.0, 10.0 ),
		'claude-haiku-5-5'  => array( 0.10, 0.50 ),
	);
	const PRICES_AT  = '2026-11-12';
	/** Web pretraga: 10 USD na 1000 pretraga. */
	const SEARCH_USD = 0.01;

	public static function models() {
		return array(
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (preporuka)',
			'claude-opus-5-5'   => 'Claude Opus 5.5 (najjači, skuplji)',
			'claude-haiku-5-5'  => 'Claude Haiku 5.5 (najbrži, najjeftiniji)',
		);
	}

	public static function available() {
		return (bool) OLR_Settings::secret( 'anthropic_key' );
	}

	private static function usage_key() {
		return 'olr_ai_usage';
	}

	public static function usage() {
		$u = get_option( self::usage_key(), array() );
		return is_array( $u ) ? $u : array();
	}

	/**
	 * Cijena modela u USD po milijunu tokena [ulaz, izlaz] prema službenom cjeniku (stanje 12. 11. 2026.).
	 * Haiku 5.5 je skuplji za zahtjeve s više od 100.000 ulaznih tokena. Nepoznat model: null.
	 */
	public static function price( $model, $in = 0 ) {
		$model = (string) $model;
		foreach ( self::PRICES as $prefix => $p ) {
			if ( 0 === strpos( $model, $prefix ) ) {
				if ( 'claude-haiku-5-5' === $prefix && $in > 100000 ) {
					return array( 0.50, 2.50 );
				}
				return $p;
			}
		}
		return null;
	}

	/** Trošak jednog poziva u USD ili null ako cijena modela nije poznata. */
	public static function cost( $model, $in, $out, $searches = 0 ) {
		$p = self::price( $model, $in );
		if ( ! $p ) {
			return null;
		}
		return ( $in * $p[0] + $out * $p[1] ) / 1000000 + $searches * self::SEARCH_USD;
	}

	private static function bump_usage( $in, $out, $searches = 0, $model = '' ) {
		$u   = self::usage();
		$day = gmdate( 'Y-m-d' );
		$mon = gmdate( 'Y-m' );
		foreach ( array( 'dani', 'mjeseci', 'trosak_dani' ) as $k ) {
			if ( ! isset( $u[ $k ] ) || ! is_array( $u[ $k ] ) ) {
				$u[ $k ] = array();
			}
		}
		$u['dani'][ $day ] = ( isset( $u['dani'][ $day ] ) ? (int) $u['dani'][ $day ] : 0 ) + 1;
		$m                 = isset( $u['mjeseci'][ $mon ] ) ? $u['mjeseci'][ $mon ] : array( 'pozivi' => 0, 'ulaz' => 0, 'izlaz' => 0 );
		if ( ! isset( $m['trosak'] ) ) {
			// Pozivi prije verzije 2.4.0 nemaju zabilježen trošak: procjena po cijeni sadašnjeg modela.
			$old         = self::cost( $model, (int) $m['ulaz'], (int) $m['izlaz'] );
			$m['trosak'] = null === $old ? 0 : $old;
			if ( $m['pozivi'] ) {
				$m['procjena'] = true;
			}
		}
		$m['pozivi'] += 1;
		$m['ulaz']   += (int) $in;
		$m['izlaz']  += (int) $out;
		if ( $searches ) {
			$m['pretrage'] = ( isset( $m['pretrage'] ) ? (int) $m['pretrage'] : 0 ) + (int) $searches;
		}
		$c = self::cost( $model, (int) $in, (int) $out, (int) $searches );
		if ( null === $c ) {
			$m['bez_cijene'] = ( isset( $m['bez_cijene'] ) ? (int) $m['bez_cijene'] : 0 ) + 1;
		} else {
			$m['trosak']               = round( (float) $m['trosak'] + $c, 6 );
			$u['trosak_dani'][ $day ]  = round( ( isset( $u['trosak_dani'][ $day ] ) ? (float) $u['trosak_dani'][ $day ] : 0 ) + $c, 6 );
		}
		$u['mjeseci'][ $mon ] = $m;
		$u['dani']            = array_slice( $u['dani'], -14, null, true );
		$u['trosak_dani']     = array_slice( $u['trosak_dani'], -14, null, true );
		$u['mjeseci']         = array_slice( $u['mjeseci'], -12, null, true );
		update_option( self::usage_key(), $u, false );
	}

	/**
	 * Trošak Claudea za Postavke, ploču i MCP: danas i po mjesecima (najnoviji prvi).
	 * Mjeseci bez zabilježenog troška (pozivi prije 2.4.0) procjenjuju se po cijeni sadašnjeg modela.
	 */
	public static function cost_summary() {
		$u     = self::usage();
		$model = (string) OLR_Settings::get( 'ai_model' );
		$today = gmdate( 'Y-m-d' );
		$rows  = array();
		foreach ( array_reverse( isset( $u['mjeseci'] ) && is_array( $u['mjeseci'] ) ? $u['mjeseci'] : array(), true ) as $mon => $m ) {
			$est = ! isset( $m['trosak'] ) || ! empty( $m['procjena'] );
			$usd = isset( $m['trosak'] ) ? (float) $m['trosak'] : self::cost( $model, (int) $m['ulaz'], (int) $m['izlaz'] );
			$rows[] = array(
				'mjesec'     => $mon,
				'pozivi'     => (int) $m['pozivi'],
				'ulaz'       => (int) $m['ulaz'],
				'izlaz'      => (int) $m['izlaz'],
				'pretrage'   => isset( $m['pretrage'] ) ? (int) $m['pretrage'] : 0,
				'usd'        => null === $usd ? null : round( $usd, 4 ),
				'procjena'   => $est,
				'bez_cijene' => isset( $m['bez_cijene'] ) ? (int) $m['bez_cijene'] : 0,
			);
		}
		$p = self::price( $model );
		return array(
			'danas'   => array(
				'pozivi' => isset( $u['dani'][ $today ] ) ? (int) $u['dani'][ $today ] : 0,
				'limit'  => max( 1, (int) OLR_Settings::get( 'ai_daily_limit' ) ),
				'usd'    => isset( $u['trosak_dani'][ $today ] ) ? round( (float) $u['trosak_dani'][ $today ], 4 ) : 0,
			),
			'mjeseci' => $rows,
			'model'   => $model,
			'cijena'  => $p ? array(
				'ulaz'     => $p[0],
				'izlaz'    => $p[1],
				'pretraga' => self::SEARCH_USD,
			) : null,
			'cjenik'  => self::PRICES_AT,
		);
	}

	/** Dopuštene uloge i oblik poruka (tekst ili blokovi). */
	private static function clean_messages( $messages ) {
		$out = array();
		foreach ( (array) $messages as $m ) {
			if ( ! is_array( $m ) || empty( $m['role'] ) || ! in_array( $m['role'], array( 'user', 'assistant' ), true ) || ! isset( $m['content'] ) ) {
				continue;
			}
			$content = $m['content'];
			if ( ! is_string( $content ) && ! is_array( $content ) ) {
				continue;
			}
			// Uzastopne poruke iste uloge spajaju se (API traži izmjenu uloga).
			$n = count( $out );
			if ( $n && $out[ $n - 1 ]['role'] === $m['role'] && is_string( $content ) && is_string( $out[ $n - 1 ]['content'] ) ) {
				$out[ $n - 1 ]['content'] .= "\n\n" . $content;
				continue;
			}
			$out[] = array(
				'role'    => $m['role'],
				'content' => $content,
			);
		}
		return $out;
	}

	/** Sadrži li odgovor poziv zadanog alata. */
	private static function has_tool_use( $content, $name ) {
		foreach ( is_array( $content ) ? $content : array() as $b ) {
			if ( isset( $b['type'], $b['name'] ) && 'tool_use' === $b['type'] && $name === $b['name'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Jedan poziv Messages API-ja. $req: messages, system?, tools?, tool_choice?, max_tokens?
	 *
	 * tool_choice[name] znači „odgovori pozivom tog alata”. Opus 5.5 i Sonnet 5.5 odbijaju prisilni poziv
	 * alata (HTTP 400), pa se API-ju uvijek šalje zadani način (auto), a alat se traži uputom u sistemskoj
	 * poruci. Auto ne jamči poziv: ako ga u odgovoru nema, ide jedan ponovljeni pokušaj s podsjetnikom.
	 *
	 * Samo za pozive sa servera ($server = true; REST ploče to ne može uključiti):
	 *   web_search (int) dodaje Anthropicov alat za web pretragu s najviše toliko pretraga,
	 *   long (bool) dopušta dulji odgovor i veći zahtjev.
	 *
	 * @return array|WP_Error Odgovor API-ja (content, stop_reason, usage, model).
	 */
	public static function call( $req, $server = false ) {
		$key = OLR_Settings::secret( 'anthropic_key' );
		if ( ! $key ) {
			return new WP_Error( 'olr_ai_nema', 'Anthropic API ključ nije postavljen (OL Radar → Postavke).', array( 'status' => 400 ) );
		}
		$search   = $server && ! empty( $req['web_search'] ) ? max( 1, min( 8, (int) $req['web_search'] ) ) : 0;
		$long     = $server && ( $search || ! empty( $req['long'] ) );
		$messages = self::clean_messages( isset( $req['messages'] ) ? $req['messages'] : array() );
		if ( ! $messages || 'user' !== $messages[0]['role'] ) {
			return new WP_Error( 'olr_ai_poruke', 'Poruke moraju početi korisnikom.', array( 'status' => 400 ) );
		}
		$body  = array(
			'model'      => OLR_Settings::get( 'ai_model' ),
			'max_tokens' => max( 64, min( $long ? self::MAX_TOKENS_JOB : self::MAX_TOKENS, isset( $req['max_tokens'] ) ? (int) $req['max_tokens'] : 2000 ) ),
			'messages'   => $messages,
		);
		$force = '';
		if ( ! empty( $req['system'] ) && is_string( $req['system'] ) ) {
			$body['system'] = $req['system'];
		}
		if ( ! empty( $req['tools'] ) && is_array( $req['tools'] ) ) {
			$tools = array();
			foreach ( array_slice( $req['tools'], 0, 20 ) as $t ) {
				if ( empty( $t['name'] ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $t['name'] ) ) {
					continue;
				}
				$tools[] = array(
					'name'         => $t['name'],
					'description'  => isset( $t['description'] ) ? (string) $t['description'] : '',
					'input_schema' => ! empty( $t['input_schema'] ) && is_array( $t['input_schema'] ) ? $t['input_schema'] : array( 'type' => 'object', 'properties' => new stdClass() ),
				);
			}
			if ( $tools ) {
				$body['tools'] = $tools;
				if ( ! empty( $req['tool_choice']['name'] ) && in_array( $req['tool_choice']['name'], wp_list_pluck( $tools, 'name' ), true ) ) {
					$force          = $req['tool_choice']['name'];
					$body['system'] = ( isset( $body['system'] ) ? $body['system'] . "\n\n" : '' ) . 'Odgovori isključivo pozivom alata ' . $force . ', točno jednom. Ne odgovaraj običnim tekstom.';
				}
			}
		}
		if ( $search ) {
			if ( ! isset( $body['tools'] ) ) {
				$body['tools'] = array();
			}
			$body['tools'][] = array(
				'type'          => self::WEB_SEARCH,
				'name'          => 'web_search',
				'max_uses'      => $search,
				'user_location' => array(
					'type'     => 'approximate',
					'city'     => 'Zagreb',
					'country'  => 'HR',
					'timezone' => 'Europe/Zagreb',
				),
			);
		}
		$out = self::send( $key, $body, $search, $long );
		if ( ! is_wp_error( $out ) && '' !== $force && 'end_turn' === $out['stop_reason'] && ! self::has_tool_use( $out['content'], $force ) ) {
			$body['system'] .= "\n\nVAŽNO: u prethodnom pokušaju alat nije pozvan. Odgovor mora biti poziv alata " . $force . '.';
			$again           = self::send( $key, $body, $search, $long );
			if ( ! is_wp_error( $again ) ) {
				$out = $again;
			}
		}
		return $out;
	}

	/** Slanje zahtjeva, obrada grešaka, brojanje potrošnje i troška. */
	private static function send( $key, $body, $search, $long ) {
		$limit = max( 1, (int) OLR_Settings::get( 'ai_daily_limit' ) );
		$u     = self::usage();
		$today = isset( $u['dani'][ gmdate( 'Y-m-d' ) ] ) ? (int) $u['dani'][ gmdate( 'Y-m-d' ) ] : 0;
		if ( $today >= $limit ) {
			return new WP_Error( 'olr_ai_limit', 'Dosegnut je dnevni limit AI poziva (' . $limit . '). Može se promijeniti u Postavkama.', array( 'status' => 429 ) );
		}
		$json = wp_json_encode( $body );
		if ( strlen( $json ) > ( $long ? self::MAX_REQUEST_JOB : self::MAX_REQUEST ) ) {
			return new WP_Error( 'olr_ai_velicina', 'Zahtjev prema Claudeu je prevelik.', array( 'status' => 413 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $search ? 300 : 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$res = wp_remote_post(
			self::URL,
			array(
				'timeout'     => $search ? 240 : 150,
				'httpversion' => '1.1',
				'headers'     => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::VERSION,
					'content-type'      => 'application/json',
				),
				'body'        => $json,
			)
		);
		if ( is_wp_error( $res ) ) {
			OLR_Settings::set_status( 'ai_poziv', false, 'veza: ' . $res->get_error_message() );
			return new WP_Error( 'olr_ai_mreza', 'Claude API: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$out  = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = isset( $out['error']['message'] ) ? $out['error']['message'] : 'HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'Anthropic API ključ nije ispravan ili je opozvan.';
			} elseif ( 429 === $code ) {
				$msg = 'Claude API: previše zahtjeva u kratkom vremenu. Pokušaj za minutu.';
			} elseif ( 529 === $code || 503 === $code ) {
				$msg = 'Claude API je trenutno preopterećen. Pokušaj za minutu.';
			} elseif ( false !== stripos( $msg, 'credit balance' ) ) {
				$msg = 'API krediti su potrošeni. Mjesečni krediti Ultra plana dolaze s novim obračunskim razdobljem; dotad se mogu kupiti u Claude Consoleu.';
			}
			OLR_Settings::set_status( 'ai_poziv', false, 'HTTP ' . $code . ': ' . $msg );
			if ( 400 === $code && false === strpos( $msg, 'krediti su potrošeni' ) ) {
				// Anthropic je odbio sam zahtjev (npr. web pretraga nije uključena): pozivatelj može pokušati drukčije.
				return new WP_Error( 'olr_ai_zahtjev', 'Claude API je odbio zahtjev: ' . $msg, array( 'status' => 400 ) );
			}
			return new WP_Error( 'olr_ai_api', $msg, array( 'status' => 429 === $code ? 429 : 502 ) );
		}
		$tin   = isset( $out['usage']['input_tokens'] ) ? (int) $out['usage']['input_tokens'] : 0;
		$tout  = isset( $out['usage']['output_tokens'] ) ? (int) $out['usage']['output_tokens'] : 0;
		$used  = isset( $out['usage']['server_tool_use']['web_search_requests'] ) ? (int) $out['usage']['server_tool_use']['web_search_requests'] : 0;
		$model = isset( $out['model'] ) ? (string) $out['model'] : (string) $body['model'];
		self::bump_usage( $tin, $tout, $used, $model );
		// Zadnji poziv (bez sadržaja): model, kako je odgovor završio i koliko je bio dug. Pomaže u dijagnostici
		// kad ploča javi da je odgovor prekinut ili u krivom obliku.
		$stop = isset( $out['stop_reason'] ) ? (string) $out['stop_reason'] : '';
		$usd  = self::cost( $model, $tin, $tout, $used );
		// Koliko je izlaza otišlo na razmišljanje modela (naplaćuje se kao izlaz i ulazi u max_tokens).
		$think = isset( $out['usage']['output_tokens_details']['thinking_tokens'] ) && is_numeric( $out['usage']['output_tokens_details']['thinking_tokens'] ) ? (int) $out['usage']['output_tokens_details']['thinking_tokens'] : 0;
		OLR_Settings::set_status(
			'ai_poziv',
			'max_tokens' !== $stop,
			$model . ' · ' . ( 'max_tokens' === $stop ? 'odgovor prekinut zbog duljine' : ( 'tool_use' === $stop ? 'odgovor alatom' : ( 'pause_turn' === $stop ? 'odgovor se nastavlja' : 'odgovor završen' ) ) ) . ' · ulaz ' . $tin . ', izlaz ' . $tout . ' tokena' . ( $think ? ' (od toga razmišljanje ' . $think . ')' : '' ) . ( $used ? ', web pretraga: ' . $used : '' ) . ( null === $usd ? '' : ' · ' . number_format( $usd, 4, ',', '.' ) . ' $' ),
			array( 'stop' => $stop )
		);
		return array(
			'content'     => isset( $out['content'] ) ? $out['content'] : array(),
			'stop_reason' => isset( $out['stop_reason'] ) ? $out['stop_reason'] : null,
			'usage'       => isset( $out['usage'] ) ? $out['usage'] : null,
			'model'       => isset( $out['model'] ) ? $out['model'] : null,
		);
	}

	public static function test() {
		$r = self::call(
			array(
				'messages'   => array( array( 'role' => 'user', 'content' => 'Odgovori samo riječju: spreman' ) ),
				'max_tokens' => 10,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$t = '';
		foreach ( $r['content'] as $b ) {
			if ( isset( $b['type'] ) && 'text' === $b['type'] ) {
				$t .= $b['text'];
			}
		}
		return 'Ključ radi (' . $r['model'] . '): ' . trim( $t );
	}
}
