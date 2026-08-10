<?php
/**
 * Draft order management.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and mutates the WooCommerce draft orders backing checkout sessions.
 *
 * All totals, tax and shipping math is WooCommerce's: the plugin only ever
 * feeds products, quantities, addresses, shipping selections and coupons into
 * the order and reads calculated results back (mirrors the Store API model).
 */
class DraftOrders {

	public const DRAFT_STATUS = 'checkout-draft';

	/**
	 * Resolve a UCP item id to a purchasable product.
	 *
	 * Accepts WooCommerce product/variation ids and SKUs.
	 *
	 * @param string $item_id Item id from the platform.
	 * @return \WC_Product|null
	 */
	public function resolve_product( string $item_id ): ?\WC_Product {
		$product = null;

		if ( ctype_digit( $item_id ) ) {
			$candidate = wc_get_product( (int) $item_id );
			if ( $candidate instanceof \WC_Product ) {
				$product = $candidate;
			}
		}

		if ( null === $product ) {
			$product_id = wc_get_product_id_by_sku( $item_id );
			if ( $product_id > 0 ) {
				$candidate = wc_get_product( $product_id );
				if ( $candidate instanceof \WC_Product ) {
					$product = $candidate;
				}
			}
		}

		if ( null === $product ) {
			return null;
		}

		if ( 'publish' !== $product->get_status() && 'publish' !== get_post_status( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ) {
			return null;
		}

		if ( $product->is_type( 'variable' ) ) {
			// A variable parent is not purchasable; platforms must send a variant id.
			return null;
		}

		return $product;
	}

	/**
	 * The canonical UCP item id for a product (SKU preferred).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function item_id_for( \WC_Product $product ): string {
		$sku = $product->get_sku();
		return '' !== $sku ? $sku : (string) $product->get_id();
	}

	/**
	 * Create the backing draft order.
	 *
	 * @return \WC_Order
	 * @throws UcpException On failure.
	 */
	public function create(): \WC_Order {
		$order = wc_create_order(
			array(
				'status'      => self::DRAFT_STATUS,
				'created_via' => 'ucp',
			)
		);

		if ( ! $order instanceof \WC_Order ) {
			throw UcpException::transport( 'internal_error', 'Unable to create a checkout session.', 500 );
		}

		$order->set_currency( get_woocommerce_currency() );

		return $order;
	}

	/**
	 * Load an order.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	public function get( int $order_id ): ?\WC_Order {
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Replace the order's product line items from validated line specs.
	 *
	 * @param \WC_Order                                                                 $order Order.
	 * @param array<int, array{product: \WC_Product, quantity: int, client_id: string}> $lines Desired lines.
	 * @return array<int, array<string, mixed>> Line mapping for session state.
	 */
	public function sync_line_items( \WC_Order $order, array $lines ): array {
		foreach ( $order->get_items() as $item_id => $item ) {
			$order->remove_item( (int) $item_id );
		}

		$mapping = array();

		foreach ( $lines as $line ) {
			$item_id = $order->add_product( $line['product'], $line['quantity'] );

			$mapping[] = array(
				'client_id'     => $line['client_id'],
				'order_item_id' => (int) $item_id,
				'item_id'       => $this->item_id_for( $line['product'] ),
				'product_id'    => $line['product']->get_id(),
				'quantity'      => $line['quantity'],
			);
		}

		$order->calculate_totals( wc_tax_enabled() );

		return $mapping;
	}

	/**
	 * Replace applied coupons with the requested set.
	 *
	 * Unknown or invalid codes are skipped (reported via the returned array);
	 * client-supplied `applied` data is always ignored — WooCommerce is the
	 * source of truth for discount math.
	 *
	 * @param \WC_Order $order Order.
	 * @param string[]  $codes Requested coupon codes.
	 * @return array<string, string> Map of applied canonical code => requested casing.
	 */
	public function sync_coupons( \WC_Order $order, array $codes ): array {
		foreach ( $order->get_coupon_codes() as $existing ) {
			$order->remove_coupon( $existing );
		}

		$applied = array();

		foreach ( $codes as $requested ) {
			if ( ! is_string( $requested ) || '' === trim( $requested ) ) {
				continue;
			}
			$code   = wc_format_coupon_code( trim( $requested ) );
			$coupon = new \WC_Coupon( $code );
			if ( $coupon->get_id() <= 0 && '' === $coupon->get_code() ) {
				continue;
			}
			$result = $order->apply_coupon( $code );
			if ( true === $result ) {
				$applied[ $coupon->get_code() ] = trim( $requested );
			}
		}

		$order->calculate_totals( wc_tax_enabled() );

		return $applied;
	}

	/**
	 * Guard: session must be modifiable.
	 *
	 * @param string $status Current UCP status.
	 * @param string $action Action name for the message.
	 * @return void
	 * @throws UcpException 409 when terminal.
	 */
	public function ensure_modifiable( string $status, string $action ): void {
		if ( in_array( $status, array( 'completed', 'canceled' ), true ) ) {
			throw UcpException::transport(
				'checkout_not_modifiable',
				sprintf( "Cannot %s checkout in state '%s'", $action, $status ),
				409
			);
		}
	}
}
