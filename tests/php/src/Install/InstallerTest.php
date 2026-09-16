<?php
/**
 * Installer tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Install;

use FlyAffiliate\Admin\Settings\Repository\SettingsRepository;
use FlyAffiliate\Admin\Settings\Schema\SettingsSchema;
use FlyAffiliate\Install\Installer;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The installer runs on activation and again on every schema upgrade, so
 * "running it twice changes nothing" is a requirement, not a nicety.
 *
 * @group install
 *
 * @since FLYAFFILIATE_SINCE
 */
class InstallerTest extends FlyAffiliateTestCase {

	/**
	 * Every table exists after installation.
	 *
	 * @return void
	 */
	public function test_it_creates_every_table(): void {
		global $wpdb;

		( new Installer() )->create_tables();

		foreach ( Installer::get_table_names() as $table ) {
			$name = $wpdb->prefix . $table;

			$this->assertSame(
				$name,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ),
				sprintf( 'Table `%s` was not created.', $name )
			);
		}
	}

	/**
	 * Money columns are DECIMAL(19,4). A FLOAT here would lose cents, and
	 * DECIMAL(10,2) cannot hold a rate applied to a base amount.
	 *
	 * @return void
	 */
	public function test_money_columns_are_decimal_19_4(): void {
		global $wpdb;

		( new Installer() )->create_tables();

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}flyaffiliate_commissions", OBJECT_K );

		foreach ( [ 'base_amount', 'rate', 'amount' ] as $column ) {
			$this->assertArrayHasKey( $column, $columns );
			$this->assertSame( 'decimal(19,4)', strtolower( $columns[ $column ]->Type ) );
		}
	}

	/**
	 * `order_item_id` is unique, which is what makes commission creation
	 * idempotent when a hook fires twice.
	 *
	 * @return void
	 */
	public function test_order_item_id_is_unique(): void {
		global $wpdb;

		( new Installer() )->create_tables();

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}flyaffiliate_commissions WHERE Key_name = 'order_item_id'" );

		$this->assertNotEmpty( $indexes, 'There is no order_item_id index.' );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique, 'The order_item_id index is not unique.' );
	}

	/**
	 * One WordPress user is one affiliate, and the database says so.
	 *
	 * @return void
	 */
	public function test_affiliate_user_id_is_unique(): void {
		global $wpdb;

		( new Installer() )->create_tables();

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}flyaffiliate_affiliates WHERE Key_name = 'user_id'" );

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	/**
	 * Installing writes the default settings.
	 *
	 * @return void
	 */
	public function test_it_writes_the_default_settings(): void {
		delete_option( SettingsRepository::OPTION_KEY );

		( new Installer() )->create_options();

		$this->assertSame( SettingsSchema::get_defaults(), get_option( SettingsRepository::OPTION_KEY ) );
		$this->assertSame( 10.0, flyaffiliate_get_option( 'default_rate' ) );
		$this->assertTrue( flyaffiliate_option_enabled( 'activation_email_enabled' ) );
	}

	/**
	 * A rate the store owner set is not overwritten by a reinstall.
	 *
	 * @return void
	 */
	public function test_it_does_not_overwrite_existing_settings(): void {
		$this->assertTrue( flyaffiliate_update_option( 'default_rate', 25.0 ) );

		( new Installer() )->create_options();

		$this->assertSame( 25.0, flyaffiliate_get_option( 'default_rate' ) );
	}

	/**
	 * The dashboard and registration pages are created.
	 *
	 * @return void
	 */
	public function test_it_creates_the_plugin_pages(): void {
		delete_option( Installer::PAGES_OPTION );

		$installer = new Installer();
		$installer->create_pages();

		foreach ( array_keys( $installer->get_page_definitions() ) as $key ) {
			$page_id = flyaffiliate_get_page_id( $key );

			$this->assertGreaterThan( 0, $page_id, sprintf( 'Page `%s` was not created.', $key ) );
			$this->assertSame( 'publish', get_post_status( $page_id ) );
		}
	}

	/**
	 * Running the installer again does not create a second set of pages.
	 *
	 * @return void
	 */
	public function test_creating_pages_is_idempotent(): void {
		delete_option( Installer::PAGES_OPTION );

		$installer = new Installer();
		$installer->create_pages();

		$first = get_option( Installer::PAGES_OPTION );

		$installer->create_pages();

		$this->assertSame( $first, get_option( Installer::PAGES_OPTION ) );
	}

	/**
	 * A full install can be run twice with no change the second time.
	 *
	 * @return void
	 */
	public function test_a_second_install_changes_nothing(): void {
		$installer = new Installer();
		$installer->do_install();

		$pages    = get_option( Installer::PAGES_OPTION );
		$settings = get_option( SettingsRepository::OPTION_KEY );

		$installer->do_install();

		$this->assertSame( $pages, get_option( Installer::PAGES_OPTION ) );
		$this->assertSame( $settings, get_option( SettingsRepository::OPTION_KEY ) );
		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}

	/**
	 * The schema version is recorded, so the upgrader knows where the site is.
	 *
	 * @return void
	 */
	public function test_it_records_the_schema_version(): void {
		delete_option( Installer::DB_VERSION_OPTION );

		( new Installer() )->do_install();

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}

	/**
	 * A table left behind with `order_item_id NOT NULL` is repaired, because
	 * dbDelta cannot do it and a manual commission needs the NULL.
	 *
	 * @return void
	 */
	public function test_it_makes_order_item_id_nullable_again(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'flyaffiliate_commissions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reproducing the prototype's schema in a test.
		$wpdb->query( "ALTER TABLE {$table} MODIFY order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0" );

		$this->assertSame( 'NO', $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'order_item_id'" )->Null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		( new Installer() )->create_tables();

		$this->assertSame( 'YES', $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'order_item_id'" )->Null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// phpcs:enable

		$affiliate = self::factory()->affiliate->create_and_get_model();
		$result    = flyaffiliate()->commission->create_manual(
			[
				'affiliate_id' => $affiliate->get_id(),
				'amount'       => 5,
			]
		);

		$this->assertNotWPError( $result );
		$this->assertNull( $result->get( 'order_item_id' ) );
	}
}
