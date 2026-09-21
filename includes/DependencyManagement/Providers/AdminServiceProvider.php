<?php
/**
 * Admin-side services.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the wp-admin menu, the React app's PHP-side services, the setup
 * wizard and the user-profile section.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AdminServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'admin-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\Admin\Menu::class,
		\FlyAffiliate\Admin\PayoutExport::class,
		\FlyAffiliate\Admin\UserProfile::class,
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
