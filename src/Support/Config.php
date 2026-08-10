<?php
/**
 * Configuration access with constant/env/option precedence.
 *
 * @package UCPWS
 */

namespace UCPWS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Central configuration reader.
 *
 * Every option can be overridden without touching the database, which keeps
 * Bedrock-style (composer-managed, env-driven) installs first-class:
 *
 *   1. PHP constant  UCPWS_{KEY}         (e.g. define( 'UCPWS_RATE_LIMIT', 600 ) in config/application.php)
 *   2. Env variable  UCPWS_{KEY}         (e.g. UCPWS_RATE_LIMIT=600 in .env)
 *   3. Option        ucpws_settings[key] (managed on the WooCommerce > UCP Server admin screen)
 *   4. Built-in default
 *
 * The resolved value is passed through the `ucpws_config_{key}` filter.
 */
final class Config {

	public const OPTION_SETTINGS = 'ucpws_settings';

	/**
	 * Built-in defaults.
	 *
	 * @var array<string, mixed>
	 */
	private static $defaults = array(
		'enabled'                  => true,
		// `registry` requires a registered platform API key on every request and
		// binds it to the platform's profile URL. `open` accepts any caller
		// (spec-permitted "open API" posture; required for the conformance suite).
		'auth_mode'                => 'registry',
		// `strict` implements the full spec discovery/negotiation error paths
		// (invalid_profile_url 400, profile_unreachable 424, profile_malformed 422,
		// capabilities_incompatible 200). `lenient` mirrors the reference server:
		// profile fetch problems are logged and the request proceeds with the
		// business's own capability set.
		'negotiation_mode'         => 'strict',
		// Dev/test escape hatches. NEVER enable in production.
		'allow_insecure_profiles'  => false,
		'allow_private_hosts'      => false,
		'dev_url_rewrites'         => '',
		// Shared secret for the /testing/simulate-shipping endpoint. Disabled
		// (403) when empty. Test environments only.
		'simulation_secret'        => '',
		// Registers the bundled mock payment handler. NON-PRODUCTION: for demos,
		// tests and the conformance suite only.
		'enable_mock_handler'      => false,
		// JSON Schema validation of inbound requests / outbound responses.
		'validate_requests'        => true,
		'validate_responses'       => true,
		// Rate limiting (requests per window, per platform/IP).
		'rate_limit'               => 300,
		'rate_limit_window'        => 60,
		// Platform profile fetching.
		'profile_cache_ttl'        => 300,
		'profile_timeout'          => 5,
		'profile_max_bytes'        => 262144,
		// Fixed discovery footprint for unrecognized platforms.
		'discovery_budget'         => 20,
		'discovery_budget_window'  => 60,
		'discovery_backoff'        => 120,
		// Checkout session TTL (seconds). Spec default is 6 hours.
		'session_ttl'              => 21600,
		// Idempotency record retention (seconds). Spec minimum is 24h.
		'idempotency_ttl'          => 172800,
		// Webhook delivery.
		'webhook_max_attempts'     => 6,
		'webhook_timeout'          => 10,
		// Discovery profile response caching.
		'profile_response_max_age' => 300,
	);

	/**
	 * Read a configuration value.
	 *
	 * @param string $key Configuration key (snake_case).
	 * @return mixed
	 */
	public static function get( string $key ) {
		$default = self::$defaults[ $key ] ?? null;
		$value   = $default;

		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( is_array( $settings ) && array_key_exists( $key, $settings ) && '' !== $settings[ $key ] ) {
			$value = $settings[ $key ];
		}

		$env = getenv( 'UCPWS_' . strtoupper( $key ) );
		if ( false !== $env && '' !== $env ) {
			$value = $env;
		}

		$constant = 'UCPWS_' . strtoupper( $key );
		if ( defined( $constant ) ) {
			$value = constant( $constant );
		}

		$value = self::coerce( $value, $default );

		/**
		 * Filters a resolved configuration value.
		 *
		 * @param mixed $value Resolved value.
		 */
		return apply_filters( 'ucpws_config_' . $key, $value );
	}

	/**
	 * Read a boolean configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return bool
	 */
	public static function get_bool( string $key ): bool {
		return (bool) self::get( $key );
	}

	/**
	 * Read an integer configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return int
	 */
	public static function get_int( string $key ): int {
		return (int) self::get( $key );
	}

	/**
	 * Coerce env-derived strings to the default's type.
	 *
	 * @param mixed $value         Raw value.
	 * @param mixed $default_value Default value used to infer the type.
	 * @return mixed
	 */
	private static function coerce( $value, $default_value ) {
		if ( is_bool( $default_value ) && is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		if ( is_int( $default_value ) && is_string( $value ) && is_numeric( $value ) ) {
			return (int) $value;
		}
		return $value;
	}

	/**
	 * All defaults (used by the settings screen).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::$defaults;
	}
}
