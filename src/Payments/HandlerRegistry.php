<?php
/**
 * Payment handler registry.
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Collects payment handlers registered via the `ucpws_payment_handlers` filter.
 */
class HandlerRegistry {

	/**
	 * Cached handlers keyed by handler id.
	 *
	 * @var array<string, PaymentHandlerInterface>|null
	 */
	private $handlers;

	/**
	 * All registered handlers keyed by handler id.
	 *
	 * @return array<string, PaymentHandlerInterface>
	 */
	public function all(): array {
		if ( null !== $this->handlers ) {
			return $this->handlers;
		}

		$defaults = array();
		if ( Config::get_bool( 'enable_mock_handler' ) ) {
			$defaults[] = new MockHandler();
		}

		/**
		 * Filters the registered UCP payment handlers.
		 *
		 * @param PaymentHandlerInterface[] $handlers Handler instances.
		 */
		$registered = apply_filters( 'ucpws_payment_handlers', $defaults );

		$this->handlers = array();
		foreach ( (array) $registered as $handler ) {
			if ( $handler instanceof PaymentHandlerInterface ) {
				$this->handlers[ $handler->get_id() ] = $handler;
			}
		}

		return $this->handlers;
	}

	/**
	 * Find a handler by the instrument's handler_id.
	 *
	 * @param string $handler_id Handler id from the payment instrument.
	 * @return PaymentHandlerInterface|null
	 */
	public function find( string $handler_id ): ?PaymentHandlerInterface {
		$handlers = $this->all();
		return $handlers[ $handler_id ] ?? null;
	}

	/**
	 * Handler declarations for the discovery profile.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function profile_declarations(): array {
		return $this->declarations( null, null );
	}

	/**
	 * Handler declarations for a checkout response (runtime, cart-filtered).
	 *
	 * @param \WC_Order|null          $order   Draft order.
	 * @param NegotiationContext|null $context Negotiation context.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function declarations( ?\WC_Order $order, ?NegotiationContext $context ): array {
		$declarations = array();

		foreach ( $this->all() as $handler ) {
			if ( null !== $order && null !== $context && ! $handler->is_available( $order, $context ) ) {
				continue;
			}

			$entry = array(
				'id'      => $handler->get_id(),
				'version' => $handler->get_version(),
			);

			$spec = $handler->get_spec_url();
			if ( null !== $spec && '' !== $spec ) {
				$entry['spec'] = $spec;
			}
			$schema = $handler->get_schema_url();
			if ( null !== $schema && '' !== $schema ) {
				$entry['schema'] = $schema;
			}

			$instruments = $handler->get_available_instruments();
			if ( array() !== $instruments ) {
				/**
				 * Filters the resolved available_instruments for a handler in a
				 * checkout response (platform/business/cart intersection point).
				 *
				 * @param array                   $instruments Advertised instruments.
				 * @param PaymentHandlerInterface $handler     Handler instance.
				 * @param \WC_Order|null          $order       Draft order (null for profile).
				 * @param NegotiationContext|null $context     Negotiation context.
				 */
				$instruments = apply_filters( 'ucpws_resolve_available_instruments', $instruments, $handler, $order, $context );
				if ( array() !== $instruments ) {
					$entry['available_instruments'] = array_values( $instruments );
				}
			}

			$entry['config'] = (object) $handler->get_config( $order, $context );

			$declarations[ $handler->get_name() ][] = $entry;
		}//end foreach

		return $declarations;
	}
}
