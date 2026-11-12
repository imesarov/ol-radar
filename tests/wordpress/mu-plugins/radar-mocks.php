<?php
/**
 * Samo za lokalni test: lažni Google, DataForSEO i Anthropic odgovori (uključeni opcijom olr_test_mocks).
 * Google token mock provjerava potpis JWT-a javnim ključem iz opcije olr_test_pub.
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! get_option( 'olr_test_mocks' ) ) {
			return $pre;
		}
		$log   = get_option( 'olr_test_log', array() );
		$log[] = ( isset( $args['method'] ) ? $args['method'] : 'GET' ) . ' ' . $url;
		update_option( 'olr_test_log', array_slice( $log, -200 ), false );
		$json = static function ( $code, $body ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		// Google OAuth token: provjera JWT potpisa.
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) ) {
			$a     = isset( $args['body']['assertion'] ) ? $args['body']['assertion'] : '';
			$parts = explode( '.', $a );
			if ( 3 !== count( $parts ) ) {
				return $json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'bad jwt' ) );
			}
			$dec    = static function ( $s ) {
				return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
			};
			$claims = json_decode( $dec( $parts[1] ), true );
			$ok     = openssl_verify( $parts[0] . '.' . $parts[1], $dec( $parts[2] ), get_option( 'olr_test_pub' ), OPENSSL_ALGO_SHA256 );
			if ( 1 !== $ok || 'https://www.googleapis.com/auth/webmasters.readonly' !== $claims['scope'] || 'https://oauth2.googleapis.com/token' !== $claims['aud'] ) {
				return $json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) );
			}
			update_option( 'olr_test_tokens', (int) get_option( 'olr_test_tokens', 0 ) + 1, false );
			return $json( 200, array( 'access_token' => 'tok-' . wp_generate_password( 8, false ), 'expires_in' => 3599, 'token_type' => 'Bearer' ) );
		}

		// Search Console Search Analytics.
		if ( false !== strpos( $url, 'https://www.googleapis.com/webmasters/v3/sites/' ) ) {
			if ( get_option( 'olr_test_gsc_disabled' ) ) {
				return $json( 403, array( 'error' => array( 'code' => 403, 'message' => 'Google Search Console API has not been used in project 885 before or it is disabled.', 'status' => 'PERMISSION_DENIED', 'details' => array( array( 'reason' => 'SERVICE_DISABLED' ) ) ) ) );
			}
			if ( get_option( 'olr_test_gsc_403' ) ) {
				return $json( 403, array( 'error' => array( 'message' => 'User does not have sufficient permission for site.' ) ) );
			}
			$q    = json_decode( $args['body'], true );
			$dims = implode( ',', $q['dimensions'] );
			$rows = array();
			if ( 'date' === $dims ) {
				$d = new DateTime( $q['endDate'] );
				$s = new DateTime( $q['startDate'] );
				$i = 0;
				while ( $d >= $s ) {
					$rows[] = array( 'keys' => array( $d->format( 'Y-m-d' ) ), 'clicks' => 10 + $i % 5, 'impressions' => 400 + $i * 3, 'ctr' => 0.025, 'position' => 6.2 );
					$d->modify( '-1 day' );
					$i++;
				}
			} elseif ( 'query,page' === $dims ) {
				$base = array(
					array( 'cijena progresivnih naočala', 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/', 5, 120, 3.0 ),
					array( 'progresivne naočale cijena', 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/', 6, 210, 2.8 ),
					array( 'optika sesvete', 'https://www.optikaljiljana.hr/', 3, 139, 11.6 ),
					array( 'stellest stakla', 'https://www.optikaljiljana.hr/stellest-lece-za-djecu/', 1, 29, 3.5 ),
				);
				foreach ( $base as $b ) {
					$rows[] = array( 'keys' => array( $b[0], $b[1] ), 'clicks' => $b[2], 'impressions' => $b[3], 'ctr' => $b[2] / $b[3], 'position' => $b[4] );
				}
			} elseif ( 'page' === $dims ) {
				$rows[] = array( 'keys' => array( 'https://www.optikaljiljana.hr/' ), 'clicks' => 74, 'impressions' => 1489, 'ctr' => 0.05, 'position' => 3.9 );
				$rows[] = array( 'keys' => array( 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' ), 'clicks' => 11, 'impressions' => 330, 'ctr' => 0.033, 'position' => 2.9 );
			} elseif ( 'query' === $dims ) {
				$rows[] = array( 'keys' => array( 'progresivne naočale cijena' ), 'clicks' => 2, 'impressions' => 53, 'ctr' => 0.04, 'position' => 2.8 );
				$rows[] = array( 'keys' => array( 'optika sesvete' ), 'clicks' => 1, 'impressions' => 35, 'ctr' => 0.03, 'position' => 11.6 );
			}
			return $json( 200, array( 'rows' => $rows, 'responseAggregationType' => 'byProperty' ) );
		}

		// DataForSEO.
		if ( 0 === strpos( $url, 'https://api.dataforseo.com/v3/' ) ) {
			$auth = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';
			if ( 'Basic ' . base64_encode( 'ivan@test.hr:tajna-api' ) !== $auth ) {
				return $json( 401, array( 'status_code' => 40100, 'status_message' => 'Unauthorized' ) );
			}
			$path = substr( $url, strlen( 'https://api.dataforseo.com/v3/' ) );
			if ( 'appendix/user_data' === $path ) {
				return $json( 200, array( 'status_code' => 20000, 'tasks' => array( array( 'result' => array( array( 'money' => array( 'balance' => 0.93 ) ) ) ) ) ) );
			}
			if ( 'serp/google/organic/task_post' === $path ) {
				$tasks = json_decode( $args['body'], true );
				$out   = array();
				$map   = get_option( 'olr_test_dfs_tasks', array() );
				foreach ( $tasks as $i => $t ) {
					$id         = 'task-' . md5( $t['keyword'] . microtime() . $i );
					$map[ $id ] = $t;
					$out[]      = array( 'id' => $id, 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 0.0024, 'data' => $t );
				}
				update_option( 'olr_test_dfs_tasks', $map, false );
				return $json( 200, array( 'status_code' => 20000, 'cost' => 0.0024 * count( $tasks ), 'tasks' => $out ) );
			}
			if ( 0 === strpos( $path, 'serp/google/organic/task_get/advanced/' ) ) {
				$id  = rawurldecode( substr( $path, strlen( 'serp/google/organic/task_get/advanced/' ) ) );
				$map = get_option( 'olr_test_dfs_tasks', array() );
				if ( ! isset( $map[ $id ] ) ) {
					return $json( 200, array( 'status_code' => 20000, 'tasks' => array( array( 'id' => $id, 'status_code' => 40400, 'status_message' => 'Not Found.' ) ) ) );
				}
				$t = $map[ $id ];
				// Prvi dohvat za "optika" je još u redu (40602), drugi je gotov.
				$seen = get_option( 'olr_test_dfs_seen', array() );
				if ( 'optika' === $t['keyword'] && empty( $seen[ $id ] ) ) {
					$seen[ $id ] = 1;
					update_option( 'olr_test_dfs_seen', $seen, false );
					return $json( 200, array( 'status_code' => 20000, 'tasks' => array( array( 'id' => $id, 'status_code' => 40602, 'status_message' => 'Task In Queue.' ) ) ) );
				}
				$run   = (int) get_option( 'olr_test_dfs_run', 1 );
				$ranks = array(
					'cijena progresivnih naočala' => 1,
					'progresivne naočale cijena'  => 2 === $run ? 6 : 3,
					'optika sesvete'              => 2,
					'stellest stakla'             => 6,
					'optika'                      => null,
				);
				$our   = array_key_exists( $t['keyword'], $ranks ) ? $ranks[ $t['keyword'] ] : 8;
				$items = array( array( 'type' => 'local_pack', 'rank_group' => 1, 'domain' => 'maps' ) );
				for ( $p = 1; $p <= 12; $p++ ) {
					$is   = $our === $p;
					$items[] = array(
						'type'       => 'organic',
						'rank_group' => $p,
						'domain'     => $is ? 'www.optikaljiljana.hr' : 'konkurent' . $p . '.hr',
						'url'        => $is ? ( 'stellest stakla' === $t['keyword'] ? 'https://www.optikaljiljana.hr/journal/' : 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' ) : 'https://konkurent' . $p . '.hr/' . $p . '/',
						'title'      => 'Rezultat ' . $p,
					);
				}
				return $json( 200, array( 'status_code' => 20000, 'tasks' => array( array( 'id' => $id, 'status_code' => 20000, 'status_message' => 'Ok.', 'result' => array( array( 'keyword' => $t['keyword'], 'datetime' => gmdate( 'Y-m-d H:i:s +00:00' ), 'items' => $items ) ) ) ) ) );
			}
			return $json( 404, array( 'status_code' => 40400 ) );
		}

		// Anthropic Messages API.
		if ( 'https://api.anthropic.com/v1/messages' === $url ) {
			if ( 'sk-ant-test-1234' !== $args['headers']['x-api-key'] || '2023-06-01' !== $args['headers']['anthropic-version'] ) {
				return $json( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
			}
			$req = json_decode( $args['body'], true );
			update_option( 'olr_test_ai_last', $req, false );
			$last = end( $req['messages'] );
			// Ako su ponuđeni alati, a zadnja poruka nije rezultat alata: traži alat "pregled".
			if ( ! empty( $req['tools'] ) && is_string( $last['content'] ) ) {
				return $json( 200, array( 'id' => 'msg_1', 'model' => $req['model'], 'stop_reason' => 'tool_use', 'usage' => array( 'input_tokens' => 120, 'output_tokens' => 20 ), 'content' => array( array( 'type' => 'text', 'text' => 'Gledam podatke.' ), array( 'type' => 'tool_use', 'id' => 'tu_1', 'name' => $req['tools'][0]['name'], 'input' => new stdClass() ) ) ) );
			}
			$txt = is_array( $last['content'] ) ? 'Prema alatu: sve je u redu.' : ( false !== strpos( $last['content'], 'JSON' ) ? '{"dijagnoza":"test","koraci":["a"]}' : 'spreman' );
			return $json( 200, array( 'id' => 'msg_2', 'model' => $req['model'], 'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 80, 'output_tokens' => 12 ), 'content' => array( array( 'type' => 'text', 'text' => $txt ) ) ) );
		}
		return $pre;
	},
	10,
	3
);
