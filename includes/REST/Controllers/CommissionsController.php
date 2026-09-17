<?php
/**
 * Commissions REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Commission;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/commissions` — read, filter, add by hand, and change status.
 *
 * There is no route that marks a commission paid; that is a payout.
 *
 * @since FLYAFFILIATE_SINCE
 */
class CommissionsController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'commissions';

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
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
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
						'description' => __( 'Unique identifier for the commission.', 'flyaffiliate' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [ 'context' => $this->get_context_param( [ 'default' => 'view' ] ) ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * List commissions.
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

		foreach ( [ 'status', 'affiliate_id', 'order_id', 'vendor_id', 'source', 'payout_id' ] as $filter ) {
			if ( isset( $request[ $filter ] ) && '' !== $request[ $filter ] ) {
				$where[ $filter ] = $request[ $filter ];
			}
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

		$items = [];

		foreach ( flyaffiliate()->commission->query( $args ) as $commission ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $commission, $request ) );
		}

		return $this->prepare_collection_response( $items, flyaffiliate()->commission->count( $args ), $per_page );
	}

	/**
	 * Get one commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$commission = flyaffiliate()->commission->get( (int) $request['id'] );

		return null === $commission ? $this->not_found() : $this->prepare_item_for_response( $commission, $request );
	}

	/**
	 * Add a commission by hand.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$args = [
			'affiliate_id' => (int) $request['affiliate_id'],
			'amount'       => (float) $request['amount'],
			'base_amount'  => (float) ( $request['base_amount'] ?? 0 ),
			'order_id'     => (int) ( $request['order_id'] ?? 0 ),
		];

		foreach ( [ 'source', 'type', 'status', 'created_at' ] as $field ) {
			if ( isset( $request[ $field ] ) && '' !== $request[ $field ] ) {
				$args[ $field ] = (string) $request[ $field ];
			}
		}

		$result = flyaffiliate()->commission->create( $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $result, $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $result->get_id() ) ) );

		return $response;
	}

	/**
	 * Edit a commission: its amount, reference, reference amount, type or status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id = (int) $request['id'];

		if ( null === flyaffiliate()->commission->get( $id ) ) {
			return $this->not_found();
		}

		$changes = [];

		foreach ( [ 'amount', 'base_amount', 'order_id', 'type', 'status' ] as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$changes[ $field ] = $request[ $field ];
			}
		}

		$result = flyaffiliate()->commission->update( $id, $changes );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->prepare_item_for_response( flyaffiliate()->commission->get( $id ), $request );
	}

	/**
	 * Delete an unpaid commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$commission = flyaffiliate()->commission->get( (int) $request['id'] );

		if ( null === $commission ) {
			return $this->not_found();
		}

		if ( $commission->is_in_payout() ) {
			return new WP_Error( 'flyaffiliate_rest_in_payout', __( 'This commission belongs to a payment. Take it out of the payment first, or delete the payment.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		if ( $commission->is_locked() ) {
			return new WP_Error( 'flyaffiliate_rest_locked', __( 'A paid commission cannot be deleted.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$previous = $this->prepare_item_for_response( $commission, $request );

		flyaffiliate()->commission->delete( $commission->get_id() );

		return rest_ensure_response(
			[
				'deleted' => true,
				'previous' => $previous->get_data(),
			]
		);
	}

	/**
	 * Turn a commission into a response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission      $item    The commission.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = [
			'id'            => $item->get_id(),
			'affiliate_id'  => (int) $item->get( 'affiliate_id' ),
			'affiliate_name' => $this->get_affiliate_name( (int) $item->get( 'affiliate_id' ) ),
			'order_id'      => (int) $item->get( 'order_id', 0 ),
			'order_item_id' => null === $item->get( 'order_item_id' ) ? null : (int) $item->get( 'order_item_id' ),
			'product_id'    => (int) $item->get( 'product_id', 0 ),
			'vendor_id'     => (int) $item->get( 'vendor_id', 0 ),
			'base_amount'   => (float) $item->get( 'base_amount', 0 ),
			'rate'          => (float) $item->get( 'rate', 0 ),
			'rate_type'     => (string) $item->get( 'rate_type' ),
			'amount'        => (float) $item->get( 'amount', 0 ),
			'currency'      => (string) $item->get( 'currency', '' ),
			'source'        => (string) $item->get( 'source' ),
			'type'          => (string) $item->get( 'type' ),
			'status'        => (string) $item->get( 'status' ),
			'payout_id'     => (int) $item->get( 'payout_id', 0 ) > 0 ? (int) $item->get( 'payout_id' ) : null,
			'matures_at'    => $this->prepare_date( $item->get( 'matures_at' ) ),
			'created_at'    => $this->prepare_date( $item->get( 'created_at' ) ),
			'updated_at'    => $this->prepare_date( $item->get( 'updated_at' ) ),
		];

		$response = rest_ensure_response( $this->filter_response_fields( $data, $request ) );

		return $this->add_links( $response, $this->prepare_links( $item ) );
	}

	/**
	 * The links for one commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $item The commission.
	 *
	 * @return array
	 */
	protected function prepare_links( $item ): array {
		$links = [
			'self'       => [ 'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ) ],
			'collection' => [ 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ],
			'affiliate'  => [
				'href' => rest_url( sprintf( '%s/affiliates/%d', $this->namespace, (int) $item->get( 'affiliate_id' ) ) ),
				'embeddable' => true,
			],
		];

		if ( (int) $item->get( 'payout_id', 0 ) > 0 ) {
			$links['payout'] = [
				'href' => rest_url( sprintf( '%s/payouts/%d', $this->namespace, (int) $item->get( 'payout_id' ) ) ),
				'embeddable' => true,
			];
		}

		return $links;
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

		$money = static fn( string $description ) => [
			'description' => $description,
			'type' => 'number',
			'context' => [ 'view', 'edit' ],
		];
		$ro    = static fn( array $property ) => $property + [ 'readonly' => true ];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flyaffiliate_commission',
			'type'       => 'object',
			'properties' => [
				'id'            => $ro(
					[
						'description' => __( 'Unique identifier for the commission.', 'flyaffiliate' ),
						'type' => 'integer',
						'context' => [ 'view', 'edit' ],
					]
				),
				'affiliate_id'  => [
					'description' => __( 'The affiliate who earned it.', 'flyaffiliate' ),
					'type' => 'integer',
					'context' => [ 'view', 'edit' ],
					'required' => true,
				],
				'order_id'      => [
					'description' => __( 'The order it came from, if any.', 'flyaffiliate' ),
					'type' => 'integer',
					'context' => [ 'view', 'edit' ],
				],
				'order_item_id' => $ro(
					[
						'description' => __( 'The order item it came from. Null for a manual commission.', 'flyaffiliate' ),
						'type' => [ 'integer', 'null' ],
						'context' => [ 'view', 'edit' ],
					]
				),
				'product_id'    => $ro(
					[
						'description' => __( 'The product.', 'flyaffiliate' ),
						'type' => 'integer',
						'context' => [ 'view', 'edit' ],
					]
				),
				'vendor_id'     => $ro(
					[
						'description' => __( 'The vendor the item belongs to, or 0 on a single-merchant store.', 'flyaffiliate' ),
						'type' => 'integer',
						'context' => [ 'view', 'edit' ],
					]
				),
				'base_amount'   => $money( __( 'The amount the rate was applied to.', 'flyaffiliate' ) ),
				'rate'          => $ro( $money( __( 'The rate applied.', 'flyaffiliate' ) ) ),
				'rate_type'     => $ro(
					[
						'description' => __( 'Percentage or fixed.', 'flyaffiliate' ),
						'type' => 'string',
						'enum' => [ Commission::RATE_PERCENTAGE, Commission::RATE_FIXED ],
						'context' => [ 'view', 'edit' ],
					]
				),
				'amount'        => $money( __( 'What the affiliate earns. Editable until the commission is paid or inside a payment.', 'flyaffiliate' ) ),
				'currency'      => $ro(
					[
						'description' => __( 'Currency code.', 'flyaffiliate' ),
						'type' => 'string',
						'context' => [ 'view', 'edit' ],
					]
				),
				'source'        => [
					'description' => __( 'Where the commission came from: its origin. Set when it is created; a WooCommerce commission with an order follows that order.', 'flyaffiliate' ),
					'type' => 'string',
					'enum' => array_keys( Commission::get_sources() ),
					'context' => [ 'view', 'edit' ],
				],
				'type'          => [
					'description' => __( 'Commission type.', 'flyaffiliate' ),
					'type' => 'string',
					'enum' => array_keys( Commission::get_types() ),
					'context' => [ 'view', 'edit' ],
				],
				'status'        => [
					'description' => __( 'Status. Any status can be given when the commission is created; afterwards paid is set by a payout, never here.', 'flyaffiliate' ),
					'type' => 'string',
					'enum' => [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID, Commission::STATUS_REJECTED, Commission::STATUS_PAID ],
					'context' => [ 'view', 'edit' ],
				],
				'payout_id'     => $ro(
					[
						'description' => __( 'The payout it was paid in, once paid.', 'flyaffiliate' ),
						'type' => [ 'integer', 'null' ],
						'context' => [ 'view', 'edit' ],
					]
				),
				'matures_at'    => $ro(
					[
						'description' => __( 'When it can mature, in GMT.', 'flyaffiliate' ),
						'type' => [ 'string', 'null' ],
						'format' => 'date-time',
						'context' => [ 'view', 'edit' ],
					]
				),
				'created_at'    => [
					'description' => __( 'When it was created, in GMT. Can be given when the commission is created; fixed afterwards.', 'flyaffiliate' ),
					'type' => [ 'string', 'null' ],
					'format' => 'date-time',
					'context' => [ 'view', 'edit' ],
				],
				'updated_at'    => $ro(
					[
						'description' => __( 'When it last changed, in GMT.', 'flyaffiliate' ),
						'type' => [ 'string', 'null' ],
						'format' => 'date-time',
						'context' => [ 'view', 'edit' ],
					]
				),
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

		$params['status']       = [
			'description' => __( 'Limit to a status.', 'flyaffiliate' ),
			'type' => 'string',
			'enum' => array_keys( Commission::get_statuses() ),
		];
		$params['affiliate_id'] = [
			'description' => __( 'Limit to an affiliate.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['order_id']     = [
			'description' => __( 'Limit to an order.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['vendor_id']    = [
			'description' => __( 'Limit to a vendor.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['source']       = [
			'description' => __( 'Limit to a source.', 'flyaffiliate' ),
			'type' => 'string',
			'enum' => array_keys( Commission::get_sources() ),
		];
		$params['payout_id']    = [
			'description' => __( 'Limit to the commissions of a payment.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['after']        = [
			'description' => __( 'Created on or after this GMT datetime.', 'flyaffiliate' ),
			'type' => 'string',
			'format' => 'date-time',
		];
		$params['before']       = [
			'description' => __( 'Created on or before this GMT datetime.', 'flyaffiliate' ),
			'type' => 'string',
			'format' => 'date-time',
		];
		$params['orderby']      = [
			'description' => __( 'Sort field.', 'flyaffiliate' ),
			'type' => 'string',
			'default' => 'created_at',
			'enum' => [ 'id', 'amount', 'status', 'created_at', 'matures_at', 'order_id' ],
		];
		$params['order']        = [
			'description' => __( 'Sort direction.', 'flyaffiliate' ),
			'type' => 'string',
			'default' => 'desc',
			'enum' => [ 'asc', 'desc' ],
		];

		return $params;
	}

	/**
	 * The 404 for a missing commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return WP_Error
	 */
	protected function not_found(): WP_Error {
		return new WP_Error( 'flyaffiliate_rest_commission_not_found', __( 'No commission with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
	}

	/**
	 * Format a stored datetime as RFC3339.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string|null $value Stored GMT value.
	 *
	 * @return string|null
	 */
	protected function prepare_date( $value ): ?string {
		return empty( $value ) ? null : mysql_to_rfc3339( (string) $value );
	}
}
