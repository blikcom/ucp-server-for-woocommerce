<?php
/**
 * Plugin activation.
 *
 * @package UCPWS
 */

namespace UCPWS\Bootstrap;

use UCPWS\Discovery\WellKnown;
use UCPWS\Security\SigningKeys;

defined( 'ABSPATH' ) || exit;

/**
 * Activation routine: tables, signing key, rewrite rules, cron.
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Installer::install();

		( new SigningKeys() )->ensure_key();

		WellKnown::register_rewrite();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'ucpws_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ucpws_cleanup' );
		}
	}
}
