<?php
/**
 * Samo za lokalni test: lažni Google, DataForSEO, Anthropic, Meta, YouTube i TikTok odgovori (uključeni opcijom olr_test_mocks).
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

		// ---------- Društvene mreže ----------
		// Meta Graph API (Facebook stranica i Instagram). Opcija olr_test_meta: istekao = token ne vrijedi,
		// bez_ig = stranica nema Instagram, bez_batcha = batch poziv ne prolazi (čita se pojedinačno),
		// uza_polja = Meta odbija širi popis polja objava.
		if ( 0 === strpos( $url, 'https://graph.facebook.com/v26.0/' ) ) {
			$mode  = (string) get_option( 'olr_test_meta' );
			$path  = rawurldecode( substr( $url, strlen( 'https://graph.facebook.com/v26.0/' ) ) );
			$auth  = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';
			$body  = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
			$token = '' !== $auth ? preg_replace( '/^Bearer /', '', $auth ) : ( isset( $body['access_token'] ) ? $body['access_token'] : '' );
			update_option( 'olr_test_meta_calls', (int) get_option( 'olr_test_meta_calls', 0 ) + 1, false );
			if ( false !== strpos( $url, 'access_token=' ) ) {
				return $json( 400, array( 'error' => array( 'code' => 1, 'message' => 'TEST: token ne smije biti u adresi' ) ) );
			}
			if ( 'istekao' === $mode || ! in_array( $token, array( 'meta-test-token', 'page-token-1' ), true ) ) {
				return $json( 400, array( 'error' => array( 'code' => 190, 'type' => 'OAuthException', 'message' => 'Error validating access token: Session has expired.' ) ) );
			}
			$day = static function ( $n ) {
				return gmdate( 'Y-m-d\TH:i:s+0000', time() - $n * DAY_IN_SECONDS );
			};
			// Metrike jedne objave. Reel r3 ne podržava prosječno vrijeme gledanja (širi skup pada, uži prolazi);
			// stara objava m_stara nema metrika uopće.
			$insights = static function ( $rel ) {
				list( $id, $q ) = array_pad( explode( '/insights?metric=', $rel, 2 ), 2, '' );
				$metrics       = explode( ',', $q );
				$ig            = array(
					'r1' => array( 'reach' => 4200, 'views' => 9000, 'saved' => 120, 'shares' => 310, 'total_interactions' => 700, 'ig_reels_avg_watch_time' => 9000 ),
					'r2' => array( 'reach' => 900, 'views' => 2400, 'saved' => 10, 'shares' => 12, 'total_interactions' => 80, 'ig_reels_avg_watch_time' => 4000 ),
					'r3' => array( 'reach' => 700, 'views' => 1800, 'saved' => 8, 'shares' => 5, 'total_interactions' => 60 ),
					'r4' => array( 'reach' => 150, 'views' => 300, 'saved' => 1, 'shares' => 0, 'total_interactions' => 9, 'ig_reels_avg_watch_time' => 2000 ),
					'k1' => array( 'reach' => 800, 'views' => 1100, 'saved' => 40, 'shares' => 9, 'total_interactions' => 95 ),
					'k2' => array( 'reach' => 650, 'views' => 1000, 'saved' => 35, 'shares' => 4, 'total_interactions' => 70 ),
					'f1' => array( 'reach' => 300, 'views' => 420, 'saved' => 2, 'shares' => 1, 'total_interactions' => 30 ),
					'f2' => array( 'reach' => 280, 'views' => 400, 'saved' => 1, 'shares' => 0, 'total_interactions' => 22 ),
				);
				$fb            = array(
					'111_p1' => array( 'post_media_view' => 2400, 'post_total_media_view_unique' => 1500 ),
					'111_p2' => array( 'post_media_view' => 500, 'post_total_media_view_unique' => 380 ),
					'111_p3' => array( 'post_media_view' => 450, 'post_total_media_view_unique' => 300 ),
					'111_p4' => array( 'post_media_view' => 420, 'post_total_media_view_unique' => 290 ),
					'111_p5' => array( 'post_media_view' => 90, 'post_total_media_view_unique' => 70 ),
					'111_p6' => array( 'post_media_view' => 480 ),
				);
				$all           = isset( $ig[ $id ] ) ? $ig[ $id ] : ( isset( $fb[ $id ] ) ? $fb[ $id ] : null );
				if ( null === $all ) {
					return array( 400, array( 'error' => array( 'code' => 100, 'message' => '(#100) Media posted before business account conversion' ) ) );
				}
				$data = array();
				foreach ( $metrics as $m ) {
					if ( ! isset( $all[ $m ] ) ) {
						return array( 400, array( 'error' => array( 'code' => 100, 'message' => '(#100) metric[' . $m . '] is not supported for this media' ) ) );
					}
					$data[] = isset( $ig[ $id ] ) ? array( 'name' => $m, 'period' => 'lifetime', 'values' => array( array( 'value' => $all[ $m ] ) ) ) : array( 'name' => $m, 'period' => 'lifetime', 'total_value' => array( 'value' => $all[ $m ] ) );
				}
				return array( 200, array( 'data' => $data ) );
			};
			if ( '' === $path && isset( $body['batch'] ) ) {
				update_option( 'olr_test_meta_batch', (int) get_option( 'olr_test_meta_batch', 0 ) + 1, false );
				if ( 'bez_batcha' === $mode ) {
					return $json( 400, array( 'error' => array( 'code' => 2, 'message' => 'Service temporarily unavailable' ) ) );
				}
				$out = array();
				foreach ( json_decode( $body['batch'], true ) as $b ) {
					$r     = $insights( $b['relative_url'] );
					$out[] = array( 'code' => $r[0], 'body' => wp_json_encode( $r[1] ) );
				}
				return $json( 200, $out );
			}
			if ( false !== strpos( $path, '/insights?metric=' ) ) {
				$r = $insights( $path );
				return $json( $r[0], $r[1] );
			}
			if ( 0 === strpos( $path, 'me/accounts' ) ) {
				if ( 'page-token-1' === $token ) {
					return $json( 400, array( 'error' => array( 'code' => 100, 'message' => '(#100) Tried accessing nonexisting field (accounts)' ) ) );
				}
				$page = array( 'id' => '111', 'name' => 'Optika Ljiljana', 'access_token' => 'page-token-1' );
				if ( 'bez_ig' !== $mode ) {
					$page['instagram_business_account'] = array( 'id' => '17841', 'username' => 'optikaljiljana' );
				}
				return $json( 200, array( 'data' => array( array( 'id' => '222', 'name' => 'Druga stranica', 'access_token' => 'page-token-2' ), $page ) ) );
			}
			if ( 0 === strpos( $path, 'me?fields=' ) ) {
				return $json( 200, array( 'id' => '111', 'name' => 'Optika Ljiljana', 'instagram_business_account' => array( 'id' => '17841', 'username' => 'optikaljiljana' ) ) );
			}
			if ( 0 === strpos( $path, '17841?fields=business_discovery.username(' ) ) {
				preg_match( '/username\(([^)]+)\)/', $path, $m );
				if ( 'uzor_optika' !== $m[1] ) {
					return $json( 400, array( 'error' => array( 'code' => 110, 'message' => 'Invalid user id' ) ) );
				}
				$md = array();
				foreach ( array( array( 'u1', 900, 40, 30000, 'REELS', 5 ), array( 'u2', 100, 5, 2000, 'REELS', 9 ), array( 'u3', 90, 4, null, 'FEED', 15 ), array( 'u4', 110, 6, 2500, 'REELS', 20 ), array( 'u5', 95, 5, null, 'FEED', 30 ) ) as $x ) {
					$md[] = array( 'id' => $x[0], 'caption' => 'Uzor objava ' . $x[0], 'like_count' => $x[1], 'comments_count' => $x[2], 'view_count' => $x[3], 'media_type' => 'REELS' === $x[4] ? 'VIDEO' : 'IMAGE', 'media_product_type' => $x[4], 'permalink' => 'https://www.instagram.com/p/' . $x[0] . '/', 'timestamp' => $day( $x[5] ) );
				}
				return $json( 200, array( 'business_discovery' => array( 'username' => 'uzor_optika', 'followers_count' => 12000, 'media_count' => 300, 'media' => array( 'data' => $md ) ), 'id' => '17841' ) );
			}
			if ( 0 === strpos( $path, '17841?fields=' ) ) {
				return $json( 200, array( 'username' => 'optikaljiljana', 'name' => 'Optika Ljiljana', 'followers_count' => 2140, 'follows_count' => 300, 'media_count' => 412, 'id' => '17841' ) );
			}
			if ( 0 === strpos( $path, '17841/media?' ) ) {
				$md = array();
				foreach (
					array(
						array( 'r1', 'REELS', 'VIDEO', 'Zašto vam se vrti u novim progresivnim naočalama? Tri razloga.', 400, 35, 6 ),
						array( 'r2', 'REELS', 'VIDEO', 'Novi okviri su stigli.', 60, 4, 13 ),
						array( 'r3', 'REELS', 'VIDEO', 'Kako čistimo leće u radionici.', 50, 3, 20 ),
						array( 'r4', 'REELS', 'VIDEO', 'Radno vrijeme za blagdane.', 8, 0, 27 ),
						array( 'k1', 'FEED', 'CAROUSEL_ALBUM', 'Pet znakova da dijete slabije vidi.', 70, 6, 34 ),
						array( 'k2', 'FEED', 'CAROUSEL_ALBUM', 'Koliko traje provjera vida?', 55, 5, 41 ),
						array( 'f1', 'FEED', 'IMAGE', 'Sretan vikend.', 25, 1, 48 ),
						array( 'f2', 'FEED', 'IMAGE', 'Nova kolekcija.', 20, 1, 55 ),
						array( 'm_stara', 'FEED', 'IMAGE', 'Stara objava bez metrika.', 30, 2, 400 ),
					) as $x
				) {
					$md[] = array( 'id' => $x[0], 'caption' => $x[3], 'media_type' => $x[2], 'media_product_type' => $x[1], 'permalink' => 'https://www.instagram.com/p/' . $x[0] . '/', 'timestamp' => $day( $x[6] ), 'like_count' => $x[4], 'comments_count' => $x[5] );
				}
				return $json( 200, array( 'data' => $md ) );
			}
			if ( 0 === strpos( $path, '111?fields=' ) ) {
				return $json( 200, array( 'name' => 'Optika Ljiljana', 'followers_count' => 3350, 'fan_count' => 3300, 'link' => 'https://www.facebook.com/optikaljiljana', 'id' => '111' ) );
			}
			if ( 0 === strpos( $path, '111/posts?' ) ) {
				// uza_polja: Meta odbija širi popis polja (reakcije, privici), prolazi tek uži.
				if ( 'uza_polja' === $mode && false !== strpos( $path, 'reactions' ) ) {
					return $json( 400, array( 'error' => array( 'code' => 100, 'message' => '(#100) Tried accessing nonexisting field (reactions)' ) ) );
				}
				$ps = array();
				foreach ( array( array( 'p1', 'video', 'Kako biramo progresivne leće?', 90, 12, 25, 4 ), array( 'p2', 'photo', 'Novi okviri.', 14, 1, 0, 9 ), array( 'p3', 'photo', 'Radionica.', 12, 0, 1, 16 ), array( 'p4', 'album', 'Kolekcija jesen.', 11, 1, 0, 23 ), array( 'p5', 'share', 'Članak o kratkovidnosti.', 3, 0, 0, 30 ), array( 'p6', 'photo', 'Sretan vikend.', 13, 2, 0, 37 ) ) as $x ) {
					$row = array( 'id' => '111_' . $x[0], 'message' => $x[2], 'created_time' => $day( $x[6] ), 'permalink_url' => 'https://www.facebook.com/optikaljiljana/posts/' . $x[0], 'reactions' => array( 'data' => array(), 'summary' => array( 'total_count' => $x[3] ) ), 'comments' => array( 'data' => array(), 'summary' => array( 'total_count' => $x[4] ) ), 'attachments' => array( 'data' => array( array( 'media_type' => $x[1] ) ) ) );
					if ( $x[5] ) {
						$row['shares'] = array( 'count' => $x[5] );
					}
					$ps[] = $row;
				}
				return $json( 200, array( 'data' => $ps ) );
			}
			return $json( 400, array( 'error' => array( 'code' => 100, 'message' => 'TEST: nepoznat Graph poziv ' . $path ) ) );
		}

		// YouTube Data API v3. Opcija olr_test_yt_kvota = potrošena dnevna kvota.
		if ( 0 === strpos( $url, 'https://www.googleapis.com/youtube/v3/' ) ) {
			$path = substr( $url, strlen( 'https://www.googleapis.com/youtube/v3/' ) );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			update_option( 'olr_test_yt_calls', array_merge( (array) get_option( 'olr_test_yt_calls', array() ), array( strtok( $path, '?' ) ) ), false );
			if ( false !== strpos( $url, 'key=' ) ) {
				return $json( 400, array( 'error' => array( 'code' => 400, 'message' => 'TEST: ključ ne smije biti u adresi' ) ) );
			}
			if ( 'yt-test-key' !== ( isset( $args['headers']['X-goog-api-key'] ) ? $args['headers']['X-goog-api-key'] : '' ) ) {
				return $json( 400, array( 'error' => array( 'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.' ) ) );
			}
			if ( get_option( 'olr_test_yt_kvota' ) ) {
				return $json( 403, array( 'error' => array( 'code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.' ) ) );
			}
			$iso = static function ( $n ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', time() - $n * DAY_IN_SECONDS );
			};
			// id => [ naslov, kanal, trajanje, pregledi, lajkovi, komentari, prije dana ]
			$vids = array(
				'own1' => array( 'Progresivne naočale: kako se naviknuti', 'UCown', 'PT45S', 5200, 140, 12, 5 ),
				'own2' => array( 'Kako čistiti naočale', 'UCown', 'PT30S', 800, 20, 1, 12 ),
				'own3' => array( 'Predstavljamo radionicu', 'UCown', 'PT4M10S', 300, 9, 0, 19 ),
				'own4' => array( 'Stellest leće za djecu', 'UCown', 'PT58S', 900, 30, 3, 26 ),
				'own5' => array( 'Pregled vida korak po korak', 'UCown', 'PT6M', 700, 25, 2, 33 ),
				'own6' => array( 'Blagdansko radno vrijeme', 'UCown', 'PT20S', 120, 2, 0, 40 ),
				'ext1' => array( 'Progressive lenses explained in 60 seconds', 'UCa', 'PT59S', 480000, 9000, 300, 30 ),
				'ext2' => array( 'Zašto progresivne naočale ne valjaju (i kad valjaju)', 'UCb', 'PT8M', 52000, 1500, 210, 50 ),
				'ext3' => array( 'Optometrist reacts', 'UCa', 'PT12M', 90000, 2000, 150, 70 ),
			);
			if ( 0 === strpos( $path, 'channels?' ) ) {
				if ( isset( $q['forHandle'] ) || ( isset( $q['id'] ) && 'UCown' === $q['id'] ) ) {
					if ( isset( $q['forHandle'] ) && '@optikaljiljana' !== $q['forHandle'] ) {
						return $json( 200, array( 'items' => array() ) );
					}
					return $json( 200, array( 'items' => array( array( 'id' => 'UCown', 'snippet' => array( 'title' => 'Optika Ljiljana' ), 'statistics' => array( 'subscriberCount' => '310', 'videoCount' => '48', 'viewCount' => '61000' ), 'contentDetails' => array( 'relatedPlaylists' => array( 'uploads' => 'UUown' ) ) ) ) ) );
				}
				$items = array();
				foreach ( explode( ',', isset( $q['id'] ) ? $q['id'] : '' ) as $id ) {
					$subs = array( 'UCa' => '120000', 'UCb' => '4000' );
					if ( isset( $subs[ $id ] ) ) {
						$items[] = array( 'id' => $id, 'statistics' => array( 'subscriberCount' => $subs[ $id ] ) );
					}
				}
				return $json( 200, array( 'items' => $items ) );
			}
			if ( 0 === strpos( $path, 'playlistItems?' ) ) {
				$items = array();
				foreach ( array( 'own1', 'own2', 'own3', 'own4', 'own5', 'own6' ) as $id ) {
					$items[] = array( 'contentDetails' => array( 'videoId' => $id ) );
				}
				return $json( 'UUown' === $q['playlistId'] ? 200 : 404, array( 'items' => $items ) );
			}
			if ( 0 === strpos( $path, 'videos?' ) ) {
				$items = array();
				foreach ( explode( ',', $q['id'] ) as $id ) {
					if ( isset( $vids[ $id ] ) ) {
						$v       = $vids[ $id ];
						$items[] = array( 'id' => $id, 'snippet' => array( 'title' => $v[0], 'channelId' => $v[1], 'channelTitle' => 'UCown' === $v[1] ? 'Optika Ljiljana' : 'Kanal ' . $v[1], 'publishedAt' => $iso( $v[6] ) ), 'contentDetails' => array( 'duration' => $v[2] ), 'statistics' => array( 'viewCount' => (string) $v[3], 'likeCount' => (string) $v[4], 'commentCount' => (string) $v[5] ) );
					}
				}
				return $json( 200, array( 'items' => $items ) );
			}
			if ( 0 === strpos( $path, 'search?' ) ) {
				$by    = array(
					'progresivne naočale' => array( 'ext2', 'own1' ),
					'progressive lenses'  => array( 'ext1', 'ext3' ),
				);
				$items = array();
				foreach ( isset( $by[ $q['q'] ] ) ? $by[ $q['q'] ] : array() as $id ) {
					$items[] = array( 'id' => array( 'kind' => 'youtube#video', 'videoId' => $id ), 'snippet' => array( 'title' => $vids[ $id ][0] ) );
				}
				return $json( 'viewCount' === $q['order'] && 'video' === $q['type'] && ! empty( $q['publishedAfter'] ) ? 200 : 400, array( 'items' => $items ) );
			}
			return $json( 404, array( 'error' => array( 'code' => 404, 'message' => 'TEST: nepoznat YouTube poziv' ) ) );
		}

		// TikTok: OAuth tokeni i Display API.
		if ( 0 === strpos( $url, 'https://open.tiktokapis.com/v2/' ) ) {
			$path = strtok( substr( $url, strlen( 'https://open.tiktokapis.com/v2/' ) ), '?' );
			if ( 'oauth/token/' === $path ) {
				$b = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
				update_option( 'olr_test_tt_token_last', array_diff_key( $b, array( 'client_secret' => 1 ) ), false );
				if ( 'tt-key' !== ( isset( $b['client_key'] ) ? $b['client_key'] : '' ) || 'tt-secret' !== ( isset( $b['client_secret'] ) ? $b['client_secret'] : '' ) ) {
					return $json( 400, array( 'error' => 'invalid_client', 'error_description' => 'Client key or secret is incorrect.', 'log_id' => 'x' ) );
				}
				if ( 'authorization_code' === $b['grant_type'] && 'kod-123' === $b['code'] && ! empty( $b['redirect_uri'] ) ) {
					return $json( 200, array( 'access_token' => 'tt-access-1', 'expires_in' => 86400, 'open_id' => 'oid-1', 'refresh_token' => 'tt-refresh-1', 'refresh_expires_in' => 31536000, 'scope' => 'user.info.basic,user.info.stats,video.list', 'token_type' => 'Bearer' ) );
				}
				if ( 'refresh_token' === $b['grant_type'] && 'tt-refresh-1' === $b['refresh_token'] && ! get_option( 'olr_test_tt_refresh_fail' ) ) {
					update_option( 'olr_test_tt_refresh', (int) get_option( 'olr_test_tt_refresh', 0 ) + 1, false );
					return $json( 200, array( 'access_token' => 'tt-access-2', 'expires_in' => 86400, 'open_id' => 'oid-1', 'refresh_token' => 'tt-refresh-1', 'refresh_expires_in' => 31536000, 'scope' => 'user.info.basic,user.info.stats,video.list', 'token_type' => 'Bearer' ) );
				}
				return $json( 200, array( 'error' => 'invalid_grant', 'error_description' => 'Authorization code is expired.', 'log_id' => 'x' ) );
			}
			$auth = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';
			if ( ! in_array( $auth, array( 'Bearer tt-access-1', 'Bearer tt-access-2' ), true ) ) {
				return $json( 401, array( 'data' => new stdClass(), 'error' => array( 'code' => 'access_token_invalid', 'message' => 'The access token is invalid or not found in the request.', 'log_id' => 'x' ) ) );
			}
			update_option( 'olr_test_tt_auth_last', $auth, false );
			$okk = array( 'code' => 'ok', 'message' => '', 'log_id' => 'x' );
			if ( 'user/info/' === $path ) {
				return $json( 200, array( 'data' => array( 'user' => array( 'open_id' => 'oid-1', 'display_name' => 'Optika Ljiljana', 'follower_count' => 540, 'likes_count' => 8100, 'video_count' => 37 ) ), 'error' => $okk ) );
			}
			if ( 'video/list/' === $path ) {
				$b    = json_decode( $args['body'], true );
				$all  = array(
					array( 't1', 'Zašto se progresivne naočale „ljuljaju”?', 21, 31000, 1900, 80, 240, 3 ),
					array( 't2', 'Kako prepoznati da dijete slabije vidi', 34, 4100, 300, 20, 40, 8 ),
					array( 't3', 'Novi okviri', 12, 3800, 150, 4, 6, 15 ),
					array( 't4', 'Dan u radionici', 48, 3500, 120, 6, 5, 22 ),
					array( 't5', 'Radno vrijeme', 9, 600, 10, 0, 0, 29 ),
					array( 't6', 'Leće za računalo', 75, 3900, 210, 12, 30, 36 ),
				);
				$from = isset( $b['cursor'] ) ? 4 : 0;
				$vs   = array();
				foreach ( array_slice( $all, $from, 4 ) as $x ) {
					$vs[] = array( 'id' => $x[0], 'title' => $x[1], 'video_description' => $x[1] . ' #optika', 'duration' => $x[2], 'share_url' => 'https://www.tiktok.com/@optikaljiljana/video/' . $x[0], 'create_time' => time() - $x[7] * DAY_IN_SECONDS, 'view_count' => $x[3], 'like_count' => $x[4], 'comment_count' => $x[5], 'share_count' => $x[6] );
				}
				return $json( 200, array( 'data' => array( 'videos' => $vs, 'cursor' => 1700000000000, 'has_more' => 0 === $from ), 'error' => $okk ) );
			}
			return $json( 404, array( 'data' => new stdClass(), 'error' => array( 'code' => 'not_found', 'message' => 'TEST: nepoznat TikTok poziv', 'log_id' => 'x' ) ) );
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
				// Odgovor bez izmjena teksta, samo s koracima (npr. „pričekaj nove podatke”).
				if ( get_option( 'olr_test_ai_bez_izmjena' ) ) {
					$ch = array();
				}
				$out = array( 'naslov' => ( $tool ? 'AI: ' : 'AI (JSON): ' ) . $n['opis_vrste'], 'zasto' => 'Objašnjenje iz ulaza.', 'izmjene' => $ch, 'koraci' => array( 'Prvi korak', 'Drugi korak' ) );
				$c   = $tool
					? array( array( 'type' => 'tool_use', 'id' => 'toolu_p', 'name' => 'spremi_prijedlog', 'input' => $out ) )
					: array( array( 'type' => 'text', 'text' => "Evo:\n```json\n" . wp_json_encode( $out ) . "\n```" ) );
				return $json( 200, array( 'id' => 'msg_p', 'model' => $req['model'], 'stop_reason' => $tool ? 'tool_use' : 'end_turn', 'usage' => $use, 'content' => $c ) );
			}
			// Društvene mreže: preporuke prisilnim pozivom alata. Opcija olr_test_ai_mreze: prekid = prvi odgovor
			// prekinut zbog duljine (drugi, kraći, prolazi); prekid2 = oba prekinuta.
			if ( isset( $req['system'] ) && false !== strpos( $req['system'], '[zadatak: društvene mreže]' ) ) {
				$mode  = (string) get_option( 'olr_test_ai_mreze' );
				$short = false !== strpos( $req['system'], 'Skrati:' );
				update_option( 'olr_test_ai_mreze_calls', (int) get_option( 'olr_test_ai_mreze_calls', 0 ) + 1, false );
				if ( 'prekid2' === $mode || ( 'prekid' === $mode && ! $short ) ) {
					return $json( 200, array( 'id' => 'msg_m', 'model' => $req['model'], 'stop_reason' => 'max_tokens', 'usage' => array( 'input_tokens' => 2500, 'output_tokens' => 4000 ), 'content' => array( array( 'type' => 'tool_use', 'id' => 'toolu_m', 'name' => 'spremi_preporuke', 'input' => array() ) ) ) );
				}
				$in  = json_decode( $last['content'], true );
				$top = isset( $in['nase_mreze']['ig']['najbolje'][0]['pregledi'] ) ? $in['nase_mreze']['ig']['najbolje'][0]['pregledi'] : 0;
				$out = array(
					'sazetak'   => ( $short ? 'Kraće: ' : '' ) . 'Najbolji reel ima ' . $top . ' pregleda, što je višestruko iznad medijana.',
					'preporuke' => array(
						array( 'mreza' => 'ig', 'naslov' => 'Više reelova koji odgovaraju na jedno pitanje', 'zasto' => 'Reel s pitanjem u opisu ima ' . $top . ' pregleda.', 'koraci' => array( 'Prvi korak', 'Drugi korak', 'Treći korak', 'Četvrti korak', 'Peti korak' ) ),
						array( 'mreza' => 'linkedin', 'naslov' => 'Preporuka s nepoznatom mrežom <b>bez</b> HTML-a', 'zasto' => 'Zato.', 'koraci' => array( 'Korak' ) ),
						array( 'mreza' => 'yt', 'zasto' => 'Bez naslova: ne smije proći.', 'koraci' => array() ),
					),
					'ideje'     => array(
						array( 'mreza' => 'tt', 'format' => 'video do 30 s', 'naslov' => 'Zašto se u novim naočalama vrti', 'kuka' => 'Vrti vam se u novim naočalama?', 'zasto' => 'Najgledaniji TikTok je na istu temu.' ),
						array( 'mreza' => 'sve', 'format' => 'karusel', 'naslov' => 'Pet znakova da dijete slabije vidi', 'kuka' => 'Dijete se približava ekranu?' ),
					),
				);
				return $json( 200, array( 'id' => 'msg_m', 'model' => $req['model'], 'stop_reason' => 'tool_use', 'usage' => array( 'input_tokens' => 2500, 'output_tokens' => 600 ), 'content' => array( array( 'type' => 'tool_use', 'id' => 'toolu_m', 'name' => 'spremi_preporuke', 'input' => $out ) ) ) );
			}
			// JSON odgovor ploče (popravak nalaza, brief): prisilni poziv alata „odgovor”.
			// Opcija olr_test_ai_json: odbij = API odbija poziv alata (400), prekid = odgovor prekinut zbog duljine.
			if ( isset( $req['tool_choice']['name'] ) && 'odgovor' === $req['tool_choice']['name'] ) {
				$mode = get_option( 'olr_test_ai_json' );
				if ( 'odbij' === $mode ) {
					return $json( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'tool_choice: not supported for this model' ) ) );
				}
				if ( 'prekid' === $mode ) {
					return $json( 200, array( 'id' => 'msg_j', 'model' => $req['model'], 'stop_reason' => 'max_tokens', 'usage' => array( 'input_tokens' => 900, 'output_tokens' => 4096 ), 'content' => array( array( 'type' => 'tool_use', 'id' => 'toolu_j', 'name' => 'odgovor', 'input' => array() ) ) ) );
				}
				$in = array( 'dijagnoza' => 'Dijagnoza iz alata.', 'zasto' => 'Zato.', 'koraci' => array( 'Prvi korak', 'Drugi korak' ), 'gotov_tekst' => array( array( 'oznaka' => 'SEO naslov', 'tekst' => 'Progresivne naočale u Sesvetama' ) ), 'gdje' => 'Rank Math', 'ucinak' => 'Za 2–4 tjedna.', 'provjera' => 'Search Console.' );
				return $json( 200, array( 'id' => 'msg_j', 'model' => $req['model'], 'stop_reason' => 'tool_use', 'usage' => array( 'input_tokens' => 900, 'output_tokens' => 220 ), 'content' => array( array( 'type' => 'tool_use', 'id' => 'toolu_j', 'name' => 'odgovor', 'input' => $in ) ) ) );
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
