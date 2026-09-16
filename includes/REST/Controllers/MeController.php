<?php
/**
 * The logged-in affiliate's own data.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Integrations\WooCommerce\OrderAttribution;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\REST\AffiliateBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `me`: what the affiliate dashboard reads and the one thing it writes.
 *
 * Every route is bound to the caller's own affiliate record, so there is no
 * id in the URL and nothing to tamper with. The rows are shaped by the admin
 * controllers' `prepare_item_for_response()`, so the dashboard and the admin
 * app share one set of types.
 *
 * @since FLYAFFILIATE_SINCE
 */
class MeController extends AffiliateBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'me';

	/**
	 * {@inheritDoc}
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
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => $this->get_date_params(),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'payment_email' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						],
						'promo_method'  => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		foreach ( [ 'commissions', 'visits', 'payouts' ] as $resource ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $resource,
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_' . $resource ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
						'args'                => $this->get_collection_params(),
					],
				]
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/commissions/(?P<id>[\\d]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_commission' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'id' => [
							'description' => __( 'The commission.', 'flyaffiliate' ),
							'type'        => 'integer',
							'required'    => true,
						],
					],
				],
			]
		);
	}

	/**
	 * The affiliate, with their totals.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$request['context'] = 'edit';

		$affiliate = $this->get_current_affiliate();
		$response  = ( new AffiliatesController() )->prepare_item_for_response( $affiliate, $request );

		list( $after, $before ) = $this->get_date_bounds( $request );

		// The figures follow the dashboard's date range; the profile itself does not.
		if ( '' !== $after || '' !== $before ) {
			$data           = $response->get_data();
			$data['totals'] = flyaffiliate()->commission->get_affiliate_totals( $affiliate->get_id(), $after, $before );
			$data['visits'] = flyaffiliate()->tracking->count_by_result(
				[
					'where'  => [ 'affiliate_id' => $affiliate->get_id() ],
					'after'  => $after,
					'before' => $before,
				]
			);

			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * One of the affiliate's commissions, with the item it paid on and the visit it came from.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_commission( $request ) {
		$affiliate  = $this->get_current_affiliate();
		$commission = flyaffiliate()->commission->get( (int) $request['id'] );

		// Someone else's commission is reported as missing, not as forbidden.
		if ( null === $commission || (int) $commission->get( 'affiliate_id' ) !== $affiliate->get_id() ) {
			return new WP_Error( 'flyaffiliate_rest_commission_not_found', __( 'No commission with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		$response = ( new CommissionsController() )->prepare_item_for_response( $commission, $request );
		$data     = $response->get_data();

		$data['product'] = $this->get_product_summary( $commission );
		$data['visit']   = $this->get_visit_summary( $commission, $affiliate->get_id() );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * The product a commission paid on: its name, and a link while it is published.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return array{id: int, name: string, url: string}|null Null for a commission with no product.
	 */
	protected function get_product_summary( Commission $commission ): ?array {
		$product_id = (int) $commission->get( 'product_id', 0 );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name       = $product ? $product->get_name() : '';

		// A deleted product still has its name on the order line.
		if ( '' === $name ) {
			$order = $commission->get_order();
			$item  = $order ? $order->get_item( (int) $commission->get( 'order_item_id', 0 ) ) : null;
			$name  = $item ? $item->get_name() : '';
		}

		if ( '' === $name ) {
			return null;
		}

		return [
			'id'   => $product_id,
			'name' => $name,
			'url'  => $product && 'publish' === $product->get_status() ? (string) get_permalink( $product->get_id() ) : '',
		];
	}

	/**
	 * The visit an order was attributed through.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission   The commission.
	 * @param int        $affiliate_id The affiliate the visit must belong to.
	 *
	 * @return array{id: int, url: string, referrer: string, created_at: string|null}|null
	 */
	protected function get_visit_summary( Commission $commission, int $affiliate_id ): ?array {
		$order    = $commission->get_order();
		$visit_id = $order ? (int) $order->get_meta( OrderAttribution::META_VISIT ) : 0;
		$visit    = $visit_id > 0 ? flyaffiliate()->tracking->get( $visit_id ) : null;

		if ( null === $visit || (int) $visit->get( 'affiliate_id' ) !== $affiliate_id ) {
			return null;
		}

		return [
			'id'         => $visit->get_id(),
			'url'        => (string) $visit->get( 'url', '' ),
			'referrer'   => (string) $visit->get( 'referrer', '' ),
			'created_at' => empty( $visit->get( 'created_at' ) ) ? null : mysql_to_rfc3339( (string) $visit->get( 'created_at' ) ),
		];
	}

	/**
	 * Change the payment email or the promotion note.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$affiliate = $this->get_current_affiliate();
		$fields    = [];

		foreach ( [ 'payment_email', 'promo_method' ] as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$fields[ $field ] = (string) $request[ $field ];
			}
		}

		if ( [] !== $fields ) {
			$result = flyaffiliate()->affiliate->update( $affiliate->get_id(), $fields );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->get_item( $request );
	}

	/**
	 * The affiliate's commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_commissions( $request ) {
		$where = [ 'affiliate_id' => $this->get_current_affiliate()->get_id() ];

		if ( ! empty( $request['status'] ) ) {
			$where['status'] = sanitize_key( (string) $request['status'] );
		}

		// The commissions inside one of the affiliate's own payments; the
		// affiliate scope above stays, so another affiliate's payment is empty.
		if ( ! empty( $request['payout_id'] ) ) {
			$where['payout_id'] = absint( $request['payout_id'] );
		}

		$args = $this->list_args( $request, $where, [ 'id', 'amount', 'status', 'created_at', 'matures_at' ] );

		return $this->list_response( new CommissionsController(), flyaffiliate()->commission, $args, $request );
	}

	/**
	 * The affiliate's visits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_visits( $request ) {
		$where = [ 'affiliate_id' => $this->get_current_affiliate()->get_id() ];

		if ( isset( $request['converted'] ) && '' !== $request['converted'] ) {
			$where['converted'] = rest_sanitize_boolean( $request['converted'] ) ? 1 : 0;
		}

		$args = $this->list_args( $request, $where, [ 'id', 'created_at', 'converted' ] );

		return $this->list_response( new VisitsController(), flyaffiliate()->tracking, $args, $request );
	}

	/**
	 * The affiliate's payouts.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_payouts( $request ) {
		$where = [ 'affiliate_id' => $this->get_current_affiliate()->get_id() ];
		$args  = $this->list_args( $request, $where, [ 'id', 'amount', 'created_at' ] );

		return $this->list_response( new PayoutsController(), flyaffiliate()->payout, $args, $request );
	}

	/**
	 * Query arguments from the paging and sorting parameters.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param array           $where    Conditions, always scoped to the affiliate.
	 * @param string[]        $sortable Columns the caller may sort by.
	 *
	 * @return array
	 */
	protected function list_args( WP_REST_Request $request, array $where, array $sortable ): array {
		$orderby = (string) ( $request['orderby'] ?? 'created_at' );

		list( $after, $before ) = $this->get_date_bounds( $request );

		return [
			'where'    => $where,
			'after'    => $after,
			'before'   => $before,
			'orderby'  => in_array( $orderby, $sortable, true ) ? $orderby : 'created_at',
			'order'    => 'asc' === strtolower( (string) ( $request['order'] ?? 'desc' ) ) ? 'asc' : 'desc',
			'per_page' => (int) $request['per_page'],
			'page'     => (int) $request['page'],
		];
	}

	/**
	 * Run a list query and shape the rows the way the admin controller does.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param \WP_REST_Controller $controller The admin controller for the resource.
	 * @param object              $manager    The resource manager: has `query()` and `count()`.
	 * @param array               $args       Query arguments.
	 * @param WP_REST_Request     $request    The request.
	 *
	 * @return WP_REST_Response
	 */
	protected function list_response( $controller, $manager, array $args, WP_REST_Request $request ): WP_REST_Response {
		$items = [];

		foreach ( $manager->query( $args ) as $row ) {
			$items[] = $this->prepare_response_for_collection( $controller->prepare_item_for_response( $row, $request ) );
		}

		return $this->prepare_collection_response( $items, $manager->count( $args ), (int) $args['per_page'] );
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

		$params['status']    = [
			'description' => __( 'Limit commissions to one status.', 'flyaffiliate' ),
			'type'        => 'string',
		];
		$params['converted'] = [
			'description' => __( 'Limit visits to converted (true) or not (false).', 'flyaffiliate' ),
			'type'        => 'string',
		];
		$params['payout_id'] = [
			'description' => __( 'Limit commissions to the ones inside one of your payouts.', 'flyaffiliate' ),
			'type'        => 'integer',
		];
		$params['orderby']   = [
			'description' => __( 'Sort the result set by this field.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'created_at',
		];
		$params['order']     = [
			'description' => __( 'Sort direction.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => [ 'asc', 'desc' ],
		];

		return array_merge( $params, $this->get_date_params() );
	}



	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return ( new AffiliatesController() )->get_item_schema();
	}
}
