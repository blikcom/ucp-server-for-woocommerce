<?php
/**
 * Customer address book.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-address storage per customer (WooCommerce natively stores only one
 * shipping address per user, UCP flows need an address book).
 *
 * Addresses are stored in user meta as UCP postal_address objects plus an id.
 */
class AddressBook {

	private const META_KEY = 'ucpws_address_book';

	/**
	 * Addresses for a buyer email.
	 *
	 * @param string $email Buyer email.
	 * @return array<int, array<string, string>>
	 */
	public function get( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}

		$book = get_user_meta( $user->ID, self::META_KEY, true );
		return is_array( $book ) ? array_values( $book ) : array();
	}

	/**
	 * Save an address for a buyer, deduplicating by content.
	 *
	 * @param string               $email   Buyer email.
	 * @param array<string, mixed> $address UCP postal address (id optional).
	 * @return string The stored address id (existing id when deduplicated).
	 */
	public function save( string $email, array $address ): string {
		$requested_id = isset( $address['id'] ) && is_string( $address['id'] ) && '' !== $address['id']
			? $address['id']
			: 'dest_' . substr( md5( (string) wp_json_encode( $address ) ), 0, 10 );

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return $requested_id;
		}

		$book = get_user_meta( $user->ID, self::META_KEY, true );
		if ( ! is_array( $book ) ) {
			$book = array();
		}

		$fingerprint = $this->fingerprint( $address );
		foreach ( $book as $existing ) {
			if ( is_array( $existing ) && $this->fingerprint( $existing ) === $fingerprint ) {
				return (string) ( $existing['id'] ?? $requested_id );
			}
		}

		$stored       = $this->pick_fields( $address );
		$stored['id'] = $requested_id;
		$book[]       = $stored;
		update_user_meta( $user->ID, self::META_KEY, $book );

		return $requested_id;
	}

	/**
	 * Content fingerprint for deduplication.
	 *
	 * @param array<string, mixed> $address Address.
	 * @return string
	 */
	private function fingerprint( array $address ): string {
		return strtolower(
			implode(
				'|',
				array(
					trim( (string) ( $address['street_address'] ?? '' ) ),
					trim( (string) ( $address['address_locality'] ?? '' ) ),
					trim( (string) ( $address['address_region'] ?? '' ) ),
					trim( (string) ( $address['postal_code'] ?? '' ) ),
					trim( (string) ( $address['address_country'] ?? '' ) ),
				)
			)
		);
	}

	/**
	 * Keep only postal-address fields.
	 *
	 * @param array<string, mixed> $address Raw address.
	 * @return array<string, string>
	 */
	private function pick_fields( array $address ): array {
		$fields = array( 'street_address', 'extended_address', 'address_locality', 'address_region', 'address_country', 'postal_code', 'first_name', 'last_name', 'phone_number', 'full_name' );
		$picked = array();
		foreach ( $fields as $field ) {
			if ( isset( $address[ $field ] ) && is_string( $address[ $field ] ) && '' !== $address[ $field ] ) {
				$picked[ $field ] = sanitize_text_field( $address[ $field ] );
			}
		}
		return $picked;
	}
}
