<?php
/**
 * Optional contract: what a payment is CALLED.
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * A handler that knows its own customer-facing name.
 *
 * `PaymentHandlerInterface::get_name()` is a reverse-domain REGISTRY KEY -
 * `com.example.tokenizer` - and `get_id()` is an instance id. Neither is a
 * name anybody should read, and until this existed the checkout had nothing
 * else to put in the order's payment-method title, so the registry key went
 * onto order pages and into order e-mails.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * `PaymentHandlerInterface`: adding one there would break every handler
 * already implementing it, in other plugins this package cannot see. Handlers
 * opt in; those that do not keep exactly the behaviour they had.
 */
interface PaymentHandlerTitle {

	/**
	 * The payment method's name, as a customer should read it.
	 *
	 * Plain words - "BLIK", "Card", "PayPal". Not an identifier, not a URL,
	 * and not a sentence. It is stored on the order and shown wherever
	 * WooCommerce shows a payment method.
	 *
	 * @return string
	 */
	public function get_title(): string;
}
