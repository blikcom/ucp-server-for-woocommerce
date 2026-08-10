<?php
/**
 * Incoming request authentication.
 *
 * @package UCPWS
 */

namespace UCPWS\Http;

use UCPWS\Negotiation\AgentHeader;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Storage\Platforms;

defined( 'ABSPATH' ) || exit;

/**
 * API-key authentication with UCP-Agent identity binding.
 *
 * `registry` mode: every request must carry an API key of a registered
 * platform (X-API-Key header or `Authorization: Bearer`), and the platform's
 * registered profile URL must match the UCP-Agent profile — a key is bound to
 * exactly one platform identity.
 *
 * `open` mode: requests are accepted without credentials (spec-permitted
 * "open API" posture; required by the UCP conformance suite). If a key is
 * provided anyway, it is validated and bound.
 */
class Auth {

	/**
	 * Platform registry.
	 *
	 * @var Platforms
	 */
	private $platforms;

	/**
	 * Constructor.
	 *
	 * @param Platforms $platforms Platform registry.
	 */
	public function __construct( Platforms $platforms ) {
		$this->platforms = $platforms;
	}

	/**
	 * Authenticate a request.
	 *
	 * @param \WP_REST_Request $request WP REST request.
	 * @param AgentHeader      $agent   Parsed UCP-Agent header.
	 * @param string           $mode    `registry` or `open`.
	 * @return object|null Authenticated platform row, or null (open mode, anonymous).
	 * @throws UcpException 401 for missing/invalid credentials, 403 for identity conflicts.
	 */
	public function authenticate( \WP_REST_Request $request, AgentHeader $agent, string $mode ) {
		$api_key = $this->extract_key( $request );

		if ( null === $api_key ) {
			if ( 'registry' === $mode ) {
				throw UcpException::transport(
					'unauthorized',
					'Authentication required: provide a platform API key via the X-API-Key header or Authorization: Bearer.',
					401
				)->with_headers( array( 'WWW-Authenticate' => 'Bearer realm="ucp"' ) );
			}
			return null;
		}

		$platform = $this->platforms->find_by_key( $api_key );

		if ( null === $platform ) {
			throw UcpException::transport( 'unauthorized', 'Unknown or disabled API key.', 401 )
				->with_headers( array( 'WWW-Authenticate' => 'Bearer realm="ucp"' ) );
		}

		// Identity binding: the authenticated key must belong to the platform
		// identified by the UCP-Agent profile URL.
		if ( untrailingslashit( (string) $platform->profile_url ) !== untrailingslashit( $agent->profile_url ) ) {
			throw UcpException::transport(
				ErrorCodes::PROFILE_NOT_TRUSTED,
				'The authenticated API key is not authorized to act for the profile in UCP-Agent.',
				403
			);
		}

		$this->platforms->touch( (int) $platform->id );

		return $platform;
	}

	/**
	 * Extract the API key from the request.
	 *
	 * @param \WP_REST_Request $request WP REST request.
	 * @return string|null
	 */
	private function extract_key( \WP_REST_Request $request ): ?string {
		$header = $request->get_header( 'X-API-Key' );
		if ( is_string( $header ) && '' !== trim( $header ) ) {
			return trim( $header );
		}

		$authorization = $request->get_header( 'Authorization' );
		if ( is_string( $authorization ) && 0 === stripos( $authorization, 'Bearer ' ) ) {
			$token = trim( substr( $authorization, 7 ) );
			return '' === $token ? null : $token;
		}

		return null;
	}
}
