<?php
/**
 * Visits REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Visit;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/visits` — read-only.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VisitsController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'visits';

	/**
	 * Register the routes.
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
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'args'   => [
					'id' => [
						'description' => __( 'Unique identifier for the visit.', 'flyaffiliate' ),
						'type' => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [ 'context' => $this->get_context_param( [ 'default' => 'view' ] ) ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * List visits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$where    = [];

		if ( ! empty( $request['affiliate_id'] ) ) {
			$where['affiliate_id'] = (int) $request['affiliate_id'];
		}

		if ( isset( $request['converted'] ) && '' !== $request['converted'] ) {
			$where['converted'] = rest_sanitize_boolean( $request['converted'] ) ? 1 : 0;
		}

		$args = [
			'where'    => $where,
			'after'    => (string) ( $request['after'] ?? '' ),
			'before'   => (string) ( $request['before'] ?? '' ),
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
			'per_page' => $per_page,
			'page'     => (int) $request['page'],
		];

		if ( ! empty( $request['search'] ) ) {
			$args['search'] = [
				'term' => (string) $request['search'],
				'columns' => [ 'url', 'referrer' ],
			];
		}

		$items = [];

		foreach ( flyaffiliate()->tracking->query( $args ) as $visit ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $visit, $request ) );
		}

		return $this->prepare_collection_response( $items, flyaffiliate()->tracking->count( $args ), $per_page );
	}

	/**
	 * Get one visit.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$visit = flyaffiliate()->tracking->get( (int) $request['id'] );

		return null === $visit
			? new WP_Error( 'flyaffiliate_rest_visit_not_found', __( 'No visit with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] )
			: $this->prepare_item_for_response( $visit, $request );
	}

	/**
	 * Turn a visit into a response. Hashes are not exposed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Visit           $item    The visit.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = [
			'id'           => $item->get_id(),
			'affiliate_id' => (int) $item->get( 'affiliate_id' ),
			'affiliate_name' => $this->get_affiliate_name( (int) $item->get( 'affiliate_id' ) ),
			'url'          => (string) $item->get( 'url', '' ),
			'referrer'     => (string) $item->get( 'referrer', '' ),
			'converted'    => $item->is_converted(),
			'order_id'     => (int) $item->get( 'order_id', 0 ) > 0 ? (int) $item->get( 'order_id' ) : null,
			'created_at'   => empty( $item->get( 'created_at' ) ) ? null : mysql_to_rfc3339( (string) $item->get( 'created_at' ) ),
		];

		$response = rest_ensure_response( $this->filter_response_fields( $data, $request ) );

		return $this->add_links( $response, $this->prepare_links( $item ) );
	}

	/**
	 * The links for one visit.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Visit $item The visit.
	 *
	 * @return array
	 */
	protected function prepare_links( $item ): array {
		return [
			'self'       => [ 'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ) ],
			'collection' => [ 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ],
			'affiliate'  => [
				'href' => rest_url( sprintf( '%s/affiliates/%d', $this->namespace, (int) $item->get( 'affiliate_id' ) ) ),
				'embeddable' => true,
			],
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

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flyaffiliate_visit',
			'type'       => 'object',
			'properties' => [
				'id'           => [
					'description' => __( 'Unique identifier for the visit.', 'flyaffiliate' ),
					'type' => 'integer',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'affiliate_name' => [
					'description' => __( 'The affiliate’s display name.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'affiliate_id' => [
					'description' => __( 'The affiliate whose link was clicked.', 'flyaffiliate' ),
					'type' => 'integer',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'url'          => [
					'description' => __( 'The landing page.', 'flyaffiliate' ),
					'type' => 'string',
					'format' => 'uri',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'referrer'     => [
					'description' => __( 'The referring page, if any.', 'flyaffiliate' ),
					'type' => 'string',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'converted'    => [
					'description' => __( 'Whether it led to an order.', 'flyaffiliate' ),
					'type' => 'boolean',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'order_id'     => [
					'description' => __( 'The order, once converted.', 'flyaffiliate' ),
					'type' => [ 'integer', 'null' ],
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
				'created_at'   => [
					'description' => __( 'When the link was clicked, in GMT.', 'flyaffiliate' ),
					'type' => [ 'string', 'null' ],
					'format' => 'date-time',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['search']       = [
			'description' => __( 'Match the landing page or referrer.', 'flyaffiliate' ),
			'type' => 'string',
		];
		$params['affiliate_id'] = [
			'description' => __( 'Limit to an affiliate.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['converted']    = [
			'description' => __( 'Limit to converted or not.', 'flyaffiliate' ),
			'type' => 'boolean',
		];
		$params['after']        = [
			'description' => __( 'On or after this GMT datetime.', 'flyaffiliate' ),
			'type' => 'string',
			'format' => 'date-time',
		];
		$params['before']       = [
			'description' => __( 'On or before this GMT datetime.', 'flyaffiliate' ),
			'type' => 'string',
			'format' => 'date-time',
		];
		$params['orderby']      = [
			'description' => __( 'Sort field.', 'flyaffiliate' ),
			'type' => 'string',
			'default' => 'created_at',
			'enum' => [ 'id', 'created_at', 'converted' ],
		];
		$params['order']        = [
			'description' => __( 'Sort direction.', 'flyaffiliate' ),
			'type' => 'string',
			'default' => 'desc',
			'enum' => [ 'asc', 'desc' ],
		];

		return $params;
	}
}
