<?php
/**
 * The base test case.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test;

use Brain\Monkey;
use FlyAffiliate\Test\CustomAssertion\DBAssertionTrait;
use FlyAffiliate\Test\CustomAssertion\MoneyAssertionTrait;
use FlyAffiliate\Test\CustomAssertion\NestedArrayAssertionTrait;
use FlyAffiliate\Test\Factories\FlyAffiliateFactory;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Every FlyAffiliate test extends this.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class FlyAffiliateTestCase extends WP_UnitTestCase {

	use DBAssertionTrait;
	use MoneyAssertionTrait;
	use NestedArrayAssertionTrait;
	use MockeryPHPUnitIntegration;

	/**
	 * The REST API namespace under test.
	 *
	 * @var string
	 */
	protected string $namespace = 'flyaffiliate/v1';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	protected int $admin_id = 0;

	/**
	 * A customer.
	 *
	 * @var int
	 */
	protected int $customer_id = 0;

	/**
	 * The REST server.
	 *
	 * @var WP_REST_Server|null
	 */
	protected ?WP_REST_Server $server = null;

	/**
	 * Set to true in a test class that needs neither the REST server nor users.
	 *
	 * @var bool
	 */
	protected bool $is_unit_test = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Monkey\setUp();

		if ( $this->is_unit_test ) {
			return;
		}

		// The settings service caches the option for the request, and the test
		// process is one long request: forget what an earlier test stored
		// before its transaction was rolled back.
		flyaffiliate()->get_container()->get( \FlyAffiliate\Admin\Settings\Repository\SettingsRepositoryInterface::class )->flush_cache();
		flyaffiliate()->settings->get_registry()->clear_cache();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;

		do_action( 'rest_api_init' );

		$this->set_up_users();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function tear_down() {
		Monkey\tearDown();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Create the users most tests need.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	protected function set_up_users(): void {
		$this->admin_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return FlyAffiliateFactory
	 */
	protected static function factory() {
		static $factory = null;

		if ( null === $factory ) {
			$factory = new FlyAffiliateFactory();
		}

		return $factory;
	}


	/**
	 * Become a user for the rest of the test.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id User id.
	 *
	 * @return void
	 */
	protected function acting_as( int $user_id ): void {
		wp_set_current_user( $user_id );
	}

	/**
	 * A route with the namespace applied.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $route Route, with or without the namespace.
	 *
	 * @return string
	 */
	protected function get_route( string $route ): string {
		$namespace = '/' . trim( $this->namespace, '/' );
		$route     = '/' . ltrim( $route, '/' );

		return 0 === strpos( $route, $namespace ) ? $route : $namespace . $route;
	}

	/**
	 * Dispatch a request against the REST server.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Query parameters for GET, body parameters otherwise.
	 *
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $this->get_route( $route ) );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a GET request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $route  Route.
	 * @param array  $params Query parameters.
	 *
	 * @return WP_REST_Response
	 */
	protected function get_request( string $route, array $params = [] ): WP_REST_Response {
		return $this->request( 'GET', $route, $params );
	}

	/**
	 * Dispatch a POST request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $route  Route.
	 * @param array  $params Body parameters.
	 *
	 * @return WP_REST_Response
	 */
	protected function post_request( string $route, array $params = [] ): WP_REST_Response {
		return $this->request( 'POST', $route, $params );
	}

	/**
	 * Dispatch a PUT request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $route  Route.
	 * @param array  $params Body parameters.
	 *
	 * @return WP_REST_Response
	 */
	protected function put_request( string $route, array $params = [] ): WP_REST_Response {
		return $this->request( 'PUT', $route, $params );
	}

	/**
	 * Dispatch a DELETE request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $route  Route.
	 * @param array  $params Body parameters.
	 *
	 * @return WP_REST_Response
	 */
	protected function delete_request( string $route, array $params = [] ): WP_REST_Response {
		return $this->request( 'DELETE', $route, $params );
	}
}
