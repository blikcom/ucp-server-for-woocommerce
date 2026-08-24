<?php
/**
 * Test double: a payment handler that does know its own name.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Stubs;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Payments\PaymentHandlerTitle;

/**
 * A handler that opts into naming itself.
 */
class NamedHandler extends UnnamedHandler implements PaymentHandlerTitle {

	/**
	 * The name this handler reports.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * @param string $title What the payment is called.
	 */
	public function __construct( string $title = 'BLIK' ) {
		$this->title = $title;
	}

	public function get_title(): string {
		return $this->title;
	}
}
