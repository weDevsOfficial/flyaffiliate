<?php
/**
 * Uninstall FlyAffiliate.
 *
 * Runs only when the plugin is deleted from the Plugins screen, with the plugin
 * itself not loaded. Nothing is removed unless the site owner asked for it: the
 * "clear data on uninstall" setting is off by default, so deleting the plugin
 * leaves the commission ledger intact for a reinstall.
 *
 * @package FlyAffiliate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Autoloader.php';

FlyAffiliate\Autoloader::register( __DIR__ . '/includes' );

$flyaffiliate_settings = get_option( FlyAffiliate\Admin\Settings\Repository\SettingsRepository::OPTION_KEY, [] );
$flyaffiliate_settings = is_array( $flyaffiliate_settings ) ? $flyaffiliate_settings : [];

// Switches store 'on' / 'off'; anything else means the owner never asked for a wipe.
if ( 'on' !== ( $flyaffiliate_settings['data_clear_on_uninstall'] ?? 'off' ) ) {
	return;
}

global $wpdb;

// The recurring maturation job, if Action Scheduler is still around.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( FlyAffiliate\Install\Installer::MATURATION_HOOK );
}

// The pages the installer created.
$flyaffiliate_pages = get_option( FlyAffiliate\Install\Installer::PAGES_OPTION, [] );

if ( is_array( $flyaffiliate_pages ) ) {
	foreach ( $flyaffiliate_pages as $flyaffiliate_page_id ) {
		wp_delete_post( (int) $flyaffiliate_page_id, true );
	}
}

// The custom tables.
foreach ( FlyAffiliate\Install\Installer::get_table_names() as $flyaffiliate_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- dropping this plugin's own tables is the point of uninstall; the names come from a hard-coded list in Installer, never from input.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $flyaffiliate_table );
}

// The affiliate role. Users who had it keep an unknown role name, which WordPress ignores.
remove_role( FlyAffiliate\Affiliate\Role::ROLE );

// The options: the settings payload plus the plugin's own bookkeeping.
$flyaffiliate_options = [
	FlyAffiliate\Admin\Settings\Repository\SettingsRepository::OPTION_KEY,
	FlyAffiliate\Install\Installer::DB_VERSION_OPTION,
	FlyAffiliate\Install\Installer::PAGES_OPTION,
	'flyaffiliate_setup_wizard_done',
];

foreach ( $flyaffiliate_options as $flyaffiliate_option ) {
	delete_option( $flyaffiliate_option );
}

// Per-user notice dismissals and any other user meta this plugin wrote. A meta_key
// LIKE sweep is the only way to reach them: there is no WordPress API for
// "delete this meta key for every user".
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_flyaffiliate_' ) . '%'
	)
);

// Product-level rate overrides, swept the same way and for the same reason.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_flyaffiliate_' ) . '%'
	)
);

// Order attribution meta. Under HPOS this lives in WooCommerce's own meta table,
// not in postmeta, and that table only exists when HPOS has been enabled.
$flyaffiliate_hpos_meta = $wpdb->prefix . 'wc_orders_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking for a table's existence has no WordPress API.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $flyaffiliate_hpos_meta ) ) === $flyaffiliate_hpos_meta ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the table name is $wpdb->prefix plus a literal; the meta key is a prepared placeholder.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$flyaffiliate_hpos_meta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is $wpdb->prefix plus a literal, checked to exist above.
			$wpdb->esc_like( '_flyaffiliate_' ) . '%'
		)
	);
}

wp_cache_flush();
