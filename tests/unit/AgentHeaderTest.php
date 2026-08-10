<?php
/**
 * UCP-Agent header parsing tests.
 *
 * @package UCPWS
 */

namespace UCPWS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UCPWS\Negotiation\AgentHeader;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;

class AgentHeaderTest extends TestCase {

	public function test_parses_profile(): void {
		$agent = AgentHeader::parse( 'profile="https://agent.example/profiles/shopping-agent.json"' );
		$this->assertSame( 'https://agent.example/profiles/shopping-agent.json', $agent->profile_url );
		$this->assertSame( UCPWS_UCP_VERSION, $agent->version );
	}

	public function test_parses_profile_with_version(): void {
		$agent = AgentHeader::parse( 'profile="https://agent.example/p"; version="2026-01-23"' );
		$this->assertSame( '2026-01-23', $agent->version );
	}

	public function test_parses_unquoted_version(): void {
		$agent = AgentHeader::parse( 'profile="https://agent.example/p"; version=2026-01-23' );
		$this->assertSame( '2026-01-23', $agent->version );
	}

	public function test_missing_header_is_invalid_profile_url_400(): void {
		try {
			AgentHeader::parse( null );
			$this->fail( 'Expected UcpException' );
		} catch ( UcpException $e ) {
			$this->assertSame( ErrorCodes::INVALID_PROFILE_URL, $e->get_error_code() );
			$this->assertSame( 400, $e->get_http_status() );
		}
	}

	public function test_missing_profile_member_is_400(): void {
		$this->expectException( UcpException::class );
		AgentHeader::parse( 'version="2026-04-08"' );
	}

	public function test_newer_version_is_422(): void {
		try {
			AgentHeader::parse( 'profile="https://a.example/p"; version="2099-01-01"' );
			$this->fail( 'Expected UcpException' );
		} catch ( UcpException $e ) {
			$this->assertSame( ErrorCodes::VERSION_UNSUPPORTED, $e->get_error_code() );
			$this->assertSame( 422, $e->get_http_status() );
		}
	}

	public function test_malformed_version_is_422(): void {
		try {
			AgentHeader::parse( 'profile="https://a.example/p"; version="not-a-date"' );
			$this->fail( 'Expected UcpException' );
		} catch ( UcpException $e ) {
			$this->assertSame( ErrorCodes::VERSION_UNSUPPORTED, $e->get_error_code() );
			$this->assertSame( 422, $e->get_http_status() );
		}
	}

	public function test_older_version_accepted(): void {
		$agent = AgentHeader::parse( 'profile="https://a.example/p"; version="2026-01-11"' );
		$this->assertSame( '2026-01-11', $agent->version );
	}

	public function test_placeholder_profile_url_is_tolerated_at_parse_time(): void {
		// The conformance suite sends profile="..." (unfetchable). Parsing must
		// succeed; fetch-time handling decides what happens next.
		$agent = AgentHeader::parse( 'profile="..."; version="2026-04-08"' );
		$this->assertSame( '...', $agent->profile_url );
		$agent->assert_url_allowed();
		$this->assertTrue( true );
	}
}
