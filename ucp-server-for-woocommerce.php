<?php
/**
 * Plugin Name:          UCP Server for WooCommerce
 * Plugin URI:           https://github.com/blikcom/ucp-server-for-woocommerce
 * Description:          Turns a WooCommerce shop into a Universal Commerce Protocol (UCP) business server: discovery profile, checkout, catalog and order capabilities over REST and MCP, signed lifecycle webhooks, and a pluggable payment handler architecture.
 * Version:              0.1.1
 * Requires at least:    6.6
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      10.9
 * Author:               UCP Server for WooCommerce Contributors
 * Text Domain:          ucp-server-for-woocommerce
 * Domain Path:          /languages
 * License:              GPL-3.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package UCPWS
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'UCPWS_VERSION' ) ) {
	// Another copy of the plugin is already loaded.
	return;
}

define( 'UCPWS_VERSION', '0.1.1' );
define( 'UCPWS_UCP_VERSION', '2026-04-08' );
define( 'UCPWS_PLUGIN_FILE', __FILE__ );
define( 'UCPWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Composer autoloader (dev installs). Distribution builds ship vendor/.
if ( is_readable( UCPWS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require UCPWS_PLUGIN_DIR . 'vendor/autoload.php';
}

if ( ! class_exists( \UCPWS\Plugin::class ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'UCP Server for WooCommerce: autoloader missing. Run `composer install` in the plugin directory (or use a release build).', 'ucp-server-for-woocommerce' );
			echo '</p></div>';
		}
	);
	return;
}

// Declare WooCommerce feature compatibility (HPOS).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( \UCPWS\Bootstrap\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \UCPWS\Bootstrap\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'UCP Server for WooCommerce requires WooCommerce to be installed and active.', 'ucp-server-for-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		\UCPWS\Plugin::instance()->init();
	},
	20
);
