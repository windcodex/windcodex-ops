<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress core has no built-in URL redirect mechanism, so this is new
 * infrastructure specifically for wp_list_redirects/wp_create_redirect –
 * a small dedicated table plus a template_redirect hook that actually
 * performs the redirect when a matching path is requested.
 */
class WCOPS_Redirects {

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'wcops_redirects';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_path VARCHAR(500) NOT NULL,
				destination_url VARCHAR(500) NOT NULL,
				redirect_type SMALLINT NOT NULL DEFAULT 301,
				hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_path (source_path(191))
			) {$charset_collate};"
		);
	}

	public static function get_all() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_redirects';
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore
	}

	public static function create( $source_path, $destination_url, $type = 301 ) {
		global $wpdb;
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_redirects',
			array(
				'source_path'      => $source_path,
				'destination_url'  => $destination_url,
				'redirect_type'    => (int) $type,
				'created_at'       => current_time( 'mysql', true ),
			)
		);
		return $result ? (int) $wpdb->insert_id : false;
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $wpdb->prefix . 'wcops_redirects', array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Hooked to template_redirect – checks the current request path
	 * against the table and performs the redirect if there's a match.
	 */
	public static function maybe_redirect() {
		global $wpdb;
		$path  = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore
		$table = $wpdb->prefix . 'wcops_redirects';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", $path ) ); // phpcs:ignore

		if ( $row ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hit_count = hit_count + 1 WHERE id = %d", $row->id ) ); // phpcs:ignore
			wp_safe_redirect( $row->destination_url, (int) $row->redirect_type );
			exit;
		}
	}
}
