<?php
/**
 * Base class for shortcodes.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Abstracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A shortcode that renders a template and enqueues its own assets.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class Shortcode {

	/**
	 * The shortcode tag.
	 *
	 * @var string
	 */
	protected string $tag = '';

	/**
	 * Register the shortcode with WordPress.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( $this->tag, [ $this, 'render' ] );
	}

	/**
	 * The shortcode tag.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_tag(): string {
		return $this->tag;
	}

	/**
	 * Render the shortcode.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	abstract public function render( $atts = [] ): string;

	/**
	 * Render a template to a string.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $template Template file name, relative to `templates/`.
	 * @param array  $args     Variables for the template.
	 *
	 * @return string
	 */
	protected function template( string $template, array $args = [] ): string {
		ob_start();

		flyaffiliate_get_template( $template, $args );

		return (string) ob_get_clean();
	}
}
