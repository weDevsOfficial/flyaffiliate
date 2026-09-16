<?php
/**
 * Schema and data upgrades.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Upgrade;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Install\Installer;

/**
 * Decides whether the installed schema is behind the code, and runs the
 * upgraders that close the gap.
 *
 * An upgrader is a class under `Upgrade\Upgrades` keyed by the schema version it
 * brings the site **to**. They run in version order, each exactly once, and the
 * stored version is only advanced after the whole set succeeds — a run that dies
 * halfway is retried on the next request rather than skipped.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager implements Hookable {

	/**
	 * Transient that keeps two concurrent requests from upgrading at once.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'flyaffiliate_upgrading';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );
	}

	/**
	 * The schema version currently installed.
	 *
	 * A site with tables but no version option predates the option; it is treated
	 * as 0.0.0 so every upgrader runs.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_installed_version(): string {
		return (string) get_option( Installer::DB_VERSION_OPTION, '0.0.0' );
	}

	/**
	 * Whether the installed schema is behind the code.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function needs_upgrade(): bool {
		return version_compare( $this->get_installed_version(), Installer::DB_VERSION, '<' );
	}

	/**
	 * The upgraders, keyed by the version each brings the site to.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, class-string>
	 */
	public function get_upgraders(): array {
		/**
		 * Filters the list of FlyAffiliate schema upgraders.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, class-string> $upgraders Version => upgrader class name.
		 */
		return apply_filters( 'flyaffiliate_upgraders', [] );
	}

	/**
	 * Run any upgraders the site is behind on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->needs_upgrade() ) {
			return;
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$this->run_upgrades();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Reconcile the tables, run the pending upgraders, record the new version.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function run_upgrades(): void {
		$installed = $this->get_installed_version();
		$upgraders = $this->get_upgraders();

		uksort( $upgraders, 'version_compare' );

		// dbDelta first: an upgrader may need a column that the new schema adds.
		$installer = new Installer();
		$installer->create_tables();

		// Settings added since the last version get their defaults stored.
		$installer->create_options();

		// The affiliate role, and every affiliate from before it existed.
		$installer->create_roles();

		foreach ( $upgraders as $version => $class_name ) {
			if ( version_compare( $installed, $version, '>=' ) ) {
				continue;
			}

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$upgrader = new $class_name();

			if ( is_callable( [ $upgrader, 'upgrade' ] ) ) {
				$upgrader->upgrade();
			}

			/**
			 * Fires after one FlyAffiliate upgrader has run.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param string $version The schema version the upgrader brought the site to.
			 */
			do_action( 'flyaffiliate_upgraded_to', $version );
		}

		update_option( Installer::DB_VERSION_OPTION, Installer::DB_VERSION );

		/**
		 * Fires after every pending FlyAffiliate upgrader has run.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $from The schema version the site was on.
		 * @param string $to   The schema version it is now on.
		 */
		do_action( 'flyaffiliate_upgrade_complete', $installed, Installer::DB_VERSION );
	}
}
