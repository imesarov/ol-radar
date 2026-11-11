<?php
/**
 * Mala admin stranica (Alati → OL Radar): stanje crawla, ručna serija i upute za spajanje.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_olr_run_batch', array( __CLASS__, 'handle_batch' ) );
	}

	public static function menu() {
		add_management_page( 'OL Radar', 'OL Radar', 'manage_options', 'ol-radar-most', array( __CLASS__, 'render' ) );
	}

	public static function handle_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemaš pravo na ovu radnju.', 403 );
		}
		check_admin_referer( 'olr_run_batch' );
		$res = OLR_Crawler::run_batch( 15, 40 );
		set_transient( 'olr_admin_notice_' . get_current_user_id(), $res, 120 );
		wp_safe_redirect( admin_url( 'tools.php?page=ol-radar-most' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$stats  = OLR_Crawler::stats();
		$notice = get_transient( 'olr_admin_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'olr_admin_notice_' . get_current_user_id() );
		}
		$oauth   = rest_url( 'mcp/mcp-oauth-server' );
		$default = rest_url( 'mcp/mcp-adapter-default-server' );
		$routes  = rest_get_server()->get_routes();
		$has_oauth   = isset( $routes['/mcp/mcp-oauth-server'] );
		$has_default = isset( $routes['/mcp/mcp-adapter-default-server'] );
		$readers     = get_users(
			array(
				'role'   => OLR_ROLE,
				'fields' => array( 'user_login' ),
			)
		);
		?>
		<div class="wrap">
			<h1>OL Radar – most za Claudea</h1>
			<p>Dodatak daje Claudeu pristup webu <strong>samo za čitanje</strong>. Ne mijenja stranice, objave, postavke ni dodatke.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p>
					Obrađeno: <?php echo (int) $notice['obradeno']; ?> ·
					preostalo: <?php echo isset( $notice['preostalo'] ) ? (int) $notice['preostalo'] : 0; ?>
					<?php if ( ! empty( $notice['poruka'] ) ) : ?>
						· <?php echo esc_html( $notice['poruka'] ); ?>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<h2>Stanje crawla</h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><td>Stranica u popisu objava</td><td><?php echo (int) $stats['stranica_u_popisu']; ?></td></tr>
					<tr><td>Obrađeno</td><td><?php echo (int) $stats['dohvaceno']; ?></td></tr>
					<tr><td>Čeka obradu (novo, promijenjeno ili starije od 7 dana)</td><td><?php echo (int) $stats['zastarjelo']; ?></td></tr>
					<tr><td>Zadnja serija (UTC)</td><td><?php echo esc_html( $stats['zadnja_serija'] ? $stats['zadnja_serija'] : '–' ); ?></td></tr>
					<tr><td>Zadnji potpuni prolaz (UTC)</td><td><?php echo esc_html( $stats['zadnji_potpuni'] ? $stats['zadnji_potpuni'] : '–' ); ?></td></tr>
					<tr><td>Sljedeći automatski korak (UTC)</td><td><?php echo esc_html( $stats['sljedeci_cron'] ? $stats['sljedeci_cron'] : 'nije zakazan' ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( ! empty( $stats['zadnje_greske'] ) ) : ?>
				<h3>Zadnje greške dohvata</h3>
				<ul>
					<?php foreach ( $stats['zadnje_greske'] as $e ) : ?>
						<li><code><?php echo esc_html( $e ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olr_run_batch">
				<?php wp_nonce_field( 'olr_run_batch' ); ?>
				<p><button type="submit" class="button button-primary">Obradi sljedećih 15 stranica</button></p>
			</form>

			<h2>Spajanje s Claudeom</h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr>
						<td>MCP s OAuth prijavom (za claude.ai)</td>
						<td><?php echo $has_oauth ? '<code>' . esc_html( $oauth ) . '</code>' : 'nije dostupan na ovom webu'; ?></td>
					</tr>
					<tr>
						<td>Zadani MCP server (Claude Code, aplikacijska lozinka)</td>
						<td><?php echo $has_default ? '<code>' . esc_html( $default ) . '</code>' : 'nije dostupan na ovom webu'; ?></td>
					</tr>
					<tr>
						<td>Korisnici s ulogom „OL Radar – samo čitanje”</td>
						<td><?php echo $readers ? esc_html( implode( ', ', wp_list_pluck( $readers, 'user_login' ) ) ) : 'nijedan – napravi jednog (Korisnici → Dodaj novog)'; ?></td>
					</tr>
				</tbody>
			</table>
			<p>Pri prijavi iz Claudea prijavi se kao korisnik s ulogom <strong>OL Radar – samo čitanje</strong>, ne kao administrator. Tako Claude vidi samo ove sposobnosti za čitanje.</p>
		</div>
		<?php
	}
}
