<?php
/**
 * Base controller for self-scoped affiliate routes.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use WP_Error;
use WP_REST_Request;

/**
 * Every route on a subclass is bound to the calling user's own affiliate record.
 *
 * The caller must be logged in and must be an active affiliate. Subclasses read
 * the affiliate id from {@see self::get_current_affiliate()} and never from the
 * request, so there is no parameter to tamper with — an affiliate cannot ask for
 * another affiliate's rows because there is nowhere to ask.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class AffiliateBaseController extends BaseController {

	/**
	 * The calling user's affiliate record, or null.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return Affiliate|null
	 */
	protected function get_current_affiliate(): ?Affiliate {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return null;
		}

		return flyaffiliate()->affiliate->get_by_user( $user_id );
	}

	/**
	 * Whether the caller is an active affiliate acting on their own data.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function check_affiliate_permission( WP_REST_Request $request ) {
		if ( 0 === get_current_user_id() ) {
			return new WP_Error(
				'flyaffiliate_rest_not_logged_in',
				__( 'You must be logged in to do that.', 'flyaffiliate' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$affiliate = $this->get_current_affiliate();

		if ( null === $affiliate || ! $affiliate->is_active() ) {
			return new WP_Error(
				'flyaffiliate_rest_not_an_affiliate',
				__( 'Your account is not an active affiliate.', 'flyaffiliate' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
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
		return $this->check_affiliate_permission( $request );
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
		return $this->check_affiliate_permission( $request );
	}
}
