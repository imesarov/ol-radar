<?php
/**
 * Društvene mreže na hostingu: Instagram i Facebook (Meta Graph API), YouTube (Data API v3) i TikTok (Display API).
 *
 * Što radi:
 *  - skuplja vlastite objave i njihove brojke (pregledi, doseg, lajkovi, komentari, dijeljenja, spremanja, gledanost);
 *  - računa što prolazi, a što ne: svaka objava prema medijanu vlastite mreže, uz obrasce (vrsta, trajanje, dan);
 *  - skuplja „uzore”: najgledanije YouTube videe po zadanim temama i objave javnih Instagram profila koje vlasnik navede;
 *  - Claude iz tih brojki piše što raditi (preporuke i ideje za objave). Brojke su uvijek iz podataka.
 *
 * Samo čitanje: ništa se ne objavljuje, ne briše i ne mijenja na mrežama. Pristupne podatke upisuje samo vlasnik
 * u Postavkama; tokeni se ne vraćaju pregledniku ni Claudeu.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Social {

	const GRAPH     = 'https://graph.facebook.com/v26.0/';
	const YT        = 'https://www.googleapis.com/youtube/v3/';
	const TT_AUTH   = 'https://www.tiktok.com/v2/auth/authorize/';
	const TT_API    = 'https://open.tiktokapis.com/v2/';
	const TT_SCOPES = 'user.info.basic,user.info.stats,video.list';
	const COLL      = 'mreze';
	const TT_OPT    = 'olr_tt_tokens';
	const RUN_OPT   = 'olr_mreze_run';
	const LOCK      = 'olr_mreze_lock';
	const TOOL      = 'spremi_preporuke';
	const MANUAL_MAX = 4;
	const NETS      = array(
		'ig' => 'Instagram',
		'fb' => 'Facebook',
		'yt' => 'YouTube',
		'tt' => 'TikTok',
	);

	/* ---------- pomoćno ---------- */

	private static function cut( $s, $n ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	private static function num( $a, $k ) {
		return is_array( $a ) && isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ? (int) $a[ $k ] : null;
	}

	/** Popis iz postavke (zarez ili novi red), bez praznih i duplih. */
	private static function list_setting( $key, $max = 12 ) {
		$out = array();
		foreach ( preg_split( '/[,\r\n]+/', (string) OLR_Settings::get( $key ) ) as $x ) {
			$x = trim( $x );
			if ( '' !== $x && ! in_array( $x, $out, true ) ) {
				$out[] = $x;
			}
		}
		return array_slice( $out, 0, $max );
	}

	/** Broj mrežnih neuspjeha po poslužitelju u ovom zahtjevu (nakon dva se taj poslužitelj više ne zove). */
	private static $down = array();

	/**
	 * HTTP poziv s JSON odgovorom. Tokeni idu u zaglavlje ili tijelo, nikad u adresu (adrese završavaju
	 * u dnevnicima). Na dijeljenom hostingu zahtjev zna ostati bez odgovora zbog IPv6 rute, pa se nakon
	 * mrežne greške ponavlja prisilno preko IPv4 (isti mehanizam kao za Google Search Console).
	 *
	 * @return array|WP_Error
	 */
	private static function http( $method, $url, $headers = array(), $body = null ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( isset( self::$down[ $host ] ) && self::$down[ $host ] >= 2 ) {
			return new WP_Error( 'olr_mreze_veza', 'veza s ' . $host . ' ne radi (preskočeno nakon dva neuspjela pokušaja)' );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$pref = OLR_Net::preferred();
		$res  = OLR_Net::post( $url, $args, $pref['nacin'] );
		if ( is_wp_error( $res ) && 'v4h1' !== $pref['nacin'] ) {
			$res = OLR_Net::post( $url, $args, 'v4h1' );
		}
		if ( is_wp_error( $res ) ) {
			self::$down[ $host ] = ( isset( self::$down[ $host ] ) ? self::$down[ $host ] : 0 ) + 1;
			return new WP_Error( 'olr_mreze_veza', 'veza s ' . $host . ' nije uspjela: ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'olr_mreze_odgovor', 'neočekivan odgovor (HTTP ' . $code . ')' );
		}
		$err = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : null;
		// TikTok vraća error.code = "ok" i kad je sve u redu.
		if ( $err && isset( $err['code'] ) && 'ok' === $err['code'] ) {
			$err = null;
		}
		// OAuth oblik greške (TikTokova krajnja točka za tokene): error je riječ, opis je u error_description.
		if ( ! $err && isset( $json['error'] ) && is_string( $json['error'] ) && '' !== $json['error'] ) {
			$err = array(
				'code'    => $json['error'],
				'message' => isset( $json['error_description'] ) ? (string) $json['error_description'] : $json['error'],
			);
		}
		if ( $code >= 400 || $err ) {
			$msg = $err && ! empty( $err['message'] ) ? (string) $err['message'] : 'HTTP ' . $code;
			return new WP_Error(
				'olr_mreze_api',
				self::cut( $msg, 300 ),
				array(
					'http' => $code,
					'kod'  => $err && isset( $err['code'] ) ? $err['code'] : null,
				)
			);
		}
		return $json;
	}

	private static function save( $net, $doc ) {
		$doc['at'] = time() * 1000;
		OLR_Store::set( self::COLL, $net, $doc );
	}

	private static function fail( $net, $e ) {
		OLR_Settings::set_status( 'mreze_' . $net, false, $e->get_error_message() );
		return $e;
	}

	public static function connected() {
		return array(
			'ig' => (bool) OLR_Settings::secret( 'meta_token' ),
			'fb' => (bool) OLR_Settings::secret( 'meta_token' ),
			'yt' => (bool) OLR_Settings::secret( 'yt_key' ) && '' !== trim( (string) OLR_Settings::get( 'yt_channel' ) ),
			'tt' => (bool) self::tt_tokens(),
		);
	}

	/* ---------- Meta: Facebook stranica i Instagram ---------- */

	private static function graph( $path, $token ) {
		return self::http( 'GET', self::GRAPH . $path, array( 'Authorization' => 'Bearer ' . $token ) );
	}

	/**
	 * Prvi upit s popisa koji prođe. Ako Meta odbije širi popis polja (npr. polje za koje token nema
	 * dopuštenje), sljedeći je uži, pa se preuzme barem osnovno. Nevaljan token prekida odmah.
	 */
	private static function graph_first( $paths, $token ) {
		$r = null;
		foreach ( $paths as $path ) {
			$r = self::graph( $path, $token );
			if ( ! is_wp_error( $r ) ) {
				return $r;
			}
			$d = (array) $r->get_error_data();
			if ( 'olr_mreze_veza' === $r->get_error_code() || ( isset( $d['kod'] ) && 190 === (int) $d['kod'] ) ) {
				break;
			}
		}
		return $r;
	}

	private static function graph_message( $e ) {
		$m = $e->get_error_message();
		$d = (array) $e->get_error_data();
		if ( isset( $d['kod'] ) && 190 === (int) $d['kod'] ) {
			return 'Meta token nije valjan ili je istekao. Generiraj novi (sistemski korisnik, trajanje „Nikad”) i upiši ga u Postavke.';
		}
		if ( isset( $d['kod'] ) && in_array( (int) $d['kod'], array( 10, 200 ), true ) ) {
			return 'Meta tokenu nedostaje dopuštenje: ' . $m . ' Potrebna su pages_show_list, pages_read_engagement, read_insights, instagram_basic i instagram_manage_insights.';
		}
		return 'Meta: ' . $m;
	}

	/**
	 * Stranica i Instagram račun kojima token ima pristup.
	 *
	 * @return array|WP_Error [ page_id, page_name, page_token, ig_id, ig_user ]
	 */
	public static function meta_context() {
		$token = OLR_Settings::secret( 'meta_token' );
		if ( ! $token ) {
			return new WP_Error( 'olr_meta_nema', 'Meta token nije postavljen (OL Radar → Postavke).' );
		}
		$pages = array();
		$r     = self::graph( 'me/accounts?fields=id,name,access_token,instagram_business_account{id,username}&limit=50', $token );
		if ( ! is_wp_error( $r ) && ! empty( $r['data'] ) ) {
			$pages = $r['data'];
		} else {
			// Token same stranice: „me” je stranica.
			$me = self::graph( 'me?fields=id,name,instagram_business_account{id,username}', $token );
			if ( is_wp_error( $me ) ) {
				return new WP_Error( 'olr_meta_api', self::graph_message( is_wp_error( $r ) ? $r : $me ) );
			}
			if ( empty( $me['id'] ) ) {
				return new WP_Error( 'olr_meta_stranica', 'Meta token ne vidi nijednu Facebook stranicu. Sistemskom korisniku dodijeli stranicu i Instagram račun.' );
			}
			$me['access_token'] = $token;
			$pages              = array( $me );
		}
		$want = OLR_DFS::lower( trim( (string) OLR_Settings::get( 'meta_page' ) ) );
		$pick = null;
		foreach ( $pages as $p ) {
			if ( '' !== $want && ( $want === (string) $p['id'] || false !== strpos( OLR_DFS::lower( $p['name'] ), $want ) ) ) {
				$pick = $p;
				break;
			}
		}
		if ( ! $pick ) {
			foreach ( $pages as $p ) {
				if ( ! empty( $p['instagram_business_account']['id'] ) ) {
					$pick = $p;
					break;
				}
			}
		}
		$pick = $pick ? $pick : $pages[0];
		return array(
			'page_id'    => (string) $pick['id'],
			'page_name'  => isset( $pick['name'] ) ? (string) $pick['name'] : '',
			'page_token' => ! empty( $pick['access_token'] ) ? (string) $pick['access_token'] : $token,
			'ig_id'      => isset( $pick['instagram_business_account']['id'] ) ? (string) $pick['instagram_business_account']['id'] : '',
			'ig_user'    => isset( $pick['instagram_business_account']['username'] ) ? (string) $pick['instagram_business_account']['username'] : '',
			'stranica'   => count( $pages ),
		);
	}

	/** Vrijednosti iz odgovora insights krajnje točke: ime metrike => broj. */
	private static function insight_values( $r ) {
		$out = array();
		foreach ( isset( $r['data'] ) && is_array( $r['data'] ) ? $r['data'] : array() as $m ) {
			$v = null;
			if ( isset( $m['values'][0]['value'] ) && is_numeric( $m['values'][0]['value'] ) ) {
				$v = $m['values'][0]['value'];
			} elseif ( isset( $m['total_value']['value'] ) && is_numeric( $m['total_value']['value'] ) ) {
				$v = $m['total_value']['value'];
			}
			if ( null !== $v && ! empty( $m['name'] ) ) {
				$out[ $m['name'] ] = $v + 0;
			}
		}
		return $out;
	}

	/** Metrike jedne objave; ako Meta odbije skup (metrika ne postoji za tu vrstu objave), pokušava s užim. */
	private static function insights( $id, $sets, $token ) {
		foreach ( $sets as $set ) {
			$r = self::graph( $id . '/insights?metric=' . $set, $token );
			if ( ! is_wp_error( $r ) ) {
				return self::insight_values( $r );
			}
			$d = (array) $r->get_error_data();
			if ( isset( $d['kod'] ) && 190 === (int) $d['kod'] ) {
				break; // token ne vrijedi: nema smisla dalje
			}
		}
		return array();
	}

	/**
	 * Metrike više objava odjednom (Graph batch: do 50 upita u jednom pozivu, svaki uspije ili padne za sebe).
	 * Objava kojoj Meta odbije širi skup metrika ide u sljedeći krug s užim skupom. Ako batch kao cjelina
	 * ne prođe, metrike se čitaju jedna po jedna dok ima vremena.
	 *
	 * @param array  $want  id objave => popis skupova metrika, od najšireg prema najužem.
	 * @param string $token Token stranice.
	 * @return array id objave => [ metrika => vrijednost ]
	 */
	private static function insights_many( $want, $token ) {
		$out  = array();
		$left = $want;
		for ( $round = 0; $round < 3 && $left; $round++ ) {
			$ids   = array();
			$batch = array();
			foreach ( $left as $id => $sets ) {
				if ( isset( $sets[ $round ] ) ) {
					$ids[]   = (string) $id;
					$batch[] = array(
						'method'       => 'GET',
						'relative_url' => $id . '/insights?metric=' . $sets[ $round ],
					);
				}
			}
			if ( ! $batch ) {
				break;
			}
			foreach ( array_chunk( $batch, 50 ) as $ci => $chunk ) {
				// Token ide u tijelo zahtjeva, ne u adresu.
				$r = self::http(
					'POST',
					self::GRAPH,
					array(),
					array(
						'access_token'    => $token,
						'include_headers' => 'false',
						'batch'           => wp_json_encode( $chunk ),
					)
				);
				if ( is_wp_error( $r ) || ! isset( $r[0] ) ) {
					// Batch nije prošao: pojedinačno, najviše 25 sekundi.
					$t0 = time();
					foreach ( $left as $id => $sets ) {
						if ( time() - $t0 > 25 ) {
							break;
						}
						$v = self::insights( $id, $sets, $token );
						if ( $v ) {
							$out[ (string) $id ] = $v;
						}
					}
					return $out;
				}
				foreach ( $r as $i => $item ) {
					$id   = isset( $ids[ $ci * 50 + $i ] ) ? $ids[ $ci * 50 + $i ] : null;
					$body = is_array( $item ) && isset( $item['body'] ) ? json_decode( (string) $item['body'], true ) : null;
					if ( null !== $id && is_array( $item ) && isset( $item['code'] ) && 200 === (int) $item['code'] && is_array( $body ) ) {
						$out[ $id ] = self::insight_values( $body );
						unset( $left[ $id ] );
					}
				}
			}
		}
		return $out;
	}

	public static function collect_ig() {
		$ctx = self::meta_context();
		if ( is_wp_error( $ctx ) ) {
			return self::fail( 'ig', $ctx );
		}
		if ( '' === $ctx['ig_id'] ) {
			return self::fail( 'ig', new WP_Error( 'olr_ig_nema', 'Facebook stranica „' . $ctx['page_name'] . '” nema povezan profesionalni Instagram račun (Instagram → Postavke → Vrsta računa, pa povezivanje sa stranicom).' ) );
		}
		$token = $ctx['page_token'];
		$acc   = self::graph_first( array( $ctx['ig_id'] . '?fields=username,name,followers_count,follows_count,media_count', $ctx['ig_id'] . '?fields=username' ), $token );
		if ( is_wp_error( $acc ) ) {
			return self::fail( 'ig', new WP_Error( 'olr_ig_api', self::graph_message( $acc ) ) );
		}
		$media = self::graph_first(
			array(
				$ctx['ig_id'] . '/media?fields=id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count&limit=40',
				$ctx['ig_id'] . '/media?fields=id,caption,media_type,permalink,timestamp&limit=40',
			),
			$token
		);
		if ( is_wp_error( $media ) ) {
			return self::fail( 'ig', new WP_Error( 'olr_ig_api', self::graph_message( $media ) ) );
		}
		$list = isset( $media['data'] ) && is_array( $media['data'] ) ? $media['data'] : array();
		$want = array();
		foreach ( $list as $m ) {
			$want[ (string) $m['id'] ] = isset( $m['media_product_type'] ) && 'REELS' === $m['media_product_type']
				? array( 'reach,views,saved,shares,total_interactions,ig_reels_avg_watch_time', 'reach,views,saved,shares,total_interactions', 'reach,saved' )
				: array( 'reach,views,saved,shares,total_interactions', 'reach,views,saved', 'reach,saved' );
		}
		$all   = $want ? self::insights_many( $want, $token ) : array();
		$posts = array();
		$noins = 0;
		foreach ( $list as $m ) {
			$reel = isset( $m['media_product_type'] ) && 'REELS' === $m['media_product_type'];
			$type = isset( $m['media_type'] ) ? $m['media_type'] : '';
			$ins  = isset( $all[ (string) $m['id'] ] ) ? $all[ (string) $m['id'] ] : array();
			$noins  += $ins ? 0 : 1;
			$posts[] = array(
				'm'          => 'ig',
				'id'         => (string) $m['id'],
				'url'        => isset( $m['permalink'] ) ? $m['permalink'] : '',
				't'          => isset( $m['timestamp'] ) ? gmdate( 'c', strtotime( $m['timestamp'] ) ) : null,
				'vrsta'      => $reel ? 'reel' : ( 'CAROUSEL_ALBUM' === $type ? 'karusel' : ( 'VIDEO' === $type ? 'video' : 'objava' ) ),
				'opis'       => self::cut( isset( $m['caption'] ) ? $m['caption'] : '', 220 ),
				'trajanje'   => null,
				'pregledi'   => self::num( $ins, 'views' ),
				'doseg'      => self::num( $ins, 'reach' ),
				'lajkovi'    => self::num( $m, 'like_count' ),
				'komentari'  => self::num( $m, 'comments_count' ),
				'dijeljenja' => self::num( $ins, 'shares' ),
				'spremanja'  => self::num( $ins, 'saved' ),
				// Meta vraća prosječno vrijeme gledanja reela u milisekundama.
				'gledano_s'  => isset( $ins['ig_reels_avg_watch_time'] ) ? round( $ins['ig_reels_avg_watch_time'] / 1000, 1 ) : null,
			);
		}
		self::save(
			'ig',
			array(
				'racun'  => array(
					'ime'        => isset( $acc['username'] ) ? '@' . $acc['username'] : $ctx['ig_user'],
					'pratitelji' => self::num( $acc, 'followers_count' ),
					'objava'     => self::num( $acc, 'media_count' ),
				),
				'objave' => $posts,
			)
		);
		OLR_Settings::set_status( 'mreze_ig', true, count( $posts ) . ' objava' . ( $noins ? ' (za ' . $noins . ' Meta nije dala metrike)' : '' ) . ' · ' . ( isset( $acc['username'] ) ? '@' . $acc['username'] : '' ) );
		return count( $posts );
	}

	public static function collect_fb() {
		$ctx = self::meta_context();
		if ( is_wp_error( $ctx ) ) {
			return self::fail( 'fb', $ctx );
		}
		$token = $ctx['page_token'];
		$page  = self::graph_first( array( $ctx['page_id'] . '?fields=name,followers_count,fan_count,link', $ctx['page_id'] . '?fields=name' ), $token );
		if ( is_wp_error( $page ) ) {
			return self::fail( 'fb', new WP_Error( 'olr_fb_api', self::graph_message( $page ) ) );
		}
		$list = self::graph_first(
			array(
				$ctx['page_id'] . '/posts?fields=id,message,created_time,permalink_url,shares,reactions.limit(0).summary(true),comments.limit(0).summary(true),attachments{media_type}&limit=30',
				$ctx['page_id'] . '/posts?fields=id,message,created_time,permalink_url,shares&limit=30',
				$ctx['page_id'] . '/posts?fields=id,message,created_time&limit=30',
			),
			$token
		);
		if ( is_wp_error( $list ) ) {
			return self::fail( 'fb', new WP_Error( 'olr_fb_api', self::graph_message( $list ) ) );
		}
		$rows = isset( $list['data'] ) && is_array( $list['data'] ) ? $list['data'] : array();
		$want = array();
		foreach ( $rows as $p ) {
			// Meta je ukinula „impressions”: pregledi su post_media_view, a doseg post_total_media_view_unique.
			$want[ (string) $p['id'] ] = array( 'post_media_view,post_total_media_view_unique', 'post_media_view' );
		}
		$all   = $want ? self::insights_many( $want, $token ) : array();
		$posts = array();
		$noins = 0;
		$types = array(
			'video' => 'video',
			'photo' => 'objava',
			'album' => 'karusel',
			'share' => 'poveznica',
		);
		foreach ( $rows as $p ) {
			$ins = isset( $all[ (string) $p['id'] ] ) ? $all[ (string) $p['id'] ] : array();
			$noins  += $ins ? 0 : 1;
			$mt      = isset( $p['attachments']['data'][0]['media_type'] ) ? $p['attachments']['data'][0]['media_type'] : '';
			$posts[] = array(
				'm'          => 'fb',
				'id'         => (string) $p['id'],
				'url'        => isset( $p['permalink_url'] ) ? $p['permalink_url'] : '',
				't'          => isset( $p['created_time'] ) ? gmdate( 'c', strtotime( $p['created_time'] ) ) : null,
				'vrsta'      => isset( $types[ $mt ] ) ? $types[ $mt ] : 'objava',
				'opis'       => self::cut( isset( $p['message'] ) ? $p['message'] : '', 220 ),
				'trajanje'   => null,
				'pregledi'   => self::num( $ins, 'post_media_view' ),
				'doseg'      => self::num( $ins, 'post_total_media_view_unique' ),
				'lajkovi'    => isset( $p['reactions']['summary']['total_count'] ) ? (int) $p['reactions']['summary']['total_count'] : null,
				'komentari'  => isset( $p['comments']['summary']['total_count'] ) ? (int) $p['comments']['summary']['total_count'] : null,
				'dijeljenja' => isset( $p['shares']['count'] ) ? (int) $p['shares']['count'] : null,
				'spremanja'  => null,
				'gledano_s'  => null,
			);
		}
		self::save(
			'fb',
			array(
				'racun'  => array(
					'ime'        => isset( $page['name'] ) ? $page['name'] : $ctx['page_name'],
					'pratitelji' => null !== self::num( $page, 'followers_count' ) ? self::num( $page, 'followers_count' ) : self::num( $page, 'fan_count' ),
					'objava'     => null,
				),
				'objave' => $posts,
			)
		);
		OLR_Settings::set_status( 'mreze_fb', true, count( $posts ) . ' objava' . ( $noins ? ' (za ' . $noins . ' Meta nije dala preglede)' : '' ) . ' · ' . ( isset( $page['name'] ) ? $page['name'] : '' ) );
		return count( $posts );
	}

	/* ---------- YouTube ---------- */

	private static function yt( $path ) {
		$r = self::http( 'GET', self::YT . $path, array( 'X-goog-api-key' => (string) OLR_Settings::secret( 'yt_key' ) ) );
		if ( is_wp_error( $r ) ) {
			$m = $r->get_error_message();
			if ( false !== stripos( $m, 'quota' ) ) {
				$m = 'dnevna kvota YouTube API-ja je potrošena; nastavlja se sutra';
			} elseif ( false !== stripos( $m, 'API key not valid' ) ) {
				$m = 'API ključ nije valjan';
			} elseif ( false !== stripos( $m, 'has not been used' ) || false !== stripos( $m, 'disabled' ) ) {
				$m = 'YouTube Data API v3 nije uključen u Google Cloud projektu tog ključa (APIs & Services → Library → YouTube Data API v3 → Enable)';
			}
			return new WP_Error( 'olr_yt_api', 'YouTube: ' . $m );
		}
		return $r;
	}

	/** Trajanje iz ISO 8601 (PT1M5S) u sekunde. */
	public static function iso_seconds( $iso ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', (string) $iso, $m ) ) {
			return null;
		}
		return ( isset( $m[1] ) ? (int) $m[1] * 86400 : 0 ) + ( isset( $m[2] ) ? (int) $m[2] * 3600 : 0 ) + ( isset( $m[3] ) ? (int) $m[3] * 60 : 0 ) + ( isset( $m[4] ) ? (int) $m[4] : 0 );
	}

	/** Podaci o videima po ID-jevima (do 50 po pozivu). */
	private static function yt_videos( $ids ) {
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 50 ) as $chunk ) {
			$r = self::yt( 'videos?part=snippet,statistics,contentDetails&maxResults=50&id=' . rawurlencode( implode( ',', $chunk ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( isset( $r['items'] ) ? $r['items'] : array() as $v ) {
				$sec   = self::iso_seconds( isset( $v['contentDetails']['duration'] ) ? $v['contentDetails']['duration'] : '' );
				$st    = isset( $v['statistics'] ) ? $v['statistics'] : array();
				$out[] = array(
					'm'          => 'yt',
					'id'         => (string) $v['id'],
					'url'        => 'https://www.youtube.com/watch?v=' . $v['id'],
					't'          => isset( $v['snippet']['publishedAt'] ) ? gmdate( 'c', strtotime( $v['snippet']['publishedAt'] ) ) : null,
					// Shorts se u API-ju ne razlikuju od ostalih videa; do 60 s računamo kao short.
					'vrsta'      => null !== $sec && $sec <= 61 ? 'short' : 'video',
					'opis'       => self::cut( isset( $v['snippet']['title'] ) ? $v['snippet']['title'] : '', 220 ),
					'trajanje'   => $sec,
					'pregledi'   => self::num( $st, 'viewCount' ),
					'doseg'      => null,
					'lajkovi'    => self::num( $st, 'likeCount' ),
					'komentari'  => self::num( $st, 'commentCount' ),
					'dijeljenja' => null,
					'spremanja'  => null,
					'gledano_s'  => null,
					'kanal'      => isset( $v['snippet']['channelTitle'] ) ? $v['snippet']['channelTitle'] : '',
					'kanal_id'   => isset( $v['snippet']['channelId'] ) ? $v['snippet']['channelId'] : '',
				);
			}
		}
		return $out;
	}

	public static function collect_yt() {
		$ch = trim( (string) OLR_Settings::get( 'yt_channel' ) );
		if ( ! OLR_Settings::secret( 'yt_key' ) || '' === $ch ) {
			return self::fail( 'yt', new WP_Error( 'olr_yt_nema', 'YouTube API ključ ili kanal nisu postavljeni (OL Radar → Postavke).' ) );
		}
		// Kanal može biti ID (UC…), oznaka (@ime) ili adresa kanala.
		if ( preg_match( '#(UC[0-9A-Za-z_-]{20,})#', $ch, $m ) ) {
			$q = 'id=' . $m[1];
		} else {
			$h = preg_match( '#@([A-Za-z0-9._-]+)#', $ch, $m ) ? $m[1] : ltrim( $ch, '@' );
			$q = 'forHandle=' . rawurlencode( '@' . $h );
		}
		$c = self::yt( 'channels?part=snippet,statistics,contentDetails&' . $q );
		if ( is_wp_error( $c ) ) {
			return self::fail( 'yt', $c );
		}
		if ( empty( $c['items'][0] ) ) {
			return self::fail( 'yt', new WP_Error( 'olr_yt_kanal', 'YouTube kanal „' . $ch . '” nije pronađen. Upiši ID kanala (počinje s UC) ili oznaku (@ime).' ) );
		}
		$it  = $c['items'][0];
		$upl = isset( $it['contentDetails']['relatedPlaylists']['uploads'] ) ? $it['contentDetails']['relatedPlaylists']['uploads'] : '';
		$ids = array();
		if ( '' !== $upl ) {
			$pl = self::yt( 'playlistItems?part=contentDetails&maxResults=50&playlistId=' . rawurlencode( $upl ) );
			if ( is_wp_error( $pl ) ) {
				return self::fail( 'yt', $pl );
			}
			foreach ( isset( $pl['items'] ) ? $pl['items'] : array() as $x ) {
				if ( ! empty( $x['contentDetails']['videoId'] ) ) {
					$ids[] = $x['contentDetails']['videoId'];
				}
			}
		}
		$vids = $ids ? self::yt_videos( $ids ) : array();
		if ( is_wp_error( $vids ) ) {
			return self::fail( 'yt', $vids );
		}
		$st = isset( $it['statistics'] ) ? $it['statistics'] : array();
		self::save(
			'yt',
			array(
				'racun'  => array(
					'ime'        => isset( $it['snippet']['title'] ) ? $it['snippet']['title'] : $ch,
					'pratitelji' => self::num( $st, 'subscriberCount' ),
					'objava'     => self::num( $st, 'videoCount' ),
					'pregleda'   => self::num( $st, 'viewCount' ),
					'id'         => (string) $it['id'],
				),
				'objave' => $vids,
			)
		);
		OLR_Settings::set_status( 'mreze_yt', true, count( $vids ) . ' videa · ' . ( isset( $it['snippet']['title'] ) ? $it['snippet']['title'] : '' ) );
		return count( $vids );
	}

	/* ---------- TikTok (Login Kit + Display API) ---------- */

	public static function tt_redirect_uri() {
		return rest_url( 'ol-radar/v1/oauth/tiktok' );
	}

	public static function tt_tokens() {
		$raw = get_option( self::TT_OPT );
		if ( ! $raw ) {
			return null;
		}
		$j = json_decode( (string) OLR_Settings::decrypt( $raw ), true );
		return is_array( $j ) && ! empty( $j['refresh'] ) ? $j : null;
	}

	private static function tt_store( $r ) {
		$old = self::tt_tokens();
		$t   = array(
			'access'      => (string) $r['access_token'],
			'refresh'     => ! empty( $r['refresh_token'] ) ? (string) $r['refresh_token'] : ( $old ? $old['refresh'] : '' ),
			'exp'         => time() + ( isset( $r['expires_in'] ) ? (int) $r['expires_in'] : 86400 ),
			'refresh_exp' => ! empty( $r['refresh_expires_in'] ) ? time() + (int) $r['refresh_expires_in'] : ( $old ? $old['refresh_exp'] : 0 ),
			'scope'       => isset( $r['scope'] ) ? (string) $r['scope'] : ( $old ? $old['scope'] : '' ),
		);
		update_option( self::TT_OPT, OLR_Settings::encrypt( wp_json_encode( $t ) ), false );
		return $t;
	}

	public static function tt_disconnect() {
		delete_option( self::TT_OPT );
		OLR_Store::delete( self::COLL, 'tt' );
	}

	/** Adresa TikTokove stranice za odobrenje; state veže povratak uz administratora koji je kliknuo „Poveži”. */
	public static function tt_authorize_url() {
		$key = trim( (string) OLR_Settings::get( 'tt_client_key' ) );
		if ( '' === $key || ! OLR_Settings::secret( 'tt_secret' ) ) {
			return new WP_Error( 'olr_tt_nema', 'Upiši i spremi TikTok Client key i Client secret pa klikni „Poveži TikTok”.' );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'olr_tt_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
		return self::TT_AUTH . '?' . http_build_query(
			array(
				'client_key'    => $key,
				'scope'         => self::TT_SCOPES,
				'response_type' => 'code',
				'redirect_uri'  => self::tt_redirect_uri(),
				'state'         => $state,
			)
		);
	}

	private static function tt_token_request( $params ) {
		$r = self::http(
			'POST',
			self::TT_API . 'oauth/token/',
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			array_merge(
				array(
					'client_key'    => trim( (string) OLR_Settings::get( 'tt_client_key' ) ),
					'client_secret' => (string) OLR_Settings::secret( 'tt_secret' ),
				),
				$params
			)
		);
		if ( ! is_wp_error( $r ) && empty( $r['access_token'] ) ) {
			// Greška u tijelu odgovora (OAuth oblik: error, error_description).
			$r = new WP_Error( 'olr_tt_api', self::cut( isset( $r['error_description'] ) ? $r['error_description'] : 'TikTok nije izdao token', 300 ) );
		}
		return $r;
	}

	/** Povratak s TikToka: zamjena koda za tokene. Vraća true ili WP_Error. */
	public static function tt_callback( $code, $state ) {
		$key = 'olr_tt_state_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $state );
		$uid = get_transient( $key );
		delete_transient( $key );
		if ( ! $uid || ! user_can( (int) $uid, 'manage_options' ) ) {
			return new WP_Error( 'olr_tt_state', 'Povezivanje je isteklo ili nije pokrenuto iz Postavki. Klikni „Poveži TikTok” ponovno.' );
		}
		if ( '' === (string) $code ) {
			return new WP_Error( 'olr_tt_kod', 'TikTok nije vratio kod za povezivanje (odobrenje je možda odbijeno).' );
		}
		$r = self::tt_token_request(
			array(
				'code'         => (string) $code,
				'grant_type'   => 'authorization_code',
				'redirect_uri' => self::tt_redirect_uri(),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'olr_tt_api', 'TikTok: ' . $r->get_error_message() );
		}
		self::tt_store( $r );
		return true;
	}

	/** Valjan pristupni token; TikTokov vrijedi 24 sata, pa se obnavlja kad mu ostane manje od 2 sata. */
	private static function tt_access() {
		$t = self::tt_tokens();
		if ( ! $t ) {
			return new WP_Error( 'olr_tt_nema', 'TikTok nije povezan (OL Radar → Postavke → Poveži TikTok).' );
		}
		if ( $t['exp'] - time() > 2 * HOUR_IN_SECONDS ) {
			return $t['access'];
		}
		$r = self::tt_token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh'],
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'olr_tt_api', 'TikTok: obnova pristupa nije uspjela (' . $r->get_error_message() . '). Ako se ponavlja, u Postavkama klikni „Poveži TikTok” ponovno.' );
		}
		$t = self::tt_store( $r );
		return $t['access'];
	}

	public static function collect_tt() {
		$tok = self::tt_access();
		if ( is_wp_error( $tok ) ) {
			return self::fail( 'tt', $tok );
		}
		$h    = array( 'Authorization' => 'Bearer ' . $tok );
		$user = self::http( 'GET', self::TT_API . 'user/info/?fields=open_id,display_name,follower_count,likes_count,video_count', $h );
		if ( is_wp_error( $user ) ) {
			// Bez dopuštenja user.info.stats TikTok odbija brojke računa; ime se i dalje može pročitati.
			$user = self::http( 'GET', self::TT_API . 'user/info/?fields=open_id,display_name', $h );
		}
		$u     = ! is_wp_error( $user ) && isset( $user['data']['user'] ) ? $user['data']['user'] : array();
		$posts = array();
		$cur   = null;
		for ( $page = 0; $page < 3; $page++ ) {
			$body = array( 'max_count' => 20 );
			if ( null !== $cur ) {
				$body['cursor'] = $cur;
			}
			$r = self::http(
				'POST',
				self::TT_API . 'video/list/?fields=id,title,video_description,duration,share_url,create_time,like_count,comment_count,share_count,view_count',
				array_merge( $h, array( 'Content-Type' => 'application/json' ) ),
				wp_json_encode( $body )
			);
			if ( is_wp_error( $r ) ) {
				if ( ! $posts ) {
					return self::fail( 'tt', new WP_Error( 'olr_tt_api', 'TikTok: ' . $r->get_error_message() ) );
				}
				break;
			}
			foreach ( isset( $r['data']['videos'] ) ? $r['data']['videos'] : array() as $v ) {
				$txt     = ! empty( $v['video_description'] ) ? $v['video_description'] : ( isset( $v['title'] ) ? $v['title'] : '' );
				$posts[] = array(
					'm'          => 'tt',
					'id'         => (string) $v['id'],
					'url'        => isset( $v['share_url'] ) ? $v['share_url'] : '',
					't'          => ! empty( $v['create_time'] ) ? gmdate( 'c', (int) $v['create_time'] ) : null,
					'vrsta'      => 'video',
					'opis'       => self::cut( $txt, 220 ),
					'trajanje'   => self::num( $v, 'duration' ),
					'pregledi'   => self::num( $v, 'view_count' ),
					'doseg'      => null,
					'lajkovi'    => self::num( $v, 'like_count' ),
					'komentari'  => self::num( $v, 'comment_count' ),
					'dijeljenja' => self::num( $v, 'share_count' ),
					'spremanja'  => null,
					'gledano_s'  => null,
				);
			}
			if ( empty( $r['data']['has_more'] ) || ! isset( $r['data']['cursor'] ) ) {
				break;
			}
			$cur = $r['data']['cursor'];
		}
		self::save(
			'tt',
			array(
				'racun'  => array(
					'ime'        => isset( $u['display_name'] ) ? $u['display_name'] : 'TikTok',
					'pratitelji' => self::num( $u, 'follower_count' ),
					'objava'     => self::num( $u, 'video_count' ),
					'lajkova'    => self::num( $u, 'likes_count' ),
				),
				'objave' => $posts,
			)
		);
		OLR_Settings::set_status( 'mreze_tt', true, count( $posts ) . ' videa · ' . ( isset( $u['display_name'] ) ? $u['display_name'] : '' ) );
		return count( $posts );
	}

	/* ---------- uzori: što je gledano kod drugih ---------- */

	/**
	 * YouTube: najgledaniji videi zadnjih 120 dana po zadanim temama, uz omjer pregleda i pretplatnika kanala.
	 * Instagram: zadnje objave javnih profesionalnih profila koje vlasnik navede (Business Discovery).
	 * TikTok i Facebook ne daju službeni pristup tuđem sadržaju.
	 */
	public static function collect_models() {
		$out   = array(
			'yt'       => array(),
			'ig'       => array(),
			'napomene' => array(),
		);
		$own   = OLR_Store::data( self::COLL, 'yt' );
		$own_c = $own && ! empty( $own['racun']['id'] ) ? $own['racun']['id'] : '';
		if ( OLR_Settings::secret( 'yt_key' ) ) {
			$ids   = array();
			$topic = array();
			$after = gmdate( 'Y-m-d\TH:i:s\Z', time() - 120 * DAY_IN_SECONDS );
			foreach ( self::list_setting( 'yt_teme', 8 ) as $q ) {
				// search.list troši 100 jedinica kvote po pozivu (dnevno ih je 10.000).
				$r = self::yt( 'search?part=snippet&type=video&order=viewCount&maxResults=10&publishedAfter=' . rawurlencode( $after ) . '&q=' . rawurlencode( $q ) );
				if ( is_wp_error( $r ) ) {
					$out['napomene'][] = $r->get_error_message();
					break;
				}
				foreach ( isset( $r['items'] ) ? $r['items'] : array() as $x ) {
					if ( ! empty( $x['id']['videoId'] ) ) {
						$ids[]                           = $x['id']['videoId'];
						$topic[ $x['id']['videoId'] ] = $q;
					}
				}
			}
			$vids = $ids ? self::yt_videos( $ids ) : array();
			if ( is_wp_error( $vids ) ) {
				$out['napomene'][] = $vids->get_error_message();
				$vids              = array();
			}
			$subs = array();
			$chs  = array_values( array_unique( array_filter( wp_list_pluck( $vids, 'kanal_id' ) ) ) );
			foreach ( array_chunk( $chs, 50 ) as $chunk ) {
				$c = self::yt( 'channels?part=statistics&maxResults=50&id=' . rawurlencode( implode( ',', $chunk ) ) );
				foreach ( ! is_wp_error( $c ) && isset( $c['items'] ) ? $c['items'] : array() as $x ) {
					$subs[ $x['id'] ] = self::num( isset( $x['statistics'] ) ? $x['statistics'] : array(), 'subscriberCount' );
				}
			}
			foreach ( $vids as $v ) {
				if ( '' !== $own_c && $v['kanal_id'] === $own_c ) {
					continue;
				}
				$s             = isset( $subs[ $v['kanal_id'] ] ) ? $subs[ $v['kanal_id'] ] : null;
				$v['tema']     = isset( $topic[ $v['id'] ] ) ? $topic[ $v['id'] ] : '';
				$v['pretplatnika'] = $s;
				// Omjer pregleda i pretplatnika: video koji je otišao daleko iznad publike kanala.
				$v['omjer']    = $s && $v['pregledi'] ? round( $v['pregledi'] / max( 100, $s ), 1 ) : null;
				$out['yt'][]   = $v;
			}
			usort(
				$out['yt'],
				static function ( $a, $b ) {
					return (int) $b['pregledi'] <=> (int) $a['pregledi'];
				}
			);
			$out['yt'] = array_slice( $out['yt'], 0, 30 );
		}
		$names = self::list_setting( 'ig_uzori', 10 );
		if ( $names && OLR_Settings::secret( 'meta_token' ) ) {
			$ctx = self::meta_context();
			if ( is_wp_error( $ctx ) || '' === $ctx['ig_id'] ) {
				$out['napomene'][] = is_wp_error( $ctx ) ? $ctx->get_error_message() : 'Instagram uzori traže povezan Instagram račun.';
			} else {
				foreach ( $names as $name ) {
					$name = ltrim( preg_replace( '#^https?://(www\.)?instagram\.com/#i', '', $name ), '@' );
					$name = preg_replace( '/[^A-Za-z0-9._]/', '', $name );
					if ( '' === $name ) {
						continue;
					}
					$r = self::graph( $ctx['ig_id'] . '?fields=' . rawurlencode( 'business_discovery.username(' . $name . '){username,followers_count,media_count,media.limit(25){id,caption,like_count,comments_count,view_count,media_type,media_product_type,permalink,timestamp}}' ), $ctx['page_token'] );
					if ( is_wp_error( $r ) || empty( $r['business_discovery'] ) ) {
						$out['napomene'][] = '@' . $name . ': Instagram ne daje podatke (profil nije profesionalni, ne postoji ili je privatan).';
						continue;
					}
					$bd    = $r['business_discovery'];
					$items = array();
					foreach ( isset( $bd['media']['data'] ) ? $bd['media']['data'] : array() as $m ) {
						$items[] = array(
							'profil'    => '@' . $name,
							'url'       => isset( $m['permalink'] ) ? $m['permalink'] : '',
							't'         => isset( $m['timestamp'] ) ? gmdate( 'c', strtotime( $m['timestamp'] ) ) : null,
							'vrsta'     => isset( $m['media_product_type'] ) && 'REELS' === $m['media_product_type'] ? 'reel' : ( isset( $m['media_type'] ) && 'CAROUSEL_ALBUM' === $m['media_type'] ? 'karusel' : 'objava' ),
							'opis'      => self::cut( isset( $m['caption'] ) ? $m['caption'] : '', 200 ),
							'pregledi'  => self::num( $m, 'view_count' ),
							'lajkovi'   => self::num( $m, 'like_count' ),
							'komentari' => self::num( $m, 'comments_count' ),
							'pratitelja' => self::num( $bd, 'followers_count' ),
						);
					}
					// Objava prema medijanu istog profila (lajkovi + komentari; skriveni lajkovi se preskaču).
					$eng = array();
					foreach ( $items as $i ) {
						if ( null !== $i['lajkovi'] ) {
							$eng[] = $i['lajkovi'] + (int) $i['komentari'];
						}
					}
					$med = self::median( $eng );
					foreach ( $items as $i ) {
						$i['omjer']  = $med && null !== $i['lajkovi'] ? round( ( $i['lajkovi'] + (int) $i['komentari'] ) / $med, 1 ) : null;
						$out['ig'][] = $i;
					}
				}
				usort(
					$out['ig'],
					static function ( $a, $b ) {
						return (float) $b['omjer'] <=> (float) $a['omjer'];
					}
				);
				$out['ig'] = array_slice( $out['ig'], 0, 30 );
			}
		}
		self::save( 'uzori', $out );
		OLR_Settings::set_status( 'mreze_uzori', ! $out['napomene'] || $out['yt'] || $out['ig'], count( $out['yt'] ) . ' YouTube videa, ' . count( $out['ig'] ) . ' Instagram objava' . ( $out['napomene'] ? ' · ' . implode( ' ', array_slice( $out['napomene'], 0, 2 ) ) : '' ) );
		return $out;
	}

	/* ---------- analiza: što prolazi, a što ne ---------- */

	public static function median( $vals ) {
		$v = array_values( array_filter( (array) $vals, 'is_numeric' ) );
		$n = count( $v );
		if ( ! $n ) {
			return null;
		}
		sort( $v );
		return $n % 2 ? $v[ (int) floor( $n / 2 ) ] : ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2;
	}

	/** Glavna mjera objave: pregledi, a gdje ih mreža ne daje doseg. */
	private static function metric( $p ) {
		return null !== $p['pregledi'] ? $p['pregledi'] : $p['doseg'];
	}

	/**
	 * Analiza jedne mreže. Objave zadnjih 180 dana uspoređuju se s vlastitim medijanom: „×” je omjer prema
	 * medijanu. Obrasci se navode samo kad skupina ima barem tri objave i odstupa barem 30 %.
	 */
	public static function analyze_net( $net, $doc ) {
		$since = time() - 180 * DAY_IN_SECONDS;
		$posts = array();
		foreach ( isset( $doc['objave'] ) ? $doc['objave'] : array() as $p ) {
			if ( null !== self::metric( $p ) && ! empty( $p['t'] ) && strtotime( $p['t'] ) >= $since ) {
				$posts[] = $p;
			}
		}
		$out = array(
			'mreza'    => $net,
			'objava'   => count( $posts ),
			'mjera'    => $posts && null !== $posts[0]['pregledi'] ? 'pregledi' : 'doseg',
			'medijan'  => null,
			'top'      => array(),
			'slabo'    => array(),
			'obrasci'  => array(),
			'napomena' => '',
		);
		if ( count( $posts ) < 5 ) {
			$out['napomena'] = count( $posts ) ? 'Premalo objava s brojkama u zadnjih 180 dana (' . count( $posts ) . ') za usporedbu; treba ih barem pet.' : 'Nema objava s brojkama u zadnjih 180 dana.';
			return $out;
		}
		$med            = self::median( array_map( array( __CLASS__, 'metric' ), $posts ) );
		$out['medijan'] = $med;
		foreach ( $posts as &$p ) {
			$m             = self::metric( $p );
			$p['omjer']    = $med > 0 ? round( $m / $med, 1 ) : null;
			$int           = (int) $p['lajkovi'] + (int) $p['komentari'] + (int) $p['dijeljenja'] + (int) $p['spremanja'];
			// Angažman: sve reakcije koje mreža daje (lajkovi, komentari, dijeljenja, spremanja) na 100 pregleda.
			$p['angazman'] = $m > 0 ? round( 100 * $int / $m, 1 ) : null;
		}
		unset( $p );
		usort(
			$posts,
			static function ( $a, $b ) {
				return (float) $b['omjer'] <=> (float) $a['omjer'];
			}
		);
		$out['top']   = array_slice(
			array_values(
				array_filter(
					$posts,
					static function ( $p ) {
						return $p['omjer'] >= 1.5;
					}
				)
			),
			0,
			6
		);
		$out['slabo'] = array_slice(
			array_reverse(
				array_values(
					array_filter(
						$posts,
						static function ( $p ) {
							return $p['omjer'] <= 0.5;
						}
					)
				)
			),
			0,
			6
		);
		// Obrasci: skupine objava čiji se medijan jasno razlikuje od medijana mreže.
		$groups = array();
		$add    = static function ( $label, $p ) use ( &$groups ) {
			$groups[ $label ][] = self::metric( $p );
		};
		$days   = array( 'nedjeljom', 'ponedjeljkom', 'utorkom', 'srijedom', 'četvrtkom', 'petkom', 'subotom' );
		foreach ( $posts as $p ) {
			$add( 'vrsta: ' . $p['vrsta'], $p );
			if ( $p['trajanje'] ) {
				$add( 'trajanje: ' . ( $p['trajanje'] <= 15 ? 'do 15 s' : ( $p['trajanje'] <= 30 ? '16–30 s' : ( $p['trajanje'] <= 60 ? '31–60 s' : 'dulje od minute' ) ) ), $p );
			}
			$ts = strtotime( $p['t'] );
			$add( 'objavljeno ' . $days[ (int) wp_date( 'w', $ts ) ], $p );
			$h = (int) wp_date( 'G', $ts );
			$add( 'objavljeno ' . ( $h < 11 ? 'ujutro (do 11 h)' : ( $h < 17 ? 'tijekom dana (11–17 h)' : 'navečer (od 17 h)' ) ), $p );
			if ( '' !== $p['opis'] ) {
				$first = preg_split( '/(?<=[.!?])\s/u', $p['opis'] )[0];
				if ( false !== strpos( $first, '?' ) ) {
					$add( 'opis počinje pitanjem', $p );
				}
			}
		}
		foreach ( $groups as $label => $vals ) {
			if ( count( $vals ) < 3 || count( $vals ) === count( $posts ) ) {
				continue;
			}
			$gm = self::median( $vals );
			$r  = $med > 0 ? $gm / $med : 1;
			if ( $r >= 1.3 || $r <= 0.7 ) {
				$out['obrasci'][] = array(
					'opis'    => $label,
					'objava'  => count( $vals ),
					'medijan' => round( $gm ),
					'omjer'   => round( $r, 1 ),
				);
			}
		}
		usort(
			$out['obrasci'],
			static function ( $a, $b ) {
				return abs( $b['omjer'] - 1 ) <=> abs( $a['omjer'] - 1 );
			}
		);
		$out['obrasci'] = array_slice( $out['obrasci'], 0, 8 );
		return $out;
	}

	public static function analyze() {
		$out = array();
		foreach ( array_keys( self::NETS ) as $net ) {
			$doc = OLR_Store::data( self::COLL, $net );
			if ( $doc ) {
				$out[ $net ] = self::analyze_net( $net, $doc );
			}
		}
		self::save( 'analiza', array( 'mreze' => $out ) );
		return $out;
	}

	/* ---------- skupljanje ---------- */

	public static function last_run() {
		$r = get_option( self::RUN_OPT );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Preuzmi podatke svih povezanih mreža i izračunaj analizu.
	 *
	 * @param bool $models Preuzmi i uzore (YouTube pretraga troši kvotu, pa ide rjeđe).
	 */
	public static function collect_all( $models = false ) {
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'olr_mreze_u_tijeku', 'Preuzimanje je već u tijeku. Pokušaj za minutu.' );
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$con = self::connected();
		$res = array();
		foreach ( array( 'ig' => 'collect_ig', 'fb' => 'collect_fb', 'yt' => 'collect_yt', 'tt' => 'collect_tt' ) as $net => $fn ) {
			if ( $con[ $net ] ) {
				$r           = self::$fn();
				$res[ $net ] = is_wp_error( $r ) ? $r->get_error_message() : $r;
			}
		}
		$run       = self::last_run();
		$run['at'] = time();
		if ( $models ) {
			self::collect_models();
			$run['uzori_at'] = time();
		}
		self::analyze();
		update_option( self::RUN_OPT, $run, false );
		delete_transient( self::LOCK );
		return $res;
	}

	/** Satni prolaz: podaci svakih 12 sati, uzori i preporuke jednom tjedno. */
	public static function tick() {
		if ( ! OLR_Settings::get( 'mreze_auto' ) || ! array_filter( self::connected() ) ) {
			return;
		}
		$run = self::last_run();
		if ( empty( $run['at'] ) || time() - (int) $run['at'] > 12 * HOUR_IN_SECONDS ) {
			self::collect_all( empty( $run['uzori_at'] ) || time() - (int) $run['uzori_at'] > 7 * DAY_IN_SECONDS );
			return;
		}
		// Preporuke jednom tjedno; nakon neuspjelog pokušaja novi tek sutradan.
		$ok  = isset( $run['preporuke_at'] ) ? (int) $run['preporuke_at'] : 0;
		$try = isset( $run['preporuke_pokusaj'] ) ? (int) $run['preporuke_pokusaj'] : 0;
		if ( OLR_AI::available() && time() - $ok > 7 * DAY_IN_SECONDS && time() - $try > 20 * HOUR_IN_SECONDS ) {
			self::recommend( 'auto' );
		}
	}

	/* ---------- Claude: što raditi ---------- */

	private static function slim( $p ) {
		$keep = array( 'vrsta', 'opis', 'trajanje', 'pregledi', 'doseg', 'lajkovi', 'komentari', 'dijeljenja', 'spremanja', 'gledano_s', 'omjer', 'angazman', 'kanal', 'pretplatnika', 'tema', 'profil', 'pratitelja' );
		$out  = array( 'datum' => ! empty( $p['t'] ) ? substr( $p['t'], 0, 10 ) : null );
		foreach ( $keep as $k ) {
			if ( isset( $p[ $k ] ) && null !== $p[ $k ] && '' !== $p[ $k ] ) {
				$out[ $k ] = $p[ $k ];
			}
		}
		return $out;
	}

	/** Ulaz za Claudea: samo brojke i kratki opisi objava; bez tokena i bez osobnih podataka pratitelja. */
	public static function ai_input() {
		$an    = OLR_Store::data( self::COLL, 'analiza', array() );
		$uz    = OLR_Store::data( self::COLL, 'uzori', array() );
		$mreze = array();
		foreach ( isset( $an['mreze'] ) ? $an['mreze'] : array() as $net => $a ) {
			$doc           = OLR_Store::data( self::COLL, $net, array() );
			$mreze[ $net ] = array(
				'mreza'      => self::NETS[ $net ],
				'racun'      => isset( $doc['racun'] ) ? $doc['racun'] : null,
				'objava_u_analizi' => $a['objava'],
				'mjera'      => $a['mjera'],
				'medijan'    => $a['medijan'],
				'najbolje'   => array_map( array( __CLASS__, 'slim' ), $a['top'] ),
				'najslabije' => array_map( array( __CLASS__, 'slim' ), $a['slabo'] ),
				'obrasci'    => $a['obrasci'],
				'napomena'   => $a['napomena'],
			);
		}
		return array(
			'nase_mreze'    => $mreze,
			'uzori_youtube' => array_map( array( __CLASS__, 'slim' ), array_slice( isset( $uz['yt'] ) ? $uz['yt'] : array(), 0, 15 ) ),
			'uzori_instagram' => array_map( array( __CLASS__, 'slim' ), array_slice( isset( $uz['ig'] ) ? $uz['ig'] : array(), 0, 15 ) ),
			'objasnjenje'   => 'omjer kod naših objava = pregledi (ili doseg) prema medijanu naše mreže; angazman = reakcije na 100 pregleda; gledano_s = prosječno vrijeme gledanja reela u sekundama; omjer kod YouTube uzora = pregledi prema broju pretplatnika kanala; omjer kod Instagram uzora = lajkovi i komentari prema medijanu tog profila.',
		);
	}

	private static function system_prompt() {
		return "[zadatak: društvene mreže]\n"
			. "Ti si urednik sadržaja za Optiku Ljiljana (obiteljska optika u Sesvetama kod Zagreba, od 1993.; optometrist, provjera vida, dioptrijske i progresivne naočale, kontrola kratkovidnosti kod djece). Publika je lokalna: Grad Zagreb i Zagrebačka županija.\n"
			. "U ulazu su brojke naših objava po mrežama (što je prošlo iznad, a što ispod našeg medijana), obrasci i najgledaniji tuđi sadržaj na iste teme. Reci što raditi da nas ljudi gledaju.\n\n"
			. "Pravila:\n"
			. "- Piši hrvatski, konkretno i mirno, bez marketinških fraza, uskličnika i emojija. Ton je premium i deklarativan.\n"
			. "- Svaku tvrdnju osloni na brojku iz ulaza i navedi je. Ako mreža ima napomenu da je objava premalo, reci to i ne izvlači zaključke o njoj.\n"
			. "- Obrazac s malo objava nazovi naznakom, ne pravilom. Ne tvrdi uzrok tamo gdje brojke pokazuju samo podudarnost.\n"
			. "- Ne izmišljaj cijene, popuste, recenzije, brojke ni medicinske tvrdnje. Medicinski oprezno: „provjera vida”, ne „dijagnoza”.\n"
			. "- Tuđi sadržaj je uzor za format i temu, ne za prepisivanje. Ne predlaži kopiranje tuđih videa ni korištenje tuđih snimki.\n"
			. "- preporuke: najviše 5; „zasto” u najviše dvije rečenice; najviše 3 koraka po preporuci, svaki do 25 riječi, takva da ih vlasnik može napraviti sam (snimanje mobitelom).\n"
			. "- ideje: najviše 6 gotovih ideja za objavu; „kuka” je prva rečenica ili prve 3 sekunde videa; „zasto” u jednoj rečenici.\n"
			. '- Odgovori isključivo pozivom alata ' . self::TOOL . ', točno jednom.';
	}

	private static function tool() {
		$s = array( 'type' => 'string' );
		return array(
			'name'         => self::TOOL,
			'description'  => 'Sprema preporuke za društvene mreže.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'sazetak'   => array(
						'type'        => 'string',
						'description' => 'Dvije do četiri rečenice: što brojke govore.',
					),
					'preporuke' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'mreza'  => array(
									'type' => 'string',
									'enum' => array( 'ig', 'fb', 'yt', 'tt', 'sve' ),
								),
								'naslov' => $s,
								'zasto'  => $s,
								'koraci' => array(
									'type'  => 'array',
									'items' => $s,
								),
							),
							'required'   => array( 'mreza', 'naslov', 'zasto', 'koraci' ),
						),
					),
					'ideje'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'mreza'  => array(
									'type' => 'string',
									'enum' => array( 'ig', 'fb', 'yt', 'tt', 'sve' ),
								),
								'format' => $s,
								'naslov' => $s,
								'kuka'   => $s,
								'zasto'  => $s,
							),
							'required'   => array( 'mreza', 'format', 'naslov', 'kuka' ),
						),
					),
				),
				'required'   => array( 'sazetak', 'preporuke' ),
			),
		);
	}

	/**
	 * Preporuke i ideje od Claudea iz spremljene analize.
	 *
	 * @param string $how auto ili rucno (ručno je ograničeno brojem pokretanja dnevno).
	 * @return array|WP_Error
	 */
	public static function recommend( $how = 'rucno' ) {
		if ( ! OLR_AI::available() ) {
			return new WP_Error( 'olr_ai_nema', 'Anthropic API ključ nije postavljen (OL Radar → Postavke).' );
		}
		$run   = self::last_run();
		$today = gmdate( 'Y-m-d' );
		if ( 'rucno' === $how ) {
			$n = isset( $run['rucno'][ $today ] ) ? (int) $run['rucno'][ $today ] : 0;
			if ( $n >= self::MANUAL_MAX ) {
				return new WP_Error( 'olr_mreze_limit', 'Danas su preporuke već tražene ' . self::MANUAL_MAX . ' puta. Sljedeće idu automatski za tjedan dana.' );
			}
			$run['rucno'] = array( $today => $n + 1 );
		}
		$run['preporuke_pokusaj'] = time();
		update_option( self::RUN_OPT, $run, false );
		$in   = self::ai_input();
		$have = false;
		foreach ( $in['nase_mreze'] as $m ) {
			$have = $have || $m['objava_u_analizi'] > 0;
		}
		if ( ! $have && ! $in['uzori_youtube'] && ! $in['uzori_instagram'] ) {
			return new WP_Error( 'olr_mreze_prazno', 'Još nema podataka s društvenih mreža. Poveži barem jednu mrežu u Postavkama i klikni „Osvježi podatke”.' );
		}
		$a   = null;
		$res = null;
		foreach ( array( '', "\n\nPrethodni odgovor je bio predug i prekinut. Skrati: najviše 4 preporuke s po 2 koraka i najviše 4 ideje." ) as $extra ) {
			$res = OLR_AI::call(
				array(
					'system'      => self::system_prompt() . $extra,
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => wp_json_encode( $in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						),
					),
					'tools'       => array( self::tool() ),
					'tool_choice' => array( 'name' => self::TOOL ),
					'max_tokens'  => 4000,
				)
			);
			if ( is_wp_error( $res ) ) {
				OLR_Settings::set_status( 'mreze_preporuke', false, $res->get_error_message() );
				return $res;
			}
			foreach ( $res['content'] as $b ) {
				if ( isset( $b['type'], $b['name'] ) && 'tool_use' === $b['type'] && self::TOOL === $b['name'] && isset( $b['input'] ) && is_array( $b['input'] ) ) {
					$a = $b['input'];
				}
			}
			if ( $a && ! empty( $a['preporuke'] ) && is_array( $a['preporuke'] ) ) {
				break;
			}
			$a = null;
			if ( 'max_tokens' !== $res['stop_reason'] ) {
				break; // nije prekid zbog duljine: ponavljanje ne bi pomoglo
			}
		}
		if ( ! $a ) {
			$e = new WP_Error( 'olr_mreze_oblik', 'max_tokens' === $res['stop_reason'] ? 'Claudeov odgovor je dvaput prekinut zbog duljine. Pokušaj ponovno.' : 'Claude nije vratio preporuke u očekivanom obliku. Pokušaj ponovno.' );
			OLR_Settings::set_status( 'mreze_preporuke', false, $e->get_error_message() );
			return $e;
		}
		$nets = array( 'ig', 'fb', 'yt', 'tt', 'sve' );
		$doc  = array(
			'sazetak'   => self::cut( isset( $a['sazetak'] ) ? $a['sazetak'] : '', 900 ),
			'preporuke' => array(),
			'ideje'     => array(),
			'model'     => $res['model'],
			'nacin'     => $how,
		);
		foreach ( array_slice( $a['preporuke'], 0, 5 ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['naslov'] ) ) {
				continue;
			}
			$steps = array();
			foreach ( isset( $p['koraci'] ) && is_array( $p['koraci'] ) ? array_slice( $p['koraci'], 0, 3 ) : array() as $k ) {
				if ( is_string( $k ) && '' !== trim( $k ) ) {
					$steps[] = self::cut( $k, 300 );
				}
			}
			$doc['preporuke'][] = array(
				'mreza'  => isset( $p['mreza'] ) && in_array( $p['mreza'], $nets, true ) ? $p['mreza'] : 'sve',
				'naslov' => self::cut( $p['naslov'], 140 ),
				'zasto'  => self::cut( isset( $p['zasto'] ) ? $p['zasto'] : '', 500 ),
				'koraci' => $steps,
			);
		}
		foreach ( isset( $a['ideje'] ) && is_array( $a['ideje'] ) ? array_slice( $a['ideje'], 0, 6 ) : array() as $i ) {
			if ( ! is_array( $i ) || empty( $i['naslov'] ) ) {
				continue;
			}
			$doc['ideje'][] = array(
				'mreza'  => isset( $i['mreza'] ) && in_array( $i['mreza'], $nets, true ) ? $i['mreza'] : 'sve',
				'format' => self::cut( isset( $i['format'] ) ? $i['format'] : '', 60 ),
				'naslov' => self::cut( $i['naslov'], 140 ),
				'kuka'   => self::cut( isset( $i['kuka'] ) ? $i['kuka'] : '', 240 ),
				'zasto'  => self::cut( isset( $i['zasto'] ) ? $i['zasto'] : '', 300 ),
			);
		}
		self::save( 'preporuke', $doc );
		$run                 = self::last_run();
		$run['preporuke_at'] = time();
		update_option( self::RUN_OPT, $run, false );
		OLR_Settings::set_status( 'mreze_preporuke', true, count( $doc['preporuke'] ) . ' preporuka, ' . count( $doc['ideje'] ) . ' ideja' );
		return $doc;
	}

	/* ---------- sažetak za ploču i MCP ---------- */

	public static function summary( $posts = 40 ) {
		$con = self::connected();
		$st  = OLR_Settings::status();
		$an  = OLR_Store::data( self::COLL, 'analiza', array() );
		$out = array(
			'mreze'     => array(),
			'uzori'     => OLR_Store::data( self::COLL, 'uzori' ),
			'preporuke' => OLR_Store::data( self::COLL, 'preporuke' ),
			'zadnje'    => array_diff_key( self::last_run(), array( 'rucno' => 1 ) ),
			'auto'      => (bool) OLR_Settings::get( 'mreze_auto' ),
			'claude'    => OLR_AI::available(),
		);
		foreach ( self::NETS as $net => $name ) {
			$doc = OLR_Store::data( self::COLL, $net );
			$out['mreze'][ $net ] = array(
				'naziv'    => $name,
				'povezano' => $con[ $net ],
				'stanje'   => isset( $st[ 'mreze_' . $net ] ) ? $st[ 'mreze_' . $net ] : null,
				'at'       => $doc ? $doc['at'] : null,
				'racun'    => $doc ? $doc['racun'] : null,
				'objave'   => $doc && $posts > 0 ? array_slice( $doc['objave'], 0, $posts ) : array(),
				'analiza'  => isset( $an['mreze'][ $net ] ) ? $an['mreze'][ $net ] : null,
			);
		}
		return $out;
	}
}
