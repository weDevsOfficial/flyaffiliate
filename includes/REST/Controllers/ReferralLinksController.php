<?php
/**
 * Referral links REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\ReferralLink;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/referral-links` — read-only; the affiliate saves and
 * removes their own through `me/referral-links`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ReferralLinksController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'referral-links';

	/**
	 * Visit figures for the links being prepared, keyed by link id.
	 *
	 * @var array<int, array{visits: int, conversions: int, last_visit_at: string|null}>
	 */
	protected array $stats = [];

	/**
	 * The affiliates of the links being prepared, keyed by id; null when deleted.
	 *
	 * @var array<int, Affiliate|null>
	 */
	protected array $affiliates = [];

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
						'description' => __( 'Unique identifier for the referral link.', 'flyaffiliate' ),
						'type'        => 'integer',
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
	 * List referral links.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$where = [];

		if ( ! empty( $request['affiliate_id'] ) ) {
			$where['affiliate_id'] = (int) $request['affiliate_id'];
		}

		$args = [
			'where'    => $where,
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
			'per_page' => (int) $request['per_page'],
			'page'     => (int) $request['page'],
		];

		return $this->prepare_collection(
			flyaffiliate()->referral_link->query( $args ),
			flyaffiliate()->referral_link->count( $args ),
			(int) $args['per_page'],
			$request
		);
	}

	/**
	 * Get one referral link.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$link = flyaffiliate()->referral_link->get( (int) $request['id'] );

		return null === $link
			? new WP_Error( 'flyaffiliate_rest_referral_link_not_found', __( 'No referral link with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] )
			: $this->prepare_item_for_response( $link, $request );
	}

	/**
	 * A page of links as a collection response, with their visit figures
	 * read in one query rather than one per row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReferralLink[]  $links    The links on this page.
	 * @param int             $total    Every matching link.
	 * @param int             $per_page Rows per page.
	 * @param WP_REST_Request $request  The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_collection( array $links, int $total, int $per_page, WP_REST_Request $request ): WP_REST_Response {
		$this->stats = flyaffiliate()->referral_link->get_stats( $links );
		$items       = [];

		foreach ( $links as $link ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $link, $request ) );
		}

		return $this->prepare_collection_response( $items, $total, $per_page );
	}

	/**
	 * Turn a referral link into a response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReferralLink    $item    The link.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		if ( ! isset( $this->stats[ $item->get_id() ] ) ) {
			$this->stats += flyaffiliate()->referral_link->get_stats( [ $item ] );
		}

		$affiliate_id = (int) $item->get( 'affiliate_id' );

		if ( ! array_key_exists( $affiliate_id, $this->affiliates ) ) {
			$this->affiliates[ $affiliate_id ] = Affiliate::find( $affiliate_id );
		}

		$affiliate = $this->affiliates[ $affiliate_id ];
		$url       = (string) $item->get( 'url', '' );
		$stats     = $this->stats[ $item->get_id() ];

		$data = [
			'id'             => $item->get_id(),
			'affiliate_id'   => $affiliate_id,
			'affiliate_name' => $this->get_affiliate_name( $affiliate_id ),
			'url'            => $url,
			// Built on the way out, so a changed referral variable reaches every saved link.
			'referral_url'   => null === $affiliate ? '' : $affiliate->get_referral_url( $url ),
			'visits'         => $stats['visits'],
			'conversions'    => $stats['conversions'],
			'last_visit_at'  => null === $stats['last_visit_at'] ? null : mysql_to_rfc3339( $stats['last_visit_at'] ),
			'created_at'     => empty( $item->get( 'created_at' ) ) ? null : mysql_to_rfc3339( (string) $item->get( 'created_at' ) ),
		];

		$response = rest_ensure_response( $this->filter_response_fields( $data, $request ) );

		return $this->add_links( $response, $this->prepare_links( $item ) );
	}

	/**
	 * The links for one referral link.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReferralLink $item The link.
	 *
	 * @return array
	 */
	protected function prepare_links( $item ): array {
		return [
			'self'       => [ 'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ) ],
			'collection' => [ 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ],
			'affiliate'  => [
				'href'       => rest_url( sprintf( '%s/affiliates/%d', $this->namespace, (int) $item->get( 'affiliate_id' ) ) ),
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
			'title'      => 'flyaffiliate_referral_link',
			'type'       => 'object',
			'properties' => [
				'id'             => [
					'description' => __( 'Unique identifier for the referral link.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'affiliate_id'   => [
					'description' => __( 'The affiliate the link belongs to.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'affiliate_name' => [
					'description' => __( 'The affiliate’s display name.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'url'            => [
					'description' => __( 'The page the link leads to.', 'flyaffiliate' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'required'    => true,
				],
				'referral_url'   => [
					'description' => __( 'The page with the affiliate’s referral variable: the link to share.', 'flyaffiliate' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'visits'         => [
					'description' => __( 'Visits the affiliate brought to the page.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'conversions'    => [
					'description' => __( 'How many of those visits ended in an order.', 'flyaffiliate' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'last_visit_at'  => [
					'description' => __( 'The latest of those visits, in GMT.', 'flyaffiliate' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'created_at'     => [
					'description' => __( 'When the link was saved, in GMT.', 'flyaffiliate' ),
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

		$params['affiliate_id'] = [
			'description' => __( 'Limit to an affiliate.', 'flyaffiliate' ),
			'type'        => 'integer',
		];
		$params['orderby']      = [
			'description' => __( 'Sort field.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'created_at',
			'enum'        => [ 'id', 'created_at' ],
		];
		$params['order']        = [
			'description' => __( 'Sort direction.', 'flyaffiliate' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => [ 'asc', 'desc' ],
		];

		return $params;
	}
}
