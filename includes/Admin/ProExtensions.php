<?php
/**
 * The Extensions page while FlyAffiliate Pro is not active.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Shows what FlyAffiliate Pro's extensions add, the way Dokan Lite's Modules
 * page shows Dokan Pro's modules (ADR-0005): an Extensions entry between
 * Payouts and Settings, and a page of cards that cannot be switched on here.
 * A card's switch, or Upgrade, opens a dialog about Pro.
 *
 * Only while Pro is not active (`flyaffiliate_is_pro_active()`). With Pro
 * running, its own Extensions page and menu entry take the place, and this
 * adds nothing.
 *
 * The list lives here, not in the app, so it is translated with the rest of
 * FlyAffiliate and reaches the app as `window.flyaffiliate.proExtensions`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ProExtensions implements Hookable {

	/**
	 * The route of the page.
	 *
	 * @var string
	 */
	const ROUTE = 'extensions';

	/**
	 * The extension states a card can show.
	 *
	 * @var string
	 */
	const STATUS_AVAILABLE   = 'available';
	const STATUS_COMING_SOON = 'coming_soon';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'flyaffiliate_admin_menu_routes', [ $this, 'add_route' ] );
		add_filter( 'flyaffiliate_admin_script_data', [ $this, 'add_script_data' ] );
	}

	/**
	 * Add Extensions to the menu, before Settings, where Pro puts its own.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, string>|mixed $routes Route => menu title.
	 *
	 * @return array<string, string>|mixed
	 */
	public function add_route( $routes ) {
		if ( ! is_array( $routes ) || flyaffiliate_is_pro_active() ) {
			return $routes;
		}

		$entry    = [ self::ROUTE => __( 'Extensions', 'flyaffiliate' ) ];
		$position = array_search( 'settings', array_keys( $routes ), true );

		if ( false === $position ) {
			return $routes + $entry;
		}

		return array_slice( $routes, 0, $position, true ) + $entry + array_slice( $routes, $position, null, true );
	}

	/**
	 * Hand the page its cards and links, as `window.flyaffiliate.proExtensions`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed>|mixed $data The admin app's data.
	 *
	 * @return array<string, mixed>|mixed
	 */
	public function add_script_data( $data ) {
		if ( ! is_array( $data ) || flyaffiliate_is_pro_active() ) {
			return $data;
		}

		$data['proExtensions'] = [
			'upgradeUrl' => flyaffiliate_get_pro_upgrade_url(),
			'pluginsUrl' => admin_url( 'plugins.php' ),
			'extensions' => $this->get_extensions(),
			'features'   => $this->get_features(),
		];

		return $data;
	}

	/**
	 * Pro's extensions, as cards: the ones Pro ships. Only Dokan for now; one
	 * on its way can be listed as `STATUS_COMING_SOON` once it is announced.
	 *
	 * The ids are the ones Pro's extensions use, so a card and the extension it
	 * stands for can be matched later. Brand names are not translated.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array{id: string, name: string, description: string, category: string, status: string, doc_url: string}>
	 */
	public function get_extensions(): array {
		$extensions = [
			[
				'id'          => 'dokan',
				'name'        => 'Dokan',
				'description' => __( 'Let Dokan vendors run their own affiliate programs. Each vendor opts in and pays the commission on their own products.', 'flyaffiliate' ),
				'category'    => 'integration',
				'status'      => self::STATUS_AVAILABLE,
				// TODO(docs): the extension's documentation, once it is published.
				'doc_url'     => 'https://flyaffiliate.co/',
			],
		];

		/**
		 * Filters the Pro extensions the Extensions page shows while Pro is not active.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $extensions The cards.
		 */
		return (array) apply_filters( 'flyaffiliate_pro_extensions_preview', $extensions );
	}

	/**
	 * What Pro adds today, for the upgrade dialog. Only what ships.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public function get_features(): array {
		return [
			__( 'Affiliate programs for Dokan vendors', 'flyaffiliate' ),
			__( 'Vendors set their own rate and hold period', 'flyaffiliate' ),
			__( 'Commission taken from the vendor’s earning', 'flyaffiliate' ),
			__( 'Affiliate figures in Dokan’s reports', 'flyaffiliate' ),
			__( 'New extensions as they are released', 'flyaffiliate' ),
		];
	}
}
