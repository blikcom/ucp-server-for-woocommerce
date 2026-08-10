<?php
/**
 * Request rate limiting.
 *
 * @package UCPWS
 */

namespace UCPWS\Http;

use UCPWS\Protocol\UcpException;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window rate limiter backed by transients (object-cache friendly).
 */
class RateLimiter {

	/**
	 * Enforce the rate limit for an identity.
	 *
	 * @param string $identity Stable identity (platform id or client IP).
	 * @return void
	 * @throws UcpException 429 with Retry-After when the limit is exceeded.
	 */
	public function check( string $identity ): void {
		$limit  = Config::get_int( 'rate_limit' );
		$window = max( 1, Config::get_int( 'rate_limit_window' ) );

		/**
		 * Filters the rate limit for an identity. Return 0 to disable.
		 *
		 * @param int    $limit    Requests per window.
		 * @param string $identity Identity.
		 */
		$limit = (int) apply_filters( 'ucpws_rate_limit', $limit, $identity );

		if ( $limit <= 0 ) {
			return;
		}

		$key    = 'ucpws_rl_' . md5( $identity );
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || ( $bucket['reset'] ?? 0 ) <= time() ) {
			$bucket = array(
				'count' => 0,
				'reset' => time() + $window,
			);
		}

		++$bucket['count'];

		if ( $bucket['count'] > $limit ) {
			$retry_after = max( 1, (int) $bucket['reset'] - time() );
			throw UcpException::transport( 'rate_limited', 'Too many requests.', 429 )
				->with_headers( array( 'Retry-After' => (string) $retry_after ) );
		}

		set_transient( $key, $bucket, $window );
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	public function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? 'unknown' : $ip;
	}
}
