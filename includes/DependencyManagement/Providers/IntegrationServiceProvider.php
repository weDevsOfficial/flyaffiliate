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
 * The Dokan services are added from a `dokan_loaded` listener attached in
 * `boot()`. `boot()` runs while the plugin file is being loaded — before
 * `plugins_loaded`, and therefore before any plugin can fire `dokan_loaded` —
 * so the listener is always in place in time, whichever order the two plugins
 * happen to load in.
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

	/**
	 * Listen for Dokan.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function boot(): void {
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
