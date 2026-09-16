<?php
/**
 * Bootable service provider contract.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider that also runs code when it is added to the container.
 *
 * `boot()` runs immediately after `register()`. It is where a provider adds
 * other providers, or attaches the WordPress hook that will add them later —
 * the root provider adds every other provider from its `boot()`.
 *
 * @since FLYAFFILIATE_SINCE
 */
interface BootableServiceProviderInterface extends ServiceProviderInterface {

	/**
	 * Run once, immediately after this provider registers its entries.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function boot(): void;
}
