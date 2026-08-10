<?php
/**
 * UCP response construction.
 *
 * @package UCPWS
 */

namespace UCPWS\Http;

use UCPWS\Protocol\UcpException;

defined( 'ABSPATH' ) || exit;

/**
 * Builds WP_REST_Response objects with UCP semantics.
 *
 * Two error shapes exist per the spec:
 *  - Transport errors (4xx/5xx): bare `{code, content, continue_url?}` body.
 *  - Business outcomes (HTTP 200): full envelope with `ucp.status = "error"`
 *    and a `messages[]` array.
 */
class Responder {

	/**
	 * Success (or business outcome) JSON response.
	 *
	 * @param array<string, mixed>  $data    Body.
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Extra headers.
	 * @return \WP_REST_Response
	 */
	public function json( array $data, int $status = 200, array $headers = array() ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * Render a UcpException.
	 *
	 * @param UcpException $exception    Exception.
	 * @param string|null  $continue_url Fallback continue URL when none set on the exception.
	 * @return \WP_REST_Response
	 */
	public function error( UcpException $exception, ?string $continue_url = null ): \WP_REST_Response {
		$url = $exception->get_continue_url() ?? $continue_url;

		if ( $exception->is_business_outcome() ) {
			$body = array(
				'ucp'      => array(
					'version'      => UCPWS_UCP_VERSION,
					'status'       => 'error',
					'capabilities' => (object) array(),
				),
				'messages' => array( $exception->to_message() ),
			);
			if ( null !== $url ) {
				$body['continue_url'] = $url;
			}
			return $this->json( $body, 200, $exception->get_headers() );
		}

		$body = array(
			'code'    => $exception->get_error_code(),
			'content' => $exception->getMessage(),
		);
		if ( null !== $url ) {
			$body['continue_url'] = $url;
		}

		return $this->json( $body, $exception->get_http_status(), $exception->get_headers() );
	}

	/**
	 * Serialize a response body exactly as it will be sent (for idempotency storage).
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return string JSON.
	 */
	public function serialize( \WP_REST_Response $response ): string {
		return (string) wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES );
	}
}
