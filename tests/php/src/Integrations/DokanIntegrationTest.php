<?php
/**
 * The Dokan integration loads whichever order the plugins boot in.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations;

use FlyAffiliate\Admin\Settings\Schema\SettingsSchema;
use FlyAffiliate\Integrations\Dokan\Integration;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Dokan fires `dokan_loaded` from `woocommerce_loaded`, before FlyAffiliate adds its integration provider on `plugins_loaded`.
 *
 * @group dokan
 */
class DokanIntegrationTest extends FlyAffiliateTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_without_dokan();
	}

	/**
	 * The provider registers even though `dokan_loaded` fired before it was added.
	 */
	public function test_dokan_services_register_when_dokan_loaded_already_fired(): void {
		$this->assertGreaterThan( 0, did_action( 'dokan_loaded' ) );
		$this->assertTrue( flyaffiliate()->get_container()->has( Integration::class ) );
	}

	/**
	 * The Integrations → Dokan subpage and its fields are in the settings schema.
	 */
	public function test_dokan_settings_are_in_the_schema(): void {
		$ids = array_column( SettingsSchema::get_schema(), 'id' );

		foreach ( [ 'dokan', 'dokan_settings', 'dokan_vendor_programs', 'dokan_vendor_can_set_rate', 'dokan_vendor_max_rate' ] as $id ) {
			$this->assertContains( $id, $ids );
		}

		$this->assertSame( 50.0, SettingsSchema::get_defaults()['dokan_vendor_max_rate'] );
	}
}
