<?php
/**
 * Crawler iznutra: popis objavljenih URL-ova, dohvat vlastitih stranica (loopback), raščlamba HTML-a i spremanje u tablicu.
 *
 * Dohvaća isključivo URL-ove na vlastitoj domeni.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Crawler {

	const MAX_AGE_DAYS = 7;
	const MAX_TEXT     = 30000;
	const MAX_IMAGES   = 300;
	const MAX_LINKS    = 600;
	const MAX_EXTERNAL = 200;
	const MAX_HEADINGS = 80;

	/** Naziv tablice. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ol_radar_pages';
	}

	/** Kreira ili nadograđuje tablicu. */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  url_hash char(32) NOT NULL,
  url varchar(2048) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT 'page',
  status smallint(5) NOT NULL DEFAULT 0,
  fetched_at datetime DEFAULT NULL,
  ms int(10) unsigned NOT NULL DEFAULT 0,
  data longtext,
  body_text longtext,
  PRIMARY KEY  (url_hash),
  KEY post_id (post_id),
  KEY kind (kind)
) {$charset};";
		dbDelta( $sql );
		update_option( 'olr_db_version', OLR_DB_VERSION, false );
	}

	/* ---------------------------------------------------------------------
	 * URL pomoćne funkcije
	 * ------------------------------------------------------------------- */

	/** Host weba bez "www.". */
	public static function site_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return self::bare_host( (string) $host );
	}

	private static function bare_host( $host ) {
		$host = strtolower( $host );
		return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
	}

	/** Je li URL na našoj domeni (s ili bez www). */
	public static function is_internal( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		return self::bare_host( $host ) === self::site_host();
	}

	/**
	 * Normalizira URL za usporedbu: shema i host kao na webu, bez fragmenta, bez utm_* i bez našeg `olr` parametra.
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$home   = wp_parse_url( home_url() );
		$scheme = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		$host   = strtolower( $parts['host'] );
		if ( self::bare_host( $host ) === self::site_host() && ! empty( $home['host'] ) ) {
			$host = strtolower( $home['host'] );
			$port = isset( $home['port'] ) ? ':' . $home['port'] : '';
		} else {
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : $scheme;
			$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		}
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$path = preg_replace( '#/+#', '/', $path );

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array_keys( $q ) as $k ) {
				if ( 'olr' === $k || 0 === strpos( (string) $k, 'utm_' ) || in_array( $k, array( 'fbclid', 'gclid' ), true ) ) {
					unset( $q[ $k ] );
				}
			}
			if ( $q ) {
				ksort( $q );
				$query = '?' . http_build_query( $q );
			}
		}
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/** Isti URL sa i bez završne kose crte (za usporedbu). */
	public static function variants( $url ) {
		$n = self::normalize( $url );
		if ( '' === $n || false !== strpos( $n, '?' ) ) {
			return array( $n );
		}
		$alt = ( '/' === substr( $n, -1 ) ) ? rtrim( $n, '/' ) : $n . '/';
		return array( $n, $alt );
	}

	public static function hash( $url ) {
		return md5( self::normalize( $url ) );
	}

	/** Pretvara relativni href u apsolutni URL u odnosu na stranicu. */
	public static function absolutize( $href, $base ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}
		if ( preg_match( '#^(mailto|tel|javascript|data|sms|whatsapp|viber):#i', $href ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$b      = wp_parse_url( $base );
		$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'https';
		$auth   = $scheme . '://' . ( isset( $b['host'] ) ? $b['host'] : '' ) . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}
		if ( '/' === $href[0] ) {
			return $auth . $href;
		}
		if ( '?' === $href[0] ) {
			return $auth . ( isset( $b['path'] ) ? $b['path'] : '/' ) . $href;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return $auth . $dir . $href;
	}

	/* ---------------------------------------------------------------------
	 * Token za zaobilaženje cachea
	 * ------------------------------------------------------------------- */

	private static function token_prefix( $hour_offset = 0 ) {
		$slot = gmdate( 'YmdH', time() - ( 3600 * $hour_offset ) );
		return substr( hash_hmac( 'sha256', 'olr|' . $slot, wp_salt( 'auth' ) ), 0, 16 );
	}

	public static function make_token() {
		return self::token_prefix() . wp_generate_password( 6, false, false );
	}

	public static function is_valid_token( $token ) {
		$prefix = substr( (string) $token, 0, 16 );
		return hash_equals( self::token_prefix(), $prefix ) || hash_equals( self::token_prefix( 1 ), $prefix );
	}

	/* ---------------------------------------------------------------------
	 * Popis URL-ova (inventar)
	 * ------------------------------------------------------------------- */

	/** Javne vrste sadržaja koje se prate. */
	public static function post_types() {
		$types   = get_post_types( array( 'public' => true ), 'names' );
		$exclude = array( 'attachment', 'elementor_library', 'e-floating-buttons' );
		$types   = array_values( array_diff( $types, $exclude ) );
		return apply_filters( 'olr_post_types', $types );
	}

	/**
	 * Objavljeni URL-ovi: [ normalizirani_url => post_id ].
	 */
	public static function inventory( $force = false ) {
		$cached = get_transient( 'olr_inventory' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$out   = array();
		$front = (int) get_option( 'page_on_front' );
		$out[ self::normalize( home_url( '/' ) ) ] = 'page' === get_option( 'show_on_front' ) ? $front : 0;

		$ids = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => 3000,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		foreach ( $ids as $id ) {
			if ( $front && (int) $id === $front ) {
				continue;
			}
			$link = get_permalink( $id );
			if ( $link ) {
				$out[ self::normalize( $link ) ] = (int) $id;
			}
		}
		set_transient( 'olr_inventory', $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** Usklađuje tablicu s inventarom: dodaje nove, briše neobjavljene. */
	public static function sync_inventory() {
		global $wpdb;
		$table = self::table();
		$inv   = self::inventory( true );

		$existing = $wpdb->get_results( "SELECT url_hash, post_id FROM {$table} WHERE kind = 'page'", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keep     = array();
		foreach ( $inv as $url => $post_id ) {
			$h          = md5( $url );
			$keep[ $h ] = true;
			if ( ! isset( $existing[ $h ] ) ) {
				// Ako je URL ranije provjeren kao meta veze, prebaci ga u stranice.
				$wpdb->delete( $table, array( 'url_hash' => $h ) );
				$wpdb->insert(
					$table,
					array(
						'url_hash' => $h,
						'url'      => $url,
						'post_id'  => (int) $post_id,
						'kind'     => 'page',
						'status'   => 0,
					)
				);
			} elseif ( (int) $existing[ $h ]->post_id !== (int) $post_id ) {
				$wpdb->update( $table, array( 'post_id' => (int) $post_id ), array( 'url_hash' => $h ) );
			}
		}
		foreach ( $existing as $h => $row ) {
			if ( ! isset( $keep[ $h ] ) ) {
				$wpdb->delete( $table, array( 'url_hash' => $h ) );
			}
		}
		return count( $inv );
	}

	/* ---------------------------------------------------------------------
	 * Serija obrade
	 * ------------------------------------------------------------------- */

	/** Granica starosti (GMT) nakon koje se stranica ponovno dohvaća. */
	private static function stale_before() {
		return gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS );
	}

	/** Zastarjele stranice: nikad dohvaćene, promijenjene nakon dohvata ili starije od 7 dana. */
	public static function stale_pages( $limit ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.url_hash, p.url, p.post_id FROM {$table} p
			 LEFT JOIN {$wpdb->posts} ps ON ps.ID = p.post_id
			 WHERE p.kind = 'page' AND ( p.fetched_at IS NULL OR p.fetched_at < %s OR ( ps.ID IS NOT NULL AND p.fetched_at < ps.post_modified_gmt ) )
			 ORDER BY COALESCE( p.fetched_at, '1970-01-01 00:00:00' ) ASC
			 LIMIT %d",
			self::stale_before(),
			(int) $limit
		);
		// phpcs:enable
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Oznaka za ponovnu obradu nakon promjene parsera (podaci ostaju dok se stranica ne obradi). */
	const REQUEUE_AT = '2000-01-01 00:00:00';

	/** Sve obrađene stranice vraća u red za ponovnu obradu (npr. nakon popravka parsera). */
	public static function requeue_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET fetched_at = %s WHERE kind = 'page' AND fetched_at IS NOT NULL", self::REQUEUE_AT ) );
	}

	/** Vrijeme obrade za prikaz; stranica vraćena u red nema stvarno vrijeme. */
	public static function fetched_label( $fetched_at ) {
		if ( null === $fetched_at ) {
			return null;
		}
		return $fetched_at <= self::REQUEUE_AT ? 'čeka ponovnu obradu' : $fetched_at . ' UTC';
	}

	public static function count_stale() {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} p
			 LEFT JOIN {$wpdb->posts} ps ON ps.ID = p.post_id
			 WHERE p.kind = 'page' AND ( p.fetched_at IS NULL OR p.fetched_at < %s OR ( ps.ID IS NOT NULL AND p.fetched_at < ps.post_modified_gmt ) )",
			self::stale_before()
		);
		// phpcs:enable
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Obradi seriju: do $limit stranica i do 20 ciljeva internih linkova, unutar $budget sekundi.
	 */
	public static function run_batch( $limit = 10, $budget = 25 ) {
		$start = microtime( true );
		if ( ! get_transient( 'olr_lock' ) ) {
			set_transient( 'olr_lock', 1, 120 );
		} else {
			return array(
				'obradeno' => 0,
				'poruka'   => 'Druga serija je već u tijeku. Pokušaj za minutu.',
			);
		}

		$done   = 0;
		$errors = array();
		try {
			self::sync_inventory();
			$rows = self::stale_pages( max( 1, min( 25, (int) $limit ) ) );
			foreach ( $rows as $row ) {
				if ( microtime( true ) - $start > $budget ) {
					break;
				}
				$res = self::crawl_url( $row->url, (int) $row->post_id, 'page' );
				++$done;
				if ( ! empty( $res['greska'] ) ) {
					$errors[] = $row->url . ': ' . $res['greska'];
				}
			}
			$targets = 0;
			if ( microtime( true ) - $start < $budget ) {
				$targets = self::check_targets( 20, $budget - ( microtime( true ) - $start ) );
			}
		} finally {
			delete_transient( 'olr_lock' );
		}

		$state                = get_option( 'olr_state', array() );
		$state['zadnja_serija'] = gmdate( 'c' );
		$stale                = self::count_stale();
		if ( 0 === $stale ) {
			$state['zadnji_potpuni'] = gmdate( 'c' );
		}
		if ( $errors ) {
			$state['zadnje_greske'] = array_slice( $errors, 0, 10 );
		}
		update_option( 'olr_state', $state, false );

		return array(
			'obradeno'        => $done,
			'provjereni_ciljevi' => isset( $targets ) ? $targets : 0,
			'preostalo'       => $stale,
			'greske'          => $errors,
			'trajanje_s'      => round( microtime( true ) - $start, 1 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Dohvat i raščlamba
	 * ------------------------------------------------------------------- */

	/**
	 * Dohvaća vlastitu stranicu bez cachea i sprema rezultat.
	 */
	public static function crawl_url( $url, $post_id = 0, $kind = 'page' ) {
		global $wpdb;
		$url = self::normalize( $url );
		if ( '' === $url || ! self::is_internal( $url ) ) {
			return array( 'greska' => 'URL nije na ovoj domeni.' );
		}
		$fetch = add_query_arg( 'olr', self::make_token(), $url );
		$t0    = microtime( true );
		$args  = array(
			'timeout'             => 20,
			'redirection'         => 0,
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'          => 'OL-Radar-Most/' . OLR_VERSION . ' (loopback; ' . home_url() . ')',
			'headers'             => array( 'Cache-Control' => 'no-cache' ),
			'limit_response_size' => 4 * MB_IN_BYTES,
		);
		$resp  = ( 'page' === $kind ) ? wp_remote_get( $fetch, $args ) : wp_remote_head( $fetch, $args );
		if ( 'page' !== $kind && ! is_wp_error( $resp ) && 405 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$resp = wp_remote_get( $fetch, $args );
		}
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$data   = array();
		$status = 0;
		if ( is_wp_error( $resp ) ) {
			$data['greska'] = $resp->get_error_message();
		} else {
			$status = (int) wp_remote_retrieve_response_code( $resp );
			$loc    = wp_remote_retrieve_header( $resp, 'location' );
			if ( $loc ) {
				$data['lokacija'] = self::normalize( self::absolutize( $loc, $url ) );
			}
			$xr = wp_remote_retrieve_header( $resp, 'x-robots-tag' );
			if ( $xr ) {
				$data['x_robots'] = is_array( $xr ) ? implode( ', ', $xr ) : (string) $xr;
			}
			$ct         = (string) wp_remote_retrieve_header( $resp, 'content-type' );
			$data['ct'] = $ct;
			if ( 'page' === $kind && 200 === $status && false !== stripos( $ct, 'html' ) ) {
				$body             = wp_remote_retrieve_body( $resp );
				$data['velicina'] = strlen( $body );
				$data             = array_merge( $data, self::parse_html( $body, $url ) );
			}
		}

		$text = isset( $data['tekst'] ) ? $data['tekst'] : '';
		unset( $data['tekst'] );
		$wpdb->replace(
			self::table(),
			array(
				'url_hash'   => md5( $url ),
				'url'        => $url,
				'post_id'    => (int) $post_id,
				'kind'       => $kind,
				'status'     => $status,
				'fetched_at' => gmdate( 'Y-m-d H:i:s' ),
				'ms'         => $ms,
				'data'       => wp_json_encode( $data ),
				'body_text'  => $text,
			)
		);
		$data['status'] = $status;
		$data['ms']     = $ms;
		return $data;
	}

	/** Kontekst elementa: "n" = navigacija/zaglavlje/podnožje, "s" = sadržaj. */
	private static function context_of( DOMNode $node ) {
		$nav_tags  = array( 'header', 'nav', 'footer', 'aside' );
		$nav_roles = array( 'banner', 'navigation', 'contentinfo', 'complementary' );
		$nav_ids   = array( 'header', 'header_wrapper', 'footer', 'top_bar', 'action_bar', 'side_slide' );
		for ( $n = $node->parentNode; $n && XML_ELEMENT_NODE === $n->nodeType; $n = $n->parentNode ) {
			$tag = strtolower( $n->nodeName );
			if ( in_array( $tag, $nav_tags, true ) ) {
				return 'n';
			}
			if ( $n instanceof DOMElement ) {
				$role = strtolower( $n->getAttribute( 'role' ) );
				if ( $role && in_array( $role, $nav_roles, true ) ) {
					return 'n';
				}
				$id = strtolower( $n->getAttribute( 'id' ) );
				if ( $id && in_array( $id, $nav_ids, true ) ) {
					return 'n';
				}
			}
		}
		return 's';
	}

	private static function clean_text( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s );
		return trim( (string) $s );
	}

	/** Raščlamba HTML-a stranice. */
	public static function parse_html( $html, $page_url ) {
		$out = array();
		if ( ! class_exists( 'DOMDocument' ) ) {
			$out['greska'] = 'PHP DOM ekstenzija nije dostupna.';
			return $out;
		}
		// libxml (HTML4 parser) završava <script> već na prvom "</" + slovo, pa HTML iz JS predložaka
		// postaje lažni sadržaj (npr. linkovi). Zato se sadržaj <script>/<style> prazni prije parsiranja
		// (oznake i atributi ostaju zbog provjere miješanog sadržaja), a JSON-LD se izdvaja ranije.
		$ld      = array();
		$cleaned = preg_replace_callback(
			'#<(script|style)\b([^>]*)>(.*?)</\1\s*>#is',
			static function ( $m ) use ( &$ld ) {
				if ( 'script' === strtolower( $m[1] ) && preg_match( '#\btype\s*=\s*["\']?application/ld\+json#i', $m[2] ) ) {
					$ld[] = $m[3];
				}
				return '<' . $m[1] . $m[2] . '></' . $m[1] . '>';
			},
			$html
		);
		$ld_ok = null !== $cleaned;
		if ( $ld_ok ) {
			$html = $cleaned;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new DOMXPath( $dom );

		$first = static function ( $query ) use ( $xp ) {
			$n = $xp->query( $query );
			return ( $n && $n->length ) ? $n->item( 0 ) : null;
		};
		$lc    = 'translate(%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';

		$t              = $first( '//title' );
		$out['naslov']  = $t ? self::clean_text( $t->textContent ) : null;
		$d              = $first( '//meta[' . sprintf( $lc, '@name' ) . '="description"]' );
		$out['opis']    = $d ? self::clean_text( $d->getAttribute( 'content' ) ) : null;
		$r              = $first( '//meta[' . sprintf( $lc, '@name' ) . '="robots"]' );
		$out['robots']  = $r ? strtolower( self::clean_text( $r->getAttribute( 'content' ) ) ) : null;
		$c              = $first( '//link[' . sprintf( $lc, '@rel' ) . '="canonical"]' );
		$out['canonical'] = $c ? self::normalize( self::absolutize( $c->getAttribute( 'href' ), $page_url ) ) : null;
		$html_el        = $first( '//html' );
		$out['jezik']   = ( $html_el instanceof DOMElement ) ? $html_el->getAttribute( 'lang' ) : '';
		$out['viewport'] = (bool) $first( '//meta[' . sprintf( $lc, '@name' ) . '="viewport"]' );

		$og = array();
		foreach ( array( 'og:title', 'og:description', 'og:image', 'og:type' ) as $p ) {
			$m = $first( '//meta[@property="' . $p . '"]' );
			if ( $m instanceof DOMElement ) {
				$og[ substr( $p, 3 ) ] = self::clean_text( $m->getAttribute( 'content' ) );
			}
		}
		$out['og'] = $og;

		$hl = array();
		foreach ( $xp->query( '//link[@rel="alternate"][@hreflang]' ) as $l ) {
			$hl[] = array(
				'jezik' => $l->getAttribute( 'hreflang' ),
				'url'   => self::normalize( self::absolutize( $l->getAttribute( 'href' ), $page_url ) ),
			);
		}
		$out['hreflang'] = $hl;

		// Naslovi.
		$h1  = array();
		$hdr = array();
		foreach ( $xp->query( '//body//*[self::h1 or self::h2 or self::h3]' ) as $h ) {
			$txt = self::clean_text( $h->textContent );
			$lvl = (int) substr( strtolower( $h->nodeName ), 1 );
			if ( 1 === $lvl ) {
				$h1[] = $txt;
			}
			if ( count( $hdr ) < self::MAX_HEADINGS ) {
				$hdr[] = array(
					'r' => $lvl,
					't' => $txt,
				);
			}
		}
		$out['h1']      = $h1;
		$out['naslovi'] = $hdr;

		// Tekst sadržaja (bez skripti, stilova i navigacije).
		$text_q = '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::svg)'
			. ' and not(ancestor::header) and not(ancestor::nav) and not(ancestor::footer)'
			. ' and not(ancestor::*[@role="banner" or @role="navigation" or @role="contentinfo"])'
			. ' and not(ancestor::*[@id="Header_wrapper" or @id="Footer" or @id="Top_bar"])]';
		$parts  = array();
		foreach ( $xp->query( $text_q ) as $tn ) {
			$s = self::clean_text( $tn->nodeValue );
			if ( '' !== $s ) {
				$parts[] = $s;
			}
		}
		$text          = implode( ' ', $parts );
		$out['rijeci'] = (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text );
		$out['tekst']  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );

		// Slike.
		$imgs        = array();
		$is_https    = 0 === strpos( $page_url, 'https://' );
		$mixed       = 0;
		foreach ( $xp->query( '//body//img[not(ancestor::noscript)]' ) as $img ) {
			$src = '';
			foreach ( array( 'data-lazy-src', 'data-src', 'src' ) as $attr ) {
				$v = trim( $img->getAttribute( $attr ) );
				if ( '' !== $v && 0 !== strpos( $v, 'data:' ) ) {
					$src = $v;
					break;
				}
			}
			if ( '' === $src ) {
				continue;
			}
			$abs = self::absolutize( $src, $page_url );
			if ( $is_https && 0 === stripos( $abs, 'http://' ) ) {
				++$mixed;
			}
			if ( count( $imgs ) < self::MAX_IMAGES ) {
				$imgs[] = array(
					'src'  => $abs,
					'alt'  => $img->hasAttribute( 'alt' ) ? self::clean_text( $img->getAttribute( 'alt' ) ) : null,
					'w'    => $img->getAttribute( 'width' ),
					'h'    => $img->getAttribute( 'height' ),
					'lazy' => 'lazy' === strtolower( $img->getAttribute( 'loading' ) ) || $img->hasAttribute( 'data-lazy-src' ),
				);
			}
		}
		$out['slike'] = $imgs;

		foreach ( $xp->query( '//script[@src] | //link[@rel="stylesheet"][@href] | //iframe[@src]' ) as $res ) {
			$v = $res->hasAttribute( 'src' ) ? $res->getAttribute( 'src' ) : $res->getAttribute( 'href' );
			if ( $is_https && 0 === stripos( trim( $v ), 'http://' ) ) {
				++$mixed;
			}
		}
		$out['mijesani_sadrzaj'] = $mixed;

		// Linkovi.
		$int = array();
		$ext = array();
		foreach ( $xp->query( '//body//a[@href]' ) as $a ) {
			$abs = self::absolutize( $a->getAttribute( 'href' ), $page_url );
			if ( '' === $abs ) {
				continue;
			}
			$anchor = self::clean_text( $a->textContent );
			if ( '' === $anchor ) {
				$im = $xp->query( './/img[@alt]', $a );
				if ( $im && $im->length ) {
					$anchor = '[slika] ' . self::clean_text( $im->item( 0 )->getAttribute( 'alt' ) );
				} elseif ( $a->getAttribute( 'aria-label' ) ) {
					$anchor = '[aria] ' . self::clean_text( $a->getAttribute( 'aria-label' ) );
				}
			}
			$rel = strtolower( $a->getAttribute( 'rel' ) );
			$nf  = false !== strpos( $rel, 'nofollow' );
			if ( self::is_internal( $abs ) ) {
				$n = self::normalize( $abs );
				if ( '' === $n || preg_match( '#/wp-(admin|login|json)|/feed/?$#', $n ) ) {
					continue;
				}
				if ( count( $int ) < self::MAX_LINKS ) {
					$int[] = array(
						'u'  => $n,
						'a'  => function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 120 ) : substr( $anchor, 0, 120 ),
						'k'  => self::context_of( $a ),
						'nf' => $nf,
					);
				}
			} elseif ( count( $ext ) < self::MAX_EXTERNAL ) {
				$ext[] = array(
					'u'  => $abs,
					'a'  => function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 120 ) : substr( $anchor, 0, 120 ),
					'nf' => $nf,
				);
			}
		}
		$out['linkovi']  = $int;
		$out['vanjski']  = $ext;

		// Schema (JSON-LD tipovi).
		$types = array();
		if ( ! $ld_ok ) {
			foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
				$ld[] = $s->textContent;
			}
		}
		foreach ( $ld as $raw ) {
			$json = json_decode( trim( $raw ), true );
			if ( is_array( $json ) ) {
				self::collect_types( $json, $types );
			}
		}
		$out['schema'] = array_values( array_unique( $types ) );

		return $out;
	}

	private static function collect_types( $node, &$types ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $t ) {
				if ( is_string( $t ) ) {
					$types[] = $t;
				}
			}
		}
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) && '@context' !== $k ) {
				self::collect_types( $v, $types );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Provjera ciljeva internih linkova koji nisu u popisu objava
	 * ------------------------------------------------------------------- */

	public static function check_targets( $limit, $budget ) {
		global $wpdb;
		$table = self::table();
		$start = microtime( true );

		$known = array();
		$rows  = $wpdb->get_results( "SELECT url, kind, fetched_at, data FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$need  = array();
		foreach ( $rows as $r ) {
			$known[ $r->url ] = $r;
		}
		foreach ( $rows as $r ) {
			if ( 'page' !== $r->kind || empty( $r->data ) ) {
				continue;
			}
			$d = json_decode( $r->data, true );
			if ( empty( $d['linkovi'] ) ) {
				continue;
			}
			foreach ( $d['linkovi'] as $l ) {
				$found = false;
				foreach ( self::variants( $l['u'] ) as $v ) {
					if ( isset( $known[ $v ] ) && ( 'page' === $known[ $v ]->kind || ( $known[ $v ]->fetched_at && $known[ $v ]->fetched_at > self::stale_before() ) ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found && preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|pdf|zip|mp4)(\?|$)#i', $l['u'] ) ) {
					$found = true; // datoteke se ne provjeravaju
				}
				if ( ! $found ) {
					$need[ $l['u'] ] = true;
				}
			}
		}
		$n = 0;
		foreach ( array_keys( $need ) as $u ) {
			if ( $n >= $limit || microtime( true ) - $start > $budget ) {
				break;
			}
			self::crawl_url( $u, 0, 'target' );
			++$n;
		}
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Čitanje iz tablice
	 * ------------------------------------------------------------------- */

	/** Svi redovi s dekodiranim podacima. */
	public static function all_rows() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT url, post_id, kind, status, fetched_at, ms, data FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $r ) {
			$r->data         = $r->data ? json_decode( $r->data, true ) : array();
			$out[ $r->url ] = $r;
		}
		return $out;
	}

	public static function get_row( $url ) {
		global $wpdb;
		$table = self::table();
		foreach ( self::variants( $url ) as $v ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT url, post_id, kind, status, fetched_at, ms, data, body_text FROM {$table} WHERE url_hash = %s", md5( $v ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $r ) {
				$r->data = $r->data ? json_decode( $r->data, true ) : array();
				if ( null !== $r->body_text && '' !== $r->body_text ) {
					$r->data['tekst'] = $r->body_text;
				}
				unset( $r->body_text );
				return $r;
			}
		}
		return null;
	}

	/** Brojke stanja crawla. */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kind = 'page'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE kind = 'page' AND fetched_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state = get_option( 'olr_state', array() );
		return array(
			'stranica_u_popisu' => $total,
			'dohvaceno'         => $done,
			'zastarjelo'        => $total ? self::count_stale() : 0,
			'zadnja_serija'     => isset( $state['zadnja_serija'] ) ? $state['zadnja_serija'] : null,
			'zadnji_potpuni'    => isset( $state['zadnji_potpuni'] ) ? $state['zadnji_potpuni'] : null,
			'zadnje_greske'     => isset( $state['zadnje_greske'] ) ? $state['zadnje_greske'] : array(),
			'sljedeci_cron'     => wp_next_scheduled( OLR_CRON ) ? gmdate( 'c', wp_next_scheduled( OLR_CRON ) ) : null,
		);
	}
}
