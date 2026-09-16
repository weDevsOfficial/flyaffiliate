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
 * @since FLYAFFILIATE_SINCE
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
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_services();
	}
}
