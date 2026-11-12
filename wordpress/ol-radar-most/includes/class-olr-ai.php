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

	private static function bump_usage( $in, $out ) {
		$u   = self::usage();
		$day = gmdate( 'Y-m-d' );
		$mon = gmdate( 'Y-m' );
		if ( ! isset( $u['dani'] ) || ! is_array( $u['dani'] ) ) {
			$u['dani'] = array();
		}
		if ( ! isset( $u['mjeseci'] ) || ! is_array( $u['mjeseci'] ) ) {
			$u['mjeseci'] = array();
		}
		$u['dani'][ $day ]   = ( isset( $u['dani'][ $day ] ) ? (int) $u['dani'][ $day ] : 0 ) + 1;
		$m                   = isset( $u['mjeseci'][ $mon ] ) ? $u['mjeseci'][ $mon ] : array( 'pozivi' => 0, 'ulaz' => 0, 'izlaz' => 0 );
		$m['pozivi']        += 1;
		$m['ulaz']          += (int) $in;
		$m['izlaz']         += (int) $out;
		$u['mjeseci'][ $mon ] = $m;
		$u['dani']           = array_slice( $u['dani'], -14, null, true );
		$u['mjeseci']        = array_slice( $u['mjeseci'], -12, null, true );
		update_option( self::usage_key(), $u, false );
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

	/**
	 * Jedan poziv Messages API-ja. $req: messages, system?, tools?, max_tokens?
	 *
	 * @return array|WP_Error Odgovor API-ja (content, stop_reason, usage, model).
	 */
	public static function call( $req ) {
		$key = OLR_Settings::secret( 'anthropic_key' );
		if ( ! $key ) {
			return new WP_Error( 'olr_ai_nema', 'Anthropic API ključ nije postavljen (OL Radar → Postavke).', array( 'status' => 400 ) );
		}
		$limit = max( 1, (int) OLR_Settings::get( 'ai_daily_limit' ) );
		$u     = self::usage();
		$today = isset( $u['dani'][ gmdate( 'Y-m-d' ) ] ) ? (int) $u['dani'][ gmdate( 'Y-m-d' ) ] : 0;
		if ( $today >= $limit ) {
			return new WP_Error( 'olr_ai_limit', 'Dosegnut je dnevni limit AI poziva (' . $limit . '). Može se promijeniti u Postavkama.', array( 'status' => 429 ) );
		}
		$messages = self::clean_messages( isset( $req['messages'] ) ? $req['messages'] : array() );
		if ( ! $messages || 'user' !== $messages[0]['role'] ) {
			return new WP_Error( 'olr_ai_poruke', 'Poruke moraju početi korisnikom.', array( 'status' => 400 ) );
		}
		$body = array(
			'model'      => OLR_Settings::get( 'ai_model' ),
			'max_tokens' => max( 64, min( self::MAX_TOKENS, isset( $req['max_tokens'] ) ? (int) $req['max_tokens'] : 2000 ) ),
			'messages'   => $messages,
		);
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
			}
		}
		$json = wp_json_encode( $body );
		if ( strlen( $json ) > self::MAX_REQUEST ) {
			return new WP_Error( 'olr_ai_velicina', 'Zahtjev prema Claudeu je prevelik.', array( 'status' => 413 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$res = wp_remote_post(
			self::URL,
			array(
				'timeout'     => 150,
				'httpversion' => '1.1',
				'headers'     => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => $json,
			)
		);
		if ( is_wp_error( $res ) ) {
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
			return new WP_Error( 'olr_ai_api', $msg, array( 'status' => 429 === $code ? 429 : 502 ) );
		}
		self::bump_usage( isset( $out['usage']['input_tokens'] ) ? $out['usage']['input_tokens'] : 0, isset( $out['usage']['output_tokens'] ) ? $out['usage']['output_tokens'] : 0 );
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
