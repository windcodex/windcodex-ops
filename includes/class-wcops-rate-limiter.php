<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal IP-based rate limiting for the plugin's public, unauthenticated
 * endpoints (OAuth token exchange, dynamic client registration, admin
 * access link consumption). These endpoints can't require auth by design –
 * that's the point of them – so they're the parts of the plugin most
 * exposed to automated abuse (brute-forcing tokens, registration spam,
 * scanning). This isn't a substitute for a real WAF, but it raises the
 * cost of casual abuse without adding any external dependency.
 */
class WCOPS_Rate_Limiter {

	/**
	 * Returns true if the action is currently allowed, and records this
	 * attempt. Returns false if the caller has exceeded the limit for the
	 * given window, in which case the caller should reject the request
	 * (e.g. HTTP 429) without doing any further work.
	 *
	 * @param string $bucket  Short identifier for what's being limited, e.g. 'oauth_token'.
	 * @param int    $limit   Max attempts allowed within the window.
	 * @param int    $window  Window length in seconds.
	 */
	public static function check( $bucket, $limit, $window ) {
		$ip  = self::get_ip();
		$key = 'wcops_rl_' . $bucket . '_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Best-effort seconds remaining until the given bucket's window resets
	 * and its counter starts fresh - used to give a precise "try again in
	 * N seconds" instead of a vague "wait a moment" when check() has just
	 * returned false for this bucket. Reads the transient's own timeout
	 * record directly, so it only works when transients are backed by the
	 * options table; returns null (no guess) on a site using an external
	 * object cache for transients, since a wrong number is worse than none.
	 */
	public static function seconds_until_reset( $bucket ) {
		$ip      = self::get_ip();
		$key     = 'wcops_rl_' . $bucket . '_' . md5( $ip );
		$timeout = get_option( '_transient_timeout_' . $key );

		if ( ! $timeout ) {
			return null;
		}

		$remaining = (int) $timeout - time();
		return $remaining > 0 ? $remaining : null;
	}

	private static function get_ip() {
		// REMOTE_ADDR is the one server-set value that can't be spoofed by
		// the client itself (unlike X-Forwarded-For, which a request can
		// set to anything). It won't reflect the real visitor IP behind a
		// proxy/load balancer that doesn't rewrite it, but that's an
		// acceptable tradeoff for a lightweight, dependency-free limiter.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}
