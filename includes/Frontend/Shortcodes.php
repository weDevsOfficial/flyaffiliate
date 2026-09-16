<?php
/**
 * Shortcode registration.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Registers the front-end shortcodes on `init`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Shortcodes implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	/**
	 * The shortcodes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return \FlyAffiliate\Abstracts\Shortcode[]
	 */
	public function get_shortcodes(): array {
		$shortcodes = [
			new AffiliateDashboard(),
			new RegistrationForm(),
		];

		/**
		 * Filters the FlyAffiliate shortcodes.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param \FlyAffiliate\Abstracts\Shortcode[] $shortcodes The shortcode objects.
		 */
		return apply_filters( 'flyaffiliate_shortcodes', $shortcodes );
	}

	/**
	 * Register them.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		foreach ( $this->get_shortcodes() as $shortcode ) {
			$shortcode->register();
		}
	}
}
