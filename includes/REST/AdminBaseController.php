<?php
/**
 * Base controller for admin-only routes.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;

/**
 * Every route on a subclass requires `flyaffiliate_admin_capability()` — `manage_options` unless filtered (ADR-0008, ADR-0013).
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class AdminBaseController extends BaseController {

	/**
	 * Whether the caller may administer FlyAffiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_permission( WP_REST_Request $request ) {
		if ( current_user_can( flyaffiliate_admin_capability() ) ) {
			return true;
		}

		return new WP_Error(
			'flyaffiliate_rest_forbidden',
			__( 'You are not allowed to manage affiliates.', 'flyaffiliate' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Permission callback for reading a collection.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->check_admin_permission( $request );
	}

	/**
	 * Permission callback for reading one item.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->check_admin_permission( $request );
	}

	/**
	 * Permission callback for creating an item.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->check_admin_permission( $request );
	}

	/**
	 * Permission callback for updating an item.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->check_admin_permission( $request );
	}

	/**
	 * Permission callback for deleting an item.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->check_admin_permission( $request );
	}
}
