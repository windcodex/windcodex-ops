<?php
/**
 * Plugin Name:       WindCodex Ops – Safe AI Actions
 * Plugin URI:        https://windcodex.com/
 * Description:       Gives Claude, ChatGPT, and other MCP-compatible AI platforms a safe, pre-approved set of actions for everyday WordPress content management – posts, pages, media, SEO, navigation, and site health – with undo protection, previews before risky changes, and full OAuth-based authentication. Never runs raw code or touches site files directly.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WindCodex
 * Author URI:        https://windcodex.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windcodex-ops
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WCOPS_VERSION', '1.1.1' );
define( 'WCOPS_PLUGIN_FILE', __FILE__ );
define( 'WCOPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCOPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCOPS_REST_NAMESPACE', 'windcodex-ops/v1' );
define( 'WCOPS_REST_ROUTE', '/mcp' );
if ( ! defined( 'WCOPS_PLUGIN_BASE' ) ) {
	define( 'WCOPS_PLUGIN_BASE', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WCOPS_PRO_PLUGIN_BASE' ) ) {
	define( 'WCOPS_PRO_PLUGIN_BASE', 'windcodex-ops-pro/windcodex-ops-pro.php' );
}

require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-rate-limiter.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-settings.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-oauth.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-activity-log.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-undo-log.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-redirects.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-tools.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-server.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-abilities.php';
require_once WCOPS_PLUGIN_DIR . 'includes/class-wcops-icons.php';
require_once WCOPS_PLUGIN_DIR . 'admin/class-wcops-admin.php';

/**
 * True if a table this plugin owns is missing from the database. Used
 * alongside the install-flag options below – an option flag alone isn't
 * proof the table actually exists (it can drift out of sync with reality:
 * a botched migration, a table dropped outside the plugin, etc.), so the
 * safety net checks the real schema rather than trusting the flag blindly.
 */
function wcops_table_missing( $table_suffix ) {
	global $wpdb;
	$table = $wpdb->prefix . $table_suffix;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * WindCodex Ops Pro is a genuinely separate, standalone plugin (full free +
 * pro feature set) rather than an add-on to this one - the two are never
 * meant to run active at the same time on one site. If Pro is active,
 * defer to it entirely rather than risk both plugins registering the same
 * OAuth/REST routes and settings screen.
 */
function wcops_pro_is_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( WCOPS_PRO_PLUGIN_BASE );
}

/**
 * Set by wcops_activate() when an activation is refused because Pro is
 * active, so the Plugins screen that loads next can say "not activated"
 * rather than "deactivated".
 */
define( 'WCOPS_ACTIVATION_REFUSED', 'wcops_activation_refused' );

function wcops_activation_refused_message() {
	return __( '<strong>WindCodex Ops</strong> was not activated because <strong>WindCodex Ops Pro</strong> is already active. Pro includes every tool this plugin offers, so you don\'t need both. To use the free version instead, deactivate WindCodex Ops Pro first.', 'windcodex-ops' );
}

function wcops_pro_active_notice() {
	$refused = get_transient( WCOPS_ACTIVATION_REFUSED );
	delete_transient( WCOPS_ACTIVATION_REFUSED );

	$message = $refused
		? wcops_activation_refused_message()
		: __( '<strong>WindCodex Ops</strong> has been deactivated because <strong>WindCodex Ops Pro</strong> is active - Pro already includes every tool this plugin offers, so running both isn\'t necessary.', 'windcodex-ops' );

	echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
}

/**
 * On the Plugins screen straight after a refused activation, drop the
 * "Plugin activated." message WordPress would otherwise print next to ours.
 */
function wcops_hide_activated_message() {
	if ( get_transient( WCOPS_ACTIVATION_REFUSED ) ) {
		unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only suppresses a core display message, no state change.
	}
}

/**
 * Kick everything off.
 */
function wcops_init() {
	if ( wcops_pro_is_active() ) {
		// Straight after a refused activation, only switch off on a real admin
		// page (normally the Plugins screen WordPress redirects to), where the
		// notice below can explain it. A cron, AJAX, REST or front-end request
		// that happens to run first would otherwise deactivate Ops silently.
		// Until then Ops stays inert; the transient's expiry bounds the wait.
		if ( get_transient( WCOPS_ACTIVATION_REFUSED ) && ( ! is_admin() || wp_doing_ajax() ) ) {
			return;
		}

		// Silent: skip our deactivation hook - nothing was set up, and
		// flushing rewrite rules this early (plugins_loaded) isn't safe.
		deactivate_plugins( WCOPS_PLUGIN_BASE, true );
		if ( is_admin() ) {
			add_action( 'load-plugins.php', 'wcops_hide_activated_message' );
			add_action( 'admin_notices', 'wcops_pro_active_notice' );
			add_action( 'network_admin_notices', 'wcops_pro_active_notice' );
		}
		return;
	}

	// Registered here, after the Pro check, so nothing of Ops runs next to
	// Pro and a refused activation leaves no trace (no flush, no option).
	add_action( 'init', 'wcops_maybe_flush_rewrite_rules', 20 );
	add_action( 'template_redirect', array( 'WCOPS_Redirects', 'maybe_redirect' ) );
	add_filter( 'robots_txt', 'wcops_filter_robots_txt' );

	WCOPS_Settings::instance();
	WCOPS_OAuth::instance();
	WCOPS_Server::instance();
	WCOPS_Abilities::instance();

	// Safety net for sites upgrading between versions: make sure the
	// tables exist even if the activation hook never re-ran.
	if ( ! get_option( 'wcops_oauth_tables_installed' ) || wcops_table_missing( 'wcops_oauth_clients' ) ) {
		WCOPS_OAuth::install_tables();
		update_option( 'wcops_oauth_tables_installed', WCOPS_VERSION );
	}

	if ( ! get_option( 'wcops_activity_log_installed' ) || wcops_table_missing( 'wcops_activity_log' ) ) {
		WCOPS_Activity_Log::install_table();
		update_option( 'wcops_activity_log_installed', WCOPS_VERSION );
	}

	if ( ! get_option( 'wcops_undo_log_installed' ) || wcops_table_missing( 'wcops_undo_log' ) ) {
		WCOPS_Undo_Log::install_table();
		update_option( 'wcops_undo_log_installed', WCOPS_VERSION );
	}

	if ( ! get_option( 'wcops_redirects_installed' ) || wcops_table_missing( 'wcops_redirects' ) ) {
		WCOPS_Redirects::install_table();
		update_option( 'wcops_redirects_installed', WCOPS_VERSION );
	}

	/**
	 * If a custom robots.txt has been saved via wp_update_robots_txt,
	 * serve it instead of WordPress core's own default output. WordPress
	 * only ever fires do_robots at all when no physical robots.txt file
	 * exists on the server, so a real file always wins automatically -
	 * this hook never needs to check for that itself.
	 */
	add_action(
		'do_robots',
		function () {
			$custom = get_option( 'wcops_custom_robots_txt', '' );
			if ( '' === trim( (string) $custom ) ) {
				return;
			}
			remove_action( 'do_robots', 'do_robots' );
			header( 'Content-Type: text\/plain; charset=utf-8' );
			echo esc_html( $custom );
		},
		1
	);

	if ( is_admin() ) {
		WCOPS_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'wcops_init' );

/**
 * Applies the custom robots.txt content set via wp_update_robots_txt, if
 * any – only takes effect when WordPress is generating robots.txt
 * dynamically (i.e. no physical robots.txt file exists on the server).
 */
function wcops_filter_robots_txt( $output ) {
	$custom = get_option( 'wcops_custom_robots_txt' );
	return $custom ? $custom : $output;
}

/**
 * Flush rewrite rules once after updating to a version that changed them,
 * for sites that update plugin files without deactivating/reactivating.
 *
 * IMPORTANT: this must run on 'init' (after WCOPS_OAuth has registered its
 * rewrite rule, also on 'init') rather than on 'plugins_loaded' – WordPress's
 * rewrite engine isn't fully ready that early, and calling flush_rewrite_rules()
 * during plugins_loaded can throw a fatal error.
 */
function wcops_maybe_flush_rewrite_rules() {
	if ( get_option( 'wcops_rewrite_flushed_version' ) !== WCOPS_VERSION ) {
		flush_rewrite_rules();
		update_option( 'wcops_rewrite_flushed_version', WCOPS_VERSION );
	}
}

/**
 * On activation: refuse to activate, with an explanation, if Pro is already
 * running (Pro includes every feature this plugin offers), otherwise set sane defaults and
 * create the OAuth tables (including a pre-registered static client so
 * there's always a Client ID/Secret an admin can paste manually if a
 * platform's auto-registration fails).
 */
function wcops_activate() {
	if ( wcops_pro_is_active() ) {
		// WP-CLI has no Plugins screen to show a notice on, so refuse outright:
		// WordPress only adds a plugin to active_plugins after this hook
		// returns, so stopping here leaves Ops inactive with the reason
		// printed as a CLI error.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			wp_die( wp_kses_post( wcops_activation_refused_message() ) );
		}

		// In the browser, skip all setup and let WordPress return to the
		// Plugins screen; wcops_init() switches Ops straight back off on
		// that load and shows wcops_pro_active_notice() explaining why.
		set_transient( WCOPS_ACTIVATION_REFUSED, 1, 5 * MINUTE_IN_SECONDS );
		return;
	}

	if ( false === get_option( 'wcops_settings' ) ) {
		update_option( 'wcops_settings', WCOPS_Settings::default_settings() );
	}

	WCOPS_OAuth::install_tables();
	update_option( 'wcops_oauth_tables_installed', WCOPS_VERSION );

	WCOPS_Activity_Log::install_table();
	update_option( 'wcops_activity_log_installed', WCOPS_VERSION );

	WCOPS_Undo_Log::install_table();
	update_option( 'wcops_undo_log_installed', WCOPS_VERSION );

	WCOPS_Redirects::install_table();
	update_option( 'wcops_redirects_installed', WCOPS_VERSION );

	// The rewrite rule is normally registered on 'init', which has already
	// fired by the time this activation callback runs – so register it
	// explicitly here before flushing, or the rule won't exist yet to flush.
	WCOPS_OAuth::add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wcops_activate' );

/**
 * On deactivation: just flush rewrite rules. We deliberately keep settings
 * so re-activating doesn't break an existing connector.
 */
function wcops_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wcops_deactivate' );
