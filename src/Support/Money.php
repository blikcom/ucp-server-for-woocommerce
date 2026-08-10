<?php
/**
 * Minor-unit money conversion.
 *
 * @package UCPWS
 */

namespace UCPWS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between WooCommerce decimal amounts and ISO 4217 minor units.
 *
 * UCP amounts are integers in the currency's minor unit (e.g. cents). WooCommerce
 * stores decimal strings, so conversion is done with string arithmetic to avoid
 * float precision loss.
 */
final class Money {

	/**
	 * ISO 4217 exponent exceptions (currency => number of minor unit digits).
	 *
	 * @var array<string, int>
	 */
	private const EXPONENTS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * Number of minor-unit digits for a currency.
	 *
	 * @param string $currency ISO 4217 alpha code.
	 * @return int
	 */
	public static function exponent( string $currency ): int {
		$currency = strtoupper( $currency );
		$exponent = self::EXPONENTS[ $currency ] ?? 2;

		/**
		 * Filters the ISO 4217 exponent used for minor-unit conversion.
		 *
		 * @param int    $exponent Number of minor-unit digits.
		 * @param string $currency Currency code.
		 */
		return (int) apply_filters( 'ucpws_currency_exponent', $exponent, $currency );
	}

	/**
	 * Convert a decimal amount (string/float/int) to integer minor units.
	 *
	 * @param string|float|int $amount   Decimal amount (e.g. "12.34").
	 * @param string           $currency ISO 4217 alpha code.
	 * @return int
	 */
	public static function to_minor( $amount, string $currency ): int {
		$exponent = self::exponent( $currency );
		$decimal  = self::normalize_decimal( $amount, $exponent );

		$negative = '-' === $decimal[0];
		if ( $negative ) {
			$decimal = substr( $decimal, 1 );
		}

		$parts    = explode( '.', $decimal, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$fraction = $parts[1] ?? '';
		$fraction = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );

		$digits = ltrim( $integer . $fraction, '0' );
		if ( '' === $digits ) {
			return 0;
		}

		$value = (int) $digits;
		return $negative ? -$value : $value;
	}

	/**
	 * Convert integer minor units to a decimal string.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217 alpha code.
	 * @return string Decimal string (e.g. "12.34").
	 */
	public static function to_decimal( int $minor, string $currency ): string {
		$exponent = self::exponent( $currency );
		$negative = $minor < 0;
		$digits   = (string) abs( $minor );

		if ( 0 === $exponent ) {
			return ( $negative ? '-' : '' ) . $digits;
		}

		$digits   = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		$integer  = substr( $digits, 0, -$exponent );
		$fraction = substr( $digits, -$exponent );

		return ( $negative ? '-' : '' ) . $integer . '.' . $fraction;
	}

	/**
	 * Normalize an arbitrary numeric input to a rounded plain decimal string.
	 *
	 * @param string|float|int $amount   Input amount.
	 * @param int              $exponent Decimal places to keep.
	 * @return string
	 */
	private static function normalize_decimal( $amount, int $exponent ): string {
		if ( is_string( $amount ) ) {
			$amount = trim( $amount );
			if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $amount ) ) {
				// Locale-tolerant fallback: strip thousands separators, normalize comma decimals.
				$normalized = str_replace( array( ' ', "\xc2\xa0" ), '', $amount );
				if ( false !== strpos( $normalized, ',' ) && false === strpos( $normalized, '.' ) ) {
					$normalized = str_replace( ',', '.', $normalized );
				} else {
					$normalized = str_replace( ',', '', $normalized );
				}
				$amount = (float) $normalized;
			}
		}

		if ( is_float( $amount ) || is_int( $amount ) ) {
			$amount = number_format( (float) $amount, $exponent + 2, '.', '' );
		}

		// Round half-up at the target exponent using string math via number_format on a float
		// only when needed; amounts here come from WooCommerce totals which are already
		// rounded to the store's decimal setting.
		$parts    = explode( '.', (string) $amount, 2 );
		$fraction = $parts[1] ?? '';

		if ( strlen( $fraction ) > $exponent ) {
			// Defer to float rounding for the (rare) extra-precision case.
			return number_format( (float) $amount, $exponent, '.', '' );
		}

		return (string) $amount;
	}
}
