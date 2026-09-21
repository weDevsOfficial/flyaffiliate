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

/**
 * The PHP side of the setup wizard.
 *
 * The wizard itself is a route of the admin app (`#/setup`): the same
 * plugin-ui components as every other screen, saving through the settings
 * REST endpoint. This class owns what only PHP can do — the route, its URL,
 * and the flag that records the wizard was completed or dismissed.
 *
 * Nothing sends an admin there. Settings shows "Run the setup wizard" until
 * it is done. A one-time redirect after activation was removed for the
 * WordPress.org review (Guideline 11 reads any redirect on `admin_init` as
 * hijacking the dashboard); it comes back, if at all, once the plugin is
 * approved — reverting the commit that removed it restores the whole thing.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SetupWizard {

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
	 * Record that the wizard is finished.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public static function mark_done(): void {
		update_option( self::DONE_OPTION, 1 );
	}
}
