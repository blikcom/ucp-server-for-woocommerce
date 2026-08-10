<?php
/**
 * Platform registry storage.
 *
 * @package UCPWS
 */

namespace UCPWS\Storage;

use UCPWS\Bootstrap\Installer;
use UCPWS\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of approved platforms.
 *
 * Each row binds one API key (stored as a SHA-256 hash) to exactly one
 * platform profile URL — the identity-binding check rejects requests whose
 * authenticated key does not match the UCP-Agent profile.
 */
class Platforms {

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_PLATFORMS;
	}

	/**
	 * Create a platform with a fresh API key.
	 *
	 * @param string $name        Display name.
	 * @param string $profile_url Platform profile URL the key is bound to.
	 * @return array{id: int, api_key: string}|null The id and the PLAINTEXT key (shown once), or null on failure.
	 */
	public function create( string $name, string $profile_url ): ?array {
		global $wpdb;

		$api_key = 'ucpws_pk_' . bin2hex( random_bytes( 24 ) );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'name'         => $name,
				'profile_url'  => $profile_url,
				'api_key_hash' => hash( 'sha256', $api_key ),
				'key_hint'     => substr( $api_key, 0, 12 ) . '…',
				'status'       => 'active',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return array(
			'id'      => (int) $wpdb->insert_id,
			'api_key' => $api_key,
		);
	}

	/**
	 * Find an active platform by plaintext API key.
	 *
	 * @param string $api_key Plaintext API key.
	 * @return object|null Row object or null.
	 */
	public function find_by_key( string $api_key ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE api_key_hash = %s AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				hash( 'sha256', $api_key )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Find an active platform by profile URL.
	 *
	 * @param string $profile_url Profile URL.
	 * @return object|null
	 */
	public function find_by_profile_url( string $profile_url ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE profile_url = %s AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$profile_url
			)
		);

		return $row ? $row : null;
	}

	/**
	 * All platforms (admin listing).
	 *
	 * @return array<int, object>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete a platform.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Toggle platform status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status `active` or `disabled`.
	 * @return bool
	 */
	public function set_status( int $id, string $status ): bool {
		global $wpdb;
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array( 'status' => 'active' === $status ? 'active' : 'disabled' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record that a platform was seen.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public function touch( int $id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
