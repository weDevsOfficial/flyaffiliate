<?php
/**
 * Third-party integrations.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the WooCommerce integration.
 *
 * A marketplace integration adds its own provider from a listener attached
 * here; none ships on this branch.
 *
 * @since FLYAFFILIATE_SINCE
 */
class IntegrationServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'integration-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\Integrations\WooCommerce\OrderAttribution::class,
		\FlyAffiliate\Integrations\WooCommerce\OrderStatusSync::class,
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
