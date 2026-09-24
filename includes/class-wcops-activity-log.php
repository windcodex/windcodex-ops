<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal audit log recording every action the AI takes through this
 * plugin – success or failure – so site owners can see exactly what
 * happened and when. See WCOPS_Undo_Log for the separate, reversible
 * change history that powers the 72-hour undo window.
 */
class WCOPS_Activity_Log {

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'wcops_activity_log';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				tool_name VARCHAR(64) NOT NULL,
				summary TEXT NULL,
				success TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id)
			) {$charset_collate};"
		);
	}

	public static function record( $user_id, $tool_name, $summary, $success = true ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_activity_log',
			array(
				'user_id'    => (int) $user_id,
				'tool_name'  => sanitize_key( $tool_name ),
				'summary'    => wp_kses_post( mb_substr( (string) $summary, 0, 2000 ) ),
				'success'    => $success ? 1 : 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	public static function get_recent( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_activity_log';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public static function get_by_tool_name( $tool_name, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_activity_log';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tool_name = %s ORDER BY id DESC LIMIT %d", sanitize_key( $tool_name ), $limit ) ); // phpcs:ignore
	}

	public static function clear() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_activity_log';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
