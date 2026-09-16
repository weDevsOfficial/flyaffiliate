<?php
/**
 * Contract for classes that register WordPress hooks.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implemented by every class that attaches WordPress hooks.
 *
 * A class implementing this interface is tagged with the interface name when it
 * is registered in the container, and `register_hooks()` is called on it
 * automatically during bootstrap. Nothing else has to remember to do it.
 *
 * Hooks belong in `register_hooks()` and nowhere else — in particular not in a
 * constructor, which runs during container resolution at a moment the class does
 * not control.
 *
 * @since FLYAFFILIATE_SINCE
 */
interface Hookable {

	/**
	 * Attach this class's WordPress hooks.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
