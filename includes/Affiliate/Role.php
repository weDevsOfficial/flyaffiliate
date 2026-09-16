<?php
/**
 * The affiliate user role.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Affiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use WP_User;

/**
 * Keeps the `flyaffiliate_affiliate` role on every user who is an affiliate.
 *
 * The role is a label, not a permission: it grants nothing beyond `read`, and
 * nothing in the plugin checks it — the affiliates table is the source of
 * truth, as in SliceWP (`slicewp_affiliate`). It exists so the Users screen
 * can filter affiliates, and so an admin can make a user an affiliate from
 * their profile.
 *
 * - Creating an affiliate adds the role to its user; deleting the affiliate
 *   removes it.
 * - Saving a user's profile keeps the two in step. WordPress replaces every
 *   role with the one picked in the profile's dropdown, so a user with an
 *   affiliate row gets the role back on every save. A user without a row who
 *   is given the role becomes an active affiliate — the one place this goes
 *   further than SliceWP, which silently strips the role again. Taking the
 *   role away on the profile does nothing: to stop someone being an affiliate,
 *   change their status on the Affiliates screen or delete the affiliate.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Role implements Hookable {

	/**
	 * The role name.
	 *
	 * @var string
	 */
	const ROLE = 'flyaffiliate_affiliate';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'ensure_registered' ], 5 );
		add_action( 'flyaffiliate_affiliate_created', [ $this, 'add_to_affiliate' ] );
		add_action( 'flyaffiliate_affiliate_deleted', [ $this, 'remove_from_deleted' ], 10, 2 );
		add_action( 'user_register', [ $this, 'sync_user' ] );
		add_action( 'profile_update', [ $this, 'sync_user' ] );
	}

	/**
	 * Register the role. Safe to call again: `add_role()` leaves an existing role alone.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public static function register(): void {
		add_role( self::ROLE, __( 'Affiliate', 'flyaffiliate' ), [ 'read' => true ] );
	}

	/**
	 * Register the role if an install or upgrade has not done so yet.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function ensure_registered(): void {
		if ( ! wp_roles()->is_role( self::ROLE ) ) {
			self::register();
		}
	}

	/**
	 * Give a new affiliate's user the role.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return void
	 */
	public function add_to_affiliate( Affiliate $affiliate ): void {
		$this->add( (int) $affiliate->get( 'user_id', 0 ) );
	}

	/**
	 * Take the role from a deleted affiliate's user.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int            $affiliate_id The deleted affiliate's id.
	 * @param Affiliate|null $affiliate    The affiliate as it was before deletion.
	 *
	 * @return void
	 */
	public function remove_from_deleted( $affiliate_id, $affiliate = null ): void {
		if ( $affiliate instanceof Affiliate ) {
			$this->remove( (int) $affiliate->get( 'user_id', 0 ) );
		}
	}

	/**
	 * Keep a user's role and affiliate row in step after their profile is saved or created.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user.
	 *
	 * @return void
	 */
	public function sync_user( $user_id ): void {
		$user = get_user_by( 'id', absint( $user_id ) );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$has_role = in_array( self::ROLE, (array) $user->roles, true );

		if ( flyaffiliate()->affiliate->is_affiliate( $user->ID ) ) {
			if ( ! $has_role ) {
				$user->add_role( self::ROLE );
			}

			return;
		}

		if ( $has_role ) {
			// Picked on the profile: the admin means this user to be an affiliate.
			flyaffiliate()->affiliate->create(
				[
					'user_id' => $user->ID,
					'status'  => Affiliate::STATUS_ACTIVE,
				]
			);
		}
	}

	/**
	 * Add the role to a user.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user.
	 *
	 * @return void
	 */
	public function add( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof WP_User && ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}
	}

	/**
	 * Remove the role from a user.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user.
	 *
	 * @return void
	 */
	public function remove( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( self::ROLE );
		}
	}
}
