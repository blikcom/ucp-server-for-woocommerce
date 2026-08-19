<?php
/**
 * Linking platform orders to customer accounts.
 *
 * @package UCPWS
 */

namespace UCPWS\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the WordPress user behind a buyer e-mail.
 *
 * Kept apart from CheckoutService so the rule is testable on its own, and so
 * the caveat below sits somewhere a reader will find it.
 *
 * WHAT THIS LINK MEANS: the e-mail arrives in the request body, so the link
 * IDENTIFIES a customer - it does not authorize anything on their behalf.
 * Anything that spends what is stored against an account (a saved payment
 * mandate above all) must demand its own proof, such as the merchant-issued
 * mandate reference, and must never treat `customer_id` alone as permission.
 */
class CustomerLink {

	/**
	 * Marks an order as created through a UCP platform, not the storefront.
	 */
	public const META_PLATFORM_ORIGIN = '_ucpws_platform';

	/**
	 * The user id for a buyer e-mail, or 0 when no account matches.
	 *
	 * @param string $email Sanitized buyer e-mail.
	 * @return int
	 */
	public static function resolve( string $email ): int {
		if ( '' === $email || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Whether an order may be linked to this user.
	 *
	 * An order that already belongs to someone is never re-pointed: a later
	 * buyer block must not move an order between accounts.
	 *
	 * @param int $current_customer_id The order's current customer id.
	 * @param int $resolved_user_id    The user the e-mail resolved to.
	 * @return bool
	 */
	public static function should_link( int $current_customer_id, int $resolved_user_id ): bool {
		return $current_customer_id <= 0 && $resolved_user_id > 0;
	}
}
