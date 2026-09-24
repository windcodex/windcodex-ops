<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the MCP endpoint and speaks JSON-RPC 2.0 over it (Streamable
 * HTTP transport – a single POST endpoint, no SSE stream). This is enough
 * for Claude to add it as a custom connector. If you later submit to the
 * Connectors Directory, note the directory's transport/auth requirements
 * in the plugin README before submitting.
 */
class WCOPS_Server {

	private static $instance = null;

	/** @var WCOPS_Settings */
	private $settings;

	/** @var WCOPS_Tools */
	private $tools;

	/** @var int|null WP user ID resolved from the current request's OAuth token. */
	private $resolved_user_id = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = WCOPS_Settings::instance();
		$this->tools    = new WCOPS_Tools( $this->settings );

		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		register_rest_route(
			WCOPS_REST_NAMESPACE,
			WCOPS_REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Auth gate for every call to the MCP endpoint. Requires a valid OAuth
	 * Bearer access token, which resolves to the WP user who actually
	 * logged in and approved the consent screen.
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! $this->settings->is_enabled() ) {
			return new WP_Error( 'wcops_disabled', 'The MCP connector is currently disabled in WordPress admin.', array( 'status' => 503 ) );
		}

		$header = $request->get_header( 'authorization' );
		$token  = '';

		if ( ! empty( $header ) && stripos( $header, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $header, 7 ) );
		}

		$oauth_user_id = ! empty( $token ) ? WCOPS_OAuth::validate_access_token( $token ) : false;

		if ( $oauth_user_id ) {
			// The token's user must still hold the "Who can connect"
			// capability, so a demoted or deleted account's existing
			// connections stop working immediately.
			if ( ! user_can( $oauth_user_id, $this->settings->get_connect_capability() ) ) {
				return new WP_Error( 'wcops_forbidden', 'The WordPress account behind this connection is no longer allowed to use this connector.', array( 'status' => 403 ) );
			}
			$this->resolved_user_id = $oauth_user_id;
			return true;
		}

		// Tell the client where to find the authorization server so a
		// compliant MCP client can discover OAuth automatically instead of
		// showing a generic "couldn't determine settings" error. This
		// points to a plugin-specific discovery path (not the generic
		// /.well-known/oauth-protected-resource) so it can't be answered
		// by a different WindCodex plugin active on the same site.
		header( 'WWW-Authenticate: Bearer resource_metadata="' . esc_url_raw( WCOPS_OAuth::protected_resource_metadata_url() ) . '"' );

		return new WP_Error( 'wcops_unauthorized', 'Missing or invalid OAuth access token.', array( 'status' => 401 ) );
	}

	/**
	 * Handle an incoming JSON-RPC 2.0 request.
	 */
	public function handle_request( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! isset( $body['method'] ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request' );
		}

		$id     = $body['id'] ?? null;
		$method = $body['method'];
		$params = $body['params'] ?? array();

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result( $id, $this->handle_initialize() );

			case 'ping':
				return $this->rpc_result( $id, new stdClass() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $this->tools->get_tool_definitions() ) );

			case 'tools/call':
				$name      = $params['name'] ?? '';
				$arguments = $params['arguments'] ?? array();

				// Real, tier-driven enforcement – not just a number shown
				// in Settings. The bucket is keyed per user, since the
				// limit is meant to bound one connected account's call
				// rate, not the whole site's traffic.
				$per_minute_limit = $this->settings->get_requests_per_minute_limit();
				$rate_bucket      = 'tools_call_' . $this->resolved_user_id;
				if ( ! WCOPS_Rate_Limiter::check( $rate_bucket, $per_minute_limit, MINUTE_IN_SECONDS ) ) {
					$retry_after = WCOPS_Rate_Limiter::seconds_until_reset( $rate_bucket );
					$wait_text   = $retry_after ? "Wait about {$retry_after} seconds and try again." : 'Wait a moment and try again.';
					return $this->rpc_result(
						$id,
						array(
							'content' => array( array( 'type' => 'text', 'text' => "Rate limit exceeded ({$per_minute_limit} requests/minute on the current plan). {$wait_text}" ) ),
							'isError' => true,
						)
					);
				}

				$result = $this->tools->call( $name, $arguments, $this->resolved_user_id );
				update_option( 'wcops_last_activity', current_time( 'mysql', true ) );

				// Every call gets logged, but deliberately without the raw
				// arguments or result text – some tool results legitimately
				// contain sensitive data (emails, license keys, customer
				// info), so the audit trail records *what ran and whether
				// it succeeded*, not the content of what moved.
				$succeeded = empty( $result['isError'] );
				if ( $this->settings->get( 'log_ai_activity', true ) ) {
					WCOPS_Activity_Log::record( $this->resolved_user_id, $name, $succeeded ? 'Completed' : 'Failed', $succeeded );
				}

				return $this->rpc_result( $id, $result );

			default:
				return $this->rpc_error( $id, -32601, "Method not found: {$method}" );
		}
	}

	private function handle_initialize() {
		return array(
			'protocolVersion' => '2025-06-18',
			'serverInfo'      => array(
				'name'    => get_bloginfo( 'name' ) . ' (WindCodex Ops)',
				'version' => WCOPS_VERSION,
			),
			'capabilities'    => array(
				'tools' => new stdClass(),
			),
		);
	}

	private function rpc_result( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	private function rpc_error( $id, $code, $message ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			200
		);
	}

	/**
	 * Convenience accessor used by the admin screen to show the full URL.
	 */
	public function get_endpoint_url() {
		return rest_url( WCOPS_REST_NAMESPACE . WCOPS_REST_ROUTE );
	}
}
