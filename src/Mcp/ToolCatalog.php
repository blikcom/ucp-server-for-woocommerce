<?php
/**
 * MCP tool definitions.
 *
 * @package UCPWS
 */

namespace UCPWS\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Static catalog of the UCP shopping tools this server implements, derived
 * from the official OpenRPC binding (mcp.openrpc.json). Cart tools are not
 * listed because the cart capability is not implemented.
 */
final class ToolCatalog {

	/**
	 * Tool names that mutate state (idempotency applies).
	 *
	 * @var string[]
	 */
	private const MUTATING = array( 'create_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout' );

	/**
	 * Whether a tool exists.
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	public static function exists( string $tool ): bool {
		foreach ( self::tools() as $definition ) {
			if ( $definition['name'] === $tool ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a tool mutates state.
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	public static function is_mutating( string $tool ): bool {
		return in_array( $tool, self::MUTATING, true );
	}

	/**
	 * The tools/list payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tools(): array {
		$schema_base = 'https://ucp.dev/' . UCPWS_UCP_VERSION . '/schemas/shopping';

		$meta = array(
			'type'                 => 'object',
			'description'          => 'Request metadata. Maps to the HTTP UCP-Agent / Idempotency-Key headers.',
			'required'             => array( 'ucp-agent' ),
			'additionalProperties' => true,
			'properties'           => array(
				'ucp-agent'       => array(
					'type'       => 'object',
					'required'   => array( 'profile' ),
					'properties' => array(
						'profile' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'URL to the platform UCP profile document.',
						),
					),
				),
				'idempotency-key' => array(
					'type'        => 'string',
					'format'      => 'uuid',
					'description' => 'Unique key for retry safety.',
				),
			),
		);

		$meta_with_idem             = $meta;
		$meta_with_idem['required'] = array( 'ucp-agent', 'idempotency-key' );

		$loose_object = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);

		$string = array( 'type' => 'string' );

		$checkout_output = array( '$ref' => $schema_base . '/checkout.json' );
		$order_output    = array( '$ref' => $schema_base . '/order.json' );

		return array(
			array(
				'name'         => 'create_checkout',
				'description'  => 'Create a checkout. Create a new checkout session.',
				'inputSchema'  => self::input(
					array(
						'meta'     => $meta,
						'checkout' => $loose_object,
					),
					array( 'meta', 'checkout' )
				),
				'outputSchema' => $checkout_output,
				'annotations'  => array( 'openWorldHint' => true ),
			),
			array(
				'name'         => 'get_checkout',
				'description'  => 'Get checkout. Returns the current state of a checkout session.',
				'inputSchema'  => self::input(
					array(
						'meta' => $meta,
						'id'   => $string,
					),
					array( 'meta', 'id' )
				),
				'outputSchema' => $checkout_output,
				'annotations'  => array(
					'readOnlyHint'  => true,
					'openWorldHint' => true,
				),
			),
			array(
				'name'         => 'update_checkout',
				'description'  => 'Update checkout. Full replacement of the checkout session state.',
				'inputSchema'  => self::input(
					array(
						'meta'     => $meta,
						'id'       => $string,
						'checkout' => $loose_object,
					),
					array( 'meta', 'id', 'checkout' )
				),
				'outputSchema' => $checkout_output,
				'annotations'  => array( 'openWorldHint' => true ),
			),
			array(
				'name'         => 'complete_checkout',
				'description'  => 'Complete checkout and place order.',
				'inputSchema'  => self::input(
					array(
						'meta'     => $meta_with_idem,
						'id'       => $string,
						'checkout' => $loose_object,
					),
					array( 'meta', 'id', 'checkout' )
				),
				'outputSchema' => $checkout_output,
				'annotations'  => array(
					'idempotentHint' => true,
					'openWorldHint'  => true,
				),
			),
			array(
				'name'         => 'cancel_checkout',
				'description'  => 'Cancel checkout.',
				'inputSchema'  => self::input(
					array(
						'meta' => $meta_with_idem,
						'id'   => $string,
					),
					array( 'meta', 'id' )
				),
				'outputSchema' => $checkout_output,
				'annotations'  => array(
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => true,
				),
			),
			array(
				'name'         => 'search_catalog',
				'description'  => 'Search for products in the catalog. Search for products using query text, filters, and pagination.',
				'inputSchema'  => self::input(
					array(
						'meta'    => $meta,
						'catalog' => array( '$ref' => $schema_base . '/catalog_search.json#/$defs/search_request' ),
					),
					array( 'meta', 'catalog' )
				),
				'outputSchema' => array( '$ref' => $schema_base . '/catalog_search.json#/$defs/search_response' ),
				'annotations'  => array(
					'readOnlyHint'  => true,
					'openWorldHint' => true,
				),
			),
			array(
				'name'         => 'lookup_catalog',
				'description'  => 'Batch lookup products or variants by identifier.',
				'inputSchema'  => self::input(
					array(
						'meta'    => $meta,
						'catalog' => array( '$ref' => $schema_base . '/catalog_lookup.json#/$defs/lookup_request' ),
					),
					array( 'meta', 'catalog' )
				),
				'outputSchema' => array( '$ref' => $schema_base . '/catalog_lookup.json#/$defs/lookup_response' ),
				'annotations'  => array(
					'readOnlyHint'  => true,
					'openWorldHint' => true,
				),
			),
			array(
				'name'         => 'get_product',
				'description'  => 'Get product details. Retrieve complete product detail by identifier with optional interactive option selection.',
				'inputSchema'  => self::input(
					array(
						'meta'    => $meta,
						'catalog' => array( '$ref' => $schema_base . '/catalog_lookup.json#/$defs/get_product_request' ),
					),
					array( 'meta', 'catalog' )
				),
				'outputSchema' => array( '$ref' => $schema_base . '/catalog_lookup.json#/$defs/get_product_response' ),
				'annotations'  => array(
					'readOnlyHint'  => true,
					'openWorldHint' => true,
				),
			),
			array(
				'name'         => 'get_order',
				'description'  => 'Get order. Get the current state of an order. Returns the full order entity as a current-state snapshot.',
				'inputSchema'  => self::input(
					array(
						'meta' => $meta,
						'id'   => array(
							'type'        => 'string',
							'description' => 'The unique identifier of the order.',
						),
					),
					array( 'meta', 'id' )
				),
				'outputSchema' => $order_output,
				'annotations'  => array(
					'readOnlyHint'  => true,
					'openWorldHint' => true,
				),
			),
		);
	}

	/**
	 * Input schema wrapper.
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @param string[]             $required   Required property names.
	 * @return array<string, mixed>
	 */
	private static function input( array $properties, array $required ): array {
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}
}
