<?php
/**
 * RFC 9421 signature tests.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Security\HttpSignature;
use UCPWS\Security\Jwk;
use UCPWS\Security\SigningKeys;

class HttpSignatureTest extends TestCase {

	/** @var string */
	private static $pem;

	public static function setUpBeforeClass(): void {
		$resource = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		openssl_pkey_export( $resource, self::$pem );
	}

	public function test_content_digest_rfc9530_vector(): void {
		// RFC 9530 example: sha-256 of {"hello": "world"}.
		$this->assertSame(
			'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:',
			HttpSignature::content_digest( '{"hello": "world"}' )
		);
	}

	public function test_sign_and_verify_request_roundtrip(): void {
		$body    = '{"id":"order_1","totals":[{"type":"total","amount":100}]}';
		$digest  = HttpSignature::content_digest( $body );
		$headers = array(
			'ucp-agent'       => 'profile="https://shop.example/.well-known/ucp"',
			'idempotency-key' => '550e8400-e29b-41d4-a716-446655440000',
			'content-digest'  => $digest,
			'content-type'    => 'application/json',
		);

		$signed = HttpSignature::sign_request( 'POST', 'https://platform.example/webhooks/ucp/orders', $headers, self::$pem, 'test-key', 1738617601 );

		$this->assertNotNull( $signed );
		$this->assertArrayHasKey( 'Signature-Input', $signed );
		$this->assertArrayHasKey( 'Signature', $signed );
		$this->assertStringContainsString( 'keyid="test-key"', $signed['Signature-Input'] );
		$this->assertStringContainsString( '"@method"', $signed['Signature-Input'] );
		$this->assertStringContainsString( '"@authority"', $signed['Signature-Input'] );
		$this->assertStringContainsString( '"ucp-agent"', $signed['Signature-Input'] );
		$this->assertStringNotContainsString( 'alg=', $signed['Signature-Input'] );

		// Raw r||s signatures are 64 bytes for P-256.
		$raw = HttpSignature::extract_signature_bytes( $signed['Signature'] );
		$this->assertNotNull( $raw );
		$this->assertSame( 64, strlen( $raw ) );

		// Verify against the public key derived from the same PEM (JWK path).
		$jwk = SigningKeys::pem_to_public_jwk( self::$pem, 'test-key' );
		$this->assertNotNull( $jwk );
		$public_pem = Jwk::to_public_pem( $jwk );
		$this->assertNotNull( $public_pem );

		$components = array(
			'@method'         => 'POST',
			'@authority'      => 'platform.example',
			'@path'           => '/webhooks/ucp/orders',
			'ucp-agent'       => $headers['ucp-agent'],
			'idempotency-key' => $headers['idempotency-key'],
			'content-digest'  => $digest,
			'content-type'    => 'application/json',
		);

		$this->assertTrue(
			HttpSignature::verify_components( $components, $signed['Signature-Input'], $signed['Signature'], $public_pem )
		);
	}

	public function test_verify_fails_on_tampered_component(): void {
		$headers = array( 'content-type' => 'application/json' );
		$signed  = HttpSignature::sign_request( 'POST', 'https://platform.example/hook', $headers, self::$pem, 'k1' );
		$this->assertNotNull( $signed );

		$jwk        = SigningKeys::pem_to_public_jwk( self::$pem, 'k1' );
		$public_pem = Jwk::to_public_pem( $jwk );

		$components = array(
			'@method'      => 'PUT', // Tampered: was POST.
			'@authority'   => 'platform.example',
			'@path'        => '/hook',
			'content-type' => 'application/json',
		);

		$this->assertFalse(
			HttpSignature::verify_components( $components, $signed['Signature-Input'], $signed['Signature'], $public_pem )
		);
	}

	public function test_verify_fails_with_wrong_key(): void {
		$headers = array( 'content-type' => 'application/json' );
		$signed  = HttpSignature::sign_request( 'POST', 'https://platform.example/hook', $headers, self::$pem, 'k1' );

		$other = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		openssl_pkey_export( $other, $other_pem );
		$jwk        = SigningKeys::pem_to_public_jwk( $other_pem, 'k2' );
		$public_pem = Jwk::to_public_pem( $jwk );

		$components = array(
			'@method'      => 'POST',
			'@authority'   => 'platform.example',
			'@path'        => '/hook',
			'content-type' => 'application/json',
		);

		$this->assertFalse(
			HttpSignature::verify_components( $components, $signed['Signature-Input'], $signed['Signature'], $public_pem )
		);
	}

	public function test_der_raw_conversion_roundtrip(): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$raw = random_bytes( 64 );
			// Ensure high-bit variance is exercised.
			$der  = HttpSignature::raw_to_der( $raw );
			$back = HttpSignature::der_to_raw( $der, 32 );
			$this->assertSame( bin2hex( $raw ), bin2hex( (string) $back ) );
		}
	}

	public function test_parse_signature_input(): void {
		$parsed = HttpSignature::parse_signature_input(
			'sig1=("@method" "@authority" "@path" "content-digest");created=1738617601;keyid="merchant-2026"'
		);

		$this->assertNotNull( $parsed );
		$this->assertSame( 'sig1', $parsed['label'] );
		$this->assertSame( array( '@method', '@authority', '@path', 'content-digest' ), $parsed['components'] );
		$this->assertSame( 'merchant-2026', $parsed['keyid'] );
		$this->assertSame( 1738617601, $parsed['created'] );
	}

	public function test_authority_includes_non_default_port(): void {
		$headers = array( 'content-type' => 'application/json' );
		$signed  = HttpSignature::sign_request( 'POST', 'http://localhost:8284/webhooks/x', $headers, self::$pem, 'k1' );
		$this->assertNotNull( $signed );

		$jwk        = SigningKeys::pem_to_public_jwk( self::$pem, 'k1' );
		$public_pem = Jwk::to_public_pem( $jwk );

		$components = array(
			'@method'      => 'POST',
			'@authority'   => 'localhost:8284',
			'@path'        => '/webhooks/x',
			'content-type' => 'application/json',
		);

		$this->assertTrue(
			HttpSignature::verify_components( $components, $signed['Signature-Input'], $signed['Signature'], $public_pem )
		);
	}
}
