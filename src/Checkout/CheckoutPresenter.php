<?php
/**
 * Checkout session response payloads.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a session (row + backing order) to the UCP checkout resource.
 */
class CheckoutPresenter {

	/**
	 * Profile builder (for the ucp response block).
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Constructor.
	 *
	 * @param ProfileBuilder $profile_builder Profile builder.
	 */
	public function __construct( ProfileBuilder $profile_builder ) {
		$this->profile_builder = $profile_builder;
	}

	/**
	 * Build the checkout resource payload.
	 *
	 * @param array<string, mixed>             $session Session row (with decoded state/negotiation).
	 * @param \WC_Order                        $order   Backing order.
	 * @param NegotiationContext               $context Negotiation context.
	 * @param array<int, array<string, mixed>> $messages Messages to include.
	 * @return array<string, mixed>
	 */
	public function present( array $session, \WC_Order $order, NegotiationContext $context, array $messages = array() ): array {
		$state    = $session['state'];
		$status   = (string) $session['status'];
		$currency = $order->get_currency();

		$payload = array(
			'ucp'        => $this->profile_builder->checkout_response_block( $context, $order ),
			'id'         => (string) $session['session_id'],
			'status'     => $status,
			'currency'   => $currency,
			'line_items' => $this->line_items( $state, $order, $currency ),
			'totals'     => $this->totals( $order, $currency ),
			'links'      => $this->links(),
		);

		if ( ! empty( $state['buyer'] ) && is_array( $state['buyer'] ) ) {
			$payload['buyer'] = $state['buyer'];
		}

		$fulfillment = $this->fulfillment( $state );
		if ( null !== $fulfillment ) {
			$payload['fulfillment'] = $fulfillment;
		}

		$discounts = $this->discounts( $state, $order, $currency );
		if ( null !== $discounts ) {
			$payload['discounts'] = $discounts;
		}

		if ( ! empty( $state['instruments'] ) && is_array( $state['instruments'] ) ) {
			$payload['payment'] = array( 'instruments' => array_values( $state['instruments'] ) );
		}

		if ( ! empty( $state['order_confirmation'] ) && is_array( $state['order_confirmation'] ) ) {
			$payload['order'] = $state['order_confirmation'];
		}

		if ( ! empty( $messages ) ) {
			$payload['messages'] = array_values( $messages );
		}

		if ( ! empty( $session['expires_at'] ) && ! in_array( $status, array( 'completed', 'canceled' ), true ) ) {
			$payload['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( (string) $session['expires_at'] . ' UTC' ) );
		}

		$continue_url = $this->continue_url( $status, $state, $order );
		if ( null !== $continue_url ) {
			$payload['continue_url'] = $continue_url;
		}

		/**
		 * Filters the checkout resource payload before it is returned.
		 *
		 * @param array     $payload Payload.
		 * @param array     $session Session row.
		 * @param \WC_Order $order   Backing order.
		 */
		return apply_filters( 'ucpws_checkout_payload', $payload, $session, $order );
	}

	/**
	 * Line items with per-line totals.
	 *
	 * @param array<string, mixed> $state    Session state.
	 * @param \WC_Order            $order    Order.
	 * @param string               $currency Currency.
	 * @return array<int, array<string, mixed>>
	 */
	private function line_items( array $state, \WC_Order $order, string $currency ): array {
		$lines = array();

		foreach ( (array) ( $state['line_items'] ?? array() ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$order_item = $order->get_item( (int) ( $line['order_item_id'] ?? 0 ) );
			if ( ! $order_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product  = $order_item->get_product();
			$quantity = (int) $order_item->get_quantity();
			$subtotal = Money::to_minor( $order_item->get_subtotal(), $currency );
			$total    = Money::to_minor( $order_item->get_total(), $currency );
			$price    = $quantity > 0 ? intdiv( $subtotal, $quantity ) : $subtotal;

			$item = array(
				'id'    => (string) ( $line['item_id'] ?? '' ),
				'title' => $order_item->get_name(),
				'price' => $price,
			);

			if ( $product instanceof \WC_Product ) {
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$image_url = wp_get_attachment_image_url( (int) $image_id, 'woocommerce_single' );
					if ( is_string( $image_url ) && '' !== $image_url ) {
						$item['image_url'] = $image_url;
					}
				}
			}

			$lines[] = array(
				'id'       => (string) ( $line['client_id'] ?? '' ),
				'item'     => $item,
				'quantity' => $quantity,
				'totals'   => array(
					array(
						'type'   => 'subtotal',
						'amount' => $subtotal,
					),
					array(
						'type'   => 'total',
						'amount' => $total,
					),
				),
			);
		}//end foreach

		return $lines;
	}

	/**
	 * Order-level totals: exactly one subtotal and one total, detail entries
	 * only when non-zero.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $currency Currency.
	 * @return array<int, array<string, mixed>>
	 */
	public function totals( \WC_Order $order, string $currency ): array {
		$totals = array(
			array(
				'type'   => 'subtotal',
				'amount' => Money::to_minor( $order->get_subtotal(), $currency ),
			),
		);

		$discount = Money::to_minor( (float) $order->get_discount_total() + (float) $order->get_discount_tax(), $currency );
		if ( $discount > 0 ) {
			$totals[] = array(
				'type'   => 'discount',
				'amount' => -$discount,
			);
		}

		$shipping = Money::to_minor( (float) $order->get_shipping_total(), $currency );
		if ( $shipping > 0 || $this->has_shipping_line( $order ) ) {
			$totals[] = array(
				'type'         => 'fulfillment',
				'display_text' => __( 'Shipping', 'ucp-server-for-woocommerce' ),
				'amount'       => $shipping,
			);
		}

		$tax = Money::to_minor( (float) $order->get_total_tax(), $currency );
		if ( $tax > 0 ) {
			$totals[] = array(
				'type'   => 'tax',
				'amount' => $tax,
			);
		}

		$totals[] = array(
			'type'   => 'total',
			'amount' => Money::to_minor( $order->get_total(), $currency ),
		);

		return $totals;
	}

	/**
	 * Whether the order has a shipping line.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function has_shipping_line( \WC_Order $order ): bool {
		return count( $order->get_items( 'shipping' ) ) > 0;
	}

	/**
	 * Fulfillment block presentation.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<string, mixed>|null
	 */
	private function fulfillment( array $state ): ?array {
		$fulfillment = $state['fulfillment'] ?? null;
		if ( ! is_array( $fulfillment ) ) {
			return null;
		}

		$destinations = array();
		foreach ( (array) ( $fulfillment['destinations'] ?? array() ) as $destination ) {
			if ( is_array( $destination ) ) {
				$destinations[] = $destination;
			}
		}

		$options = array();
		foreach ( (array) ( $fulfillment['options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$options[] = array(
				'id'     => (string) $option['id'],
				'title'  => (string) $option['label'],
				'totals' => array(
					array(
						'type'   => 'total',
						'amount' => (int) $option['amount'],
					),
				),
			);
		}

		$line_item_ids = array_values( array_map( 'strval', (array) ( $fulfillment['line_item_ids'] ?? array() ) ) );

		$method = array(
			'id'            => (string) ( $fulfillment['method_id'] ?? 'method_1' ),
			'type'          => (string) ( $fulfillment['type'] ?? 'shipping' ),
			'line_item_ids' => $line_item_ids,
		);

		if ( array() !== $destinations ) {
			$method['destinations'] = $destinations;
		}

		$method['selected_destination_id'] = isset( $fulfillment['selected_destination_id'] ) && '' !== (string) $fulfillment['selected_destination_id']
			? (string) $fulfillment['selected_destination_id']
			: null;

		$group = array(
			'id'            => (string) ( $fulfillment['group_id'] ?? 'group_1' ),
			'line_item_ids' => $line_item_ids,
		);
		if ( array() !== $options ) {
			$group['options'] = $options;
		}
		$group['selected_option_id'] = isset( $fulfillment['selected_option_id'] ) && '' !== (string) $fulfillment['selected_option_id']
			? (string) $fulfillment['selected_option_id']
			: null;

		if ( array() !== $options || null !== $group['selected_option_id'] ) {
			$method['groups'] = array( $group );
		}

		return array( 'methods' => array( $method ) );
	}

	/**
	 * Discounts block (codes + applied rebuilt from WooCommerce).
	 *
	 * @param array<string, mixed> $state    Session state.
	 * @param \WC_Order            $order    Order.
	 * @param string               $currency Currency.
	 * @return array<string, mixed>|null
	 */
	private function discounts( array $state, \WC_Order $order, string $currency ): ?array {
		if ( ! array_key_exists( 'discount_codes', $state ) ) {
			return null;
		}

		$requested_map = is_array( $state['discount_codes'] ) ? $state['discount_codes'] : array();

		$applied = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$canonical = (string) $coupon_item->get_code();
			$requested = isset( $requested_map[ $canonical ] ) ? (string) $requested_map[ $canonical ] : $canonical;
			$amount    = Money::to_minor( (float) $coupon_item->get_discount() + (float) $coupon_item->get_discount_tax(), $currency );

			$applied[] = array(
				'code'      => $requested,
				'title'     => $requested,
				'amount'    => $amount,
				'automatic' => false,
			);
		}

		return array(
			'codes'   => array_values( array_map( 'strval', $requested_map ) ),
			'applied' => $applied,
		);
	}

	/**
	 * Legal links.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function links(): array {
		$links = array();

		$terms_id = (int) get_option( 'woocommerce_terms_page_id', 0 );
		if ( $terms_id > 0 ) {
			$url = get_permalink( $terms_id );
			if ( is_string( $url ) ) {
				$links[] = array(
					'type' => 'terms_of_service',
					'url'  => $url,
				);
			}
		}

		$privacy = get_privacy_policy_url();
		if ( '' !== $privacy ) {
			$links[] = array(
				'type' => 'privacy_policy',
				'url'  => $privacy,
			);
		}

		/**
		 * Filters the checkout `links` array.
		 *
		 * @param array $links Links.
		 */
		return apply_filters( 'ucpws_checkout_links', $links );
	}

	/**
	 * The contextually relevant continue URL for a status.
	 *
	 * @param string               $status Status.
	 * @param array<string, mixed> $state  Session state.
	 * @param \WC_Order            $order  Order.
	 * @return string|null
	 */
	private function continue_url( string $status, array $state, \WC_Order $order ): ?string {
		if ( in_array( $status, array( 'completed', 'canceled' ), true ) ) {
			return null;
		}

		if ( 'requires_escalation' === $status && ! empty( $state['escalation_url'] ) ) {
			return (string) $state['escalation_url'];
		}

		$url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );

		/**
		 * Filters the checkout continue_url.
		 *
		 * @param string    $url    URL.
		 * @param string    $status Session status.
		 * @param \WC_Order $order  Backing order.
		 */
		return apply_filters( 'ucpws_continue_url', $url, $status, $order );
	}
}
