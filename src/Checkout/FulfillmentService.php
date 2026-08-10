<?php
/**
 * Fulfillment extension handling.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

use UCPWS\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Applies fulfillment requests to a checkout session and computes delivery
 * options from WooCommerce's own shipping engine.
 *
 * Session state shape (state['fulfillment']):
 * {
 *   method_id, type, line_item_ids: [],
 *   destinations: [ {id, street_address, ...} ],
 *   selected_destination_id: string|null,
 *   group_id,
 *   options: [ {id, rate_id, method_id, instance_id, label, cost, taxes: {}} ],
 *   selected_option_id: string|null
 * }
 */
class FulfillmentService {

	/**
	 * Address book.
	 *
	 * @var AddressBook
	 */
	private $address_book;

	/**
	 * Constructor.
	 *
	 * @param AddressBook $address_book Address book.
	 */
	public function __construct( AddressBook $address_book ) {
		$this->address_book = $address_book;
	}

	/**
	 * Apply a fulfillment request payload onto session state.
	 *
	 * @param array<string, mixed> $state       Session state (modified).
	 * @param array<string, mixed> $fulfillment Request `fulfillment` object.
	 * @param \WC_Order            $order       Backing draft order.
	 * @return array<string, mixed> Updated state.
	 */
	public function apply_request( array $state, array $fulfillment, \WC_Order $order ): array {
		$current = isset( $state['fulfillment'] ) && is_array( $state['fulfillment'] ) ? $state['fulfillment'] : $this->default_state( $state );

		$methods = isset( $fulfillment['methods'] ) && is_array( $fulfillment['methods'] ) ? $fulfillment['methods'] : array();

		// Single-method model (default platform config): the first shipping-ish
		// method drives state; identifiers are echoed as provided.
		$request_method = array();
		foreach ( $methods as $method ) {
			if ( is_array( $method ) ) {
				$request_method = $method;
				break;
			}
		}

		if ( array() !== $request_method ) {
			if ( isset( $request_method['id'] ) && is_string( $request_method['id'] ) && '' !== $request_method['id'] ) {
				$current['method_id'] = $request_method['id'];
			}
			if ( isset( $request_method['type'] ) && is_string( $request_method['type'] ) && '' !== $request_method['type'] ) {
				$current['type'] = $request_method['type'];
			}
			if ( isset( $request_method['line_item_ids'] ) && is_array( $request_method['line_item_ids'] ) && array() !== $request_method['line_item_ids'] ) {
				$current['line_item_ids'] = array_values( array_map( 'strval', $request_method['line_item_ids'] ) );
			}

			$buyer_email = isset( $state['buyer']['email'] ) ? (string) $state['buyer']['email'] : '';

			// Destinations: request-supplied replace, otherwise preserve, otherwise
			// populate from the buyer's address book.
			if ( isset( $request_method['destinations'] ) && is_array( $request_method['destinations'] ) && array() !== $request_method['destinations'] ) {
				$destinations = array();
				foreach ( $request_method['destinations'] as $destination ) {
					if ( ! is_array( $destination ) ) {
						continue;
					}
					$stored = $this->pick_address( $destination );
					$id     = isset( $destination['id'] ) && is_string( $destination['id'] ) ? $destination['id'] : '';
					if ( '' !== $buyer_email ) {
						$stored_with_id = $stored;
						if ( '' !== $id ) {
							$stored_with_id['id'] = $id;
						}
						$id = $this->address_book->save( $buyer_email, $stored_with_id );
					} elseif ( '' === $id ) {
						$id = 'dest_' . substr( md5( (string) wp_json_encode( $stored ) ), 0, 10 );
					}
					$stored['id']   = $id;
					$destinations[] = $stored;
				}
				$current['destinations'] = $destinations;
			} elseif ( array() === ( $current['destinations'] ?? array() ) && '' !== $buyer_email ) {
				$current['destinations'] = $this->address_book->get( $buyer_email );
			}//end if

			if ( array_key_exists( 'selected_destination_id', $request_method ) ) {
				$selected                           = $request_method['selected_destination_id'];
				$current['selected_destination_id'] = is_string( $selected ) && '' !== $selected ? $selected : null;
			}

			// Groups: single-group model; honor the client's group id and option selection.
			if ( isset( $request_method['groups'] ) && is_array( $request_method['groups'] ) ) {
				foreach ( $request_method['groups'] as $group ) {
					if ( ! is_array( $group ) ) {
						continue;
					}
					if ( isset( $group['id'] ) && is_string( $group['id'] ) && '' !== $group['id'] ) {
						$current['group_id'] = $group['id'];
					}
					if ( array_key_exists( 'selected_option_id', $group ) ) {
						$selected_option               = $group['selected_option_id'];
						$current['selected_option_id'] = is_string( $selected_option ) && '' !== $selected_option ? $selected_option : null;
					}
					break;
				}
			}
		} elseif ( array() === ( $current['destinations'] ?? array() ) ) {
			$buyer_email = isset( $state['buyer']['email'] ) ? (string) $state['buyer']['email'] : '';
			if ( '' !== $buyer_email ) {
				$current['destinations'] = $this->address_book->get( $buyer_email );
			}
		}//end if

		$state['fulfillment'] = $current;
		return $this->refresh( $state, $order );
	}

	/**
	 * Recompute options for the selected destination and sync the order's
	 * shipping address/line. Call after any line item or destination change.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param \WC_Order            $order Backing draft order.
	 * @return array<string, mixed>
	 */
	public function refresh( array $state, \WC_Order $order ): array {
		if ( ! isset( $state['fulfillment'] ) || ! is_array( $state['fulfillment'] ) ) {
			return $state;
		}

		$fulfillment = $state['fulfillment'];
		$destination = $this->selected_destination( $fulfillment );

		if ( null === $destination ) {
			$fulfillment['options']            = array();
			$fulfillment['selected_option_id'] = null;
			$this->apply_shipping_line( $order, null );
			$state['fulfillment'] = $fulfillment;
			return $state;
		}

		$this->set_order_shipping_address( $order, $destination );

		$fulfillment['options'] = $this->compute_options( $order, $destination );

		$selected_option = null;
		if ( ! empty( $fulfillment['selected_option_id'] ) ) {
			foreach ( $fulfillment['options'] as $option ) {
				if ( $option['id'] === $fulfillment['selected_option_id'] ) {
					$selected_option = $option;
					break;
				}
			}
			if ( null === $selected_option ) {
				$fulfillment['selected_option_id'] = null;
			}
		}

		$this->apply_shipping_line( $order, $selected_option );

		$state['fulfillment'] = $fulfillment;
		return $state;
	}

	/**
	 * Whether the fulfillment requirements for completion are met.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @param \WC_Order            $order Backing order.
	 * @return bool
	 */
	public function is_complete( array $state, \WC_Order $order ): bool {
		if ( ! $this->order_needs_shipping( $order ) ) {
			return true;
		}

		$fulfillment = isset( $state['fulfillment'] ) && is_array( $state['fulfillment'] ) ? $state['fulfillment'] : array();

		return ! empty( $fulfillment['selected_destination_id'] )
			&& null !== $this->selected_destination( $fulfillment )
			&& ! empty( $fulfillment['selected_option_id'] );
	}

	/**
	 * Whether any order item needs shipping.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function order_needs_shipping( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product instanceof \WC_Product && $product->needs_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The selected destination address, if resolvable.
	 *
	 * @param array<string, mixed> $fulfillment Fulfillment state.
	 * @return array<string, string>|null
	 */
	public function selected_destination( array $fulfillment ): ?array {
		$selected_id = $fulfillment['selected_destination_id'] ?? null;
		if ( ! is_string( $selected_id ) || '' === $selected_id ) {
			return null;
		}
		foreach ( (array) ( $fulfillment['destinations'] ?? array() ) as $destination ) {
			if ( is_array( $destination ) && ( $destination['id'] ?? '' ) === $selected_id ) {
				return $destination;
			}
		}
		return null;
	}

	/**
	 * Compute delivery options from WooCommerce shipping.
	 *
	 * @param \WC_Order             $order       Order (contents).
	 * @param array<string, string> $destination Destination address.
	 * @return array<int, array<string, mixed>>
	 */
	private function compute_options( \WC_Order $order, array $destination ): array {
		$contents = array();
		$cost     = 0.0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}
			$line_total = (float) $item->get_subtotal();
			$contents[] = array(
				'data'              => $product,
				'quantity'          => (int) $item->get_quantity(),
				'line_total'        => $line_total,
				'line_subtotal'     => $line_total,
				'line_tax'          => 0,
				'line_subtotal_tax' => 0,
			);
			$cost      += $line_total;
		}

		if ( array() === $contents ) {
			return array();
		}

		$package = array(
			'contents'        => $contents,
			'contents_cost'   => $cost,
			'applied_coupons' => $order->get_coupon_codes(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => array(
				'country'   => (string) ( $destination['address_country'] ?? '' ),
				'state'     => (string) ( $destination['address_region'] ?? '' ),
				'postcode'  => (string) ( $destination['postal_code'] ?? '' ),
				'city'      => (string) ( $destination['address_locality'] ?? '' ),
				'address'   => (string) ( $destination['street_address'] ?? '' ),
				'address_1' => (string) ( $destination['street_address'] ?? '' ),
				'address_2' => (string) ( $destination['extended_address'] ?? '' ),
			),
			'cart_subtotal'   => $cost,
		);

		$shipping = WC()->shipping();
		if ( null === $shipping ) {
			return array();
		}

		// WC_Shipping::calculate_shipping_for_package() reads WC()->session for
		// rate caching; REST/CLI requests have no session unless initialized.
		if ( null === WC()->session ) {
			WC()->initialize_session();
		}

		$calculated = $shipping->calculate_shipping_for_package( $package, 0 );
		$rates      = isset( $calculated['rates'] ) && is_array( $calculated['rates'] ) ? $calculated['rates'] : array();

		$currency = $order->get_currency();
		$options  = array();

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}

			$option_id = str_replace( ':', '-', (string) $rate->get_id() );

			/**
			 * Filters the UCP fulfillment option id derived from a WooCommerce
			 * shipping rate. Lets stores expose stable, human-friendly ids.
			 *
			 * @param string            $option_id Derived option id.
			 * @param \WC_Shipping_Rate $rate      Shipping rate.
			 */
			$option_id = (string) apply_filters( 'ucpws_fulfillment_option_id', $option_id, $rate );

			$rate_cost  = (float) $rate->get_cost();
			$rate_taxes = array_map( 'floatval', (array) $rate->get_taxes() );

			$options[] = array(
				'id'          => $option_id,
				'rate_id'     => (string) $rate->get_id(),
				'method_id'   => (string) $rate->get_method_id(),
				'instance_id' => (string) $rate->get_instance_id(),
				'label'       => (string) $rate->get_label(),
				'cost'        => $rate_cost,
				'taxes'       => $rate_taxes,
				'amount'      => Money::to_minor( $rate_cost + array_sum( $rate_taxes ), $currency ),
			);
		}//end foreach

		usort(
			$options,
			static function ( $a, $b ) {
				return $a['amount'] <=> $b['amount'];
			}
		);

		/**
		 * Filters the computed fulfillment options.
		 *
		 * @param array     $options     Options.
		 * @param \WC_Order $order       Order.
		 * @param array     $destination Destination address.
		 */
		return apply_filters( 'ucpws_fulfillment_options', $options, $order, $destination );
	}

	/**
	 * Replace the order's shipping line with the selected option (or none).
	 *
	 * @param \WC_Order                 $order  Order.
	 * @param array<string, mixed>|null $option Selected option or null.
	 * @return void
	 */
	private function apply_shipping_line( \WC_Order $order, ?array $option ): void {
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$order->remove_item( (int) $item_id );
		}

		if ( null !== $option ) {
			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( (string) $option['label'] );
			$shipping_item->set_method_id( (string) $option['method_id'] );
			// @phpstan-ignore-next-line -- WC accepts numeric instance-id strings.
			$shipping_item->set_instance_id( (string) $option['instance_id'] );
			$shipping_item->set_total( (string) $option['cost'] );
			if ( ! empty( $option['taxes'] ) && is_array( $option['taxes'] ) ) {
				$shipping_item->set_taxes( array( 'total' => $option['taxes'] ) );
			}
			$order->add_item( $shipping_item );
		}

		$order->calculate_totals( wc_tax_enabled() );
	}

	/**
	 * Write the destination to the order shipping address.
	 *
	 * @param \WC_Order             $order       Order.
	 * @param array<string, string> $destination Destination.
	 * @return void
	 */
	private function set_order_shipping_address( \WC_Order $order, array $destination ): void {
		$order->set_shipping_address_1( (string) ( $destination['street_address'] ?? '' ) );
		$order->set_shipping_address_2( (string) ( $destination['extended_address'] ?? '' ) );
		$order->set_shipping_city( (string) ( $destination['address_locality'] ?? '' ) );
		$order->set_shipping_state( (string) ( $destination['address_region'] ?? '' ) );
		$order->set_shipping_postcode( (string) ( $destination['postal_code'] ?? '' ) );
		$order->set_shipping_country( (string) ( $destination['address_country'] ?? '' ) );
		if ( ! empty( $destination['first_name'] ) ) {
			$order->set_shipping_first_name( (string) $destination['first_name'] );
		}
		if ( ! empty( $destination['last_name'] ) ) {
			$order->set_shipping_last_name( (string) $destination['last_name'] );
		}
	}

	/**
	 * Default fulfillment state derived from current line items.
	 *
	 * @param array<string, mixed> $state Session state.
	 * @return array<string, mixed>
	 */
	private function default_state( array $state ): array {
		$line_ids = array();
		foreach ( (array) ( $state['line_items'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['client_id'] ) ) {
				$line_ids[] = (string) $line['client_id'];
			}
		}

		return array(
			'method_id'               => 'method_1',
			'type'                    => 'shipping',
			'line_item_ids'           => $line_ids,
			'destinations'            => array(),
			'selected_destination_id' => null,
			'group_id'                => 'group_1',
			'options'                 => array(),
			'selected_option_id'      => null,
		);
	}

	/**
	 * Keep only postal-address fields (plus tolerated extras).
	 *
	 * @param array<string, mixed> $address Raw destination.
	 * @return array<string, string>
	 */
	private function pick_address( array $address ): array {
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
