<?php
/**
 * Payouts REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Payout;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/payouts` — payments and the batches (payouts) they belong to.
 *
 * Preview and create a batch, list its payments, mark a payment paid or
 * unpaid, take a commission out of an unpaid payment, delete an unpaid
 * payment or a whole unpaid batch — the SliceWP payout flow (ADR-0012).
 *
 * @since FLYAFFILIATE_SINCE
 */
class PayoutsController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'payouts';

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
					'args'                => $this->get_batch_args(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'preview' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_batch_args(),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batches',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_batches' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'page'     => [
							'description' => __( 'Current page of the collection.', 'flyaffiliate' ),
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						],
						'per_page' => [
							'description' => __( 'Maximum number of batches to be returned in result set.', 'flyaffiliate' ),
							'type'        => 'integer',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch/(?P<batch_key>[A-Za-z0-9-]+)',
			[
				'args' => [
					'batch_key' => [
						'description' => __( 'The batch key.', 'flyaffiliate' ),
						'type'        => 'string',
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_batch' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch/(?P<batch_key>[A-Za-z0-9-]+)/pay',
			[
				'args' => [
					'batch_key' => [
						'description' => __( 'The batch key.', 'flyaffiliate' ),
						'type'        => 'string',
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'pay_batch' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'args'   => [
					'id' => [
						'description' => __( 'Unique identifier for the payment.', 'flyaffiliate' ),
						'type' => 'integer',
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
					'args'                => [
						'status' => [
							'description' => __( 'Mark the payment paid (the money was sent) or unpaid again.', 'flyaffiliate' ),
							'type'        => 'string',
							'enum'        => [ Payout::STATUS_UNPAID, Payout::STATUS_PAID ],
							'required'    => true,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/commissions/(?P<commission_id>[\d]+)',
			[
				'args' => [
					'id'            => [
						'description' => __( 'The payment.', 'flyaffiliate' ),
						'type'        => 'integer',
					],
					'commission_id' => [
						'description' => __( 'The commission to take out of it.', 'flyaffiliate' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_commission' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
				],
			]
		);
	}

	/**
	 * The arguments a batch preview or creation takes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	protected function get_batch_args(): array {
		return [
			'mode'           => [
				'description' => __( 'Which affiliates: all, only the selected, or all except the selected.', 'flyaffiliate' ),
				'type' => 'string',
				'enum' => [ 'all', 'selected', 'except' ],
				'default' => 'all',
			],
			'affiliate_ids'  => [
				'description' => __( 'The affiliates the mode refers to.', 'flyaffiliate' ),
				'type' => 'array',
				'items' => [ 'type' => 'integer' ],
				'default' => [],
			],
			'minimum_amount' => [
				'description' => __( 'Leave out affiliates below this unpaid total.', 'flyaffiliate' ),
				'type' => 'number',
				'minimum' => 0,
			],
			'period_start'   => [
				'description' => __( 'Only commissions created on or after this date (Y-m-d).', 'flyaffiliate' ),
				'type' => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			],
			'period_end'     => [
				'description' => __( 'Only commissions created on or before this date (Y-m-d).', 'flyaffiliate' ),
				'type' => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			],
			'note'           => [
				'description' => __( 'A description shown as the payout title.', 'flyaffiliate' ),
				'type' => 'string',
			],
			'reference'      => [
				'description' => __( 'A payment reference recorded on every row.', 'flyaffiliate' ),
				'type' => 'string',
			],
		];
	}

	/**
	 * The batch selection from a request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array
	 */
	protected function get_selection( WP_REST_Request $request ): array {
		$selection = [
			'mode'          => (string) $request['mode'],
			'affiliate_ids' => array_map( 'intval', (array) $request['affiliate_ids'] ),
			'period_start'  => (string) ( $request['period_start'] ?? '' ),
			'period_end'    => (string) ( $request['period_end'] ?? '' ),
			'note'          => (string) ( $request['note'] ?? '' ),
			'reference'     => (string) ( $request['reference'] ?? '' ),
		];

		if ( isset( $request['minimum_amount'] ) ) {
			$selection['minimum_amount'] = (float) $request['minimum_amount'];
		}

		return $selection;
	}

	/**
	 * List payout rows.
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

		if ( ! empty( $request['batch_key'] ) ) {
			$where['batch_key'] = (string) $request['batch_key'];
		}

		if ( ! empty( $request['status'] ) ) {
			$where['status'] = (string) $request['status'];
		}

		$args  = [
			'where' => $where,
			'orderby' => (string) $request['orderby'],
			'order' => (string) $request['order'],
			'per_page' => $per_page,
			'page' => (int) $request['page'],
		];
		$items = [];

		foreach ( flyaffiliate()->payout->query( $args ) as $payout ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $payout, $request ) );
		}

		return $this->prepare_collection_response( $items, flyaffiliate()->payout->count( $args ), $per_page );
	}

	/**
	 * Get one payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$payout = flyaffiliate()->payout->get( (int) $request['id'] );

		return null === $payout
			? new WP_Error( 'flyaffiliate_rest_payout_not_found', __( 'No payment with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] )
			: $this->prepare_item_for_response( $payout, $request );
	}

	/**
	 * Mark a payment paid or unpaid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id     = (int) $request['id'];
		$result = Payout::STATUS_PAID === (string) $request['status']
			? flyaffiliate()->payout->mark_paid( $id )
			: flyaffiliate()->payout->mark_unpaid( $id );

		return is_wp_error( $result ) ? $result : $this->prepare_item_for_response( $result, $request );
	}

	/**
	 * Delete an unpaid payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$result = flyaffiliate()->payout->delete( (int) $request['id'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * Take a commission out of an unpaid payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_commission( $request ) {
		$result = flyaffiliate()->payout->remove_commission( (int) $request['id'], (int) $request['commission_id'] );

		return is_wp_error( $result ) ? $result : $this->prepare_item_for_response( $result, $request );
	}

	/**
	 * The batches, newest first.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_batches( $request ) {
		$per_page = (int) $request['per_page'];
		$result   = flyaffiliate()->payout->get_batches( $per_page, (int) $request['page'] );

		return $this->prepare_collection_response( $result['batches'], $result['total'], $per_page );
	}

	/**
	 * Mark every unpaid payment of a batch paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function pay_batch( $request ) {
		return rest_ensure_response( [ 'paid' => flyaffiliate()->payout->pay_batch( (string) $request['batch_key'] ) ] );
	}

	/**
	 * Delete a batch none of whose payments has been paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_batch( $request ) {
		$result = flyaffiliate()->payout->delete_batch( (string) $request['batch_key'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( [ 'deleted' => $result ] );
	}

	/**
	 * What a batch would pay, without writing.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function preview( $request ) {
		$preview = flyaffiliate()->payout->preview( $this->get_selection( $request ) );
		$rows    = [];

		foreach ( $preview['rows'] as $affiliate_id => $row ) {
			$rows[] = [
				'affiliate_id'  => $affiliate_id,
				'name'          => $row['affiliate']->get_display_name(),
				'payment_email' => $row['affiliate']->get_payment_email(),
				'commissions'   => array_map( static fn( $commission ) => $commission->get_id(), $row['commissions'] ),
				'amount'        => $row['amount'],
			];
		}

		return rest_ensure_response(
			[
				'rows'    => $rows,
				'total'   => $preview['total'],
				'count'   => $preview['count'],
				'pending' => $preview['pending'],
			]
		);
	}

	/**
	 * Record a batch.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$result = flyaffiliate()->payout->create( $this->get_selection( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payouts = [];

		foreach ( $result['payouts'] as $payout ) {
			$payouts[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $payout, $request ) );
		}

		$response = rest_ensure_response(
			[
				'batch_key' => $result['batch_key'],
				'total' => $result['total'],
				'payouts' => $payouts,
			]
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Turn a payout row into a response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout          $item    The payout.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data = [
			'id'           => $item->get_id(),
			'batch_key'    => (string) $item->get( 'batch_key' ),
			'affiliate_id' => (int) $item->get( 'affiliate_id' ),
			'affiliate_name' => $this->get_affiliate_name( (int) $item->get( 'affiliate_id' ) ),
			'payment_email'  => $this->get_affiliate_payment_email( (int) $item->get( 'affiliate_id' ) ),
			'amount'       => (float) $item->get( 'amount', 0 ),
			'currency'     => (string) $item->get( 'currency', '' ),
			'method'       => (string) $item->get( 'method' ),
			'status'       => (string) $item->get( 'status' ),
			'reference'    => (string) $item->get( 'reference', '' ),
			'note'         => (string) $item->get( 'note', '' ),
			'period_start' => empty( $item->get( 'period_start' ) ) ? null : mysql_to_rfc3339( (string) $item->get( 'period_start' ) ),
			'period_end'   => empty( $item->get( 'period_end' ) ) ? null : mysql_to_rfc3339( (string) $item->get( 'period_end' ) ),
			'created_by'   => (int) $item->get( 'created_by', 0 ),
			'created_at'   => empty( $item->get( 'created_at' ) ) ? null : mysql_to_rfc3339( (string) $item->get( 'created_at' ) ),
			'commissions'  => array_map( static fn( $commission ) => $commission->get_id(), $item->get_commissions() ),
		];

		$response = rest_ensure_response( $this->filter_response_fields( $data, $request ) );

		return $this->add_links( $response, $this->prepare_links( $item ) );
	}

	/**
	 * The links for one payout row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $item The payout.
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
			'batch'      => [ 'href' => add_query_arg( 'batch_key', (string) $item->get( 'batch_key' ), rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ) ],
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

		$ro = static fn( array $property ) => $property + [
			'context' => [ 'view', 'edit' ],
			'readonly' => true,
		];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flyaffiliate_payout',
			'type'       => 'object',
			'properties' => [
				'id'           => $ro(
					[
						'description' => __( 'Unique identifier for the payout row.', 'flyaffiliate' ),
						'type' => 'integer',
					]
				),
				'batch_key'    => $ro(
					[
						'description' => __( 'The batch this row belongs to.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'affiliate_id' => $ro(
					[
						'description' => __( 'The affiliate paid.', 'flyaffiliate' ),
						'type' => 'integer',
					]
				),
				'payment_email' => $ro(
					[
						'description' => __( 'Where this affiliate is paid.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'amount'       => $ro(
					[
						'description' => __( 'The amount paid.', 'flyaffiliate' ),
						'type' => 'number',
					]
				),
				'currency'     => $ro(
					[
						'description' => __( 'Currency code.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'method'       => $ro(
					[
						'description' => __( 'Payment method.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'status'       => $ro(
					[
						'description' => __( 'unpaid until the money is sent and the payment marked paid; paid after that.', 'flyaffiliate' ),
						'type' => 'string',
						'enum' => [ Payout::STATUS_UNPAID, Payout::STATUS_PAID ],
					]
				),
				'reference'    => $ro(
					[
						'description' => __( 'Payment reference.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'note'         => $ro(
					[
						'description' => __( 'Description.', 'flyaffiliate' ),
						'type' => 'string',
					]
				),
				'period_start' => $ro(
					[
						'description' => __( 'Start of the commission period, in GMT.', 'flyaffiliate' ),
						'type' => [ 'string', 'null' ],
						'format' => 'date-time',
					]
				),
				'period_end'   => $ro(
					[
						'description' => __( 'End of the commission period, in GMT.', 'flyaffiliate' ),
						'type' => [ 'string', 'null' ],
						'format' => 'date-time',
					]
				),
				'created_by'   => $ro(
					[
						'description' => __( 'The user who recorded it.', 'flyaffiliate' ),
						'type' => 'integer',
					]
				),
				'created_at'   => $ro(
					[
						'description' => __( 'When it was recorded, in GMT.', 'flyaffiliate' ),
						'type' => [ 'string', 'null' ],
						'format' => 'date-time',
					]
				),
				'commissions'  => $ro(
					[
						'description' => __( 'The commission ids it covers.', 'flyaffiliate' ),
						'type' => 'array',
						'items' => [ 'type' => 'integer' ],
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

		$params['affiliate_id'] = [
			'description' => __( 'Limit to an affiliate.', 'flyaffiliate' ),
			'type' => 'integer',
		];
		$params['batch_key']    = [
			'description'       => __( 'Limit to a batch.', 'flyaffiliate' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		];
		$params['status']       = [
			'description' => __( 'Limit to a status.', 'flyaffiliate' ),
			'type' => 'string',
			'enum' => [ Payout::STATUS_UNPAID, Payout::STATUS_PAID ],
		];
		$params['orderby']      = [
			'description' => __( 'Sort field.', 'flyaffiliate' ),
			'type' => 'string',
			'default' => 'created_at',
			'enum' => [ 'id', 'amount', 'created_at', 'affiliate_id' ],
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
