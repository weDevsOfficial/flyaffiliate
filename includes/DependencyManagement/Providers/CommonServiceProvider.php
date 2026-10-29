<?php
/**
 * Services that run on every request.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers services needed on both the admin side and the front end.
 *
 * @since FLYAFFILIATE_SINCE
 */
class CommonServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'common-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * The services every request needs, admin or front end.
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\Affiliate\Role::class,
		\FlyAffiliate\Tracking\Tracker::class,
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
