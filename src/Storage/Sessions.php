<?php
/**
 * Checkout session storage.
 *
 * @package UCPWS
 */

namespace UCPWS\Storage;

use UCPWS\Bootstrap\Installer;
use UCPWS\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Persists checkout sessions in the plugin's custom table.
 *
 * A session references the backing WooCommerce draft order (which owns all
 * money math) plus protocol state that WooCommerce has no model for: the
 * negotiation context, client-supplied identifiers, fulfillment option lists,
 * messages and instrument echoes.
 */
class Sessions {

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . Installer::TABLE_SESSIONS;
	}

	/**
	 * Create a session row.
	 *
	 * @param int                  $order_id    Backing WC order id.
	 * @param string               $currency    ISO currency.
	 * @param array<string, mixed> $negotiation Serialized negotiation context.
	 * @param array<string, mixed> $state       Session state.
	 * @param int                  $ttl         Lifetime in seconds.
	 * @param int|null             $platform_id Registered platform id.
	 * @return string|null New session id.
	 */
	public function create( int $order_id, string $currency, array $negotiation, array $state, int $ttl, ?int $platform_id = null ): ?string {
		global $wpdb;

		$session_id = Ids::prefixed( 'chk', 16 );
		$now        = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'session_id'  => $session_id,
				'order_id'    => $order_id,
				'platform_id' => $platform_id,
				'status'      => 'incomplete',
				'currency'    => $currency,
				'negotiation' => wp_json_encode( $negotiation ),
				'state'       => wp_json_encode( $state ),
				'created_at'  => $now,
				'updated_at'  => $now,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			)
		);

		return false === $inserted ? null : $session_id;
	}

	/**
	 * Load a session row.
	 *
	 * @param string $session_id Session id.
	 * @return array<string, mixed>|null
	 */
	public function find( string $session_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['negotiation'] = json_decode( (string) $row['negotiation'], true );
		$row['state']       = json_decode( (string) $row['state'], true );
		if ( ! is_array( $row['negotiation'] ) ) {
			$row['negotiation'] = array();
		}
		if ( ! is_array( $row['state'] ) ) {
			$row['state'] = array();
		}

		return $row;
	}

	/**
	 * Find a session by its backing order.
	 *
	 * @param int $order_id WC order id.
	 * @return array<string, mixed>|null
	 */
	public function find_by_order( int $order_id ): ?array {
		global $wpdb;

		$session_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT session_id FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		return is_string( $session_id ) ? $this->find( $session_id ) : null;
	}

	/**
	 * Update session status and state.
	 *
	 * @param string               $session_id Session id.
	 * @param string               $status     UCP checkout status.
	 * @param array<string, mixed> $state      Session state.
	 * @return void
	 */
	public function update( string $session_id, string $status, array $state ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'status'     => $status,
				'state'      => wp_json_encode( $state ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'session_id' => $session_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Purge expired, never-completed sessions.
	 *
	 * The backing draft orders are cleaned up by WooCommerce's own
	 * `woocommerce_cleanup_draft_orders` routine.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE expires_at <= %s AND status IN ('incomplete', 'ready_for_complete', 'requires_escalation', 'canceled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}
}
