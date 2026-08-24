<?php
/**
 * Test double: a payment handler that does not know its own name.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Stubs;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Payments\PaymentHandlerInterface;

/**
 * A handler that never heard of PaymentHandlerTitle.
 *
 * Only get_name()/get_id() are ever exercised; the rest exist because the
 * interface demands them.
 */
class UnnamedHandler implements PaymentHandlerInterface {

	public function get_name(): string {
		return 'com.example.tokenizer';
	}

	public function get_id(): string {
		return 'tokenizer';
	}

	public function get_version(): string {
		return '2026-08-24';
	}

	public function get_spec_url(): ?string {
		return null;
	}

	public function get_schema_url(): ?string {
		return null;
	}

	public function get_available_instruments(): array {
		return array();
	}

	public function get_config( ?\WC_Order $order = null, ?NegotiationContext $context = null ): array {
		return array();
	}

	public function is_available( \WC_Order $order, NegotiationContext $context ): bool {
		return true;
	}

	public function charge( \WC_Order $order, array $instrument, array $request, NegotiationContext $context ): \UCPWS\Payments\PaymentResult {
		throw new \LogicException( 'not exercised by these tests' );
	}
}
