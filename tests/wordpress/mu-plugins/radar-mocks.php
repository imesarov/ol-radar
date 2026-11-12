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

		// Simulacija veze koja ne odgovara: auto = zadani način pada, v4h1 prolazi; www = prva adresa pada, rezervna prolazi; sve = ništa ne prolazi.
		$nf        = get_option( 'olr_test_net_fail' );
		$is_google = false !== strpos( $url, 'googleapis.com/' );
		$is_sc     = false !== strpos( $url, '/webmasters/v3/sites/' );
		if ( $nf && $is_google ) {
			$mode = class_exists( 'OLR_Net' ) ? OLR_Net::mode() : 'auto';
			if ( ( 'auto' === $nf && 'auto' === $mode ) || ( 'www' === $nf && $is_sc && false !== strpos( $url, 'https://www.googleapis.com/' ) ) || ( 'sve' === $nf && $is_sc ) ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' );
			}
		}

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

		// Search Console URL Inspection (indeksiranost). Rezultat ovisi o putanji stranice.
		if ( 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' === $url ) {
			$q = json_decode( $args['body'], true );
			update_option( 'olr_test_indeks_calls', (int) get_option( 'olr_test_indeks_calls', 0 ) + 1, false );
			update_option( 'olr_test_indeks_last', $q, false );
			if ( 0 !== strpos( isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '', 'Bearer tok-' ) ) {
				return $json( 401, array( 'error' => array( 'message' => 'Invalid Credentials' ) ) );
			}
			if ( get_option( 'olr_test_indeks_403' ) ) {
				return $json( 403, array( 'error' => array( 'code' => 403, 'message' => 'You do not own this site, or the inspected URL is not part of this property.' ) ) );
			}
			$u    = $q['inspectionUrl'];
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( '/sample-page/' === $path ) {
				return $json( 400, array( 'error' => array( 'code' => 400, 'message' => 'Invalid inspection URL.' ) ) );
			}
			$is = array(
				'verdict'         => 'PASS',
				'coverageState'   => 'Poslano i indeksirano',
				'robotsTxtState'  => 'ALLOWED',
				'indexingState'   => 'INDEXING_ALLOWED',
				'lastCrawlTime'   => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ),
				'pageFetchState'  => 'SUCCESSFUL',
				'googleCanonical' => $u,
				'userCanonical'   => $u,
				'crawledAs'       => 'MOBILE',
				'sitemap'         => array( 'https://www.optikaljiljana.hr/sitemap_index.xml' ),
			);
			if ( '/kontakt/' === $path ) {
				$is['verdict']       = 'NEUTRAL';
				$is['coverageState'] = 'Pretraženo – trenutačno nije indeksirano';
				$is['lastCrawlTime'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 20 * DAY_IN_SECONDS );
				unset( $is['googleCanonical'] );
			} elseif ( '/dioptrijske-naocale/' === $path ) {
				$is['googleCanonical'] = str_replace( '/dioptrijske-naocale/', '/progresivne-naocale/', $u );
			} elseif ( '/usluge-a/' === $path ) {
				$is['verdict']       = 'NEUTRAL';
				$is['coverageState'] = 'Isključeno oznakom "noindex"';
				$is['indexingState'] = 'BLOCKED_BY_META_TAG';
			} elseif ( '/usluge-b/' === $path ) {
				$is['verdict']        = 'FAIL';
				$is['coverageState']  = 'Greška poslužitelja (5xx)';
				$is['pageFetchState'] = 'SERVER_ERROR';
			}
			return $json( 200, array( 'inspectionResult' => array( 'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=test&id=' . md5( $u ), 'indexStatusResult' => $is ) ) );
		}

		// Search Console Search Analytics.
		if ( false !== strpos( $url, 'https://www.googleapis.com/webmasters/v3/sites/' ) || false !== strpos( $url, 'https://searchconsole.googleapis.com/webmasters/v3/sites/' ) ) {
			$extra = (bool) get_option( 'olr_test_gsc_extra' );
			if ( get_option( 'olr_test_gsc_disabled' ) ) {
				return $json( 403, array( 'error' => array( 'code' => 403, 'message' => 'Google Search Console API has not been used in project 885 before or it is disabled.', 'status' => 'PERMISSION_DENIED', 'details' => array( array( 'reason' => 'SERVICE_DISABLED' ) ) ) ) );
			}
			if ( get_option( 'olr_test_gsc_403' ) ) {
				return $json( 403, array( 'error' => array( 'message' => 'User does not have sufficient permission for site.' ) ) );
			}
			$q    = json_decode( $args['body'], true );
			$dims = implode( ',', $q['dimensions'] );
			$rows = array();
			if ( 'date' === $dims && ( ! empty( $q['dimensionFilterGroups'] ) || ( isset( $q['dataState'] ) && 'final' === $q['dataState'] ) ) ) {
				// Mjerenje učinka: konačni podaci kasne 3 dana. Uz filtar stranice brojke ovise o danu provedbe iz
				// opcije olr_test_ucinak (putanja => dan): poslije tog dana stranica ima više klikova i bolju poziciju.
				$last  = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS );
				$path  = null;
				$query = null;
				$map   = (array) get_option( 'olr_test_ucinak', array() );
				foreach ( ! empty( $q['dimensionFilterGroups'][0]['filters'] ) ? $q['dimensionFilterGroups'][0]['filters'] : array() as $f ) {
					if ( 'page' === $f['dimension'] ) {
						$path = '?';
						foreach ( array_merge( array_keys( $map ), array( '/', '/kontakt/', '/progresivne-naocale/', '/dioptrijske-naocale/', '/progresivne-naocalne-lece/' ) ) as $cand ) {
							if ( 'includingRegex' === $f['operator'] && preg_match( '~' . $f['expression'] . '~', 'https://www.optikaljiljana.hr' . $cand ) ) {
								$path = $cand;
							}
						}
					} elseif ( 'query' === $f['dimension'] ) {
						$query = $f['expression'];
					}
				}
				$d = new DateTime( min( $q['endDate'], $last ) );
				$s = new DateTime( $q['startDate'] );
				while ( $d >= $s ) {
					$day = $d->format( 'Y-m-d' );
					if ( null === $path ) {
						$row = array( 12, 400, 6.2 );                       // cijeli web: ravno
					} elseif ( isset( $map[ $path ] ) && $day > $map[ $path ] ) {
						$row = $query ? array( 2, 20, 5.0 ) : array( 3, 45, 4.0 ); // poslije izmjene
					} elseif ( '?' === $path ) {
						$row = null;                                        // nepoznata stranica: nema podataka
					} else {
						$row = $query ? array( 1, 20, 7.0 ) : array( 1, 40, 6.0 ); // prije izmjene (ili stranica bez izmjene)
					}
					if ( $row ) {
						$rows[] = array( 'keys' => array( $day ), 'clicks' => $row[0], 'impressions' => $row[1], 'ctr' => $row[0] / $row[1], 'position' => $row[2] );
					}
					$d->modify( '-1 day' );
				}
				update_option( 'olr_test_gsc_last_filter', isset( $q['dimensionFilterGroups'] ) ? $q['dimensionFilterGroups'] : null, false );
			} elseif ( 'date' === $dims ) {
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
				if ( $extra ) {
					// Bogatiji skup za test dnevne analize (putanje postoje u testnom webu).
					$h    = 'https://www.optikaljiljana.hr';
					$base = array_merge(
						$base,
						array(
							array( 'progresivne naočale', $h . '/progresivne-naocale/', 2, 400, 2.4 ),          // nizak CTR na visokoj poziciji
							array( 'kontakt optika test', $h . '/kontakt/', 3, 260, 7.8 ),                     // blizu vrha (upit je o toj stranici)
							array( 'kontakt optika test', $h . '/kontakt/?utm_source=google&rwg_token=abc', 0, 40, 2.0 ), // ista stranica s parametrima: spaja se
							array( 'naočale test', $h . '/dioptrijske-naocale/', 1, 60, 9.0 ),                  // kanibalizacija
							array( 'naočale test', $h . '/progresivne-naocale/', 0, 45, 12.0 ),
							array( 'dioptrijske naočale', $h . '/dioptrijske-naocale/', 1, 300, 2.0 ),          // nizak CTR, ali stranica je svježe mijenjana
							array( 'optika anda', $h . '/', 1, 80, 2.2 ),                                       // tuđa optika: nije o našoj stranici
							array( 'ljiljan', $h . '/', 0, 54, 2.5 ),                                           // brend po korijenu
							array( 'mrena na oku simptomi', $h . '/', 0, 180, 18.0 ),                           // nema prave stranice
							array( 'optika ljiljana sesvete', $h . '/', 40, 90, 1.2 ),                          // brend: ne ulazi
							array( 'privatno nešto', $h . '/privatno/', 0, 300, 3.0 ),                          // noindex: ne ulazi
						)
					);
				}
				foreach ( $base as $b ) {
					$rows[] = array( 'keys' => array( $b[0], $b[1] ), 'clicks' => $b[2], 'impressions' => $b[3], 'ctr' => $b[2] / $b[3], 'position' => $b[4] );
				}
			} elseif ( 'page' === $dims ) {
				$rows[] = array( 'keys' => array( 'https://www.optikaljiljana.hr/' ), 'clicks' => 74, 'impressions' => 1489, 'ctr' => 0.05, 'position' => 3.9 );
				$rows[] = array( 'keys' => array( 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' ), 'clicks' => 11, 'impressions' => 330, 'ctr' => 0.033, 'position' => 2.9 );
			} elseif ( 'query' === $dims ) {
				$rows[] = array( 'keys' => array( 'progresivne naočale cijena' ), 'clicks' => 2, 'impressions' => 53, 'ctr' => 0.04, 'position' => 2.8 );
				$rows[] = array( 'keys' => array( 'optika sesvete' ), 'clicks' => 1, 'impressions' => 35, 'ctr' => 0.03, 'position' => 11.6 );
				if ( $extra ) {
					// Prethodni tjedan (stariji kraj razdoblja) bolji je od zadnjeg: pad.
					$prev   = $q['endDate'] < gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
					$rows[] = $prev
						? array( 'keys' => array( 'progresivne naočale' ), 'clicks' => 9, 'impressions' => 120, 'ctr' => 0.075, 'position' => 2.1 )
						: array( 'keys' => array( 'progresivne naočale' ), 'clicks' => 2, 'impressions' => 95, 'ctr' => 0.021, 'position' => 2.6 );
				}
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
				$types = array( 'local_pack', 'organic' );
				// AI pregled: za jednu riječ citira nas, za drugu ne; za treću postoji, ali se učitava naknadno
				// (stiže samo ako zadatak ima load_async_ai_overview).
				$ref = static function ( $domain, $url ) {
					return array( 'type' => 'ai_overview_reference', 'source' => $domain, 'domain' => $domain, 'url' => $url, 'title' => 'Izvor', 'text' => 'Tekst izvora' );
				};
				if ( 'cijena progresivnih naočala' === $t['keyword'] ) {
					$types[] = 'ai_overview';
					$items[] = array(
						'type'       => 'ai_overview',
						'rank_group' => 1,
						'markdown'   => 'Cijena ovisi o lećama.',
						'items'      => array( array( 'type' => 'ai_overview_element', 'title' => 'Cijena', 'text' => 'Ovisi o lećama.', 'references' => array( $ref( 'www.optikaljiljana.hr', 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' ) ) ) ),
						'references' => array( $ref( 'konkurent1.hr', 'https://konkurent1.hr/1/' ), $ref( 'hr.wikipedia.org', 'https://hr.wikipedia.org/wiki/Leća' ) ),
					);
				} elseif ( 'optika sesvete' === $t['keyword'] ) {
					$types[] = 'ai_overview';
					$items[] = array( 'type' => 'ai_overview', 'rank_group' => 1, 'markdown' => 'Optike u Sesvetama.', 'references' => array( $ref( 'www.konkurent2.hr', 'https://www.konkurent2.hr/2/' ), $ref( 'konkurent4.hr', 'https://konkurent4.hr/4/' ) ) );
				} elseif ( 'stellest stakla' === $t['keyword'] ) {
					$types[] = 'ai_overview';
					if ( ! empty( $t['load_async_ai_overview'] ) ) {
						$items[] = array( 'type' => 'ai_overview', 'rank_group' => 1, 'asynchronous_ai_overview' => true, 'markdown' => 'Stellest leće.', 'references' => array( $ref( 'essilor.com', 'https://www.essilor.com/stellest' ) ) );
					}
				}
				for ( $p = 1; $p <= 12; $p++ ) {
					$is  = $our === $p;
					// Od drugog mjerenja: na 10. mjesto ulazi nova domena, a dotadašnja pada na 11.
					$dom = 'konkurent' . $p . '.hr';
					if ( $run >= 2 && 10 === $p ) {
						$dom = 'novi-igrac.hr';
					} elseif ( $run >= 2 && 11 === $p ) {
						$dom = 'konkurent10.hr';
					}
					$items[] = array(
						'type'       => 'organic',
						'rank_group' => $p,
						'domain'     => $is ? 'www.optikaljiljana.hr' : $dom,
						'url'        => $is ? ( 'stellest stakla' === $t['keyword'] ? 'https://www.optikaljiljana.hr/journal/' : 'https://www.optikaljiljana.hr/progresivne-naocalne-lece/' ) : 'https://' . $dom . '/' . $p . '/',
						'title'      => 'Rezultat ' . $p,
					);
				}
				return $json( 200, array( 'status_code' => 20000, 'tasks' => array( array( 'id' => $id, 'status_code' => 20000, 'status_message' => 'Ok.', 'result' => array( array( 'keyword' => $t['keyword'], 'datetime' => gmdate( 'Y-m-d H:i:s +00:00' ), 'item_types' => $types, 'items' => $items ) ) ) ) ) );
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
			// Dnevni prijedlozi: za svaki nalaz vrati izmjene (jedan predug naslov i jedan link na nepostojeći izvor, za provjeru čišćenja).
			if ( isset( $req['system'] ) && false !== strpos( $req['system'], '[zadatak: dnevni prijedlozi]' ) ) {
				$tool = ! empty( $req['tools'] ) && isset( $req['tool_choice']['name'] ) && 'spremi_prijedlog' === $req['tool_choice']['name'];
				$use  = array( 'input_tokens' => 700, 'output_tokens' => 300 );
				// Prekinut ili neupotrebljiv odgovor u oba načina.
				if ( get_option( 'olr_test_ai_bad_json' ) ) {
					$c = $tool ? array( array( 'type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'spremi_prijedlog', 'input' => array() ) ) : array( array( 'type' => 'text', 'text' => '{"naslov":"Prekinut' ) );
					return $json( 200, array( 'id' => 'msg_p', 'model' => $req['model'], 'stop_reason' => 'max_tokens', 'usage' => $use, 'content' => $c ) );
				}
				// API odbija prisilni poziv alata: mora proći rezervni način s JSON-om u tekstu.
				if ( $tool && get_option( 'olr_test_ai_tool_fail' ) ) {
					return $json( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'tool_choice: not supported for this model' ) ) );
				}
				$n  = json_decode( $last['content'], true );
				$ch = array();
				if ( 'ctr' === $n['vrsta'] ) {
					$ch[] = array( 'polje' => 'seo_naslov', 'gdje' => 'Rank Math → SEO naslov', 'novo' => 'Progresivne naočale u Sesvetama: pregled vida, Varilux leće i prilagodba' );
					$ch[] = array( 'polje' => 'meta_opis', 'gdje' => 'Rank Math → opis', 'novo' => 'Progresivne naočale po mjeri u Sesvetama. Pregled vida od 60 minuta, Varilux leće i besplatna prilagodba. Rezervirajte termin.' );
				} elseif ( ! empty( $n['izvori_linka'] ) ) {
					$ch[] = array( 'polje' => 'odlomak', 'gdje' => 'ispod uvoda', 'novo' => 'H2: Odgovor na upit. Dvije do tri rečenice o temi.' );
					$ch[] = array( 'polje' => 'interni_link', 'gdje' => $n['izvori_linka'][0]['put'], 'novo' => 'tekst sidra' );
					$ch[] = array( 'polje' => 'interni_link', 'gdje' => '/nepostojeca-stranica/', 'novo' => 'ne smije proći' );
				} else {
					$ch[] = array( 'polje' => 'tehnicki', 'gdje' => isset( $n['stranica']['put'] ) ? $n['stranica']['put'] : '', 'novo' => 'Konkretna izmjena <b>bez</b> HTML-a.' );
					$ch[] = array( 'polje' => 'nepoznato_polje', 'novo' => 'ne smije proći' );
				}
				$out = array( 'naslov' => ( $tool ? 'AI: ' : 'AI (JSON): ' ) . $n['opis_vrste'], 'zasto' => 'Objašnjenje iz ulaza.', 'izmjene' => $ch, 'koraci' => array( 'Prvi korak', 'Drugi korak' ) );
				$c   = $tool
					? array( array( 'type' => 'tool_use', 'id' => 'toolu_p', 'name' => 'spremi_prijedlog', 'input' => $out ) )
					: array( array( 'type' => 'text', 'text' => "Evo:\n```json\n" . wp_json_encode( $out ) . "\n```" ) );
				return $json( 200, array( 'id' => 'msg_p', 'model' => $req['model'], 'stop_reason' => $tool ? 'tool_use' : 'end_turn', 'usage' => $use, 'content' => $c ) );
			}
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
