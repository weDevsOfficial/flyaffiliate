<?php
/**
 * The vendor dashboard's Affiliates page.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Adds "Affiliates" to the vendor dashboard: who sent customers to the store
 * and what each of them earned.
 *
 * The menu entry is PHP, the page is a route of Dokan's own React dashboard.
 * The script registers that route and draws the table with Dokan's
 * components, so it looks like every other page there. It bundles no copy of
 * the component library and no stylesheet.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VendorDashboard implements Hookable {

	/**
	 * The route inside Dokan's React dashboard, and the menu key.
	 *
	 * @var string
	 */
	const ROUTE = 'flyaffiliate';

	/**
	 * The script handle.
	 *
	 * @var string
	 */
	const HANDLE = 'flyaffiliate-dokan-vendor';

	/**
	 * What a vendor or a staff member needs to see the page.
	 *
	 * @var string
	 */
	const CAPABILITY = 'dokan_view_overview_menu';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'dokan_get_dashboard_nav', [ $this, 'add_menu' ] );
		add_filter( 'flyaffiliate_scripts', [ $this, 'add_script' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_script' ], 20 );
	}

	/**
	 * The menu entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, array<string, mixed>> $menus The dashboard menu.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function add_menu( $menus ): array {
		$menus = (array) $menus;

		if ( ! Settings::is_enabled() ) {
			return $menus;
		}

		$menus[ self::ROUTE ] = [
			'title'       => __( 'Affiliates', 'flyaffiliate' ),
			'icon'        => '<i class="fas fa-handshake"></i>',
			'icon_name'   => 'Handshake',
			'url'         => dokan_get_navigation_url( self::ROUTE ),
			'pos'         => 56,
			'permission'  => self::CAPABILITY,
			'react_route' => self::ROUTE,
		];

		return $menus;
	}

	/**
	 * Register the script with the rest of the plugin's scripts.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, array{src: string, deps: string[], version: string}> $scripts Handle => definition.
	 *
	 * @return array<string, array{src: string, deps: string[], version: string}>
	 */
	public function add_script( $scripts ): array {
		$scripts = (array) $scripts;
		$assets  = flyaffiliate()->assets;

		$scripts[ self::HANDLE ] = [
			'src'     => FLYAFFILIATE_ASSETS_URL . '/js/dokan-vendor.js',
			// Dokan's dashboard bundle last: the route must be registered before the dashboard renders.
			'deps'    => array_values( array_unique( array_merge( $assets->get_script_dependencies( '/assets/js/dokan-vendor.asset.php' ), [ 'dokan-react-frontend' ] ) ) ),
			'version' => $assets->get_version( '/assets/js/dokan-vendor.js', '/assets/js/dokan-vendor.asset.php' ),
		];

		return $scripts;
	}

	/**
	 * Load the script on the vendor dashboard.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function enqueue_script(): void {
		if ( ! Settings::is_enabled() || ! dokan_is_seller_dashboard() || ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE );
		wp_set_script_translations( self::HANDLE, 'flyaffiliate' );
	}
}
