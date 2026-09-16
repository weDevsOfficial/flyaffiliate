<?php
/**
 * WP-CLI commands.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BaseServiceProvider;

/**
 * Registers the WP-CLI commands, resolved only when running under WP-CLI.
 *
 * Sample-data generation lives here and nowhere else: a plugin that seeds data
 * on `admin_init` is rejected by WordPress.org, and rightly so.
 *
 * @since FLYAFFILIATE_SINCE
 */
class CliServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'cli-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		\FlyAffiliate\CLI\Seeder::class,
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
