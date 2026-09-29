<?php
/**
 * What the Dokan integration adds to the plugin's own REST API.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * The integration announces itself.
 *
 * Its settings are not here: a marketplace admin finds them in Dokan's own
 * settings ({@see AdminSettings}), and a vendor in their Dokan dashboard
 * ({@see VendorSettings}). What is left for the plugin's own side is the REST
 * route the vendor dashboard reads.
 *
 * Registered by {@see \FlyAffiliate\DependencyManagement\Providers\DokanServiceProvider},
 * so it only exists while Dokan is active.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Integration implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'flyaffiliate_rest_controllers', [ $this, 'add_controllers' ] );
	}

	/**
	 * The vendor's REST routes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param class-string[] $controllers Controller class names.
	 *
	 * @return class-string[]
	 */
	public function add_controllers( $controllers ): array {
		$controllers   = (array) $controllers;
		$controllers[] = REST\VendorAffiliatesController::class;

		return $controllers;
	}
}
