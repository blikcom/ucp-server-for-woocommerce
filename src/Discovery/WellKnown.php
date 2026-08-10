<?php
/**
 * /.well-known/ucp endpoint.
 *
 * @package UCPWS
 */

namespace UCPWS\Discovery;

use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the business discovery profile at the site root.
 *
 * Uses a rewrite rule so the profile is available at
 * `https://shop.example/.well-known/ucp` regardless of the REST prefix. The
 * same document is also exposed at `wp-json/ucp/v1/.well-known/ucp` (see
 * RestServer) for consumers that resolve it relative to the REST endpoint.
 */
class WellKnown {

	private const QUERY_VAR = 'ucpws_well_known';

	/**
	 * Profile builder.
	 *
	 * @var ProfileBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param ProfileBuilder $builder Profile builder.
	 */
	public function __construct( ProfileBuilder $builder ) {
		$this->builder = $builder;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Register the rewrite rule (also called on activation before flushing).
	 *
	 * @return void
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^\.well-known/ucp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Expose the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the profile when the well-known route matched.
	 *
	 * @param \WP $wp WP environment instance.
	 * @return void
	 */
	public function maybe_serve( $wp ): void {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$this->send_headers();

		echo wp_json_encode( $this->builder->build(), JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Emit the spec-required response headers.
	 *
	 * Cache-Control MUST be `public` with a max-age of at least 60 and MUST NOT
	 * include private/no-store/no-cache.
	 *
	 * @return void
	 */
	public function send_headers(): void {
		$max_age = max( 60, Config::get_int( 'profile_response_max_age' ) );

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=' . $max_age );
			header( 'X-Robots-Tag: noindex' );
		}
	}

	/**
	 * Cache-Control value for REST responses serving the profile.
	 *
	 * @return string
	 */
	public static function cache_control(): string {
		return 'public, max-age=' . max( 60, Config::get_int( 'profile_response_max_age' ) );
	}
}
