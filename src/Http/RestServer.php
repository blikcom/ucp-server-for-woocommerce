<?php
/**
 * REST transport binding.
 *
 * @package UCPWS
 */

namespace UCPWS\Http;

use UCPWS\Catalog\CatalogService;
use UCPWS\Checkout\CheckoutService;
use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Discovery\WellKnown;
use UCPWS\Mcp\McpServer;
use UCPWS\Negotiation\AgentHeader;
use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Negotiation\Negotiator;
use UCPWS\Orders\OrderService;
use UCPWS\Protocol\UcpException;
use UCPWS\Storage\IdempotencyStore;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ucp/v1` REST namespace and runs the shared request pipeline:
 * rate limit -> UCP-Agent parsing/version validation -> authentication ->
 * idempotency replay/conflict -> capability negotiation -> operation.
 */
class RestServer {

	public const REST_NAMESPACE = 'ucp/v1';

	/** @var ProfileBuilder */
	private $profile_builder;

	/** @var Negotiator */
	private $negotiator;

	/** @var Auth */
	private $auth;

	/** @var RateLimiter */
	private $rate_limiter;

	/** @var IdempotencyStore */
	private $idempotency;

	/** @var Responder */
	private $responder;

	/** @var CheckoutService */
	private $checkout;

	/** @var CatalogService */
	private $catalog;

	/** @var OrderService */
	private $orders;

	/** @var McpServer */
	private $mcp;

	/**
	 * Constructor.
	 *
	 * @param ProfileBuilder   $profile_builder Profile builder.
	 * @param Negotiator       $negotiator      Negotiator.
	 * @param Auth             $auth            Authenticator.
	 * @param RateLimiter      $rate_limiter    Rate limiter.
	 * @param IdempotencyStore $idempotency     Idempotency store.
	 * @param Responder        $responder       Responder.
	 * @param CheckoutService  $checkout        Checkout service.
	 * @param CatalogService   $catalog         Catalog service.
	 * @param OrderService     $orders          Order service.
	 * @param McpServer        $mcp             MCP server.
	 */
	public function __construct(
		ProfileBuilder $profile_builder,
		Negotiator $negotiator,
		Auth $auth,
		RateLimiter $rate_limiter,
		IdempotencyStore $idempotency,
		Responder $responder,
		CheckoutService $checkout,
		CatalogService $catalog,
		OrderService $orders,
		McpServer $mcp
	) {
		$this->profile_builder = $profile_builder;
		$this->negotiator      = $negotiator;
		$this->auth            = $auth;
		$this->rate_limiter    = $rate_limiter;
		$this->idempotency     = $idempotency;
		$this->responder       = $responder;
		$this->checkout        = $checkout;
		$this->catalog         = $catalog;
		$this->orders          = $orders;
		$this->mcp             = $mcp;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Auth and error shaping are handled inside the pipeline: UCP requires
		// exact protocol error bodies which WP_Error-based permission callbacks
		// cannot produce.
		$open = array( 'permission_callback' => '__return_true' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/.well-known/ucp',
			array(
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'well_known' ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout-sessions',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( 'create', array( $this, 'create_checkout' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'  => 'GET',
					'callback' => $this->pipe( null, array( $this, 'get_checkout' ) ),
				) + $open,
				array(
					'methods'  => 'PUT',
					'callback' => $this->pipe( 'update', array( $this, 'update_checkout' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_\-]+)/complete',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( 'complete', array( $this, 'complete_checkout' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_\-]+)/cancel',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( 'cancel', array( $this, 'cancel_checkout' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/catalog/search',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( null, array( $this, 'search_catalog' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/catalog/lookup',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( null, array( $this, 'lookup_catalog' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/catalog/product',
			array(
				array(
					'methods'  => 'POST',
					'callback' => $this->pipe( null, array( $this, 'get_product' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'  => 'GET',
					'callback' => $this->pipe( null, array( $this, 'get_order' ) ),
				) + $open,
				array(
					'methods'  => 'PUT',
					'callback' => $this->pipe( 'order_update', array( $this, 'update_order' ) ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/testing/simulate-shipping/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'simulate_shipping' ),
				) + $open,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'  => 'POST',
					'callback' => array( $this->mcp, 'handle' ),
				) + $open,
			)
		);
	}

	/**
	 * GET /.well-known/ucp (REST alias of the site-root endpoint).
	 *
	 * @return \WP_REST_Response
	 */
	public function well_known(): \WP_REST_Response {
		$response = $this->responder->json( $this->profile_builder->build(), 200 );
		$response->header( 'Cache-Control', WellKnown::cache_control() );
		return $response;
	}

	/**
	 * Wrap an operation with the shared pipeline.
	 *
	 * @param string|null $idem_operation Idempotency operation name (null = not idempotent).
	 * @param callable    $handler        function( WP_REST_Request, NegotiationContext ): WP_REST_Response.
	 * @return callable
	 */
	private function pipe( ?string $idem_operation, callable $handler ): callable {
		return function ( \WP_REST_Request $request ) use ( $idem_operation, $handler ) {
			$continue_url = null;

			try {
				if ( ! Config::get_bool( 'enabled' ) ) {
					throw UcpException::transport( 'service_unavailable', 'UCP server is disabled.', 503 );
				}

				$this->rate_limiter->check( $this->rate_limiter->client_ip() );

				// 1. UCP-Agent (includes protocol version validation).
				$agent = AgentHeader::parse( $request->get_header( 'UCP-Agent' ) );

				// 2. Authentication + identity binding.
				$platform = $this->auth->authenticate( $request, $agent, (string) Config::get( 'auth_mode' ) );

				// 3. Idempotency replay / conflict.
				$idem_key = null;
				$scope    = null;
				$hash     = null;

				if ( null !== $idem_operation && 'GET' !== $request->get_method() ) {
					$header = $request->get_header( 'Idempotency-Key' );
					if ( is_string( $header ) && '' !== trim( $header ) ) {
						$idem_key = trim( $header );
						$scope    = $idem_operation . ':' . (string) ( $request['id'] ?? '' );
						$payload  = $request->get_json_params();
						$hash     = $this->idempotency->hash_payload( is_array( $payload ) ? $payload : array() );

						$stored = $this->idempotency->find( $idem_key, $scope );
						if ( null !== $stored ) {
							if ( $stored['request_hash'] !== $hash ) {
								throw UcpException::transport(
									'idempotency_conflict',
									'Idempotency key reused with different parameters',
									409
								);
							}
							return $this->responder->json(
								(array) json_decode( $stored['response_body'], false ),
								$stored['response_status']
							);
						}
					}//end if
				}//end if

				// 4. Capability negotiation.
				$context = $this->negotiator->negotiate( $agent, null !== $platform );
				if ( null !== $platform ) {
					$context->platform_id = (int) $platform->id;
				}

				/** @var \WP_REST_Response $response */
				$response = $handler( $request, $context );

				// 5. Persist the result for replays.
				if ( null !== $idem_key && null !== $scope && null !== $hash && $response->get_status() < 500 ) {
					$this->idempotency->store( $idem_key, $scope, $hash, $response->get_status(), $this->responder->serialize( $response ) );
				}

				return $response;
			} catch ( UcpException $exception ) {
				return $this->responder->error( $exception, $this->default_continue_url() );
			} catch ( \Throwable $throwable ) {
				$this->log_throwable( $throwable );
				return $this->responder->error(
					UcpException::transport( 'internal_error', 'An unexpected server error occurred.', 500 )
				);
			}//end try
		};
	}

	/**
	 * POST /checkout-sessions.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function create_checkout( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		$body   = $this->json_body( $request );
		$result = $this->checkout->create( $body, $context );
		return $this->responder->json( $result['payload'], $result['status'] );
	}

	/**
	 * GET /checkout-sessions/{id}.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function get_checkout( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->checkout->get( (string) $request['id'], $context ) );
	}

	/**
	 * PUT /checkout-sessions/{id}.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function update_checkout( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->checkout->update( (string) $request['id'], $this->json_body( $request ), $context ) );
	}

	/**
	 * POST /checkout-sessions/{id}/complete.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function complete_checkout( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->checkout->complete( (string) $request['id'], $this->json_body( $request ), $context ) );
	}

	/**
	 * POST /checkout-sessions/{id}/cancel.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function cancel_checkout( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->checkout->cancel( (string) $request['id'], $context ) );
	}

	/**
	 * POST /catalog/search.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function search_catalog( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->catalog->search( $this->json_body( $request ), $context ) );
	}

	/**
	 * POST /catalog/lookup.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function lookup_catalog( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->catalog->lookup( $this->json_body( $request ), $context ) );
	}

	/**
	 * POST /catalog/product.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function get_product( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->catalog->get_product( $this->json_body( $request ), $context ) );
	}

	/**
	 * GET /orders/{id}.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function get_order( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->orders->get( (string) $request['id'], $context ) );
	}

	/**
	 * PUT /orders/{id}.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param NegotiationContext $context Context.
	 * @return \WP_REST_Response
	 */
	public function update_order( \WP_REST_Request $request, NegotiationContext $context ): \WP_REST_Response {
		return $this->responder->json( $this->orders->update( (string) $request['id'], $this->json_body( $request ), $context ) );
	}

	/**
	 * POST /testing/simulate-shipping/{id} (test environments only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function simulate_shipping( \WP_REST_Request $request ): \WP_REST_Response {
		$secret     = (string) Config::get( 'simulation_secret' );
		$provided   = $request->get_header( 'Simulation-Secret' );
		$authorized = '' !== $secret && is_string( $provided ) && hash_equals( $secret, $provided );

		if ( ! $authorized ) {
			return $this->responder->json(
				array(
					'code'    => 'forbidden',
					'content' => 'Invalid Simulation Secret',
				),
				403
			);
		}

		if ( ! $this->orders->simulate_shipped( (string) $request['id'] ) ) {
			return $this->responder->json(
				array(
					'code'    => 'not_found',
					'content' => 'Order not found',
				),
				404
			);
		}

		return $this->responder->json( array( 'status' => 'ok' ) );
	}

	/**
	 * Decoded JSON body (tolerates empty bodies).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function json_body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Default continue URL for error responses (checkout page, else storefront).
	 *
	 * @return string
	 */
	private function default_continue_url(): string {
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$url = wc_get_checkout_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Log unexpected failures without leaking details to the client.
	 *
	 * @param \Throwable $throwable Throwable.
	 * @return void
	 */
	private function log_throwable( \Throwable $throwable ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'UCP request failed: %s in %s:%d', $throwable->getMessage(), $throwable->getFile(), $throwable->getLine() ),
				array( 'source' => 'ucp-server-for-woocommerce' )
			);
		}
	}
}
