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
 * @since FLYAFFILIATE_SINCE
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
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	/**
	 * The menu icon: a paper plane, as an inline SVG.
	 *
	 * A single fill lets wp-admin recolour it for every admin colour scheme.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTI1LjYgNi43IDYuNyAxMy45Yy0uNjIuMjQtLjYgMS4xMy4wMyAxLjM0TDEzIDE3LjNsMi40IDYuM2MuMjIuNiAxLjA2LjYyIDEuMzIuMDNMMjYuNiA3LjljLjI4LS42Mi0uMzctMS4yNi0xLTEuMloiLz48L3N2Zz4=';
	}

	/**
	 * The routes that appear as submenu entries, in order.
	 *
	 * Each route is a path of the admin app. The Dashboard comes first: it is
	 * where the top-level menu entry lands.
	 *
	 * @since FLYAFFILIATE_SINCE
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
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, string> $routes Route => menu title.
		 */
		return apply_filters( 'flyaffiliate_admin_menu_routes', $routes );
	}

	/**
	 * The admin URL of a route.
	 *
	 * @since FLYAFFILIATE_SINCE
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
	 * @since FLYAFFILIATE_SINCE
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
	 * @since FLYAFFILIATE_SINCE
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
