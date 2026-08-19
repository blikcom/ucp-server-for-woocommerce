<?php
/**
 * The few WP_User fields the unit tests need (no WordPress here).
 *
 * @package UCPWS
 */

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal stand-in for WordPress's user object.
	 */
	class WP_User {

		/**
		 * User id.
		 *
		 * @var int
		 */
		public $ID = 0;
	}
}
