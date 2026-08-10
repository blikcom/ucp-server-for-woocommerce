<?php
/**
 * Money conversion tests.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Support\Money;

class MoneyTest extends TestCase {

	public function test_to_minor_two_decimal_currency(): void {
		$this->assertSame( 1234, Money::to_minor( '12.34', 'USD' ) );
		$this->assertSame( 1234, Money::to_minor( 12.34, 'USD' ) );
		$this->assertSame( 3500, Money::to_minor( '35', 'USD' ) );
		$this->assertSame( 3500, Money::to_minor( '35.00', 'EUR' ) );
		$this->assertSame( 0, Money::to_minor( '0', 'USD' ) );
		$this->assertSame( 1, Money::to_minor( '0.01', 'USD' ) );
		$this->assertSame( 10, Money::to_minor( '0.1', 'USD' ) );
	}

	public function test_to_minor_zero_decimal_currency(): void {
		$this->assertSame( 1234, Money::to_minor( '1234', 'JPY' ) );
		$this->assertSame( 1234, Money::to_minor( '1234.00', 'JPY' ) );
	}

	public function test_to_minor_three_decimal_currency(): void {
		$this->assertSame( 12345, Money::to_minor( '12.345', 'KWD' ) );
		$this->assertSame( 12340, Money::to_minor( '12.34', 'KWD' ) );
	}

	public function test_to_minor_negative_amounts(): void {
		$this->assertSame( -350, Money::to_minor( '-3.50', 'USD' ) );
		$this->assertSame( -1, Money::to_minor( '-0.01', 'USD' ) );
	}

	public function test_to_minor_large_amounts_no_float_loss(): void {
		$this->assertSame( 99999999999, Money::to_minor( '999999999.99', 'USD' ) );
		$this->assertSame( 123456789012, Money::to_minor( '1234567890.12', 'PLN' ) );
	}

	public function test_to_minor_rounds_extra_precision(): void {
		$this->assertSame( 1235, Money::to_minor( '12.345', 'USD' ) );
		$this->assertSame( 1234, Money::to_minor( '12.344', 'USD' ) );
	}

	public function test_to_decimal_roundtrip(): void {
		$this->assertSame( '12.34', Money::to_decimal( 1234, 'USD' ) );
		$this->assertSame( '-3.50', Money::to_decimal( -350, 'USD' ) );
		$this->assertSame( '1234', Money::to_decimal( 1234, 'JPY' ) );
		$this->assertSame( '0.05', Money::to_decimal( 5, 'USD' ) );
		$this->assertSame( '12.345', Money::to_decimal( 12345, 'BHD' ) );

		foreach ( array( 1, 99, 100, 101, 999999999 ) as $minor ) {
			$this->assertSame( $minor, Money::to_minor( Money::to_decimal( $minor, 'USD' ), 'USD' ) );
		}
	}

	public function test_exponent(): void {
		$this->assertSame( 2, Money::exponent( 'USD' ) );
		$this->assertSame( 2, Money::exponent( 'PLN' ) );
		$this->assertSame( 0, Money::exponent( 'JPY' ) );
		$this->assertSame( 3, Money::exponent( 'KWD' ) );
	}
}
