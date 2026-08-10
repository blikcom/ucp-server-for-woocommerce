<?php
/**
 * Identifier generation.
 *
 * @package UCPWS
 */

namespace UCPWS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Opaque identifier helpers.
 */
final class Ids {

	/**
	 * RFC 4122 v4 UUID.
	 *
	 * @return string
	 */
	public static function uuid4(): string {
		$bytes = random_bytes( 16 );

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		$hex = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/**
	 * Prefixed random identifier (e.g. `chk_9f2c…`).
	 *
	 * @param string $prefix Prefix without underscore.
	 * @param int    $bytes  Random byte count.
	 * @return string
	 */
	public static function prefixed( string $prefix, int $bytes = 16 ): string {
		return $prefix . '_' . bin2hex( random_bytes( $bytes ) );
	}
}
