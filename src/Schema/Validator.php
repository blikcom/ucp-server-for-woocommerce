<?php
/**
 * JSON Schema validation against the bundled official UCP schemas.
 *
 * @package UCPWS
 */

namespace UCPWS\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as OpisValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Validates payloads against the official UCP v2026-04-08 JSON Schemas
 * (bundled under resources/schemas), composing extension schemas onto their
 * parent capability per the spec's schema-resolution rules
 * (`extension.json#/$defs/{root_capability}` via allOf).
 */
class Validator {

	private const ID_PREFIX = 'https://ucp.dev/schemas/';

	/**
	 * Opis validator.
	 *
	 * @var OpisValidator|null
	 */
	private $validator;

	/**
	 * Registered composed schema ids.
	 *
	 * @var array<string, bool>
	 */
	private $composed = array();

	/**
	 * Lazily build the Opis validator with the bundled schema resolver.
	 *
	 * @return OpisValidator
	 */
	private function validator(): OpisValidator {
		if ( null !== $this->validator ) {
			return $this->validator;
		}

		$this->validator = new OpisValidator();
		$this->validator->setMaxErrors( 5 );

		$resolver = $this->validator->resolver();
		if ( null !== $resolver ) {
			$resolver->registerPrefix( self::ID_PREFIX, UCPWS_PLUGIN_DIR . 'resources/schemas/' . UCPWS_UCP_VERSION . '/' );
		}

		return $this->validator;
	}

	/**
	 * Validate a payload against a schema reference.
	 *
	 * @param mixed  $payload Decoded payload (arrays are converted to objects).
	 * @param string $ref     Schema URI (e.g. `https://ucp.dev/schemas/shopping/checkout.json`).
	 * @return string[] Error strings; empty when valid.
	 */
	public function validate( $payload, string $ref ): array {
		$data   = json_decode( (string) wp_json_encode( $payload ) );
		$result = $this->validator()->validate( $data, $ref );

		if ( $result->isValid() ) {
			return array();
		}

		$error = $result->error();
		if ( null === $error ) {
			return array( 'Unknown validation error.' );
		}

		$formatted = ( new ErrorFormatter() )->format( $error, false );
		$messages  = array();
		foreach ( $formatted as $pointer => $message ) {
			$messages[] = $pointer . ': ' . ( is_array( $message ) ? implode( '; ', $message ) : (string) $message );
		}

		return $messages;
	}

	/**
	 * Validate a checkout resource with active extensions composed in.
	 *
	 * @param mixed    $payload    Checkout payload.
	 * @param string[] $extensions Active extension capability names (e.g. dev.ucp.shopping.fulfillment).
	 * @return string[] Errors.
	 */
	public function validate_checkout( $payload, array $extensions = array() ): array {
		$refs = array( self::ID_PREFIX . 'shopping/checkout.json' );

		if ( in_array( 'dev.ucp.shopping.fulfillment', $extensions, true ) ) {
			$refs[] = self::ID_PREFIX . 'shopping/fulfillment.json#/$defs/dev.ucp.shopping.checkout';
		}

		if ( 1 === count( $refs ) ) {
			return $this->validate( $payload, $refs[0] );
		}

		// Compose: allOf over the base + each active extension's $defs entry.
		$all_of = array();
		foreach ( $refs as $ref ) {
			$all_of[] = array( '$ref' => $ref );
		}

		$composed = array(
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id'     => self::ID_PREFIX . 'composed/' . md5( implode( '|', $refs ) ) . '.json',
			'allOf'   => $all_of,
		);

		$validator = $this->validator();
		$schema_id = (string) $composed['$id'];
		if ( ! isset( $this->composed[ $schema_id ] ) ) {
			// @phpstan-ignore-next-line -- the resolver is always configured.
			$validator->resolver()->registerRaw( (string) wp_json_encode( $composed ), $schema_id );
			$this->composed[ $schema_id ] = true;
		}

		return $this->validate( $payload, $schema_id );
	}

	/**
	 * Validate the business discovery profile.
	 *
	 * @param mixed $payload Profile document.
	 * @return string[] Errors.
	 */
	public function validate_profile( $payload ): array {
		return $this->validate( $payload, self::ID_PREFIX . 'discovery/profile_schema.json#/$defs/business_profile' );
	}

	/**
	 * Validate an order entity.
	 *
	 * @param mixed $payload Order payload.
	 * @return string[] Errors.
	 */
	public function validate_order( $payload ): array {
		return $this->validate( $payload, self::ID_PREFIX . 'shopping/order.json' );
	}

	/**
	 * Validate a catalog search response.
	 *
	 * @param mixed $payload Response payload.
	 * @return string[] Errors.
	 */
	public function validate_catalog_search( $payload ): array {
		return $this->validate( $payload, self::ID_PREFIX . 'shopping/catalog_search.json#/$defs/search_response' );
	}

	/**
	 * Validate a catalog lookup response.
	 *
	 * @param mixed $payload Response payload.
	 * @return string[] Errors.
	 */
	public function validate_catalog_lookup( $payload ): array {
		return $this->validate( $payload, self::ID_PREFIX . 'shopping/catalog_lookup.json#/$defs/lookup_response' );
	}
}
