<?php
/**
 * UCP error code registry.
 *
 * @package UCPWS
 */

namespace UCPWS\Protocol;

defined( 'ABSPATH' ) || exit;

/**
 * Error codes and their HTTP statuses per the UCP v2026-04-08 error registry.
 */
final class ErrorCodes {

	// Negotiation / discovery errors (transport-level).
	public const INVALID_PROFILE_URL       = 'invalid_profile_url';
	public const PROFILE_UNREACHABLE       = 'profile_unreachable';
	public const PROFILE_MALFORMED         = 'profile_malformed';
	public const VERSION_UNSUPPORTED       = 'version_unsupported';
	public const CAPABILITIES_INCOMPATIBLE = 'capabilities_incompatible';
	public const PROFILE_NOT_TRUSTED       = 'profile_not_trusted';

	// Signature errors (transport-level).
	public const SIGNATURE_MISSING     = 'signature_missing';
	public const SIGNATURE_INVALID     = 'signature_invalid';
	public const KEY_NOT_FOUND         = 'key_not_found';
	public const DIGEST_MISMATCH       = 'digest_mismatch';
	public const ALGORITHM_UNSUPPORTED = 'algorithm_unsupported';

	// Standard business error codes.
	public const NOT_FOUND             = 'not_found';
	public const OUT_OF_STOCK          = 'out_of_stock';
	public const ITEM_UNAVAILABLE      = 'item_unavailable';
	public const ADDRESS_UNDELIVERABLE = 'address_undeliverable';
	public const PAYMENT_FAILED        = 'payment_failed';
	public const ELIGIBILITY_INVALID   = 'eligibility_invalid';
	public const UNAUTHORIZED          = 'unauthorized';
	public const INVALID_REQUEST       = 'invalid_request';
	public const MISSING               = 'missing';

	public const DISCOVERY_CODES = array(
		self::INVALID_PROFILE_URL,
		self::PROFILE_UNREACHABLE,
		self::PROFILE_MALFORMED,
		self::VERSION_UNSUPPORTED,
	);

	/**
	 * HTTP status for a transport-level error code (REST binding).
	 *
	 * @param string $code UCP error code.
	 * @return int
	 */
	public static function http_status( string $code ): int {
		switch ( $code ) {
			case self::INVALID_PROFILE_URL:
			case self::DIGEST_MISMATCH:
			case self::ALGORITHM_UNSUPPORTED:
				return 400;
			case self::SIGNATURE_MISSING:
			case self::SIGNATURE_INVALID:
			case self::KEY_NOT_FOUND:
				return 401;
			case self::PROFILE_NOT_TRUSTED:
				return 403;
			case self::PROFILE_MALFORMED:
			case self::VERSION_UNSUPPORTED:
				return 422;
			case self::PROFILE_UNREACHABLE:
				return 424;
			default:
				return 400;
		}
	}
}
