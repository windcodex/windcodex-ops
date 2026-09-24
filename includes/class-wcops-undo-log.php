<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 72-hour undo window – WindCodex Ops's core safety feature, available
 * at every tier, no exceptions. Every mutating tool call that changes
 * existing content records a snapshot of what it looked like *before* the
 * change, here. wp_undo_change can restore from that snapshot within the
 * configured window (default 72 hours – see WCOPS_Settings::get_undo_window_hours()).
 *
 * This is deliberately a separate table from the Activity Log: the
 * Activity Log is a human-readable audit trail; this table exists purely
 * to make a restore operation possible, and stores the actual before/after
 * data needed to do that.
 */
class WCOPS_Undo_Log {

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'wcops_undo_log';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				tool_name VARCHAR(64) NOT NULL,
				object_type VARCHAR(32) NOT NULL,
				object_id BIGINT UNSIGNED NOT NULL,
				action VARCHAR(32) NOT NULL,
				before_data LONGTEXT NULL,
				summary VARCHAR(255) NULL,
				restored TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY object_lookup (object_type, object_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Record a snapshot before a mutating action runs. $before_data should
	 * be an associative array of whatever fields wp_undo_change would need
	 * to actually restore the object (e.g. post_title, post_content,
	 * post_status for a post edit).
	 */
	public static function record( $user_id, $tool_name, $object_type, $object_id, $action, array $before_data, $summary = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_undo_log',
			array(
				'user_id'     => (int) $user_id,
				'tool_name'   => sanitize_key( $tool_name ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => (int) $object_id,
				'action'      => sanitize_key( $action ),
				'before_data' => wp_json_encode( $before_data ),
				'summary'     => mb_substr( (string) $summary, 0, 255 ),
				'restored'    => 0,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_undo_log';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Every entry still within the undo window and not already restored,
	 * most recent first.
	 */
	public static function get_undoable( $window_hours, $limit = 50 ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'wcops_undo_log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $window_hours * HOUR_IN_SECONDS ) );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE restored = 0 AND created_at >= %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- esc_sql()'d table name; %s can't parameterize identifiers.
				$cutoff,
				$limit
			)
		);
	}

	public static function get_recent( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_undo_log';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	/**
	 * How many changes have been recorded so far today (UTC). This is a
	 * real count of actual mutating actions, not a decorative number –
	 * it deliberately doesn't include read-only tool calls, since those
	 * aren't tracked anywhere in this table.
	 */
	public static function get_today_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_undo_log';
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today_start ) ); // phpcs:ignore
	}

	public static function mark_restored( $id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'wcops_undo_log',
			array( 'restored' => 1 ),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Shared restore logic used by both the wp_undo_change MCP tool and
	 * the admin UI's inline Undo button – one implementation, so the two
	 * surfaces can never quietly drift apart. Returns a plain string on
	 * success or a WP_Error on failure.
	 *
	 * Dispatches to a type-specific restore helper below, because
	 * "restore" means something different for each object_type: posts and
	 * pages come back via wp_update_post()/wp_untrash_post(); categories
	 * and tags need their own logic since terms don't share the post
	 * table's trash/revision machinery.
	 */
	public static function restore( $id, $undo_window_hours ) {
		$entry = self::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'not_found', "No change found with ID {$id}." );
		}
		if ( $entry->restored ) {
			return new WP_Error( 'already_restored', "Change #{$id} has already been undone." );
		}

		$expires_at = strtotime( $entry->created_at . ' UTC' ) + ( (int) $undo_window_hours * HOUR_IN_SECONDS );
		if ( time() > $expires_at ) {
			return new WP_Error( 'expired', "Change #{$id} is outside the {$undo_window_hours}-hour undo window and can no longer be undone." );
		}

		$before = json_decode( $entry->before_data, true );
		$before = is_array( $before ) ? $before : array();

		switch ( $entry->object_type ) {
			case 'category':
			case 'tag':
				$result = self::restore_term( $entry, $before );
				break;
			default:
				$result = self::restore_post( $entry, $before );
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_restored( $id );

		return "Undone: {$entry->summary} has been restored to how it looked before that change.";
	}

	/**
	 * Posts and pages – restores title/content/status, or untrashes on a
	 * delete.
	 */
	private static function restore_post( $entry, $before ) {
		if ( ! current_user_can( 'edit_post', $entry->object_id ) ) {
			return new WP_Error( 'forbidden', 'Acting user is not permitted to edit this item.' );
		}
		if ( 'delete' === $entry->action ) {
			$result = wp_untrash_post( $entry->object_id );
			return $result ? true : new WP_Error( 'restore_failed', "Could not restore {$entry->object_type} #{$entry->object_id} from trash." );
		}
		$result = wp_update_post(
			array(
				'ID'           => $entry->object_id,
				'post_title'   => $before['post_title'] ?? '',
				'post_content' => $before['post_content'] ?? '',
				'post_status'  => $before['post_status'] ?? 'draft',
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Categories and tags. WordPress has no trash for taxonomy terms, so a
	 * deleted term can only be recreated from its saved name/slug/
	 * description/parent – any posts that had it are not automatically
	 * reassigned, since that would require snapshotting every affected
	 * post at delete time.
	 */
	private static function restore_term( $entry, $before ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return new WP_Error( 'forbidden', 'Acting user is not permitted to manage categories or tags.' );
		}

		$taxonomy = $before['taxonomy'] ?? ( 'category' === $entry->object_type ? 'category' : 'post_tag' );

		if ( 'delete' === $entry->action ) {
			$args = array();
			if ( ! empty( $before['description'] ) ) {
				$args['description'] = $before['description'];
			}
			if ( ! empty( $before['parent'] ) ) {
				$args['parent'] = (int) $before['parent'];
			}
			if ( ! empty( $before['slug'] ) ) {
				$args['slug'] = $before['slug'];
			}
			$result = wp_insert_term( $before['name'] ?? '', $taxonomy, $args );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( ! term_exists( (int) $entry->object_id, $taxonomy ) ) {
			return new WP_Error( 'not_found', "Term #{$entry->object_id} no longer exists." );
		}
		$update = array(
			'name'        => $before['name'] ?? '',
			'slug'        => $before['slug'] ?? '',
			'description' => $before['description'] ?? '',
		);
		if ( 'category' === $taxonomy ) {
			$update['parent'] = (int) ( $before['parent'] ?? 0 );
		}
		$result = wp_update_term( (int) $entry->object_id, $taxonomy, $update );
		return is_wp_error( $result ) ? $result : true;
	}

	public static function clear() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_undo_log';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
