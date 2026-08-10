<?php
/**
 * Payment handler contract.
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

use UCPWS\Negotiation\NegotiationContext;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for UCP payment handlers.
 *
 * Register implementations from any plugin via the `ucpws_payment_handlers`
 * filter:
 *
 *     add_filter( 'ucpws_payment_handlers', function ( array $handlers ) {
 *         $handlers[] = new My_PSP_Handler();
 *         return $handlers;
 *     } );
 *
 * Design notes:
 *  - The handler's `get_id()` is the `handler_id` platforms send back in
 *    `payment.instruments[]`. The server validates instrument handler ids
 *    against the advertised set before ever calling charge().
 *  - `charge()` receives the platform-supplied instrument verbatim, including
 *    the opaque `credential`. Credentials may be single-use tokens, encrypted
 *    payloads, or opaque references to merchant-stored mandates (e.g. a
 *    recurring BLIK mandate id). Handlers MUST treat them as secrets: never
 *    echo them into responses, order meta visible to browsers, or logs.
 *  - Merchant-initiated / human-not-present charges: when the credential
 *    references a stored mandate and the handler can charge without buyer
 *    interaction it returns PaymentResult::success(). When buyer interaction
 *    is unavoidable (SCA challenge, expired mandate, ...) it returns
 *    PaymentResult::escalation() — the checkout answers with
 *    `status: requires_escalation` plus a `continue_url` so the platform can
 *    fall back to the classic web checkout.
 *  - Idempotency: complete_checkout is idempotency-key protected upstream and
 *    each checkout session charges at most once. Handlers SHOULD additionally
 *    use the order id / their PSP idempotency primitives so retried charges
 *    never double-capture.
 */
interface PaymentHandlerInterface {

	/**
	 * Reverse-domain handler name used as the registry key in
	 * `ucp.payment_handlers` (e.g. `com.example.tokenizer`).
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Handler instance id. Advertised in profile/checkout responses and matched
	 * against `payment.instruments[].handler_id`.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Handler version (YYYY-MM-DD).
	 *
	 * @return string
	 */
	public function get_version(): string;

	/**
	 * URL of the human-readable handler specification (or null).
	 *
	 * @return string|null
	 */
	public function get_spec_url(): ?string;

	/**
	 * URL of the handler JSON Schema (or null).
	 *
	 * @return string|null
	 */
	public function get_schema_url(): ?string;

	/**
	 * Instrument types this handler supports, with optional constraints.
	 * Return an empty array to omit the field (= all instrument types of the
	 * handler schema are available).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_instruments(): array;

	/**
	 * Handler configuration for the given context.
	 *
	 * Called with a null order for the discovery profile declaration, and with
	 * the draft order for checkout responses (runtime config).
	 *
	 * @param \WC_Order|null          $order   Draft order, when building a checkout response.
	 * @param NegotiationContext|null $context Negotiation context, when available.
	 * @return array<string, mixed>
	 */
	public function get_config( ?\WC_Order $order = null, ?NegotiationContext $context = null ): array;

	/**
	 * Whether the handler applies to this checkout (dynamic filtering: currency,
	 * cart contents, shipping country, ...).
	 *
	 * @param \WC_Order          $order   Draft order.
	 * @param NegotiationContext $context Negotiation context.
	 * @return bool
	 */
	public function is_available( \WC_Order $order, NegotiationContext $context ): bool;

	/**
	 * Attempt the charge for a checkout completion.
	 *
	 * @param \WC_Order            $order      The (still draft) WooCommerce order carrying final totals.
	 * @param array<string, mixed> $instrument The selected payment instrument (id, handler_id, type, credential, billing_address, display, ...).
	 * @param array<string, mixed> $request    Full complete_checkout request body (signals etc.). Treat as untrusted.
	 * @param NegotiationContext   $context    Negotiation context.
	 * @return PaymentResult
	 */
	public function charge( \WC_Order $order, array $instrument, array $request, NegotiationContext $context ): PaymentResult;
}
