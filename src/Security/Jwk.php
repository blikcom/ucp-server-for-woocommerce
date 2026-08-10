<?php
/**
 * JWK helpers.
 *
 * @package UCPWS
 */

namespace UCPWS\Security;

defined( 'ABSPATH' ) || exit;

/**
 * EC P-256 JWK to PEM conversion (for verifying counterparty signatures).
 */
final class Jwk {

	/**
	 * Convert an EC P-256 public JWK to a PEM SubjectPublicKeyInfo.
	 *
	 * @param array<string, mixed> $jwk JWK with kty=EC, crv=P-256, x, y.
	 * @return string|null PEM string, or null if unsupported/invalid.
	 */
	public static function to_public_pem( array $jwk ): ?string {
		if ( ( $jwk['kty'] ?? '' ) !== 'EC' || ( $jwk['crv'] ?? '' ) !== 'P-256' ) {
			return null;
		}

		$x = self::b64url_decode( (string) ( $jwk['x'] ?? '' ) );
		$y = self::b64url_decode( (string) ( $jwk['y'] ?? '' ) );
		if ( null === $x || null === $y ) {
			return null;
		}

		$x = str_pad( $x, 32, "\0", STR_PAD_LEFT );
		$y = str_pad( $y, 32, "\0", STR_PAD_LEFT );
		if ( 32 !== strlen( $x ) || 32 !== strlen( $y ) ) {
			return null;
		}

		// SubjectPublicKeyInfo for id-ecPublicKey + prime256v1 with an
		// uncompressed EC point (0x04 || X || Y).
		$point = "\x04" . $x . $y;

		// AlgorithmIdentifier: the ecPublicKey OID followed by the prime256v1 curve OID.
		$algorithm = "\x30\x13"
			. "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
			. "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

		$bit_string = "\x03" . chr( strlen( $point ) + 1 ) . "\x00" . $point;
		$spki       = "\x30" . chr( strlen( $algorithm . $bit_string ) ) . $algorithm . $bit_string;

		return "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $spki ), 64, "\n" ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- DER encoding.
			. "-----END PUBLIC KEY-----\n";
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Base64url string.
	 * @return string|null
	 */
	private static function b64url_decode( string $data ): ?string {
		if ( '' === $data ) {
			return null;
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWK decoding.
		return false === $decoded ? null : $decoded;
	}
}
