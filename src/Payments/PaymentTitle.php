<?php
/**
 * Choosing what a customer reads as the payment method.
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the customer-facing name of a payment handler.
 *
 * One ternary, given its own class so the rule can be asserted. It used to be
 * inline in the checkout, where the only way to test it was to drive a whole
 * WooCommerce order through `complete_checkout`, so it was never tested at
 * all - and it was wrong.
 */
final class PaymentTitle {

	/**
	 * The name to store on the order.
	 *
	 * A handler that implements PaymentHandlerTitle says what its payment is
	 * called. Anything else falls back to the registry key, which is what the
	 * checkout did for every handler before this existed: not good, but not a
	 * regression, and there is nothing better to invent on a handler's behalf.
	 *
	 * A handler that implements the interface and returns a blank string is
	 * treated as not having answered - an empty payment method on an order
	 * reads as "we do not know how this was paid for", which is worse than a
	 * reverse-domain key.
	 *
	 * @param PaymentHandlerInterface $handler The handler that took the money.
	 * @return string
	 */
	public static function of( PaymentHandlerInterface $handler ): string {
		if ( $handler instanceof PaymentHandlerTitle ) {
			$title = trim( $handler->get_title() );

			if ( '' !== $title ) {
				return $title;
			}
		}

		return $handler->get_name();
	}
}
