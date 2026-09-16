<?php
/**
 * Services that only exist during an admin-ajax request.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the admin-ajax handlers.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AjaxServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'ajax-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [];

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
