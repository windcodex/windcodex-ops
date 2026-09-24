<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A minimal OAuth 2.1 authorization server, just complete enough for Claude
 * (or any MCP client) to authenticate against this site.
 *
 * Supports:
 *  - RFC 8414 authorization server metadata (/.well-known/oauth-authorization-server)
 *  - RFC 9728 protected resource metadata (/.well-known/oauth-protected-resource)
 *  - RFC 7591 dynamic client registration (POST .../oauth/register)
 *  - Authorization Code + PKCE (S256) grant, via a real WP login + consent screen
 *  - Refresh token grant
 *  - A pre-generated static client (id + secret) admins can paste into Claude
 *    manually if dynamic registration ever fails on the client's end.
 *
 * Tokens authenticate as whichever WordPress user logs in and approves the
 * consent screen, and only if that user holds the "Who can connect"
 * capability (Administrators only by default). There is no shared or
 * fallback account.
 */
class WCOPS_OAuth {

	private static $instance = null;

	const CODE_TTL             = 300;        // 5 minutes to complete the code exchange.
	const ACCESS_TOKEN_TTL     = 3600;       // 1 hour.
	const REFRESH_TOKEN_TTL    = 60 * 60 * 24 * 30; // 30 days.

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_serve_well_known' ), -1 );
		add_action( 'init', array( $this, 'register_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_pretty_authorize' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'admin_post_wcops_oauth_authorize', array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_nopriv_wcops_oauth_authorize', array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_wcops_oauth_consent', array( $this, 'handle_consent' ) );
	}

	/**
	 * Give the authorize endpoint a clean URL with no query string
	 * (home_url('/wcops-oauth/authorize')) instead of only the
	 * admin-post.php?action=... form. Some OAuth clients mishandle an
	 * authorization_endpoint value that already contains a query string,
	 * silently falling back to a guessed "{origin}/authorize" instead of
	 * using it correctly – this rewrite avoids that ambiguity entirely.
	 * The admin-post.php route is kept working too, for anything that
	 * already discovered/cached the old URL.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^wcops-oauth/authorize/?$', 'index.php?wcops_oauth_authorize=1', 'top' );
	}

	public function register_rewrite_rule() {
		self::add_rewrite_rules();
	}

	public function register_query_var( $vars ) {
		$vars[] = 'wcops_oauth_authorize';
		return $vars;
	}

	public function maybe_handle_pretty_authorize() {
		if ( get_query_var( 'wcops_oauth_authorize' ) ) {
			$this->handle_authorize();
		}
	}

	/* -----------------------------------------------------------------
	 * Setup / schema
	 * ------------------------------------------------------------- */

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$clients_table = $wpdb->prefix . 'wcops_oauth_clients';
		$codes_table   = $wpdb->prefix . 'wcops_oauth_codes';
		$tokens_table  = $wpdb->prefix . 'wcops_oauth_tokens';

		dbDelta(
			"CREATE TABLE {$clients_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				client_id VARCHAR(64) NOT NULL,
				client_secret VARCHAR(128) NULL,
				client_name VARCHAR(191) NULL,
				redirect_uris TEXT NULL,
				is_static TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$codes_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				code VARCHAR(128) NOT NULL,
				client_id VARCHAR(64) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				redirect_uri TEXT NOT NULL,
				code_challenge VARCHAR(128) NULL,
				code_challenge_method VARCHAR(16) NULL,
				expires_at DATETIME NOT NULL,
				used TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$tokens_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				access_token_hash VARCHAR(64) NOT NULL,
				refresh_token_hash VARCHAR(64) NULL,
				client_id VARCHAR(64) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				access_expires_at DATETIME NOT NULL,
				refresh_expires_at DATETIME NULL,
				revoked TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY access_token_hash (access_token_hash),
				KEY refresh_token_hash (refresh_token_hash)
			) {$charset_collate};"
		);

		self::ensure_static_client();
	}

	/**
	 * Make sure a single pre-registered "claude" client exists (kept from
	 * earlier versions, for backward compatibility with sites already
	 * pointing a connector at it). Admins can create additional named
	 * clients per platform via create_manual_client() below.
	 */
	public static function ensure_static_client() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_clients';

		$existing = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_static = 1 LIMIT 1" ); // phpcs:ignore

		if ( $existing ) {
			return $existing;
		}

		return self::create_manual_client( 'Claude' );
	}

	public static function get_static_client() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_clients';
		return $wpdb->get_row( "SELECT * FROM {$table} WHERE is_static = 1 ORDER BY id ASC LIMIT 1" ); // phpcs:ignore
	}

	public static function regenerate_static_client_secret() {
		global $wpdb;
		$table  = $wpdb->prefix . 'wcops_oauth_clients';
		$client = self::get_static_client();

		if ( ! $client ) {
			return self::ensure_static_client();
		}

		$new_secret = wp_generate_password( 48, false, false );
		$wpdb->update( $table, array( 'client_secret' => $new_secret ), array( 'id' => $client->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return self::get_static_client();
	}

	/**
	 * Regenerate the secret for any specific manual client by its client_id.
	 */
	public static function regenerate_client_secret( $client_id ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'wcops_oauth_clients';
		$new_secret = wp_generate_password( 48, false, false );
		$wpdb->update( $table, array( 'client_secret' => $new_secret ), array( 'client_id' => $client_id, 'is_static' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ) ); // phpcs:ignore
	}

	/**
	 * Create a new named, manually-pasteable client (one per platform, e.g.
	 * "ChatGPT", "Perplexity"). Each gets its own Client ID/Secret so
	 * platforms are never sharing credentials and can be revoked
	 * independently. Accepts any redirect_uri, same as the legacy static
	 * client, since we can't predict every platform's callback URL.
	 */
	public static function create_manual_client( $label ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_clients';

		$slug          = sanitize_title( $label ) ?: 'client';
		$client_id     = $slug . '-' . wp_generate_password( 12, false, false );
		$client_secret = wp_generate_password( 48, false, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'client_name'   => sanitize_text_field( $label ),
				'redirect_uris' => wp_json_encode( array() ),
				'is_static'     => 1,
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ) ); // phpcs:ignore
	}

	/**
	 * All manually-created clients (used to populate the admin table), most
	 * recently created first.
	 */
	public static function get_manual_clients() {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_clients';
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE is_static = 1 ORDER BY id DESC" ); // phpcs:ignore
	}

	/**
	 * Delete a manual client and revoke any tokens it holds.
	 */
	public static function delete_manual_client( $client_id ) {
		global $wpdb;
		$clients_table = $wpdb->prefix . 'wcops_oauth_clients';
		$tokens_table  = $wpdb->prefix . 'wcops_oauth_tokens';

		$wpdb->update( $tokens_table, array( 'revoked' => 1 ), array( 'client_id' => $client_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $clients_table, array( 'client_id' => $client_id, 'is_static' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Every client that's ever connected (manually created or
	 * self-registered by an AI platform via dynamic registration), with
	 * its most recent non-revoked token's timestamp as "last used" – real
	 * data, not a placeholder, since a client that registered but never
	 * actually completed a token exchange shouldn't show a fake activity
	 * date.
	 */
	public static function list_all_clients_with_activity( $limit = 50 ) {
		global $wpdb;
		$clients_table = esc_sql( $wpdb->prefix . 'wcops_oauth_clients' );
		$tokens_table  = esc_sql( $wpdb->prefix . 'wcops_oauth_tokens' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom plugin tables, esc_sql()'d above; %s can't parameterize identifiers.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*,
					(SELECT MAX(created_at) FROM {$tokens_table} t WHERE t.client_id = c.client_id AND t.revoked = 0) AS last_used,
					(SELECT t2.user_id FROM {$tokens_table} t2 WHERE t2.client_id = c.client_id ORDER BY t2.created_at DESC LIMIT 1) AS acting_user_id
				FROM {$clients_table} c
				ORDER BY c.created_at DESC
				LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Revoke every token (access and refresh) belonging to one specific
	 * client, without touching any other connected app.
	 */
	public static function revoke_client_tokens( $client_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_tokens';
		return $wpdb->update( $table, array( 'revoked' => 1 ), array( 'client_id' => sanitize_text_field( $client_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/* -----------------------------------------------------------------
	 * Discovery (.well-known)
	 *
	 * IMPORTANT: the bare, generic paths (/.well-known/oauth-authorization-
	 * server and /.well-known/oauth-protected-resource) are NOT used here.
	 * Those assume exactly one OAuth authorization server per website –
	 * true for a single plugin, false the moment a second WindCodex plugin
	 * (or the old combined connector) is active on the same site, since
	 * whichever one loads first would silently answer for both, sending
	 * clients through the wrong plugin's consent screen entirely. Instead,
	 * this uses RFC 8414/9728's path-suffixed form, scoped to this
	 * specific plugin, so multiple WindCodex plugins can coexist on one
	 * site without colliding.
	 * ------------------------------------------------------------- */

	const DISCOVERY_SUFFIX = '/wcops';

	public function maybe_serve_well_known() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = parse_url( $request_uri, PHP_URL_PATH ); // phpcs:ignore

		// well-known paths must be compared relative to wherever this site
		// actually lives, not assumed to be the domain root - on a site
		// installed in a subdirectory (home_url() like
		// https://example.com/blog), the real request path is
		// /blog/.well-known/..., and comparing against a bare
		// /.well-known/... would never match, silently falling through to
		// WordPress's normal routing (or another plugin's own well-known
		// handler) instead of ours.
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( $home_path . '/.well-known/oauth-authorization-server' . self::DISCOVERY_SUFFIX === $path ) {
			$this->serve_json( $this->authorization_server_metadata() );
		}

		if ( $home_path . '/.well-known/oauth-protected-resource' . self::DISCOVERY_SUFFIX === $path ) {
			$this->serve_json( $this->protected_resource_metadata() );
		}
	}

	private function serve_json( $data ) {
		nocache_headers();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $data );
		exit;
	}

	private function authorization_server_metadata() {
		// A distinguishing, plugin-specific issuer value – not just the
		// bare site URL, which is what two separate plugins would
		// otherwise both claim identically.
		$issuer = untrailingslashit( home_url() ) . self::DISCOVERY_SUFFIX;

		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'                => home_url( '/wcops-oauth/authorize' ),
			'token_endpoint'                         => rest_url( WCOPS_REST_NAMESPACE . '/oauth/token' ),
			'registration_endpoint'                  => rest_url( WCOPS_REST_NAMESPACE . '/oauth/register' ),
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'       => array( 'S256' ),
			'token_endpoint_auth_methods_supported'  => array( 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                       => array( 'mcp' ),
		);
	}

	private function protected_resource_metadata() {
		return array(
			'resource'              => rest_url( WCOPS_REST_NAMESPACE . '/mcp' ),
			'authorization_servers' => array( untrailingslashit( home_url() ) . self::DISCOVERY_SUFFIX ),
		);
	}

	/**
	 * The resource-specific URL to point clients at via the
	 * WWW-Authenticate header on a 401 – this, not the generic bare path,
	 * is what actually avoids the multi-plugin collision. See
	 * WCOPS_Server::check_permission().
	 */
	public static function protected_resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' . self::DISCOVERY_SUFFIX );
	}

	/* -----------------------------------------------------------------
	 * Dynamic Client Registration (RFC 7591) + Token endpoint (REST)
	 * ------------------------------------------------------------- */

	public function register_rest_routes() {
		register_rest_route(
			WCOPS_REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_register_client' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			WCOPS_REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function rest_register_client( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! WCOPS_Settings::instance()->get( 'allow_dynamic_registration', true ) ) {
			return new WP_REST_Response( array( 'error' => 'access_denied', 'error_description' => 'Dynamic client registration is disabled on this server.' ), 403 );
		}

		// Registration is unauthenticated by design (that's what dynamic
		// client registration means), so it's the most exposed endpoint to
		// spam/abuse – limit how many new clients one IP can create.
		if ( ! WCOPS_Rate_Limiter::check( 'oauth_register', 10, HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'too_many_requests', 'error_description' => 'Too many registration attempts. Please try again later.' ), 429 );
		}

		$body          = $request->get_json_params();
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$client_name   = sanitize_text_field( $body['client_name'] ?? 'MCP client' );

		if ( empty( $redirect_uris ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris is required.' ), 400 );
		}

		$client_id     = 'dyn-' . wp_generate_password( 16, false, false );
		$client_secret = wp_generate_password( 48, false, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_oauth_clients',
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'client_name'   => $client_name,
				'redirect_uris' => wp_json_encode( array_map( 'esc_url_raw', $redirect_uris ) ),
				'is_static'     => 0,
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		return new WP_REST_Response(
			array(
				'client_id'                => $client_id,
				'client_secret'            => $client_secret,
				'client_id_issued_at'      => time(),
				'client_secret_expires_at' => 0,
				'redirect_uris'            => $redirect_uris,
				'client_name'              => $client_name,
				'token_endpoint_auth_method' => 'client_secret_post',
				'grant_types'               => array( 'authorization_code', 'refresh_token' ),
				'response_types'            => array( 'code' ),
			),
			201
		);
	}

	public function rest_token( WP_REST_Request $request ) {
		// This endpoint is unauthenticated by design (that's the entire
		// point of a token exchange), which makes it the natural target for
		// brute-forcing authorization codes or refresh tokens. Both are
		// long, random, single-use values, so brute-forcing one is already
		// computationally infeasible – this is defense in depth on top of
		// that, not the only thing standing between an attacker and a token.
		if ( ! WCOPS_Rate_Limiter::check( 'oauth_token', 30, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'error' => 'too_many_requests', 'error_description' => 'Too many token requests. Please try again later.' ), 429 );
		}

		$params = $request->get_params();

		// Some OAuth clients send client_id/client_secret via HTTP Basic
		// auth (RFC 6749 §2.3.1 client_secret_basic) instead of the POST
		// body. Support both so we're not just compatible with clients
		// that happen to use client_secret_post.
		$auth_header = $request->get_header( 'authorization' );
		if ( empty( $params['client_id'] ) && ! empty( $auth_header ) && stripos( $auth_header, 'Basic ' ) === 0 ) {
			$decoded = base64_decode( trim( substr( $auth_header, 6 ) ) ); // phpcs:ignore
			if ( false !== $decoded && strpos( $decoded, ':' ) !== false ) {
				list( $basic_client_id, $basic_client_secret ) = explode( ':', $decoded, 2 );
				$params['client_id']     = rawurldecode( $basic_client_id );
				$params['client_secret'] = rawurldecode( $basic_client_secret );
			}
		}

		$grant_type = $params['grant_type'] ?? '';

		if ( 'authorization_code' === $grant_type ) {
			return $this->token_from_auth_code( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->token_from_refresh( $params );
		}

		return new WP_REST_Response( array( 'error' => 'unsupported_grant_type' ), 400 );
	}

	private function token_from_auth_code( $params ) {
		global $wpdb;

		$code          = $params['code'] ?? '';
		$client_id     = $params['client_id'] ?? '';
		$redirect_uri  = $params['redirect_uri'] ?? '';
		$code_verifier = $params['code_verifier'] ?? '';

		$codes_table = $wpdb->prefix . 'wcops_oauth_codes';
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE code = %s", $code ) ); // phpcs:ignore

		if ( ! $row || $row->used || strtotime( $row->expires_at ) < time() ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'Code is invalid, used, or expired.' ), 400 );
		}

		if ( $row->client_id !== $client_id || $row->redirect_uri !== $redirect_uri ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'client_id or redirect_uri mismatch.' ), 400 );
		}

		$client_error = $this->authenticate_client( $client_id, $params['client_secret'] ?? '' );
		if ( $client_error ) {
			return $client_error;
		}

		// PKCE is mandatory (OAuth 2.1), not optional: every authorization
		// code issued by handle_consent() carries a code_challenge, so a
		// missing one here means the code was never legitimately issued by
		// this server, and a missing/mismatched verifier must always fail
		// closed rather than silently skipping verification.
		if ( empty( $row->code_challenge ) || 'S256' !== $row->code_challenge_method ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE (S256) is required for this authorization code.' ), 400 );
		}

		if ( '' === $code_verifier ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request', 'error_description' => 'code_verifier is required.' ), 400 );
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $row->code_challenge, $computed ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ), 400 );
		}

		// Mark the code used so it can't be replayed.
		$wpdb->update( $codes_table, array( 'used' => 1 ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response( $this->issue_tokens( $client_id, (int) $row->user_id ), 200 );
	}

	private function token_from_refresh( $params ) {
		global $wpdb;

		$refresh_token = $params['refresh_token'] ?? '';
		$client_id     = $params['client_id'] ?? '';
		$hash          = hash( 'sha256', $refresh_token );

		$tokens_table = $wpdb->prefix . 'wcops_oauth_tokens';
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tokens_table} WHERE refresh_token_hash = %s AND client_id = %s", $hash, $client_id ) ); // phpcs:ignore

		if ( ! $row || $row->revoked || strtotime( $row->refresh_expires_at ) < time() ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid, revoked, or expired.' ), 400 );
		}

		$client_error = $this->authenticate_client( $client_id, $params['client_secret'] ?? '' );
		if ( $client_error ) {
			return $client_error;
		}

		// Revoke the old token pair and issue a fresh one (rotation).
		$wpdb->update( $tokens_table, array( 'revoked' => 1 ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response( $this->issue_tokens( $client_id, (int) $row->user_id ), 200 );
	}

	private function issue_tokens( $client_id, $user_id ) {
		global $wpdb;

		$access_token  = wp_generate_password( 64, false, false );
		$refresh_token = wp_generate_password( 64, false, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_oauth_tokens',
			array(
				'access_token_hash'  => hash( 'sha256', $access_token ),
				'refresh_token_hash' => hash( 'sha256', $refresh_token ),
				'client_id'          => $client_id,
				'user_id'            => $user_id,
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL ),
				'revoked'            => 0,
				'created_at'         => current_time( 'mysql', true ),
			)
		);

		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh_token,
			'scope'         => 'mcp',
		);
	}

	/**
	 * Validate a Bearer access token. Returns the WP user ID it belongs to,
	 * or false if invalid/expired/revoked.
	 */
	public static function validate_access_token( $token ) {
		global $wpdb;

		if ( empty( $token ) ) {
			return false;
		}

		$hash  = hash( 'sha256', $token );
		$table = $wpdb->prefix . 'wcops_oauth_tokens';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE access_token_hash = %s", $hash ) ); // phpcs:ignore

		if ( ! $row || $row->revoked || strtotime( $row->access_expires_at ) < time() ) {
			return false;
		}

		return (int) $row->user_id;
	}

	/* -----------------------------------------------------------------
	 * Authorize + consent (interactive, HTML)
	 * ------------------------------------------------------------- */

	/**
	 * Only users holding the "Who can connect" capability (Settings >
	 * General, Administrators only by default) may approve a connection.
	 * Checked on both the consent screen and its form submission.
	 */
	private function require_connect_capability() {
		if ( current_user_can( WCOPS_Settings::instance()->get_connect_capability() ) ) {
			return;
		}
		wp_die(
			esc_html__( 'Your account is not allowed to connect AI apps to this site. Ask a site administrator to connect it, or to change "Who can connect" in the plugin settings.', 'windcodex-ops' ) .
				' <a href="' . esc_url( wp_logout_url( home_url( add_query_arg( array() ) ) ) ) . '">' . esc_html__( 'Log out and switch accounts', 'windcodex-ops' ) . '</a>',
			'MCP Connector',
			array( 'response' => 403 )
		);
	}

	public function handle_authorize() {
		if ( ! is_user_logged_in() ) {
			auth_redirect(); // Sends to wp-login.php and back here after login.
			exit;
		}

		$this->require_connect_capability();

		$client_id             = sanitize_text_field( $_GET['client_id'] ?? '' );      // phpcs:ignore
		$redirect_uri          = esc_url_raw( $_GET['redirect_uri'] ?? '' );            // phpcs:ignore
		$state                 = sanitize_text_field( $_GET['state'] ?? '' );           // phpcs:ignore
		$code_challenge        = sanitize_text_field( $_GET['code_challenge'] ?? '' );  // phpcs:ignore
		$code_challenge_method = sanitize_text_field( $_GET['code_challenge_method'] ?? 'S256' ); // phpcs:ignore

		$client = $this->find_client( $client_id );

		if ( ! $client || empty( $redirect_uri ) ) {
			wp_die( 'Invalid client or redirect_uri.', 'MCP Connector', array( 'response' => 400 ) );
		}

		// PKCE (S256) is mandatory (OAuth 2.1) – reject the request outright
		// rather than silently issuing a code that token_from_auth_code()
		// would later have to accept without verification.
		if ( '' === $code_challenge || 'S256' !== $code_challenge_method ) {
			wp_die( 'This authorization request is missing a required PKCE code_challenge (S256).', 'MCP Connector', array( 'response' => 400 ) );
		}

		// Static client accepts any redirect_uri (Claude's own callback URLs
		// vary and aren't practical to pre-register); dynamic clients must
		// match a URI they registered.
		if ( ! $client->is_static ) {
			$allowed = json_decode( $client->redirect_uris, true );
			if ( ! is_array( $allowed ) || ! in_array( $redirect_uri, $allowed, true ) ) {
				wp_die( 'redirect_uri does not match this client\'s registration.', 'MCP Connector', array( 'response' => 400 ) );
			}
		}

		$user           = wp_get_current_user();
		$settings       = WCOPS_Settings::instance();
		$site_name      = get_bloginfo( 'name' );
		$client_name    = $client->client_name ?: $client_id;
		// A generic "AI assistant" icon for any connecting client, same
		// selection rule the Connected Apps list already uses – there's no
		// per-platform logo bundled, just enough to tell OpenAI-family
		// clients apart from everything else.
		$client_icon    = ( false !== stripos( $client_name, 'gpt' ) || false !== stripos( $client_name, 'openai' ) ) ? 'brand-openai' : 'message-circle-2';
		$permissions    = $this->describe_permissions();
		$logout_url     = wp_logout_url( home_url( add_query_arg( array() ) ) );
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="utf-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title>Authorize <?php echo esc_html( $client_name ); ?></title>
			<?php
			ob_start();
			?>
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
					background: linear-gradient(160deg, #f4f6fb 0%, #eef1f8 100%);
					margin: 0;
					padding: 40px 16px;
					color: #1d2327;
				}
				.wcops-card {
					max-width: 440px;
					margin: 0 auto;
					background: #fff;
					border-radius: 16px;
					box-shadow: 0 12px 32px rgba(20, 30, 60, 0.10), 0 2px 6px rgba(20, 30, 60, 0.06);
					overflow: hidden;
				}
				.wcops-header {
					padding: 32px 32px 24px;
					text-align: center;
					border-bottom: 1px solid #eef0f4;
				}
				.wcops-avatars {
					display: flex;
					align-items: center;
					justify-content: center;
					gap: 14px;
					margin-bottom: 20px;
				}
				.wcops-avatar {
					width: 44px;
					height: 44px;
					border-radius: 50%;
					display: flex;
					align-items: center;
					justify-content: center;
					background: #E7F0FE;
					color: #2563EB;
				}
				.wcops-avatar svg { width: 22px; height: 22px; }
				.wcops-avatar.wcops-site {
					background: #F0F1F3;
					color: #23282D;
				}
				.wcops-link-icon {
					color: #b7bccb;
					font-size: 18px;
					line-height: 1;
				}
				.wcops-header h1 {
					font-size: 19px;
					font-weight: 600;
					margin: 0 0 6px;
					line-height: 1.4;
				}
				.wcops-header p {
					font-size: 14px;
					color: #6b7280;
					margin: 0;
					line-height: 1.5;
				}
				.wcops-body { padding: 24px 32px; }
				.wcops-body h2 {
					font-size: 13px;
					font-weight: 600;
					color: #4b5157;
					margin: 0 0 10px;
				}
				.wcops-permissions { list-style: none; margin: 0 0 16px; padding: 0; border: 1px solid #eef0f4; border-radius: 10px; }
				.wcops-permissions li {
					display: flex;
					align-items: center;
					gap: 12px;
					font-size: 14px;
					line-height: 1.4;
					padding: 12px 14px;
					color: #33383d;
					border-bottom: 1px solid #eef0f4;
				}
				.wcops-permissions li:last-child { margin-bottom: 0; border-bottom: 0; }
				.wcops-permission-icon {
					flex: none;
					width: 18px;
					height: 18px;
					color: #6b7280;
				}
				.wcops-asuser {
					margin-top: 8px;
					padding: 12px 14px;
					background: #f6f7fb;
					border-radius: 10px;
					font-size: 13px;
					color: #5c616b;
				}
				.wcops-asuser strong { color: #1d2327; }
				.wcops-actions { padding: 8px 32px 28px; display: flex; gap: 10px; }
				.wcops-btn {
					flex: 1;
					padding: 12px 16px;
					border-radius: 10px;
					font-size: 14px;
					font-weight: 600;
					border: none;
					cursor: pointer;
					transition: opacity 0.15s ease;
				}
				.wcops-btn:hover { opacity: 0.9; }
				.wcops-btn-approve { background: #185FA5; color: #fff; }
				.wcops-btn-approve:hover { background: #124879; }
				.wcops-btn-deny { background: #f1f2f4; color: #33383d; }

				.wcops-reassurance-note {
					background: #EEF6F0; color: #2F6F4E; border-radius: 8px;
					padding: 10px 12px; font-size: 12.5px; line-height: 1.5; margin: 4px 0 10px;
				}
				.wcops-revoke-hint { font-size: 12px; color: #6b7280; margin: 0; }
				.wcops-footer {
					text-align: center;
					padding: 16px 32px 28px;
					font-size: 12px;
					color: #9aa0ab;
					line-height: 1.6;
				}
				.wcops-footer a { color: #6b7280; }
			<?php
			$css = ob_get_clean();
			wp_register_style( 'wcops-oauth-consent', false, array(), WCOPS_VERSION );
			wp_enqueue_style( 'wcops-oauth-consent' );
			wp_add_inline_style( 'wcops-oauth-consent', $css );
			wp_print_styles( 'wcops-oauth-consent' );
			?>
		</head>
		<body>
			<div class="wcops-card">
				<div class="wcops-header">
					<div class="wcops-avatars">
						<div class="wcops-avatar"><?php WCOPS_Icons::render( $client_icon ); ?></div>
						<div class="wcops-link-icon">&#8646;</div>
						<div class="wcops-avatar wcops-site"><?php WCOPS_Icons::render( 'brand-wordpress' ); ?></div>
					</div>
					<h1><?php echo esc_html( $client_name ); ?> wants to connect<br />to <?php echo esc_html( $site_name ); ?> via WindCodex Ops</h1>
				</div>

				<div class="wcops-body">
					<h2>This will allow <?php echo esc_html( $client_name ); ?> to</h2>
					<ul class="wcops-permissions">
						<?php foreach ( $permissions as $permission ) : ?>
							<li>
								<?php WCOPS_Icons::render( $permission['icon'], 'wcops-permission-icon' ); ?>
								<span><?php echo esc_html( $permission['label'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="wcops-reassurance-note">
						Only groups you've enabled in settings are accessible. Every change can be undone for <?php echo (int) $settings->get_undo_window_hours(); ?> hours.
					</div>

					<p class="wcops-revoke-hint">You can revoke this access anytime from WindCodex Ops settings.</p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcops-actions">
					<?php wp_nonce_field( 'wcops_oauth_consent' ); ?>
					<input type="hidden" name="action" value="wcops_oauth_consent" />
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>" />
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>" />
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>" />
					<button type="submit" name="decision" value="deny" class="wcops-btn wcops-btn-deny">Cancel</button>
					<button type="submit" name="decision" value="approve" class="wcops-btn wcops-btn-approve">Authorize</button>
				</form>

				<div class="wcops-footer">
					Not <?php echo esc_html( $user->display_name ); ?>? <a href="<?php echo esc_url( $logout_url ); ?>">Log out and switch accounts</a>.
				</div>
			</div>

		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Turn the currently enabled tool groups into plain-language permission
	 * lines for the consent screen, so the person approving actually knows
	 * what they're granting rather than reading a generic sentence.
	 */
	/**
	 * Returns an array of ['label' => ..., 'risk' => ...]
	 * for every group actually available right now – driven entirely by
	 * WCOPS_Settings::group_metadata(), the same registry the Tools tab
	 * and onboarding wizard use, so this screen can never show something
	 * different from what's actually granted. Uses is_group_available(),
	 * not just is_group_enabled(), so a Pro group left "on" in storage
	 * from before a downgrade to Free doesn't get overclaimed here.
	 */
	/**
	 * Consolidated, plain-English permission lines for the consent
	 * screen – not one line per settings bundle (which could run to 8+
	 * lines), but natural categories grouped by what they let an AI
	 * actually do. Still driven entirely by which bundles are really
	 * enabled via is_group_available(), so it can't overclaim.
	 */
	private function describe_permissions() {
		$settings = WCOPS_Settings::instance();
		$bundles  = WCOPS_Settings::bundle_metadata();
		$on       = array();
		foreach ( $bundles as $key => $bundle ) {
			$all_available = true;
			foreach ( $bundle['groups'] as $real_group ) {
				if ( ! $settings->is_group_available( $real_group ) ) {
					$all_available = false;
					break;
				}
			}
			$on[ $key ] = $all_available;
		}

		// Icon per line reuses that bundle's own icon from bundle_metadata()
		// (the same one shown on the Tools tab) so this screen stays visually
		// consistent with the rest of the plugin rather than inventing a
		// separate icon vocabulary just for the consent screen.
		$lines = array();

		if ( $on['content'] || $on['media'] || $on['seo'] || $on['structure'] ) {
			$lines[] = array( 'label' => 'Read and edit content, media, and SEO settings', 'icon' => 'file-text' );
		}
		// Always shown – site status and the activity/undo log are
		// available regardless of which content bundles are toggled.
		$lines[] = array( 'label' => 'View site status and activity logs', 'icon' => 'activity' );

		if ( 1 === count( $lines ) ) {
			array_unshift( $lines, array( 'label' => 'Nothing else yet – no other tools are currently enabled in Settings -> WindCodex Ops.', 'icon' => 'info-circle' ) );
		}

		return $lines;
	}

	public function handle_consent() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'You must be logged in.', 'MCP Connector', array( 'response' => 401 ) );
		}

		$this->require_connect_capability();

		check_admin_referer( 'wcops_oauth_consent' );

		$client_id             = sanitize_text_field( $_POST['client_id'] ?? '' );      // phpcs:ignore
		$redirect_uri          = esc_url_raw( $_POST['redirect_uri'] ?? '' );            // phpcs:ignore
		$state                 = sanitize_text_field( $_POST['state'] ?? '' );           // phpcs:ignore
		$code_challenge        = sanitize_text_field( $_POST['code_challenge'] ?? '' );  // phpcs:ignore
		$code_challenge_method = sanitize_text_field( $_POST['code_challenge_method'] ?? 'S256' ); // phpcs:ignore
		$decision              = sanitize_text_field( $_POST['decision'] ?? '' );        // phpcs:ignore

		// The consent form re-submits client_id/redirect_uri as plain hidden
		// fields, so re-validate them here exactly as handle_authorize() did
		// – otherwise a tampered redirect_uri would send the auth code (or
		// the denial) to a URI the client never registered.
		$client = $this->find_client( $client_id );

		if ( ! $client || empty( $redirect_uri ) ) {
			wp_die( 'Invalid client or redirect_uri.', 'MCP Connector', array( 'response' => 400 ) );
		}

		if ( ! $client->is_static ) {
			$allowed = json_decode( $client->redirect_uris, true );
			if ( ! is_array( $allowed ) || ! in_array( $redirect_uri, $allowed, true ) ) {
				wp_die( 'redirect_uri does not match this client\'s registration.', 'MCP Connector', array( 'response' => 400 ) );
			}
		}

		// Re-validate PKCE here too, exactly as handle_authorize() required it
		// – the consent form resubmits it as a plain hidden field, and a code
		// issued without it would let token_from_auth_code() skip
		// verification entirely.
		if ( '' === $code_challenge || 'S256' !== $code_challenge_method ) {
			wp_die( 'This authorization request is missing a required PKCE code_challenge (S256).', 'MCP Connector', array( 'response' => 400 ) );
		}

		if ( 'approve' !== $decision ) {
			// $redirect_uri is a third-party OAuth callback validated above
			// against the client's registration, not a local admin URL, so
			// wp_safe_redirect()'s allowed-hosts model doesn't apply here.
			wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		global $wpdb;
		$code = wp_generate_password( 48, false, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'wcops_oauth_codes',
			array(
				'code'                  => $code,
				'client_id'             => $client_id,
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'used'                  => 0,
			)
		);

		// See the note above the denial-path redirect: $redirect_uri is
		// validated against the client's registration, so wp_safe_redirect()
		// would wrongly block this legitimate external OAuth callback.
		wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private function find_client( $client_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcops_oauth_clients';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ) ); // phpcs:ignore
	}

	/**
	 * Every client this server issues is confidential (always gets a
	 * client_secret at registration time – see rest_register_client() and
	 * create_manual_client()), so the token endpoint must authenticate it,
	 * not just trust whatever client_id is asserted in the request body.
	 * PKCE alone only protects the authorization code from interception; it
	 * doesn't prove the caller is the client the code/refresh token was
	 * actually issued to.
	 *
	 * Returns null if authentication succeeds, or a WP_REST_Response error to
	 * return immediately if it fails.
	 */
	private function authenticate_client( $client_id, $client_secret ) {
		$client = $this->find_client( $client_id );

		if ( ! $client ) {
			return new WP_REST_Response( array( 'error' => 'invalid_client', 'error_description' => 'Unknown client_id.' ), 401 );
		}

		if ( empty( $client->client_secret ) ) {
			// No secret on file for this client – nothing to check against.
			return null;
		}

		if ( ! is_string( $client_secret ) || '' === $client_secret || ! hash_equals( $client->client_secret, $client_secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_client', 'error_description' => 'client_secret is missing or incorrect.' ), 401 );
		}

		return null;
	}

	/**
	 * Revoke every issued token. Used by the "Revoke all OAuth access" admin button.
	 */
	public static function revoke_all_tokens() {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}wcops_oauth_tokens SET revoked = 1" ); // phpcs:ignore
	}
}
