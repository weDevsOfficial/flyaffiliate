<?php
/**
 * Affiliate registration and activation.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Affiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use WP_Error;

/**
 * Email-only signup with a one-time activation link.
 *
 * The `[flyaffiliate_register]` form posts an email address. A matching user
 * is reused; an unknown address gets a new user with a random password. Either
 * way the affiliate is created `pending` with an activation key, and the link
 * carrying that key sets them `active`. One user is one affiliate, so a second
 * signup for an address that is already an affiliate is told so rather than
 * duplicated.
 *
 * The activation email and the optional welcome email an admin can send when
 * adding an affiliate are the only emails Phase 1 sends (ADR-0009). When the
 * setting turns it off, affiliates stay pending until an admin activates them.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Registration implements Hookable {

	/**
	 * The `admin_post` action the form posts to.
	 *
	 * @var string
	 */
	const ACTION = 'flyaffiliate_register';

	/**
	 * The query variable the activation link carries.
	 *
	 * @var string
	 */
	const ACTIVATION_VAR = 'flyaffiliate_activate';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_form' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle_form' ] );
		add_action( 'template_redirect', [ $this, 'maybe_activate' ] );
	}

	/**
	 * Whether public signup is open.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		/**
		 * Filters whether affiliates may register themselves.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'flyaffiliate_registration_enabled', flyaffiliate_option_enabled( 'registration_enabled' ) );
	}

	/**
	 * Handle the registration form.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function handle_form(): void {
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$redirect = '' !== $redirect ? $redirect : home_url( '/' );
		// A field people never see; a bot filling every input trips it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		if ( ! empty( $_POST['flyaffiliate_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'flyaffiliate_error', 'spam', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
		$promo = isset( $_POST['promo_method'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promo_method'] ) ) : '';

		$result = $this->register( $email, $promo );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'flyaffiliate_error', rawurlencode( $result->get_error_code() ), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'flyaffiliate_registered', $result->is_active() ? 'active' : 'pending', $redirect ) );
		exit;
	}

	/**
	 * Register an affiliate for an email address.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $email        The address.
	 * @param string $promo_method How they plan to promote the store.
	 *
	 * @return Affiliate|WP_Error
	 */
	public function register( string $email, string $promo_method = '' ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'registration_closed', __( 'Affiliate registration is closed.', 'flyaffiliate' ) );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'flyaffiliate' ) );
		}

		$user = get_user_by( 'email', $email );

		if ( $user && flyaffiliate()->affiliate->is_affiliate( $user->ID ) ) {
			return new WP_Error( 'already_registered', __( 'That address is already registered as an affiliate.', 'flyaffiliate' ) );
		}

		if ( ! $user ) {
			$user_id = wp_insert_user(
				[
					'user_login' => $this->unique_login( $email ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24, true, true ),
					'role'       => 'subscriber',
				]
			);

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'user_creation_failed', __( 'The account could not be created. Try again, or contact the site owner.', 'flyaffiliate' ) );
			}
		} else {
			$user_id = $user->ID;
		}

		$send_email = flyaffiliate_option_enabled( 'activation_email_enabled' );
		$affiliate  = flyaffiliate()->affiliate->create(
			[
				'user_id'        => $user_id,
				'status'         => Affiliate::STATUS_PENDING,
				'promo_method'   => $promo_method,
				'activation_key' => $send_email ? $this->generate_key() : '',
			]
		);

		if ( is_wp_error( $affiliate ) ) {
			return $affiliate;
		}

		if ( $send_email ) {
			$this->send_activation_email( $affiliate );
		}

		/**
		 * Fires after an affiliate registers through the public form.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Affiliate $affiliate The new, pending affiliate.
		 * @param bool      $emailed   Whether an activation link was sent.
		 */
		do_action( 'flyaffiliate_affiliate_registered', $affiliate, $send_email );

		return $affiliate;
	}

	/**
	 * Activate an affiliate from a key.
	 *
	 * The key is single-use: it is cleared as the status is set.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $key The activation key.
	 *
	 * @return Affiliate|WP_Error
	 */
	public function activate( string $key ) {
		$affiliate = flyaffiliate()->affiliate->get_by_activation_key( $key );

		if ( null === $affiliate ) {
			return new WP_Error( 'invalid_key', __( 'That activation link is not valid, or has already been used.', 'flyaffiliate' ) );
		}

		return flyaffiliate()->affiliate->update(
			$affiliate->get_id(),
			[
				'status'         => Affiliate::STATUS_ACTIVE,
				'activation_key' => '',
			]
		);
	}

	/**
	 * Activate from the query variable on the front end.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function maybe_activate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key itself is the single-use secret.
		$key = isset( $_GET[ self::ACTIVATION_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ACTIVATION_VAR ] ) ) : '';

		if ( '' === $key ) {
			return;
		}

		$result    = $this->activate( $key );
		$dashboard = flyaffiliate_get_page_url( 'affiliate_dashboard' );
		$target    = '' !== $dashboard ? $dashboard : home_url( '/' );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'flyaffiliate_error', $result->get_error_code(), $target ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'flyaffiliate_activated', '1', $target ) );
		exit;
	}

	/**
	 * The activation URL for an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return string
	 */
	public function get_activation_url( Affiliate $affiliate ): string {
		$dashboard = flyaffiliate_get_page_url( 'affiliate_dashboard' );

		return add_query_arg( self::ACTIVATION_VAR, (string) $affiliate->get( 'activation_key' ), '' !== $dashboard ? $dashboard : home_url( '/' ) );
	}

	/**
	 * Send the activation email.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return bool
	 */
	public function send_activation_email( Affiliate $affiliate ): bool {
		$user = $affiliate->get_user();

		if ( null === $user || '' === (string) $affiliate->get( 'activation_key' ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		// translators: %s: site name.
		$subject = sprintf( __( 'Activate your affiliate account at %s', 'flyaffiliate' ), $site_name );
		$message = sprintf(
			// translators: 1: site name, 2: activation URL.
			__( "Thanks for joining the %1\$s affiliate programme.\n\nClick the link below to activate your account:\n%2\$s\n\nIf you did not sign up, you can ignore this email.", 'flyaffiliate' ),
			$site_name,
			$this->get_activation_url( $affiliate )
		);

		/**
		 * Filters the activation email before it is sent.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array     $email     `[ 'to' => string, 'subject' => string, 'message' => string, 'headers' => array ]`.
		 * @param Affiliate $affiliate The affiliate.
		 */
		$email = apply_filters(
			'flyaffiliate_activation_email',
			[
				'to'      => $user->user_email,
				'subject' => $subject,
				'message' => $message,
				'headers' => [],
			],
			$affiliate
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Send a welcome email to an affiliate an admin just added.
	 *
	 * SliceWP's "add affiliate" form has the same switch. The email carries the
	 * referral link and the dashboard address, so the affiliate can start
	 * without anyone telling them where to go.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return bool
	 */
	public function send_welcome_email( Affiliate $affiliate ): bool {
		$user = $affiliate->get_user();

		if ( null === $user ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$dashboard = flyaffiliate_get_page_url( 'affiliate_dashboard' );
		// translators: %s: site name.
		$subject = sprintf( __( 'Welcome to the %s affiliate programme', 'flyaffiliate' ), $site_name );
		$message = sprintf(
			// translators: 1: site name, 2: referral URL, 3: dashboard URL.
			__( "You are now an affiliate of %1\$s.\n\nYour referral link:\n%2\$s\n\nShare it, and you earn a commission on every order it brings in. Your dashboard shows your visits, commissions and payouts:\n%3\$s", 'flyaffiliate' ),
			$site_name,
			$affiliate->get_referral_url(),
			'' !== $dashboard ? $dashboard : home_url( '/' )
		);

		/**
		 * Filters the welcome email before it is sent.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array     $email     `[ 'to' => string, 'subject' => string, 'message' => string, 'headers' => array ]`.
		 * @param Affiliate $affiliate The affiliate.
		 */
		$email = apply_filters(
			'flyaffiliate_welcome_email',
			[
				'to'      => $user->user_email,
				'subject' => $subject,
				'message' => $message,
				'headers' => [],
			],
			$affiliate
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * A random activation key.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	protected function generate_key(): string {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * A login derived from an email address that no user has yet.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $email The address.
	 *
	 * @return string
	 */
	protected function unique_login( string $email ): string {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( false !== $local ? $local : $email, true );
		$base  = '' !== $base ? $base : 'affiliate';
		$login = $base;
		$n     = 1;

		while ( username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}

		return $login;
	}
}
