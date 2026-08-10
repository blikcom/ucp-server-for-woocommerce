<?php
/**
 * MCP transport binding (JSON-RPC 2.0 over streamable HTTP).
 *
 * @package UCPWS
 */

namespace UCPWS\Mcp;

use UCPWS\Catalog\CatalogService;
use UCPWS\Checkout\CheckoutService;
use UCPWS\Http\Auth;
use UCPWS\Http\RateLimiter;
use UCPWS\Negotiation\AgentHeader;
use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Negotiation\Negotiator;
use UCPWS\Orders\OrderService;
use UCPWS\Protocol\UcpException;
use UCPWS\Storage\IdempotencyStore;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Single-endpoint MCP server exposing the UCP shopping tools.
 *
 * Notes:
 *  - The platform profile travels in `params.arguments.meta["ucp-agent"].profile`
 *    (an ordinary tool argument per the UCP binding, NOT the base-MCP `_meta`).
 *  - Business outcomes are JSON-RPC results with the UCP envelope; protocol
 *    errors are JSON-RPC errors AND the HTTP status mirrors the error class
 *    (UCP requirement for streamable HTTP).
 *  - Responses carry `structuredContent` (payload at root) plus a serialized
 *    `content[]` fallback.
 */
class McpServer {

	private const PROTOCOL_VERSION = '2025-06-18';

	/** @var Negotiator */
	private $negotiator;

	/** @var Auth */
	private $auth;

	/** @var RateLimiter */
	private $rate_limiter;

	/** @var IdempotencyStore */
	private $idempotency;

	/** @var CheckoutService */
	private $checkout;

	/** @var CatalogService */
	private $catalog;

	/** @var OrderService */
	private $orders;

	/**
	 * Constructor.
	 *
	 * @param Negotiator       $negotiator   Negotiator.
	 * @param Auth             $auth         Authenticator.
	 * @param RateLimiter      $rate_limiter Rate limiter.
	 * @param IdempotencyStore $idempotency  Idempotency store.
	 * @param CheckoutService  $checkout     Checkout service.
	 * @param CatalogService   $catalog      Catalog service.
	 * @param OrderService     $orders       Order service.
	 */
	public function __construct( Negotiator $negotiator, Auth $auth, RateLimiter $rate_limiter, IdempotencyStore $idempotency, CheckoutService $checkout, CatalogService $catalog, OrderService $orders ) {
		$this->negotiator   = $negotiator;
		$this->auth         = $auth;
		$this->rate_limiter = $rate_limiter;
		$this->idempotency  = $idempotency;
		$this->checkout     = $checkout;
		$this->catalog      = $catalog;
		$this->orders       = $orders;
	}

	/**
	 * POST /mcp entry point.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$message = json_decode( $request->get_body(), true );

		if ( null === $message && 'null' !== trim( $request->get_body() ) ) {
			return $this->rpc_error( null, -32700, 'Parse error', null, 400 );
		}

		if ( ! is_array( $message ) || array_keys( $message ) === range( 0, count( $message ) - 1 ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request: batch requests are not supported.', null, 400 );
		}

		$id     = $message['id'] ?? null;
		$method = isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';

		// Notifications: no response body.
		if ( ! array_key_exists( 'id', $message ) && 0 === strpos( $method, 'notifications/' ) ) {
			return new \WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				$params    = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
				$requested = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : self::PROTOCOL_VERSION;
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => $requested,
						'capabilities'    => array( 'tools' => (object) array() ),
						'serverInfo'      => array(
							'name'    => 'ucp-server-for-woocommerce',
							'version' => UCPWS_VERSION,
						),
					)
				);

			case 'ping':
				return $this->rpc_result( $id, (object) array() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => ToolCatalog::tools() ) );

			case 'tools/call':
				return $this->tools_call( $request, $message, $id );

			default:
				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method, null, 404 );
		}//end switch
	}

	/**
	 * Handle tools/call.
	 *
	 * @param \WP_REST_Request     $request Request.
	 * @param array<string, mixed> $message JSON-RPC message.
	 * @param mixed                $id      JSON-RPC id.
	 * @return \WP_REST_Response
	 * @throws UcpException Converted to JSON-RPC errors below.
	 */
	private function tools_call( \WP_REST_Request $request, array $message, $id ): \WP_REST_Response {
		$params    = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$tool      = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( ! ToolCatalog::exists( $tool ) ) {
			return $this->rpc_error( $id, -32601, 'Unknown tool: ' . $tool, null, 404 );
		}

		try {
			$this->rate_limiter->check( $this->rate_limiter->client_ip() );

			$agent    = $this->agent_from( $request, $arguments );
			$platform = $this->auth->authenticate( $request, $agent, (string) Config::get( 'auth_mode' ) );

			$meta     = isset( $arguments['meta'] ) && is_array( $arguments['meta'] ) ? $arguments['meta'] : array();
			$idem_key = isset( $meta['idempotency-key'] ) && is_string( $meta['idempotency-key'] ) && '' !== trim( $meta['idempotency-key'] )
				? trim( $meta['idempotency-key'] )
				: $this->header_idempotency_key( $request );

			if ( in_array( $tool, array( 'complete_checkout', 'cancel_checkout' ), true ) && null === $idem_key ) {
				return $this->rpc_error( $id, -32602, 'Invalid params: meta["idempotency-key"] is required for ' . $tool . '.', null, 400 );
			}

			$resource_id = isset( $arguments['id'] ) && is_string( $arguments['id'] ) ? $arguments['id'] : '';
			$scope       = null;
			$hash        = null;

			if ( null !== $idem_key && ToolCatalog::is_mutating( $tool ) ) {
				$scope = 'mcp:' . $tool . ':' . $resource_id;
				$hash  = $this->idempotency->hash_payload( $this->payload_of( $arguments ) );

				$stored = $this->idempotency->find( $idem_key, $scope );
				if ( null !== $stored ) {
					if ( $stored['request_hash'] !== $hash ) {
						throw UcpException::transport( 'idempotency_conflict', 'Idempotency key reused with different payload', 409 );
					}
					$payload = json_decode( $stored['response_body'], false );
					return $this->rpc_result( $id, $this->wrap( $payload ) );
				}
			}

			$context = $this->negotiator->negotiate( $agent, null !== $platform );
			if ( null !== $platform ) {
				$context->platform_id = (int) $platform->id;
			}

			$payload = $this->dispatch( $tool, $arguments, $context );

			if ( null !== $idem_key && null !== $scope && null !== $hash ) {
				$this->idempotency->store( $idem_key, $scope, $hash, 200, (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) );
			}

			return $this->rpc_result( $id, $this->wrap( $payload ) );
		} catch ( UcpException $exception ) {
			if ( $exception->is_business_outcome() ) {
				// Business outcomes stay JSON-RPC results with the UCP envelope.
				$envelope = array(
					'ucp'      => array(
						'version' => UCPWS_UCP_VERSION,
						'status'  => 'error',
					),
					'messages' => array( $exception->to_message() ),
				);
				$continue = $exception->get_continue_url();
				if ( null !== $continue ) {
					$envelope['continue_url'] = $continue;
				}
				return $this->rpc_result( $id, $this->wrap( $envelope ) );
			}

			$data = array(
				'code'    => $exception->get_error_code(),
				'content' => $exception->getMessage(),
			);
			if ( null !== $exception->get_continue_url() ) {
				$data['continue_url'] = $exception->get_continue_url();
			}
			if ( 429 === $exception->get_http_status() ) {
				$headers = $exception->get_headers();
				if ( isset( $headers['Retry-After'] ) ) {
					$data['retry_after'] = (int) $headers['Retry-After'];
				}
			}

			return $this->rpc_error( $id, $exception->get_jsonrpc_code(), $exception->getMessage(), $data, $exception->get_http_status(), $exception->get_headers() );
		} catch ( \Throwable $throwable ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'UCP MCP failure: ' . $throwable->getMessage(), array( 'source' => 'ucp-server-for-woocommerce' ) );
			}
			return $this->rpc_error( $id, -32603, 'Internal error', null, 500 );
		}//end try
	}

	/**
	 * Dispatch a tool call to the capability services.
	 *
	 * @param string               $tool      Tool name.
	 * @param array<string, mixed> $arguments Arguments.
	 * @param NegotiationContext   $context   Context.
	 * @return array<string, mixed>
	 * @throws UcpException On errors.
	 */
	private function dispatch( string $tool, array $arguments, NegotiationContext $context ): array {
		$resource_id = isset( $arguments['id'] ) && is_string( $arguments['id'] ) ? $arguments['id'] : '';
		$checkout    = isset( $arguments['checkout'] ) && is_array( $arguments['checkout'] ) ? $arguments['checkout'] : array();
		$catalog     = isset( $arguments['catalog'] ) && is_array( $arguments['catalog'] ) ? $arguments['catalog'] : array();

		switch ( $tool ) {
			case 'create_checkout':
				$result = $this->checkout->create( $checkout, $context );
				return $result['payload'];
			case 'get_checkout':
				return $this->checkout->get( $resource_id, $context );
			case 'update_checkout':
				return $this->checkout->update( $resource_id, $checkout, $context );
			case 'complete_checkout':
				return $this->checkout->complete( $resource_id, $checkout, $context );
			case 'cancel_checkout':
				return $this->checkout->cancel( $resource_id, $context );
			case 'search_catalog':
				return $this->catalog->search( $catalog, $context );
			case 'lookup_catalog':
				return $this->catalog->lookup( $catalog, $context );
			case 'get_product':
				return $this->catalog->get_product( $catalog, $context );
			case 'get_order':
				return $this->orders->get( $resource_id, $context );
			default:
				throw UcpException::transport( 'invalid_request', 'Unknown tool.', 400 );
		}//end switch
	}

	/**
	 * Resolve the agent identity: `meta["ucp-agent"].profile` is the transport
	 * of record; the HTTP header contributes the optional version parameter.
	 *
	 * @param \WP_REST_Request     $request   Request.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return AgentHeader
	 * @throws UcpException On invalid_profile_url / version_unsupported.
	 */
	private function agent_from( \WP_REST_Request $request, array $arguments ): AgentHeader {
		$meta    = isset( $arguments['meta'] ) && is_array( $arguments['meta'] ) ? $arguments['meta'] : array();
		$profile = $meta['ucp-agent']['profile'] ?? null;

		$header = $request->get_header( 'UCP-Agent' );

		if ( ! is_string( $profile ) || '' === $profile ) {
			// Fall back to the HTTP header form.
			return AgentHeader::parse( is_string( $header ) ? $header : null );
		}

		$version = UCPWS_UCP_VERSION;
		if ( is_string( $header ) && preg_match( '/(?:^|;)\s*version=(?:"([^"]*)"|([^;\s]+))/i', $header, $matches ) ) {
			$raw     = trim( '' !== $matches[1] ? $matches[1] : ( $matches[2] ?? '' ) );
			$version = AgentHeader::validate_version( $raw );
		}

		$agent = new AgentHeader( $profile, $version );
		$agent->assert_url_allowed();

		return $agent;
	}

	/**
	 * The idempotency key from HTTP headers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|null
	 */
	private function header_idempotency_key( \WP_REST_Request $request ): ?string {
		$header = $request->get_header( 'Idempotency-Key' );
		return is_string( $header ) && '' !== trim( $header ) ? trim( $header ) : null;
	}

	/**
	 * Arguments minus meta (idempotency fingerprint input).
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 */
	private function payload_of( array $arguments ): array {
		unset( $arguments['meta'] );
		return $arguments;
	}

	/**
	 * Wrap a payload into the MCP dual-output result.
	 *
	 * @param mixed $payload UCP payload (array or object graph).
	 * @return array<string, mixed>
	 */
	private function wrap( $payload ): array {
		return array(
			'structuredContent' => $payload,
			'content'           => array(
				array(
					'type' => 'text',
					'text' => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
				),
			),
		);
	}

	/**
	 * JSON-RPC result response.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result value.
	 * @return \WP_REST_Response
	 */
	private function rpc_result( $id, $result ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * JSON-RPC error response with a mirrored HTTP status.
	 *
	 * @param mixed                     $id      JSON-RPC id.
	 * @param int                       $code    JSON-RPC error code.
	 * @param string                    $message Error message.
	 * @param array<string, mixed>|null $data    Optional error data.
	 * @param int                       $status  HTTP status.
	 * @param array<string, string>     $headers Extra headers.
	 * @return \WP_REST_Response
	 */
	private function rpc_error( $id, int $code, string $message, ?array $data, int $status, array $headers = array() ): \WP_REST_Response {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}

		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			$status
		);

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
