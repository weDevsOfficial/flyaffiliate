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
 * Today it registers the Integrations → Dokan settings. Phase 4 fills in the
 * rest: the vendor program and rates, sub-order attribution, the earnings
 * adjuster, the vendor charge, refund sync, the vendor dashboard and the
 * product promote panel. What each of them may and may not do is settled in
 * CONTEXT.md and ADR-0004.
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
		\FlyAffiliate\Integrations\Dokan\Integration::class,
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
	}
}
