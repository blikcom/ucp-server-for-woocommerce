<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's tables, options and scheduled events. Set the
 * UCPWS_PRESERVE_DATA constant (or the ucpws_preserve_data_on_uninstall
 * filter) to keep data across uninstalls.
 *
 * @package UCPWS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'UCPWS_PRESERVE_DATA' ) && UCPWS_PRESERVE_DATA ) {
	return;
}

/**
 * Filters whether uninstall should preserve plugin data.
 *
 * @param bool $preserve Default false.
 */
if ( apply_filters( 'ucpws_preserve_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;

// Custom tables.
foreach ( array( 'ucpws_sessions', 'ucpws_idempotency', 'ucpws_platforms' ) as $ucpws_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $ucpws_table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
}

// Options.
delete_option( 'ucpws_settings' );
delete_option( 'ucpws_signing_keys' );
delete_option( 'ucpws_db_version' );

// Transients (profile cache, rate limit buckets, discovery budget).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ucpws\_%' OR option_name LIKE '\_transient\_timeout\_ucpws\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

// Cron.
wp_clear_scheduled_hook( 'ucpws_cleanup' );

// Address books (user meta).
delete_metadata( 'user', 0, 'ucpws_address_book', '', true );
