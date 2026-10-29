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
 * @since 1.0.0
 */
class Shortcodes implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	/**
	 * The shortcodes.
	 *
	 * @since 1.0.0
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
		 * @since 1.0.0
		 *
		 * @param \FlyAffiliate\Abstracts\Shortcode[] $shortcodes The shortcode objects.
		 */
		return apply_filters( 'flyaffiliate_shortcodes', $shortcodes );
	}

	/**
	 * Register them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		foreach ( $this->get_shortcodes() as $shortcode ) {
			$shortcode->register();
		}
	}
}
