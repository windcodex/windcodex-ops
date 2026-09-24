<?php
/**
 * Uninstall handler.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins
 * screen (not on ordinary deactivation). By default it does nothing –
 * settings, OAuth clients/tokens, and the activity log are all left in
 * place, so reinstalling the plugin later restores exactly where things
 * were. Full cleanup only happens if the admin explicitly opted into it
 * via the "Delete all data on uninstall" toggle in Settings > WindCodex Ops
 * > General before deleting the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * WindCodex Ops Pro shares this plugin's settings option and every table
 * dropped below (oauth_clients, activity_log, undo_log, redirects) rather
 * than using its own copies, so an upgrade carries data over with no
 * migration. That means if Pro is active right now, this Free plugin is
 * just a deactivated leftover on disk - deleting it must never drop the
 * tables/settings Pro is actively using. Bail out entirely in that case.
 */
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( is_plugin_active( 'windcodex-ops-pro/windcodex-ops-pro.php' ) ) {
	return;
}

$wcops_settings = get_option( 'wcops_settings', array() );

if ( empty( $wcops_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

delete_option( 'wcops_settings' );
delete_option( 'wcops_oauth_tables_installed' );
delete_option( 'wcops_activity_log_installed' );
delete_option( 'wcops_undo_log_installed' );
delete_option( 'wcops_redirects_installed' );
delete_option( 'wcops_rewrite_flushed_version' );
delete_option( 'wcops_last_activity' );

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names only, no user input; uninstall-time cleanup of this plugin's own tables, opted into explicitly.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_oauth_clients" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_oauth_codes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_oauth_tokens" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_activity_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_undo_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcops_redirects" );
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
