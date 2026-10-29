<?php
/**
 * The Extensions page while FlyAffiliate Pro is not active.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Admin;

use FlyAffiliate\Admin\ProExtensions;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Without Pro, an Extensions entry before Settings and Pro's extensions as
 * previews; with Pro, nothing, so Pro's own page owns the route.
 */
class ProExtensionsTest extends FlyAffiliateTestCase {

	/**
	 * The menu routes as FlyAffiliate lists them.
	 *
	 * @var array<string, string>
	 */
	protected array $routes = [
		'dashboard' => 'Dashboard',
		'payouts'   => 'Payouts',
		'settings'  => 'Settings',
	];

	/**
	 * {@inheritDoc}
	 */
	public function tear_down() {
		remove_filter( 'flyaffiliate_is_pro_active', '__return_true' );

		parent::tear_down();
	}

	/**
	 * Without Pro, Extensions sits between Payouts and Settings, and the app
	 * gets the cards and the upgrade links.
	 *
	 * @return void
	 */
	public function test_without_pro_the_page_previews_pros_extensions(): void {
		$service = new ProExtensions();

		$this->assertFalse( flyaffiliate_is_pro_active() );
		$this->assertSame( [ 'dashboard', 'payouts', ProExtensions::ROUTE, 'settings' ], array_keys( $service->add_route( $this->routes ) ) );

		$data = $service->add_script_data( [] )['proExtensions'];

		$this->assertSame( flyaffiliate_get_pro_upgrade_url(), $data['upgradeUrl'] );
		$this->assertSame( admin_url( 'plugins.php' ), $data['pluginsUrl'] );
		$this->assertNotEmpty( $data['features'] );
	}

	/**
	 * Every card names one extension Pro ships, by Pro's id, with its kind.
	 *
	 * @return void
	 */
	public function test_the_cards_list_only_what_pro_ships(): void {
		$extensions = ( new ProExtensions() )->get_extensions();
		$by_id      = array_column( $extensions, null, 'id' );

		foreach ( $extensions as $extension ) {
			$this->assertSame( [ 'id', 'name', 'description', 'category', 'doc_url' ], array_keys( $extension ) );
			$this->assertContains( $extension['category'], [ 'integration', 'payout_method' ] );
		}

		$this->assertSame( [ 'dokan', 'paypal' ], array_keys( $by_id ), 'Only what Pro ships is listed.' );
		$this->assertSame( 'integration', $by_id['dokan']['category'] );
		$this->assertSame( 'payout_method', $by_id['paypal']['category'] );
	}

	/**
	 * With Pro running, neither the entry nor the data: Pro's page takes the route.
	 *
	 * @return void
	 */
	public function test_with_pro_it_adds_nothing(): void {
		add_filter( 'flyaffiliate_is_pro_active', '__return_true' );

		$service = new ProExtensions();

		$this->assertTrue( flyaffiliate_is_pro_active() );
		$this->assertSame( $this->routes, $service->add_route( $this->routes ) );
		$this->assertArrayNotHasKey( 'proExtensions', $service->add_script_data( [ 'version' => '1.0.0' ] ) );
	}
}
