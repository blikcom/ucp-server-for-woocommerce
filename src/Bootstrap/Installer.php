<?php
/**
 * Database schema installer.
 *
 * @package UCPWS
 */

namespace UCPWS\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/updates the plugin's custom tables via dbDelta.
 */
class Installer {

	public const DB_VERSION        = '1';
	public const OPTION_DB_VERSION = 'ucpws_db_version';

	/**
	 * Table name (unprefixed) for checkout sessions.
	 */
	public const TABLE_SESSIONS = 'ucpws_sessions';

	/**
	 * Table name (unprefixed) for idempotency records.
	 */
	public const TABLE_IDEMPOTENCY = 'ucpws_idempotency';

	/**
	 * Table name (unprefixed) for the platform registry.
	 */
	public const TABLE_PLATFORMS = 'ucpws_platforms';

	/**
	 * Run dbDelta for all plugin tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$sessions    = $wpdb->prefix . self::TABLE_SESSIONS;
		$idempotency = $wpdb->prefix . self::TABLE_IDEMPOTENCY;
		$platforms   = $wpdb->prefix . self::TABLE_PLATFORMS;

		$schema = "
CREATE TABLE {$sessions} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id VARCHAR(64) NOT NULL,
	order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	platform_id BIGINT UNSIGNED NULL,
	status VARCHAR(32) NOT NULL DEFAULT 'incomplete',
	currency VARCHAR(8) NOT NULL DEFAULT '',
	negotiation LONGTEXT NULL,
	state LONGTEXT NULL,
	created_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	expires_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY session_id (session_id),
	KEY order_id (order_id),
	KEY expires_at (expires_at)
) {$collate};
CREATE TABLE {$idempotency} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	idem_key VARCHAR(191) NOT NULL,
	scope VARCHAR(191) NOT NULL,
	request_hash CHAR(64) NOT NULL,
	response_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
	response_body LONGTEXT NULL,
	created_at DATETIME NOT NULL,
	expires_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY key_scope (idem_key(100),scope(80)),
	KEY expires_at (expires_at)
) {$collate};
CREATE TABLE {$platforms} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(191) NOT NULL DEFAULT '',
	profile_url VARCHAR(191) NOT NULL,
	api_key_hash CHAR(64) NOT NULL,
	key_hint VARCHAR(16) NOT NULL DEFAULT '',
	status VARCHAR(16) NOT NULL DEFAULT 'active',
	created_at DATETIME NOT NULL,
	last_seen_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY api_key_hash (api_key_hash),
	KEY profile_url (profile_url)
) {$collate};
";

		dbDelta( $schema );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Upgrade path: re-run dbDelta when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
