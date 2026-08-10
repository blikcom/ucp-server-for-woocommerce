<?php
/**
 * Capability negotiation.
 *
 * @package UCPWS
 */

namespace UCPWS\Negotiation;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the UCP capability intersection algorithm against a platform profile.
 */
class Negotiator {

	/**
	 * Profile fetcher.
	 *
	 * @var ProfileFetcher
	 */
	private $fetcher;

	/**
	 * Business profile builder (source of our own capability declarations).
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Constructor.
	 *
	 * @param ProfileFetcher $fetcher         Profile fetcher.
	 * @param ProfileBuilder $profile_builder Business profile builder.
	 */
	public function __construct( ProfileFetcher $fetcher, ProfileBuilder $profile_builder ) {
		$this->fetcher         = $fetcher;
		$this->profile_builder = $profile_builder;
	}

	/**
	 * Negotiate capabilities for a request.
	 *
	 * @param AgentHeader $agent          Parsed UCP-Agent header.
	 * @param bool        $budget_exempt  Whether the platform is registered (skips discovery budget).
	 * @return NegotiationContext
	 * @throws UcpException Discovery errors in strict mode.
	 */
	public function negotiate( AgentHeader $agent, bool $budget_exempt = false ): NegotiationContext {
		$strict  = 'strict' === Config::get( 'negotiation_mode' );
		$context = new NegotiationContext( $agent->profile_url, $agent->version );

		$agent->assert_url_allowed();

		$platform_profile = null;
		try {
			$platform_profile         = $this->fetcher->fetch( $agent->profile_url, $budget_exempt );
			$context->profile_fetched = true;
		} catch ( UcpException $exception ) {
			if ( $strict ) {
				throw $exception;
			}
			// Lenient mode: proceed without a platform profile (reference server
			// behavior; the conformance suite requires this posture).
		}

		$business_capabilities = $this->profile_builder->capability_declarations();

		if ( null === $platform_profile ) {
			// No platform declarations available: our capability set is active as-is.
			foreach ( $business_capabilities as $name => $entries ) {
				$context->capabilities[ $name ] = $entries[0]['version'];
			}
			return $context;
		}

		$platform_version = (string) ( $platform_profile['ucp']['version'] ?? '' );
		if ( '' !== $platform_version && $strict ) {
			// Request-time protocol version validation against the fetched profile.
			AgentHeader::validate_version( $platform_version );
		}

		$platform_capabilities = $this->normalize_capabilities( $platform_profile['ucp']['capabilities'] ?? array() );

		$context->platform_configs = $this->extract_configs( $platform_capabilities );
		$context->webhook_url      = $this->extract_webhook_url( $platform_capabilities );

		$intersection = $this->intersect( $business_capabilities, $platform_capabilities );

		if ( $strict ) {
			if ( array() === $intersection ) {
				throw UcpException::business(
					ErrorCodes::CAPABILITIES_INCOMPATIBLE,
					'No compatible capabilities: the platform and business capability sets do not intersect.'
				);
			}
			$context->capabilities = $intersection;
		} else {
			// Lenient: our declared set stays active; intersection versions win
			// where both parties declared the capability.
			foreach ( $business_capabilities as $name => $entries ) {
				$context->capabilities[ $name ] = $intersection[ $name ] ?? $entries[0]['version'];
			}
		}

		return $context;
	}

	/**
	 * Run the spec intersection algorithm.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $business Business capability declarations.
	 * @param array<string, array<int, array<string, mixed>>> $platform Platform capability declarations.
	 * @return array<string, string> Active capability name => selected version.
	 */
	public function intersect( array $business, array $platform ): array {
		$selected = array();

		// Steps 1 + 2: name intersection, then highest mutual version.
		foreach ( $business as $name => $business_entries ) {
			if ( ! isset( $platform[ $name ] ) ) {
				continue;
			}

			$business_versions = $this->versions_of( $business_entries );
			$platform_versions = $this->versions_of( $platform[ $name ] );
			$mutual            = array_values( array_intersect( $business_versions, $platform_versions ) );

			if ( array() === $mutual ) {
				continue;
			}

			sort( $mutual, SORT_STRING );
			$selected[ $name ] = (string) end( $mutual );
		}

		// Steps 3 + 4: prune orphaned extensions until stable.
		do {
			$removed = false;
			foreach ( array_keys( $selected ) as $name ) {
				$extends = $this->extends_of( $business[ $name ] ?? array() );
				if ( array() === $extends ) {
					continue;
				}
				$has_parent = false;
				foreach ( $extends as $parent ) {
					if ( isset( $selected[ $parent ] ) ) {
						$has_parent = true;
						break;
					}
				}
				if ( ! $has_parent ) {
					unset( $selected[ $name ] );
					$removed = true;
				}
			}
		} while ( $removed );

		return $selected;
	}

	/**
	 * Normalize a platform capabilities registry (tolerates dict-of-lists or bare list).
	 *
	 * @param mixed $capabilities Raw capabilities value.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function normalize_capabilities( $capabilities ): array {
		$normalized = array();

		if ( ! is_array( $capabilities ) ) {
			return $normalized;
		}

		foreach ( $capabilities as $key => $value ) {
			if ( is_string( $key ) && is_array( $value ) ) {
				$entries = array();
				foreach ( $value as $entry ) {
					if ( is_array( $entry ) ) {
						$entries[] = $entry;
					}
				}
				if ( array() !== $entries ) {
					$normalized[ $key ] = $entries;
				}
			} elseif ( is_int( $key ) && is_array( $value ) && isset( $value['name'] ) && is_string( $value['name'] ) ) {
				// Tolerate the bare-list capability form where each entry carries its own name.
				$normalized[ $value['name'] ][] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Extract capability configs keyed by capability name.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $capabilities Normalized platform capabilities.
	 * @return array<string, array<string, mixed>>
	 */
	private function extract_configs( array $capabilities ): array {
		$configs = array();
		foreach ( $capabilities as $name => $entries ) {
			foreach ( $entries as $entry ) {
				if ( isset( $entry['config'] ) && is_array( $entry['config'] ) ) {
					$configs[ $name ] = $entry['config'];
					break;
				}
			}
		}
		return $configs;
	}

	/**
	 * First webhook_url found in any capability config (order capability preferred).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $capabilities Normalized platform capabilities.
	 * @return string|null
	 */
	private function extract_webhook_url( array $capabilities ): ?string {
		$ordered = $capabilities;
		if ( isset( $ordered['dev.ucp.shopping.order'] ) ) {
			$ordered = array( 'dev.ucp.shopping.order' => $ordered['dev.ucp.shopping.order'] ) + $ordered;
		}

		foreach ( $ordered as $entries ) {
			foreach ( $entries as $entry ) {
				$url = $entry['config']['webhook_url'] ?? null;
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}
		}

		return null;
	}

	/**
	 * Version strings from capability entries.
	 *
	 * @param array<int, array<string, mixed>> $entries Capability entries.
	 * @return string[]
	 */
	private function versions_of( array $entries ): array {
		$versions = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['version'] ) && is_string( $entry['version'] ) ) {
				$versions[] = $entry['version'];
			}
		}
		return $versions;
	}

	/**
	 * `extends` values of the first entry (string or array form).
	 *
	 * @param array<int, array<string, mixed>> $entries Capability entries.
	 * @return string[]
	 */
	private function extends_of( array $entries ): array {
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['extends'] ) ) {
				continue;
			}
			if ( is_string( $entry['extends'] ) ) {
				return array( $entry['extends'] );
			}
			if ( is_array( $entry['extends'] ) ) {
				return array_values( array_filter( $entry['extends'], 'is_string' ) );
			}
		}
		return array();
	}
}
