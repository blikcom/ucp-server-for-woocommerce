<?php
/**
 * Plugin deactivation.
 *
 * @package UCPWS
 */

namespace UCPWS\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation routine.
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( 'ucpws_cleanup' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'ucpws_cleanup' );
		}
	}
}
