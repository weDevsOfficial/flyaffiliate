<?php
/**
 * REST: the affiliates who sent customers to a vendor's store.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Integrations\Dokan\VendorDashboard;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\REST\BaseController;
use FlyAffiliate\Utilities\Money;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * One row per affiliate, with what they earned on the vendor's products.
 *
 * Self-scoped: the vendor is the current user (or the vendor a staff member
 * works for), never a parameter. Rejected commissions are left out; they are
 * money nobody earned.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VendorAffiliatesController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'vendor/affiliates';

	/**
	 * What the list can be sorted by: columns of the grouped query.
	 *
	 * @var string[]
	 */
	const ORDER_BY = [ 'earned', 'sales', 'orders', 'paid', 'last_sale' ];

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_routes() {
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
	}

	/**
	 * Only a vendor, or their staff, and only while vendor programs are on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'flyaffiliate_rest_not_logged_in', __( 'Log in to see your affiliates.', 'flyaffiliate' ), [ 'status' => 401 ] );
		}

		if ( ! Settings::is_enabled() || ! current_user_can( 'dokandar' ) || ! current_user_can( VendorDashboard::CAPABILITY ) ) {
			return new WP_Error( 'flyaffiliate_rest_forbidden', __( 'You cannot see this store’s affiliates.', 'flyaffiliate' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * The affiliates, highest earner first.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$vendor_id = absint( dokan_get_current_user_id() );
		$per_page  = max( 1, min( 100, absint( $request['per_page'] ?? 10 ) ) );
		$page      = max( 1, absint( $request['page'] ?? 1 ) );
		$order_by  = in_array( (string) $request['orderby'], self::ORDER_BY, true ) ? (string) $request['orderby'] : 'earned';
		$order     = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
		$table     = Commission::get_table();
		$matching  = $this->get_matching_affiliate_ids( (string) $request['search'] );

		// A search that matches nobody is an empty page, not the whole list.
		if ( null !== $matching && [] === $matching ) {
			return $this->prepare_collection_response( [], 0, $per_page );
		}

		$only        = null === $matching ? '' : ' AND affiliate_id IN ( ' . implode( ', ', array_fill( 0, count( $matching ), '%d' ) ) . ' )';
		$only_values = null === $matching ? [] : $matching;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FlyAffiliate's own table; its name, the sort column and the direction come from fixed lists above, every value is a placeholder, and the search adds one %d per affiliate id with the ids passed alongside.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT affiliate_id ) FROM {$table} WHERE vendor_id = %d AND status <> %s{$only}",
				array_merge( [ $vendor_id, Commission::STATUS_REJECTED ], $only_values )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id,
					COUNT( DISTINCT NULLIF( order_id, 0 ) ) AS orders,
					COUNT( * ) AS commissions,
					SUM( base_amount ) AS sales,
					SUM( amount ) AS earned,
					SUM( CASE WHEN status = %s THEN amount ELSE 0 END ) AS pending,
					SUM( CASE WHEN status = %s THEN amount ELSE 0 END ) AS unpaid,
					SUM( CASE WHEN status = %s THEN amount ELSE 0 END ) AS paid,
					MAX( created_at ) AS last_sale
				FROM {$table}
				WHERE vendor_id = %d AND status <> %s{$only}
				GROUP BY affiliate_id
				ORDER BY {$order_by} {$order}, affiliate_id ASC
				LIMIT %d OFFSET %d",
				array_merge(
					[
						Commission::STATUS_PENDING,
						Commission::STATUS_UNPAID,
						Commission::STATUS_PAID,
						$vendor_id,
						Commission::STATUS_REJECTED,
					],
					$only_values,
					[ $per_page, ( $page - 1 ) * $per_page ]
				)
			)
		);
		// phpcs:enable

		$rows  = (array) $rows;
		$items = [];

		// The page's affiliates and their users in two queries, not a few per row.
		$affiliates = [];

		if ( [] !== $rows ) {
			foreach ( flyaffiliate()->affiliate->query(
				[
					'where'    => [ 'id' => array_map( 'intval', wp_list_pluck( $rows, 'affiliate_id' ) ) ],
					'per_page' => -1,
				]
			) as $affiliate ) {
				$affiliates[ $affiliate->get_id() ] = $affiliate;
			}

			cache_users( array_map( static fn( $affiliate ): int => (int) $affiliate->get( 'user_id' ), $affiliates ) );
		}

		foreach ( $rows as $row ) {
			$row->affiliate = $affiliates[ (int) $row->affiliate_id ] ?? null;
			$items[]        = $this->prepare_response_for_collection( $this->prepare_item_for_response( $row, $request ) );
		}

		return $this->prepare_collection_response( $items, $total, $per_page );
	}

	/**
	 * The affiliates whose name matches a search.
	 *
	 * Only the name: a vendor does not search by the email an affiliate is paid at.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $term What was typed.
	 *
	 * @return int[]|null Null without a search, so every affiliate is listed.
	 */
	protected function get_matching_affiliate_ids( string $term ): ?array {
		if ( '' === trim( $term ) ) {
			return null;
		}

		return flyaffiliate()->tracking->find_affiliate_ids_by_user_search( $term, [ 'user_login', 'user_nicename', 'display_name' ] );
	}

	/**
	 * One affiliate's row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param object          $item    The grouped row.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$affiliate_id = absint( $item->affiliate_id );
		$affiliate    = $item->affiliate ?? flyaffiliate()->affiliate->get( $affiliate_id );
		$money        = static fn( $amount ): float => Money::round( $amount );

		$data = [
			'id'          => $affiliate_id,
			'name'        => $affiliate ? $affiliate->get_display_name() : '',
			'avatar_url'  => $affiliate ? (string) get_avatar_url( (int) $affiliate->get( 'user_id' ), [ 'size' => 64 ] ) : '',
			'orders'      => absint( $item->orders ),
			'commissions' => absint( $item->commissions ),
			'sales'       => $money( $item->sales ),
			'earned'      => $money( $item->earned ),
			'pending'     => $money( $item->pending ),
			'unpaid'      => $money( $item->unpaid ),
			'paid'        => $money( $item->paid ),
			'last_sale'   => $this->prepare_date( $item->last_sale ),
		];

		return rest_ensure_response( $this->filter_response_fields( $data, $request ) );
	}

	/**
	 * The links of a row: none, a vendor cannot open an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param object $item The grouped row.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare_links( $item ): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		return [
			'page'     => [
				'description'       => __( 'The page of the list.', 'flyaffiliate' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'description'       => __( 'How many affiliates a page holds.', 'flyaffiliate' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
			'search'   => [
				'description'       => __( 'Only affiliates whose name contains this.', 'flyaffiliate' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby'  => [
				'description' => __( 'What to sort by.', 'flyaffiliate' ),
				'type'        => 'string',
				'default'     => 'earned',
				'enum'        => self::ORDER_BY,
			],
			'order'    => [
				'description' => __( 'Sort direction.', 'flyaffiliate' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => [ 'asc', 'desc' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$money = static fn( string $description ): array => [
			'description' => $description,
			'type'        => 'number',
			'context'     => [ 'view' ],
			'readonly'    => true,
		];

		return $this->add_additional_fields_schema(
			[
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'flyaffiliate_vendor_affiliate',
				'type'       => 'object',
				'properties' => [
					'id'          => [
						'description' => __( 'The affiliate.', 'flyaffiliate' ),
						'type'        => 'integer',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'name'        => [
						'description' => __( 'The affiliate’s name.', 'flyaffiliate' ),
						'type'        => 'string',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'avatar_url'  => [
						'description' => __( 'The affiliate’s picture.', 'flyaffiliate' ),
						'type'        => 'string',
						'format'      => 'uri',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'orders'      => [
						'description' => __( 'Orders the affiliate sent to the store.', 'flyaffiliate' ),
						'type'        => 'integer',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'commissions' => [
						'description' => __( 'Commissions on those orders.', 'flyaffiliate' ),
						'type'        => 'integer',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
					'sales'       => $money( __( 'What those orders were worth to the store.', 'flyaffiliate' ) ),
					'earned'      => $money( __( 'What the affiliate earned on them.', 'flyaffiliate' ) ),
					'pending'     => $money( __( 'The part still waiting for its order to be paid or its lock to end.', 'flyaffiliate' ) ),
					'unpaid'      => $money( __( 'The part ready to be paid.', 'flyaffiliate' ) ),
					'paid'        => $money( __( 'The part already paid.', 'flyaffiliate' ) ),
					'last_sale'   => [
						'description' => __( 'When the affiliate last sent an order, in GMT.', 'flyaffiliate' ),
						'type'        => [ 'string', 'null' ],
						'format'      => 'date-time',
						'context'     => [ 'view' ],
						'readonly'    => true,
					],
				],
			]
		);
	}
}
