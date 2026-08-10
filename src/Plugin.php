<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package UCPWS
 */

namespace UCPWS;

use UCPWS\Admin\SettingsPage;
use UCPWS\Bootstrap\Installer;
use UCPWS\Catalog\CatalogService;
use UCPWS\Checkout\AddressBook;
use UCPWS\Checkout\CheckoutPresenter;
use UCPWS\Checkout\CheckoutService;
use UCPWS\Checkout\DraftOrders;
use UCPWS\Checkout\FulfillmentService;
use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Discovery\WellKnown;
use UCPWS\Http\Auth;
use UCPWS\Http\RateLimiter;
use UCPWS\Http\Responder;
use UCPWS\Http\RestServer;
use UCPWS\Mcp\McpServer;
use UCPWS\Negotiation\Negotiator;
use UCPWS\Negotiation\ProfileFetcher;
use UCPWS\Orders\OrderService;
use UCPWS\Orders\WebhookDispatcher;
use UCPWS\Payments\HandlerRegistry;
use UCPWS\Schema\Validator;
use UCPWS\Security\SigningKeys;
use UCPWS\Storage\IdempotencyStore;
use UCPWS\Storage\Platforms;
use UCPWS\Storage\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Service container and hook registration.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Built services.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire services and register hooks. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public function init(): void {
		Installer::maybe_upgrade();

		$this->well_known()->register();
		$this->rest_server()->register();
		$this->webhooks()->register();

		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 2 );
		add_action( 'ucpws_cleanup', array( $this, 'cleanup' ) );

		if ( is_admin() ) {
			( new SettingsPage( $this->platforms(), $this->signing_keys(), $this->profile_builder() ) )->register();
		}
	}

	/**
	 * Order status transitions -> order_updated webhooks.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @return void
	 */
	public function on_order_status_changed( $order_id, $from ): void {
		// The draft -> paid transition is covered by the synchronous
		// order_placed webhook sent from complete_checkout.
		if ( DraftOrders::DRAFT_STATUS === $from ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || 'ucp' !== $order->get_created_via() ) {
			return;
		}

		if ( '' === (string) $order->get_meta( '_ucpws_webhook_url' ) ) {
			return;
		}

		$this->webhooks()->enqueue( (int) $order_id, 'order_updated' );
	}

	/**
	 * Hourly cleanup of expired sessions and idempotency records.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$this->sessions()->cleanup();
		$this->idempotency()->cleanup();
	}

	/** @return SigningKeys */
	public function signing_keys(): SigningKeys {
		return $this->service(
			'signing_keys',
			static function () {
				return new SigningKeys();
			}
		);
	}

	/** @return Platforms */
	public function platforms(): Platforms {
		return $this->service(
			'platforms',
			static function () {
				return new Platforms();
			}
		);
	}

	/** @return Sessions */
	public function sessions(): Sessions {
		return $this->service(
			'sessions',
			static function () {
				return new Sessions();
			}
		);
	}

	/** @return IdempotencyStore */
	public function idempotency(): IdempotencyStore {
		return $this->service(
			'idempotency',
			static function () {
				return new IdempotencyStore();
			}
		);
	}

	/** @return HandlerRegistry */
	public function payment_handlers(): HandlerRegistry {
		return $this->service(
			'payment_handlers',
			static function () {
				return new HandlerRegistry();
			}
		);
	}

	/** @return ProfileBuilder */
	public function profile_builder(): ProfileBuilder {
		$plugin = $this;
		return $this->service(
			'profile_builder',
			static function () use ( $plugin ) {
				return new ProfileBuilder( $plugin->payment_handlers(), $plugin->signing_keys() );
			}
		);
	}

	/** @return WellKnown */
	public function well_known(): WellKnown {
		$plugin = $this;
		return $this->service(
			'well_known',
			static function () use ( $plugin ) {
				return new WellKnown( $plugin->profile_builder() );
			}
		);
	}

	/** @return ProfileFetcher */
	public function profile_fetcher(): ProfileFetcher {
		return $this->service(
			'profile_fetcher',
			static function () {
				return new ProfileFetcher();
			}
		);
	}

	/** @return Negotiator */
	public function negotiator(): Negotiator {
		$plugin = $this;
		return $this->service(
			'negotiator',
			static function () use ( $plugin ) {
				return new Negotiator( $plugin->profile_fetcher(), $plugin->profile_builder() );
			}
		);
	}

	/** @return WebhookDispatcher */
	public function webhooks(): WebhookDispatcher {
		$plugin = $this;
		return $this->service(
			'webhooks',
			static function () use ( $plugin ) {
				return new WebhookDispatcher(
					$plugin->signing_keys(),
					$plugin->profile_builder(),
					$plugin->profile_fetcher(),
					static function () use ( $plugin ) {
						return $plugin->orders();
					}
				);
			}
		);
	}

	/** @return OrderService */
	public function orders(): OrderService {
		$plugin = $this;
		return $this->service(
			'orders',
			static function () use ( $plugin ) {
				return new OrderService( $plugin->profile_builder(), $plugin->webhooks() );
			}
		);
	}

	/** @return CheckoutService */
	public function checkout(): CheckoutService {
		$plugin = $this;
		return $this->service(
			'checkout',
			static function () use ( $plugin ) {
				return new CheckoutService(
					$plugin->sessions(),
					new DraftOrders(),
					new FulfillmentService( new AddressBook() ),
					$plugin->payment_handlers(),
					new CheckoutPresenter( $plugin->profile_builder() ),
					$plugin->orders()
				);
			}
		);
	}

	/** @return CatalogService */
	public function catalog(): CatalogService {
		$plugin = $this;
		return $this->service(
			'catalog',
			static function () use ( $plugin ) {
				return new CatalogService( $plugin->profile_builder() );
			}
		);
	}

	/** @return Validator */
	public function schema_validator(): Validator {
		return $this->service(
			'schema_validator',
			static function () {
				return new Validator();
			}
		);
	}

	/** @return McpServer */
	public function mcp(): McpServer {
		$plugin = $this;
		return $this->service(
			'mcp',
			static function () use ( $plugin ) {
				return new McpServer(
					$plugin->negotiator(),
					new Auth( $plugin->platforms() ),
					new RateLimiter(),
					$plugin->idempotency(),
					$plugin->checkout(),
					$plugin->catalog(),
					$plugin->orders()
				);
			}
		);
	}

	/** @return RestServer */
	public function rest_server(): RestServer {
		$plugin = $this;
		return $this->service(
			'rest_server',
			static function () use ( $plugin ) {
				return new RestServer(
					$plugin->profile_builder(),
					$plugin->negotiator(),
					new Auth( $plugin->platforms() ),
					new RateLimiter(),
					$plugin->idempotency(),
					new Responder(),
					$plugin->checkout(),
					$plugin->catalog(),
					$plugin->orders(),
					$plugin->mcp()
				);
			}
		);
	}

	/**
	 * Memoized service builder.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( string $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}
		return $this->services[ $key ];
	}
}
