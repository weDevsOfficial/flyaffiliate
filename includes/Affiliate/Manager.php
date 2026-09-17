<?php
/**
 * Affiliate data access.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Affiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use WP_Error;

/**
 * The one way to create, read, change and remove affiliates.
 *
 * Screens, REST controllers and the registration flow all go through here, so
 * the rules that make an affiliate valid — one WordPress user is one affiliate
 * sitewide, the user has to exist, the status has to be a real status — are
 * written once and cannot be bypassed by whichever caller is next.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * Get an affiliate by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $affiliate_id Affiliate id.
	 *
	 * @return Affiliate|null
	 */
	public function get( int $affiliate_id ): ?Affiliate {
		return Affiliate::find( $affiliate_id );
	}

	/**
	 * Get the affiliate belonging to a WordPress user.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id WordPress user id.
	 *
	 * @return Affiliate|null
	 */
	public function get_by_user( int $user_id ): ?Affiliate {
		if ( $user_id <= 0 ) {
			return null;
		}

		return Affiliate::find_by( [ 'user_id' => $user_id ] );
	}

	/**
	 * Get an affiliate by its activation key.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $activation_key The key sent in the activation link.
	 *
	 * @return Affiliate|null
	 */
	public function get_by_activation_key( string $activation_key ): ?Affiliate {
		if ( '' === $activation_key ) {
			return null;
		}

		return Affiliate::find_by( [ 'activation_key' => $activation_key ] );
	}

	/**
	 * Whether a user is already an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $user_id WordPress user id.
	 *
	 * @return bool
	 */
	public function is_affiliate( int $user_id ): bool {
		return null !== $this->get_by_user( $user_id );
	}

	/**
	 * Create an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Affiliate data.
	 *
	 *     @type int    $user_id        Required. The WordPress user this affiliate is.
	 *     @type string $status         Default `pending`.
	 *     @type string $payment_email  Default the user's own email.
	 *     @type string $promo_method   How the affiliate plans to promote the store.
	 *     @type string $website        The affiliate's website; stored on the user, as SliceWP does.
	 *     @type bool   $send_welcome_email Email the new affiliate a welcome with their referral link. Default false.
	 *     @type string $activation_key Set by the registration flow.
	 * }
	 *
	 * @return Affiliate|WP_Error
	 */
	public function create( array $args ) {
		$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;

		if ( 0 === $user_id || false === get_user_by( 'id', $user_id ) ) {
			return new WP_Error(
				'flyaffiliate_invalid_user',
				__( 'An affiliate needs an existing WordPress user.', 'flyaffiliate' ),
				[ 'status' => 400 ]
			);
		}

		if ( $this->is_affiliate( $user_id ) ) {
			return new WP_Error(
				'flyaffiliate_affiliate_exists',
				__( 'That user is already an affiliate.', 'flyaffiliate' ),
				[ 'status' => 409 ]
			);
		}

		$affiliate = new Affiliate();

		$affiliate->fill(
			[
				'user_id'        => $user_id,
				'status'         => $this->sanitize_status( $args['status'] ?? Affiliate::STATUS_PENDING ),
				'payment_email'  => $this->sanitize_email( $args['payment_email'] ?? '', $user_id ),
				'promo_method'   => sanitize_textarea_field( (string) ( $args['promo_method'] ?? '' ) ),
				'activation_key' => sanitize_text_field( (string) ( $args['activation_key'] ?? '' ) ),
			]
		);

		if ( 0 === $affiliate->save() ) {
			return new WP_Error(
				'flyaffiliate_create_failed',
				__( 'The affiliate could not be saved.', 'flyaffiliate' ),
				[ 'status' => 500 ]
			);
		}

		if ( isset( $args['website'] ) ) {
			$this->set_website( $user_id, (string) $args['website'] );
		}

		/**
		 * Fires after an affiliate is created.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Affiliate $affiliate The affiliate.
		 */
		do_action( 'flyaffiliate_affiliate_created', $affiliate );

		if ( ! empty( $args['send_welcome_email'] ) ) {
			flyaffiliate()->registration->send_welcome_email( $affiliate );
		}

		return $affiliate;
	}

	/**
	 * Update an affiliate.
	 *
	 * `user_id` is not updatable: moving an affiliate to another user would move
	 * their commissions with it and break the one-user-one-affiliate rule.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $affiliate_id Affiliate id.
	 * @param array $args         Fields to change.
	 *
	 * @return Affiliate|WP_Error
	 */
	public function update( int $affiliate_id, array $args ) {
		$affiliate = $this->get( $affiliate_id );

		if ( null === $affiliate ) {
			return new WP_Error(
				'flyaffiliate_affiliate_not_found',
				__( 'No affiliate with that ID.', 'flyaffiliate' ),
				[ 'status' => 404 ]
			);
		}

		$previous_status = (string) $affiliate->get( 'status' );

		if ( isset( $args['status'] ) ) {
			$affiliate->set( 'status', $this->sanitize_status( $args['status'] ) );
		}

		if ( isset( $args['payment_email'] ) ) {
			$affiliate->set( 'payment_email', $this->sanitize_email( $args['payment_email'], (int) $affiliate->get( 'user_id' ) ) );
		}

		if ( isset( $args['promo_method'] ) ) {
			$affiliate->set( 'promo_method', sanitize_textarea_field( (string) $args['promo_method'] ) );
		}

		if ( array_key_exists( 'activation_key', $args ) ) {
			$affiliate->set( 'activation_key', sanitize_text_field( (string) $args['activation_key'] ) );
		}

		if ( isset( $args['website'] ) ) {
			$this->set_website( (int) $affiliate->get( 'user_id' ), (string) $args['website'] );
		}

		if ( 0 === $affiliate->save() ) {
			return new WP_Error(
				'flyaffiliate_update_failed',
				__( 'The affiliate could not be saved.', 'flyaffiliate' ),
				[ 'status' => 500 ]
			);
		}

		$new_status = (string) $affiliate->get( 'status' );

		if ( $previous_status !== $new_status ) {
			/**
			 * Fires when an affiliate's status changes.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param Affiliate $affiliate       The affiliate.
			 * @param string    $new_status      The status it is now in.
			 * @param string    $previous_status The status it was in.
			 */
			do_action( 'flyaffiliate_affiliate_status_changed', $affiliate, $new_status, $previous_status );
		}

		/**
		 * Fires after an affiliate is updated.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Affiliate $affiliate The affiliate.
		 */
		do_action( 'flyaffiliate_affiliate_updated', $affiliate );

		return $affiliate;
	}

	/**
	 * Store the affiliate's website on their user, where SliceWP keeps it too.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $user_id The user.
	 * @param string $website The URL; empty clears it.
	 *
	 * @return void
	 */
	protected function set_website( int $user_id, string $website ): void {
		wp_update_user(
			[
				'ID'       => $user_id,
				'user_url' => esc_url_raw( trim( $website ) ),
			]
		);
	}

	/**
	 * Delete an affiliate row.
	 *
	 * The commissions, visits and payouts that reference it are left in place:
	 * they are financial records, and deleting the affiliate does not un-earn
	 * the money.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $affiliate_id Affiliate id.
	 *
	 * @return bool
	 */
	public function delete( int $affiliate_id ): bool {
		$affiliate = $this->get( $affiliate_id );

		if ( null === $affiliate ) {
			return false;
		}

		// The model empties itself on delete; listeners get what it was.
		$snapshot = clone $affiliate;
		$deleted  = $affiliate->delete();

		if ( $deleted ) {
			/**
			 * Fires after an affiliate is deleted.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param int       $affiliate_id The id of the deleted affiliate.
			 * @param Affiliate $affiliate    The affiliate as it was before deletion.
			 */
			do_action( 'flyaffiliate_affiliate_deleted', $affiliate_id, $snapshot );
		}

		return $deleted;
	}

	/**
	 * Query affiliates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::query()}.
	 *
	 * @return Affiliate[]
	 */
	public function query( array $args = [] ): array {
		return Affiliate::query( $args );
	}

	/**
	 * Count affiliates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $conditions Column => value, or the full query arguments.
	 *
	 * @return int
	 */
	public function count( array $conditions = [] ): int {
		return Affiliate::count( $conditions );
	}

	/**
	 * The `search` query argument for a free-text term.
	 *
	 * An affiliate's name and login live on the WordPress user, so the term
	 * is matched there first and the users found are OR-ed with a match on
	 * the payment email column.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $term What was typed.
	 *
	 * @return array Empty for an empty term; otherwise a `search` argument for {@see query()}.
	 */
	public function get_search_args( string $term ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			return [];
		}

		$user_ids = get_users(
			[
				'search'         => '*' . $term . '*',
				'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
				'fields'         => 'ID',
				'number'         => 500,
			]
		);

		return [
			'term'    => $term,
			'columns' => [ 'payment_email' ],
			'in'      => [ 'user_id' => array_map( 'intval', $user_ids ) ],
		];
	}

	/**
	 * Reduce a value to a known affiliate status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $status Candidate status.
	 *
	 * @return string
	 */
	protected function sanitize_status( $status ): string {
		$status = sanitize_key( (string) $status );

		return array_key_exists( $status, Affiliate::get_statuses() ) ? $status : Affiliate::STATUS_PENDING;
	}

	/**
	 * Reduce a value to a valid payment email, falling back to the user's own.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $email   Candidate email.
	 * @param int   $user_id The affiliate's user id, for the fallback.
	 *
	 * @return string
	 */
	protected function sanitize_email( $email, int $user_id ): string {
		$email = sanitize_email( (string) $email );

		if ( '' !== $email && is_email( $email ) ) {
			return $email;
		}

		$user = get_user_by( 'id', $user_id );

		return $user instanceof \WP_User ? $user->user_email : '';
	}
}
