<?php
/**
 * Small unit tests: ids, error mapping, idempotency hashing, JWK export.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Security\SigningKeys;
use UCPWS\Storage\IdempotencyStore;
use UCPWS\Support\Ids;

class MiscUnitTest extends TestCase {

	public function test_uuid4_shape(): void {
		$uuid = Ids::uuid4();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid );
		$this->assertNotSame( $uuid, Ids::uuid4() );
	}

	public function test_prefixed_ids(): void {
		$id = Ids::prefixed( 'chk', 16 );
		$this->assertMatchesRegularExpression( '/^chk_[0-9a-f]{32}$/', $id );
	}

	public function test_error_code_http_mapping(): void {
		$this->assertSame( 400, ErrorCodes::http_status( ErrorCodes::INVALID_PROFILE_URL ) );
		$this->assertSame( 424, ErrorCodes::http_status( ErrorCodes::PROFILE_UNREACHABLE ) );
		$this->assertSame( 422, ErrorCodes::http_status( ErrorCodes::PROFILE_MALFORMED ) );
		$this->assertSame( 422, ErrorCodes::http_status( ErrorCodes::VERSION_UNSUPPORTED ) );
		$this->assertSame( 401, ErrorCodes::http_status( ErrorCodes::SIGNATURE_INVALID ) );
		$this->assertSame( 400, ErrorCodes::http_status( ErrorCodes::DIGEST_MISMATCH ) );
		$this->assertSame( 403, ErrorCodes::http_status( ErrorCodes::PROFILE_NOT_TRUSTED ) );
	}

	public function test_jsonrpc_code_mapping(): void {
		$this->assertSame( -32001, UcpException::transport( ErrorCodes::PROFILE_UNREACHABLE, 'x', 424 )->get_jsonrpc_code() );
		$this->assertSame( -32001, UcpException::transport( ErrorCodes::VERSION_UNSUPPORTED, 'x', 422 )->get_jsonrpc_code() );
		$this->assertSame( -32600, UcpException::transport( ErrorCodes::DIGEST_MISMATCH, 'x', 400 )->get_jsonrpc_code() );
		$this->assertSame( -32000, UcpException::transport( 'unauthorized', 'x', 401 )->get_jsonrpc_code() );
		$this->assertSame( -32000, UcpException::transport( 'idempotency_conflict', 'x', 409 )->get_jsonrpc_code() );
		$this->assertSame( -32603, UcpException::transport( 'internal_error', 'x', 500 )->get_jsonrpc_code() );
	}

	public function test_business_outcome_message_shape(): void {
		$e = UcpException::business( ErrorCodes::OUT_OF_STOCK, 'All requested items are currently out of stock' );
		$this->assertTrue( $e->is_business_outcome() );
		$this->assertSame( 200, $e->get_http_status() );
		$message = $e->to_message();
		$this->assertSame( 'error', $message['type'] );
		$this->assertSame( 'out_of_stock', $message['code'] );
		$this->assertSame( 'unrecoverable', $message['severity'] );
	}

	public function test_idempotency_hash_is_key_order_insensitive(): void {
		$store = new IdempotencyStore();

		$a = $store->hash_payload(
			array(
				'b' => 1,
				'a' => array(
					'y' => 2,
					'x' => 3,
				),
			)
		);
		$b = $store->hash_payload(
			array(
				'a' => array(
					'x' => 3,
					'y' => 2,
				),
				'b' => 1,
			)
		);

		$this->assertSame( $a, $b );
	}

	public function test_idempotency_hash_differs_for_different_payloads(): void {
		$store = new IdempotencyStore();
		$this->assertNotSame(
			$store->hash_payload( array( 'currency' => 'USD' ) ),
			$store->hash_payload( array( 'currency' => 'EUR' ) )
		);
	}

	public function test_public_jwk_has_padded_coordinates(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$resource = openssl_pkey_new(
				array(
					'curve_name'       => 'prime256v1',
					'private_key_type' => OPENSSL_KEYTYPE_EC,
				)
			);
			openssl_pkey_export( $resource, $pem );
			$jwk = SigningKeys::pem_to_public_jwk( $pem, 'k' . $i );

			$this->assertNotNull( $jwk );
			$this->assertSame( 'EC', $jwk['kty'] );
			$this->assertSame( 'P-256', $jwk['crv'] );
			$this->assertSame( 'ES256', $jwk['alg'] );
			// base64url of 32 bytes is 43 chars unpadded.
			$this->assertSame( 43, strlen( $jwk['x'] ) );
			$this->assertSame( 43, strlen( $jwk['y'] ) );
		}
	}
}
