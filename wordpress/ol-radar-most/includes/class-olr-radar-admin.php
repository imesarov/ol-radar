<?php
/**
 * OL Radar u wp-adminu: nadzorna ploča u radnom dijelu (WP izbornik ostaje lijevo) i stranica Postavke.
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
		$hook = add_menu_page( 'OL Radar', 'OL Radar', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ), 'dashicons-chart-area', 3 );
		add_submenu_page( self::SLUG, 'OL Radar – nadzorna ploča', 'Nadzorna ploča', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
		add_submenu_page( self::SLUG, 'OL Radar – postavke', 'Postavke', 'manage_options', self::SETTINGS, array( __CLASS__, 'render_settings' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );
	}

	/**
	 * Ploča ide u okvir (iframe) u radnom dijelu wp-admina: WP izbornik ostaje lijevo, a stilovi
	 * ploče i wp-admina se ne miješaju. Sam okvir učitava istu stranicu s ?olr_okvir=1.
	 */
	public static function load_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- samo prikaz, bez promjena
		if ( isset( $_GET['olr_okvir'] ) ) {
			self::render_app();
		}
		add_action(
			'admin_head',
			function () {
				echo '<style>#wpcontent{padding-left:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}'
					. '#wpbody-content>.notice,#wpbody-content>.update-nag,#wpbody-content>.error,#wpbody-content>.updated{margin:10px 16px}'
					. '.olr-okvir{display:block;width:100%;border:0;height:calc(100vh - 32px);background:#f6f5f1}'
					. '@media (max-width:782px){.olr-okvir{height:calc(100vh - 46px)}}</style>';
			}
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$src = add_query_arg( 'olr_okvir', '1', admin_url( 'admin.php?page=' . self::SLUG ) );
		?>
		<iframe id="olr-okvir" class="olr-okvir" title="OL Radar – nadzorna ploča" src="<?php echo esc_url( $src ); ?>"></iframe>
		<script>
		( function () {
			var f = document.getElementById( 'olr-okvir' );
			// visina do dna prozora (iznad mogu biti obavijesti drugih dodataka); modul iz #adrese preuzima sama ploča
			function fit() { f.style.height = Math.max( 400, window.innerHeight - f.getBoundingClientRect().top - window.scrollY ) + 'px'; }
			window.addEventListener( 'resize', fit );
			fit();
		} )();
		</script>
		<?php
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
		$forget = sanitize_key( (string) self::post( 'olr_forget' ) );
		if ( '' !== $forget ) {
			self::forget( $forget );
		}
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
		$vals['prijedlozi_auto'] = '1' === self::post( 'prijedlozi_auto' );
		$pmax                    = (int) self::post( 'prijedlozi_max' );
		if ( $pmax > 0 ) {
			$vals['prijedlozi_max'] = min( 10, $pmax );
		}
		$brand = sanitize_text_field( (string) self::post( 'brand' ) );
		if ( '' !== $brand ) {
			$vals['brand'] = $brand;
		}
		OLR_Settings::update( $vals );
		$msgs[] = 'Postavke spremljene.';
		$then   = self::run_then( sanitize_key( (string) self::post( 'olr_then' ) ) );
		if ( $then ) {
			self::back( implode( ' ', $msgs ) . ' ' . $then['msg'], $then['ok'] );
		}
		self::back( implode( ' ', $msgs ) );
	}

	public static function handle_forget() {
		self::check( 'forget' );
		self::forget( sanitize_key( (string) self::post( 'kljuc' ) ) );
	}

	private static function forget( $k ) {
		if ( in_array( $k, array( 'gsc_sa', 'dfs_login', 'dfs_password', 'anthropic_key' ), true ) ) {
			OLR_Settings::set_secret( $k, null );
			if ( 'gsc_sa' === $k ) {
				delete_transient( OLR_GSC::TOKEN_TR );
			}
			self::back( 'Ključ obrisan iz baze. (Ako je definiran u wp-config.php, i dalje vrijedi.)' );
		}
		self::back( 'Nepoznat ključ.', false );
	}

	/**
	 * Radnja nakon spremanja (provjera, preuzimanje, mjerenje).
	 *
	 * @return array|null [ 'ok' => bool, 'msg' => string ] ili null ako radnja nije zadana.
	 */
	public static function run_then( $then ) {
		switch ( $then ) {
			case 'test_gsc':
				$r = OLR_GSC::test();
				break;
			case 'collect_gsc':
				$r = OLR_GSC::collect();
				if ( ! is_wp_error( $r ) ) {
					$r = 'Google podaci preuzeti: do ' . $r['do'] . ', ' . $r['redaka']['qp'] . ' redaka upit+stranica.';
				}
				break;
			case 'test_dfs':
				$r = OLR_DFS::test();
				break;
			case 'run_dfs':
				$r = OLR_DFS::start_run( 'rucno' );
				if ( ! is_wp_error( $r ) ) {
					$r = sprintf( 'Mjerenje poslano: %d riječi, trošak %.4f $. Rezultati stižu za nekoliko minuta (gumb „Pokupi rezultate” ili sami u sljedećem satu).', $r['zadaci'], $r['trosak'] );
				}
				break;
			case 'poll_dfs':
				$r = OLR_DFS::poll();
				if ( ! is_wp_error( $r ) ) {
					$r = ! empty( $r['u_tijeku'] ) ? sprintf( 'Stiglo %d od %d rezultata. Ostali stižu uskoro.', $r['gotovo'], $r['ukupno'] ) : ( ! empty( $r['gotovo'] ) ? 'Mjerenje završeno, pozicije su u nadzornoj ploči.' : 'Nema mjerenja u tijeku.' );
				}
				break;
			case 'test_ai':
				$r = OLR_AI::test();
				break;
			case 'run_prijedlozi':
				$r = OLR_Prijedlozi::run( 'rucno' );
				if ( ! is_wp_error( $r ) ) {
					$r = sprintf( 'Analiza gotova: %d nalaza, %d novih prijedloga.%s Otvori nadzornu ploču → Prijedlozi.', $r['kandidata'], $r['novih'], $r['novih'] && ! $r['ai_ok'] ? ' Tekst Claudea nije napisan: ' . $r['ai_poruka'] : '' );
				}
				break;
			default:
				return null;
		}
		return array(
			'ok'  => ! is_wp_error( $r ),
			'msg' => is_wp_error( $r ) ? $r->get_error_message() : (string) $r,
		);
	}

	private static function run_and_back( $then ) {
		self::check( $then );
		$r = self::run_then( $then );
		self::back( $r['msg'], $r['ok'] );
	}

	public static function handle_test_gsc() {
		self::run_and_back( 'test_gsc' );
	}

	public static function handle_collect_gsc() {
		self::run_and_back( 'collect_gsc' );
	}

	public static function handle_test_dfs() {
		self::run_and_back( 'test_dfs' );
	}

	public static function handle_run_dfs() {
		self::run_and_back( 'run_dfs' );
	}

	public static function handle_poll_dfs() {
		self::run_and_back( 'poll_dfs' );
	}

	public static function handle_test_ai() {
		self::run_and_back( 'test_ai' );
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

	/** Gumb „obriši” je dio glavnog obrasca (ugniježđeni <form> nije dopušten u HTML-u). */
	private static function forget_button( $key ) {
		if ( 'baza (šifrirano)' !== OLR_Settings::secret_source( $key ) ) {
			return;
		}
		echo ' <button type="submit" class="button-link button-link-delete" name="olr_forget" value="' . esc_attr( $key ) . '" data-olr-brisi="1">obriši</button>';
	}

	private static function secret_row( $key, $label, $help, $type = 'password' ) {
		$desc = OLR_Settings::describe_secret( $key );
		$src  = OLR_Settings::secret_source( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="4" class="large-text code" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( $desc ? 'Postavljeno. Zalijepi novi JSON samo ako ga mijenjaš.' : 'Zalijepi cijeli sadržaj JSON datoteke' ) . '"></textarea>';
		} else {
			// new-password: preglednik inače zna sam upisati spremljenu lozinku za wp-admin u polje ključa.
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="' . ( 'password' === $type ? 'new-password' : 'off' ) . '" data-lpignore="true" data-1p-ignore value="" placeholder="' . esc_attr( $desc ? 'postavljeno; upiši samo za promjenu' : '' ) . '">';
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
			<?php
			$warn = OLR_Settings::upgrade_warning();
			if ( $warn ) :
				?>
				<div class="notice notice-error"><p><strong>Nakon nadogradnje (<?php echo esc_html( $warn['s'] . ' → ' . $warn['na'] ); ?>) nedostaje ključ:</strong>
					<?php echo esc_html( implode( ', ', array_map( array( 'OLR_Settings', 'label' ), (array) $warn['nedostaju'] ) ) ); ?>.
					Upiši ga ponovno i javi Claudeu ovu poruku, jer nadogradnja ne smije dirati ključeve.</p></div>
			<?php endif; ?>
			<p>Ključevi se spremaju šifrirano i nikad se ne prikazuju u pregledniku. Nadogradnja dodatka ih ne dira, a ostaju i ako se dodatak obriše pa ponovno instalira; pojedini ključ briše se samo gumbom „obriši”. Sigurnije je definirati ih u <code>wp-config.php</code> (konstante <code>OLR_GSC_SA_FILE</code>, <code>OLR_DFS_LOGIN</code>, <code>OLR_DFS_PASSWORD</code>, <code>OLR_ANTHROPIC_KEY</code>); tada imaju prednost.</p>

			<h2>Stanje izvora</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<?php
				$labels = array(
					'gsc'        => 'Google Search Console',
					'rank'       => 'Pozicije (DataForSEO)',
					'prijedlozi' => 'Dnevni prijedlozi izmjena',
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
				<?php // Zadani gumb za Enter u polju: obično spremanje (inače bi Enter „kliknuo” prvi gumb u obrascu, npr. „obriši”). ?>
				<button type="submit" class="olr-zadano" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">Spremi postavke</button>

				<h2>Google Search Console</h2>
				<table class="form-table" role="presentation">
					<?php self::secret_row( 'gsc_sa', 'JSON ključ servisnog računa', 'Google Cloud → IAM i administracija → Servisni računi → Ključevi → Dodaj ključ (JSON). Adresu servisnog računa dodaj u Search Consoleu kao korisnika (Postavke → Korisnici i dopuštenja, ograničeni pristup).', 'textarea' ); ?>
					<tr><th scope="row"><label for="gsc_site">Svojstvo</label></th><td>
						<input type="text" id="gsc_site" name="gsc_site" class="regular-text code" value="<?php echo esc_attr( $s['gsc_site'] ); ?>">
						<p class="description">Točno kao u Search Consoleu: <code>https://www.optikaljiljana.hr/</code> (URL svojstvo) ili <code>sc-domain:optikaljiljana.hr</code> (domena).</p>
					</td></tr>
				</table>
				<p class="olr-akcije"><button type="submit" class="button" name="olr_then" value="test_gsc">Spremi i provjeri Google vezu</button> <button type="submit" class="button" name="olr_then" value="collect_gsc">Spremi i preuzmi Google podatke sada</button></p>

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
				<p class="olr-akcije"><button type="submit" class="button" name="olr_then" value="test_dfs">Spremi i provjeri DataForSEO prijavu (besplatno)</button>
					<?php if ( $pending ) : ?>
						<strong style="margin-left:8px">Mjerenje u tijeku (<?php echo esc_html( $pending ); ?>).</strong> <button type="submit" class="button" name="olr_then" value="poll_dfs">Spremi i pokupi rezultate</button>
					<?php else : ?>
						<button type="submit" class="button" name="olr_then" value="run_dfs" data-olr-trosak="<?php echo esc_attr( sprintf( '%.4f', OLR_DFS::estimate() ) ); ?>"><?php echo esc_html( sprintf( 'Spremi i izmjeri pozicije sada (oko %.4f $)', OLR_DFS::estimate() ) ); ?></button>
					<?php endif; ?>
				</p>

				<h2>Claude u nadzornoj ploči (Anthropic API)</h2>
				<table class="form-table" role="presentation">
					<?php self::secret_row( 'anthropic_key', 'API ključ', 'Claude Console → API Keys. Ultra plan uključuje mjesečne API kredite kad je Console organizacija povezana s planom (claude.ai → Postavke → Billing → API credits).' ); ?>
					<tr><th scope="row"><label for="ai_model">Model</label></th><td>
						<select id="ai_model" name="ai_model"><?php foreach ( OLR_AI::models() as $id => $l ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $s['ai_model'], $id, false ) . '>' . esc_html( $l ) . '</option>'; } ?></select>
						<label style="margin-left:12px">Dnevni limit poziva <input type="number" name="ai_daily_limit" class="small-text" min="1" max="2000" value="<?php echo (int) $s['ai_daily_limit']; ?>"></label>
					</td></tr>
				</table>
				<p class="olr-akcije"><button type="submit" class="button" name="olr_then" value="test_ai">Spremi i provjeri Claude ključ</button></p>

				<h2>Dnevni prijedlozi izmjena</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Automatska analiza</th><td>
						<label><input type="checkbox" name="prijedlozi_auto" value="1" <?php checked( ! empty( $s['prijedlozi_auto'] ) ); ?>> Svaki dan, kad stignu novi Google podaci, analiziraj i pripremi prijedloge izmjena</label>
						<p class="description">Analizu radi server iz spremljenih podataka. Tekst prijedloga (novi naslov, opis, odlomak) piše Claude jednim pozivom dnevno preko Anthropic API ključa; brojke u prijedlogu uvijek su iz Search Consolea. Bez ključa prijedlozi nastaju bez napisanog teksta. Web se ne mijenja.</p>
					</td></tr>
					<tr><th scope="row"><label for="prijedlozi_max">Najviše novih dnevno</label></th><td>
						<input type="number" id="prijedlozi_max" name="prijedlozi_max" class="small-text" min="1" max="10" value="<?php echo (int) $s['prijedlozi_max']; ?>">
						<p class="description">Nalaz koji već ima otvoren prijedlog ne ponavlja se. Odbijeni se ne vraća 45 dana, provedeni 28 dana.</p>
					</td></tr>
					<tr><th scope="row"><label for="brand">Riječi brenda</label></th><td>
						<input type="text" id="brand" name="brand" class="regular-text" value="<?php echo esc_attr( $s['brand'] ); ?>">
						<p class="description">Odvojene zarezom. Upiti s tim riječima (npr. „optika ljiljana”) ne ulaze u analizu, jer za njih ionako rangiramo.</p>
					</td></tr>
				</table>
				<p class="olr-akcije"><button type="submit" class="button" name="olr_then" value="run_prijedlozi">Spremi i izradi prijedloge sada</button></p>
				<?php submit_button( 'Spremi postavke' ); ?>
			</form>
			<script>
			( function () {
				// Upozorenje na nespremljene promjene i potvrda troška mjerenja.
				var form = document.querySelector( 'form input[name="action"][value="olr_save"]' ).form, dirty = false;
				form.addEventListener( 'input', function () { dirty = true; } );
				form.addEventListener( 'change', function () { dirty = true; } );
				form.addEventListener( 'submit', function ( e ) {
					var b = e.submitter;
					if ( b && b.getAttribute( 'data-olr-brisi' ) && ! window.confirm( 'Obrisati ovaj ključ iz baze?' ) ) {
						e.preventDefault();
						return;
					}
					if ( b && b.getAttribute( 'data-olr-trosak' ) && ! window.confirm( 'Pokrenuti mjerenje pozicija? Trošak je oko ' + b.getAttribute( 'data-olr-trosak' ) + ' $ s DataForSEO računa.' ) ) {
						e.preventDefault();
						return;
					}
					dirty = false;
				} );
				// Na razini dokumenta jer obrazac za uvoz dolazi tek nakon ove skripte.
				document.addEventListener( 'submit', function ( e ) {
					if ( e.target === form || ! dirty ) { return; }
					if ( window.confirm( 'Imaš nespremljene promjene u postavkama. Nastaviti bez spremanja?' ) ) {
						dirty = false;
					} else {
						e.preventDefault();
					}
				}, true );
				window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
			} )();
			</script>

			<h2>Uvoz iz Radara na claude.ai</h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'olr_import' ); ?>
				<input type="hidden" name="action" value="olr_import">
				<input type="file" name="datoteka" accept="application/json,.json">
				<?php submit_button( 'Uvezi', 'secondary', 'olr_uvezi', false ); ?>
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
