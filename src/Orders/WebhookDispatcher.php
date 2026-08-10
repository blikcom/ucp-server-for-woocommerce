<?php
/**
 * Signed order webhook delivery.
 *
 * @package UCPWS
 */

namespace UCPWS\Orders;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Negotiation\ProfileFetcher;
use UCPWS\Security\HttpSignature;
use UCPWS\Security\SigningKeys;
use UCPWS\Support\Config;
use UCPWS\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers order lifecycle webhooks to the platform.
 *
 * - Payload: the full UCP order entity (current-state snapshot).
 * - Destination: the webhook_url from the platform profile's order capability
 *   config, captured on the order at completion time.
 * - Signing: RFC 9421 (ES256) with Content-Digest; the business profile URL
 *   travels in UCP-Agent so platforms can resolve our signing keys.
 * - Reliability: the triggering flow attempts synchronous delivery; failures
 *   are retried via Action Scheduler with exponential backoff.
 */
class WebhookDispatcher {

	public const HOOK = 'ucpws_webhook_deliver';

	/**
	 * Backoff schedule in seconds (attempt N uses index N-1).
	 *
	 * @var int[]
	 */
	private const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Signing keys.
	 *
	 * @var SigningKeys
	 */
	private $keys;

	/**
	 * Profile builder (business profile URL).
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Profile fetcher (dev URL rewrites).
	 *
	 * @var ProfileFetcher
	 */
	private $fetcher;

	/**
	 * Order presenter, injected lazily to avoid a construction cycle.
	 *
	 * @var callable():OrderService
	 */
	private $order_service_factory;

	/**
	 * Constructor.
	 *
	 * @param SigningKeys    $keys                  Signing keys.
	 * @param ProfileBuilder $profile_builder       Profile builder.
	 * @param ProfileFetcher $fetcher               Profile fetcher.
	 * @param callable       $order_service_factory Factory returning the OrderService.
	 */
	public function __construct( SigningKeys $keys, ProfileBuilder $profile_builder, ProfileFetcher $fetcher, callable $order_service_factory ) {
		$this->keys                  = $keys;
		$this->profile_builder       = $profile_builder;
		$this->fetcher               = $fetcher;
		$this->order_service_factory = $order_service_factory;
	}

	/**
	 * Register the Action Scheduler retry hook.
	 *
	 * @return void
	 */
	public function register(): void {
		$dispatcher = $this;
		add_action(
			self::HOOK,
			static function ( $order_id, $event_type = 'order_updated', $attempt = 1 ) use ( $dispatcher ): void {
				$dispatcher->deliver( $order_id, $event_type, $attempt );
			},
			10,
			3
		);
	}

	/**
	 * Queue an async delivery (used for wp-admin status transitions).
	 *
	 * @param int    $order_id   Order id.
	 * @param string $event_type Event type.
	 * @return void
	 */
	public function enqueue( int $order_id, string $event_type ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $order_id, $event_type, 1 ), 'ucpws' );
		} else {
			$this->deliver( $order_id, $event_type, 1 );
		}
	}

	/**
	 * Deliver a webhook (attempt N). Schedules the next attempt on failure.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $event_type Event type (order_placed, order_shipped, order_updated, ...).
	 * @param int    $attempt    Attempt number (1-based).
	 * @return bool Whether delivery succeeded.
	 */
	public function deliver( $order_id, $event_type, $attempt = 1 ): bool {
		$order_id   = (int) $order_id;
		$event_type = (string) $event_type;
		$attempt    = max( 1, (int) $attempt );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$url = (string) $order->get_meta( '_ucpws_webhook_url' );
		if ( '' === $url ) {
			return false;
		}

		$service = ( $this->order_service_factory )();
		$payload = $service->present( $order );
		$body    = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );

		$target = $this->fetcher->apply_dev_rewrites( $url );

		$headers = array(
			'Content-Type'      => 'application/json',
			'UCP-Agent'         => 'profile="' . $this->profile_builder->profile_url() . '"',
			'X-Event-Type'      => $event_type,
			'Webhook-Id'        => Ids::uuid4(),
			'Webhook-Timestamp' => (string) time(),
			'Idempotency-Key'   => Ids::uuid4(),
			'Content-Digest'    => HttpSignature::content_digest( $body ),
		);

		$signature_headers = $this->sign( 'POST', $target, $headers );
		if ( null !== $signature_headers ) {
			$headers = array_merge( $headers, $signature_headers );
		}

		$response = wp_remote_post(
			$target,
			array(
				'timeout'     => Config::get_int( 'webhook_timeout' ),
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		$code    = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$success = $code >= 200 && $code < 300;

		if ( $success ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: event type, 2: attempt number. */
					__( 'UCP webhook %1$s delivered (attempt %2$d).', 'ucp-server-for-woocommerce' ),
					$event_type,
					$attempt
				)
			);
			return true;
		}

		$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
		wc_get_logger()->warning(
			sprintf( 'UCP webhook %s for order %d failed (attempt %d): %s', $event_type, $order_id, $attempt, $reason ),
			array( 'source' => 'ucp-server-for-woocommerce' )
		);

		$max_attempts = max( 1, Config::get_int( 'webhook_max_attempts' ) );
		if ( $attempt < $max_attempts && function_exists( 'as_schedule_single_action' ) ) {
			$delay = self::BACKOFF[ min( $attempt, count( self::BACKOFF ) ) - 1 ];
			as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id, $event_type, $attempt + 1 ), 'ucpws' );
		}

		return false;
	}

	/**
	 * Sign the webhook request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Target URL.
	 * @param array<string, string> $headers Prepared headers.
	 * @return array{Signature-Input: string, Signature: string}|null
	 */
	private function sign( string $method, string $url, array $headers ): ?array {
		$key = $this->keys->active_key();
		if ( null === $key ) {
			return null;
		}

		return HttpSignature::sign_request(
			$method,
			$url,
			array(
				'ucp-agent'       => $headers['UCP-Agent'],
				'idempotency-key' => $headers['Idempotency-Key'],
				'content-digest'  => $headers['Content-Digest'],
				'content-type'    => $headers['Content-Type'],
			),
			$key['pem'],
			$key['kid'],
			time()
		);
	}
}
