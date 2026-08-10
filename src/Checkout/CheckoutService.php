<?php
/**
 * Checkout session orchestration.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Orders\OrderService;
use UCPWS\Payments\HandlerRegistry;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Storage\Sessions;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the five checkout operations on top of WC draft orders.
 */
class CheckoutService {

	/**
	 * Session storage.
	 *
	 * @var Sessions
	 */
	private $sessions;

	/**
	 * Draft order manager.
	 *
	 * @var DraftOrders
	 */
	private $drafts;

	/**
	 * Fulfillment service.
	 *
	 * @var FulfillmentService
	 */
	private $fulfillment;

	/**
	 * Payment handler registry.
	 *
	 * @var HandlerRegistry
	 */
	private $handlers;

	/**
	 * Presenter.
	 *
	 * @var CheckoutPresenter
	 */
	private $presenter;

	/**
	 * Order service (completion side effects).
	 *
	 * @var OrderService
	 */
	private $orders;

	/**
	 * Constructor.
	 *
	 * @param Sessions           $sessions    Session storage.
	 * @param DraftOrders        $drafts      Draft order manager.
	 * @param FulfillmentService $fulfillment Fulfillment service.
	 * @param HandlerRegistry    $handlers    Handler registry.
	 * @param CheckoutPresenter  $presenter   Presenter.
	 * @param OrderService       $orders      Order service.
	 */
	public function __construct( Sessions $sessions, DraftOrders $drafts, FulfillmentService $fulfillment, HandlerRegistry $handlers, CheckoutPresenter $presenter, OrderService $orders ) {
		$this->sessions    = $sessions;
		$this->drafts      = $drafts;
		$this->fulfillment = $fulfillment;
		$this->handlers    = $handlers;
		$this->presenter   = $presenter;
		$this->orders      = $orders;
	}

	/**
	 * Create a checkout session.
	 *
	 * @param array<string, mixed> $body    Request body.
	 * @param NegotiationContext   $context Negotiation context.
	 * @return array{payload: array<string, mixed>, status: int}
	 * @throws UcpException On invalid input or unavailable items.
	 */
	public function create( array $body, NegotiationContext $context ): array {
		$requested_lines = isset( $body['line_items'] ) && is_array( $body['line_items'] ) ? $body['line_items'] : array();

		if ( array() === $requested_lines ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'line_items is required and must contain at least one item.', 400 );
		}

		$resolution = $this->resolve_lines( $requested_lines );

		if ( array() === $resolution['lines'] ) {
			// No purchasable items: business outcome, no resource created.
			$first = $resolution['problems'][0] ?? array(
				'code'    => ErrorCodes::ITEM_UNAVAILABLE,
				'content' => 'All items are not available for purchase',
			);
			throw UcpException::business( (string) $first['code'], (string) $first['content'], 'unrecoverable' );
		}

		$order   = $this->drafts->create();
		$mapping = $this->drafts->sync_line_items( $order, $resolution['lines'] );

		$state = array(
			'line_items' => $mapping,
			'messages'   => array(),
		);

		$state = $this->apply_buyer( $state, $body, $order );

		if ( isset( $body['fulfillment'] ) && is_array( $body['fulfillment'] ) ) {
			$state = $this->fulfillment->apply_request( $state, $body['fulfillment'], $order );
		} elseif ( isset( $state['buyer']['email'] ) ) {
			$state = $this->fulfillment->apply_request( $state, array(), $order );
		}

		$state = $this->apply_discounts( $state, $body, $order );

		$order->update_meta_data( '_ucpws_platform_profile', $context->profile_url );
		$order->save();

		$ttl        = max( 300, Config::get_int( 'session_ttl' ) );
		$session_id = $this->sessions->create( $order->get_id(), $order->get_currency(), $context->to_array(), $state, $ttl, $context->platform_id );

		if ( null === $session_id ) {
			throw UcpException::transport( 'internal_error', 'Unable to persist the checkout session.', 500 );
		}

		$order->update_meta_data( '_ucpws_session_id', $session_id );
		$order->save();

		$session = $this->sessions->find( $session_id );
		if ( null === $session ) {
			throw UcpException::transport( 'internal_error', 'Unable to load the checkout session.', 500 );
		}

		$messages = array_merge( $resolution['messages'], $this->compute_status_messages( $session['state'], $order ) );
		$status   = $this->compute_status( $session['state'], $order );
		$this->sessions->update( $session_id, $status, $session['state'] );
		$session['status'] = $status;

		return array(
			'payload' => $this->presenter->present( $session, $order, $context, $messages ),
			'status'  => 201,
		);
	}

	/**
	 * Get a checkout session.
	 *
	 * @param string             $session_id Session id.
	 * @param NegotiationContext $context    Negotiation context.
	 * @return array<string, mixed> Payload.
	 * @throws UcpException 404 when unknown.
	 */
	public function get( string $session_id, NegotiationContext $context ): array {
		list( $session, $order ) = $this->load( $session_id );

		$messages = $this->compute_status_messages( $session['state'], $order );
		if ( in_array( $session['status'], array( 'completed', 'canceled', 'requires_escalation' ), true ) ) {
			$messages = array_merge( $session['state']['messages'] ?? array(), array() );
		}

		return $this->presenter->present( $session, $order, $this->context_for( $session, $context ), $messages );
	}

	/**
	 * Update (full replacement) a checkout session.
	 *
	 * @param string               $session_id Session id.
	 * @param array<string, mixed> $body       Request body.
	 * @param NegotiationContext   $context    Negotiation context.
	 * @return array<string, mixed> Payload.
	 * @throws UcpException 404/409 or validation errors.
	 */
	public function update( string $session_id, array $body, NegotiationContext $context ): array {
		list( $session, $order ) = $this->load( $session_id );

		$this->drafts->ensure_modifiable( (string) $session['status'], 'update' );

		$state    = $session['state'];
		$messages = array();

		// Line items (full replacement when provided).
		if ( isset( $body['line_items'] ) && is_array( $body['line_items'] ) && array() !== $body['line_items'] ) {
			$resolution = $this->resolve_lines( $body['line_items'], $state['line_items'] ?? array() );

			if ( array() === $resolution['lines'] ) {
				$first = $resolution['problems'][0] ?? array(
					'code'    => ErrorCodes::ITEM_UNAVAILABLE,
					'content' => 'All items are not available for purchase',
				);
				throw UcpException::business( (string) $first['code'], (string) $first['content'], 'unrecoverable' );
			}

			$state['line_items'] = $this->drafts->sync_line_items( $order, $resolution['lines'] );
			$messages            = array_merge( $messages, $resolution['messages'] );
		}

		$state = $this->apply_buyer( $state, $body, $order );

		if ( isset( $body['fulfillment'] ) && is_array( $body['fulfillment'] ) ) {
			$state = $this->fulfillment->apply_request( $state, $body['fulfillment'], $order );
		} else {
			// Line items or buyer may have changed: refresh options and shipping.
			$state = $this->fulfillment->apply_request( $state, array(), $order );
		}

		$state = $this->apply_discounts( $state, $body, $order );

		$order->save();

		$messages = array_merge( $messages, $this->compute_status_messages( $state, $order ) );
		$status   = $this->compute_status( $state, $order );

		$this->sessions->update( $session_id, $status, $state );

		$session['state']  = $state;
		$session['status'] = $status;

		return $this->presenter->present( $session, $order, $this->context_for( $session, $context ), $messages );
	}

	/**
	 * Complete a checkout session (place the order).
	 *
	 * @param string               $session_id Session id.
	 * @param array<string, mixed> $body       Request body.
	 * @param NegotiationContext   $context    Negotiation context.
	 * @return array<string, mixed> Payload.
	 * @throws UcpException Validation, payment and stock errors.
	 */
	public function complete( string $session_id, array $body, NegotiationContext $context ): array {
		list( $session, $order ) = $this->load( $session_id );

		$this->drafts->ensure_modifiable( (string) $session['status'], 'complete' );

		$state = $session['state'];

		if ( ! $this->fulfillment->is_complete( $state, $order ) ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_REQUEST,
				'Fulfillment address and option must be selected before completion.',
				400
			);
		}

		$instrument = $this->select_instrument( $body );
		$handler    = $this->handlers->find( (string) $instrument['handler_id'] );

		if ( null === $handler ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_REQUEST,
				'Unsupported payment handler: ' . (string) $instrument['handler_id'],
				400
			);
		}

		// Echo instruments without credentials; never persist or log credentials.
		$state['instruments'] = $this->strip_credentials( $body['payment']['instruments'] );

		$this->apply_billing_address( $order, $instrument );

		// Reserve stock atomically for the lifetime of the charge.
		$this->reserve_stock( $order );

		$result = $handler->charge( $order, $instrument, $body, $this->context_for( $session, $context ) );

		if ( $result->is_declined() ) {
			$this->release_stock( $order );
			throw UcpException::transport( $result->get_error_code(), $result->get_message(), $result->get_http_status() );
		}

		if ( $result->is_escalation() ) {
			$this->release_stock( $order );

			// Make the order payable via the classic web checkout.
			$order->set_status( 'pending' );
			$order->save();

			$escalation_url = $result->get_continue_url();
			if ( null === $escalation_url || '' === $escalation_url ) {
				$escalation_url = $order->get_checkout_payment_url();
			}
			$state['escalation_url'] = $escalation_url;

			$messages          = $state['messages'] ?? array();
			$messages[]        = array(
				'type'     => 'error',
				'code'     => $result->get_error_code(),
				'content'  => $result->get_message(),
				'severity' => 'requires_buyer_input',
			);
			$state['messages'] = $messages;

			$this->sessions->update( $session_id, 'requires_escalation', $state );
			$session['state']  = $state;
			$session['status'] = 'requires_escalation';

			return $this->presenter->present( $session, $order, $this->context_for( $session, $context ), $messages );
		}//end if

		// Success: transition the draft into a real, paid order.
		$order->set_payment_method( 'ucpws_' . sanitize_key( $handler->get_id() ) );
		$order->set_payment_method_title( $handler->get_name() );
		$order->update_meta_data( '_ucpws_checkout_id', $session_id );
		if ( null !== $context->webhook_url ) {
			$order->update_meta_data( '_ucpws_webhook_url', $context->webhook_url );
		}
		$order->save();

		$transaction_id = $result->get_transaction_id();
		$order->payment_complete( null === $transaction_id ? '' : $transaction_id );

		$state['order_confirmation'] = array(
			'id'            => (string) $order->get_id(),
			'permalink_url' => $order->get_checkout_order_received_url(),
		);
		$state['messages']           = array();

		$this->sessions->update( $session_id, 'completed', $state );
		$session['state']  = $state;
		$session['status'] = 'completed';

		// Snapshot + deliver the order_placed webhook synchronously.
		$this->orders->handle_completed_checkout( $order, $session_id, $this->context_for( $session, $context ), $state );

		return $this->presenter->present( $session, $order, $this->context_for( $session, $context ), array() );
	}

	/**
	 * Cancel a checkout session.
	 *
	 * @param string             $session_id Session id.
	 * @param NegotiationContext $context    Negotiation context.
	 * @return array<string, mixed> Payload.
	 * @throws UcpException 404/409.
	 */
	public function cancel( string $session_id, NegotiationContext $context ): array {
		list( $session, $order ) = $this->load( $session_id );

		$this->drafts->ensure_modifiable( (string) $session['status'], 'cancel' );

		$order->update_status( 'cancelled', __( 'UCP checkout session canceled by the platform.', 'ucp-server-for-woocommerce' ) );

		$state = $session['state'];
		$this->sessions->update( $session_id, 'canceled', $state );
		$session['status'] = 'canceled';

		return $this->presenter->present( $session, $order, $this->context_for( $session, $context ), array() );
	}

	/**
	 * Load session + order or fail.
	 *
	 * @param string $session_id Session id.
	 * @return array{0: array<string, mixed>, 1: \WC_Order}
	 * @throws UcpException 404 when unknown.
	 */
	private function load( string $session_id ): array {
		$session = $this->sessions->find( $session_id );

		if ( null === $session ) {
			throw UcpException::transport( ErrorCodes::NOT_FOUND, 'Checkout session not found', 404 );
		}

		// Lazy expiry: expired non-terminal sessions become canceled.
		if ( ! in_array( $session['status'], array( 'completed', 'canceled' ), true )
			&& ! empty( $session['expires_at'] )
			&& strtotime( (string) $session['expires_at'] . ' UTC' ) < time() ) {
			$this->sessions->update( $session_id, 'canceled', $session['state'] );
			$session['status'] = 'canceled';
		}

		$order = $this->drafts->get( (int) $session['order_id'] );
		if ( null === $order ) {
			throw UcpException::transport( ErrorCodes::NOT_FOUND, 'Checkout session not found', 404 );
		}

		return array( $session, $order );
	}

	/**
	 * Resolve requested line items to purchasable products.
	 *
	 * @param array<int, mixed>                $requested Requested line items.
	 * @param array<int, array<string, mixed>> $existing Existing mapping (update flow).
	 * @return array{lines: array<int, array<string, mixed>>, problems: array<int, array<string, string>>, messages: array<int, array<string, mixed>>}
	 */
	private function resolve_lines( array $requested, array $existing = array() ): array {
		$lines    = array();
		$problems = array();
		$messages = array();
		$index    = 0;

		$existing_by_client = array();
		foreach ( $existing as $line ) {
			if ( is_array( $line ) && isset( $line['client_id'] ) ) {
				$existing_by_client[ (string) $line['client_id'] ] = $line;
			}
		}

		foreach ( $requested as $position => $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$item_id  = isset( $line['item']['id'] ) ? (string) $line['item']['id'] : '';
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			$quantity = max( 1, $quantity );

			$client_id = isset( $line['id'] ) && is_string( $line['id'] ) && '' !== $line['id']
				? $line['id']
				: 'li_' . ( ++$index ) . '_' . substr( md5( $item_id . $position ), 0, 6 );

			if ( '' === $item_id ) {
				$problems[] = array(
					'code'    => ErrorCodes::INVALID_REQUEST,
					'content' => 'line_items[].item.id is required.',
				);
				continue;
			}

			$product = $this->drafts->resolve_product( $item_id );

			if ( null === $product ) {
				$problems[] = array(
					'code'    => ErrorCodes::NOT_FOUND,
					'content' => sprintf( 'Product %s not found', $item_id ),
				);
				$messages[] = array(
					'type'     => 'error',
					'code'     => ErrorCodes::NOT_FOUND,
					'content'  => sprintf( 'Product %s not found', $item_id ),
					'severity' => 'recoverable',
					'path'     => '$.line_items[' . (int) $position . ']',
				);
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$problems[] = array(
					'code'    => ErrorCodes::OUT_OF_STOCK,
					'content' => sprintf( 'Item %s is out of stock', $item_id ),
				);
				$messages[] = array(
					'type'     => 'error',
					'code'     => ErrorCodes::OUT_OF_STOCK,
					'content'  => sprintf( 'Item %s is out of stock', $item_id ),
					'severity' => 'recoverable',
					'path'     => '$.line_items[' . (int) $position . ']',
				);
				continue;
			}

			if ( ! $product->has_enough_stock( $quantity ) ) {
				// Keep the previous quantity when known; otherwise reject the line.
				$previous = $existing_by_client[ $client_id ]['quantity'] ?? null;
				$content  = sprintf( 'Insufficient stock for item %s', $item_id );

				$messages[] = array(
					'type'     => 'error',
					'code'     => ErrorCodes::OUT_OF_STOCK,
					'content'  => $content,
					'severity' => 'recoverable',
					'path'     => '$.line_items[' . (int) $position . '].quantity',
				);

				if ( null === $previous ) {
					$problems[] = array(
						'code'    => ErrorCodes::OUT_OF_STOCK,
						'content' => $content,
					);
					continue;
				}
				$quantity = (int) $previous;
			}//end if

			$lines[] = array(
				'product'   => $product,
				'quantity'  => $quantity,
				'client_id' => $client_id,
			);
		}//end foreach

		return array(
			'lines'    => $lines,
			'problems' => $problems,
			'messages' => $messages,
		);
	}

	/**
	 * Merge buyer info into state and the order billing fields.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param array<string, mixed> $body  Request body.
	 * @param \WC_Order            $order Order.
	 * @return array<string, mixed>
	 */
	private function apply_buyer( array $state, array $body, \WC_Order $order ): array {
		if ( ! isset( $body['buyer'] ) || ! is_array( $body['buyer'] ) ) {
			return $state;
		}

		$buyer          = $body['buyer'];
		$state['buyer'] = $buyer;

		if ( isset( $buyer['email'] ) && is_string( $buyer['email'] ) && is_email( $buyer['email'] ) ) {
			$order->set_billing_email( sanitize_email( $buyer['email'] ) );
		}
		if ( isset( $buyer['first_name'] ) && is_string( $buyer['first_name'] ) ) {
			$order->set_billing_first_name( sanitize_text_field( $buyer['first_name'] ) );
		}
		if ( isset( $buyer['last_name'] ) && is_string( $buyer['last_name'] ) ) {
			$order->set_billing_last_name( sanitize_text_field( $buyer['last_name'] ) );
		}
		if ( isset( $buyer['phone_number'] ) && is_string( $buyer['phone_number'] ) ) {
			$order->set_billing_phone( sanitize_text_field( $buyer['phone_number'] ) );
		}

		return $state;
	}

	/**
	 * Apply requested discount codes (client `applied` data is ignored).
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param array<string, mixed> $body  Request body.
	 * @param \WC_Order            $order Order.
	 * @return array<string, mixed>
	 */
	private function apply_discounts( array $state, array $body, \WC_Order $order ): array {
		if ( ! isset( $body['discounts'] ) || ! is_array( $body['discounts'] ) ) {
			return $state;
		}

		$codes = isset( $body['discounts']['codes'] ) && is_array( $body['discounts']['codes'] ) ? $body['discounts']['codes'] : array();

		$state['discount_codes'] = $this->drafts->sync_coupons( $order, $codes );

		// Coupon changes can affect shipping (free shipping rules): refresh.
		return $this->fulfillment->refresh( $state, $order );
	}

	/**
	 * Select and validate the payment instrument from a complete request.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>
	 * @throws UcpException 400 on missing pieces.
	 */
	private function select_instrument( array $body ): array {
		$instruments = $body['payment']['instruments'] ?? null;

		if ( ! is_array( $instruments ) || array() === $instruments ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Missing payment instruments', 400 );
		}

		$selected = null;
		foreach ( $instruments as $instrument ) {
			if ( is_array( $instrument ) && ! empty( $instrument['selected'] ) ) {
				$selected = $instrument;
				break;
			}
		}
		if ( null === $selected ) {
			$first = reset( $instruments );
			if ( is_array( $first ) ) {
				$selected = $first;
			}
		}

		if ( null === $selected ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Missing payment instruments', 400 );
		}

		if ( empty( $selected['handler_id'] ) || ! is_string( $selected['handler_id'] ) ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Missing handler_id in instrument', 400 );
		}

		if ( empty( $selected['credential'] ) || ! is_array( $selected['credential'] ) ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'Missing credentials in instrument', 400 );
		}

		return $selected;
	}

	/**
	 * Strip credentials from instruments before echoing/persisting.
	 *
	 * @param mixed $instruments Instruments array.
	 * @return array<int, array<string, mixed>>
	 */
	private function strip_credentials( $instruments ): array {
		$stripped = array();
		foreach ( (array) $instruments as $instrument ) {
			if ( ! is_array( $instrument ) ) {
				continue;
			}
			unset( $instrument['credential'] );
			$stripped[] = $instrument;
		}
		return $stripped;
	}

	/**
	 * Copy the billing address from the instrument to the order.
	 *
	 * @param \WC_Order            $order      Order.
	 * @param array<string, mixed> $instrument Instrument.
	 * @return void
	 */
	private function apply_billing_address( \WC_Order $order, array $instrument ): void {
		$billing = isset( $instrument['billing_address'] ) && is_array( $instrument['billing_address'] ) ? $instrument['billing_address'] : null;
		if ( null === $billing ) {
			return;
		}

		$order->set_billing_address_1( sanitize_text_field( (string) ( $billing['street_address'] ?? '' ) ) );
		$order->set_billing_address_2( sanitize_text_field( (string) ( $billing['extended_address'] ?? '' ) ) );
		$order->set_billing_city( sanitize_text_field( (string) ( $billing['address_locality'] ?? '' ) ) );
		$order->set_billing_state( sanitize_text_field( (string) ( $billing['address_region'] ?? '' ) ) );
		$order->set_billing_postcode( sanitize_text_field( (string) ( $billing['postal_code'] ?? '' ) ) );
		$order->set_billing_country( sanitize_text_field( (string) ( $billing['address_country'] ?? '' ) ) );
		if ( ! empty( $billing['first_name'] ) && '' === $order->get_billing_first_name() ) {
			$order->set_billing_first_name( sanitize_text_field( (string) $billing['first_name'] ) );
		}
		if ( ! empty( $billing['last_name'] ) && '' === $order->get_billing_last_name() ) {
			$order->set_billing_last_name( sanitize_text_field( (string) $billing['last_name'] ) );
		}
	}

	/**
	 * Reserve stock for the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 * @throws UcpException 409 out_of_stock when reservation fails.
	 */
	private function reserve_stock( \WC_Order $order ): void {
		if ( ! class_exists( \Automattic\WooCommerce\Checkout\Helpers\ReserveStock::class ) ) {
			return;
		}

		try {
			( new \Automattic\WooCommerce\Checkout\Helpers\ReserveStock() )->reserve_stock_for_order( $order, 10 );
		} catch ( \Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $exception ) {
			throw UcpException::transport( ErrorCodes::OUT_OF_STOCK, $exception->getMessage() . ' (insufficient stock)', 409 );
		}
	}

	/**
	 * Release a stock reservation.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function release_stock( \WC_Order $order ): void {
		if ( function_exists( 'wc_release_stock_for_order' ) ) {
			wc_release_stock_for_order( $order );
		}
	}

	/**
	 * Compute the session status from state.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param \WC_Order            $order Order.
	 * @return string
	 */
	private function compute_status( array $state, \WC_Order $order ): string {
		if ( ! $this->fulfillment->is_complete( $state, $order ) ) {
			return 'incomplete';
		}
		return 'ready_for_complete';
	}

	/**
	 * Messages describing what is missing to progress.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param \WC_Order            $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	private function compute_status_messages( array $state, \WC_Order $order ): array {
		$messages = array();

		if ( ! $this->fulfillment->order_needs_shipping( $order ) ) {
			return $messages;
		}

		$fulfillment = isset( $state['fulfillment'] ) && is_array( $state['fulfillment'] ) ? $state['fulfillment'] : array();

		if ( empty( $fulfillment['selected_destination_id'] ) || null === $this->fulfillment->selected_destination( $fulfillment ) ) {
			$messages[] = array(
				'type'     => 'error',
				'code'     => ErrorCodes::MISSING,
				'path'     => '$.fulfillment.methods[0].selected_destination_id',
				'content'  => 'Fulfillment address is required',
				'severity' => 'recoverable',
			);
		} elseif ( empty( $fulfillment['selected_option_id'] ) ) {
			$messages[] = array(
				'type'     => 'error',
				'code'     => ErrorCodes::MISSING,
				'path'     => '$.fulfillment.methods[0].groups[0].selected_option_id',
				'content'  => 'Please select a fulfillment option',
				'severity' => 'recoverable',
			);
		}

		return $messages;
	}

	/**
	 * Prefer the session's stored negotiation context (webhook URL etc. captured
	 * at create time) over the per-request context.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @param NegotiationContext   $context Current request context.
	 * @return NegotiationContext
	 */
	private function context_for( array $session, NegotiationContext $context ): NegotiationContext {
		if ( is_array( $session['negotiation'] ) && array() !== $session['negotiation'] ) {
			$stored = NegotiationContext::from_array( $session['negotiation'] );
			// Current request may carry a fresher webhook URL.
			if ( null === $stored->webhook_url && null !== $context->webhook_url ) {
				$stored->webhook_url = $context->webhook_url;
			}
			return $stored;
		}
		return $context;
	}
}
