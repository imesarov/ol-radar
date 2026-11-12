<?php
/**
 * OL Radar u wp-adminu: nadzorna ploča preko cijelog zaslona i stranica Postavke.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Radar_Admin {

	const SLUG     = 'ol-radar';
	const SETTINGS = 'ol-radar-postavke';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'save', 'test_gsc', 'collect_gsc', 'test_dfs', 'run_dfs', 'poll_dfs', 'test_ai', 'forget', 'import' ) as $a ) {
			add_action( 'admin_post_olr_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu() {
		$hook = add_menu_page( 'OL Radar', 'OL Radar', 'manage_options', self::SLUG, '__return_null', 'dashicons-chart-area', 3 );
		add_submenu_page( self::SLUG, 'OL Radar – nadzorna ploča', 'Nadzorna ploča', 'manage_options', self::SLUG, '__return_null' );
		add_submenu_page( self::SLUG, 'OL Radar – postavke', 'Postavke', 'manage_options', self::SETTINGS, array( __CLASS__, 'render_settings' ) );
		// Ploča se ispisuje prije wp-admin okvira, preko cijelog zaslona.
		add_action( 'load-' . $hook, array( __CLASS__, 'render_app' ) );
	}

	/** Nadzorna ploča: isti kod kao Radar na claude.ai, s podacima s ovog servera. */
	public static function render_app() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemaš pravo na OL Radar.', 403 );
		}
		$file = OLR_DIR . 'app/radar.html';
		if ( ! is_readable( $file ) ) {
			wp_die( 'Datoteka nadzorne ploče nedostaje (app/radar.html). Ponovno učitaj dodatak.', 500 );
		}
		$boot = array(
			'rest'     => esc_url_raw( rest_url( OLR_REST::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'ai'       => OLR_AI::available(),
			'postavke' => admin_url( 'admin.php?page=' . self::SETTINGS ),
			'wpadmin'  => admin_url(),
			'web'      => home_url( '/' ),
			'verzija'  => OLR_VERSION,
			'korisnik' => wp_get_current_user()->display_name,
		);
		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = wp_json_encode( $boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		$html = str_replace( '/*OLR_BOOT*/', 'window.OLR_HOSTED = ' . $json . ';', $html );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vlastita datoteka dodatka; ubačeni JSON je kodiran.
		exit;
	}

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemaš pravo na ovu radnju.', 403 );
		}
		check_admin_referer( 'olr_' . $action );
	}

	private static function back( $msg, $ok = true ) {
		set_transient(
			'olr_settings_notice_' . get_current_user_id(),
			array(
				'ok'  => $ok,
				'msg' => $msg,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS ) );
		exit;
	}

	private static function post( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- provjereno u check()
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	}

	public static function handle_save() {
		self::check( 'save' );
		$msgs = array();
		$sa   = trim( (string) self::post( 'gsc_sa' ) );
		if ( '' !== $sa ) {
			$v = OLR_GSC::validate_json( $sa );
			if ( true !== $v ) {
				self::back( 'Google ključ nije spremljen: ' . $v, false );
			}
			OLR_Settings::set_secret( 'gsc_sa', $sa );
			delete_transient( OLR_GSC::TOKEN_TR );
			$msgs[] = 'Google servisni račun spremljen.';
		}
		$site = trim( (string) self::post( 'gsc_site' ) );
		$vals = array();
		if ( '' !== $site ) {
			if ( 0 !== strpos( $site, 'sc-domain:' ) && ! preg_match( '#^https?://[^\s]+/$#', $site ) ) {
				self::back( 'Svojstvo mora biti oblika https://www.optikaljiljana.hr/ ili sc-domain:optikaljiljana.hr', false );
			}
			$vals['gsc_site'] = $site;
		}
		foreach ( array( 'dfs_login', 'dfs_password', 'anthropic_key' ) as $k ) {
			$v = trim( (string) self::post( $k ) );
			if ( '' !== $v ) {
				OLR_Settings::set_secret( $k, $v );
				$msgs[] = array( 'dfs_login' => 'DataForSEO login spremljen.', 'dfs_password' => 'DataForSEO lozinka spremljena.', 'anthropic_key' => 'Anthropic ključ spremljen.' )[ $k ];
			}
		}
		$kws = self::post( 'rank_keywords' );
		if ( null !== $kws ) {
			$list = array();
			foreach ( preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $kws ) ) as $w ) {
				$w = trim( $w );
				if ( '' !== $w && ! in_array( OLR_DFS::lower( $w ), array_map( array( 'OLR_DFS', 'lower' ), $list ), true ) ) {
					$list[] = $w;
				}
			}
			$vals['rank_keywords'] = array_slice( $list, 0, OLR_DFS::MAX_KEYWORDS );
		}
		$loc = sanitize_text_field( (string) self::post( 'rank_location' ) );
		if ( '' !== $loc ) {
			$vals['rank_location'] = $loc;
		}
		$lang = sanitize_key( (string) self::post( 'rank_language' ) );
		if ( '' !== $lang ) {
			$vals['rank_language'] = $lang;
		}
		$vals['rank_device'] = 'desktop' === self::post( 'rank_device' ) ? 'desktop' : 'mobile';
		$depth               = (int) self::post( 'rank_depth' );
		$vals['rank_depth']  = in_array( $depth, array( 10, 20, 30, 40, 50, 100 ), true ) ? $depth : 40;
		$vals['rank_weekly'] = '1' === self::post( 'rank_weekly' );
		$model               = (string) self::post( 'ai_model' );
		if ( isset( OLR_AI::models()[ $model ] ) ) {
			$vals['ai_model'] = $model;
		}
		$lim = (int) self::post( 'ai_daily_limit' );
		if ( $lim > 0 ) {
			$vals['ai_daily_limit'] = min( 2000, $lim );
		}
		OLR_Settings::update( $vals );
		$msgs[] = 'Postavke spremljene.';
		self::back( implode( ' ', $msgs ) );
	}

	public static function handle_forget() {
		self::check( 'forget' );
		$k = sanitize_key( (string) self::post( 'kljuc' ) );
		if ( in_array( $k, array( 'gsc_sa', 'dfs_login', 'dfs_password', 'anthropic_key' ), true ) ) {
			OLR_Settings::set_secret( $k, null );
			if ( 'gsc_sa' === $k ) {
				delete_transient( OLR_GSC::TOKEN_TR );
			}
			self::back( 'Ključ obrisan iz baze. (Ako je definiran u wp-config.php, i dalje vrijedi.)' );
		}
		self::back( 'Nepoznat ključ.', false );
	}

	public static function handle_test_gsc() {
		self::check( 'test_gsc' );
		$r = OLR_GSC::test();
		self::back( is_wp_error( $r ) ? $r->get_error_message() : $r, ! is_wp_error( $r ) );
	}

	public static function handle_collect_gsc() {
		self::check( 'collect_gsc' );
		$r = OLR_GSC::collect();
		self::back( is_wp_error( $r ) ? $r->get_error_message() : 'Google podaci preuzeti: do ' . $r['do'] . ', ' . $r['redaka']['qp'] . ' redaka upit+stranica.', ! is_wp_error( $r ) );
	}

	public static function handle_test_dfs() {
		self::check( 'test_dfs' );
		$r = OLR_DFS::test();
		self::back( is_wp_error( $r ) ? $r->get_error_message() : $r, ! is_wp_error( $r ) );
	}

	public static function handle_run_dfs() {
		self::check( 'run_dfs' );
		$r = OLR_DFS::start_run( 'rucno' );
		self::back( is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'Mjerenje poslano: %d riječi, trošak %.4f $. Rezultati stižu za nekoliko minuta (gumb „Pokupi rezultate” ili sami u sljedećem satu).', $r['zadaci'], $r['trosak'] ), ! is_wp_error( $r ) );
	}

	public static function handle_poll_dfs() {
		self::check( 'poll_dfs' );
		$r = OLR_DFS::poll();
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), false );
		}
		if ( ! empty( $r['u_tijeku'] ) ) {
			self::back( sprintf( 'Stiglo %d od %d rezultata. Ostali stižu uskoro.', $r['gotovo'], $r['ukupno'] ) );
		}
		self::back( ! empty( $r['gotovo'] ) ? 'Mjerenje završeno, pozicije su u nadzornoj ploči.' : 'Nema mjerenja u tijeku.' );
	}

	public static function handle_test_ai() {
		self::check( 'test_ai' );
		$r = OLR_AI::test();
		self::back( is_wp_error( $r ) ? $r->get_error_message() : $r, ! is_wp_error( $r ) );
	}

	/** Uvoz podataka iz Radara na claude.ai (JSON: { "zbirke": { "ime": [ { "id": ..., "data": {...} } ] } }). */
	public static function handle_import() {
		self::check( 'import' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['datoteka']['tmp_name'] ) || ! is_uploaded_file( $_FILES['datoteka']['tmp_name'] ) ) {
			self::back( 'Odaberi JSON datoteku za uvoz.', false );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $_FILES['datoteka']['tmp_name'] );
		$r = self::import_json( (string) $raw );
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), false );
		}
		self::back( sprintf( 'Uvezeno %d dokumenata.%s', $r['uvezeno'], $r['preskoceno'] ? ' Preskočeno zbirki: ' . $r['preskoceno'] . '.' : '' ) );
	}

	/** Uvoz izvoza iz Radara na claude.ai u bazu dodatka; samo dopuštene zbirke. */
	public static function import_json( $raw ) {
		$j = json_decode( $raw, true );
		if ( ! is_array( $j ) || empty( $j['zbirke'] ) || ! is_array( $j['zbirke'] ) ) {
			return new WP_Error( 'olr_uvoz', 'Datoteka nije izvoz OL Radara (nedostaje „zbirke”).' );
		}
		$allowed = array( 'arch_kw', 'arch_hist', 'arch_serp', 'arch_comp', 'arch_misc', 'issues', 'fixes', 'plan', 'kp', 'snap', 'cfg', 'trends', 'tjedno' );
		$n       = 0;
		$skip    = 0;
		foreach ( $j['zbirke'] as $coll => $docs ) {
			if ( ! in_array( $coll, $allowed, true ) || ! is_array( $docs ) ) {
				$skip++;
				continue;
			}
			foreach ( $docs as $d ) {
				if ( isset( $d['id'], $d['data'] ) && OLR_Store::valid_name( (string) $d['id'] ) && true === OLR_Store::set( $coll, (string) $d['id'], $d['data'] ) ) {
					$n++;
				}
			}
		}
		return array(
			'uvezeno'    => $n,
			'preskoceno' => $skip,
		);
	}

	private static function button( $action, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0">';
		wp_nonce_field( 'olr_' . $action );
		echo '<input type="hidden" name="action" value="olr_' . esc_attr( $action ) . '">';
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	private static function forget_button( $key ) {
		if ( 'baza (šifrirano)' !== OLR_Settings::secret_source( $key ) ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'olr_forget' );
		echo '<input type="hidden" name="action" value="olr_forget"><input type="hidden" name="kljuc" value="' . esc_attr( $key ) . '">';
		echo ' <button type="submit" class="button-link button-link-delete">obriši</button></form>';
	}

	private static function secret_row( $key, $label, $help, $type = 'password' ) {
		$desc = OLR_Settings::describe_secret( $key );
		$src  = OLR_Settings::secret_source( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="4" class="large-text code" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( $desc ? 'Postavljeno. Zalijepi novi JSON samo ako ga mijenjaš.' : 'Zalijepi cijeli sadržaj JSON datoteke' ) . '"></textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="off" value="" placeholder="' . esc_attr( $desc ? 'postavljeno; upiši samo za promjenu' : '' ) . '">';
		}
		echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		if ( $desc ) {
			echo '<p><strong>Sada:</strong> ' . esc_html( $desc ) . ' <span style="color:#646970">(' . esc_html( $src ) . ')</span>';
			self::forget_button( $key );
			echo '</p>';
		}
		echo '</td></tr>';
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = OLR_Settings::all();
		$st     = OLR_Settings::status();
		$notice = get_transient( 'olr_settings_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'olr_settings_notice_' . get_current_user_id() );
		}
		$cost    = get_option( 'olr_dfs_cost', array() );
		$usage   = OLR_AI::usage();
		$pending = get_option( OLR_DFS::PENDING );
		$m       = gmdate( 'Y-m' );
		?>
		<div class="wrap">
			<h1>OL Radar – postavke</h1>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">Otvori nadzornu ploču</a></p>
			<?php if ( $notice ) : ?>
				<div class="notice <?php echo $notice['ok'] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<p>Ključevi se spremaju šifrirano i nikad se ne prikazuju u pregledniku. Sigurnije je definirati ih u <code>wp-config.php</code> (konstante <code>OLR_GSC_SA_FILE</code>, <code>OLR_DFS_LOGIN</code>, <code>OLR_DFS_PASSWORD</code>, <code>OLR_ANTHROPIC_KEY</code>); tada imaju prednost.</p>

			<h2>Stanje izvora</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<?php
				$labels = array(
					'gsc'  => 'Google Search Console',
					'rank' => 'Pozicije (DataForSEO)',
				);
				foreach ( $labels as $k => $l ) {
					$x = isset( $st[ $k ] ) ? $st[ $k ] : null;
					echo '<tr><td style="width:240px"><strong>' . esc_html( $l ) . '</strong></td><td>';
					if ( $x ) {
						echo ( $x['ok'] ? '<span style="color:#008a20">●</span> ' : '<span style="color:#d63638">●</span> ' ) . esc_html( $x['msg'] ) . ' <span style="color:#646970">· ' . esc_html( human_time_diff( $x['at'] ) ) . ' prije</span>';
					} else {
						echo '<span style="color:#646970">još nije pokrenuto</span>';
					}
					echo '</td></tr>';
				}
				?>
				<tr><td><strong>Claude (Anthropic API)</strong></td><td>
					<?php
					echo OLR_AI::available() ? '<span style="color:#008a20">●</span> ključ postavljen' : '<span style="color:#646970">ključ nije postavljen</span>';
					if ( isset( $usage['mjeseci'][ $m ] ) ) {
						$u = $usage['mjeseci'][ $m ];
						echo esc_html( sprintf( ' · ovaj mjesec %d poziva, %s ulaznih i %s izlaznih tokena', $u['pozivi'], number_format_i18n( $u['ulaz'] ), number_format_i18n( $u['izlaz'] ) ) );
					}
					?>
				</td></tr>
				<tr><td><strong>DataForSEO trošak</strong></td><td>
					<?php echo esc_html( sprintf( 'ovaj mjesec %.4f $ · procjena jednog mjerenja %.4f $', isset( $cost[ $m ] ) ? $cost[ $m ] : 0, OLR_DFS::estimate() ) ); ?>
				</td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'olr_save' ); ?>
				<input type="hidden" name="action" value="olr_save">

				<h2>Google Search Console</h2>
				<table class="form-table" role="presentation">
					<?php self::secret_row( 'gsc_sa', 'JSON ključ servisnog računa', 'Google Cloud → IAM i administracija → Servisni računi → Ključevi → Dodaj ključ (JSON). Adresu servisnog računa dodaj u Search Consoleu kao korisnika (Postavke → Korisnici i dopuštenja, ograničeni pristup).', 'textarea' ); ?>
					<tr><th scope="row"><label for="gsc_site">Svojstvo</label></th><td>
						<input type="text" id="gsc_site" name="gsc_site" class="regular-text code" value="<?php echo esc_attr( $s['gsc_site'] ); ?>">
						<p class="description">Točno kao u Search Consoleu: <code>https://www.optikaljiljana.hr/</code> (URL svojstvo) ili <code>sc-domain:optikaljiljana.hr</code> (domena).</p>
					</td></tr>
				</table>

				<h2>Pozicije (DataForSEO)</h2>
				<table class="form-table" role="presentation">
					<?php self::secret_row( 'dfs_login', 'API login', 'DataForSEO → API Access: API login (obično e-mail).', 'text' ); ?>
					<?php self::secret_row( 'dfs_password', 'API lozinka', 'DataForSEO → API Access: API password (nije lozinka za prijavu u sučelje).' ); ?>
					<tr><th scope="row"><label for="rank_keywords">Ciljane riječi</label></th><td>
						<textarea id="rank_keywords" name="rank_keywords" rows="10" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $s['rank_keywords'] ) ); ?></textarea>
						<p class="description">Jedna po retku, najviše <?php echo (int) OLR_DFS::MAX_KEYWORDS; ?>. Sada: <?php echo (int) count( OLR_DFS::keywords() ); ?>.</p>
					</td></tr>
					<tr><th scope="row">Mjesto i uređaj</th><td>
						<input type="text" name="rank_location" class="regular-text" value="<?php echo esc_attr( $s['rank_location'] ); ?>">
						<input type="text" name="rank_language" class="small-text" value="<?php echo esc_attr( $s['rank_language'] ); ?>">
						<select name="rank_device"><option value="mobile" <?php selected( $s['rank_device'], 'mobile' ); ?>>mobitel</option><option value="desktop" <?php selected( $s['rank_device'], 'desktop' ); ?>>računalo</option></select>
						<select name="rank_depth"><?php foreach ( array( 10, 20, 30, 40, 50, 100 ) as $d ) { echo '<option value="' . (int) $d . '" ' . selected( (int) $s['rank_depth'], $d, false ) . '>do ' . (int) $d . '. mjesta</option>'; } ?></select>
						<p class="description">Lokacija u DataForSEO obliku, npr. <code>Zagreb,City of Zagreb,Croatia</code>. Cijena: 0,0006 $ po 10 rezultata po riječi (standardni red).</p>
					</td></tr>
					<tr><th scope="row">Tjedno mjerenje</th><td>
						<label><input type="checkbox" name="rank_weekly" value="1" <?php checked( ! empty( $s['rank_weekly'] ) ); ?>> Mjeri automatski jednom tjedno (nedjelja navečer)</label>
						<p class="description">Procjena: <?php echo esc_html( sprintf( '%.4f $ po mjerenju, oko %.2f $ mjesečno', OLR_DFS::estimate(), OLR_DFS::estimate() * 4.35 ) ); ?>.</p>
					</td></tr>
				</table>

				<h2>Claude u nadzornoj ploči (Anthropic API)</h2>
				<table class="form-table" role="presentation">
					<?php self::secret_row( 'anthropic_key', 'API ključ', 'Claude Console → API Keys. Ultra plan uključuje mjesečne API kredite kad je Console organizacija povezana s planom (claude.ai → Postavke → Billing → API credits).' ); ?>
					<tr><th scope="row"><label for="ai_model">Model</label></th><td>
						<select id="ai_model" name="ai_model"><?php foreach ( OLR_AI::models() as $id => $l ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $s['ai_model'], $id, false ) . '>' . esc_html( $l ) . '</option>'; } ?></select>
						<label style="margin-left:12px">Dnevni limit poziva <input type="number" name="ai_daily_limit" class="small-text" min="1" max="2000" value="<?php echo (int) $s['ai_daily_limit']; ?>"></label>
					</td></tr>
				</table>
				<?php submit_button( 'Spremi postavke' ); ?>
			</form>

			<h2>Provjere i ručno pokretanje</h2>
			<p>
				<?php
				self::button( 'test_gsc', 'Provjeri Google vezu' );
				self::button( 'collect_gsc', 'Preuzmi Google podatke sada' );
				self::button( 'test_dfs', 'Provjeri DataForSEO prijavu (besplatno)' );
				self::button( 'test_ai', 'Provjeri Claude ključ' );
				?>
			</p>
			<p>
				<?php
				if ( $pending ) {
					echo '<strong>Mjerenje u tijeku (' . esc_html( $pending ) . ').</strong> ';
					self::button( 'poll_dfs', 'Pokupi rezultate' );
				} else {
					self::button( 'run_dfs', sprintf( 'Izmjeri pozicije sada (oko %.4f $)', OLR_DFS::estimate() ), 'button button-secondary' );
				}
				?>
			</p>

			<h2>Uvoz iz Radara na claude.ai</h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'olr_import' ); ?>
				<input type="hidden" name="action" value="olr_import">
				<input type="file" name="datoteka" accept="application/json,.json">
				<?php submit_button( 'Uvezi', 'secondary', 'submit', false ); ?>
				<p class="description">Arhiva Ahrefsa, nalazi, plan sadržaja i povijest. Datoteku priprema Claude.</p>
			</form>

			<h2>Pohrana</h2>
			<table class="widefat striped" style="max-width:600px"><thead><tr><th>Zbirka</th><th>Dokumenata</th><th>Zadnja promjena (UTC)</th></tr></thead><tbody>
			<?php
			foreach ( (array) OLR_Store::count_all() as $r ) {
				echo '<tr><td>' . esc_html( $r['coll'] ) . '</td><td>' . (int) $r['n'] . '</td><td>' . esc_html( $r['zadnje'] ) . '</td></tr>';
			}
			?>
			</tbody></table>
			<p class="description">Pravi cron na hostingu (svakih 15 minuta) pouzdaniji je od WordPressovog: <code>wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
		</div>
		<?php
	}
}
