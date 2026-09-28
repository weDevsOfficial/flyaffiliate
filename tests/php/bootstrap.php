<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads WooCommerce, loads Dokan when it is installed, then FlyAffiliate, into
 * the WordPress test environment.
 *
 * Dokan is optional on purpose: CI runs one leg of the matrix with Dokan absent,
 * so a `@group dokan` test that forgot to skip itself fails there rather than
 * passing by accident.
 *
 * @package FlyAffiliate
 */

define( 'FLYAFFILIATE_TESTS_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'FLYAFFILIATE_TESTS_PLUGINS_DIR', dirname( FLYAFFILIATE_TESTS_PLUGIN_DIR ) );

require_once FLYAFFILIATE_TESTS_PLUGIN_DIR . '/vendor/autoload.php';

/**
 * Find a plugin's directory, trying the names it is installed under.
 *
 * WooCommerce is always `woocommerce`. Dokan Lite is `dokan-lite` when installed
 * from WordPress.org and `dokan` in a development checkout, so both are tried.
 *
 * @param string[] $candidates Directory names to try, in order.
 *
 * @return string The absolute path, or an empty string when none exists.
 */
function flyaffiliate_tests_locate_plugin( array $candidates ): string {
	foreach ( $candidates as $candidate ) {
		$path = FLYAFFILIATE_TESTS_PLUGINS_DIR . '/' . $candidate;

		if ( is_dir( $path ) ) {
			return $path;
		}
	}

	return '';
}

$flyaffiliate_wc_dir    = flyaffiliate_tests_locate_plugin( [ 'woocommerce' ] );
$flyaffiliate_dokan_dir = flyaffiliate_tests_locate_plugin( [ 'dokan-lite', 'dokan' ] );

if ( '' === $flyaffiliate_wc_dir || ! file_exists( $flyaffiliate_wc_dir . '/woocommerce.php' ) ) {
	echo 'WooCommerce was not found next to this plugin. Run `npm run env:start` first.' . PHP_EOL;
	exit( 1 );
}

define( 'FLYAFFILIATE_TESTS_WC_DIR', $flyaffiliate_wc_dir );
define( 'FLYAFFILIATE_TESTS_DOKAN_DIR', $flyaffiliate_dokan_dir );

/**
 * Whether the suite is running with Dokan available.
 *
 * `@group dokan` tests skip themselves when this is false.
 */
define( 'FLYAFFILIATE_TESTS_HAS_DOKAN', '' !== $flyaffiliate_dokan_dir && file_exists( $flyaffiliate_dokan_dir . '/dokan.php' ) );

$flyaffiliate_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : getenv( 'WP_PHPUNIT__DIR' );

if ( ! $flyaffiliate_tests_dir ) {
	$flyaffiliate_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $flyaffiliate_tests_dir . '/includes/functions.php' ) ) {
	echo "The WordPress test library was not found in {$flyaffiliate_tests_dir}." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo 'Run `npm run phpunit`, which runs inside wp-env, or provision one with bin/install-wp-tests.sh.' . PHP_EOL;
	exit( 1 );
}

require_once $flyaffiliate_tests_dir . '/includes/functions.php';

// HPOS on, for every test. FlyAffiliate must never read an order through
// post meta, and a suite running on the legacy storage would not catch it.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		add_filter(
			'woocommerce_feature_enabled',
			static function ( $enabled, $feature ) {
				return 'custom_order_tables' === $feature ? true : $enabled;
			},
			10,
			2
		);

		add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', '__return_true' );
		add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', '__return_true' );
	},
	1
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		defined( 'WC_TAX_ROUNDING_MODE' ) || define( 'WC_TAX_ROUNDING_MODE', 'auto' );
		defined( 'WC_USE_TRANSACTIONS' ) || define( 'WC_USE_TRANSACTIONS', false );

		require FLYAFFILIATE_TESTS_WC_DIR . '/woocommerce.php';

		if ( FLYAFFILIATE_TESTS_HAS_DOKAN ) {
			require FLYAFFILIATE_TESTS_DOKAN_DIR . '/dokan.php';
		}

		require FLYAFFILIATE_TESTS_PLUGIN_DIR . '/flyaffiliate.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		defined( 'WC_REMOVE_ALL_DATA' ) || define( 'WC_REMOVE_ALL_DATA', true );

		include FLYAFFILIATE_TESTS_WC_DIR . '/uninstall.php';

		WC_Install::install();

		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );

		WC_Install::create_tables();

		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

			if ( method_exists( $controller, 'create_database_tables' ) ) {
				$controller->create_database_tables();
			}
		}

		// Roles gained capabilities during install; rebuild the cached singleton so
		// the first test sees the same roles as the hundredth.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();

		echo 'Installed WooCommerce with HPOS enabled.' . PHP_EOL;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( FLYAFFILIATE_TESTS_HAS_DOKAN && function_exists( 'dokan' ) ) {
			dokan()->activate();
			echo 'Installed Dokan.' . PHP_EOL;
		} else {
			echo 'Running without Dokan: @group dokan tests will skip.' . PHP_EOL;
		}

		flyaffiliate()->activate();

		// Empty the tables once, here, outside any test transaction. It cannot go
		// in the test case: WordPress wraps each test in a transaction and rolls it
		// back, and TRUNCATE forces an implicit commit that would end it. Written
		// inline rather than calling FlyAffiliateTestCase, whose parent
		// WP_UnitTestCase does not exist until the wp-phpunit bootstrap finishes.
		global $wpdb;

		foreach ( FlyAffiliate\Install\Installer::get_table_names() as $flyaffiliate_table ) {
			$flyaffiliate_table_name = $wpdb->prefix . $flyaffiliate_table;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $flyaffiliate_table_name ) ) === $flyaffiliate_table_name ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is $wpdb->prefix plus a name from Installer's hard-coded list, checked to exist above.
				$wpdb->query( "TRUNCATE TABLE {$flyaffiliate_table_name}" );
			}
		}

		echo 'Installed FlyAffiliate.' . PHP_EOL;
	}
);

require $flyaffiliate_tests_dir . '/includes/bootstrap.php';
