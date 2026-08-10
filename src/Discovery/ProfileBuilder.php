<?php
/**
 * Business discovery profile builder.
 *
 * @package UCPWS
 */

namespace UCPWS\Discovery;

use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Payments\HandlerRegistry;
use UCPWS\Security\SigningKeys;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the business profile served at /.well-known/ucp and the per-response
 * `ucp` metadata blocks.
 */
class ProfileBuilder {

	public const CAP_CHECKOUT       = 'dev.ucp.shopping.checkout';
	public const CAP_FULFILLMENT    = 'dev.ucp.shopping.fulfillment';
	public const CAP_ORDER          = 'dev.ucp.shopping.order';
	public const CAP_CATALOG_SEARCH = 'dev.ucp.shopping.catalog.search';
	public const CAP_CATALOG_LOOKUP = 'dev.ucp.shopping.catalog.lookup';

	/**
	 * Payment handler registry.
	 *
	 * @var HandlerRegistry
	 */
	private $handlers;

	/**
	 * Signing keys.
	 *
	 * @var SigningKeys
	 */
	private $keys;

	/**
	 * Constructor.
	 *
	 * @param HandlerRegistry $handlers Payment handler registry.
	 * @param SigningKeys     $keys     Signing keys.
	 */
	public function __construct( HandlerRegistry $handlers, SigningKeys $keys ) {
		$this->handlers = $handlers;
		$this->keys     = $keys;
	}

	/**
	 * The REST base endpoint (no trailing slash).
	 *
	 * @return string
	 */
	public function rest_endpoint(): string {
		return untrailingslashit( rest_url( 'ucp/v1' ) );
	}

	/**
	 * The MCP endpoint.
	 *
	 * @return string
	 */
	public function mcp_endpoint(): string {
		return $this->rest_endpoint() . '/mcp';
	}

	/**
	 * The business profile URL (site root well-known).
	 *
	 * @return string
	 */
	public function profile_url(): string {
		return untrailingslashit( home_url() ) . '/.well-known/ucp';
	}

	/**
	 * The full business discovery profile document.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$spec_base  = 'https://ucp.dev/' . UCPWS_UCP_VERSION;
		$rest_first = array(
			array(
				'version'   => UCPWS_UCP_VERSION,
				'spec'      => $spec_base . '/specification/overview',
				'transport' => 'rest',
				'endpoint'  => $this->rest_endpoint(),
				'schema'    => $spec_base . '/services/shopping/rest.openapi.json',
			),
			array(
				'version'   => UCPWS_UCP_VERSION,
				'spec'      => $spec_base . '/specification/overview',
				'transport' => 'mcp',
				'endpoint'  => $this->mcp_endpoint(),
				'schema'    => $spec_base . '/services/shopping/mcp.openrpc.json',
			),
		);

		$profile = array(
			'ucp'          => array(
				'version'          => UCPWS_UCP_VERSION,
				'services'         => array(
					// The REST entry MUST come first: consumers commonly read index 0.
					'dev.ucp.shopping' => $rest_first,
				),
				'capabilities'     => $this->capability_declarations(),
				'payment_handlers' => (object) $this->handlers->profile_declarations(),
			),
			'signing_keys' => $this->keys->public_jwks(),
		);

		/**
		 * Filters the business discovery profile before it is served.
		 *
		 * @param array $profile Profile document.
		 */
		return apply_filters( 'ucpws_business_profile', $profile );
	}

	/**
	 * Business capability declarations (registry keyed by capability name).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function capability_declarations(): array {
		$spec_base = 'https://ucp.dev/' . UCPWS_UCP_VERSION;

		$capabilities = array(
			self::CAP_CHECKOUT       => array(
				array(
					'version' => UCPWS_UCP_VERSION,
					'spec'    => $spec_base . '/specification/checkout',
					'schema'  => $spec_base . '/schemas/shopping/checkout.json',
				),
			),
			self::CAP_FULFILLMENT    => array(
				array(
					'version' => UCPWS_UCP_VERSION,
					'spec'    => $spec_base . '/specification/fulfillment',
					'schema'  => $spec_base . '/schemas/shopping/fulfillment.json',
					'extends' => self::CAP_CHECKOUT,
				),
			),
			self::CAP_ORDER          => array(
				array(
					'version' => UCPWS_UCP_VERSION,
					'spec'    => $spec_base . '/specification/order',
					'schema'  => $spec_base . '/schemas/shopping/order.json',
				),
			),
			self::CAP_CATALOG_SEARCH => array(
				array(
					'version' => UCPWS_UCP_VERSION,
					'spec'    => $spec_base . '/specification/catalog/search',
					'schema'  => $spec_base . '/schemas/shopping/catalog_search.json',
				),
			),
			self::CAP_CATALOG_LOOKUP => array(
				array(
					'version' => UCPWS_UCP_VERSION,
					'spec'    => $spec_base . '/specification/catalog/lookup',
					'schema'  => $spec_base . '/schemas/shopping/catalog_lookup.json',
				),
			),
		);

		/**
		 * Filters the business capability declarations.
		 *
		 * Extensions (e.g. a discounts plugin) can append their capability entries
		 * here; entries with `extends` are pruned automatically during negotiation
		 * when their parent is not active.
		 *
		 * @param array $capabilities Capability registry.
		 */
		return apply_filters( 'ucpws_capabilities', $capabilities );
	}

	/**
	 * The `ucp` response block for a checkout response.
	 *
	 * @param NegotiationContext $context Negotiation context.
	 * @param \WC_Order|null     $order   Draft order for handler filtering.
	 * @return array<string, mixed>
	 */
	public function checkout_response_block( NegotiationContext $context, ?\WC_Order $order = null ): array {
		return array(
			'version'          => $context->version,
			'capabilities'     => $this->response_capabilities( $context, array( self::CAP_CHECKOUT ) ),
			'payment_handlers' => (object) $this->handlers->declarations( $order, $context ),
		);
	}

	/**
	 * The `ucp` response block for an order response/webhook.
	 *
	 * @param NegotiationContext $context Negotiation context.
	 * @return array<string, mixed>
	 */
	public function order_response_block( NegotiationContext $context ): array {
		return array(
			'version'      => $context->version,
			'capabilities' => $this->response_capabilities( $context, array( self::CAP_ORDER ) ),
		);
	}

	/**
	 * The `ucp` response block for a catalog response.
	 *
	 * @param NegotiationContext $context Negotiation context.
	 * @param string             $capability Relevant catalog capability.
	 * @return array<string, mixed>
	 */
	public function catalog_response_block( NegotiationContext $context, string $capability ): array {
		return array(
			'version'      => $context->version,
			'capabilities' => $this->response_capabilities( $context, array( $capability ) ),
		);
	}

	/**
	 * Response-relevant active capabilities (roots + their active extensions).
	 *
	 * @param NegotiationContext $context Negotiation context.
	 * @param string[]           $roots   Relevant root capability names.
	 * @return array<string, array<int, array<string, string>>>
	 */
	private function response_capabilities( NegotiationContext $context, array $roots ): array {
		$declared = $this->capability_declarations();
		$result   = array();

		foreach ( $context->capabilities as $name => $version ) {
			$relevant = in_array( $name, $roots, true );

			if ( ! $relevant && isset( $declared[ $name ][0]['extends'] ) ) {
				$extends = $declared[ $name ][0]['extends'];
				$extends = is_array( $extends ) ? $extends : array( $extends );
				foreach ( $extends as $parent ) {
					if ( in_array( $parent, $roots, true ) && $context->has_capability( $parent ) ) {
						$relevant = true;
						break;
					}
				}
			}

			if ( $relevant ) {
				$result[ $name ] = array( array( 'version' => $version ) );
			}
		}

		return $result;
	}
}
