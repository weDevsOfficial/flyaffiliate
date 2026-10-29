<?php
/**
 * The wp-admin menu.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Registers the FlyAffiliate menu and mounts the admin app.
 *
 * There is one admin page. Every entry under it is a hash route of the React
 * application (`admin.php?page=flyaffiliate#/commissions`), the way Dokan's
 * admin dashboard works, so the list screens and settings share one bundle
 * and navigate without a page load. Everything is gated on `flyaffiliate_admin_capability()`
 * (ADR-0008).
 *
 * @since 1.0.0
 */
class Menu implements Hookable {

	/**
	 * Slug of the admin page.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'flyaffiliate';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	/**
	 * The menu icon: FlyAffiliate's mark, as an inline SVG.
	 *
	 * A single fill lets wp-admin recolour it for every admin colour scheme.
	 * The view box leaves a margin round the mark, so at wp-admin's 20px it
	 * draws 17px wide, as wide as Dokan's menu icon.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjEzNy41IDEzNiAzMDQgMzA0IiB3aWR0aD0iMjAiIGhlaWdodD0iMjAiPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0xODUgMTUySDM0N0MzNDAgMjMwIDI5MCAyODIgMjIwIDI4MkgxNjBWMTc3UTE2MCAxNTIgMTg1IDE1MlpNMTg1IDI5M0g0MTlDNDEyIDM4MCAzNTAgNDI0IDI2MCA0MjRIMTkwUTE2MCA0MjQgMTYwIDM5NFYzMThRMTYwIDI5MyAxODUgMjkzWiIvPjwvc3ZnPg==';
	}

	/**
	 * The routes that appear as submenu entries, in order.
	 *
	 * Each route is a path of the admin app. The Dashboard comes first: it is
	 * where the top-level menu entry lands.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Route => menu title.
	 */
	public function get_routes(): array {
		$routes = [
			'dashboard'   => __( 'Dashboard', 'flyaffiliate' ),
			'affiliates'  => __( 'Affiliates', 'flyaffiliate' ),
			'commissions' => __( 'Commissions', 'flyaffiliate' ),
			'visits'      => __( 'Visits', 'flyaffiliate' ),
			'payouts'     => __( 'Payouts', 'flyaffiliate' ),
			'settings'    => __( 'Settings', 'flyaffiliate' ),
		];

		/**
		 * Filters the routes listed under the FlyAffiliate menu.
		 *
		 * Adding a route here only adds the link; the React app decides what
		 * renders at it through the `flyaffiliate_admin_routes` JavaScript filter.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $routes Route => menu title.
		 */
		return apply_filters( 'flyaffiliate_admin_menu_routes', $routes );
	}

	/**
	 * Whether the current admin request is for one of the plugin's screens.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_plugin_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the page slug to decide what to render changes nothing.
		return isset( $_GET['page'] ) && self::PARENT_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * The admin URL of a route.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route A route, e.g. `commissions` or `affiliates/5`.
	 *
	 * @return string
	 */
	public static function get_route_url( string $route = '' ): string {
		return admin_url( 'admin.php?page=' . self::PARENT_SLUG . '#/' . ltrim( $route, '/' ) );
	}

	/**
	 * Register the menu with WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		global $submenu;

		$capability = flyaffiliate_admin_capability();

		add_menu_page(
			__( 'FlyAffiliate', 'flyaffiliate' ),
			__( 'FlyAffiliate', 'flyaffiliate' ),
			$capability,
			self::PARENT_SLUG,
			[ $this, 'render' ],
			self::get_menu_icon(),
			56
		);

		if ( ! current_user_can( $capability ) ) {
			return;
		}

		// Hash links cannot be registered through add_submenu_page(), which
		// treats the slug as a page. Writing the global directly is how core
		// itself, and Dokan, add such entries.
		foreach ( $this->get_routes() as $route => $title ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- appending menu entries is the documented use of this global.
			$submenu[ self::PARENT_SLUG ][] = [ $title, $capability, 'admin.php?page=' . self::PARENT_SLUG . '#/' . $route ];
		}
	}

	/**
	 * Render the app's mount point.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'flyaffiliate' ), 403 );
		}

		flyaffiliate_get_template( 'admin/app.php' );
	}
}
