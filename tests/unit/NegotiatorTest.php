<?php
/**
 * Capability intersection tests.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Negotiation\Negotiator;
use UCPWS\Negotiation\ProfileFetcher;
use UCPWS\Payments\HandlerRegistry;
use UCPWS\Security\SigningKeys;

class NegotiatorTest extends TestCase {

	private function negotiator(): Negotiator {
		return new Negotiator(
			new ProfileFetcher(),
			new ProfileBuilder( new HandlerRegistry(), new SigningKeys() )
		);
	}

	public function test_name_intersection_and_highest_mutual_version(): void {
		$business = array(
			'dev.ucp.shopping.checkout' => array(
				array( 'version' => '2026-01-11' ),
				array( 'version' => '2026-04-08' ),
			),
			'dev.ucp.shopping.order'    => array(
				array( 'version' => '2026-04-08' ),
			),
		);
		$platform = array(
			'dev.ucp.shopping.checkout' => array(
				array( 'version' => '2026-01-11' ),
				array( 'version' => '2026-04-08' ),
			),
			'dev.ucp.shopping.cart'     => array(
				array( 'version' => '2026-04-08' ),
			),
		);

		$result = $this->negotiator()->intersect( $business, $platform );

		$this->assertSame( array( 'dev.ucp.shopping.checkout' => '2026-04-08' ), $result );
	}

	public function test_no_mutual_version_excludes_capability(): void {
		$business = array(
			'dev.ucp.shopping.checkout' => array( array( 'version' => '2026-04-08' ) ),
		);
		$platform = array(
			'dev.ucp.shopping.checkout' => array( array( 'version' => '2026-01-11' ) ),
		);

		$this->assertSame( array(), $this->negotiator()->intersect( $business, $platform ) );
	}

	public function test_orphaned_extension_is_pruned(): void {
		$business = array(
			'dev.ucp.shopping.checkout'    => array( array( 'version' => '2026-04-08' ) ),
			'dev.ucp.shopping.fulfillment' => array(
				array(
					'version' => '2026-04-08',
					'extends' => 'dev.ucp.shopping.checkout',
				),
			),
		);
		// Platform supports fulfillment but NOT checkout: fulfillment must be pruned.
		$platform = array(
			'dev.ucp.shopping.fulfillment' => array( array( 'version' => '2026-04-08' ) ),
		);

		$this->assertSame( array(), $this->negotiator()->intersect( $business, $platform ) );
	}

	public function test_extension_with_present_parent_survives(): void {
		$business = array(
			'dev.ucp.shopping.checkout'    => array( array( 'version' => '2026-04-08' ) ),
			'dev.ucp.shopping.fulfillment' => array(
				array(
					'version' => '2026-04-08',
					'extends' => 'dev.ucp.shopping.checkout',
				),
			),
		);
		$platform = array(
			'dev.ucp.shopping.checkout'    => array( array( 'version' => '2026-04-08' ) ),
			'dev.ucp.shopping.fulfillment' => array( array( 'version' => '2026-04-08' ) ),
		);

		$result = $this->negotiator()->intersect( $business, $platform );

		$this->assertArrayHasKey( 'dev.ucp.shopping.fulfillment', $result );
	}

	public function test_multi_parent_extension_needs_at_least_one_parent(): void {
		$business = array(
			'dev.ucp.shopping.checkout' => array( array( 'version' => '2026-04-08' ) ),
			'dev.ucp.shopping.discount' => array(
				array(
					'version' => '2026-04-08',
					'extends' => array( 'dev.ucp.shopping.checkout', 'dev.ucp.shopping.cart' ),
				),
			),
		);
		$platform = array(
			'dev.ucp.shopping.checkout' => array( array( 'version' => '2026-04-08' ) ),
			'dev.ucp.shopping.discount' => array( array( 'version' => '2026-04-08' ) ),
		);

		$result = $this->negotiator()->intersect( $business, $platform );
		$this->assertArrayHasKey( 'dev.ucp.shopping.discount', $result );
	}

	public function test_transitive_extension_chain_pruning(): void {
		$business = array(
			'com.example.a' => array(
				array(
					'version' => '2026-04-08',
					'extends' => 'com.example.missing',
				),
			),
			'com.example.b' => array(
				array(
					'version' => '2026-04-08',
					'extends' => 'com.example.a',
				),
			),
		);
		$platform = array(
			'com.example.a' => array( array( 'version' => '2026-04-08' ) ),
			'com.example.b' => array( array( 'version' => '2026-04-08' ) ),
		);

		$this->assertSame( array(), $this->negotiator()->intersect( $business, $platform ) );
	}
}
