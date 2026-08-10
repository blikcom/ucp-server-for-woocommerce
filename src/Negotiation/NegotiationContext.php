<?php
/**
 * Result of capability negotiation for one request.
 *
 * @package UCPWS
 */

namespace UCPWS\Negotiation;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish negotiation outcome carried through request handling.
 */
final class NegotiationContext {

	/**
	 * Platform profile URL (from UCP-Agent).
	 *
	 * @var string
	 */
	public $profile_url;

	/**
	 * Negotiated protocol version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Active capabilities: name => selected version.
	 *
	 * @var array<string, string>
	 */
	public $capabilities;

	/**
	 * Platform capability configs: name => config array.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $platform_configs;

	/**
	 * Webhook URL from the platform's order capability config (if any).
	 *
	 * @var string|null
	 */
	public $webhook_url;

	/**
	 * Registered platform row id (registry auth mode), if authenticated.
	 *
	 * @var int|null
	 */
	public $platform_id;

	/**
	 * Whether the platform profile was successfully fetched.
	 *
	 * @var bool
	 */
	public $profile_fetched;

	/**
	 * Constructor.
	 *
	 * @param string $profile_url Platform profile URL.
	 * @param string $version     Negotiated protocol version.
	 */
	public function __construct( string $profile_url, string $version ) {
		$this->profile_url      = $profile_url;
		$this->version          = $version;
		$this->capabilities     = array();
		$this->platform_configs = array();
		$this->webhook_url      = null;
		$this->platform_id      = null;
		$this->profile_fetched  = false;
	}

	/**
	 * Whether a capability is active.
	 *
	 * @param string $name Capability name.
	 * @return bool
	 */
	public function has_capability( string $name ): bool {
		return isset( $this->capabilities[ $name ] );
	}

	/**
	 * Serialize for session persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'profile_url'      => $this->profile_url,
			'version'          => $this->version,
			'capabilities'     => $this->capabilities,
			'platform_configs' => $this->platform_configs,
			'webhook_url'      => $this->webhook_url,
			'platform_id'      => $this->platform_id,
			'profile_fetched'  => $this->profile_fetched,
		);
	}

	/**
	 * Restore from session persistence.
	 *
	 * @param array<string, mixed> $data Serialized context.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$context                   = new self( (string) ( $data['profile_url'] ?? '' ), (string) ( $data['version'] ?? UCPWS_UCP_VERSION ) );
		$context->capabilities     = is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : array();
		$context->platform_configs = is_array( $data['platform_configs'] ?? null ) ? $data['platform_configs'] : array();
		$context->webhook_url      = isset( $data['webhook_url'] ) && is_string( $data['webhook_url'] ) && '' !== $data['webhook_url'] ? $data['webhook_url'] : null;
		$context->platform_id      = isset( $data['platform_id'] ) ? (int) $data['platform_id'] : null;
		$context->profile_fetched  = ! empty( $data['profile_fetched'] );
		return $context;
	}
}
