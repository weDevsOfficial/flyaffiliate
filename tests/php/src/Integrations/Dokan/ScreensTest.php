<?php
/**
 * The screens the integration adds to Dokan and to the storefront.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations\Dokan;

use FlyAffiliate\Integrations\Dokan\AdminSettings;
use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Integrations\Dokan\StorefrontNotices;
use FlyAffiliate\Integrations\Dokan\VendorDashboard;
use FlyAffiliate\Integrations\Dokan\VendorProgram;
use FlyAffiliate\Integrations\Dokan\VendorSettings;

/**
 * Settings inside Dokan's admin settings, the vendor's tab, the menu and the notices.
 *
 * @group dokan
 */
class ScreensTest extends DokanTestCase {

	/**
	 * A service the container built.
	 *
	 * @param class-string $class_name The service.
	 *
	 * @return object
	 */
	private function service( string $class_name ): object {
		return flyaffiliate()->get_container()->get( $class_name );
	}

	/**
	 * What a callback prints.
	 *
	 * @param callable $callback The callback.
	 *
	 * @return string
	 */
	private function output_of( callable $callback ): string {
		ob_start();
		$callback();

		return trim( (string) ob_get_clean() );
	}

	/**
	 * The legacy screen gets a sub-section under Selling Options, the new one a page of its own, over the same values.
	 *
	 * @return void
	 */
	public function test_the_settings_are_in_both_of_dokans_screens(): void {
		$legacy = apply_filters( 'dokan_settings_selling_options', [] );

		$this->assertSame( 'sub_section', $legacy['flyaffiliate_section']['type'] );

		foreach ( Settings::get_keys() as $key ) {
			$this->assertArrayHasKey( $key, $legacy );
			$this->assertSame( $key, $legacy[ $key ]['name'] );
		}

		$schema = apply_filters( 'dokan_get_admin_settings_schema', [] );
		$by_id  = array_column( $schema, null, 'id' );

		$this->assertSame( 'page', $by_id[ AdminSettings::PAGE ]['type'] );
		$this->assertSame( AdminSettings::PAGE, $by_id['flyaffiliate_vendor_programs_page']['page_id'] );

		foreach ( Settings::get_keys() as $key ) {
			$this->assertSame( Settings::SECTION . '.' . $key, $by_id[ $key ]['legacy_key'], 'the new field edits the legacy value' );
		}

		foreach ( array_column( $schema, 'id' ) as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z_-]+$/', $id, 'Dokan accepts only these characters in an id it uses as a scope' );
		}
	}

	/**
	 * The vendor's tab is there while the marketplace allows vendor settings, and saving it stores the vendor's values.
	 *
	 * @return void
	 */
	public function test_the_vendor_tab_follows_the_marketplace_switch(): void {
		$vendor   = $this->vendor();
		$settings = $this->service( VendorSettings::class );
		$ids      = static fn( array $schema ): array => array_column( $schema, 'id' );

		$this->assertContains( 'tab_flyaffiliate', $ids( $settings->add_schema( [], $vendor ) ) );
		$this->assertContains( VendorSettings::FIELD_RATE, $ids( $settings->add_schema( [], $vendor ) ) );

		$settings->save_schema_values(
			$vendor,
			[
				VendorSettings::FIELD_OVERRIDE => 'on',
				VendorSettings::FIELD_RATE     => 22,
				VendorSettings::FIELD_LOCK     => 9,
			]
		);

		$this->assertSame( 22.0, $this->program()->get_rate( $vendor ) );
		$this->assertSame( 9, $this->program()->get_hold_days( $vendor, 30 ) );

		// Switching back to the marketplace's terms keeps what the vendor had entered.
		$settings->save_schema_values(
			$vendor,
			[
				VendorSettings::FIELD_OVERRIDE => 'off',
				VendorSettings::FIELD_RATE     => 0,
				VendorSettings::FIELD_LOCK     => 0,
			]
		);

		$this->assertFalse( $this->program()->has_override( $vendor ) );
		$this->assertSame( 22.0, (float) get_user_meta( $vendor, VendorProgram::META, true )['rate'] );

		$this->acting_as( $vendor );
		$this->assertArrayHasKey( VendorSettings::SLUG, $settings->add_settings_tab( [] ) );

		$this->marketplace( [ Settings::VENDOR_OVERRIDE => 'off' ] );

		$this->assertSame( [], $settings->add_schema( [], $vendor ) );
		$this->assertSame( [], $settings->add_settings_tab( [] ) );
	}

	/**
	 * The vendor dashboard gets an Affiliates entry that opens a route of Dokan's React dashboard.
	 *
	 * @return void
	 */
	public function test_the_vendor_dashboard_menu(): void {
		$dashboard = $this->service( VendorDashboard::class );
		$menu      = $dashboard->add_menu( [] );

		$this->assertSame( VendorDashboard::ROUTE, $menu[ VendorDashboard::ROUTE ]['react_route'] );
		$this->assertSame( VendorDashboard::CAPABILITY, $menu[ VendorDashboard::ROUTE ]['permission'] );

		$this->marketplace( [ Settings::PROGRAMS => 'off' ] );

		$this->assertSame( [], $dashboard->add_menu( [] ) );
	}

	/**
	 * Affiliates and vendors see what a product pays; a customer and a visitor do not.
	 *
	 * @return void
	 */
	public function test_only_affiliates_and_vendors_see_the_terms(): void {
		$vendor   = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);
		$plain    = $this->vendor();
		$product  = $this->factory()->product->create( [ 'vendor_id' => $vendor ] );
		$notices  = fn(): StorefrontNotices => new StorefrontNotices( $this->program() );
		$on_store = fn( int $vendor_id ): string => $this->output_of( fn() => $notices()->render_store_notice( get_userdata( $vendor_id ) ) );
		$on_item  = function () use ( $notices, $product ): string {
			$GLOBALS['product'] = wc_get_product( $product );

			return $this->output_of( fn() => $notices()->render_product_terms() );
		};

		$this->assertSame( '', $on_store( $vendor ), 'a visitor sees nothing' );
		$this->assertSame( '', $on_item() );

		$this->acting_as( $this->factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( '', $on_store( $vendor ), 'nor does a customer' );
		$this->assertSame( '', $on_item() );

		$affiliate = $this->factory()->affiliate->create_and_get_model();
		$this->acting_as( (int) $affiliate->get( 'user_id' ) );

		$this->assertStringContainsString( '25%', $on_store( $vendor ) );
		$this->assertStringContainsString( '5 days', $on_store( $vendor ) );
		$this->assertStringContainsString( esc_html( dokan()->vendor->get( $vendor )->get_shop_name() ), $on_store( $vendor ) );
		$this->assertSame( '', $on_store( $plain ), 'a store on the marketplace’s terms has nothing to announce' );
		$this->assertStringContainsString( '25%', $on_item() );

		$this->acting_as( $plain );
		$this->assertStringContainsString( '25%', $on_item(), 'a vendor sees it too' );

		unset( $GLOBALS['product'] );
	}
}
