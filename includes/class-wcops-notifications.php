<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High-risk tool handling and email notifications.
 *
 * "High-risk" here means a tool whose change the undo log can't reverse
 * (permanent deletes, file overwrites, removals) or one that changes many
 * things at once. These back three General → Preferences settings:
 *
 *  - Require preview before high-risk actions: single-item high-risk tools
 *    return a preview until they're called again with confirm=true. Bulk
 *    tools already work that way on their own, whatever this setting says.
 *  - Email me on high-risk actions: one email per high-risk change that
 *    actually ran, capped per hour so a runaway session can't flood the inbox.
 *  - Weekly activity summary: a WP-Cron digest of the last 7 days.
 */
class WCOPS_Notifications {

	const WEEKLY_HOOK = 'wcops_weekly_summary';

	/** Emails per hour before high-risk alerts are held back. */
	const ALERT_HOURLY_CAP = 10;

	/**
	 * Single-item tools whose change can't be undone. These get the preview
	 * gate when "Require preview before high-risk actions" is on.
	 */
	const PREVIEW_TOOLS = array(
		'wp_delete_media',
		'wp_compress_image',
		'wp_delete_category',
		'wp_delete_tag',
		'wp_delete_menu_item',
		'wp_remove_widget',
		'wp_remove_navigation_menu_item',
	);

	/**
	 * Bulk tools. They always dry-run first and only change anything when
	 * called with confirm=true.
	 */
	const BULK_TOOLS = array(
		'wp_find_replace',
		'wp_bulk_publish_drafts',
		'wp_bulk_trash_old_drafts',
		'wp_bulk_delete_unused_media',
		'wp_cleanup_database',
	);

	public static function is_high_risk( $tool_name ) {
		return in_array( $tool_name, self::PREVIEW_TOOLS, true ) || in_array( $tool_name, self::BULK_TOOLS, true );
	}

	public static function needs_preview( $tool_name ) {
		return in_array( $tool_name, self::PREVIEW_TOOLS, true )
			&& WCOPS_Settings::instance()->get( 'require_preview_on_high_risk', true );
	}

	/**
	 * Whether a finished call actually changed something. Bulk tools called
	 * without confirm=true only returned a dry run.
	 */
	public static function did_apply( $tool_name, array $args, array $result ) {
		if ( ! empty( $result['isError'] ) ) {
			return false;
		}
		if ( in_array( $tool_name, self::BULK_TOOLS, true ) || self::needs_preview( $tool_name ) ) {
			return ! empty( $args['confirm'] );
		}
		return true;
	}

	/* -----------------------------------------------------------------
	 * High-risk alert
	 * ------------------------------------------------------------- */

	public static function maybe_send_high_risk_alert( $tool_name, array $args, array $result, $user_id ) {
		if ( ! self::is_high_risk( $tool_name ) || ! self::did_apply( $tool_name, $args, $result ) ) {
			return;
		}
		if ( ! WCOPS_Settings::instance()->get( 'email_alerts_on_high_risk', false ) ) {
			return;
		}

		$sent = (int) get_transient( 'wcops_alerts_sent_hour' );
		if ( $sent >= self::ALERT_HOURLY_CAP ) {
			return;
		}
		set_transient( 'wcops_alerts_sent_hour', $sent + 1, HOUR_IN_SECONDS );

		$user      = get_userdata( (int) $user_id );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$outcome   = isset( $result['content'][0]['text'] ) ? (string) $result['content'][0]['text'] : '';

		unset( $args['confirm'] );

		$lines = array(
			sprintf( 'A connected AI ran a high-risk action on %s.', $site_name ),
			'',
			'Tool:      ' . $tool_name,
			'Acting as: ' . ( $user ? $user->user_login . ' (' . $user->user_email . ')' : 'user #' . (int) $user_id ),
			'Time:      ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'Arguments: ' . ( $args ? wp_json_encode( $args ) : '(none)' ),
			'',
			'Result:',
			mb_substr( $outcome, 0, 1500 ),
			'',
			'Review activity: ' . admin_url( 'options-general.php?page=windcodex-ops&tab=log' ),
			'Turn these emails off under Settings → Windcodex Ops → General → Preferences.',
		);

		if ( $sent + 1 === self::ALERT_HOURLY_CAP ) {
			$lines[] = '';
			$lines[] = sprintf( 'This is alert %1$d of %1$d allowed per hour, so further alerts are paused until the hour is up. Check the Activity tab for anything after this.', self::ALERT_HOURLY_CAP );
		}

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] High-risk AI action: %s', $site_name, $tool_name ),
			implode( "\n", $lines )
		);
	}

	/* -----------------------------------------------------------------
	 * Weekly summary
	 * ------------------------------------------------------------- */

	public static function init() {
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'send_weekly_summary' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Keep the cron event in line with the setting - also covers sites that
	 * updated the plugin without re-activating it.
	 */
	public static function sync_schedule() {
		$enabled   = (bool) WCOPS_Settings::instance()->get( 'weekly_activity_summary', true );
		$scheduled = wp_next_scheduled( self::WEEKLY_HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::WEEKLY_HOOK );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
	}

	public static function send_weekly_summary() {
		if ( ! WCOPS_Settings::instance()->get( 'weekly_activity_summary', true ) ) {
			return;
		}

		global $wpdb;
		$since    = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$activity = $wpdb->prefix . 'wcops_activity_log';
		$undo     = $wpdb->prefix . 'wcops_undo_log';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names only; this plugin's own tables.
		$by_tool = $wpdb->get_results( $wpdb->prepare( "SELECT tool_name, COUNT(*) AS calls, SUM(success = 0) AS failed FROM {$activity} WHERE created_at >= %s GROUP BY tool_name ORDER BY calls DESC", $since ) );
		$changes = $wpdb->get_results( $wpdb->prepare( "SELECT summary, restored, user_id FROM {$undo} WHERE created_at >= %s ORDER BY id DESC", $since ) );
		$users   = array_unique(
			array_merge(
				$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$activity} WHERE created_at >= %s", $since ) ),
				wp_list_pluck( $changes, 'user_id' )
			)
		);
		// phpcs:enable

		$total_calls  = array_sum( wp_list_pluck( $by_tool, 'calls' ) );
		$total_failed = array_sum( wp_list_pluck( $by_tool, 'failed' ) );

		// Nothing happened this week - don't send an empty email.
		if ( ! $total_calls && ! $changes ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines     = array(
			sprintf( 'AI activity on %s over the last 7 days:', $site_name ),
			'',
			sprintf( '- %d tool calls (%d failed)', $total_calls, $total_failed ),
			sprintf( '- %d undoable changes (%d already undone)', count( $changes ), count( array_filter( wp_list_pluck( $changes, 'restored' ) ) ) ),
			sprintf( '- %d WordPress user(s) the AI acted as', count( $users ) ),
		);

		$high_risk = array_filter(
			$by_tool,
			function ( $row ) {
				return self::is_high_risk( $row->tool_name );
			}
		);
		if ( $high_risk ) {
			$lines[] = sprintf( '- %d high-risk tool calls', array_sum( wp_list_pluck( $high_risk, 'calls' ) ) );
		}

		if ( $by_tool ) {
			$lines[] = '';
			$lines[] = 'Most used tools:';
			foreach ( array_slice( $by_tool, 0, 10 ) as $row ) {
				$lines[] = sprintf( '  %s - %d', $row->tool_name, $row->calls ) . ( $row->failed ? sprintf( ' (%d failed)', $row->failed ) : '' );
			}
		}

		if ( $changes ) {
			$lines[] = '';
			$lines[] = 'Recent changes:';
			foreach ( array_slice( $changes, 0, 10 ) as $change ) {
				$lines[] = '  ' . wp_strip_all_tags( (string) $change->summary ) . ( $change->restored ? ' (undone)' : '' );
			}
		}

		if ( ! WCOPS_Settings::instance()->get( 'log_ai_activity', true ) ) {
			$lines[] = '';
			$lines[] = 'Note: "Show activity feed" is off, so tool calls are not being recorded - only undoable changes are counted.';
		}

		$lines[] = '';
		$lines[] = 'Full details: ' . admin_url( 'options-general.php?page=windcodex-ops&tab=log' );
		$lines[] = 'Turn this summary off under Settings → Windcodex Ops → General → Preferences.';

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] Weekly AI activity summary', $site_name ),
			implode( "\n", $lines )
		);
	}
}
