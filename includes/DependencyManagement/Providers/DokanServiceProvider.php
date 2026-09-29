<?php
/**
 * Dokan integration services.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the Dokan integration.
 *
 * Added by {@see IntegrationServiceProvider} once `dokan_loaded` has fired, so
 * it is never constructed on a site without Dokan. Everything it registers
 * lives under `FlyAffiliate\Integrations\Dokan`.
 *
 * Today it registers the vendor programs: the settings inside Dokan's admin
 * settings, the vendor's own rate and lock, the seams that feed them into a
 * commission, the storefront notices and the vendor dashboard's Affiliates
 * page. Still to come: the earnings adjuster, the vendor charge and refund
 * sync, whose limits are settled in CONTEXT.md and ADR-0004.
 *
 * @since FLYAFFILIATE_SINCE
 */
class DokanServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'integration-service', 'dokan-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\Integrations\Dokan\VendorProgram::class,
		\FlyAffiliate\Integrations\Dokan\Integration::class,
		\FlyAffiliate\Integrations\Dokan\AdminSettings::class,
		\FlyAffiliate\Integrations\Dokan\VendorRates::class,
		\FlyAffiliate\Integrations\Dokan\VendorSettings::class,
		\FlyAffiliate\Integrations\Dokan\StorefrontNotices::class,
		\FlyAffiliate\Integrations\Dokan\VendorDashboard::class,
	];

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_services();

		// The named service `flyaffiliate()->dokan` is the same shared instance, so its per-request memo is one memo.
		$this->getContainer()->addShared(
			'dokan',
			static fn( $container ) => $container->get( \FlyAffiliate\Integrations\Dokan\VendorProgram::class )
		);
	}
}
