<?php
/**
 * EC P-256 signing key management.
 *
 * @package UCPWS
 */

namespace UCPWS\Security;

use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Generates, stores and exposes the business signing keys.
 *
 * - Private keys are generated on activation and stored in a non-autoloaded
 *   option. They never leave the server and are never rendered anywhere.
 * - Public keys are published as JWKs in the discovery profile `signing_keys`.
 * - Rotation-friendly: multiple keys can be live; the newest active key signs,
 *   all non-retired keys stay published so in-flight signatures verify.
 * - Bedrock/env installs can instead provide a PEM via the
 *   `UCPWS_SIGNING_KEY_PATH` constant/env (file path) or `UCPWS_SIGNING_KEY_PEM`.
 */
class SigningKeys {

	public const OPTION = 'ucpws_signing_keys';

	/**
	 * Ensure at least one signing key exists. Called on activation.
	 *
	 * @return void
	 */
	public function ensure_key(): void {
		if ( array() !== $this->get_keys() ) {
			return;
		}
		$this->generate_key();
	}

	/**
	 * Generate and store a new active signing key.
	 *
	 * @return string|null The new key id, or null on failure.
	 */
	public function generate_key(): ?string {
		$resource = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		if ( false === $resource ) {
			return null;
		}

		if ( ! openssl_pkey_export( $resource, $pem ) ) {
			return null;
		}

		$kid = 'ucpws-' . gmdate( 'Ymd' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );

		$keys   = $this->stored_keys();
		$keys[] = array(
			'kid'        => $kid,
			'pem'        => $pem,
			'created_at' => gmdate( 'c' ),
			'retired'    => false,
		);

		update_option( self::OPTION, $keys, false );

		return $kid;
	}

	/**
	 * Retire a key (stops publishing + signing with it).
	 *
	 * @param string $kid Key id.
	 * @return bool
	 */
	public function retire_key( string $kid ): bool {
		$keys  = $this->stored_keys();
		$found = false;
		foreach ( $keys as &$key ) {
			if ( $key['kid'] === $kid ) {
				$key['retired'] = true;
				$found          = true;
			}
		}
		unset( $key );
		if ( $found ) {
			update_option( self::OPTION, $keys, false );
		}
		return $found;
	}

	/**
	 * All live (non-retired) keys, external key first if configured.
	 *
	 * @return array<int, array{kid: string, pem: string}>
	 */
	public function get_keys(): array {
		$keys = array();

		$external = $this->external_key();
		if ( null !== $external ) {
			$keys[] = $external;
		}

		foreach ( $this->stored_keys() as $key ) {
			if ( empty( $key['retired'] ) ) {
				$keys[] = array(
					'kid' => (string) $key['kid'],
					'pem' => (string) $key['pem'],
				);
			}
		}

		return $keys;
	}

	/**
	 * The active signing key (first live key).
	 *
	 * @return array{kid: string, pem: string}|null
	 */
	public function active_key(): ?array {
		$keys = $this->get_keys();
		return array() === $keys ? null : $keys[0];
	}

	/**
	 * Public JWKs for the discovery profile.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function public_jwks(): array {
		$jwks = array();
		foreach ( $this->get_keys() as $key ) {
			$jwk = self::pem_to_public_jwk( $key['pem'], $key['kid'] );
			if ( null !== $jwk ) {
				$jwks[] = $jwk;
			}
		}
		return $jwks;
	}

	/**
	 * Convert an EC private key PEM to its public JWK representation.
	 *
	 * @param string $pem Private key PEM.
	 * @param string $kid Key id.
	 * @return array<string, string>|null
	 */
	public static function pem_to_public_jwk( string $pem, string $kid ): ?array {
		$key = openssl_pkey_get_private( $pem );
		if ( false === $key ) {
			return null;
		}

		$details = openssl_pkey_get_details( $key );
		if ( false === $details || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return null;
		}

		return array(
			'kid' => $kid,
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => self::b64url( str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) ),
			'y'   => self::b64url( str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT ) ),
			'use' => 'sig',
			'alg' => 'ES256',
		);
	}

	/**
	 * Externally-provided key (constant/env), for secret-manager based installs.
	 *
	 * @return array{kid: string, pem: string}|null
	 */
	private function external_key(): ?array {
		$pem = '';

		if ( defined( 'UCPWS_SIGNING_KEY_PEM' ) && is_string( UCPWS_SIGNING_KEY_PEM ) ) {
			$pem = UCPWS_SIGNING_KEY_PEM;
		} elseif ( false !== getenv( 'UCPWS_SIGNING_KEY_PEM' ) ) {
			$pem = (string) getenv( 'UCPWS_SIGNING_KEY_PEM' );
		} else {
			$path = '';
			if ( defined( 'UCPWS_SIGNING_KEY_PATH' ) && is_string( UCPWS_SIGNING_KEY_PATH ) ) {
				$path = UCPWS_SIGNING_KEY_PATH;
			} elseif ( false !== getenv( 'UCPWS_SIGNING_KEY_PATH' ) ) {
				$path = (string) getenv( 'UCPWS_SIGNING_KEY_PATH' );
			}
			if ( '' !== $path && is_readable( $path ) ) {
				$pem = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local key file read.
			}
		}

		if ( '' === trim( $pem ) ) {
			return null;
		}

		$kid = defined( 'UCPWS_SIGNING_KEY_ID' ) ? (string) UCPWS_SIGNING_KEY_ID : ( getenv( 'UCPWS_SIGNING_KEY_ID' ) ? (string) getenv( 'UCPWS_SIGNING_KEY_ID' ) : 'ucpws-external' );

		return array(
			'kid' => $kid,
			'pem' => $pem,
		);
	}

	/**
	 * Raw stored key entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored_keys(): array {
		$keys = get_option( self::OPTION, array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWK encoding.
	}
}
