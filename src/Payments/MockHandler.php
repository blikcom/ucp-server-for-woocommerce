<?php
/**
 * Mock payment handler (non-production).
 *
 * @package UCPWS
 */

namespace UCPWS\Payments;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Development/demo payment handler.
 *
 * NON-PRODUCTION. Charges nothing. Enabled via the `enable_mock_handler`
 * option/constant; used by the test suites and the UCP conformance suite.
 *
 * Token credentials drive the outcome:
 *  - `success_token`  -> approved
 *  - `fail_token`     -> declined (402, "Payment Failed: Insufficient Funds (Mock)")
 *  - `fraud_token`    -> declined (403, "Payment Failed: Fraud Detected (Mock)")
 *  - `escalate_token` -> requires_escalation with a continue_url (simulates SCA)
 *  - `mandate_*`      -> approved (simulates a merchant-stored mandate reference,
 *                        i.e. a human-not-present charge on a stored credential)
 *  - anything else    -> declined (402, unknown token)
 *
 * Card credentials (`type: card`) and unknown credential shapes with a type are
 * approved, mirroring the UCP reference merchant server.
 */
class MockHandler implements PaymentHandlerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'dev.ucpws.mock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'mock_payment_handler';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return UCPWS_UCP_VERSION;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_spec_url(): ?string {
		return 'https://github.com/blikcom/ucp-server-for-woocommerce/blob/main/docs/payment-handlers.md#mock-handler';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_schema_url(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_available_instruments(): array {
		return array(
			array( 'type' => 'card' ),
		);
	}

	/**
	 * Handler configuration.
	 *
	 * @param \WC_Order|null          $order   Draft order (checkout responses) or null (profile).
	 * @param NegotiationContext|null $context Negotiation context.
	 * @return array<string, mixed>
	 */
	public function get_config( ?\WC_Order $order = null, ?NegotiationContext $context = null ): array {
		return array(
			'environment' => 'TEST',
		);
	}

	/**
	 * Availability filter (the mock handler applies to every checkout).
	 *
	 * @param \WC_Order          $order   Draft order.
	 * @param NegotiationContext $context Negotiation context.
	 * @return bool
	 */
	public function is_available( \WC_Order $order, NegotiationContext $context ): bool {
		return true;
	}

	/**
	 * Mock charge driven by the credential token.
	 *
	 * @param \WC_Order            $order      Draft order.
	 * @param array<string, mixed> $instrument Selected payment instrument.
	 * @param array<string, mixed> $request    Full complete_checkout request body.
	 * @param NegotiationContext   $context    Negotiation context.
	 * @return PaymentResult
	 */
	public function charge( \WC_Order $order, array $instrument, array $request, NegotiationContext $context ): PaymentResult {
		$credential = isset( $instrument['credential'] ) && is_array( $instrument['credential'] ) ? $instrument['credential'] : array();
		$type       = isset( $credential['type'] ) ? (string) $credential['type'] : '';

		if ( 'card' === $type ) {
			// Raw/tokenized card shapes are approved as-is (mock).
			return PaymentResult::success( Ids::prefixed( 'mocktxn', 8 ) );
		}

		$token = isset( $credential['token'] ) ? (string) $credential['token'] : '';

		switch ( $token ) {
			case 'success_token':
				return PaymentResult::success( Ids::prefixed( 'mocktxn', 8 ) );

			case 'fail_token':
				return PaymentResult::declined( 'Payment Failed: Insufficient Funds (Mock)', 'payment_failed', 402 );

			case 'fraud_token':
				return PaymentResult::declined( 'Payment Failed: Fraud Detected (Mock)', 'payment_failed', 403 );

			case 'escalate_token':
				return PaymentResult::escalation(
					'The bank requires buyer verification to authorize this payment.',
					null,
					'requires_3ds'
				);

			default:
				if ( 0 === strpos( $token, 'mandate_' ) ) {
					// Simulated merchant-stored mandate: charge without buyer present.
					return PaymentResult::success( Ids::prefixed( 'mockmit', 8 ) );
				}
				if ( '' === $token && '' !== $type ) {
					// Unknown-but-typed credential shapes are accepted (mock).
					return PaymentResult::success( Ids::prefixed( 'mocktxn', 8 ) );
				}
				return PaymentResult::declined( 'Payment Failed: Unknown mock token.', 'payment_failed', 402 );
		}//end switch
	}
}
