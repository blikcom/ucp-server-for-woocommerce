<?php
/**
 * Admin settings screen.
 *
 * @package UCPWS
 */

namespace UCPWS\Admin;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Security\SigningKeys;
use UCPWS\Storage\Platforms;
use UCPWS\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > UCP Server: platform registry, signing keys, core toggles.
 */
class SettingsPage {

	private const SLUG       = 'ucpws-settings';
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Platform registry.
	 *
	 * @var Platforms
	 */
	private $platforms;

	/**
	 * Signing keys.
	 *
	 * @var SigningKeys
	 */
	private $keys;

	/**
	 * Profile builder.
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Constructor.
	 *
	 * @param Platforms      $platforms       Platform registry.
	 * @param SigningKeys    $keys            Signing keys.
	 * @param ProfileBuilder $profile_builder Profile builder.
	 */
	public function __construct( Platforms $platforms, SigningKeys $keys, ProfileBuilder $profile_builder ) {
		$this->platforms       = $platforms;
		$this->keys            = $keys;
		$this->profile_builder = $profile_builder;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'UCP Server', 'ucp-server-for-woocommerce' ),
			__( 'UCP Server', 'ucp-server-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle form submissions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['ucpws_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'ucpws_settings' );

		$action = sanitize_key( wp_unslash( $_POST['ucpws_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$this->save_settings();
				break;

			case 'add_platform':
				$name        = isset( $_POST['ucpws_platform_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ucpws_platform_name'] ) ) : '';
				$profile_url = isset( $_POST['ucpws_platform_profile'] ) ? esc_url_raw( wp_unslash( $_POST['ucpws_platform_profile'] ) ) : '';
				if ( '' !== $name && '' !== $profile_url ) {
					$created = $this->platforms->create( $name, untrailingslashit( $profile_url ) );
					if ( null !== $created ) {
						set_transient( 'ucpws_new_key_' . get_current_user_id(), $created['api_key'], 120 );
					}
				}
				break;

			case 'delete_platform':
				$platform_id = isset( $_POST['ucpws_platform_id'] ) ? absint( $_POST['ucpws_platform_id'] ) : 0;
				if ( $platform_id > 0 ) {
					$this->platforms->delete( $platform_id );
				}
				break;

			case 'toggle_platform':
				$platform_id = isset( $_POST['ucpws_platform_id'] ) ? absint( $_POST['ucpws_platform_id'] ) : 0;
				$status      = isset( $_POST['ucpws_platform_status'] ) ? sanitize_key( wp_unslash( $_POST['ucpws_platform_status'] ) ) : 'active';
				if ( $platform_id > 0 ) {
					$this->platforms->set_status( $platform_id, $status );
				}
				break;

			case 'rotate_key':
				$this->keys->generate_key();
				break;

			case 'retire_key':
				$kid = isset( $_POST['ucpws_kid'] ) ? sanitize_text_field( wp_unslash( $_POST['ucpws_kid'] ) ) : '';
				if ( '' !== $kid ) {
					$this->keys->retire_key( $kid );
				}
				break;
		}//end switch

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&updated=1' ) );
		exit;
	}

	/**
	 * Persist the settings option.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$settings = array(
			'enabled'             => isset( $_POST['ucpws_enabled'] ) ? true : false,
			'auth_mode'           => isset( $_POST['ucpws_auth_mode'] ) && 'open' === $_POST['ucpws_auth_mode'] ? 'open' : 'registry',
			'negotiation_mode'    => isset( $_POST['ucpws_negotiation_mode'] ) && 'lenient' === $_POST['ucpws_negotiation_mode'] ? 'lenient' : 'strict',
			'validate_responses'  => isset( $_POST['ucpws_validate_responses'] ) ? true : false,
			'enable_mock_handler' => isset( $_POST['ucpws_enable_mock_handler'] ) ? true : false,
			'rate_limit'          => isset( $_POST['ucpws_rate_limit'] ) ? absint( $_POST['ucpws_rate_limit'] ) : 300,
		);
		// phpcs:enable

		$existing = get_option( Config::OPTION_SETTINGS, array() );
		update_option( Config::OPTION_SETTINGS, array_merge( is_array( $existing ) ? $existing : array(), $settings ) );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$new_key = get_transient( 'ucpws_new_key_' . get_current_user_id() );
		if ( false !== $new_key ) {
			delete_transient( 'ucpws_new_key_' . get_current_user_id() );
		}

		$platforms = $this->platforms->all();
		$jwks      = $this->keys->public_jwks();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UCP Server for WooCommerce', 'ucp-server-for-woocommerce' ); ?></h1>

			<p>
				<?php esc_html_e( 'Discovery profile:', 'ucp-server-for-woocommerce' ); ?>
				<code><?php echo esc_html( $this->profile_builder->profile_url() ); ?></code>
				&nbsp;|&nbsp;
				<?php esc_html_e( 'REST endpoint:', 'ucp-server-for-woocommerce' ); ?>
				<code><?php echo esc_html( $this->profile_builder->rest_endpoint() ); ?></code>
			</p>

			<?php if ( is_string( $new_key ) && '' !== $new_key ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php esc_html_e( 'Platform API key (copy now - it will not be shown again):', 'ucp-server-for-woocommerce' ); ?></strong>
						<code><?php echo esc_html( $new_key ); ?></code>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'ucp-server-for-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Every option can be overridden with UCPWS_* constants or environment variables (Bedrock-friendly). Values set that way take precedence over this form.', 'ucp-server-for-woocommerce' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'ucpws_settings' ); ?>
				<input type="hidden" name="ucpws_action" value="save_settings" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable UCP server', 'ucp-server-for-woocommerce' ); ?></th>
						<td><input type="checkbox" name="ucpws_enabled" <?php checked( Config::get_bool( 'enabled' ) ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Authentication', 'ucp-server-for-woocommerce' ); ?></th>
						<td>
							<select name="ucpws_auth_mode">
								<option value="registry" <?php selected( 'registry', Config::get( 'auth_mode' ) ); ?>><?php esc_html_e( 'Registry (require platform API keys)', 'ucp-server-for-woocommerce' ); ?></option>
								<option value="open" <?php selected( 'open', Config::get( 'auth_mode' ) ); ?>><?php esc_html_e( 'Open (no credentials required)', 'ucp-server-for-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Negotiation mode', 'ucp-server-for-woocommerce' ); ?></th>
						<td>
							<select name="ucpws_negotiation_mode">
								<option value="strict" <?php selected( 'strict', Config::get( 'negotiation_mode' ) ); ?>><?php esc_html_e( 'Strict (full spec discovery errors)', 'ucp-server-for-woocommerce' ); ?></option>
								<option value="lenient" <?php selected( 'lenient', Config::get( 'negotiation_mode' ) ); ?>><?php esc_html_e( 'Lenient (tolerate unreachable platform profiles)', 'ucp-server-for-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Validate responses against UCP schemas', 'ucp-server-for-woocommerce' ); ?></th>
						<td><input type="checkbox" name="ucpws_validate_responses" <?php checked( Config::get_bool( 'validate_responses' ) ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mock payment handler', 'ucp-server-for-woocommerce' ); ?></th>
						<td>
							<input type="checkbox" name="ucpws_enable_mock_handler" <?php checked( Config::get_bool( 'enable_mock_handler' ) ); ?> />
							<span class="description" style="color:#b32d2e;"><?php esc_html_e( 'Non-production. Approves test tokens without charging anything.', 'ucp-server-for-woocommerce' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate limit (requests per minute)', 'ucp-server-for-woocommerce' ); ?></th>
						<td><input type="number" min="0" name="ucpws_rate_limit" value="<?php echo esc_attr( (string) Config::get_int( 'rate_limit' ) ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'ucp-server-for-woocommerce' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Platform registry', 'ucp-server-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each API key is bound to exactly one platform profile URL. Requests are rejected when the authenticated key does not match the UCP-Agent profile.', 'ucp-server-for-woocommerce' ); ?></p>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'ucp-server-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Profile URL', 'ucp-server-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Key', 'ucp-server-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ucp-server-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'ucp-server-for-woocommerce' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $platforms ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No platforms registered yet.', 'ucp-server-for-woocommerce' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $platforms as $platform ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $platform->name ); ?></td>
							<td><code><?php echo esc_html( (string) $platform->profile_url ); ?></code></td>
							<td><code><?php echo esc_html( (string) $platform->key_hint ); ?></code></td>
							<td><?php echo esc_html( (string) $platform->status ); ?></td>
							<td><?php echo esc_html( (string) ( $platform->last_seen_at ?? '—' ) ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'ucpws_settings' ); ?>
									<input type="hidden" name="ucpws_action" value="toggle_platform" />
									<input type="hidden" name="ucpws_platform_id" value="<?php echo esc_attr( (string) $platform->id ); ?>" />
									<input type="hidden" name="ucpws_platform_status" value="<?php echo esc_attr( 'active' === $platform->status ? 'disabled' : 'active' ); ?>" />
									<button class="button-link"><?php 'active' === $platform->status ? esc_html_e( 'Disable', 'ucp-server-for-woocommerce' ) : esc_html_e( 'Enable', 'ucp-server-for-woocommerce' ); ?></button>
								</form>
								|
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'ucpws_settings' ); ?>
									<input type="hidden" name="ucpws_action" value="delete_platform" />
									<input type="hidden" name="ucpws_platform_id" value="<?php echo esc_attr( (string) $platform->id ); ?>" />
									<button class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'ucp-server-for-woocommerce' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Register a platform', 'ucp-server-for-woocommerce' ); ?></h3>
			<form method="post">
				<?php wp_nonce_field( 'ucpws_settings' ); ?>
				<input type="hidden" name="ucpws_action" value="add_platform" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ucpws_platform_name"><?php esc_html_e( 'Name', 'ucp-server-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ucpws_platform_name" name="ucpws_platform_name" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ucpws_platform_profile"><?php esc_html_e( 'Platform profile URL', 'ucp-server-for-woocommerce' ); ?></label></th>
						<td><input type="url" class="regular-text" id="ucpws_platform_profile" name="ucpws_platform_profile" placeholder="https://platform.example/.well-known/ucp" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create API key', 'ucp-server-for-woocommerce' ), 'secondary' ); ?>
			</form>

			<h2><?php esc_html_e( 'Signing keys', 'ucp-server-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Public JWKs published in the discovery profile. Private keys never leave this server. Rotate regularly; retired keys stop being published.', 'ucp-server-for-woocommerce' ); ?></p>
			<table class="widefat striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key ID', 'ucp-server-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Type', 'ucp-server-for-woocommerce' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jwks as $jwk ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $jwk['kid'] ); ?></code></td>
							<td><?php echo esc_html( $jwk['kty'] . ' / ' . $jwk['crv'] . ' / ' . $jwk['alg'] ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'ucpws_settings' ); ?>
									<input type="hidden" name="ucpws_action" value="retire_key" />
									<input type="hidden" name="ucpws_kid" value="<?php echo esc_attr( (string) $jwk['kid'] ); ?>" />
									<button class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Retire', 'ucp-server-for-woocommerce' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'ucpws_settings' ); ?>
				<input type="hidden" name="ucpws_action" value="rotate_key" />
				<?php submit_button( __( 'Generate new signing key', 'ucp-server-for-woocommerce' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
