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
		\FlyAffiliate\Integrations\WooCommerce\Integration::class,
	];

	/**
	 * The services that act on the platform, registered only while it is active.
	 *
	 * The integration itself (`Integration`) is always there, so the platform
	 * is listed as an origin and under Settings → Integrations whether or not
	 * its plugin is installed, as SliceWP lists its integrations. Its hooks —
	 * checkout attribution and order status sync — are what wait for the
	 * platform (ADR-0013).
	 *
	 * @var class-string[]
	 */
	protected array $platform_services = [
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
		if ( class_exists( 'WooCommerce' ) ) {
			$this->services = array_merge( $this->services, $this->platform_services );
		}

		$this->register_services();
	}
}
