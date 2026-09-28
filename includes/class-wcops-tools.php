<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines every tool this connector can offer, and dispatches tool calls.
 *
 * Tools are grouped (wp_posts, wp_pages, woo_products, woo_orders) so the
 * admin settings page can turn whole groups on/off. Every write tool also
 * checks the global "read only" switch before doing anything.
 */
class WCOPS_Tools {

	/** @var WCOPS_Settings */
	private $settings;

	public function __construct( WCOPS_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the list of tools currently exposed, based on enabled groups
	 * and the read-only switch. Shape matches the MCP tools/list schema.
	 */
	public function get_tool_definitions() {
		$tools = array();

		if ( $this->settings->is_group_available( 'wp_site' ) ) {
			$tools = array_merge( $tools, $this->wp_site_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_posts' ) ) {
			$tools = array_merge( $tools, $this->wp_post_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_pages' ) ) {
			$tools = array_merge( $tools, $this->wp_page_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_media' ) ) {
			$tools = array_merge( $tools, $this->wp_media_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_taxonomy' ) ) {
			$tools = array_merge( $tools, $this->wp_taxonomy_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_posts' ) ) {
			$tools = array_merge( $tools, $this->content_depth_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_navigation' ) ) {
			$tools = array_merge( $tools, $this->wp_navigation_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_site_health' ) ) {
			$tools = array_merge( $tools, $this->wp_site_health_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_posts' ) ) {
			$tools = array_merge( $tools, $this->wp_bulk_tools() );
		}

		if ( $this->settings->is_group_available( 'wp_seo' ) ) {
			$tools = array_merge( $tools, $this->seo_tools() );
		}

		// Undo is always available, at every tier – not gated by any
		// tool_groups toggle, since a safety net that can be switched off
		// isn't much of a safety net.
		$tools = array_merge( $tools, $this->undo_tools() );

		// Advertise the confirm step on tools the preview gate applies to.
		foreach ( $tools as &$tool ) {
			if ( WCOPS_Notifications::needs_preview( $tool['name'] ) ) {
				$tool['inputSchema']['properties']['confirm'] = array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'false (default) returns a preview only. Set true, after the user approves the preview, to actually run this action. It cannot be undone.',
				);
				$tool['description'] .= ' High-risk: the first call returns a preview; call again with confirm=true to apply.';
			}
		}
		unset( $tool );

		// If read-only mode is on, strip out every tool that isn't flagged readOnlyHint.
		if ( $this->settings->is_read_only() ) {
			$tools = array_values(
				array_filter(
					$tools,
					function ( $tool ) {
						return ! empty( $tool['annotations']['readOnlyHint'] );
					}
				)
			);
		}

		return $tools;
	}

	/**
	 * Dispatch a tools/call request to the right handler.
	 *
	 * @param string   $name    Tool name.
	 * @param array    $args    Arguments supplied by Claude.
	 * @param int      $user_id WP user ID resolved from the request's auth:
	 *                          the user who logged in and approved the OAuth
	 *                          consent screen, or the logged-in user for an
	 *                          Abilities API call. There is no fallback user.
	 * @return array MCP-shaped tool result: ['content' => [...], 'isError' => bool]
	 */
	public function call( $name, array $args, $user_id ) {
		$available = wp_list_pluck( $this->get_tool_definitions(), 'name' );

		if ( ! in_array( $name, $available, true ) ) {
			return $this->error_result( "Unknown or disabled tool: {$name}" );
		}

		// Run as the authenticated user so every tool's own capability check
		// applies to that real account. There is no fallback user.
		$acting_user_id = (int) $user_id;
		if ( $acting_user_id <= 0 ) {
			return $this->error_result( 'No authenticated user for this call.' );
		}
		wp_set_current_user( $acting_user_id );

		// Deny by default: every tool first requires the "Who can connect"
		// capability, on top of any stricter check inside the tool itself.
		if ( ! current_user_can( $this->settings->get_connect_capability() ) ) {
			return $this->error_result( 'Your account is not allowed to use this connector.' );
		}

		// "Require preview before high-risk actions": changes the undo log
		// can't reverse only run once the AI has shown this preview and
		// comes back with confirm=true.
		if ( WCOPS_Notifications::needs_preview( $name ) && empty( $args['confirm'] ) && ! $this->settings->is_read_only() ) {
			return $this->high_risk_preview( $name, $args );
		}

		$result = $this->dispatch( $name, $args );

		WCOPS_Notifications::maybe_send_high_risk_alert( $name, $args, $result, $acting_user_id );

		return $result;
	}

	/**
	 * Preview returned instead of running a gated high-risk tool.
	 */
	private function high_risk_preview( $name, array $args ) {
		$definition = null;
		foreach ( $this->get_tool_definitions() as $tool ) {
			if ( $tool['name'] === $name ) {
				$definition = $tool;
				break;
			}
		}

		$partial_undo = in_array( $name, array( 'wp_delete_category', 'wp_delete_tag' ), true );
		$targets      = array();
		foreach ( $args as $key => $value ) {
			if ( ! is_numeric( $value ) || ! preg_match( '/(^|_)id$/', $key ) ) {
				continue;
			}
			$id = (int) $value;
			if ( in_array( $name, array( 'wp_delete_category', 'wp_delete_tag' ), true ) ) {
				$term = get_term( $id );
				if ( $term && ! is_wp_error( $term ) ) {
					$targets[] = sprintf( '%s "%s" (ID %d, used by %d posts)', $term->taxonomy, $term->name, $id, $term->count );
				}
			} elseif ( 'wp_remove_navigation_menu_item' !== $name ) {
				$post = get_post( $id );
				if ( $post ) {
					$targets[] = sprintf( '%s "%s" (ID %d)', $post->post_type, $post->post_title, $id );
				}
			}
		}

		return $this->text_result(
			wp_json_encode(
				array(
					'preview_only' => true,
					'tool'         => $name,
					'action'       => $definition['description'] ?? $name,
					'arguments'    => $args,
					'targets'      => $targets,
					'undoable'     => $partial_undo ? 'partial - undo recreates the term, but posts are not re-assigned to it' : false,
					'note'         => 'Nothing has been changed. This is a high-risk action that cannot be fully undone. Show this preview to the user, and only if they approve, call ' . $name . ' again with the same arguments plus confirm: true.',
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Run a tool by name. Callers have already checked availability and
	 * permissions.
	 */
	private function dispatch( $name, array $args ) {
		try {
			switch ( $name ) {
				case 'wp_get_site_info':
					return $this->wp_get_site_info( $args );
				case 'wp_list_posts':
					return $this->wp_list_posts( $args );
				case 'wp_get_post':
					return $this->wp_get_post( $args );
				case 'wp_create_post':
					return $this->wp_create_post( $args );
				case 'wp_update_post':
					return $this->wp_update_post( $args );
				case 'wp_delete_post':
					return $this->wp_delete_post( $args );
				case 'wp_schedule_post':
					return $this->wp_schedule_post( $args );
				case 'wp_list_pages':
					return $this->wp_list_pages( $args );
				case 'wp_create_page':
					return $this->wp_create_page( $args );
				case 'wp_update_page':
					return $this->wp_update_page( $args );
				case 'wp_delete_page':
					return $this->wp_delete_page( $args );
				case 'wp_reorder_page':
					return $this->wp_reorder_page( $args );
				case 'wp_search_media':
					return $this->wp_search_media( $args );
				case 'wp_get_media':
					return $this->wp_get_media( $args );
				case 'wp_upload_media_from_url':
					return $this->wp_upload_media_from_url( $args );
				case 'wp_delete_media':
					return $this->wp_delete_media( $args );
				case 'wp_attach_media_to_post':
					return $this->wp_attach_media_to_post( $args );
				case 'wp_get_media_usage':
					return $this->wp_get_media_usage( $args );
				case 'wp_list_media_by_type':
					return $this->wp_list_media_by_type( $args );
				case 'wp_update_media_details':
					return $this->wp_update_media_details( $args );
				case 'wp_get_image_dimensions':
					return $this->wp_get_image_dimensions( $args );
				case 'wp_bulk_delete_unused_media':
					return $this->wp_bulk_delete_unused_media( $args );
				case 'wp_regenerate_thumbnails':
					return $this->wp_regenerate_thumbnails( $args );
				case 'wp_compress_image':
					return $this->wp_compress_image( $args );
				case 'wp_convert_image_format':
					return $this->wp_convert_image_format( $args );
				case 'wp_list_categories':
					return $this->wp_list_categories( $args );
				case 'wp_create_category':
					return $this->wp_create_category( $args );
				case 'wp_update_category':
					return $this->wp_update_category( $args );
				case 'wp_delete_category':
					return $this->wp_delete_category( $args );
				case 'wp_update_tag':
					return $this->wp_update_tag( $args );
				case 'wp_delete_tag':
					return $this->wp_delete_tag( $args );
				case 'wp_list_tags':
					return $this->wp_list_tags( $args );
				case 'wp_create_tag':
					return $this->wp_create_tag( $args );
				case 'wp_set_post_terms':
					return $this->wp_set_post_terms( $args );
				case 'wp_get_post_revisions':
					return $this->wp_get_post_revisions( $args );
				case 'wp_restore_post_revision':
					return $this->wp_restore_post_revision( $args );
				case 'wp_set_featured_image':
					return $this->wp_set_featured_image( $args );
				case 'wp_get_featured_image':
					return $this->wp_get_featured_image( $args );
				case 'wp_list_post_types':
					return $this->wp_list_post_types( $args );
				case 'wp_bulk_publish_drafts':
					return $this->wp_bulk_publish_drafts( $args );
				case 'wp_list_content_blocks':
					return $this->wp_list_content_blocks( $args );
				case 'wp_insert_content_block':
					return $this->wp_insert_content_block( $args );
				case 'wp_update_content_block':
					return $this->wp_update_content_block( $args );
				case 'wp_remove_content_block':
					return $this->wp_remove_content_block( $args );
				case 'wp_get_word_count':
					return $this->wp_get_word_count( $args );
				case 'wp_list_recently_modified':
					return $this->wp_list_recently_modified( $args );
				case 'wp_search_content':
					return $this->wp_search_content( $args );
				case 'wp_get_post_by_slug':
					return $this->wp_get_post_by_slug( $args );
				case 'wp_bulk_trash_old_drafts':
					return $this->wp_bulk_trash_old_drafts( $args );
				case 'wp_list_recent_changes':
					return $this->wp_list_recent_changes( $args );
				case 'wp_undo_change':
					return $this->wp_undo_change( $args );
				case 'wp_list_navigation_menus':
					return $this->wp_list_navigation_menus( $args );
				case 'wp_get_navigation_menu':
					return $this->wp_get_navigation_menu( $args );
				case 'wp_create_navigation_menu':
					return $this->wp_create_navigation_menu( $args );
				case 'wp_add_navigation_menu_item':
					return $this->wp_add_navigation_menu_item( $args );
				case 'wp_remove_navigation_menu_item':
					return $this->wp_remove_navigation_menu_item( $args );
				case 'wp_list_menus':
					return $this->wp_list_menus( $args );
				case 'wp_add_menu_item':
					return $this->wp_add_menu_item( $args );
				case 'wp_publish_and_add_to_menu':
					return $this->wp_publish_and_add_to_menu( $args );
				case 'wp_create_menu':
					return $this->wp_create_menu( $args );
				case 'wp_delete_menu_item':
					return $this->wp_delete_menu_item( $args );
				case 'wp_reorder_menu_item':
					return $this->wp_reorder_menu_item( $args );
				case 'wp_list_theme_locations':
					return $this->wp_list_theme_locations( $args );
				case 'wp_assign_menu_to_location':
					return $this->wp_assign_menu_to_location( $args );
				case 'wp_get_permalink_structure':
					return $this->wp_get_permalink_structure( $args );
				case 'wp_list_widgets':
					return $this->wp_list_widgets( $args );
				case 'wp_add_text_widget':
					return $this->wp_add_text_widget( $args );
				case 'wp_remove_widget':
					return $this->wp_remove_widget( $args );
				case 'wp_get_sitemap_status':
					return $this->wp_get_sitemap_status( $args );
				case 'wp_list_redirects':
					return $this->wp_list_redirects( $args );
				case 'wp_create_redirect':
					return $this->wp_create_redirect( $args );
				case 'wp_site_health_check':
					return $this->wp_site_health_check( $args );
				case 'wp_list_scheduled_tasks':
					return $this->wp_list_scheduled_tasks( $args );
				case 'wp_get_cron_health':
					return $this->wp_get_cron_health( $args );
				case 'wp_get_database_size':
					return $this->wp_get_database_size( $args );
				case 'wp_get_disk_usage':
					return $this->wp_get_disk_usage( $args );
				case 'wp_list_orphaned_data':
					return $this->wp_list_orphaned_data( $args );
				case 'wp_cleanup_database':
					return $this->wp_cleanup_database( $args );
				case 'wp_check_debug_log':
					return $this->wp_check_debug_log( $args );
				case 'wp_check_page_speed':
					return $this->wp_check_page_speed( $args );
				case 'wp_check_broken_links_sitewide':
					return $this->wp_check_broken_links_sitewide( $args );
				case 'wp_check_https_status':
					return $this->wp_check_https_status( $args );
				case 'wp_get_php_error_log':
					return $this->wp_get_php_error_log( $args );
				case 'wp_check_plugin_staleness':
					return $this->wp_check_plugin_staleness( $args );
				case 'wp_find_replace':
					return $this->wp_find_replace( $args );
				case 'wp_get_seo_meta':
					return $this->wp_get_seo_meta( $args );
				case 'wp_update_seo_meta':
					return $this->wp_update_seo_meta( $args );
				case 'wp_get_focus_keyword':
					return $this->wp_get_focus_keyword( $args );
				case 'wp_set_focus_keyword':
					return $this->wp_set_focus_keyword( $args );
				case 'wp_get_canonical_url':
					return $this->wp_get_canonical_url( $args );
				case 'wp_set_canonical_url':
					return $this->wp_set_canonical_url( $args );
				case 'wp_get_open_graph_meta':
					return $this->wp_get_open_graph_meta( $args );
				case 'wp_set_open_graph_meta':
					return $this->wp_set_open_graph_meta( $args );
				case 'wp_get_robots_txt':
					return $this->wp_get_robots_txt( $args );
				case 'wp_check_broken_links':
					return $this->wp_check_broken_links( $args );
				case 'wp_get_serp_preview':
					return $this->wp_get_serp_preview( $args );
				case 'wp_get_readability_score':
					return $this->wp_get_readability_score( $args );
				case 'wp_suggest_internal_links':
					return $this->wp_suggest_internal_links( $args );
				case 'wp_check_outdated_content':
					return $this->wp_check_outdated_content( $args );
				case 'wp_get_image_alt_text_report':
					return $this->wp_get_image_alt_text_report( $args );
				case 'wp_set_image_alt_text':
					return $this->wp_set_image_alt_text( $args );
				default:
					return $this->error_result( "Tool not implemented: {$name}" );
			}
		} catch ( Exception $e ) {
			return $this->error_result( $e->getMessage() );
		}
	}

	/* -----------------------------------------------------------------
	 * Tool group: Site info
	 * ------------------------------------------------------------- */

	private function wp_site_tools() {
		return array(
			array(
				'name'        => 'wp_get_site_info',
				'title'       => 'Get Site Info',
				'description' => 'Get basic info about this WordPress site: name, description, URL, WordPress version, active theme, and whether WooCommerce is active.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
		);
	}

	private function wp_get_site_info( $args ) {
		global $wp_version;

		$theme = wp_get_theme();

		$data = array(
			'name'              => get_bloginfo( 'name' ),
			'description'       => get_bloginfo( 'description' ),
			'url'               => home_url(),
			'wordpress_version' => $wp_version,
			'active_theme'      => $theme ? $theme->get( 'Name' ) : null,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
			'woocommerce_version' => class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) ? WC_VERSION : null,
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/* -----------------------------------------------------------------
	 * Tool group: WordPress posts
	 * ------------------------------------------------------------- */

	private function wp_post_tools() {
		return array(
			array(
				'name'        => 'wp_list_posts',
				'title'       => 'List Posts',
				'description' => 'List WordPress blog posts, optionally filtered by status or a search term.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'description' => 'publish, draft, pending, private, or any', 'default' => 'publish' ),
						'search'   => array( 'type' => 'string', 'description' => 'Optional search term to filter by title/content.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Max number of posts to return.', 'default' => 10 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_post',
				'title'       => 'Get Post',
				'description' => 'Get the full content and metadata of a single WordPress post by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_post',
				'title'       => 'Create Post',
				'description' => 'Create a new WordPress post.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'description' => 'draft or publish', 'default' => 'draft' ),
					),
					'required'   => array( 'title', 'content' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_update_post',
				'title'       => 'Update Post',
				'description' => 'Update the title, content, or status of an existing WordPress post.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_delete_post',
				'title'       => 'Delete Post',
				'description' => 'Move a post to the trash (not permanently deleted – recoverable from the WordPress trash).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_schedule_post',
				'title'       => 'Schedule Post',
				'description' => 'Schedules an existing post to publish automatically at a future date and time.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'publish_date' => array( 'type' => 'string', 'description' => 'When to publish, in the site\'s timezone, e.g. "2026-09-15 09:00:00".' ),
					),
					'required'   => array( 'id', 'publish_date' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_list_posts( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read posts.' );
		}

		$per_page = min( (int) ( $args['per_page'] ?? 10 ), $this->settings->get_max_results_limit() );

		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => sanitize_text_field( $args['status'] ?? 'publish' ),
			'posts_per_page' => max( 1, $per_page ),
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		$query = new WP_Query( $query_args );

		$posts = array_map(
			function ( $post ) {
				return array(
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'status' => $post->post_status,
					'date'   => $post->post_date,
					'link'   => get_permalink( $post ),
				);
			},
			$query->posts
		);

		return $this->text_result( wp_json_encode( $posts, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_post( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read posts.' );
		}

		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}

		$data = array(
			'id'      => $post->ID,
			'title'   => get_the_title( $post ),
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'date'    => $post->post_date,
			'link'    => get_permalink( $post ),
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_create_post( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'publish_posts' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to create posts.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $args['title'] ?? '' ),
				'post_content' => wp_kses_post( $args['content'] ?? '' ),
				'post_status'  => sanitize_text_field( $args['status'] ?? 'draft' ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->error_result( $post_id->get_error_message() );
		}

		return $this->text_result( "Created post #{$post_id}: " . get_permalink( $post_id ) );
	}

	private function wp_update_post( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id = (int) ( $args['id'] ?? 0 );

		$existing = get_post( $id );
		if ( ! $existing ) {
			return $this->error_result( "No post found with ID {$id}." );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}

		$this->snapshot_before_change( 'wp_update_post', 'post', $id, 'update', $existing, "Post #{$id}: \"" . get_the_title( $existing ) . '"' );

		$update = array( 'ID' => $id );

		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['status'] ) ) {
			$update['post_status'] = sanitize_text_field( $args['status'] );
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Updated post #{$id}. " . $this->undo_note() );
	}

	private function wp_delete_post( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id = (int) ( $args['id'] ?? 0 );

		$existing = get_post( $id );
		if ( ! $existing ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to delete this post.' );
		}

		$this->snapshot_before_change( 'wp_delete_post', 'post', $id, 'delete', $existing, "Post #{$id}: \"" . get_the_title( $existing ) . '"' );

		$result = wp_trash_post( $id );

		if ( ! $result ) {
			return $this->error_result( "Could not trash post #{$id}." );
		}

		return $this->text_result( "Moved post #{$id} to trash. " . $this->undo_note() );
	}

	private function wp_schedule_post( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id = (int) ( $args['id'] ?? 0 );

		$existing = get_post( $id );
		if ( ! $existing ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to schedule this post.' );
		}

		$publish_date = sanitize_text_field( $args['publish_date'] ?? '' );
		$timestamp    = strtotime( $publish_date );

		if ( ! $timestamp ) {
			return $this->error_result( 'publish_date could not be understood. Use a format like "2026-09-15 09:00:00".' );
		}
		if ( $timestamp <= current_time( 'timestamp' ) ) {
			return $this->error_result( 'publish_date must be in the future – use wp_update_post with status "publish" to publish immediately instead.' );
		}

		$this->snapshot_before_change( 'wp_schedule_post', 'post', $id, 'update', $existing, "Post #{$id}: \"" . get_the_title( $existing ) . '"' );

		$result = wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'post_date_gmt' => get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Post #{$id} scheduled to publish at {$publish_date}." );
	}

	/* -----------------------------------------------------------------
	 * Tool group: WordPress pages
	 * ------------------------------------------------------------- */

	private function wp_page_tools() {
		return array(
			array(
				'name'        => 'wp_list_pages',
				'title'       => 'List Pages',
				'description' => 'List WordPress pages.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array( 'type' => 'integer', 'default' => 10 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_page',
				'title'       => 'Create Page',
				'description' => 'Create a new WordPress page.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'description' => 'draft or publish', 'default' => 'draft' ),
					),
					'required'   => array( 'title', 'content' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_update_page',
				'title'       => 'Update Page',
				'description' => 'Update the title, content, or status of an existing WordPress page.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_delete_page',
				'title'       => 'Delete Page',
				'description' => 'Move a page to the trash (not permanently deleted).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_reorder_page',
				'title'       => 'Reorder Page',
				'description' => 'Sets a page\'s menu order – controls display order in page lists and default navigation menus.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'menu_order' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id', 'menu_order' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_list_pages( $args ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->error_result( 'Acting user is not permitted to read pages.' );
		}

		$per_page = min( (int) ( $args['per_page'] ?? 10 ), $this->settings->get_max_results_limit() );

		$pages = get_pages( array( 'number' => max( 1, $per_page ) ) );

		$data = array_map(
			function ( $page ) {
				return array(
					'id'    => $page->ID,
					'title' => get_the_title( $page ),
					'link'  => get_permalink( $page ),
				);
			},
			$pages
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_create_page( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'publish_pages' ) && ! current_user_can( 'edit_pages' ) ) {
			return $this->error_result( 'Acting user is not permitted to create pages.' );
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $args['title'] ?? '' ),
				'post_content' => wp_kses_post( $args['content'] ?? '' ),
				'post_status'  => sanitize_text_field( $args['status'] ?? 'draft' ),
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $this->error_result( $page_id->get_error_message() );
		}

		return $this->text_result( "Created page #{$page_id}: " . get_permalink( $page_id ) );
	}

	private function wp_update_page( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id = (int) ( $args['id'] ?? 0 );

		$existing = get_post( $id );
		if ( ! $existing ) {
			return $this->error_result( "No page found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_page', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this page.' );
		}

		$this->snapshot_before_change( 'wp_update_page', 'page', $id, 'update', $existing, "Page #{$id}: \"" . get_the_title( $existing ) . '"' );

		$update = array( 'ID' => $id );

		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['status'] ) ) {
			$update['post_status'] = sanitize_text_field( $args['status'] );
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Updated page #{$id}." );
	}

	private function wp_delete_page( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id       = (int) ( $args['id'] ?? 0 );
		$existing = get_post( $id );

		if ( ! $existing || 'page' !== $existing->post_type ) {
			return $this->error_result( "No page found with ID {$id}." );
		}
		if ( ! current_user_can( 'delete_page', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to delete this page.' );
		}

		$this->snapshot_before_change( 'wp_delete_page', 'page', $id, 'delete', $existing, "Page #{$id}: \"" . get_the_title( $existing ) . '"' );

		$result = wp_trash_post( $id );
		if ( ! $result ) {
			return $this->error_result( "Could not trash page #{$id}." );
		}

		return $this->text_result( "Moved page #{$id} to trash. " . $this->undo_note() );
	}

	private function wp_reorder_page( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id         = (int) ( $args['id'] ?? 0 );
		$menu_order = (int) ( $args['menu_order'] ?? 0 );
		$existing   = get_post( $id );

		if ( ! $existing || 'page' !== $existing->post_type ) {
			return $this->error_result( "No page found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_page', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this page.' );
		}

		wp_update_post( array( 'ID' => $id, 'menu_order' => $menu_order ) );

		return $this->text_result( "Set page #{$id}'s menu order to {$menu_order}." );
	}

	/* -----------------------------------------------------------------
	 * Tool group: WordPress media
	 * ------------------------------------------------------------- */

	private function wp_media_tools() {
		return array(
			array(
				'name'        => 'wp_search_media',
				'title'       => 'Search Media',
				'description' => 'Search the WordPress media library by filename or title, returning each item\'s URL.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search term to match against media titles/filenames.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Max number of items to return.', 'default' => 10 ),
					),
					'required'   => array( 'search' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_media',
				'title'       => 'Get Media',
				'description' => 'Read-only: gets full details of a single media library item – URL, dimensions (if an image), file size, MIME type, title, caption, and description.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_upload_media_from_url',
				'title'       => 'Upload Media From URL',
				'description' => 'Downloads an image or file from a URL and adds it to the media library.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'   => array( 'type' => 'string' ),
						'title' => array( 'type' => 'string' ),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_delete_media',
				'title'       => 'Delete Media',
				'description' => 'Permanently deletes a media library item and its file. Not recoverable via the trash.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_attach_media_to_post',
				'title'       => 'Attach Media to Post',
				'description' => 'Associates an existing media item with a post (sets its parent), e.g. after uploading an image separately from the post it belongs to.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'post_id'       => array( 'type' => 'integer' ),
					),
					'required'   => array( 'attachment_id', 'post_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_media_usage',
				'title'       => 'Get Media Usage',
				'description' => 'Read-only: finds which posts reference an image (in content, or as featured image), so it\'s safe to check before deleting.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_media_by_type',
				'title'       => 'List Media By Type',
				'description' => 'Read-only: lists media library items filtered by MIME type (e.g. "image", "video", "application/pdf").',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type'     => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					),
					'required'   => array( 'type' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_update_media_details',
				'title'       => 'Update Media Details',
				'description' => 'Updates a media item\'s title, caption, or description (not its alt text – use wp_set_image_alt_text for that).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_image_dimensions',
				'title'       => 'Get Image Dimensions',
				'description' => 'Read-only: gets an image\'s width/height and the sizes (thumbnail, medium, large, etc.) WordPress has generated for it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_bulk_delete_unused_media',
				'title'       => 'Bulk Delete Unused Media',
				'description' => 'Finds and deletes media items with no post parent (never attached to any post) older than a given number of days. Defaults to a dry run (confirm=false) – this only catches unattached items, not images referenced by URL inside post content without being formally "attached", so review the preview carefully.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'older_than_days' => array( 'type' => 'integer', 'default' => 90 ),
						'confirm'         => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_regenerate_thumbnails',
				'title'       => 'Regenerate Thumbnails',
				'description' => 'Regenerates all registered image sizes (thumbnail, medium, large, and any theme-added sizes) for an image, from its original full-size file. Useful after a theme change adds new image sizes, or if a size was generated incorrectly.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_image_alt_text_report',
				'title'       => 'Get Image Alt Text Report',
				'description' => 'Read-only: lists media library images missing alt text – an accessibility and image-SEO gap.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 20 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_set_image_alt_text',
				'title'       => 'Set Image Alt Text',
				'description' => 'Sets the alt text for a single media library image.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'alt_text'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'attachment_id', 'alt_text' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_compress_image',
				'title'       => 'Compress Image',
				'description' => 'Recompresses an image in place at a given quality level, using WordPress\'s own image editor (whichever of Imagick/GD the server has). Overwrites the original file and regenerates its thumbnails – not undoable, the same as other destructive media operations in this plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'quality'       => array( 'type' => 'integer', 'description' => '1-100. Lower is smaller/blurrier.', 'default' => 82 ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_convert_image_format',
				'title'       => 'Convert Image Format',
				'description' => 'Creates a new media library item by converting an existing image to jpg, png, or webp – the original attachment is left untouched, so this is non-destructive. WebP conversion requires the server\'s PHP image library to support it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'format'        => array( 'type' => 'string', 'description' => '"jpg", "png", or "webp".' ),
					),
					'required'   => array( 'attachment_id', 'format' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
		);
	}

	private function wp_search_media( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to read media.' );
		}

		$search   = sanitize_text_field( $args['search'] ?? '' );
		$per_page = min( (int) ( $args['per_page'] ?? 10 ), $this->settings->get_max_results_limit() );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $search,
				'posts_per_page' => max( 1, $per_page ),
			)
		);

		$items = array_map(
			function ( $post ) {
				return array(
					'id'        => $post->ID,
					'title'     => get_the_title( $post ),
					'url'       => wp_get_attachment_url( $post->ID ),
					'mime_type' => $post->post_mime_type,
					'date'      => $post->post_date,
				);
			},
			$query->posts
		);

		return $this->text_result( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_media( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to read media.' );
		}
		$id = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error_result( "No media item found with ID {$id}." );
		}
		$metadata = wp_get_attachment_metadata( $id );
		$data = array(
			'id'          => $id,
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'url'         => wp_get_attachment_url( $id ),
			'mime_type'   => $post->post_mime_type,
			'width'       => $metadata['width'] ?? null,
			'height'      => $metadata['height'] ?? null,
			'file_size'   => $metadata['filesize'] ?? null,
		);
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_upload_media_from_url( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to upload media.' );
		}
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( '' === $url ) {
			return $this->error_result( 'url is required.' );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, 0, $args['title'] ?? null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return $this->error_result( $attachment_id->get_error_message() );
		}
		return $this->text_result( "Uploaded media from URL as attachment #{$attachment_id}." );
	}

	private function wp_delete_media( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'delete_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to delete media.' );
		}
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) || 'attachment' !== get_post_type( $id ) ) {
			return $this->error_result( "No media item found with ID {$id}." );
		}
		$result = wp_delete_attachment( $id, true );
		if ( ! $result ) {
			return $this->error_result( "Could not delete media item #{$id}." );
		}
		return $this->text_result( "Permanently deleted media item #{$id}." );
	}

	private function wp_attach_media_to_post( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}
		$attachment_id = (int) ( $args['attachment_id'] ?? 0 );
		$post_id       = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return $this->error_result( "No media item found with ID {$attachment_id}." );
		}
		if ( ! get_post( $post_id ) ) {
			return $this->error_result( "No post found with ID {$post_id}." );
		}
		wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
		return $this->text_result( "Attached media #{$attachment_id} to post #{$post_id}." );
	}

	private function wp_get_media_usage( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$id  = (int) ( $args['attachment_id'] ?? 0 );
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return $this->error_result( "No media item found with ID {$id}." );
		}

// post_status must be explicit here – get_posts()/WP_Query default
		// to 'publish' only, which silently misses drafts, scheduled, and
		// private posts. Since this tool exists specifically so a caller
		// can check "is this image safe to delete?", missing a draft's
		// usage would be a false negative with real consequences.
		$as_featured = get_posts( array( 'meta_key' => '_thumbnail_id', 'meta_value' => $id, 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 20 ) ); // phpcs:ignore
		$filename    = basename( $url );
		$in_content  = get_posts( array( 's' => $filename, 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 20 ) );

		$data = array(
			'used_as_featured_image_in' => array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ) ); }, $as_featured ),
			'possibly_referenced_in'    => array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ) ); }, $in_content ),
		);
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_media_by_type( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to read media.' );
		}
		$type     = sanitize_text_field( $args['type'] ?? '' );
		$per_page = min( (int) ( $args['per_page'] ?? 20 ), $this->settings->get_max_results_limit() );
		$query    = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $type, 'posts_per_page' => max( 1, $per_page ) ) );
		$data     = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'url' => wp_get_attachment_url( $p->ID ), 'mime_type' => $p->post_mime_type ); }, $query->posts );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_update_media_details( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) || 'attachment' !== get_post_type( $id ) ) {
			return $this->error_result( "No media item found with ID {$id}." );
		}
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$update['post_excerpt'] = sanitize_text_field( $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$update['post_content'] = wp_kses_post( $args['description'] );
		}
		wp_update_post( $update );
		return $this->text_result( "Updated media item #{$id}." );
	}

	private function wp_get_image_dimensions( $args ) {
		$id = (int) ( $args['attachment_id'] ?? 0 );
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->error_result( "No image attachment found with ID {$id}." );
		}
		$metadata = wp_get_attachment_metadata( $id );
		$sizes    = array();
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			$sizes[ $name ] = array( 'width' => $size['width'], 'height' => $size['height'] );
		}
		$data = array( 'full_width' => $metadata['width'] ?? null, 'full_height' => $metadata['height'] ?? null, 'generated_sizes' => $sizes );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_bulk_delete_unused_media( $args ) {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to delete media.' );
		}
		$confirm = ! empty( $args['confirm'] );
		if ( $confirm && $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$days   = max( 1, (int) ( $args['older_than_days'] ?? 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$unused = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $cutoff ) ),
			)
		);

		if ( empty( $unused ) ) {
			return $this->text_result( "No unattached media older than {$days} days found." );
		}

		if ( ! $confirm ) {
			$preview = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ) ); }, $unused );
			return $this->text_result( wp_json_encode( array( 'preview_only' => true, 'items_that_would_be_deleted' => count( $unused ), 'items' => $preview, 'note' => 'This only catches items with no post parent – it cannot detect images referenced by URL inside post content without a formal attachment relationship. Review the list before confirming.' ), JSON_PRETTY_PRINT ) );
		}

		$deleted = array();
		foreach ( $unused as $item ) {
			wp_delete_attachment( $item->ID, true );
			$deleted[] = $item->ID;
		}
		return $this->text_result( wp_json_encode( array( 'preview_only' => false, 'deleted_count' => count( $deleted ), 'attachment_ids' => $deleted ), JSON_PRETTY_PRINT ) );
	}

	private function wp_compress_image( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}

		$id      = (int) ( $args['attachment_id'] ?? 0 );
		$quality = max( 1, min( 100, (int) ( $args['quality'] ?? 82 ) ) );

		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->error_result( "No image attachment found with ID {$id}." );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $this->error_result( 'Original file could not be found on disk.' );
		}

		$original_size = filesize( $file );

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $this->error_result( 'Could not load an image editor for this file: ' . $editor->get_error_message() );
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $file );

		if ( is_wp_error( $saved ) ) {
			return $this->error_result( 'Could not save the recompressed image: ' . $saved->get_error_message() );
		}

		clearstatcache( true, $file );
		$new_size = filesize( $file );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		$saved_percent = $original_size > 0 ? round( ( 1 - ( $new_size / $original_size ) ) * 100, 1 ) : 0;

		return $this->text_result( "Recompressed attachment #{$id} at quality {$quality}: {$original_size} bytes -> {$new_size} bytes ({$saved_percent}% smaller). Thumbnails were regenerated to match." );
	}

	private function wp_convert_image_format( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}

		$id     = (int) ( $args['attachment_id'] ?? 0 );
		$format = strtolower( sanitize_key( $args['format'] ?? '' ) );

		$mime_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);

		if ( ! isset( $mime_map[ $format ] ) ) {
			return $this->error_result( 'format must be one of: jpg, png, webp.' );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->error_result( "No image attachment found with ID {$id}." );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $this->error_result( 'Original file could not be found on disk.' );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $this->error_result( 'Could not load an image editor for this file: ' . $editor->get_error_message() );
		}

		$target_mime = $mime_map[ $format ];
		$target_ext  = 'jpeg' === $format ? 'jpg' : $format;
		$new_path    = preg_replace( '/\.[^.]+$/', '', $file ) . '-converted.' . $target_ext;

		$saved = $editor->save( $new_path, $target_mime );

		if ( is_wp_error( $saved ) ) {
			$hint = 'webp' === $format ? ' (this server\'s PHP image library may not support WebP.)' : '';
			return $this->error_result( "Could not convert to {$format}: " . $saved->get_error_message() . $hint );
		}

		$new_file       = $saved['path'];
		$filetype       = wp_check_filetype( $new_file );
		$original_title = get_the_title( $id );

		$new_attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => $original_title . ' (' . strtoupper( $format ) . ')',
				'post_status'    => 'inherit',
			),
			$new_file
		);

		if ( ! $new_attachment_id || is_wp_error( $new_attachment_id ) ) {
			return $this->error_result( 'Converted the file but could not add it to the media library.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $new_attachment_id, $new_file );
		wp_update_attachment_metadata( $new_attachment_id, $metadata );

		return $this->text_result( "Created a new {$format} copy of attachment #{$id} as attachment #{$new_attachment_id} (\"" . get_the_title( $new_attachment_id ) . '"). The original is untouched.' );
	}

	private function wp_regenerate_thumbnails( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}
		$id = (int) ( $args['attachment_id'] ?? 0 );
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->error_result( "No image attachment found with ID {$id}." );
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $this->error_result( 'Original file could not be found on disk.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( is_wp_error( $metadata ) || empty( $metadata ) ) {
			return $this->error_result( 'Could not regenerate thumbnails for this image.' );
		}
		wp_update_attachment_metadata( $id, $metadata );
		$sizes = array_keys( $metadata['sizes'] ?? array() );
		return $this->text_result( "Regenerated thumbnails for attachment #{$id}: " . implode( ', ', $sizes ) . '.' );
	}

	/* -----------------------------------------------------------------
	 * Tool group: WordPress taxonomy (categories) – Free
	 * ------------------------------------------------------------- */

	private function wp_taxonomy_tools() {
		return array(
			array(
				'name'        => 'wp_list_categories',
				'title'       => 'List Categories',
				'description' => 'List WordPress post categories, with post counts.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_category',
				'title'       => 'Create Category',
				'description' => 'Creates a new post category, optionally nested under a parent category.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer', 'description' => 'ID of the parent category, for a nested subcategory.' ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_update_category',
				'title'       => 'Update Category',
				'description' => 'Renames a category and/or changes its slug or parent.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'name'      => array( 'type' => 'string' ),
						'slug'      => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_delete_category',
				'title'       => 'Delete Category',
				'description' => 'Deletes a category. Posts assigned to it are not deleted – they fall back to the site\'s default category.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_update_tag',
				'title'       => 'Update Tag',
				'description' => 'Renames a tag and/or changes its slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
						'slug' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_delete_tag',
				'title'       => 'Delete Tag',
				'description' => 'Deletes a tag. Posts that had it are not deleted – the tag is simply removed from them.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_list_categories( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read categories.' );
		}

		$per_page   = min( (int) ( $args['per_page'] ?? 20 ), $this->settings->get_max_results_limit() );
		$categories = get_categories( array( 'number' => max( 1, $per_page ), 'hide_empty' => false ) );

		$data = array_map(
			function ( $cat ) {
				return array(
					'id'    => $cat->term_id,
					'name'  => $cat->name,
					'slug'  => $cat->slug,
					'count' => $cat->count,
				);
			},
			$categories
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_create_category( $args ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $this->error_result( 'Acting user is not permitted to manage categories.' );
		}

		$name      = sanitize_text_field( $args['name'] ?? '' );
		$parent_id = (int) ( $args['parent_id'] ?? 0 );

		if ( '' === $name ) {
			return $this->error_result( 'name is required.' );
		}

		$result = wp_insert_term( $name, 'category', array( 'parent' => $parent_id ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Created category \"{$name}\" (ID {$result['term_id']})." );
	}

	private function wp_update_category( $args ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $this->error_result( 'Acting user is not permitted to manage categories.' );
		}

		$id = (int) ( $args['id'] ?? 0 );
		if ( ! $id || ! term_exists( $id, 'category' ) ) {
			return $this->error_result( "No category found with ID {$id}." );
		}

		$update = array();
		if ( isset( $args['name'] ) ) {
			$update['name'] = sanitize_text_field( $args['name'] );
		}
		if ( isset( $args['slug'] ) ) {
			$update['slug'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['parent_id'] ) ) {
			$update['parent'] = (int) $args['parent_id'];
		}

		if ( empty( $update ) ) {
			return $this->error_result( 'Provide at least one of name, slug, or parent_id to change.' );
		}

		$term = get_term( $id, 'category' );
		$this->snapshot_before_term_change( 'wp_update_category', 'category', $id, 'category', 'update', 'Category #' . $id . ': "' . ( $term && ! is_wp_error( $term ) ? $term->name : '' ) . '"' );

		$result = wp_update_term( $id, 'category', $update );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Updated category #{$id}." );
	}

	private function wp_delete_category( $args ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $this->error_result( 'Acting user is not permitted to manage categories.' );
		}

		$id = (int) ( $args['id'] ?? 0 );
		if ( ! $id || ! term_exists( $id, 'category' ) ) {
			return $this->error_result( "No category found with ID {$id}." );
		}
		if ( (int) get_option( 'default_category' ) === $id ) {
			return $this->error_result( 'This is the site\'s default category – it can\'t be deleted while it\'s set as the default.' );
		}

		$term = get_term( $id, 'category' );
		$this->snapshot_before_term_change( 'wp_delete_category', 'category', $id, 'category', 'delete', 'Category #' . $id . ': "' . ( $term && ! is_wp_error( $term ) ? $term->name : '' ) . '"' );

		$deleted = wp_delete_term( $id, 'category' );

		if ( is_wp_error( $deleted ) || ! $deleted ) {
			return $this->error_result( "Could not delete category #{$id}." );
		}

		return $this->text_result( "Deleted category #{$id}. Posts that were in it now fall back to the site's default category. " . $this->undo_note( 'though posts will need to be re-tagged with the recreated category manually.' ) );
	}

	private function wp_update_tag( $args ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $this->error_result( 'Acting user is not permitted to manage tags.' );
		}

		$id = (int) ( $args['id'] ?? 0 );
		if ( ! $id || ! term_exists( $id, 'post_tag' ) ) {
			return $this->error_result( "No tag found with ID {$id}." );
		}

		$update = array();
		if ( isset( $args['name'] ) ) {
			$update['name'] = sanitize_text_field( $args['name'] );
		}
		if ( isset( $args['slug'] ) ) {
			$update['slug'] = sanitize_title( $args['slug'] );
		}

		if ( empty( $update ) ) {
			return $this->error_result( 'Provide at least one of name or slug to change.' );
		}

		$term = get_term( $id, 'post_tag' );
		$this->snapshot_before_term_change( 'wp_update_tag', 'tag', $id, 'post_tag', 'update', 'Tag #' . $id . ': "' . ( $term && ! is_wp_error( $term ) ? $term->name : '' ) . '"' );

		$result = wp_update_term( $id, 'post_tag', $update );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( "Updated tag #{$id}." );
	}

	private function wp_delete_tag( $args ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return $this->error_result( 'Acting user is not permitted to manage tags.' );
		}

		$id = (int) ( $args['id'] ?? 0 );
		if ( ! $id || ! term_exists( $id, 'post_tag' ) ) {
			return $this->error_result( "No tag found with ID {$id}." );
		}

		$term = get_term( $id, 'post_tag' );
		$this->snapshot_before_term_change( 'wp_delete_tag', 'tag', $id, 'post_tag', 'delete', 'Tag #' . $id . ': "' . ( $term && ! is_wp_error( $term ) ? $term->name : '' ) . '"' );

		$deleted = wp_delete_term( $id, 'post_tag' );

		if ( is_wp_error( $deleted ) || ! $deleted ) {
			return $this->error_result( "Could not delete tag #{$id}." );
		}

		return $this->text_result( "Deleted tag #{$id}. " . $this->undo_note( 'though posts will need to be re-tagged with the recreated tag manually.' ) );
	}

	/* -----------------------------------------------------------------
	 * Tool group: WordPress comments – list is Free, moderation is Pro
	 * ------------------------------------------------------------- */

	private function wp_navigation_tools() {
		return array(
			array(
				'name'        => 'wp_list_menus',
				'title'       => 'List Menus',
				'description' => 'Lists WordPress navigation menus and their items (label, URL, order).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_add_menu_item',
				'title'       => 'Add Menu Item',
				'description' => 'Adds a link to an existing post or page to a navigation menu – useful for adding newly published content to the site\'s navigation automatically.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'menu_id'    => array( 'type' => 'integer', 'description' => 'The menu ID from wp_list_menus.' ),
						'object_id'  => array( 'type' => 'integer', 'description' => 'The post or page ID to link to.' ),
						'title'      => array( 'type' => 'string', 'description' => 'Optional label; defaults to the post/page title.' ),
					),
					'required'   => array( 'menu_id', 'object_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_publish_and_add_to_menu',
				'title'       => 'Publish And Add To Menu',
				'description' => 'Publishes a draft/pending post or page and adds it to a navigation menu in one step – the combined action of publishing plus wp_add_menu_item, instead of two separate calls.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'object_id' => array( 'type' => 'integer', 'description' => 'The post or page ID to publish and link to.' ),
						'menu_id'   => array( 'type' => 'integer', 'description' => 'The menu ID from wp_list_menus.' ),
						'title'     => array( 'type' => 'string', 'description' => 'Optional menu-item label; defaults to the post/page title.' ),
					),
					'required'   => array( 'object_id', 'menu_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_menu',
				'title'       => 'Create Menu',
				'description' => 'Creates a new, empty navigation menu.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_delete_menu_item',
				'title'       => 'Delete Menu Item',
				'description' => 'Removes a single item from a navigation menu (the item ID from wp_list_menus, not the post/page itself).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'item_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'item_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_reorder_menu_item',
				'title'       => 'Reorder Menu Item',
				'description' => 'Changes a menu item\'s position within its menu.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'item_id' => array( 'type' => 'integer' ),
						'position' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'item_id', 'position' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_list_theme_locations',
				'title'       => 'List Theme Menu Locations',
				'description' => 'Read-only: lists the navigation menu slots the active theme defines (e.g. "primary", "footer"), and which menu (if any) is currently assigned to each.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_assign_menu_to_location',
				'title'       => 'Assign Menu to Location',
				'description' => 'Assigns a navigation menu to one of the theme\'s defined menu locations.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'menu_id'  => array( 'type' => 'integer' ),
						'location' => array( 'type' => 'string', 'description' => 'A location slug from wp_list_theme_locations.' ),
					),
					'required'   => array( 'menu_id', 'location' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_permalink_structure',
				'title'       => 'Get Permalink Structure',
				'description' => 'Read-only: gets the site\'s current permalink (URL) structure setting.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_widgets',
				'title'       => 'List Widgets',
				'description' => 'Read-only: lists widget areas (sidebars) and the widgets placed in each. Only reflects classic widget areas – block-theme site editor content isn\'t covered.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_add_text_widget',
				'title'       => 'Add Text/HTML Widget',
				'description' => 'Adds a Text/HTML widget to a classic widget area.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sidebar_id' => array( 'type' => 'string', 'description' => 'The widget area ID from wp_list_widgets.' ),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'sidebar_id', 'content' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_remove_widget',
				'title'       => 'Remove Widget',
				'description' => 'Removes a widget from its widget area, by the widget instance ID from wp_list_widgets.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'widget_id' => array( 'type' => 'string' ),
					),
					'required'   => array( 'widget_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_sitemap_status',
				'title'       => 'Get Sitemap Status',
				'description' => 'Read-only: checks whether WordPress\'s built-in XML sitemap is enabled and reachable, and whether the site is set to discourage search engines from indexing it.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_navigation_menus',
				'title'       => 'List Navigation Blocks',
				'description' => 'Read-only: lists WordPress\'s newer block-based navigation menus (the "wp_navigation" post type used by full-site-editing themes), with each one\'s item count. Separate from the classic menu system covered by wp_list_menus.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_navigation_menu',
				'title'       => 'Get Navigation Block',
				'description' => 'Read-only: gets the items in a block-based navigation menu, with each item\'s index (for use with wp_remove_navigation_menu_item), label, URL, and whether it links to an existing post/page or a custom URL.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_navigation_menu',
				'title'       => 'Create Navigation Block',
				'description' => 'Creates a new, empty block-based navigation menu.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string' ),
					),
					'required'   => array( 'title' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_add_navigation_menu_item',
				'title'       => 'Add Navigation Block Item',
				'description' => 'Adds a link to a block-based navigation menu – either to an existing post/page (object_id) or a custom URL (url + label).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer', 'description' => 'The navigation block\'s ID, from wp_list_navigation_menus.' ),
						'object_id' => array( 'type' => 'integer', 'description' => 'An existing post/page ID to link to.' ),
						'url'       => array( 'type' => 'string', 'description' => 'A custom URL to link to instead of object_id.' ),
						'label'     => array( 'type' => 'string', 'description' => 'Link text. Defaults to the post/page title when object_id is used; required for a custom url.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_remove_navigation_menu_item',
				'title'       => 'Remove Navigation Block Item',
				'description' => 'Removes one item from a block-based navigation menu, by its index from wp_get_navigation_menu.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'index' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id', 'index' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_list_navigation_menus( $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read menus.' );
		}

		$navs = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
			)
		);

		$data = array_map(
			function ( $nav ) {
				$blocks = array_filter(
					parse_blocks( $nav->post_content ),
					function ( $block ) {
						return ! empty( $block['blockName'] );
					}
				);
				return array(
					'id'         => $nav->ID,
					'title'      => $nav->post_title ?: '(untitled)',
					'item_count' => count( $blocks ),
				);
			},
			$navs
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_navigation_menu( $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read menus.' );
		}

		$id  = (int) ( $args['id'] ?? 0 );
		$nav = get_post( $id );

		if ( ! $nav || 'wp_navigation' !== $nav->post_type ) {
			return $this->error_result( "No navigation block found with ID {$id}." );
		}

		$blocks = parse_blocks( $nav->post_content );
		$items  = array();

		foreach ( $blocks as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$items[] = array(
				'index'     => $index,
				'block'     => $block['blockName'],
				'label'     => $block['attrs']['label'] ?? null,
				'url'       => $block['attrs']['url'] ?? null,
				'kind'      => $block['attrs']['kind'] ?? null,
				'object_id' => $block['attrs']['id'] ?? null,
			);
		}

		return $this->text_result( wp_json_encode( array( 'id' => $id, 'title' => $nav->post_title, 'items' => $items ), JSON_PRETTY_PRINT ) );
	}

	private function wp_create_navigation_menu( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}

		$title = sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return $this->error_result( 'title is required.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->error_result( $post_id->get_error_message() );
		}

		return $this->text_result( "Created navigation block \"{$title}\" (ID {$post_id})." );
	}

	private function wp_add_navigation_menu_item( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}

		$id  = (int) ( $args['id'] ?? 0 );
		$nav = get_post( $id );

		if ( ! $nav || 'wp_navigation' !== $nav->post_type ) {
			return $this->error_result( "No navigation block found with ID {$id}." );
		}

		$object_id = (int) ( $args['object_id'] ?? 0 );
		$label     = sanitize_text_field( $args['label'] ?? '' );
		$url       = esc_url_raw( $args['url'] ?? '' );

		if ( $object_id ) {
			$target = get_post( $object_id );
			if ( ! $target ) {
				return $this->error_result( "No post or page found with ID {$object_id}." );
			}
			$attrs = array(
				'label' => $label ?: get_the_title( $target ),
				'type'  => $target->post_type,
				'kind'  => 'post-type',
				'id'    => $object_id,
				'url'   => get_permalink( $target ),
			);
		} elseif ( '' !== $url ) {
			if ( '' === $label ) {
				return $this->error_result( 'label is required when linking to a custom url.' );
			}
			$attrs = array(
				'label' => $label,
				'kind'  => 'custom',
				'url'   => $url,
			);
		} else {
			return $this->error_result( 'Provide either object_id (an existing post/page) or url plus label.' );
		}

		$blocks   = parse_blocks( $nav->post_content );
		$blocks[] = array(
			'blockName'    => 'core/navigation-link',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$updated = wp_update_post( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ), true );

		if ( is_wp_error( $updated ) ) {
			return $this->error_result( $updated->get_error_message() );
		}

		return $this->text_result( "Added \"{$attrs['label']}\" to navigation block #{$id}." );
	}

	private function wp_remove_navigation_menu_item( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}

		$id    = (int) ( $args['id'] ?? 0 );
		$index = (int) ( $args['index'] ?? -1 );
		$nav   = get_post( $id );

		if ( ! $nav || 'wp_navigation' !== $nav->post_type ) {
			return $this->error_result( "No navigation block found with ID {$id}." );
		}

		$blocks = parse_blocks( $nav->post_content );

		if ( ! isset( $blocks[ $index ] ) ) {
			return $this->error_result( "No item at index {$index}. Use wp_get_navigation_menu to see valid indexes." );
		}

		$removed_label = $blocks[ $index ]['attrs']['label'] ?? '(untitled)';
		array_splice( $blocks, $index, 1 );

		$updated = wp_update_post( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ), true );

		if ( is_wp_error( $updated ) ) {
			return $this->error_result( $updated->get_error_message() );
		}

		return $this->text_result( "Removed \"{$removed_label}\" from navigation block #{$id}." );
	}

	private function wp_list_menus( $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read menus.' );
		}

		$menus = wp_get_nav_menus();

		$data = array_map(
			function ( $menu ) {
				$items = wp_get_nav_menu_items( $menu->term_id );
				return array(
					'id'    => $menu->term_id,
					'name'  => $menu->name,
					'items' => array_map(
						function ( $item ) {
							return array(
								'id'    => (int) $item->ID,
								'label' => $item->title,
								'url'   => $item->url,
								'order' => (int) $item->menu_order,
							);
						},
						$items ?: array()
					),
				);
			},
			$menus
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_add_menu_item( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}

		$menu_id   = (int) ( $args['menu_id'] ?? 0 );
		$object_id = (int) ( $args['object_id'] ?? 0 );

		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return $this->error_result( "No menu found with ID {$menu_id}." );
		}

		$post = get_post( $object_id );
		if ( ! $post ) {
			return $this->error_result( "No post or page found with ID {$object_id}." );
		}

		$title = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : get_the_title( $post );

		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => $title,
				'menu-item-object-id' => $object_id,
				'menu-item-object'    => $post->post_type,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);

		if ( is_wp_error( $item_id ) ) {
			return $this->error_result( $item_id->get_error_message() );
		}

		return $this->text_result( "Added \"{$title}\" to menu #{$menu_id} (new item ID {$item_id})." );
	}

	private function wp_publish_and_add_to_menu( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$object_id = (int) ( $args['object_id'] ?? 0 );
		$post      = get_post( $object_id );

		if ( ! $post ) {
			return $this->error_result( "No post or page found with ID {$object_id}." );
		}
		if ( ! current_user_can( 'publish_posts' ) && ! current_user_can( 'publish_pages' ) ) {
			return $this->error_result( 'Acting user is not permitted to publish content.' );
		}

		if ( 'publish' !== $post->post_status ) {
			$updated = wp_update_post( array( 'ID' => $object_id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $updated ) ) {
				return $this->error_result( 'Could not publish: ' . $updated->get_error_message() );
			}
		}

		$menu_result = $this->wp_add_menu_item(
			array(
				'menu_id'   => $args['menu_id'] ?? 0,
				'object_id' => $object_id,
				'title'     => $args['title'] ?? '',
			)
		);

		$menu_message = $menu_result['content'][0]['text'] ?? '';

		if ( ! empty( $menu_result['isError'] ) ) {
			return $this->error_result( 'Published "' . get_the_title( $object_id ) . '", but could not add it to the menu: ' . $menu_message );
		}

		return $this->text_result( 'Published "' . get_the_title( $object_id ) . '" and ' . lcfirst( $menu_message ) );
	}

	private function wp_create_menu( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to create menus.' );
		}
		$name = sanitize_text_field( $args['name'] ?? '' );
		if ( '' === $name ) {
			return $this->error_result( 'name is required.' );
		}
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return $this->error_result( $menu_id->get_error_message() );
		}
		return $this->text_result( "Created menu \"{$name}\" (#{$menu_id})." );
	}

	private function wp_delete_menu_item( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}
		$item_id = (int) ( $args['item_id'] ?? 0 );
		if ( ! get_post( $item_id ) ) {
			return $this->error_result( "No menu item found with ID {$item_id}." );
		}
		$result = wp_delete_post( $item_id, true );
		if ( ! $result ) {
			return $this->error_result( "Could not delete menu item #{$item_id}." );
		}
		return $this->text_result( "Deleted menu item #{$item_id}." );
	}

	private function wp_reorder_menu_item( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}
		$item_id  = (int) ( $args['item_id'] ?? 0 );
		$position = (int) ( $args['position'] ?? 0 );
		if ( ! get_post( $item_id ) ) {
			return $this->error_result( "No menu item found with ID {$item_id}." );
		}
		wp_update_post( array( 'ID' => $item_id, 'menu_order' => $position ) );
		return $this->text_result( "Set menu item #{$item_id}'s position to {$position}." );
	}

	private function wp_list_theme_locations( $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$data       = array();
		foreach ( $registered as $slug => $label ) {
			$menu_id = $assigned[ $slug ] ?? 0;
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
			$data[]  = array( 'location' => $slug, 'label' => $label, 'assigned_menu' => $menu ? $menu->name : null );
		}
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_assign_menu_to_location( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit menus.' );
		}
		$menu_id  = (int) ( $args['menu_id'] ?? 0 );
		$location = sanitize_key( $args['location'] ?? '' );
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return $this->error_result( "No menu found with ID {$menu_id}." );
		}
		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $location ] ) ) {
			return $this->error_result( "\"{$location}\" is not a registered menu location on this theme." );
		}
		$locations             = get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return $this->text_result( "Assigned menu #{$menu_id} to location \"{$location}\"." );
	}

	private function wp_get_permalink_structure( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		return $this->text_result( wp_json_encode( array( 'structure' => get_option( 'permalink_structure' ) ?: '(plain – ?p=123 style)' ), JSON_PRETTY_PRINT ) );
	}

	private function wp_list_widgets( $args ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		global $wp_registered_sidebars, $wp_registered_widgets;
		// Direct option read (what wp_get_sidebars_widgets() itself does in
		// admin/REST contexts) instead of that wrapper, which core marks
		// discouraged for plugin use in favor of accessing the option
		// directly.
		$sidebars_widgets = apply_filters( 'sidebars_widgets', get_option( 'sidebars_widgets', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-applying WordPress core's own existing "sidebars_widgets" filter (exactly what wp_get_sidebars_widgets() does internally), not defining a new hook.
		$data             = array();
		foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
			$widgets = array();
			foreach ( (array) ( $sidebars_widgets[ $sidebar_id ] ?? array() ) as $widget_id ) {
				$widgets[] = array( 'id' => $widget_id, 'name' => $wp_registered_widgets[ $widget_id ]['name'] ?? $widget_id );
			}
			$data[] = array( 'sidebar_id' => $sidebar_id, 'sidebar_name' => $sidebar['name'], 'widgets' => $widgets );
		}
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_add_text_widget( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit widgets.' );
		}
		$sidebar_id = sanitize_text_field( $args['sidebar_id'] ?? '' );
		$content    = wp_kses_post( $args['content'] ?? '' );
		$title      = sanitize_text_field( $args['title'] ?? '' );

		global $wp_registered_sidebars;
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return $this->error_result( "No widget area found with ID \"{$sidebar_id}\". Use wp_list_widgets to see valid IDs." );
		}

		$text_widgets   = get_option( 'widget_text', array() );
		$next_index     = empty( $text_widgets ) ? 2 : max( array_filter( array_keys( $text_widgets ), 'is_int' ) ) + 1;
		$text_widgets[ $next_index ] = array( 'title' => $title, 'text' => $content, 'filter' => true, 'visual' => true );
		update_option( 'widget_text', $text_widgets );

		// Direct option read (what wp_get_sidebars_widgets() itself does in
		// admin/REST contexts) instead of that wrapper, which core marks
		// discouraged for plugin use in favor of accessing the option
		// directly.
		$sidebars_widgets = apply_filters( 'sidebars_widgets', get_option( 'sidebars_widgets', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-applying WordPress core's own existing "sidebars_widgets" filter (exactly what wp_get_sidebars_widgets() does internally), not defining a new hook.
		$sidebars_widgets[ $sidebar_id ][] = "text-{$next_index}";
		wp_set_sidebars_widgets( $sidebars_widgets );

		return $this->text_result( "Added Text widget \"text-{$next_index}\" to \"{$sidebar_id}\"." );
	}

	private function wp_remove_widget( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit widgets.' );
		}
		$widget_id = sanitize_text_field( $args['widget_id'] ?? '' );

		// Direct option read (what wp_get_sidebars_widgets() itself does in
		// admin/REST contexts) instead of that wrapper, which core marks
		// discouraged for plugin use in favor of accessing the option
		// directly.
		$sidebars_widgets = apply_filters( 'sidebars_widgets', get_option( 'sidebars_widgets', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-applying WordPress core's own existing "sidebars_widgets" filter (exactly what wp_get_sidebars_widgets() does internally), not defining a new hook.
		$found = false;
		foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
			if ( is_array( $widgets ) && false !== ( $key = array_search( $widget_id, $widgets, true ) ) ) {
				unset( $sidebars_widgets[ $sidebar_id ][ $key ] );
				$found = true;
			}
		}
		if ( ! $found ) {
			return $this->error_result( "Widget \"{$widget_id}\" not found in any widget area." );
		}
		wp_set_sidebars_widgets( $sidebars_widgets );
		return $this->text_result( "Removed widget \"{$widget_id}\"." );
	}

	private function wp_get_sitemap_status( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$data = array(
			'core_sitemaps_enabled' => class_exists( 'WP_Sitemaps' ) && apply_filters( 'wp_sitemaps_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading WordPress core's own sitemap-enabled filter, not a hook this plugin defines.
			'search_engines_discouraged' => '0' === get_option( 'blog_public' ),
			'sitemap_url'           => home_url( '/wp-sitemap.xml' ),
		);
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_redirects( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$redirects = WCOPS_Redirects::get_all();
		$data = array_map( function ( $r ) { return array( 'id' => $r->id, 'source' => $r->source_path, 'destination' => $r->destination_url, 'type' => $r->redirect_type, 'hits' => $r->hit_count ); }, $redirects );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_create_redirect( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to create redirects.' );
		}
		$source      = sanitize_text_field( $args['source_path'] ?? '' );
		$destination = esc_url_raw( $args['destination_url'] ?? '' );
		$type_input  = (int) ( $args['type'] ?? 301 );
		$type        = in_array( $type_input, array( 301, 302 ), true ) ? $type_input : 301;

		if ( '' === $source || '' === $destination ) {
			return $this->error_result( 'source_path and destination_url are both required.' );
		}
		if ( '/' !== substr( $source, 0, 1 ) ) {
			$source = '/' . $source;
		}

		$id = WCOPS_Redirects::create( $source, $destination, $type );
		if ( ! $id ) {
			return $this->error_result( "Could not create the redirect – a redirect from \"{$source}\" may already exist." );
		}
		return $this->text_result( "Created {$type} redirect from \"{$source}\" to \"{$destination}\" (#{$id})." );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Site health checks – read-only, always safe
	 * ------------------------------------------------------------- */

	private function wp_site_health_tools() {
		return array(
			array(
				'name'        => 'wp_site_health_check',
				'title'       => 'Site Health Check',
				'description' => 'Read-only diagnostic snapshot: WordPress and PHP versions, active theme, active plugin count, whether debug mode is on, and whether WP-Cron looks like it\'s running. Cannot change anything.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_scheduled_tasks',
				'title'       => 'List Scheduled Tasks',
				'description' => 'Lists WP-Cron scheduled events – what\'s registered to run and when. Useful for spotting a task that\'s stopped firing.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_cron_health',
				'title'       => 'Get Cron Health',
				'description' => 'Checks whether WP-Cron appears to actually be running (comparing the next scheduled event\'s time against now) rather than just whether it\'s enabled.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_database_size',
				'title'       => 'Get Database Size',
				'description' => 'Reports the total size of the WordPress database, broken down by the largest tables.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_disk_usage',
				'title'       => 'Get Disk Usage',
				'description' => 'Reports the total size of the uploads folder.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_orphaned_data',
				'title'       => 'List Orphaned Data',
				'description' => 'Finds database clutter: post meta and comment meta rows left behind after their parent post/comment was deleted. Read-only – reports counts, doesn\'t delete anything.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_cleanup_database',
				'title'       => 'Clean Up Database',
				'description' => 'Removes post revisions, trashed posts, spam/trashed comments, and expired transients. Defaults to a dry run (confirm=false) showing exactly what would be removed and how many rows. Only applies when called again with confirm=true.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'confirm' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_check_debug_log',
				'title'       => 'Check Debug Log',
				'description' => 'If WP_DEBUG_LOG is enabled, reports the debug.log file\'s size and last-modified time, and the last few lines – a quick way to check for recent errors without needing file/server access.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_page_speed',
				'title'       => 'Check Page Speed',
				'description' => 'Measures time-to-first-byte for the homepage via a server-side request. This is a basic server response timing check, not a full page-speed audit (that would need a real browser rendering engine and a third-party API this plugin doesn\'t have credentials for) – treat it as one signal, not a complete Lighthouse-style score.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_broken_links_sitewide',
				'title'       => 'Check Broken Links Site-Wide',
				'description' => 'Checks links across the most recently published posts (capped, to keep this reasonably fast) rather than one post at a time. For a single post, wp_check_broken_links is faster and more thorough.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_limit' => array( 'type' => 'integer', 'description' => 'How many recent posts to check.', 'default' => 5 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_https_status',
				'title'       => 'Check HTTPS Status',
				'description' => 'Read-only: checks whether the site is being served over HTTPS and whether the site URL settings force it.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_php_error_log',
				'title'       => 'Get PHP Error Log',
				'description' => 'Reads the last lines of PHP\'s own error log (separate from WordPress\'s debug.log), if PHP is configured to log to a file this plugin can read.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_plugin_staleness',
				'title'       => 'Check Plugin Staleness',
				'description' => 'Checks each active plugin against the WordPress.org plugin directory for how long it\'s been since its last update – flags ones that look abandoned. Plugins not listed on WordPress.org (premium/custom plugins) are skipped, not flagged.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
		);
	}

	private function wp_site_health_check( $args ) {
		global $wp_version;

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read site health.' );
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$theme          = wp_get_theme();

		$data = array(
			'wordpress_version'   => $wp_version,
			'php_version'         => PHP_VERSION,
			'active_theme'        => $theme ? $theme->get( 'Name' ) : null,
			'active_plugin_count' => count( $active_plugins ),
			'debug_mode_on'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'site_url'            => site_url(),
			'is_multisite'        => is_multisite(),
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_list_scheduled_tasks( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read scheduled tasks.' );
		}

		$crons = _get_cron_array();
		$data  = array();

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					$data[] = array(
						'hook'      => $hook,
						'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'schedule'  => $event['schedule'] ?: 'single event',
					);
				}
			}
			if ( count( $data ) >= 100 ) {
				break;
			}
		}

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_cron_health( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read cron health.' );
		}

		$crons = _get_cron_array();

		if ( empty( $crons ) ) {
			return $this->text_result( wp_json_encode( array( 'status' => 'no_events_scheduled' ), JSON_PRETTY_PRINT ) );
		}

		$next_timestamp   = min( array_keys( $crons ) );
		$overdue_seconds  = time() - $next_timestamp;
		// A cron event a few minutes overdue is completely normal (it only
		// runs on a page visit); more than an hour overdue on a site that
		// gets regular traffic suggests WP-Cron may not be firing.
		$looks_healthy    = $overdue_seconds < HOUR_IN_SECONDS;

		$data = array(
			'next_event_was_due_at' => gmdate( 'Y-m-d H:i:s', $next_timestamp ),
			'overdue_by_seconds'    => max( 0, $overdue_seconds ),
			'looks_healthy'         => $looks_healthy,
			'wp_cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'note'                  => $looks_healthy ? 'Cron looks like it\'s firing normally.' : 'The next scheduled event is significantly overdue – if this persists, WP-Cron may not be running (common on low-traffic sites; consider a real system cron job instead).',
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_database_size( $args ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read database size.' );
		}

		// information_schema always reports the column back as TABLE_NAME
		// (uppercase) regardless of how it's written in the SELECT list, so
		// an explicit lowercase alias is required to get a predictable
		// property name back from $wpdb->get_results().
		$tables = $wpdb->get_results( $wpdb->prepare( "SELECT table_name AS tbl_name, ROUND( ( data_length + index_length ) / 1024 / 1024, 2 ) AS size_mb FROM information_schema.TABLES WHERE table_schema = %s ORDER BY size_mb DESC LIMIT 10", DB_NAME ) ); // phpcs:ignore

		$total_mb = $wpdb->get_var( $wpdb->prepare( "SELECT ROUND( SUM( data_length + index_length ) / 1024 / 1024, 2 ) FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME ) ); // phpcs:ignore

		$data = array(
			'total_size_mb'  => (float) $total_mb,
			'largest_tables' => array_map(
				function ( $t ) {
					return array( 'table' => $t->tbl_name, 'size_mb' => (float) $t->size_mb );
				},
				$tables
			),
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_disk_usage( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read disk usage.' );
		}

		$upload_dir = wp_upload_dir();
		$path       = $upload_dir['basedir'];

		$size = 0;
		if ( is_dir( $path ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		}

		return $this->text_result( wp_json_encode( array( 'uploads_folder_size_mb' => round( $size / 1024 / 1024, 2 ) ), JSON_PRETTY_PRINT ) );
	}

	private function wp_list_orphaned_data( $args ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}

		$orphaned_postmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" ); // phpcs:ignore
		$orphaned_commentmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" ); // phpcs:ignore

		$data = array(
			'orphaned_postmeta_rows'    => $orphaned_postmeta,
			'orphaned_commentmeta_rows' => $orphaned_commentmeta,
			'note'                      => 'These are metadata rows left behind after their parent post/comment was deleted. This tool only reports them – nothing is deleted.',
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_cleanup_database( $args ) {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to clean up the database.' );
		}

		$confirm = ! empty( $args['confirm'] );

		if ( $confirm && $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$revision_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore
		$trash_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ); // phpcs:ignore
		$spam_comment_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore
		$trash_comment_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore
		$expired_transients  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_%' ) ); // phpcs:ignore

		if ( ! $confirm ) {
			return $this->text_result(
				wp_json_encode(
					array(
						'preview_only'          => true,
						'post_revisions'        => $revision_count,
						'trashed_posts'         => $trash_count,
						'spam_comments'         => $spam_comment_count,
						'trashed_comments'      => $trash_comment_count,
						'transient_option_rows' => $expired_transients,
						'note'                  => 'No changes have been made. Call wp_cleanup_database again with confirm:true to actually delete these.',
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')" ); // phpcs:ignore
		delete_expired_transients();

		return $this->text_result(
			wp_json_encode(
				array(
					'preview_only'     => false,
					'revisions_removed' => $revision_count,
					'trashed_posts_removed' => $trash_count,
					'spam_trash_comments_removed' => $spam_comment_count + $trash_comment_count,
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	private function wp_check_debug_log( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read the debug log.' );
		}

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return $this->text_result( 'WP_DEBUG_LOG is not enabled on this site.' );
		}

		$log_path = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_file( $log_path ) ) {
			return $this->text_result( 'WP_DEBUG_LOG is enabled but no debug.log file exists yet – no errors logged so far.' );
		}

		$size = filesize( $log_path );
		// Reading only the tail of a potentially large file – cap what's
		// pulled into memory, since debug.log can grow to many megabytes.
		$max_read = min( $size, 20000 );
		// WP_Filesystem's get_contents() has no seek/partial-read equivalent
		// - it always reads the entire file, which would defeat the point
		// of only pulling the tail of a log that can grow to many
		// megabytes. Raw filesystem calls are the only way to seek to an
		// offset and read a bounded number of bytes from there.
		$handle = fopen( $log_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $handle, -$max_read, SEEK_END );
		$tail = fread( $handle, $max_read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$lines = array_slice( array_filter( explode( "\n", $tail ) ), -20 );

		$data = array(
			'file_size_bytes' => $size,
			'last_modified'   => gmdate( 'Y-m-d H:i:s', filemtime( $log_path ) ),
			'last_lines'      => array_values( $lines ),
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_check_page_speed( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to run this check.' );
		}
		$start    = microtime( true );
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15 ) );
		$elapsed  = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->error_result( 'Could not fetch the homepage: ' . $response->get_error_message() );
		}

		$data = array(
			'time_to_first_byte_ms' => $elapsed,
			'http_status'           => wp_remote_retrieve_response_code( $response ),
			'note'                  => 'This measures server response time only – not a full page-speed audit (asset loading, render time, etc. aren\'t measured here).',
		);
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_check_broken_links_sitewide( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$post_limit = min( (int) ( $args['post_limit'] ?? 5 ), 10 );
		$posts      = get_posts( array( 'post_type' => array( 'post', 'page' ), 'posts_per_page' => $post_limit, 'orderby' => 'date', 'order' => 'DESC' ) );

		$results     = array();
		$total_links = 0;
		foreach ( $posts as $post ) {
			preg_match_all( '/href=["\']([^"\']+)["\']/', $post->post_content, $matches );
			$links = array_unique( array_slice( $matches[1], 0, 10 ) ); // Cap per post to keep the whole call reasonably fast.
			foreach ( $links as $link ) {
				if ( 0 !== strpos( $link, 'http' ) || $total_links >= 30 ) {
					continue; // Global cap across the whole batch.
				}
				$total_links++;
				$response = wp_remote_head( $link, array( 'timeout' => 5, 'redirection' => 3 ) );
				$status   = is_wp_error( $response ) ? 'unreachable' : wp_remote_retrieve_response_code( $response );
				if ( 'unreachable' === $status || $status >= 400 ) {
					$results[] = array( 'post_id' => $post->ID, 'post_title' => get_the_title( $post ), 'url' => $link, 'status' => $status );
				}
			}
		}

		return $this->text_result( wp_json_encode( array( 'posts_checked' => count( $posts ), 'links_checked' => $total_links, 'broken_links' => $results ), JSON_PRETTY_PRINT ) );
	}

	private function wp_check_https_status( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$data = array(
			'site_url_uses_https' => 0 === strpos( site_url(), 'https://' ),
			'currently_served_over_https' => is_ssl(),
		);
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_php_error_log( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$log_path = ini_get( 'error_log' );
		if ( ! $log_path || ! is_file( $log_path ) || ! is_readable( $log_path ) ) {
			return $this->text_result( 'No readable PHP error log path is configured on this server (this is normal on many hosts – errors may go to a host-level log this plugin can\'t access).' );
		}
		$size     = filesize( $log_path );
		$max_read = min( $size, 20000 );
		// WP_Filesystem's get_contents() has no seek/partial-read equivalent
		// - it always reads the entire file, which would defeat the point
		// of only pulling the tail of a log that can grow to many
		// megabytes. Raw filesystem calls are the only way to seek to an
		// offset and read a bounded number of bytes from there.
		$handle = fopen( $log_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $handle, -$max_read, SEEK_END );
		$tail = fread( $handle, $max_read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = array_slice( array_filter( explode( "\n", $tail ) ), -20 );
		return $this->text_result( wp_json_encode( array( 'file_size_bytes' => $size, 'last_lines' => array_values( $lines ) ), JSON_PRETTY_PRINT ) );
	}

	private function wp_check_plugin_staleness( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( 'Acting user is not permitted to check this.' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$results = array();

		foreach ( $plugins as $path => $info ) {
			if ( ! in_array( $path, $active, true ) ) {
				continue;
			}
			$slug     = strtok( $path, '/' );
			$response = wp_remote_get( "https://api.wordpress.org/plugins/info/1.0/{$slug}.json", array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$plugin_info = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $plugin_info['last_updated'] ) ) {
				continue; // Not on WordPress.org (premium/custom) – skip rather than flag.
			}
			$days_since_update = (int) ( ( time() - strtotime( $plugin_info['last_updated'] ) ) / DAY_IN_SECONDS );
			if ( $days_since_update > 365 ) {
				$results[] = array( 'plugin' => $info['Name'], 'days_since_last_update' => $days_since_update );
			}
		}

		return $this->text_result( wp_json_encode( array( 'stale_plugins' => $results, 'note' => 'Plugins over a year without an update on WordPress.org. Premium/custom plugins not listed there are skipped, not flagged.' ), JSON_PRETTY_PRINT ) );
	}

	/* -----------------------------------------------------------------
	 * Tool group: SEO
	 *
	 * Works generically with whichever SEO plugin is actually active –
	 * detects Yoast or Rank Math's meta keys automatically, falling back
	 * to the plugin's own keys if neither is installed, so the tool
	 * doesn't need separate versions per SEO plugin.
	 * ------------------------------------------------------------- */

	private function seo_tools() {
		return array(
			array(
				'name'        => 'wp_get_seo_meta',
				'title'       => 'Get SEO Meta',
				'description' => 'Gets the SEO title and meta description for a post or page. Automatically reads from Yoast SEO or Rank Math if either is active, otherwise falls back to this plugin\'s own fields.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_update_seo_meta',
				'title'       => 'Update SEO Meta',
				'description' => 'Sets the SEO title and/or meta description for a post or page. Automatically writes to Yoast SEO or Rank Math if either is active, otherwise falls back to this plugin\'s own fields.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'seo_title'       => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_focus_keyword',
				'title'       => 'Get Focus Keyword',
				'description' => 'Read-only: gets a post\'s SEO focus keyword/keyphrase.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_set_focus_keyword',
				'title'       => 'Set Focus Keyword',
				'description' => 'Sets a post\'s SEO focus keyword/keyphrase.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'keyword' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'keyword' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_canonical_url',
				'title'       => 'Get Canonical URL',
				'description' => 'Read-only: gets a post\'s canonical URL override, if one is set.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_set_canonical_url',
				'title'       => 'Set Canonical URL',
				'description' => 'Sets a canonical URL override for a post – tells search engines this URL is the "real" one, useful when the same content is reachable at multiple URLs.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_open_graph_meta',
				'title'       => 'Get Open Graph Meta',
				'description' => 'Read-only: gets a post\'s social sharing (Open Graph) title, description, and image overrides – what shows up when the URL is shared on Facebook, LinkedIn, etc.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_set_open_graph_meta',
				'title'       => 'Set Open Graph Meta',
				'description' => 'Sets a post\'s social sharing (Open Graph) title, description, and/or image override.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array( 'type' => 'integer' ),
						'og_title'      => array( 'type' => 'string' ),
						'og_description' => array( 'type' => 'string' ),
						'og_image_id'   => array( 'type' => 'integer', 'description' => 'Media library attachment ID.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_robots_txt',
				'title'       => 'Get robots.txt',
				'description' => 'Read-only: fetches the site\'s current live robots.txt content.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_broken_links',
				'title'       => 'Check Broken Links',
				'description' => 'Checks every link within a single post\'s content and reports which ones return an error – capped to a reasonable number of links per call to keep response times sane.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_redirects',
				'title'       => 'List Redirects',
				'description' => 'Read-only: lists URL redirects configured through this plugin (a lightweight built-in redirect manager – WordPress core has none). Doesn\'t see redirects set up through a separate redirect plugin.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_redirect',
				'title'       => 'Create Redirect',
				'description' => 'Creates a URL redirect – visiting the source path will send visitors to the destination URL.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'source_path'      => array( 'type' => 'string', 'description' => 'e.g. "/old-page/".' ),
						'destination_url'  => array( 'type' => 'string' ),
						'type'             => array( 'type' => 'integer', 'description' => '301 (permanent) or 302 (temporary).', 'default' => 301 ),
					),
					'required'   => array( 'source_path', 'destination_url' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_serp_preview',
				'title'       => 'Get SERP Preview',
				'description' => 'Read-only: audits how a post would likely appear in a Google search result – the SEO title and meta description actually used (from Yoast/Rank Math if active, otherwise the post title and an auto-generated excerpt), their character lengths, and warnings if either is likely to be truncated or is missing.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_readability_score',
				'title'       => 'Get Readability Score',
				'description' => 'Read-only: scores a post\'s content on the Flesch Reading Ease scale (0-100, higher is easier to read) along with a plain-language grade label, word/sentence counts, and average sentence length. A heuristic estimate, not a perfect linguistic analysis.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_suggest_internal_links',
				'title'       => 'Suggest Internal Links',
				'description' => 'Read-only: scans a post\'s content for other published posts\' titles that appear as plain text but aren\'t already linked, and suggests adding an internal link to each – a simple, best-effort text match, not a deep semantic analysis.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'max_suggestions' => array( 'type' => 'integer', 'default' => 5 ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_check_outdated_content',
				'title'       => 'Check Outdated Content',
				'description' => 'Read-only: lists published posts/pages that haven\'t been modified in over a given number of days – candidates for a content refresh.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'older_than_days' => array( 'type' => 'integer', 'default' => 365 ),
						'post_type'       => array( 'type' => 'string', 'default' => 'post' ),
						'per_page'        => array( 'type' => 'integer', 'default' => 20 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
		);
	}

	/**
	 * Which plugin's meta keys to read/write, detected once per call.
	 * Returns array( 'title_key' => ..., 'desc_key' => ... ).
	 */
	private function detect_seo_meta_keys() {
		switch ( $this->detect_seo_provider() ) {
			case 'yoast':
				return array(
					'title_key'     => '_yoast_wpseo_title',
					'desc_key'      => '_yoast_wpseo_metadesc',
					'focuskw_key'   => '_yoast_wpseo_focuskw',
					'canonical_key' => '_yoast_wpseo_canonical',
					'og_title_key'  => '_yoast_wpseo_opengraph-title',
					'og_desc_key'   => '_yoast_wpseo_opengraph-description',
					'og_image_key'  => '_yoast_wpseo_opengraph-image',
				);
			case 'rankmath':
				return array(
					'title_key'     => 'rank_math_title',
					'desc_key'      => 'rank_math_description',
					'focuskw_key'   => 'rank_math_focus_keyword',
					'canonical_key' => 'rank_math_canonical_url',
					'og_title_key'  => 'rank_math_facebook_title',
					'og_desc_key'   => 'rank_math_facebook_description',
					'og_image_key'  => 'rank_math_facebook_image',
				);
			case 'seopress':
				return array(
					'title_key'     => '_seopress_titles_title',
					'desc_key'      => '_seopress_titles_desc',
					'focuskw_key'   => '_seopress_analysis_target_kw',
					'canonical_key' => '_seopress_robots_canonical',
					'og_title_key'  => '_seopress_social_fb_title',
					'og_desc_key'   => '_seopress_social_fb_desc',
					'og_image_key'  => '_seopress_social_fb_img',
				);
			default:
				return array(
					'title_key'     => '_wcops_seo_title',
					'desc_key'      => '_wcops_seo_description',
					'focuskw_key'   => '_wcops_focus_keyword',
					'canonical_key' => '_wcops_canonical_url',
					'og_title_key'  => '_wcops_og_title',
					'og_desc_key'   => '_wcops_og_description',
					'og_image_key'  => '_wcops_og_image',
				);
		}
	}

	/**
	 * Which SEO plugin (if any) governs this site's meta, used to route
	 * SEO field reads/writes to the right storage mechanism. AIOSEO
	 * stores its data in its own database table rather than post meta,
	 * so it needs a completely different code path from the other
	 * three, which are all meta-key-based.
	 */
	private function detect_seo_provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return 'native';
	}

	/**
	 * Human-readable label for the detected SEO provider, used in tool
	 * output so it's clear which plugin's data is actually being shown.
	 */
	private function seo_provider_label( $provider ) {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
			'native'   => 'WindCodex Ops (no SEO plugin detected)',
		);
		return $labels[ $provider ] ?? $labels['native'];
	}

	/**
	 * AIOSEO stores its SEO data in its own `{prefix}aioseo_posts` table
	 * rather than post meta, so it can't be read through the meta-key
	 * approach the other three providers share. The focus keyphrase
	 * lives inside a JSON blob in that table's `keyphrases` column, not
	 * its own column.
	 */
	private function get_aioseo_field( $post_id, $field ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return '';
		}
		if ( 'focus_keyword' === $field ) {
			$keyphrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
			return $keyphrases['focus']['keyphrase'] ?? '';
		}
		return $row[ $field ] ?? '';
	}

	/**
	 * Writes one AIOSEO field, creating the post's row in `aioseo_posts`
	 * if it doesn't exist yet - the same lazy creation AIOSEO's own edit
	 * screen relies on for a post it hasn't touched before.
	 */
	private function set_aioseo_field( $post_id, $field, $value ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'aioseo_posts';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, keyphrases FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore

		if ( 'focus_keyword' === $field ) {
			$keyphrases = $existing ? json_decode( (string) ( $existing['keyphrases'] ?? '' ), true ) : array();
			if ( ! is_array( $keyphrases ) ) {
				$keyphrases = array();
			}
			if ( ! isset( $keyphrases['focus'] ) || ! is_array( $keyphrases['focus'] ) ) {
				$keyphrases['focus'] = array();
			}
			$keyphrases['focus']['keyphrase'] = $value;
			$column                           = 'keyphrases';
			$value_to_store                   = wp_json_encode( $keyphrases );
		} else {
			$column         = $field;
			$value_to_store = $value;
		}

		if ( $existing ) {
			$wpdb->update( $table, array( $column => $value_to_store ), array( 'id' => $existing['id'] ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $table, array( 'post_id' => $post_id, $column => $value_to_store ) ); // phpcs:ignore
		}
	}

	private function wp_get_serp_preview( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		$keys        = $this->detect_seo_meta_keys();
		$seo_title   = get_post_meta( $id, $keys['title_key'], true );
		$seo_desc    = get_post_meta( $id, $keys['desc_key'], true );
		$title       = '' !== trim( (string) $seo_title ) ? $seo_title : get_the_title( $post );
		$description = '' !== trim( (string) $seo_desc ) ? $seo_desc : wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 30, '...' );

		$title_length = mb_strlen( $title );
		$desc_length  = mb_strlen( $description );

		$warnings = array();
		if ( $title_length > 60 ) {
			$warnings[] = "Title is {$title_length} characters – Google typically truncates titles beyond ~60 characters.";
		}
		if ( '' === trim( (string) $description ) ) {
			$warnings[] = 'No meta description is set, and no excerpt/content was available to fall back on – Google will generate its own snippet.';
		} elseif ( $desc_length > 160 ) {
			$warnings[] = "Meta description is {$desc_length} characters – Google typically truncates descriptions beyond ~160 characters.";
		} elseif ( $desc_length < 50 ) {
			$warnings[] = "Meta description is only {$desc_length} characters – quite short for a search snippet.";
		}

		$data = array(
			'url'                    => get_permalink( $post ),
			'title'                  => $title,
			'title_length'           => $title_length,
			'meta_description'       => $description,
			'meta_description_length' => $desc_length,
			'warnings'               => $warnings,
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Rough syllable-count heuristic (counts vowel groups, with common
	 * silent-e adjustment) – the same kind of approximation tools like
	 * this typically use; not a dictionary-perfect linguistic count.
	 */
	private function count_syllables( $word ) {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( '' === $word ) {
			return 0;
		}
		if ( strlen( $word ) <= 3 ) {
			return 1;
		}
		$word  = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word  = preg_replace( '/^y/', '', $word );
		preg_match_all( '/[aeiouy]{1,2}/', $word, $matches );
		return max( 1, count( $matches[0] ) );
	}

	private function wp_get_readability_score( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		$text = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		if ( '' === $text ) {
			return $this->error_result( 'This post has no readable text content to score.' );
		}

		$sentences      = max( 1, preg_match_all( '/[.!?]+/', $text ) );
		$words          = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$word_count     = count( $words );
		$syllable_count = 0;
		foreach ( $words as $word ) {
			$syllable_count += $this->count_syllables( $word );
		}

		if ( 0 === $word_count ) {
			return $this->error_result( 'This post has no readable text content to score.' );
		}

		$score = 206.835 - 1.015 * ( $word_count / $sentences ) - 84.6 * ( $syllable_count / $word_count );
		$score = max( 0, min( 100, round( $score, 1 ) ) );

		if ( $score >= 90 ) {
			$grade = 'Very easy';
		} elseif ( $score >= 80 ) {
			$grade = 'Easy';
		} elseif ( $score >= 70 ) {
			$grade = 'Fairly easy';
		} elseif ( $score >= 60 ) {
			$grade = 'Standard';
		} elseif ( $score >= 50 ) {
			$grade = 'Fairly difficult';
		} elseif ( $score >= 30 ) {
			$grade = 'Difficult';
		} else {
			$grade = 'Very difficult';
		}

		$data = array(
			'score'                 => $score,
			'grade'                 => $grade,
			'word_count'            => $word_count,
			'sentence_count'        => $sentences,
			'avg_words_per_sentence' => round( $word_count / $sentences, 1 ),
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_suggest_internal_links( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		$max_suggestions = max( 1, min( 20, (int) ( $args['max_suggestions'] ?? 5 ) ) );
		$content_text    = wp_strip_all_tags( $post->post_content );

		$candidates = get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'exclude'        => array( $id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- on-demand AI tool call, not a hot page-load path; capped at 200 results.
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$suggestions = array();
		foreach ( $candidates as $candidate ) {
			if ( count( $suggestions ) >= $max_suggestions ) {
				break;
			}
			$title = trim( $candidate->post_title );
			if ( mb_strlen( $title ) < 4 ) {
				continue; // Too short/generic a phrase to safely match on.
			}
			if ( false === stripos( $content_text, $title ) ) {
				continue; // Title doesn't appear in this post's text at all.
			}
			$permalink = get_permalink( $candidate );
			if ( false !== strpos( $post->post_content, $permalink ) || false !== strpos( $post->post_content, 'p=' . $candidate->ID ) ) {
				continue; // Already linked to this post somewhere.
			}
			$suggestions[] = array(
				'post_id'        => $candidate->ID,
				'title'          => $title,
				'url'            => $permalink,
				'matched_phrase' => $title,
			);
		}

		return $this->text_result( wp_json_encode( array( 'suggestions' => $suggestions ), JSON_PRETTY_PRINT ) );
	}

	private function wp_check_outdated_content( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}

		$older_than_days = max( 1, (int) ( $args['older_than_days'] ?? 365 ) );
		$post_type       = sanitize_key( $args['post_type'] ?? 'post' );
		$per_page        = min( (int) ( $args['per_page'] ?? 20 ), $this->settings->get_max_results_limit() );

		$posts = get_posts(
			array(
				'post_type'      => $post_type ?: 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, $per_page ),
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'column' => 'post_modified',
						'before' => $older_than_days . ' days ago',
					),
				),
			)
		);

		$data = array_map(
			function ( $post ) {
				return array(
					'id'               => $post->ID,
					'title'            => get_the_title( $post ),
					'url'              => get_permalink( $post ),
					'last_modified'    => $post->post_modified,
					'days_since_update' => (int) floor( ( time() - strtotime( $post->post_modified_gmt . ' UTC' ) ) / DAY_IN_SECONDS ),
				);
			},
			$posts
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_seo_meta( $args ) {
		$id = (int) ( $args['id'] ?? 0 );

		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		$provider = $this->detect_seo_provider();

		if ( 'aioseo' === $provider ) {
			$data = array(
				'id'               => $id,
				'seo_title'        => $this->get_aioseo_field( $id, 'title' ),
				'meta_description' => $this->get_aioseo_field( $id, 'description' ),
				'source'           => $this->seo_provider_label( $provider ),
			);
		} else {
			$keys = $this->detect_seo_meta_keys();
			$data = array(
				'id'               => $id,
				'seo_title'        => get_post_meta( $id, $keys['title_key'], true ),
				'meta_description' => get_post_meta( $id, $keys['desc_key'], true ),
				'source'           => $this->seo_provider_label( $provider ),
			);
		}

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_update_seo_meta( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id = (int) ( $args['id'] ?? 0 );

		$existing = get_post( $id );
		if ( ! $existing ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}

		$this->snapshot_before_change( 'wp_update_seo_meta', 'post', $id, 'update', $existing, "SEO meta for post #{$id}: \"" . get_the_title( $existing ) . '"' );

		$provider = $this->detect_seo_provider();

		if ( 'aioseo' === $provider ) {
			if ( isset( $args['seo_title'] ) ) {
				$this->set_aioseo_field( $id, 'title', sanitize_text_field( $args['seo_title'] ) );
			}
			if ( isset( $args['meta_description'] ) ) {
				$this->set_aioseo_field( $id, 'description', sanitize_text_field( $args['meta_description'] ) );
			}
		} else {
			$keys = $this->detect_seo_meta_keys();
			if ( isset( $args['seo_title'] ) ) {
				update_post_meta( $id, $keys['title_key'], sanitize_text_field( $args['seo_title'] ) );
			}
			if ( isset( $args['meta_description'] ) ) {
				update_post_meta( $id, $keys['desc_key'], sanitize_text_field( $args['meta_description'] ) );
			}
		}

		return $this->text_result( "Updated SEO meta for post #{$id}." );
	}

	private function wp_get_focus_keyword( $args ) {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		$provider = $this->detect_seo_provider();
		if ( 'aioseo' === $provider ) {
			$keyword = $this->get_aioseo_field( $id, 'focus_keyword' );
		} else {
			$keys    = $this->detect_seo_meta_keys();
			$keyword = get_post_meta( $id, $keys['focuskw_key'], true );
		}
		return $this->text_result( wp_json_encode( array( 'focus_keyword' => $keyword ), JSON_PRETTY_PRINT ) );
	}

	private function wp_set_focus_keyword( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id      = (int) ( $args['id'] ?? 0 );
		$keyword = sanitize_text_field( $args['keyword'] ?? '' );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		$provider = $this->detect_seo_provider();
		if ( 'aioseo' === $provider ) {
			$this->set_aioseo_field( $id, 'focus_keyword', $keyword );
		} else {
			$keys = $this->detect_seo_meta_keys();
			update_post_meta( $id, $keys['focuskw_key'], $keyword );
		}
		return $this->text_result( "Set focus keyword on post #{$id} to \"{$keyword}\"." );
	}

	private function wp_get_canonical_url( $args ) {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		$provider = $this->detect_seo_provider();
		if ( 'aioseo' === $provider ) {
			$url = $this->get_aioseo_field( $id, 'canonical_url' );
		} else {
			$keys = $this->detect_seo_meta_keys();
			$url  = get_post_meta( $id, $keys['canonical_key'], true );
		}
		return $this->text_result( wp_json_encode( array( 'canonical_url' => $url ?: null ), JSON_PRETTY_PRINT ) );
	}

	private function wp_set_canonical_url( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id  = (int) ( $args['id'] ?? 0 );
		$url = esc_url_raw( $args['url'] ?? '' );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		if ( '' === $url ) {
			return $this->error_result( 'url is required.' );
		}
		$provider = $this->detect_seo_provider();
		if ( 'aioseo' === $provider ) {
			$this->set_aioseo_field( $id, 'canonical_url', $url );
		} else {
			$keys = $this->detect_seo_meta_keys();
			update_post_meta( $id, $keys['canonical_key'], $url );
		}
		return $this->text_result( "Set canonical URL on post #{$id} to \"{$url}\"." );
	}

	private function wp_get_open_graph_meta( $args ) {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		$provider = $this->detect_seo_provider();
		if ( 'aioseo' === $provider ) {
			$data = array(
				'og_title'       => $this->get_aioseo_field( $id, 'og_title' ) ?: null,
				'og_description' => $this->get_aioseo_field( $id, 'og_description' ) ?: null,
				'og_image_url'   => $this->get_aioseo_field( $id, 'og_image_custom_url' ) ?: null,
			);
		} else {
			$keys = $this->detect_seo_meta_keys();
			$data = array(
				'og_title'       => get_post_meta( $id, $keys['og_title_key'], true ) ?: null,
				'og_description' => get_post_meta( $id, $keys['og_desc_key'], true ) ?: null,
				'og_image_url'   => get_post_meta( $id, $keys['og_image_key'], true ) ?: null,
			);
		}
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_set_open_graph_meta( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		$provider = $this->detect_seo_provider();

		if ( 'aioseo' === $provider ) {
			if ( isset( $args['og_title'] ) ) {
				$this->set_aioseo_field( $id, 'og_title', sanitize_text_field( $args['og_title'] ) );
			}
			if ( isset( $args['og_description'] ) ) {
				$this->set_aioseo_field( $id, 'og_description', sanitize_text_field( $args['og_description'] ) );
			}
			if ( isset( $args['og_image_id'] ) ) {
				$url = wp_get_attachment_url( (int) $args['og_image_id'] );
				if ( ! $url ) {
					return $this->error_result( "No attachment found with ID {$args['og_image_id']}." );
				}
				$this->set_aioseo_field( $id, 'og_image_custom_url', $url );
			}
		} else {
			$keys = $this->detect_seo_meta_keys();
			if ( isset( $args['og_title'] ) ) {
				update_post_meta( $id, $keys['og_title_key'], sanitize_text_field( $args['og_title'] ) );
			}
			if ( isset( $args['og_description'] ) ) {
				update_post_meta( $id, $keys['og_desc_key'], sanitize_text_field( $args['og_description'] ) );
			}
			if ( isset( $args['og_image_id'] ) ) {
				$url = wp_get_attachment_url( (int) $args['og_image_id'] );
				if ( ! $url ) {
					return $this->error_result( "No attachment found with ID {$args['og_image_id']}." );
				}
				update_post_meta( $id, $keys['og_image_key'], $url );
			}
		}
		return $this->text_result( "Updated Open Graph meta for post #{$id}." );
	}

	private function wp_get_robots_txt( $args ) {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return $this->error_result( 'Could not fetch robots.txt: ' . $response->get_error_message() );
		}
		return $this->text_result( wp_remote_retrieve_body( $response ) );
	}

	private function wp_check_broken_links( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}

		preg_match_all( '/href=["\']([^"\']+)["\']/', $post->post_content, $matches );
		$links = array_unique( array_slice( $matches[1], 0, 20 ) ); // Cap to keep this call fast.

		$results = array();
		foreach ( $links as $link ) {
			if ( 0 !== strpos( $link, 'http' ) ) {
				continue; // Skip anchors, mailto:, etc.
			}
			$response = wp_remote_head( $link, array( 'timeout' => 5, 'redirection' => 3 ) );
			$status   = is_wp_error( $response ) ? 'unreachable' : wp_remote_retrieve_response_code( $response );
			if ( 'unreachable' === $status || $status >= 400 ) {
				$results[] = array( 'url' => $link, 'status' => $status );
			}
		}

		return $this->text_result( wp_json_encode( array( 'links_checked' => count( $links ), 'broken_links' => $results ), JSON_PRETTY_PRINT ) );
	}

	private function wp_get_image_alt_text_report( $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$limit  = min( (int) ( $args['limit'] ?? 20 ), $this->settings->get_max_results_limit() );
		$images = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => -1, 'post_status' => 'inherit' ) );

		$missing = array();
		foreach ( $images as $image ) {
			$alt = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
			if ( '' === trim( (string) $alt ) ) {
				$missing[] = array( 'attachment_id' => $image->ID, 'filename' => basename( get_attached_file( $image->ID ) ) );
			}
			if ( count( $missing ) >= $limit ) {
				break;
			}
		}

		return $this->text_result( wp_json_encode( array( 'images_missing_alt_text' => count( $missing ), 'images' => $missing ), JSON_PRETTY_PRINT ) );
	}

	private function wp_set_image_alt_text( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit media.' );
		}
		$id  = (int) ( $args['attachment_id'] ?? 0 );
		$alt = sanitize_text_field( $args['alt_text'] ?? '' );
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->error_result( "No image attachment found with ID {$id}." );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return $this->text_result( "Set alt text on attachment #{$id}." );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Custom fields (works with ACF and similar plugins,
	 * since they store their values as regular post meta)
	 * ------------------------------------------------------------- */

	private function content_depth_tools() {
		return array(
			array(
				'name'        => 'wp_list_tags',
				'title'       => 'List Tags',
				'description' => 'Lists post tags with usage counts.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_create_tag',
				'title'       => 'Create Tag',
				'description' => 'Creates a new post tag.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_set_post_terms',
				'title'       => 'Set Post Terms',
				'description' => 'Assigns categories and/or tags to a post, replacing whatever was there before.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category IDs.' ),
						'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_post_revisions',
				'title'       => 'Get Post Revisions',
				'description' => 'Read-only: lists the revision history of a post, with the author and date of each revision.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_restore_post_revision',
				'title'       => 'Restore Post Revision',
				'description' => 'Restores a post to a specific past revision, from wp_get_post_revisions.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'revision_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_set_featured_image',
				'title'       => 'Set Featured Image',
				'description' => 'Sets a post/page\'s featured image from an existing media library attachment.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id', 'attachment_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_featured_image',
				'title'       => 'Get Featured Image',
				'description' => 'Read-only: gets a post/page\'s current featured image details, if it has one.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_post_types',
				'title'       => 'List Post Types',
				'description' => 'Read-only: lists every public post type registered on this site (post, page, product, and any custom post types from themes/plugins).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_bulk_publish_drafts',
				'title'       => 'Bulk Publish Drafts',
				'description' => 'Publishes multiple draft posts at once. Defaults to a dry run (confirm=false) showing exactly which drafts would be published.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'  => array( 'type' => 'string', 'description' => 'Only affect drafts matching this search term.' ),
						'confirm' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_list_content_blocks',
				'title'       => 'List Content Blocks',
				'description' => 'Read-only: parses a post\'s Gutenberg block structure and lists each block\'s type and position, so a specific block can be targeted by wp_remove_content_block.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_insert_content_block',
				'title'       => 'Insert Content Block',
				'description' => 'Inserts a new block (as raw block HTML) into a post\'s content at a given position.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'block_html'  => array( 'type' => 'string', 'description' => 'e.g. "<!-- wp:paragraph --><p>New text</p><!-- /wp:paragraph -->".' ),
						'position'    => array( 'type' => 'integer', 'description' => 'Block index to insert before. Omit to append at the end.' ),
					),
					'required'   => array( 'id', 'block_html' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_update_content_block',
				'title'       => 'Update Content Block',
				'description' => 'Updates one specific block\'s attributes and/or inner HTML in place, by position, re-serializing it correctly as real Gutenberg block markup. Protected by WordPress\'s own post revision history, the same as any other content edit.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'position'   => array( 'type' => 'integer', 'description' => 'The block index from wp_list_content_blocks.' ),
						'attrs'      => array( 'type' => 'object', 'description' => 'New attributes object for the block. Omit to leave attributes unchanged.' ),
						'inner_html' => array( 'type' => 'string', 'description' => 'New inner HTML for the block. Omit to leave content unchanged.' ),
					),
					'required'   => array( 'id', 'position' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
					array(
				'name'        => 'wp_remove_content_block',
				'title'       => 'Remove Content Block',
				'description' => 'Removes a specific block from a post\'s content, identified by its position from wp_list_content_blocks.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'position' => array( 'type' => 'integer', 'description' => 'The block index from wp_list_content_blocks.' ),
					),
					'required'   => array( 'id', 'position' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp_get_word_count',
				'title'       => 'Get Word Count',
				'description' => 'Read-only: word and character count for a post\'s content.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_list_recently_modified',
				'title'       => 'List Recently Modified Content',
				'description' => 'Read-only: lists the most recently edited posts/pages, across all statuses, most recent first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 10 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_search_content',
				'title'       => 'Search Content',
				'description' => 'Read-only: full-text search across posts and pages together (unlike wp_list_posts, which only searches one post type at a time).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
						'limit' => array( 'type' => 'integer', 'default' => 10 ),
					),
					'required'   => array( 'query' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_get_post_by_slug',
				'title'       => 'Get Post By Slug',
				'description' => 'Read-only: looks up a post or page by its URL slug instead of its numeric ID – useful when working from a URL rather than an ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array( 'type' => 'string' ),
					),
					'required'   => array( 'slug' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_bulk_trash_old_drafts',
				'title'       => 'Bulk Trash Old Drafts',
				'description' => 'Trashes draft posts that haven\'t been touched in a given number of days – cleanup for abandoned drafts. Defaults to a dry run (confirm=false).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'older_than_days' => array( 'type' => 'integer', 'default' => 90 ),
						'confirm'         => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_list_tags( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read tags.' );
		}
		$tags = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
		$data = array_map( function ( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name, 'count' => $t->count ); }, is_array( $tags ) ? $tags : array() );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_create_tag( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to create tags.' );
		}
		$name = sanitize_text_field( $args['name'] ?? '' );
		if ( '' === $name ) {
			return $this->error_result( 'name is required.' );
		}
		$result = wp_insert_term( $name, 'post_tag' );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		return $this->text_result( "Created tag \"{$name}\" (#{$result['term_id']})." );
	}

	private function wp_set_post_terms( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		if ( isset( $args['categories'] ) ) {
			wp_set_post_categories( $id, array_map( 'intval', (array) $args['categories'] ) );
		}
		if ( isset( $args['tags'] ) ) {
			wp_set_post_tags( $id, (array) $args['tags'] );
		}
		return $this->text_result( "Updated terms on post #{$id}." );
	}

	private function wp_get_post_revisions( $args ) {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}
		$revisions = wp_get_post_revisions( $id );
		$data = array_map( function ( $r ) { return array( 'revision_id' => $r->ID, 'author' => get_the_author_meta( 'display_name', $r->post_author ), 'date' => $r->post_modified ); }, $revisions );
		return $this->text_result( wp_json_encode( array_values( $data ), JSON_PRETTY_PRINT ) );
	}

	private function wp_restore_post_revision( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$revision_id = (int) ( $args['revision_id'] ?? 0 );
		$revision    = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return $this->error_result( "No revision found with ID {$revision_id}." );
		}
		if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		$parent = get_post( $revision->post_parent );
		$this->snapshot_before_change( 'wp_restore_post_revision', 'post', $revision->post_parent, 'update', $parent, "Post #{$revision->post_parent} before restoring revision #{$revision_id}" );
		wp_restore_post_revision( $revision_id );
		return $this->text_result( "Restored post #{$revision->post_parent} to revision #{$revision_id}." );
	}

	private function wp_set_featured_image( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id            = (int) ( $args['id'] ?? 0 );
		$attachment_id = (int) ( $args['attachment_id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $this->error_result( "No image attachment found with ID {$attachment_id}." );
		}
		set_post_thumbnail( $id, $attachment_id );
		return $this->text_result( "Set featured image on post #{$id} to attachment #{$attachment_id}." );
	}

	private function wp_get_featured_image( $args ) {
		$id = (int) ( $args['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		$thumb_id = get_post_thumbnail_id( $id );
		if ( ! $thumb_id ) {
			return $this->text_result( 'This post has no featured image.' );
		}
		return $this->text_result( wp_json_encode( array( 'attachment_id' => $thumb_id, 'url' => wp_get_attachment_url( $thumb_id ) ), JSON_PRETTY_PRINT ) );
	}

	private function wp_list_post_types( $args ) {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$data  = array_map( function ( $t ) { return array( 'name' => $t->name, 'label' => $t->label ); }, array_values( $types ) );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_bulk_publish_drafts( $args ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to publish posts.' );
		}
		$confirm = ! empty( $args['confirm'] );
		if ( $confirm && $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$query_args = array( 'post_type' => 'post', 'post_status' => 'draft', 'posts_per_page' => -1 );
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}
		$drafts = get_posts( $query_args );
		if ( empty( $drafts ) ) {
			return $this->text_result( 'No matching drafts found.' );
		}
		if ( ! $confirm ) {
			$preview = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ) ); }, $drafts );
			return $this->text_result( wp_json_encode( array( 'preview_only' => true, 'drafts_that_would_publish' => count( $drafts ), 'drafts' => $preview ), JSON_PRETTY_PRINT ) );
		}
		$published = array();
		foreach ( $drafts as $draft ) {
			$this->snapshot_before_change( 'wp_bulk_publish_drafts', 'post', $draft->ID, 'update', $draft, "Post #{$draft->ID}: \"" . get_the_title( $draft ) . '"' );
			wp_update_post( array( 'ID' => $draft->ID, 'post_status' => 'publish' ) );
			$published[] = $draft->ID;
		}
		return $this->text_result( wp_json_encode( array( 'preview_only' => false, 'published_count' => count( $published ), 'post_ids' => $published ), JSON_PRETTY_PRINT ) );
	}

	private function wp_list_content_blocks( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to read this post.' );
		}
		$blocks = parse_blocks( $post->post_content );
		$data   = array();
		foreach ( $blocks as $i => $block ) {
			if ( null === $block['blockName'] ) {
				continue; // Whitespace between blocks parses as a null-name block – not meaningful to list.
			}
			$data[] = array( 'position' => $i, 'type' => $block['blockName'] );
		}
		return $this->text_result( wp_json_encode( array_values( $data ), JSON_PRETTY_PRINT ) );
	}

	private function wp_insert_content_block( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id         = (int) ( $args['id'] ?? 0 );
		$block_html = (string) ( $args['block_html'] ?? '' );
		$post       = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		if ( '' === trim( $block_html ) ) {
			return $this->error_result( 'block_html is required.' );
		}
		$this->snapshot_before_change( 'wp_insert_content_block', 'post', $id, 'update', $post, "Post #{$id}: \"" . get_the_title( $post ) . '"' );
		$blocks = parse_blocks( $post->post_content );
		$position = isset( $args['position'] ) ? max( 0, min( (int) $args['position'], count( $blocks ) ) ) : count( $blocks );
		$new_block = parse_blocks( $block_html );
		array_splice( $blocks, $position, 0, $new_block );
		wp_update_post( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ) );
		return $this->text_result( "Inserted new block into post #{$id} at position {$position}." );
	}

	private function wp_update_content_block( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id       = (int) ( $args['id'] ?? 0 );
		$position = (int) ( $args['position'] ?? -1 );
		$post     = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		if ( ! isset( $args['attrs'] ) && ! isset( $args['inner_html'] ) ) {
			return $this->error_result( 'Provide at least one of attrs or inner_html to change.' );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( $position < 0 || $position >= count( $blocks ) || null === $blocks[ $position ]['blockName'] ) {
			return $this->error_result( "No block at position {$position}. Use wp_list_content_blocks to see valid positions." );
		}

		$this->snapshot_before_change( 'wp_update_content_block', 'post', $id, 'update', $post, "Post #{$id}: \"" . get_the_title( $post ) . '"' );

		if ( isset( $args['attrs'] ) && is_array( $args['attrs'] ) ) {
			$blocks[ $position ]['attrs'] = $args['attrs'];
		}
		if ( isset( $args['inner_html'] ) ) {
			$new_html                          = (string) $args['inner_html'];
			$blocks[ $position ]['innerHTML']    = $new_html;
			$blocks[ $position ]['innerContent'] = array( $new_html );
		}

		wp_update_post( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ) );

		return $this->text_result( "Updated block at position {$position} on post #{$id}. " . $this->undo_note() );
	}

	private function wp_remove_content_block( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$id       = (int) ( $args['id'] ?? 0 );
		$position = (int) ( $args['position'] ?? -1 );
		$post     = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $this->error_result( 'Acting user is not permitted to edit this post.' );
		}
		$blocks = parse_blocks( $post->post_content );
		if ( $position < 0 || $position >= count( $blocks ) ) {
			return $this->error_result( "No block at position {$position}. Use wp_list_content_blocks to see valid positions." );
		}
		$this->snapshot_before_change( 'wp_remove_content_block', 'post', $id, 'update', $post, "Post #{$id}: \"" . get_the_title( $post ) . '"' );
		array_splice( $blocks, $position, 1 );
		wp_update_post( array( 'ID' => $id, 'post_content' => serialize_blocks( $blocks ) ) );
		return $this->text_result( "Removed block at position {$position} from post #{$id}." );
	}

	private function wp_get_word_count( $args ) {
		$id   = (int) ( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error_result( "No post found with ID {$id}." );
		}
		$text = wp_strip_all_tags( $post->post_content );
		return $this->text_result( wp_json_encode( array( 'word_count' => str_word_count( $text ), 'character_count' => mb_strlen( $text ) ), JSON_PRETTY_PRINT ) );
	}

	private function wp_list_recently_modified( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to read this.' );
		}
		$limit = min( (int) ( $args['limit'] ?? 10 ), $this->settings->get_max_results_limit() );
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'orderby' => 'modified', 'order' => 'DESC', 'posts_per_page' => $limit ) );
		$data  = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'type' => $p->post_type, 'modified' => $p->post_modified ); }, $posts );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_search_content( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to search content.' );
		}
		$query = sanitize_text_field( $args['query'] ?? '' );
		if ( '' === $query ) {
			return $this->error_result( 'query is required.' );
		}
		$limit = min( (int) ( $args['limit'] ?? 10 ), $this->settings->get_max_results_limit() );
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 's' => $query, 'posts_per_page' => $limit ) );
		$data  = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'type' => $p->post_type ); }, $posts );
		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_get_post_by_slug( $args ) {
		$slug = sanitize_title( $args['slug'] ?? '' );
		if ( '' === $slug ) {
			return $this->error_result( 'slug is required.' );
		}
		$post = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );
		if ( ! $post ) {
			$found = get_posts( array( 'name' => $slug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'posts_per_page' => 1 ) );
			$post  = $found ? $found[0] : null;
		}
		if ( ! $post ) {
			return $this->error_result( "No post or page found with slug \"{$slug}\"." );
		}
		return $this->text_result( wp_json_encode( array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'type' => $post->post_type, 'status' => $post->post_status ), JSON_PRETTY_PRINT ) );
	}

	private function wp_bulk_trash_old_drafts( $args ) {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to delete posts.' );
		}
		$confirm = ! empty( $args['confirm'] );
		if ( $confirm && $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}
		$days   = max( 1, (int) ( $args['older_than_days'] ?? 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$drafts = get_posts( array( 'post_type' => 'post', 'post_status' => 'draft', 'date_query' => array( array( 'column' => 'post_modified_gmt', 'before' => $cutoff ) ), 'posts_per_page' => -1 ) );
		if ( empty( $drafts ) ) {
			return $this->text_result( "No drafts older than {$days} days found." );
		}
		if ( ! $confirm ) {
			$preview = array_map( function ( $p ) { return array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'last_modified' => $p->post_modified ); }, $drafts );
			return $this->text_result( wp_json_encode( array( 'preview_only' => true, 'drafts_that_would_be_trashed' => count( $drafts ), 'drafts' => $preview ), JSON_PRETTY_PRINT ) );
		}
		$trashed = array();
		foreach ( $drafts as $draft ) {
			$this->snapshot_before_change( 'wp_bulk_trash_old_drafts', 'post', $draft->ID, 'delete', $draft, "Post #{$draft->ID}: \"" . get_the_title( $draft ) . '"' );
			wp_trash_post( $draft->ID );
			$trashed[] = $draft->ID;
		}
		return $this->text_result( wp_json_encode( array( 'preview_only' => false, 'trashed_count' => count( $trashed ), 'post_ids' => $trashed ), JSON_PRETTY_PRINT ) );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Bulk actions – preview-before-you-commit
	 *
	 * The plan's "Preview before big changes" safety idea: a single tool
	 * that changes many things at once always defaults to a dry run first,
	 * showing exactly what would change without touching anything, and
	 * only actually applies the change when called again with confirm=true.
	 * ------------------------------------------------------------- */

	private function wp_bulk_tools() {
		return array(
			array(
				'name'        => 'wp_find_replace',
				'title'       => 'Site-Wide Find & Replace',
				'description' => 'Finds and replaces text across multiple posts/pages at once. Defaults to a dry run (confirm=false) that shows exactly which posts would change and how many matches each has, without changing anything. Only actually applies the change when called again with confirm=true.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'find'      => array( 'type' => 'string', 'description' => 'Text to search for (case-sensitive, literal – not a regex).' ),
						'replace'   => array( 'type' => 'string', 'description' => 'Text to replace it with.' ),
						'post_type' => array( 'type' => 'string', 'description' => 'post or page.', 'default' => 'post' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'false (default) previews only; true actually applies the change.', 'default' => false ),
					),
					'required'   => array( 'find', 'replace' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	private function wp_find_replace( $args ) {
		$find      = (string) ( $args['find'] ?? '' );
		$replace   = (string) ( $args['replace'] ?? '' );
		$post_type_input = $args['post_type'] ?? 'post';
		$post_type       = in_array( $post_type_input, array( 'post', 'page' ), true ) ? $post_type_input : 'post';
		$confirm   = ! empty( $args['confirm'] );

		if ( '' === $find ) {
			return $this->error_result( 'find is required and cannot be empty.' );
		}
		if ( 'post' === $post_type && ! current_user_can( 'edit_posts' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit posts.' );
		}
		if ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
			return $this->error_result( 'Acting user is not permitted to edit pages.' );
		}
		if ( $confirm && $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				's'              => $find,
			)
		);

		// WP_Query's 's' search isn't a guaranteed substring match against
		// raw post_content (it can match titles/excerpts too, and applies
		// its own relevance logic) – filter down to posts that actually
		// contain the literal string, so the preview and the real run
		// operate on exactly the same set of posts.
		$matches = array();
		foreach ( $query->posts as $post ) {
			$count = substr_count( $post->post_content, $find );
			if ( $count > 0 ) {
				$matches[] = array( 'post' => $post, 'count' => $count );
			}
		}

		if ( empty( $matches ) ) {
			return $this->text_result( "No {$post_type}s contain \"{$find}\". Nothing to do." );
		}

		if ( ! $confirm ) {
			$preview = array_map(
				function ( $m ) {
					return array(
						'id'           => $m['post']->ID,
						'title'        => get_the_title( $m['post'] ),
						'match_count'  => $m['count'],
					);
				},
				$matches
			);

			$total_matches = array_sum( array_column( $matches, 'count' ) );

			return $this->text_result(
				wp_json_encode(
					array(
						'preview_only'        => true,
						'posts_that_would_change' => count( $matches ),
						'total_matches'       => $total_matches,
						'posts'               => $preview,
						'note'                => 'No changes have been made. Call wp_find_replace again with the same find/replace and confirm:true to actually apply this.',
					),
					JSON_PRETTY_PRINT
				)
			);
		}

		$changed = array();
		foreach ( $matches as $m ) {
			$post = $m['post'];
			$this->snapshot_before_change( 'wp_find_replace', $post_type, $post->ID, 'update', $post, "{$post_type} #{$post->ID}: \"" . get_the_title( $post ) . '"' );

			$new_content = str_replace( $find, $replace, $post->post_content );
			$result      = wp_update_post( array( 'ID' => $post->ID, 'post_content' => $new_content ), true );

			if ( ! is_wp_error( $result ) ) {
				$changed[] = $post->ID;
			}
		}

		return $this->text_result(
			wp_json_encode(
				array(
					'preview_only'   => false,
					'posts_changed'  => count( $changed ),
					'post_ids'       => $changed,
					'note'           => $this->settings->get( 'enable_undo_log', true )
						? 'Each change was recorded individually and can be undone per-post within ' . $this->settings->get_undo_window_hours() . ' hours via wp_list_recent_changes / wp_undo_change.'
						: 'Undo history is currently off ("Keep undo history" in Settings > WindCodex Ops > General > Preferences), so these changes cannot be undone.',
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * The trailing sentence every mutating tool's success message ends
	 * with. Must check the *actual* current state of "Keep undo history"
	 * rather than assuming it's on - a hardcoded claim that a change "can
	 * be undone" is actively false, not just incomplete, when that toggle
	 * is off, and the only way anyone would find out otherwise is by
	 * trying wp_undo_change and having it fail.
	 */
	private function undo_note( $suffix = '' ) {
		if ( ! $this->settings->get( 'enable_undo_log', true ) ) {
			return 'Undo history is currently off ("Keep undo history" in Settings > WindCodex Ops > General > Preferences), so this change cannot be undone.';
		}
		return 'This can be undone within ' . $this->settings->get_undo_window_hours() . ' hours via wp_undo_change' . ( $suffix ? ", {$suffix}" : '.' );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Undo – always available, at every tier, no exceptions
	 *
	 * This is the plan's core safety feature: any content change made
	 * through this plugin can be reversed within a configurable window
	 * (default 72 hours). Unlike every other tool group, this one isn't
	 * gated by a tool_groups toggle – undo has to always be reachable,
	 * or it isn't a real safety net.
	 * ------------------------------------------------------------- */

	/**
	 * Called by every mutating tool right before it makes a change, to
	 * capture what the object looked like beforehand. $existing_post
	 * should be the WP_Post object as it was immediately before the change.
	 */
	private function snapshot_before_change( $tool_name, $object_type, $object_id, $action, $existing_post, $summary ) {
		if ( ! $this->settings->get( 'enable_undo_log', true ) ) {
			return; // Undo logging is off – the undo guarantee can't be honored without a record to restore from, so nothing gets saved.
		}
		$before_data = array(
			'post_title'   => $existing_post->post_title,
			'post_content' => $existing_post->post_content,
			'post_status'  => $existing_post->post_status,
		);

		WCOPS_Undo_Log::record( get_current_user_id(), $tool_name, $object_type, $object_id, $action, $before_data, $summary );
	}

	/**
	 * Same idea as snapshot_before_change() above, but for a taxonomy term
	 * instead of a post: categories and tags don't have title/content/
	 * status, they have name/slug/description/parent, and WordPress has no
	 * trash for terms at all, so a deleted term can only be recreated from
	 * this snapshot, not restored in place.
	 */
	private function snapshot_before_term_change( $tool_name, $object_type, $term_id, $taxonomy, $action, $summary ) {
		if ( ! $this->settings->get( 'enable_undo_log', true ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$before_data = array(
			'taxonomy'    => $taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
		);

		WCOPS_Undo_Log::record( get_current_user_id(), $tool_name, $object_type, $term_id, $action, $before_data, $summary );
	}

	/* -----------------------------------------------------------------
	 * Tool group: Memory - site-wide facts a future, separate
	 * conversation can pick back up, rather than starting from zero.
	 * Plaintext, not a secrets vault - the same honest framing already
	 * used for the license-key tracker.
	 * ------------------------------------------------------------- */

	private function undo_tools() {

		return array(
			array(
				'name'        => 'wp_list_recent_changes',
				'title'       => 'List Recent Changes',
				'description' => 'Lists changes made through this plugin that are still within the undo window (default 72 hours) and haven\'t already been undone. Use this to find the ID needed for wp_undo_change.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false ),
			),
			array(
				'name'        => 'wp_undo_change',
				'title'       => 'Undo Change',
				'description' => 'Reverses a specific change (post/page edit, category/tag edit, or delete) made through this plugin, restoring it to how it looked immediately before that change. Note: a deleted category or tag is recreated rather than restored in place, so posts that had it are not automatically reassigned. Only works within the undo window and only once per change. Get the change ID from wp_list_recent_changes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'change_id' => array( 'type' => 'integer', 'description' => 'The ID from wp_list_recent_changes.' ),
					),
					'required'   => array( 'change_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
			),
		);
	}

	private function wp_list_recent_changes( $args ) {
		$entries = WCOPS_Undo_Log::get_undoable( $this->settings->get_undo_window_hours(), 50 );

		$data = array_map(
			function ( $entry ) {
				$expires_at = strtotime( $entry->created_at . ' UTC' ) + ( $this->settings->get_undo_window_hours() * HOUR_IN_SECONDS );
				return array(
					'change_id'       => (int) $entry->id,
					'tool'            => $entry->tool_name,
					'action'          => $entry->action,
					'summary'         => $entry->summary,
					'made_at'         => $entry->created_at . ' UTC',
					'hours_remaining' => max( 0, round( ( $expires_at - time() ) / HOUR_IN_SECONDS, 1 ) ),
				);
			},
			$entries
		);

		return $this->text_result( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function wp_undo_change( $args ) {
		if ( $this->settings->is_read_only() ) {
			return $this->error_result( 'This connector is in read-only mode.' );
		}

		$id     = (int) ( $args['change_id'] ?? 0 );
		$result = WCOPS_Undo_Log::restore( $id, $this->settings->get_undo_window_hours() );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		return $this->text_result( $result );
	}

	/* -----------------------------------------------------------------
	 * Result helpers (MCP tool result shape)
	 * ------------------------------------------------------------- */

	private function text_result( $text ) {
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $text ),
			),
			'isError' => false,
		);
	}

	private function error_result( $message ) {
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $message ),
			),
			'isError' => true,
		);
	}
}
