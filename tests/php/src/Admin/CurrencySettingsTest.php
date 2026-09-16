<?php
/**
 * The Currency settings.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Admin;

use FlyAffiliate\Install\Installer;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Amounts are written the way FlyAffiliate's own settings say, not WooCommerce's.
 */
class CurrencySettingsTest extends FlyAffiliateTestCase {

	/**
	 * The saved currency settings reach both apps, whatever WooCommerce says.
	 *
	 * @return void
	 */
	public function test_saved_currency_settings_reach_the_apps(): void {
		update_option( 'woocommerce_currency', 'GBP' );

		$saved = flyaffiliate()->settings->save(
			[
				'currency'                     => 'EUR',
				'currency_position'            => 'right_space',
				'currency_thousands_separator' => ' ',
				'currency_decimal_separator'   => ',',
			]
		);

		$this->assertNotWPError( $saved );
		$this->assertSame( 'EUR', flyaffiliate_get_currency() );

		$currency = flyaffiliate()->assets->get_admin_script_data()['currency'];

		$this->assertSame( 'EUR', $currency['code'] );
		$this->assertSame( '€', $currency['symbol'] );
		$this->assertSame( 'right_space', $currency['position'] );
		$this->assertSame( ' ', $currency['thousandSeparator'], 'a space survives sanitizing' );
		$this->assertSame( ',', $currency['decimalSeparator'] );
		$this->assertSame( 2, $currency['decimals'] );

		$this->assertSame( $currency, flyaffiliate()->assets->get_dashboard_script_data()['currency'] );
	}

	/**
	 * Unknown values fall back instead of being stored as sent.
	 *
	 * @return void
	 */
	public function test_unknown_values_are_refused(): void {
		flyaffiliate()->settings->save(
			[
				'currency'          => 'XYZ',
				'currency_position' => 'middle',
			]
		);

		$this->assertSame( 'USD', flyaffiliate_get_option( 'currency' ) );
		$this->assertSame( 'left', flyaffiliate_get_option( 'currency_position' ) );
		$this->assertSame( 0, flyaffiliate_get_currency_decimals( 'JPY' ) );
		$this->assertSame( 3, flyaffiliate_get_currency_decimals( 'KWD' ) );
	}

	/**
	 * A site that installed before the Currency settings existed stores them once,
	 * so a later change to the WooCommerce currency does not move them.
	 *
	 * @return void
	 */
	public function test_an_upgrade_stores_the_currency_once(): void {
		$stored = (array) get_option( 'flyaffiliate_settings', [] );
		unset( $stored['currency'], $stored['currency_position'], $stored['currency_thousands_separator'], $stored['currency_decimal_separator'] );
		update_option( 'flyaffiliate_settings', $stored );
		update_option( 'woocommerce_currency', 'CAD' );
		flyaffiliate()->settings->get_registry()->clear_cache();

		( new Installer() )->create_options();

		update_option( 'woocommerce_currency', 'AUD' );
		flyaffiliate()->settings->get_registry()->clear_cache();

		$this->assertSame( 'CAD', ( (array) get_option( 'flyaffiliate_settings' ) )['currency'] );
		$this->assertSame( 'CAD', flyaffiliate_get_currency() );
	}
}
