<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the same tool catalog as WCOPS_Server through WordPress's native
 * Abilities API (wp_register_ability(), WP 6.9+), tagged so any adapter built
 * on that API — including the "Enable Abilities for MCP" plugin's default
 * MCP server and its admin "Abilities" tab — can list and run these tools
 * without knowing anything about this plugin's own OAuth server.
 *
 * This is a second, parallel exposure of the existing tool definitions and
 * dispatcher (WCOPS_Tools) — the bespoke JSON-RPC server in
 * class-wcops-server.php keeps working unchanged for existing Claude custom
 * connector installs.
 */
class WCOPS_Abilities {

	const CATEGORY = 'windcodex-ops';

	private static $instance = null;

	/** @var WCOPS_Settings */
	private $settings;

	/** @var WCOPS_Tools */
	private $tools;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = WCOPS_Settings::instance();
		$this->tools    = new WCOPS_Tools( $this->settings );

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WindCodex Ops', 'windcodex-ops' ),
				'description' => __( 'Content, media, users, security, and WooCommerce actions exposed by WindCodex Ops.', 'windcodex-ops' ),
			)
		);
	}

	/**
	 * Registers every tool from WCOPS_Tools::get_tool_definitions() as a
	 * native WP Ability. Runs on every request that reaches this hook, so
	 * disabled tool groups / read-only mode (already applied inside
	 * get_tool_definitions()) are reflected automatically.
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // WP < 6.9 – the Abilities API isn't available.
		}

		foreach ( $this->tools->get_tool_definitions() as $tool ) {
			$this->register_one( $tool );
		}
	}

	private function register_one( array $tool ) {
		$tool_name    = $tool['name'];
		$ability_name = self::CATEGORY . '/' . str_replace( '_', '-', $tool_name );

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
			return;
		}

		$readonly    = ! empty( $tool['annotations']['readOnlyHint'] );
		$destructive = ! empty( $tool['annotations']['destructiveHint'] );

		wp_register_ability(
			$ability_name,
			array(
				'label'               => $tool['title'] ?? $tool_name,
				'description'         => $tool['description'] ?? '',
				'category'            => self::CATEGORY,
				'input_schema'        => $tool['inputSchema'] ?? array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) use ( $tool_name ) {
					return $this->execute( $tool_name, (array) $input );
				},
				'permission_callback' => function () use ( $tool_name, $destructive ) {
					return current_user_can( $this->capability_for( $tool_name, $destructive ) );
				},
				'meta'                => array(
					// Core's own generic broadcast flags (documentation / future core surfaces).
					'public'       => true,
					// This plugin already gates everything behind its own OAuth/REST
					// layer for the JSON-RPC server; don't also open a second, raw
					// core REST route for these abilities.
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => $readonly,
						'destructive' => $destructive,
					),
					// The convention read by wordpress/mcp-adapter's default server
					// (bundled by "Enable Abilities for MCP" and similar plugins) to
					// decide what's MCP-reachable.
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Runs the tool through the same dispatcher and per-tool authorization
	 * as the OAuth MCP server (WCOPS_Tools::call() still does its own
	 * current_user_can( 'delete_post', $id )-style checks, read-only mode,
	 * undo snapshots, etc.), then unwraps the MCP {content, isError} envelope
	 * into the plain return value / WP_Error the Abilities API expects.
	 *
	 * Passes the real logged-in user (not this connector's configured acting
	 * user) so those internal checks run as *them*.
	 */
	private function execute( $tool_name, array $input ) {
		$result = $this->tools->call( $tool_name, $input, get_current_user_id() );
		$text   = $result['content'][0]['text'] ?? '';

		if ( ! empty( $result['isError'] ) ) {
			return new WP_Error( 'wcops_tool_error', $text, array( 'status' => 400 ) );
		}

		return $text;
	}

	/**
	 * Coarse, defense-in-depth capability gate for the ability itself — the
	 * fine-grained, per-item checks (e.g. delete_post on a specific ID,
	 * edit_users for role changes) still happen inside WCOPS_Tools::call(),
	 * same as they do for the OAuth server. This just decides who can reach
	 * the tool at all via this exposure path.
	 *
	 * Unknown/ungrouped tool names deliberately fall back to the strictest
	 * option (manage_options) rather than guessing loose — content tools
	 * (posts, pages, media, taxonomy, comments, menus, SEO, forms) are the
	 * only ones treated as safe for a regular editor. Override per site with
	 * the wcops_ability_capability filter.
	 */
	private function capability_for( $tool_name, $destructive ) {
		if ( 0 === strpos( $tool_name, 'woo_' ) ) {
			$capability = 'manage_woocommerce';
		} else {
			$content_keywords = array( 'post', 'page', 'media', 'image', 'categor', 'tag', 'comment', 'menu', 'seo', 'rankmath', 'yoast', 'seopress', 'form', 'redirect', 'custom_field', 'undo' );

			$is_content = false;
			foreach ( $content_keywords as $keyword ) {
				if ( false !== strpos( $tool_name, $keyword ) ) {
					$is_content = true;
					break;
				}
			}

			if ( $is_content ) {
				$capability = $destructive ? 'delete_posts' : 'edit_posts';
			} else {
				// Users, security, site health, advanced admin, memory, and
				// anything not recognized above.
				$capability = 'manage_options';
			}
		}

		return apply_filters( 'wcops_ability_capability', $capability, $tool_name, $destructive );
	}
}
