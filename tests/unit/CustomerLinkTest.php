<?php
/**
 * ADPT-001: linking platform orders to customer accounts.
 *
 * @package UCPWS
 */

use PHPUnit\Framework\TestCase;
use UCPWS\Checkout\CustomerLink;

/**
 * The rule that decides whether a UCP order belongs to a known customer.
 */
final class CustomerLinkTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ucpws_test_users'] = array();
	}

	public function test_a_known_email_resolves_to_that_user() {
		$GLOBALS['ucpws_test_users']['anna@example.test'] = 42;

		$this->assertSame( 42, CustomerLink::resolve( 'anna@example.test' ) );
	}

	public function test_an_unknown_email_resolves_to_nobody() {
		$this->assertSame( 0, CustomerLink::resolve( 'stranger@example.test' ) );
	}

	public function test_an_empty_email_resolves_to_nobody() {
		$this->assertSame( 0, CustomerLink::resolve( '' ) );
	}

	public function test_a_guest_order_with_a_known_buyer_is_linked() {
		$this->assertTrue( CustomerLink::should_link( 0, 42 ) );
	}

	public function test_a_guest_order_with_an_unknown_buyer_stays_a_guest_order() {
		// The behaviour that existed before this change must be unchanged.
		$this->assertFalse( CustomerLink::should_link( 0, 0 ) );
	}

	public function test_an_order_that_already_has_a_customer_is_never_re_pointed() {
		// A later buyer block must not move an order between accounts.
		$this->assertFalse( CustomerLink::should_link( 7, 42 ) );
		$this->assertFalse( CustomerLink::should_link( 7, 7 ) );
	}

	public function test_the_attribution_meta_key_is_stable() {
		// Merchant-side code and reports read this key; renaming it silently
		// would orphan every order already stamped.
		$this->assertSame( '_ucpws_platform', CustomerLink::META_PLATFORM_ORIGIN );
	}
}
