<?php
/**
 * Idempotency key storage.
 *
 * @package UCPWS
 */

namespace UCPWS\Storage;

use UCPWS\Bootstrap\Installer;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Stores operation results keyed by (Idempotency-Key, scope).
 *
 * Scope is `{operation}:{resource-id}` so the same key value used for
 * different operations conflicts instead of replaying the wrong response.
 * Records are retained for at least 24h (default 48h) and replayed verbatim.
 */
class IdempotencyStore {

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_IDEMPOTENCY;
	}

	/**
	 * Canonical request hash.
	 *
	 * @param mixed $payload Request payload (decoded array or null).
	 * @return string SHA-256 hex.
	 */
	public function hash_payload( $payload ): string {
		$normalized = $this->normalize( $payload );
		return hash( 'sha256', (string) wp_json_encode( $normalized ) );
	}

	/**
	 * Look up a stored record.
	 *
	 * @param string $key   Idempotency key.
	 * @param string $scope Operation scope.
	 * @return array{request_hash: string, response_status: int, response_body: string}|null
	 */
	public function find( string $key, string $scope ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT request_hash, response_status, response_body FROM {$this->table()} WHERE idem_key = %s AND scope = %s AND expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$scope,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'request_hash'    => (string) $row['request_hash'],
			'response_status' => (int) $row['response_status'],
			'response_body'   => (string) $row['response_body'],
		);
	}

	/**
	 * Persist an operation result.
	 *
	 * @param string $key    Idempotency key.
	 * @param string $scope  Operation scope.
	 * @param string $hash   Request hash.
	 * @param int    $status Response HTTP status.
	 * @param string $body   Serialized response body (JSON).
	 * @return bool Whether the write succeeded.
	 */
	public function store( string $key, string $scope, string $hash, int $status, string $body ): bool {
		global $wpdb;

		$ttl = max( DAY_IN_SECONDS, Config::get_int( 'idempotency_ttl' ) );

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a fixed constant.
				"INSERT INTO {$this->table()} (idem_key, scope, request_hash, response_status, response_body, created_at, expires_at)
				 VALUES (%s, %s, %s, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE response_status = VALUES(response_status), response_body = VALUES(response_body)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$scope,
				$hash,
				$status,
				$body,
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() + $ttl )
			)
		);

		return false !== $result;
	}

	/**
	 * Purge expired records.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE expires_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Recursively key-sort arrays for canonical hashing.
	 *
	 * @param mixed $value Payload value.
	 * @return mixed
	 */
	private function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = $this->normalize( $item );
		}

		if ( ! $is_list ) {
			ksort( $normalized );
		}

		return $normalized;
	}
}
