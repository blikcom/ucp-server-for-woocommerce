<?php
/**
 * Order capability: snapshots, retrieval, platform updates.
 *
 * @package UCPWS
 */

namespace UCPWS\Orders;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Support\Ids;
use UCPWS\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Presents WooCommerce orders as UCP order entities (current-state snapshots).
 *
 * Protocol-only data (client line item ids, expectations, fulfillment events,
 * adjustments) is stored in order meta; money data always comes from the
 * WooCommerce order itself.
 */
class OrderService {

	private const META_SNAPSHOT = '_ucpws_order_snapshot';

	/**
	 * Profile builder.
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Webhook dispatcher.
	 *
	 * @var WebhookDispatcher
	 */
	private $webhooks;

	/**
	 * Constructor.
	 *
	 * @param ProfileBuilder    $profile_builder Profile builder.
	 * @param WebhookDispatcher $webhooks        Webhook dispatcher.
	 */
	public function __construct( ProfileBuilder $profile_builder, WebhookDispatcher $webhooks ) {
		$this->profile_builder = $profile_builder;
		$this->webhooks        = $webhooks;
	}

	/**
	 * Completion side effects: snapshot protocol data and send order_placed.
	 *
	 * @param \WC_Order            $order      Completed order.
	 * @param string               $session_id Checkout session id.
	 * @param NegotiationContext   $context    Negotiation context.
	 * @param array<string, mixed> $state      Final session state.
	 * @return void
	 */
	public function handle_completed_checkout( \WC_Order $order, string $session_id, NegotiationContext $context, array $state ): void {
		$expectations = $this->build_expectations( $order, $state );

		$snapshot = array(
			'checkout_id'  => $session_id,
			'line_map'     => $this->line_map_from_state( $state ),
			'expectations' => $expectations,
			'events'       => array(),
			'adjustments'  => array(),
			'negotiation'  => $context->to_array(),
		);

		$order->update_meta_data( self::META_SNAPSHOT, wp_json_encode( $snapshot ) );
		$order->save();

		$this->webhooks->deliver( $order->get_id(), 'order_placed', 1 );
	}

	/**
	 * Get an order snapshot payload.
	 *
	 * @param string             $order_id Order id.
	 * @param NegotiationContext $context  Request context.
	 * @return array<string, mixed> UCP order entity or error envelope (both HTTP 200).
	 */
	public function get( string $order_id, NegotiationContext $context ): array {
		$order = $this->load( $order_id );

		if ( null === $order ) {
			return $this->error_envelope( $context, ErrorCodes::NOT_FOUND, 'Order not found.' );
		}

		return $this->present( $order, $context );
	}

	/**
	 * Platform update of an order document (fulfillment events + adjustments).
	 *
	 * The request is a full order document; only the client-authorable arrays
	 * (`fulfillment.events`, `adjustments`) are persisted.
	 *
	 * @param string               $order_id Order id.
	 * @param array<string, mixed> $body     Request body.
	 * @param NegotiationContext   $context  Request context.
	 * @return array<string, mixed>
	 * @throws UcpException 422 on malformed arrays/enums, 404 when unknown.
	 */
	public function update( string $order_id, array $body, NegotiationContext $context ): array {
		$order = $this->load( $order_id );

		if ( null === $order ) {
			throw UcpException::transport( ErrorCodes::NOT_FOUND, 'Order not found', 404 );
		}

		$snapshot = $this->snapshot( $order );

		if ( array_key_exists( 'adjustments', $body ) ) {
			$snapshot['adjustments'] = $this->validate_adjustments( $body['adjustments'] );
		}

		if ( isset( $body['fulfillment'] ) ) {
			if ( ! is_array( $body['fulfillment'] ) ) {
				throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'fulfillment must be an object.', 422 );
			}
			if ( array_key_exists( 'events', $body['fulfillment'] ) ) {
				$snapshot['events'] = $this->validate_events( $body['fulfillment']['events'] );
			}
		}

		$order->update_meta_data( self::META_SNAPSHOT, wp_json_encode( $snapshot ) );
		$order->save();

		return $this->present( $order, $context );
	}

	/**
	 * Append a `shipped` fulfillment event and notify the platform.
	 * Used by the /testing/simulate-shipping endpoint.
	 *
	 * @param string $order_id Order id.
	 * @return bool Whether the order existed.
	 */
	public function simulate_shipped( string $order_id ): bool {
		$order = $this->load( $order_id );
		if ( null === $order ) {
			return false;
		}

		$snapshot = $this->snapshot( $order );

		$line_items = array();
		foreach ( $snapshot['line_map'] as $line ) {
			$line_items[] = array(
				'id'       => (string) $line['client_id'],
				'quantity' => (int) $line['quantity'],
			);
		}

		$snapshot['events'][] = array(
			'id'          => Ids::prefixed( 'evt', 8 ),
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'type'        => 'shipped',
			'line_items'  => $line_items,
			'description' => 'Shipped (simulated)',
		);

		$order->update_meta_data( self::META_SNAPSHOT, wp_json_encode( $snapshot ) );
		$order->save();

		$this->webhooks->deliver( $order->get_id(), 'order_shipped', 1 );

		return true;
	}

	/**
	 * Present a WooCommerce order as the UCP order entity.
	 *
	 * @param \WC_Order               $order   Order.
	 * @param NegotiationContext|null $context Context (falls back to the stored one).
	 * @return array<string, mixed>
	 */
	public function present( \WC_Order $order, ?NegotiationContext $context = null ): array {
		$snapshot = $this->snapshot( $order );
		$currency = $order->get_currency();

		if ( null === $context ) {
			$context = NegotiationContext::from_array( is_array( $snapshot['negotiation'] ) ? $snapshot['negotiation'] : array() );
		}

		$line_items = array();
		foreach ( $snapshot['line_map'] as $line ) {
			$order_item = $order->get_item( (int) $line['order_item_id'] );
			if ( ! $order_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$quantity  = (int) $order_item->get_quantity();
			$fulfilled = $this->fulfilled_quantity( $snapshot['events'], (string) $line['client_id'] );
			$fulfilled = min( $fulfilled, $quantity );
			$subtotal  = Money::to_minor( $order_item->get_subtotal(), $currency );
			$total     = Money::to_minor( $order_item->get_total(), $currency );

			if ( 0 === $quantity ) {
				$status = 'removed';
			} elseif ( $fulfilled >= $quantity ) {
				$status = 'fulfilled';
			} elseif ( $fulfilled > 0 ) {
				$status = 'partial';
			} else {
				$status = 'processing';
			}

			$line_items[] = array(
				'id'       => (string) $line['client_id'],
				'item'     => array(
					'id'    => (string) $line['item_id'],
					'title' => $order_item->get_name(),
					'price' => $quantity > 0 ? intdiv( $subtotal, $quantity ) : $subtotal,
				),
				'quantity' => array(
					'original'  => (int) $line['quantity'],
					'total'     => $quantity,
					'fulfilled' => $fulfilled,
				),
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
				'status'   => $status,
			);
		}//end foreach

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
		if ( $shipping > 0 || count( $order->get_items( 'shipping' ) ) > 0 ) {
			$totals[] = array(
				'type'   => 'fulfillment',
				'amount' => $shipping,
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

		$payload = array(
			'ucp'           => $this->profile_builder->order_response_block( $context ),
			'id'            => (string) $order->get_id(),
			'checkout_id'   => (string) $snapshot['checkout_id'],
			'permalink_url' => $order->get_checkout_order_received_url(),
			'currency'      => $currency,
			'line_items'    => $line_items,
			'fulfillment'   => array(
				'expectations' => array_values( $snapshot['expectations'] ),
				'events'       => array_values( $snapshot['events'] ),
			),
			'adjustments'   => array_values( $snapshot['adjustments'] ),
			'totals'        => $totals,
		);

		/**
		 * Filters the UCP order entity payload.
		 *
		 * @param array     $payload Payload.
		 * @param \WC_Order $order   Order.
		 */
		return apply_filters( 'ucpws_order_payload', $payload, $order );
	}

	/**
	 * Load an order that belongs to a UCP checkout.
	 *
	 * @param string $order_id Order id.
	 * @return \WC_Order|null
	 */
	public function load( string $order_id ): ?\WC_Order {
		if ( ! ctype_digit( $order_id ) ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( 'ucp' !== $order->get_created_via() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Error envelope (order errors are HTTP 200 business outcomes).
	 *
	 * @param NegotiationContext $context Context.
	 * @param string             $code    Error code.
	 * @param string             $content Message.
	 * @return array<string, mixed>
	 */
	public function error_envelope( NegotiationContext $context, string $code, string $content ): array {
		return array(
			'ucp'      => array(
				'version'      => $context->version,
				'status'       => 'error',
				'capabilities' => array(
					ProfileBuilder::CAP_ORDER => array( array( 'version' => $context->version ) ),
				),
			),
			'messages' => array(
				array(
					'type'     => 'error',
					'code'     => $code,
					'severity' => 'unrecoverable',
					'content'  => $content,
				),
			),
		);
	}

	/**
	 * Stored snapshot with defaults.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{checkout_id: string, line_map: array<int, array<string, mixed>>, expectations: array<int, mixed>, events: array<int, mixed>, adjustments: array<int, mixed>, negotiation: array<string, mixed>}
	 */
	private function snapshot( \WC_Order $order ): array {
		$raw     = $order->get_meta( self::META_SNAPSHOT );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$decoded = is_array( $decoded ) ? $decoded : array();

		$line_map = isset( $decoded['line_map'] ) && is_array( $decoded['line_map'] ) ? $decoded['line_map'] : array();
		if ( array() === $line_map ) {
			// Orders without a stored map (edge: created outside checkout flow).
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product    = $item->get_product();
				$line_map[] = array(
					'client_id'     => 'li_' . $item_id,
					'order_item_id' => (int) $item_id,
					'item_id'       => $product instanceof \WC_Product && '' !== $product->get_sku() ? $product->get_sku() : (string) $item->get_product_id(),
					'quantity'      => (int) $item->get_quantity(),
				);
			}
		}

		return array(
			'checkout_id'  => (string) ( $decoded['checkout_id'] ?? $order->get_meta( '_ucpws_checkout_id' ) ),
			'line_map'     => $line_map,
			'expectations' => isset( $decoded['expectations'] ) && is_array( $decoded['expectations'] ) ? $decoded['expectations'] : array(),
			'events'       => isset( $decoded['events'] ) && is_array( $decoded['events'] ) ? $decoded['events'] : array(),
			'adjustments'  => isset( $decoded['adjustments'] ) && is_array( $decoded['adjustments'] ) ? $decoded['adjustments'] : array(),
			'negotiation'  => isset( $decoded['negotiation'] ) && is_array( $decoded['negotiation'] ) ? $decoded['negotiation'] : array(),
		);
	}

	/**
	 * Buyer-facing expectations built from the checkout's fulfillment state.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $state Session state.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_expectations( \WC_Order $order, array $state ): array {
		$fulfillment = isset( $state['fulfillment'] ) && is_array( $state['fulfillment'] ) ? $state['fulfillment'] : array();

		$line_items = array();
		foreach ( $this->line_map_from_state( $state ) as $line ) {
			$line_items[] = array(
				'id'       => (string) $line['client_id'],
				'quantity' => (int) $line['quantity'],
			);
		}

		if ( array() === $line_items ) {
			return array();
		}

		$destination = null;
		$selected_id = $fulfillment['selected_destination_id'] ?? null;
		foreach ( (array) ( $fulfillment['destinations'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['id'] ?? '' ) === $selected_id ) {
				$destination = $candidate;
				break;
			}
		}

		if ( null === $destination ) {
			if ( '' === $order->get_shipping_country() ) {
				return array();
			}
			$destination = array(
				'street_address'   => $order->get_shipping_address_1(),
				'address_locality' => $order->get_shipping_city(),
				'address_region'   => $order->get_shipping_state(),
				'postal_code'      => $order->get_shipping_postcode(),
				'address_country'  => $order->get_shipping_country(),
			);
		} else {
			$destination = array_diff_key( $destination, array( 'id' => true ) );
		}

		$description        = '';
		$selected_option_id = $fulfillment['selected_option_id'] ?? null;
		foreach ( (array) ( $fulfillment['options'] ?? array() ) as $option ) {
			if ( is_array( $option ) && ( $option['id'] ?? '' ) === $selected_option_id ) {
				$description = (string) $option['label'];
				break;
			}
		}

		$expectation = array(
			'id'          => Ids::prefixed( 'exp', 8 ),
			'line_items'  => $line_items,
			'method_type' => 'shipping',
			'destination' => $destination,
		);
		if ( '' !== $description ) {
			$expectation['description'] = $description;
		}

		return array( $expectation );
	}

	/**
	 * Line map from session state.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<int, array<string, mixed>>
	 */
	private function line_map_from_state( array $state ): array {
		$map = array();
		foreach ( (array) ( $state['line_items'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['client_id'], $line['order_item_id'] ) ) {
				$map[] = $line;
			}
		}
		return $map;
	}

	/**
	 * Quantity fulfilled for a line, derived from delivered/shipped events.
	 *
	 * @param array<int, mixed> $events    Fulfillment events.
	 * @param string            $client_id Line client id.
	 * @return int
	 */
	private function fulfilled_quantity( array $events, string $client_id ): int {
		$fulfilled = 0;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || 'delivered' !== ( $event['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $event['line_items'] ?? array() ) as $line ) {
				if ( is_array( $line ) && ( $line['id'] ?? '' ) === $client_id ) {
					$fulfilled += (int) ( $line['quantity'] ?? 0 );
				}
			}
		}
		return $fulfilled;
	}

	/**
	 * Validate an adjustments payload.
	 *
	 * @param mixed $adjustments Raw value.
	 * @return array<int, array<string, mixed>>
	 * @throws UcpException 422 on malformed input.
	 */
	private function validate_adjustments( $adjustments ): array {
		if ( ! is_array( $adjustments ) || ( array() !== $adjustments && array_keys( $adjustments ) !== range( 0, count( $adjustments ) - 1 ) ) ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'adjustments must be an array of adjustment objects.', 422 );
		}

		$valid_statuses = array( 'pending', 'completed', 'failed' );
		$validated      = array();

		foreach ( $adjustments as $adjustment ) {
			if ( ! is_array( $adjustment ) ) {
				throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Each adjustment must be an object.', 422 );
			}
			foreach ( array( 'id', 'type', 'occurred_at', 'status' ) as $required ) {
				if ( empty( $adjustment[ $required ] ) || ! is_string( $adjustment[ $required ] ) ) {
					throw UcpException::transport( ErrorCodes::INVALID_REQUEST, sprintf( 'Adjustment field `%s` is required.', $required ), 422 );
				}
			}
			if ( ! in_array( $adjustment['status'], $valid_statuses, true ) ) {
				throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Adjustment status must be one of pending, completed, failed.', 422 );
			}
			$validated[] = $adjustment;
		}

		return $validated;
	}

	/**
	 * Validate a fulfillment events payload.
	 *
	 * @param mixed $events Raw value.
	 * @return array<int, array<string, mixed>>
	 * @throws UcpException 422 on malformed input.
	 */
	private function validate_events( $events ): array {
		if ( ! is_array( $events ) || ( array() !== $events && array_keys( $events ) !== range( 0, count( $events ) - 1 ) ) ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'fulfillment.events must be an array of event objects.', 422 );
		}

		$validated = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Each fulfillment event must be an object.', 422 );
			}
			foreach ( array( 'id', 'occurred_at', 'type' ) as $required ) {
				if ( empty( $event[ $required ] ) || ! is_string( $event[ $required ] ) ) {
					throw UcpException::transport( ErrorCodes::INVALID_REQUEST, sprintf( 'Fulfillment event field `%s` is required.', $required ), 422 );
				}
			}
			$validated[] = $event;
		}

		return $validated;
	}
}
