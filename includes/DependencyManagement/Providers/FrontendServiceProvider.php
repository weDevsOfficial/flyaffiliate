<?php
/**
 * Front-end services.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the affiliate-facing front-end services.
 *
 * @since 1.0.0
 */
class FrontendServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'frontend-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\Frontend\Shortcodes::class,
		\FlyAffiliate\Frontend\AffiliateDashboard::class,
	];

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_services();
	}
}
