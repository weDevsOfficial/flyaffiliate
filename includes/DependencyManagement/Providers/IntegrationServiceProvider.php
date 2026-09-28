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

use FlyAffiliate\DependencyManagement\BootableServiceProvider;

/**
 * Registers the WooCommerce integration, and the Dokan one when Dokan is active.
 *
 * The Dokan services are added once `dokan_loaded` has fired. This provider is
 * added on `plugins_loaded`, and Dokan usually fires `dokan_loaded` before that
 * (from `woocommerce_loaded`, at `plugins_loaded` priority -1), so `boot()`
 * registers them straight away when it already has, and listens otherwise.
 *
 * Nothing outside `FlyAffiliate\Integrations\Dokan` may reference a Dokan
 * symbol. The plugin is fully functional with Dokan absent.
 *
 * @since FLYAFFILIATE_SINCE
 */
class IntegrationServiceProvider extends BootableServiceProvider {

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

	/**
	 * Register the Dokan services, now or once Dokan has loaded.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( did_action( 'dokan_loaded' ) ) {
			$this->register_dokan_services();
			return;
		}

		add_action( 'dokan_loaded', [ $this, 'register_dokan_services' ] );
	}

	/**
	 * Add the Dokan provider, once Dokan has confirmed it is loaded.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_dokan_services(): void {
		if ( ! function_exists( 'dokan' ) ) {
			return;
		}

		$this->getContainer()->addServiceProvider( new DokanServiceProvider() );
	}
}
