<?php
/**
 * Affiliates REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/affiliates` — the admin's view of the affiliate roster.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AffiliatesController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'affiliates';

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
						'description' => __( 'Unique identifier for the affiliate.', 'flyaffiliate' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'context' => $this->get_context_param( [ 'default' => 'view' ] ),
					],
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
					'args'                => [
						'force' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Delete the affiliate row. Their commissions, visits and payouts are financial records and are kept.', 'flyaffiliate' ),
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * List affiliates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * Commission totals for the affiliates in the collection being prepared.
	 *
	 * Filled once per collection request so the list does not run a grouped
	 * query per row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @var array<int, array>
	 */
	protected array $list_totals = [];

	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$where    = [];

		if ( ! empty( $request['status'] ) ) {
			$where['status'] = $request['status'];
		}

		if ( ! empty( $request['user_id'] ) ) {
			$where['user_id'] = absint( $request['user_id'] );
		}

		$conditions = [
			'where'  => $where,
			'search' => flyaffiliate()->affiliate->get_search_args( (string) ( $request['search'] ?? '' ) ),
		];

		$affiliates = flyaffiliate()->affiliate->query(
			$conditions + [
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
				'per_page' => $per_page,
				'page'     => (int) $request['page'],
			]
		);

		$this->list_totals = flyaffiliate()->commission->get_totals_for_affiliates(
			array_map( static fn( $affiliate ) => $affiliate->get_id(), $affiliates )
		);

		$items = [];

		foreach ( $affiliates as $affiliate ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $affiliate, $request ) );
		}

		$this->list_totals = [];

		return $this->prepare_collection_response( $items, flyaffiliate()->affiliate->count( $conditions ), $per_page );
	}

	/**
	 * Get one affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$affiliate = flyaffiliate()->affiliate->get( (int) $request['id'] );

		if ( null === $affiliate ) {
			return $this->not_found();
		}

		return $this->prepare_item_for_response( $affiliate, $request );
	}

	/**
	 * Create an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$affiliate = flyaffiliate()->affiliate->create(
			[
				'user_id'       => (int) $request['user_id'],
				'status'        => $request['status'] ?? Affiliate::STATUS_PENDING,
				'payment_email' => $request['payment_email'] ?? '',
				'promo_method'  => $request['promo_method'] ?? '',
				'website'       => $request['website'] ?? '',
				'send_welcome_email' => ! empty( $request['send_welcome_email'] ),
			]
		);

		if ( is_wp_error( $affiliate ) ) {
			return $affiliate;
		}

		$response = $this->prepare_item_for_response( $affiliate, $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $affiliate->get_id() ) ) );

		return $response;
	}

	/**
	 * Update an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$changes = [];

		foreach ( [ 'status', 'payment_email', 'promo_method', 'website' ] as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$changes[ $field ] = $request[ $field ];
			}
		}

		$affiliate = flyaffiliate()->affiliate->update( (int) $request['id'], $changes );

		if ( is_wp_error( $affiliate ) ) {
			return $affiliate;
		}

		return $this->prepare_item_for_response( $affiliate, $request );
	}

	/**
	 * Delete an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$affiliate = flyaffiliate()->affiliate->get( (int) $request['id'] );

		if ( null === $affiliate ) {
			return $this->not_found();
		}

		$previous = $this->prepare_item_for_response( $affiliate, $request );

		if ( ! flyaffiliate()->affiliate->delete( (int) $request['id'] ) ) {
			return new WP_Error(
				'flyaffiliate_rest_delete_failed',
				__( 'The affiliate could not be deleted.', 'flyaffiliate' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'deleted'  => true,
				'previous' => $previous->get_data(),
			]
		);
	}

	/**
	 * Turn an affiliate into a response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate       $item    The affiliate.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * The WordPress username behind an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user id.
	 *
	 * @return string The login, or an empty string when the user is gone.
	 */
	protected function get_user_login( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? $user->user_login : '';
	}

	/**
	 * The account email behind an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user.
	 *
	 * @return string The email, or an empty string when the user is gone.
	 */
	protected function get_user_email( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? $user->user_email : '';
	}

	public function prepare_item_for_response( $item, $request ) {
		$data = [
			'id'            => $item->get_id(),
			'user_id'       => (int) $item->get( 'user_id' ),
			'name'          => $item->get_display_name(),
			'user_login'    => $this->get_user_login( (int) $item->get( 'user_id' ) ),
			'email'         => $this->get_user_email( (int) $item->get( 'user_id' ) ),
			'status'        => (string) $item->get( 'status' ),
			'payment_email' => $item->get_payment_email(),
			'promo_method'  => (string) $item->get( 'promo_method', '' ),
			'website'       => $item->get_website(),
			'referral_url'  => $item->get_referral_url(),
			'created_at'    => $this->prepare_date( $item->get( 'created_at' ) ),
			'updated_at'    => $this->prepare_date( $item->get( 'updated_at' ) ),
		];

		// The list totals every affiliate on the page in one query; the detail
		// view adds the visit counts, which the list does not show.
		if ( isset( $this->list_totals[ $item->get_id() ] ) ) {
			$data['totals'] = $this->list_totals[ $item->get_id() ];
		}

		if ( 'edit' === ( $request['context'] ?? 'view' ) ) {
			$data['totals'] = flyaffiliate()->commission->get_affiliate_totals( $item->get_id() );
			$data['visits'] = flyaffiliate()->tracking->count_by_result( [ 'where' => [ 'affiliate_id' => $item->get_id() ] ] );
		}

		$response = rest_ensure_response( $this->filter_response_fields( $data, $request ) );

		return $this->add_links( $response, $this->prepare_links( $item ) );
	}

	/**
	 * The links for one affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $item The affiliate.
	 *
	 * @return array
	 */
	protected function prepare_links( $item ): array {
		$links = [
			'self'       => [
				'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ),
			],
			'collection' => [
				'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ),
			],
		];

		$user_id = (int) $item->get( 'user_id' );

		if ( $user_id > 0 ) {
			$links['author'] = [
				'href'       => rest_url( sprintf( 'wp/v2/users/%d', $user_id ) ),
				'embeddable' => true,
			];
		}

		return $links;
	}

	/**
	 * Validate the payment email, which may be empty.
	 *
	 * Clearing the field is how an admin sends payouts back to the account
	 * email, so the empty string passes; anything else must be an address.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed           $value   The submitted value.
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   The parameter name.
	 *
	 * @return true|WP_Error True when valid, WP_Error otherwise.
	 */
	public function validate_payment_email( $value, $request, $param ) {
		if ( '' === trim( (string) $value ) ) {
			return true;
		}

		return rest_validate_request_arg( $value, $request, $param );
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
			'title'      => 'flyaffiliate_affiliate',
			'type'       => 'object',
			'properties' => [
				'id'            => [
					'description' => __( 'Unique identifier for the affiliate.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'user_id'       => [
					'description' => __( 'The WordPress user this affiliate is. One user is one affiliate, sitewide.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'required'    => true,
				],
				'name'          => [
					'description' => __( 'The affiliate\'s display name.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'user_login'    => [
					'description' => __( 'The WordPress username behind the affiliate.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'email'         => [
					'description' => __( 'The account email of the user behind the affiliate.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'status'        => [
					'description' => __( 'Affiliate status.', 'flyaffiliate' ),
					'type'        => 'string',
					'enum'        => array_keys( Affiliate::get_statuses() ),
					'context'     => [ 'view', 'edit' ],
				],
				'payment_email' => [
					'description' => __( 'Where payouts for this affiliate are sent. Empty means the account email.', 'flyaffiliate' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => [ 'view', 'edit' ],
					'arg_options' => [
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => [ $this, 'validate_payment_email' ],
					],
				],
				'promo_method'  => [
					'description' => __( 'How the affiliate plans to promote the store.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
				],
				'website'       => [
					'description' => __( 'The affiliate\'s website. Kept on their WordPress user.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'arg_options' => [
						'sanitize_callback' => 'esc_url_raw',
					],
				],
				'send_welcome_email' => [
					'description' => __( 'When creating: email the affiliate a welcome with their referral link.', 'flyaffiliate' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => [],
				],
				'referral_url'  => [
					'description' => __( 'The affiliate\'s referral link.', 'flyaffiliate' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'totals'        => [
					'description' => __( 'Commission totals by status: amounts and counts.', 'flyaffiliate' ),
					'type'        => 'object',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'visits'        => [
					'description' => __( 'Visit counts by result. Only with context=edit.', 'flyaffiliate' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
				'created_at'    => [
					'description' => __( 'When the affiliate was created, in GMT.', 'flyaffiliate' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'updated_at'    => [
					'description' => __( 'When the affiliate was last changed, in GMT.', 'flyaffiliate' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
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

		$params['status'] = [
			'description' => __( 'Limit the result set to affiliates with a given status.', 'flyaffiliate' ),
			'type'        => 'string',
			'enum'        => array_keys( Affiliate::get_statuses() ),
		];

		$params['user_id'] = [
			'description' => __( 'Limit the result set to the affiliate belonging to a WordPress user.', 'flyaffiliate' ),
			'type'        => 'integer',
		];

		$params['search'] = [
			'description' => __( 'Match the affiliate\'s name, login, email or payment email.', 'flyaffiliate' ),
			'type'        => 'string',
		];

		$params['orderby'] = [
			'description' => __( 'Sort the result set by this field.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'id',
			'enum'        => [ 'id', 'user_id', 'status', 'created_at', 'updated_at' ],
		];

		$params['order'] = [
			'description' => __( 'Sort direction.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => [ 'asc', 'desc' ],
		];

		return $params;
	}

	/**
	 * The 404 this controller returns for a missing affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return WP_Error
	 */
	protected function not_found(): WP_Error {
		return new WP_Error(
			'flyaffiliate_rest_affiliate_not_found',
			__( 'No affiliate with that ID.', 'flyaffiliate' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Format a stored datetime as RFC3339 for the response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string|null $value Stored `Y-m-d H:i:s` value, in GMT.
	 *
	 * @return string|null
	 */
	protected function prepare_date( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		return mysql_to_rfc3339( (string) $value );
	}
}
