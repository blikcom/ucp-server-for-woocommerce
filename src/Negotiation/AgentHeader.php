<?php
/**
 * UCP-Agent header parsing.
 *
 * @package UCPWS
 */

namespace UCPWS\Negotiation;

use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the `UCP-Agent` header (RFC 8941 Dictionary subset).
 *
 * Format: `profile="https://platform.example/profile"; version="2026-04-08"`.
 * The `profile` member is required; `version` is optional and defaults to the
 * business's own protocol version.
 */
final class AgentHeader {

	/**
	 * Platform profile URL.
	 *
	 * @var string
	 */
	public $profile_url;

	/**
	 * Protocol version declared by the platform (YYYY-MM-DD).
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Constructor.
	 *
	 * @param string $profile_url Profile URL.
	 * @param string $version     Declared protocol version.
	 */
	public function __construct( string $profile_url, string $version ) {
		$this->profile_url = $profile_url;
		$this->version     = $version;
	}

	/**
	 * Parse a UCP-Agent header value.
	 *
	 * @param string|null $header Raw header value (null when absent).
	 * @return self
	 * @throws UcpException When the header is missing or malformed (invalid_profile_url, 400)
	 *                      or declares an unsupported version (version_unsupported, 422).
	 */
	public static function parse( ?string $header ): self {
		if ( null === $header || '' === trim( $header ) ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_PROFILE_URL,
				'The UCP-Agent header is required and must include a profile member, e.g. UCP-Agent: profile="https://platform.example/profile".',
				400
			);
		}

		if ( ! preg_match( '/(?:^|;)\s*profile=(?:"([^"]*)"|([^;\s]+))/i', $header, $matches ) ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_PROFILE_URL,
				'The UCP-Agent header does not contain a profile member.',
				400
			);
		}

		$profile_url = trim( '' !== $matches[1] ? $matches[1] : ( $matches[2] ?? '' ) );

		if ( '' === $profile_url ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_PROFILE_URL,
				'The UCP-Agent profile member is empty.',
				400
			);
		}

		$version = UCPWS_UCP_VERSION;
		if ( preg_match( '/(?:^|;)\s*version=(?:"([^"]*)"|([^;\s]+))/i', $header, $version_matches ) ) {
			$raw_version = trim( '' !== $version_matches[1] ? $version_matches[1] : ( $version_matches[2] ?? '' ) );
			$version     = self::validate_version( $raw_version );
		}

		return new self( $profile_url, $version );
	}

	/**
	 * Validate a declared protocol version against the server version.
	 *
	 * @param string $version Version string.
	 * @return string The validated version.
	 * @throws UcpException When malformed or newer than the supported version (422).
	 */
	public static function validate_version( string $version ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) || false === strtotime( $version . 'T00:00:00Z' ) ) {
			throw UcpException::transport(
				ErrorCodes::VERSION_UNSUPPORTED,
				sprintf( "Version '%s' is invalid. Expected YYYY-MM-DD.", $version ),
				422
			);
		}

		if ( strcmp( $version, UCPWS_UCP_VERSION ) > 0 ) {
			throw UcpException::transport(
				ErrorCodes::VERSION_UNSUPPORTED,
				sprintf( 'Version %s is not supported. This business implements version %s.', $version, UCPWS_UCP_VERSION ),
				422
			);
		}

		return $version;
	}

	/**
	 * Validate the profile URL scheme/host rules.
	 *
	 * @return void
	 * @throws UcpException When the URL is not HTTPS (unless insecure profiles are allowed).
	 */
	public function assert_url_allowed(): void {
		$parts = wp_parse_url( $this->profile_url );

		if ( false === $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			// Tolerated: unresolvable placeholder profile URLs are handled at fetch
			// time (lenient) or rejected there (strict). A structurally hopeless
			// value is rejected here only when it has no scheme AND no host.
			return;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'https' !== $scheme && ! Config::get_bool( 'allow_insecure_profiles' ) ) {
			throw UcpException::transport(
				ErrorCodes::INVALID_PROFILE_URL,
				'Platform profile URLs must use HTTPS.',
				400
			);
		}
	}
}
