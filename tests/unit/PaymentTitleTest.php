<?php
/**
 * What a customer reads as the payment method.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Payments\PaymentTitle;
use UCPWS\Tests\Stubs\NamedHandler;
use UCPWS\Tests\Stubs\UnnamedHandler;

class PaymentTitleTest extends TestCase {

	public function test_a_handler_that_knows_its_name_is_asked(): void {
		$this->assertSame( 'BLIK', PaymentTitle::of( new NamedHandler() ) );
	}

	public function test_a_handler_that_does_not_keeps_the_previous_behaviour(): void {
		// Not good - it is a registry key - but it is what the checkout did for
		// every handler before this existed, and there is nothing better to
		// invent on a third-party handler's behalf.
		$this->assertSame( 'com.example.tokenizer', PaymentTitle::of( new UnnamedHandler() ) );
	}

	public function test_a_blank_title_is_not_an_answer(): void {
		// An empty payment method on an order reads as "we do not know how this
		// was paid for", which is worse than a reverse-domain key.
		$this->assertSame( 'com.example.tokenizer', PaymentTitle::of( new NamedHandler( '' ) ) );
		$this->assertSame( 'com.example.tokenizer', PaymentTitle::of( new NamedHandler( "  \t " ) ) );
	}

	public function test_a_padded_title_is_trimmed(): void {
		$this->assertSame( 'BLIK', PaymentTitle::of( new NamedHandler( '  BLIK  ' ) ) );
	}

	public function test_the_registry_key_never_reaches_a_named_handler_title(): void {
		// The regression this exists to prevent: a reverse-domain identifier on
		// the customer's order page and in their e-mail.
		$this->assertStringNotContainsString( 'com.', PaymentTitle::of( new NamedHandler() ) );
	}
}
