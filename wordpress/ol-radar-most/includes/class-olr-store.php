<?php
/**
 * Pohrana Radara na hostingu: jednostavna baza dokumenata (zbirka + id + JSON),
 * isti model kao baza artefakta na claude.ai, pa nadzorna ploča radi s istim kodom.
 */

defined( 'ABSPATH' ) || exit;

class OLR_Store {

	const DB_VERSION = '1';
	const MAX_BYTES  = 8388608; // 8 MB po dokumentu.

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ol_radar_docs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			coll varchar(64) NOT NULL,
			doc_id varchar(191) NOT NULL,
			data longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (coll, doc_id),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'olr_store_version', self::DB_VERSION, false );
	}

	/** Dopuštena imena zbirki i dokumenata: slova, brojke, - _ . | */
	public static function valid_name( $s, $max = 191 ) {
		return is_string( $s ) && '' !== $s && strlen( $s ) <= $max && (bool) preg_match( '/^[A-Za-z0-9_\-\.|]+$/', $s );
	}

	public static function get( $coll, $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT data, updated_at FROM {$table} WHERE coll = %s AND doc_id = %s", $coll, $id ) );
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( $row->data, true );
		return array(
			'data'       => $data,
			'updated_at' => $row->updated_at,
		);
	}

	/** Vraća samo podatke dokumenta ili $default. */
	public static function data( $coll, $id, $default = null ) {
		$d = self::get( $coll, $id );
		return $d ? $d['data'] : $default;
	}

	public static function set( $coll, $id, $data ) {
		global $wpdb;
		if ( ! self::valid_name( $coll, 64 ) || ! self::valid_name( $id ) ) {
			return new WP_Error( 'olr_ime', 'Neispravno ime zbirke ili dokumenta.' );
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new WP_Error( 'olr_json', 'Podaci se ne mogu spremiti kao JSON.' );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'olr_velicina', 'Dokument je prevelik (više od 8 MB).' );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (coll, doc_id, data, updated_at) VALUES (%s, %s, %s, %s)", $coll, $id, $json, current_time( 'mysql', true ) ) );
		return false === $ok ? new WP_Error( 'olr_baza', 'Spremanje u bazu nije uspjelo.' ) : true;
	}

	public static function delete( $coll, $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete( $table, array( 'coll' => $coll, 'doc_id' => $id ) );
	}

	/**
	 * Popis dokumenata zbirke. Sortiranje po polju dokumenta radi se u PHP-u (zbirke su male).
	 *
	 * @return array[] [ [ 'id' => ..., 'data' => ... ], ... ]
	 */
	public static function list_docs( $coll, $order_by = '', $dir = 'asc', $limit = 1000 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT doc_id, data FROM {$table} WHERE coll = %s ORDER BY doc_id ASC LIMIT 5000", $coll ) );
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'   => $r->doc_id,
				'data' => json_decode( $r->data, true ),
			);
		}
		if ( '' !== $order_by ) {
			$desc = 'desc' === strtolower( $dir );
			usort(
				$items,
				static function ( $a, $b ) use ( $order_by, $desc ) {
					$x = isset( $a['data'][ $order_by ] ) ? $a['data'][ $order_by ] : null;
					$y = isset( $b['data'][ $order_by ] ) ? $b['data'][ $order_by ] : null;
					if ( $x === $y ) {
						return 0;
					}
					$c = ( $x < $y ) ? -1 : 1;
					return $desc ? -$c : $c;
				}
			);
		}
		return array_slice( $items, 0, max( 1, min( 5000, (int) $limit ) ) );
	}

	public static function count_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( "SELECT coll, COUNT(*) AS n, MAX(updated_at) AS zadnje FROM {$table} GROUP BY coll ORDER BY coll", ARRAY_A );
	}
}
