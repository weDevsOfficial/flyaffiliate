<?php
/**
 * The affiliate model.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of `{prefix}flyaffiliate_affiliates`.
 *
 * One WordPress user is one affiliate, sitewide (CONTEXT.md). `user_id` is
 * unique, and the database enforces it rather than trusting the caller.
 *
 * @since 1.0.0
 */
class Affiliate extends BaseModel {

	/**
	 * Awaiting activation. Earns nothing and cannot be attributed.
	 *
	 * @var string
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Active. Referral links resolve and commissions accrue.
	 *
	 * @var string
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * Switched off by the affiliate or an admin.
	 *
	 * @var string
	 */
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Switched off by an admin for cause.
	 *
	 * @var string
	 */
	const STATUS_SUSPENDED = 'suspended';

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static string $table = 'flyaffiliate_affiliates';

	/**
	 * {@inheritDoc}
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [
		'id'                    => 'int',
		'user_id'               => 'int',
		'status'                => 'string',
		'payment_email'         => 'string',
		'payout_method'         => 'string',
		'promo_method'          => 'string',
		'activation_key'        => 'string',
		'activation_expires_at' => 'datetime',
		'created_at'            => 'datetime',
		'updated_at'            => 'datetime',
	];

	/**
	 * Every status an affiliate can be in.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Status => translated label.
	 */
	public static function get_statuses(): array {
		return [
			self::STATUS_PENDING   => __( 'Pending', 'flyaffiliate' ),
			self::STATUS_ACTIVE    => __( 'Active', 'flyaffiliate' ),
			self::STATUS_INACTIVE  => __( 'Inactive', 'flyaffiliate' ),
			self::STATUS_SUSPENDED => __( 'Suspended', 'flyaffiliate' ),
		];
	}

	/**
	 * Whether this affiliate can earn commissions and be attributed traffic.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->get( 'status' );
	}

	/**
	 * The affiliate's website, kept on their user's `user_url`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_website(): string {
		$user = $this->get_user();

		return null === $user ? '' : (string) $user->user_url;
	}

	/**
	 * The WordPress user behind this affiliate.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_User|null
	 */
	public function get_user(): ?\WP_User {
		$user = get_user_by( 'id', (int) $this->get( 'user_id', 0 ) );

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * A name to show for this affiliate.
	 *
	 * Falls back through display name and login to the affiliate id, so a row
	 * whose user was deleted still renders as something a human can act on.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_display_name(): string {
		$user = $this->get_user();

		if ( null === $user ) {
			// translators: %d: affiliate ID.
			return sprintf( __( 'Affiliate #%d', 'flyaffiliate' ), $this->get_id() );
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );

		if ( '' !== $name ) {
			return $name;
		}

		return '' !== $user->display_name ? $user->display_name : $user->user_login;
	}

	/**
	 * The email a payout for this affiliate should be sent to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_payment_email(): string {
		$email = (string) $this->get( 'payment_email', '' );

		if ( '' !== $email ) {
			return $email;
		}

		$user = $this->get_user();

		return null === $user ? '' : $user->user_email;
	}

	/**
	 * The payout method this affiliate chose, or an empty string for the default.
	 *
	 * The choice only: `flyaffiliate()->payout_method->resolve_for()` says which
	 * method actually pays them, since a chosen method can be switched off.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_payout_method(): string {
		return (string) $this->get( 'payout_method', '' );
	}

	/**
	 * This affiliate's referral link.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The URL to append the referral variable to. Defaults to the site home.
	 *
	 * @return string
	 */
	public function get_referral_url( string $url = '' ): string {
		$variable = flyaffiliate_get_option( 'referral_variable', 'affiliate' );

		return add_query_arg( rawurlencode( $variable ), $this->get_id(), '' !== $url ? $url : home_url( '/' ) );
	}
}
