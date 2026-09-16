<?php
/**
 * The first-run setup wizard.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * The PHP side of the setup wizard.
 *
 * The wizard itself is a route of the admin app (`#/setup`): the same
 * plugin-ui components as every other screen, saving through the settings
 * REST endpoint. This class owns what only PHP can do — send the activating
 * admin there once, and remember that the wizard was completed or dismissed
 * so it never redirects again.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SetupWizard implements Hookable {

	/**
	 * The route of the wizard inside the admin app.
	 *
	 * @var string
	 */
	const ROUTE = 'setup';

	/**
	 * Option recording that the wizard has been completed or dismissed.
	 *
	 * @var string
	 */
	const DONE_OPTION = 'flyaffiliate_setup_wizard_done';

	/**
	 * Transient set on activation that triggers the one-time redirect.
	 *
	 * @var string
	 */
	const REDIRECT_TRANSIENT = 'flyaffiliate_setup_wizard_redirect';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
	}

	/**
	 * The URL of the wizard.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public static function get_url(): string {
		return Menu::get_route_url( self::ROUTE );
	}

	/**
	 * Whether the wizard has been completed or dismissed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public static function is_done(): bool {
		return (bool) get_option( self::DONE_OPTION );
	}

	/**
	 * Record that the wizard is finished, so it never redirects again.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public static function mark_done(): void {
		update_option( self::DONE_OPTION, 1 );
		delete_transient( self::REDIRECT_TRANSIENT );
	}

	/**
	 * Arm the one-time redirect.
	 *
	 * Called by the installer, not from a hook: activation runs the installer
	 * after `init`, when the plugin's own listeners are not attached yet, so a
	 * hook here would never fire on the activation that matters.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public static function schedule_redirect(): void {
		if ( self::is_done() ) {
			return;
		}

		set_transient( self::REDIRECT_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Send the activating admin to the wizard, once.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deciding whether to redirect; a bulk activation is left alone.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( self::get_url() );
		exit;
	}
}
