<?php
/**
 * REST API bootstrap.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Registers every FlyAffiliate REST controller on `rest_api_init`.
 *
 * @since 1.0.0
 */
class Manager implements Hookable {

	/**
	 * The API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'flyaffiliate/v1';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * The controllers to register.
	 *
	 * @since 1.0.0
	 *
	 * @return class-string[]
	 */
	public function get_controllers(): array {
		$controllers = [
			\FlyAffiliate\REST\Controllers\AffiliatesController::class,
			\FlyAffiliate\REST\Controllers\CommissionsController::class,
			\FlyAffiliate\REST\Controllers\VisitsController::class,
			\FlyAffiliate\REST\Controllers\ReferralLinksController::class,
			\FlyAffiliate\REST\Controllers\PayoutsController::class,
			\FlyAffiliate\REST\Controllers\SettingsController::class,
			\FlyAffiliate\REST\Controllers\SetupController::class,
			\FlyAffiliate\REST\Controllers\MeController::class,
			\FlyAffiliate\REST\Controllers\DashboardController::class,
		];

		/**
		 * Filters the FlyAffiliate REST controllers.
		 *
		 * @since 1.0.0
		 *
		 * @param class-string[] $controllers Controller class names.
		 */
		return apply_filters( 'flyaffiliate_rest_controllers', $controllers );
	}

	/**
	 * Register the routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->get_controllers() as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$controller = new $class_name();

			if ( is_callable( [ $controller, 'register_routes' ] ) ) {
				$controller->register_routes();
			}
		}
	}
}
