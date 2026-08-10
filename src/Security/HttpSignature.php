<?php
/**
 * RFC 9421 HTTP Message Signatures (ES256).
 *
 * @package UCPWS
 */

namespace UCPWS\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal RFC 9421 implementation covering the UCP profile:
 *
 * - Derived components: `@method`, `@authority`, `@path`, `@query`, `@status`.
 * - Field components: lowercase header names.
 * - Signature params: `created` (optional) and `keyid`; `alg` is intentionally
 *   omitted per the UCP signatures spec (derived from the JWK `crv`).
 * - ECDSA P-256 / SHA-256 with fixed-width raw `r || s` signatures (64 bytes),
 *   converted to/from the DER encoding used by OpenSSL.
 * - Content digests per RFC 9530 (`sha-256=:base64:`, raw body bytes).
 */
class HttpSignature {

	/**
	 * RFC 9530 Content-Digest header value for a body.
	 *
	 * @param string $body Raw body bytes.
	 * @return string
	 */
	public static function content_digest( string $body ): string {
		return 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 9530 encoding.
	}

	/**
	 * Sign an outgoing HTTP request.
	 *
	 * @param string                $method      HTTP method.
	 * @param string                $url         Absolute request URL.
	 * @param array<string, string> $headers     Headers to cover (lowercase name => value). Order defines coverage order.
	 * @param string                $private_pem EC P-256 private key PEM.
	 * @param string                $kid         Key id to advertise.
	 * @param int|null              $created     Unix timestamp for the `created` param (null to omit).
	 * @return array{Signature-Input: string, Signature: string}|null Signature headers, or null on failure.
	 */
	public static function sign_request( string $method, string $url, array $headers, string $private_pem, string $kid, ?int $created = null ) {
		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['host'] ) ) {
			return null;
		}

		$authority = strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) && ! self::is_default_port( $parts['scheme'] ?? 'https', (int) $parts['port'] ) ) {
			$authority .= ':' . $parts['port'];
		}

		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = $parts['query'] ?? null;

		$components = array(
			'@method'    => strtoupper( $method ),
			'@authority' => $authority,
			'@path'      => $path,
		);
		if ( null !== $query && '' !== $query ) {
			$components['@query'] = '?' . $query;
		}
		foreach ( $headers as $name => $value ) {
			$components[ strtolower( $name ) ] = trim( $value );
		}

		return self::sign_components( $components, $private_pem, $kid, $created );
	}

	/**
	 * Sign an HTTP response (uses `@status`).
	 *
	 * @param int                   $status      HTTP status code.
	 * @param array<string, string> $headers     Headers to cover (lowercase name => value).
	 * @param string                $private_pem EC P-256 private key PEM.
	 * @param string                $kid         Key id.
	 * @param int|null              $created     Unix timestamp (null to omit).
	 * @return array{Signature-Input: string, Signature: string}|null
	 */
	public static function sign_response( int $status, array $headers, string $private_pem, string $kid, ?int $created = null ) {
		$components = array(
			'@status' => (string) $status,
		);
		foreach ( $headers as $name => $value ) {
			$components[ strtolower( $name ) ] = trim( $value );
		}

		return self::sign_components( $components, $private_pem, $kid, $created );
	}

	/**
	 * Sign an ordered component map.
	 *
	 * @param array<string, string> $components  Ordered component identifier => value.
	 * @param string                $private_pem Private key PEM.
	 * @param string                $kid         Key id.
	 * @param int|null              $created     Unix timestamp (null to omit).
	 * @return array{Signature-Input: string, Signature: string}|null
	 */
	public static function sign_components( array $components, string $private_pem, string $kid, ?int $created = null ) {
		$params = self::serialize_params( array_keys( $components ), $kid, $created );
		$base   = self::signature_base( $components, $params );

		$key = openssl_pkey_get_private( $private_pem );
		if ( false === $key ) {
			return null;
		}

		if ( ! openssl_sign( $base, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		$raw = self::der_to_raw( $der, 32 );
		if ( null === $raw ) {
			return null;
		}

		return array(
			'Signature-Input' => 'sig1=' . $params,
			'Signature'       => 'sig1=:' . base64_encode( $raw ) . ':', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 9421 encoding.
		);
	}

	/**
	 * Verify a signature over an ordered component map.
	 *
	 * @param array<string, string> $components      Ordered component identifier => value (must match covered order).
	 * @param string                $signature_input Full Signature-Input header value.
	 * @param string                $signature       Full Signature header value.
	 * @param string                $public_pem_or_jwk Public key PEM, or JWK array via Jwk::to_pem() beforehand.
	 * @return bool
	 */
	public static function verify_components( array $components, string $signature_input, string $signature, string $public_pem_or_jwk ): bool {
		$parsed = self::parse_signature_input( $signature_input );
		if ( null === $parsed ) {
			return false;
		}

		// Rebuild the base using the covered component order from the header.
		$ordered = array();
		foreach ( $parsed['components'] as $identifier ) {
			if ( ! array_key_exists( $identifier, $components ) ) {
				return false;
			}
			$ordered[ $identifier ] = $components[ $identifier ];
		}

		$base = self::signature_base( $ordered, $parsed['params'] );

		$raw = self::extract_signature_bytes( $signature );
		if ( null === $raw || 64 !== strlen( $raw ) ) {
			return false;
		}

		$der = self::raw_to_der( $raw );
		$key = openssl_pkey_get_public( $public_pem_or_jwk );
		if ( false === $key ) {
			return false;
		}

		return 1 === openssl_verify( $base, $der, $key, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Parse a Signature-Input header (single signature label).
	 *
	 * @param string $header Signature-Input value, e.g. `sig1=("@method" "content-digest");keyid="abc";created=123`.
	 * @return array{label: string, components: string[], params: string, keyid: string|null, created: int|null}|null
	 */
	public static function parse_signature_input( string $header ) {
		if ( ! preg_match( '/^\s*([!#$%&\'*+\-.^_`|~0-9a-zA-Z]+)=(\(.*)$/s', trim( $header ), $m ) ) {
			return null;
		}
		$label = $m[1];
		$rest  = $m[2];

		if ( ! preg_match( '/^\(([^)]*)\)(.*)$/s', $rest, $m2 ) ) {
			return null;
		}

		$components = array();
		if ( preg_match_all( '/"([^"]+)"/', $m2[1], $matches ) ) {
			$components = $matches[1];
		}

		$keyid   = null;
		$created = null;
		if ( preg_match( '/;keyid="([^"]*)"/', $m2[2], $mk ) ) {
			$keyid = $mk[1];
		}
		if ( preg_match( '/;created=(\d+)/', $m2[2], $mc ) ) {
			$created = (int) $mc[1];
		}

		return array(
			'label'      => $label,
			'components' => $components,
			'params'     => '(' . $m2[1] . ')' . rtrim( $m2[2] ),
			'keyid'      => $keyid,
			'created'    => $created,
		);
	}

	/**
	 * Extract raw signature bytes from a Signature header.
	 *
	 * @param string $header Signature header value, e.g. `sig1=:BASE64:`.
	 * @return string|null
	 */
	public static function extract_signature_bytes( string $header ): ?string {
		if ( ! preg_match( '/=\s*:([A-Za-z0-9+\/=]+):/', $header, $m ) ) {
			return null;
		}
		$decoded = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 9421 decoding.
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Build the RFC 9421 signature base string.
	 *
	 * @param array<string, string> $components Ordered component identifier => value.
	 * @param string                $params     Serialized signature params.
	 * @return string
	 */
	public static function signature_base( array $components, string $params ): string {
		$lines = array();
		foreach ( $components as $identifier => $value ) {
			$lines[] = '"' . $identifier . '": ' . $value;
		}
		$lines[] = '"@signature-params": ' . $params;

		return implode( "\n", $lines );
	}

	/**
	 * Serialize signature params: `("a" "b");created=...;keyid="..."`.
	 *
	 * @param string[] $identifiers Covered component identifiers, in order.
	 * @param string   $kid         Key id.
	 * @param int|null $created     Unix timestamp or null.
	 * @return string
	 */
	private static function serialize_params( array $identifiers, string $kid, ?int $created ): string {
		$quoted = array_map(
			static function ( $identifier ) {
				return '"' . $identifier . '"';
			},
			$identifiers
		);

		$params = '(' . implode( ' ', $quoted ) . ')';
		if ( null !== $created ) {
			$params .= ';created=' . $created;
		}
		$params .= ';keyid="' . $kid . '"';

		return $params;
	}

	/**
	 * Convert a DER ECDSA signature to fixed-width raw r||s.
	 *
	 * @param string $der  DER-encoded signature.
	 * @param int    $size Integer size in bytes (32 for P-256).
	 * @return string|null
	 */
	public static function der_to_raw( string $der, int $size ): ?string {
		$offset = 0;
		$length = strlen( $der );

		if ( $length < 2 || "\x30" !== $der[ $offset ] ) {
			return null;
		}
		++$offset;
		$offset += self::der_length_size( $der, $offset );

		$r = self::der_read_integer( $der, $offset, $size );
		if ( null === $r ) {
			return null;
		}
		$s = self::der_read_integer( $der, $offset, $size );
		if ( null === $s ) {
			return null;
		}

		return $r . $s;
	}

	/**
	 * Convert a raw r||s signature to DER.
	 *
	 * @param string $raw Raw signature (r || s).
	 * @return string
	 */
	public static function raw_to_der( string $raw ): string {
		$half = (int) ( strlen( $raw ) / 2 );
		$r    = self::der_encode_integer( substr( $raw, 0, $half ) );
		$s    = self::der_encode_integer( substr( $raw, $half ) );
		$body = $r . $s;

		return "\x30" . self::der_encode_length( strlen( $body ) ) . $body;
	}

	/**
	 * Read one DER INTEGER and return it left-padded to $size bytes.
	 *
	 * @param string $der    DER bytes.
	 * @param int    $offset Read offset (advanced).
	 * @param int    $size   Target size.
	 * @return string|null
	 */
	private static function der_read_integer( string $der, int &$offset, int $size ): ?string {
		if ( $offset >= strlen( $der ) || "\x02" !== $der[ $offset ] ) {
			return null;
		}
		++$offset;

		$len = ord( $der[ $offset ] );
		++$offset;

		$value   = substr( $der, $offset, $len );
		$offset += $len;

		$value = ltrim( $value, "\0" );
		if ( strlen( $value ) > $size ) {
			return null;
		}

		return str_pad( $value, $size, "\0", STR_PAD_LEFT );
	}

	/**
	 * DER-encode a positive INTEGER from raw bytes.
	 *
	 * @param string $bytes Raw unsigned big-endian integer.
	 * @return string
	 */
	private static function der_encode_integer( string $bytes ): string {
		$bytes = ltrim( $bytes, "\0" );
		if ( '' === $bytes ) {
			$bytes = "\0";
		}
		if ( ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\0" . $bytes;
		}
		return "\x02" . chr( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * DER length octets.
	 *
	 * @param int $length Content length.
	 * @return string
	 */
	private static function der_encode_length( int $length ): string {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		$bytes = ltrim( pack( 'N', $length ), "\0" );
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Size of the DER length field at an offset (advances past it).
	 *
	 * @param string $der    DER bytes.
	 * @param int    $offset Offset of the length field.
	 * @return int Number of bytes occupied by the length field.
	 */
	private static function der_length_size( string $der, int $offset ): int {
		$first = ord( $der[ $offset ] );
		if ( $first < 0x80 ) {
			return 1;
		}
		return 1 + ( $first & 0x7f );
	}

	/**
	 * Whether a port is the default for a scheme.
	 *
	 * @param string $scheme URL scheme.
	 * @param int    $port   Port number.
	 * @return bool
	 */
	private static function is_default_port( string $scheme, int $port ): bool {
		return ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
	}
}
