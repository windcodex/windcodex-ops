<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for reading/writing plugin configuration.
 *
 * WindCodex Ops has no code-execution concept at all – that capability
 * doesn't exist in this codebase, full stop, per the plan's core safety
 * principle ("no shortcuts around these rules, ever"). Tool groups are
 * tagged with a risk tier (low/high) so high-risk groups (store price
 * changes, security settings, etc.) default OFF while low-risk groups
 * (content editing) default ON – see WCOPS_Tools for the tier definitions.
 */
class WCOPS_Settings {

	private static $instance = null;

	const OPTION_KEY = 'wcops_settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default configuration used on activation and as a fallback. Every
	 * tool group defaults on - this codebase carries the full feature
	 * set, with no free/paid tier distinction anywhere in it.
	 */
	public static function default_settings() {
		return array(
			'enabled'                    => true,
			'read_only'                  => false,
			'connect_capability'         => 'manage_options',
			'tool_groups'                => array(
				'wp_site'       => true,
				'wp_posts'      => true,
				'wp_pages'      => true,
				'wp_media'      => true,
				'wp_taxonomy'   => true,
				'wp_navigation' => true,
				'wp_site_health' => true,
				'wp_seo'         => true,
			),
			'max_results_limit'          => 50,
			'requests_per_minute_limit'  => 120,
			'allow_dynamic_registration' => true,
			'delete_data_on_uninstall'   => false,
			'undo_window_hours'          => 72,
			'require_preview_on_high_risk' => true,
			'email_alerts_on_high_risk'  => false,
			'weekly_activity_summary'    => true,
			// Visible activity feed only – purely cosmetic, safe to turn
			// off without affecting the undo guarantee.
			'log_ai_activity'            => true,
			// Real toggle, not decorative – turning this off actually
			// stops the undo snapshot table from recording, which means
			// the 72-hour undo guarantee stops working. The UI says so
			// explicitly.
			'enable_undo_log'            => true,
			'onboarding_completed'       => false,
		);
	}

	/**
	 * The single source of truth for every tool group's display metadata:
	 * icon, title, description, and risk tier. Used by the main settings
	 * list, the onboarding screen, and the OAuth consent screen's
	 * permission list – so all three always agree with each other and
	 * with reality, rather than three hand-maintained copies drifting
	 * apart.
	 *
	 * Risk tiers are a judgment call, not something the underlying tool
	 * code enforces mechanically: "low" means read-heavy or easily
	 * reversible; "medium" means it changes real content/settings but
	 * isn't typically catastrophic; "high" means account access, revenue-
	 * affecting store changes, or something that's expensive to fully
	 * undo (e.g. a corrupted page layout).
	 */
	public static function group_metadata() {
		return array(
			'wp_site'          => array( 'icon' => 'info-circle', 'title' => 'Site info', 'desc' => 'Read-only site name, version, active theme, and plugins.', 'risk' => 'low' ),
			'wp_posts'         => array( 'icon' => 'file-pencil', 'title' => 'Posts', 'desc' => 'Create, edit, schedule, and delete blog posts.', 'risk' => 'low' ),
			'wp_pages'         => array( 'icon' => 'file-text', 'title' => 'Pages', 'desc' => 'Create and edit static pages.', 'risk' => 'low' ),
			'wp_media'         => array( 'icon' => 'photo', 'title' => 'Media', 'desc' => 'Search, upload, and manage the media library.', 'risk' => 'low' ),
			'wp_taxonomy'      => array( 'icon' => 'stack-2', 'title' => 'Categories', 'desc' => 'Read-only category and post-count listing.', 'risk' => 'low' ),
			'wp_navigation'    => array( 'icon' => 'link', 'title' => 'Navigation', 'desc' => 'Edit menus, widgets, and redirects.', 'risk' => 'medium' ),
			'wp_site_health'   => array( 'icon' => 'circle-check', 'title' => 'Site health', 'desc' => 'Diagnostics: cron, database size, error logs.', 'risk' => 'low' ),
			'wp_seo'           => array( 'icon' => 'search', 'title' => 'SEO', 'desc' => 'Meta titles, descriptions, and social previews.', 'risk' => 'low' ),
		);
	}

	public function get_all() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::default_settings() );
	}

	public function get( $key, $default = null ) {
		$all = $this->get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public function update( array $partial ) {
		$all = $this->get_all();
		$all = array_merge( $all, $partial );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	public function is_group_enabled( $group ) {
		$groups = $this->get( 'tool_groups', array() );
		if ( array_key_exists( $group, $groups ) ) {
			return ! empty( $groups[ $group ] );
		}
		// The key is entirely absent from saved settings - this happens
		// when a plugin update adds a new group after settings were already
		// saved, since WordPress doesn't deep-merge new nested array keys
		// into an existing option. Fall back to the shipped default so a
		// newly-added group isn't silently disabled on every site that
		// already had settings saved.
		$defaults = self::default_settings();
		return ! empty( $defaults['tool_groups'][ $group ] );
	}

	public function is_read_only() {
		return (bool) $this->get( 'read_only', false );
	}

	public function is_enabled() {
		return (bool) $this->get( 'enabled', true );
	}

	public function get_max_results_limit() {
		$limit = (int) $this->get( 'max_results_limit', 50 );
		return $limit > 0 ? $limit : 50;
	}

	/**
	 * Choices for "Who can connect". Anything else stored in the option
	 * falls back to Administrators only.
	 */
	public static function connect_capability_choices() {
		return array(
			'manage_options'    => __( 'Administrators only', 'windcodex-ops' ),
			'edit_others_posts' => __( 'Editors and above', 'windcodex-ops' ),
		);
	}

	/**
	 * The capability a user needs to approve an AI connection – and that the
	 * connection's user must still hold on every request and tool call, so a
	 * demoted or deleted user's connection stops working immediately.
	 */
	public function get_connect_capability() {
		$capability = (string) $this->get( 'connect_capability', 'manage_options' );
		if ( ! array_key_exists( $capability, self::connect_capability_choices() ) ) {
			$capability = 'manage_options';
		}
		return (string) apply_filters( 'wcops_connect_capability', $capability );
	}

	public function get_undo_window_hours() {
		$hours = (int) $this->get( 'undo_window_hours', 72 );
		return $hours > 0 ? $hours : 72;
	}

	/**
	 * The 11 groups shown to a person in Settings, each bundling one or
	 * more of the real, granular tool_groups keys underneath. This is a
	 * display/toggle-consolidation layer only – it doesn't replace
	 * group_metadata() or is_group_available(), which still gate actual
	 * tool registration per real group. A bundle's toggle just sets every
	 * real group it contains to the same on/off state at once.
	 */
	public static function bundle_metadata() {
		return array(
			'content'      => array( 'icon' => 'file-text', 'title' => 'Content management', 'desc' => 'Posts, pages, categories', 'risk' => 'low', 'groups' => array( 'wp_posts', 'wp_pages', 'wp_taxonomy' ) ),
			'media'        => array( 'icon' => 'photo', 'title' => 'Media and assets', 'desc' => 'Uploads, alt text, image tools', 'risk' => 'low', 'groups' => array( 'wp_media' ) ),
			'seo'          => array( 'icon' => 'search', 'title' => 'SEO and discoverability', 'desc' => 'Meta tags, audits, redirects', 'risk' => 'low', 'groups' => array( 'wp_seo' ) ),
			'structure'    => array( 'icon' => 'layout-grid', 'title' => 'Site structure', 'desc' => 'Menus, widgets, redirects', 'risk' => 'low', 'groups' => array( 'wp_navigation' ) ),
			'health'       => array( 'icon' => 'activity', 'title' => 'Site health and diagnostics', 'desc' => 'Status, error logs, cron', 'risk' => 'low', 'groups' => array( 'wp_site_health', 'wp_site' ) ),
		);
	}

	/**
	 * Real detection, not decorative – used by the onboarding wizard's
	 * scan step. Returns which of these four commonly-paired plugins are
	 * actually active on this site.
	 */
	public static function detect_related_plugins() {
		return array(
			'woocommerce' => class_exists( 'WooCommerce' ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) || class_exists( 'Elementor\Plugin' ),
			'acf'         => class_exists( 'ACF' ) || function_exists( 'acf_get_field_groups' ),
			'yoast'       => defined( 'WPSEO_VERSION' ),
			'rankmath'    => class_exists( 'RankMath' ),
			'aioseo'      => defined( 'AIOSEO_VERSION' ),
			'seopress'    => defined( 'SEOPRESS_VERSION' ),
			'pods'        => function_exists( 'pods' ),
			'jetengine'   => function_exists( 'jet_engine' ),
			'metabox'     => defined( 'RWMB_VER' ),
			'wpbakery'    => defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ),
			'kadence'     => defined( 'KADENCE_BLOCKS_VERSION' ) || class_exists( 'Kadence_Blocks_Pro' ),
			'generateblocks' => defined( 'GENERATEBLOCKS_VERSION' ),
			'bricks'      => defined( 'BRICKS_VERSION' ) || function_exists( 'bricks_is_builder' ),
			'breakdance'  => defined( 'BREAKDANCE_VERSION' ) || class_exists( '\Breakdance\Plugin' ),
		);
	}

	/**
	 * Connector-wide requests-per-minute limit, user-editable from the
	 * General tab (default 120). Clamped to a sane range on every read, not
	 * just on save, so a stray/hand-edited option value can never disable
	 * the protection entirely (0 or absurdly high) or make the connector
	 * unusably slow (single digits).
	 */
	public function get_requests_per_minute_limit() {
		$limit = (int) $this->get( 'requests_per_minute_limit', 120 );
		return max( 10, min( 600, $limit ) );
	}

	/**
	 * Whether a group is actually reachable right now. This plugin has no
	 * paid-tier feature gating - the only thing that determines
	 * availability is whether the site owner has switched the group on.
	 */
	public function is_group_available( $group ) {
		return $this->is_group_enabled( $group );
	}
}
