<?php
/**
 * REST controller for the admin Dashboard figures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Dashboard\Stats;
use FlyAffiliate\REST\AdminBaseController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/dashboard` — everything the Dashboard screen shows, for a date range.
 *
 * @since FLYAFFILIATE_SINCE
 */
class DashboardController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'dashboard';

	/**
	 * Register the route.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_date_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pending-notice',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'dismiss_pending_notice' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Close the pending-review notice for the current user.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function dismiss_pending_notice( $request ) {
		$stats = new Stats();
		$stats->dismiss_pending_notice();

		return rest_ensure_response( [ 'pending_notice' => false ] );
	}

	/**
	 * The figures for the range.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		list( $after, $before ) = $this->get_date_bounds( $request );

		return $this->prepare_item_for_response( ( new Stats() )->get( $after, $before ), $request );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array           $item    The figures.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		return $this->add_links( rest_ensure_response( $this->filter_response_fields( (array) $item, $request ) ), $this->prepare_links( $item ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $item The figures.
	 *
	 * @return array
	 */
	protected function prepare_links( $item ): array {
		return [
			'self' => [ 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$ro = static fn( string $type, string $description ) => [
			'description' => $description,
			'type'        => $type,
			'context'     => [ 'view' ],
			'readonly'    => true,
		];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flyaffiliate_dashboard',
			'type'       => 'object',
			'properties' => [
				'range'              => $ro( 'object', __( 'The GMT bounds the figures cover.', 'flyaffiliate' ) ),
				'earnings'           => $ro( 'object', __( 'Referral revenue, commissions, net revenue, paid, unpaid and pending amounts.', 'flyaffiliate' ) ),
				'performance'        => $ro( 'object', __( 'Commission, visit and conversion counts.', 'flyaffiliate' ) ),
				'trend'              => $ro( 'array', __( 'Visits and conversions per day.', 'flyaffiliate' ) ),
				'top_affiliates'     => $ro( 'array', __( 'The affiliates who earned the most.', 'flyaffiliate' ) ),
				'top_products'       => $ro( 'array', __( 'The products that earned the most commissions.', 'flyaffiliate' ) ),
				'recent_visits'      => $ro( 'array', __( 'The newest visits.', 'flyaffiliate' ) ),
				'recent_commissions' => $ro( 'array', __( 'The newest commissions.', 'flyaffiliate' ) ),
				'affiliates'         => $ro( 'object', __( 'Affiliate counts.', 'flyaffiliate' ) ),
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
