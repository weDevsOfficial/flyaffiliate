<?php
/**
 * The registration form shortcode.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Abstracts\Shortcode;
use FlyAffiliate\Affiliate\Registration;

/**
 * `[flyaffiliate_register]`: email-only signup.
 *
 * A logged-in user who is already an affiliate is sent to the dashboard
 * instead of a form they cannot use.
 *
 * @since FLYAFFILIATE_SINCE
 */
class RegistrationForm extends Shortcode {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected string $tag = 'flyaffiliate_register';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render( $atts = [] ): string {
		wp_enqueue_style( 'flyaffiliate-frontend' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a status flag from a redirect for display.
		$registered = isset( $_GET['flyaffiliate_registered'] ) ? sanitize_key( wp_unslash( $_GET['flyaffiliate_registered'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$error = isset( $_GET['flyaffiliate_error'] ) ? sanitize_key( wp_unslash( $_GET['flyaffiliate_error'] ) ) : '';

		$current_user = wp_get_current_user();
		$affiliate    = $current_user->exists() ? flyaffiliate()->affiliate->get_by_user( $current_user->ID ) : null;

		return $this->template(
			'registration/form.php',
			[
				'action'        => Registration::ACTION,
				'enabled'       => flyaffiliate()->registration->is_enabled(),
				'registered'    => $registered,
				'error'         => $this->error_message( $error ),
				'affiliate'     => $affiliate,
				'dashboard_url' => flyaffiliate_get_page_url( 'affiliate_dashboard' ),
				'email'         => $current_user->exists() ? $current_user->user_email : '',
				'redirect_to'   => get_permalink(),
			]
		);
	}

	/**
	 * A message for an error code from the redirect.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $code Error code.
	 *
	 * @return string An empty string for no error.
	 */
	protected function error_message( string $code ): string {
		$messages = [
			'invalid_email'        => __( 'Enter a valid email address.', 'flyaffiliate' ),
			'already_registered'   => __( 'That address is already registered as an affiliate. Log in to open your dashboard.', 'flyaffiliate' ),
			'registration_closed'  => __( 'Affiliate registration is closed.', 'flyaffiliate' ),
			'user_creation_failed' => __( 'The account could not be created. Try again, or contact the site owner.', 'flyaffiliate' ),
		];

		return $messages[ $code ] ?? ( '' !== $code ? __( 'Something went wrong. Try again.', 'flyaffiliate' ) : '' );
	}
}
