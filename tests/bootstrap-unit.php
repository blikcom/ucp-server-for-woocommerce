<?php
/**
 * Unit test bootstrap: no WordPress, minimal shims.
 *
 * @package UCPWS
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'UCPWS_VERSION', '0.1.0-test' );
define( 'UCPWS_UCP_VERSION', '2026-04-08' );
define( 'UCPWS_PLUGIN_FILE', dirname( __DIR__ ) . '/ucp-server-for-woocommerce.php' );
define( 'UCPWS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * In-memory options store for tests.
 *
 * @var array<string, mixed>
 */
$GLOBALS['ucpws_test_options'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return $GLOBALS['ucpws_test_options'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['ucpws_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['ucpws_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['ucpws_test_options'][ 'transient_' . $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['ucpws_test_options'][ 'transient_' . $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['ucpws_test_options'][ 'transient_' . $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ); // phpcs:ignore
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}
