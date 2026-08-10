<?php
/**
 * Platform profile fetching with caching and a fixed discovery budget.
 *
 * @package UCPWS
 */

namespace UCPWS\Negotiation;

use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and caches platform discovery profiles.
 *
 * Spec obligations implemented here:
 *  - HTTPS-only profile URLs (dev override: `allow_insecure_profiles`).
 *  - Redirects are never followed.
 *  - Connect/response timeouts and a response size cap.
 *  - Cache with a 60 second TTL floor regardless of origin headers.
 *  - Fixed discovery footprint: a global fetch budget per window plus negative
 *    caching/backoff for persistently failing origins. Registered platforms
 *    (see Platforms registry) are exempt from the budget.
 */
class ProfileFetcher {

	private const CACHE_PREFIX    = 'ucpws_profile_';
	private const NEGATIVE_PREFIX = 'ucpws_profile_neg_';
	private const BUDGET_KEY      = 'ucpws_discovery_budget';

	/**
	 * Fetch (or serve from cache) a platform profile.
	 *
	 * @param string $url            Profile URL.
	 * @param bool   $budget_exempt  Whether this origin skips the discovery budget (registered platforms).
	 * @return array<string, mixed> Decoded profile document.
	 * @throws UcpException On discovery failures: profile_unreachable (424), profile_malformed (422), invalid_profile_url (400).
	 */
	public function fetch( string $url, bool $budget_exempt = false ): array {
		$cache_key = self::CACHE_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$negative_key = self::NEGATIVE_PREFIX . md5( $url );
		if ( false !== get_transient( $negative_key ) ) {
			throw UcpException::transport(
				ErrorCodes::PROFILE_UNREACHABLE,
				'Platform profile fetch is temporarily suspended after repeated failures (backoff active).',
				424
			);
		}

		if ( ! $budget_exempt ) {
			$this->consume_budget();
		}

		$fetch_url = $this->apply_dev_rewrites( $url );

		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- profile fetch is core protocol behavior.
			$fetch_url,
			array(
				'timeout'             => Config::get_int( 'profile_timeout' ),
				'redirection'         => 0,
				'reject_unsafe_urls'  => ! Config::get_bool( 'allow_private_hosts' ),
				'limit_response_size' => Config::get_int( 'profile_max_bytes' ),
				'headers'             => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->record_failure( $negative_key );
			throw UcpException::transport(
				ErrorCodes::PROFILE_UNREACHABLE,
				'Unable to fetch platform profile: ' . $response->get_error_message(),
				424
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 300 && $status < 400 ) {
			$this->record_failure( $negative_key );
			throw UcpException::transport(
				ErrorCodes::PROFILE_UNREACHABLE,
				'Platform profile URLs must not redirect (got HTTP ' . $status . ').',
				424
			);
		}
		if ( 200 !== $status ) {
			$this->record_failure( $negative_key );
			throw UcpException::transport(
				ErrorCodes::PROFILE_UNREACHABLE,
				'Unable to fetch platform profile: HTTP ' . $status . '.',
				424
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['ucp'] ) || ! is_array( $decoded['ucp'] ) ) {
			// Malformed content is cached negatively too: refetching won't fix it
			// within the backoff window and the budget must stay bounded.
			$this->record_failure( $negative_key );
			throw UcpException::transport(
				ErrorCodes::PROFILE_MALFORMED,
				'Platform profile is not a valid UCP profile document (missing `ucp` object).',
				422
			);
		}

		if ( empty( $decoded['ucp']['version'] ) || ! is_string( $decoded['ucp']['version'] ) ) {
			$this->record_failure( $negative_key );
			throw UcpException::transport(
				ErrorCodes::PROFILE_MALFORMED,
				'Platform profile is missing `ucp.version`.',
				422
			);
		}

		$ttl = $this->cache_ttl( $response );
		set_transient( $cache_key, $decoded, $ttl );
		delete_transient( $negative_key );

		return $decoded;
	}

	/**
	 * Cache TTL honoring origin Cache-Control with a 60s floor.
	 *
	 * @param array<string, mixed> $response HTTP response array.
	 * @return int Seconds.
	 */
	private function cache_ttl( $response ): int {
		$ttl           = Config::get_int( 'profile_cache_ttl' );
		$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );

		if ( is_string( $cache_control ) && preg_match( '/max-age=(\d+)/i', $cache_control, $matches ) ) {
			$ttl = (int) $matches[1];
		}

		return max( 60, $ttl );
	}

	/**
	 * Enforce the global discovery fetch budget.
	 *
	 * @return void
	 * @throws UcpException 429 with Retry-After when the budget is exhausted.
	 */
	private function consume_budget(): void {
		$window = max( 1, Config::get_int( 'discovery_budget_window' ) );
		$budget = max( 1, Config::get_int( 'discovery_budget' ) );

		$bucket = get_transient( self::BUDGET_KEY );
		if ( ! is_array( $bucket ) || ( $bucket['reset'] ?? 0 ) <= time() ) {
			$bucket = array(
				'count' => 0,
				'reset' => time() + $window,
			);
		}

		if ( $bucket['count'] >= $budget ) {
			$retry_after = max( 1, (int) $bucket['reset'] - time() );
			throw UcpException::transport(
				ErrorCodes::PROFILE_UNREACHABLE,
				'Discovery budget exhausted; retry later.',
				424
			)->with_headers( array( 'Retry-After' => (string) $retry_after ) );
		}

		++$bucket['count'];
		set_transient( self::BUDGET_KEY, $bucket, $window );
	}

	/**
	 * Record a fetch failure for negative caching/backoff.
	 *
	 * @param string $negative_key Transient key.
	 * @return void
	 */
	private function record_failure( string $negative_key ): void {
		set_transient( $negative_key, 1, max( 1, Config::get_int( 'discovery_backoff' ) ) );
	}

	/**
	 * Apply the dev-only URL rewrite map (docker/wp-env networking).
	 *
	 * Reads a JSON object of prefix => replacement from the `dev_url_rewrites`
	 * config key. Never active unless explicitly configured; intended solely for
	 * containerized test environments where the platform's `localhost` URLs
	 * are not resolvable from inside the WordPress container.
	 *
	 * @param string $url Original URL.
	 * @return string
	 */
	public function apply_dev_rewrites( string $url ): string {
		$raw = Config::get( 'dev_url_rewrites' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $url;
		}

		$map = json_decode( $raw, true );
		if ( ! is_array( $map ) ) {
			return $url;
		}

		foreach ( $map as $prefix => $replacement ) {
			if ( is_string( $prefix ) && is_string( $replacement ) && 0 === strpos( $url, $prefix ) ) {
				return $replacement . substr( $url, strlen( $prefix ) );
			}
		}

		return $url;
	}
}
