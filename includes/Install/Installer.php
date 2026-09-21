<?php
/**
 * Installation: tables, options, pages and the scheduled job.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Repository\SettingsRepository;
use FlyAffiliate\Admin\SetupWizard;
use FlyAffiliate\Admin\Settings\Schema\SettingsSchema;
use FlyAffiliate\Affiliate\Role;
use FlyAffiliate\Models\Affiliate;

/**
 * Runs on activation and on an upgrade that changes the schema.
 *
 * Everything here is idempotent: `dbDelta` reconciles the tables against the
 * definitions below, options are only written when absent, pages are only
 * created when the stored id no longer resolves, and the recurring job is only
 * scheduled when it is not already scheduled. Running `do_install()` twice
 * changes nothing the second time.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Installer {

	/**
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'flyaffiliate_db_version';

	/**
	 * The schema version this code expects. Bump it when a table changes, and add
	 * the matching upgrader under `Upgrade\Upgrades`.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option holding the ids of the pages this plugin created.
	 *
	 * @var string
	 */
	const PAGES_OPTION = 'flyaffiliate_pages';

	/**
	 * The recurring Action Scheduler hook that matures commissions.
	 *
	 * @var string
	 */
	const MATURATION_HOOK = 'flyaffiliate_mature_commissions';

	/**
	 * Install or repair everything the plugin needs to run.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function do_install(): void {
		$this->create_tables();
		$this->create_options();
		$this->create_roles();
		$this->create_pages();
		$this->schedule_events();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// The first admin page load after activation opens the setup wizard, as Dokan's does.
		SetupWizard::schedule_redirect();

		/**
		 * Fires after FlyAffiliate has finished installing or repairing itself.
		 *
		 * @since FLYAFFILIATE_SINCE
		 */
		do_action( 'flyaffiliate_installed' );
	}

	/**
	 * Create or reconcile the custom tables.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$wpdb->hide_errors();

		foreach ( $this->get_schema() as $statement ) {
			dbDelta( $statement );
		}

		$this->repair_nullable_columns();
	}

	/**
	 * Columns that must accept NULL, table => column => definition.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function get_nullable_columns(): array {
		return [
			'flyaffiliate_commissions' => [
				// A manual commission has no order item (CONTEXT.md); the unique
				// key still holds because MySQL ignores NULLs in unique indexes.
				'order_item_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
			],
		];
	}

	/**
	 * Make the columns that must accept NULL nullable when they are not.
	 *
	 * dbDelta adds missing columns and widens existing ones, but it never turns
	 * a `NOT NULL` column into a nullable one. A site whose table was created
	 * by the prototype therefore keeps `order_item_id NOT NULL`, and every
	 * manual commission insert fails. This runs after dbDelta on every install
	 * or repair, and is a no-op once the column is right.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	protected function repair_nullable_columns(): void {
		global $wpdb;

		foreach ( $this->get_nullable_columns() as $table => $columns ) {
			$table_name = $wpdb->prefix . $table;

			foreach ( $columns as $column => $definition ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema repair on this plugin's own table; the table and column names come from the hard-coded list above, the column name is a placeholder.
				$current = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", $column ) );

				if ( null === $current || 'NO' !== ( $current->Null ?? '' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's column name.
					continue;
				}

				$wpdb->query( "ALTER TABLE {$table_name} MODIFY {$column} {$definition}" );
				// phpcs:enable
			}
		}
	}

	/**
	 * The `CREATE TABLE` statements, in the form `dbDelta` expects.
	 *
	 * dbDelta is strict about formatting: two spaces after PRIMARY KEY, one
	 * field per line, KEY names present. Money columns are DECIMAL(19,4) —
	 * never FLOAT, and never DECIMAL(10,2), which cannot hold four decimal
	 * places of a rate applied to a base amount.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public function get_schema(): array {
		global $wpdb;

		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';
		$prefix  = $wpdb->prefix;

		$tables = [];

		$tables[] = "CREATE TABLE {$prefix}flyaffiliate_affiliates (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	status VARCHAR(20) NOT NULL DEFAULT 'pending',
	payment_email VARCHAR(191) NOT NULL DEFAULT '',
	promo_method TEXT NULL,
	activation_key VARCHAR(64) NOT NULL DEFAULT '',
	activation_expires_at DATETIME NULL DEFAULT NULL,
	created_at DATETIME NULL DEFAULT NULL,
	updated_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY user_id (user_id),
	KEY status (status),
	KEY activation_key (activation_key)
) {$collate};";

		$tables[] = "CREATE TABLE {$prefix}flyaffiliate_commissions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	order_item_id BIGINT UNSIGNED NULL DEFAULT NULL,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	base_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
	rate DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
	rate_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
	amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
	currency VARCHAR(10) NOT NULL DEFAULT '',
	source VARCHAR(50) NOT NULL DEFAULT 'woocommerce',
	type VARCHAR(20) NOT NULL DEFAULT 'sale',
	status VARCHAR(20) NOT NULL DEFAULT 'pending',
	payout_id BIGINT UNSIGNED NULL DEFAULT NULL,
	matures_at DATETIME NULL DEFAULT NULL,
	created_at DATETIME NULL DEFAULT NULL,
	updated_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY order_item_id (order_item_id),
	KEY order_id (order_id),
	KEY affiliate_status (affiliate_id,status),
	KEY status_matures_at (status,matures_at),
	KEY vendor_status (vendor_id,status),
	KEY payout_id (payout_id)
) {$collate};";

		$tables[] = "CREATE TABLE {$prefix}flyaffiliate_visits (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	url TEXT NULL,
	referrer TEXT NULL,
	ip_hash VARCHAR(64) NOT NULL DEFAULT '',
	user_agent_hash VARCHAR(64) NOT NULL DEFAULT '',
	converted TINYINT(1) NOT NULL DEFAULT 0,
	order_id BIGINT UNSIGNED NULL DEFAULT NULL,
	created_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY affiliate_created (affiliate_id,created_at),
	KEY created_at (created_at),
	KEY order_id (order_id)
) {$collate};";

		$tables[] = "CREATE TABLE {$prefix}flyaffiliate_payouts (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	batch_key VARCHAR(64) NOT NULL DEFAULT '',
	affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
	currency VARCHAR(10) NOT NULL DEFAULT '',
	method VARCHAR(20) NOT NULL DEFAULT 'manual',
	status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
	reference VARCHAR(191) NOT NULL DEFAULT '',
	note TEXT NULL,
	period_start DATETIME NULL DEFAULT NULL,
	period_end DATETIME NULL DEFAULT NULL,
	created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
	paid_at DATETIME NULL DEFAULT NULL,
	created_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY batch_key (batch_key),
	KEY affiliate_id (affiliate_id)
) {$collate};";

		return $tables;
	}

	/**
	 * Write the default settings when the site has none, and the defaults of
	 * any setting added since.
	 *
	 * A stored value is never overwritten: a reinstall, a repair or an upgrade
	 * must not change a rate a store owner set. Only missing keys are added,
	 * which freezes a default read from WooCommerce (the currency) at the value
	 * it had when the setting arrived.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function create_options(): void {
		$stored = get_option( SettingsRepository::OPTION_KEY, false );

		if ( false === $stored ) {
			add_option( SettingsRepository::OPTION_KEY, SettingsSchema::get_defaults(), '', true );

			return;
		}

		// A setting added since the site installed is written once, so a default
		// read from WooCommerce (the currency, say) stops following WooCommerce.
		$missing = array_diff_key( SettingsSchema::get_defaults(), (array) $stored );

		if ( [] !== $missing ) {
			update_option( SettingsRepository::OPTION_KEY, array_merge( (array) $stored, $missing ), true );
		}
	}

	/**
	 * Register the affiliate role and give it to every affiliate's user.
	 *
	 * The backfill covers sites that had affiliates before the role existed;
	 * from then on {@see Role} keeps the two in step.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function create_roles(): void {
		Role::register();

		$role = new Role();
		$page = 1;

		do {
			$affiliates = Affiliate::query(
				[
					'per_page' => 200,
					'page'     => $page,
					'orderby'  => 'id',
					'order'    => 'ASC',
				]
			);

			foreach ( $affiliates as $affiliate ) {
				$role->add( (int) $affiliate->get( 'user_id', 0 ) );
			}

			$found = count( $affiliates );
			++$page;
		} while ( 200 === $found );
	}

	/**
	 * The pages the plugin needs, and the shortcode each one holds.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	public function get_page_definitions(): array {
		return [
			'affiliate_dashboard' => [
				'title'   => __( 'Affiliate Dashboard', 'flyaffiliate' ),
				'content' => '[flyaffiliate_dashboard]',
			],
			'affiliate_register'  => [
				'title'   => __( 'Affiliate Registration', 'flyaffiliate' ),
				'content' => '[flyaffiliate_register]',
			],
		];
	}

	/**
	 * Create the plugin's pages, if they are missing.
	 *
	 * A page whose stored id still resolves to a published page is left alone, so
	 * reactivating the plugin does not litter the site with duplicates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function create_pages(): void {
		$stored = get_option( self::PAGES_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $this->get_page_definitions() as $key => $page ) {
			$existing = isset( $stored[ $key ] ) ? get_post( (int) $stored[ $key ] ) : null;

			if ( $existing instanceof \WP_Post && 'trash' !== $existing->post_status ) {
				continue;
			}

			$page_id = wp_insert_post(
				[
					'post_title'     => $page['title'],
					'post_content'   => $page['content'],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				]
			);

			if ( ! is_wp_error( $page_id ) ) {
				$stored[ $key ] = (int) $page_id;
			}
		}

		update_option( self::PAGES_OPTION, $stored );
	}

	/**
	 * Schedule the daily maturation job.
	 *
	 * Action Scheduler, which WooCommerce bundles, rather than WP-Cron: the job
	 * pages through commissions and must survive a request that dies halfway.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function schedule_events(): void {
		// Action Scheduler ships with WooCommerce. Without it, WP-Cron runs the
		// daily job; the hook is the same, so `HoldPeriod` does not care which.
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! wp_next_scheduled( self::MATURATION_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MATURATION_HOOK );
			}

			return;
		}

		// Action Scheduler's data store is set up on `init`. Activation from an
		// ordinary admin request is well past that, but WP-CLI and the test
		// bootstrap can get here first, and calling it early is a _doing_it_wrong.
		if ( ! did_action( 'action_scheduler_init' ) ) {
			add_action( 'action_scheduler_init', [ $this, 'schedule_events' ] );

			return;
		}

		if ( as_has_scheduled_action( self::MATURATION_HOOK ) ) {
			return;
		}

		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::MATURATION_HOOK, [], 'flyaffiliate' );
	}

	/**
	 * Cancel the scheduled job. Called on deactivation.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public static function clear_scheduled_events(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::MATURATION_HOOK );
		}

		wp_clear_scheduled_hook( self::MATURATION_HOOK );
	}

	/**
	 * The table names this plugin owns, without the prefix.
	 *
	 * Used by the uninstaller, and by the test harness to truncate between tests.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public static function get_table_names(): array {
		return [
			'flyaffiliate_affiliates',
			'flyaffiliate_commissions',
			'flyaffiliate_visits',
			'flyaffiliate_payouts',
		];
	}
}
