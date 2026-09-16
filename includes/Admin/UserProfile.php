<?php
/**
 * The affiliate section on the user profile.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use WP_User;

/**
 * Lets an admin make any user an affiliate — or stop them being one — from
 * the Users screen, and set their status there.
 *
 * @since FLYAFFILIATE_SINCE
 */
class UserProfile implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
	}

	/**
	 * Render the section.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		flyaffiliate_get_template(
			'admin/user-profile.php',
			[
				'user'      => $user,
				'affiliate' => flyaffiliate()->affiliate->get_by_user( $user->ID ),
			]
		);
	}

	/**
	 * Save the section.
	 *
	 * WordPress has already verified the profile form's nonce by the time
	 * these actions fire; the capability is re-checked here because a user
	 * editing their own profile reaches `personal_options_update` too.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id The user being edited.
	 *
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified the profile form's nonce before firing this action.
		if ( ! isset( $_POST['flyaffiliate_profile_section'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$wanted = ! empty( $_POST['flyaffiliate_is_affiliate'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$status = isset( $_POST['flyaffiliate_status'] ) ? sanitize_key( wp_unslash( $_POST['flyaffiliate_status'] ) ) : Affiliate::STATUS_ACTIVE;

		$affiliate = flyaffiliate()->affiliate->get_by_user( $user_id );

		if ( $wanted && null === $affiliate ) {
			flyaffiliate()->affiliate->create(
				[
					'user_id' => $user_id,
					'status' => $status,
				]
			);
		} elseif ( $wanted && null !== $affiliate ) {
			flyaffiliate()->affiliate->update( $affiliate->get_id(), [ 'status' => $status ] );
		} elseif ( ! $wanted && null !== $affiliate ) {
			flyaffiliate()->affiliate->delete( $affiliate->get_id() );
		}
	}
}
