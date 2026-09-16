<?php
/**
 * The request-time referral tracker.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;

/**
 * Turns a referral link into a visit row and a first-party cookie.
 *
 * `/?affiliate=5` on any frontend request records a visit for affiliate 5 and
 * sets one signed cookie carrying the affiliate and visit ids. The cookie
 * lives for the attribution window (the `cookie_duration` setting); the order
 * attribution reads it at checkout. The last link clicked wins.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Tracker implements Hookable {

	/**
	 * The cookie name.
	 *
	 * @var string
	 */
	const COOKIE = 'flyaffiliate_ref';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Frontend only, and before any output so the cookie can still be set.
		add_action( 'template_redirect', [ $this, 'track' ], 1 );
	}

	/**
	 * The query variable a referral link carries.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public static function get_variable(): string {
		$variable = (string) flyaffiliate_get_option( 'referral_variable', 'affiliate' );

		return '' !== $variable ? $variable : 'affiliate';
	}

	/**
	 * Record the visit and set the cookie when the request carries a referral.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function track(): void {
		$variable = self::get_variable();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a referral link is a public GET; there is no form to protect.
		$affiliate_id = isset( $_GET[ $variable ] ) ? absint( wp_unslash( $_GET[ $variable ] ) ) : 0;

		if ( 0 === $affiliate_id ) {
			return;
		}

		$affiliate = flyaffiliate()->affiliate->get( $affiliate_id );

		if ( null === $affiliate || Affiliate::STATUS_ACTIVE !== $affiliate->get( 'status' ) ) {
			return;
		}

		// An affiliate following their own link earns nothing; do not even attribute it.
		if ( flyaffiliate_option_enabled( 'block_self_referral' ) && get_current_user_id() === (int) $affiliate->get( 'user_id' ) ) {
			return;
		}

		/**
		 * Filters whether this request is tracked as a visit.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param bool      $track     Default true.
		 * @param Affiliate $affiliate The affiliate the link belongs to.
		 */
		if ( ! apply_filters( 'flyaffiliate_track_visit', true, $affiliate ) ) {
			return;
		}

		// A repeat click on the same link inside the window is one visit; only the expiry moves.
		$current = $this->get_attribution();

		if ( null !== $current && $current['affiliate_id'] === $affiliate_id ) {
			$this->set_cookie( $affiliate_id, $current['visit_id'] );

			return;
		}

		$visit = flyaffiliate()->tracking->create(
			[
				'affiliate_id' => $affiliate_id,
				'url'          => $this->get_landing_url( $variable ),
				'referrer'     => (string) wp_get_raw_referer(),
				'ip'           => $this->get_ip(),
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			]
		);

		if ( null === $visit ) {
			return;
		}

		$this->set_cookie( $affiliate_id, $visit->get_id() );

		/**
		 * Fires after a referral visit is recorded.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param \FlyAffiliate\Models\Visit $visit     The visit.
		 * @param Affiliate                  $affiliate The affiliate.
		 */
		do_action( 'flyaffiliate_visit_tracked', $visit, $affiliate );
	}

	/**
	 * The affiliate and visit the current visitor is attributed to, if any.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array{affiliate_id: int, visit_id: int}|null Null when there is no valid cookie.
	 */
	public function get_attribution(): ?array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and verified by parse().
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';

		return self::parse( $raw );
	}

	/**
	 * Build a signed cookie value.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $affiliate_id Affiliate id.
	 * @param int $visit_id     Visit id.
	 *
	 * @return string
	 */
	public static function sign( int $affiliate_id, int $visit_id ): string {
		$payload = $affiliate_id . '|' . $visit_id;

		return $payload . '|' . substr( wp_hash( 'flyaffiliate_ref|' . $payload, 'auth' ), 0, 32 );
	}

	/**
	 * Verify and parse a cookie value.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $value The raw cookie value.
	 *
	 * @return array{affiliate_id: int, visit_id: int}|null
	 */
	public static function parse( string $value ): ?array {
		$parts = explode( '|', $value );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		$affiliate_id = absint( $parts[0] );
		$visit_id     = absint( $parts[1] );

		if ( 0 === $affiliate_id || ! hash_equals( self::sign( $affiliate_id, $visit_id ), $value ) ) {
			return null;
		}

		return [
			'affiliate_id' => $affiliate_id,
			'visit_id'     => $visit_id,
		];
	}

	/**
	 * Set the attribution cookie for the attribution window.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $affiliate_id Affiliate id.
	 * @param int $visit_id     Visit id.
	 *
	 * @return void
	 */
	protected function set_cookie( int $affiliate_id, int $visit_id ): void {
		$days    = absint( flyaffiliate_get_option( 'cookie_duration', 30 ) );
		$value   = self::sign( $affiliate_id, $visit_id );
		$expires = $days > 0 ? time() + $days * DAY_IN_SECONDS : 0;

		// The same request may go on to create an order (a direct add-to-cart link).
		$_COOKIE[ self::COOKIE ] = $value;

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$value,
			[
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * The page the visitor landed on, without the referral variable.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $variable The referral variable.
	 *
	 * @return string
	 */
	protected function get_landing_url( string $variable ): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return remove_query_arg( $variable, home_url( $path ) );
	}

	/**
	 * The visitor's IP address, for the hashed fingerprint.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	protected function get_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
