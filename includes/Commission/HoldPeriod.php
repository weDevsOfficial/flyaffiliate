<?php
/**
 * The hold period: how long a commission waits before a payout may take it.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Commission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;

/**
 * Keeps every commission's maturity date in step with the `hold_days` setting.
 *
 * The hold period gates payouts, not statuses (ADR-0014): a commission
 * becomes unpaid when its order is paid, and `Payout\Manager::preview()` takes
 * it only once `matures_at` — `created_at + hold_days` — is in the past. This
 * class owns that date. Changing the setting moves the date on every
 * commission that can still be paid; paid ones keep theirs.
 *
 * @since FLYAFFILIATE_SINCE
 */
class HoldPeriod implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'flyaffiliate_after_save_settings', [ $this, 'handle_settings_saved' ] );
	}

	/**
	 * Follow a change of the hold period.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $sanitized The values that were saved.
	 *
	 * @return void
	 */
	public function handle_settings_saved( $sanitized ): void {
		if ( ! is_array( $sanitized ) || ! array_key_exists( 'hold_days', $sanitized ) ) {
			return;
		}

		$this->reschedule( absint( $sanitized['hold_days'] ) );
	}

	/**
	 * Recompute the maturity date of every commission a payout may still take.
	 *
	 * Money rule 4 makes a commission payable at `created_at + hold_days`, so
	 * the stored date follows the setting instead of the value it had when the
	 * commission was created. Pending and rejected rows move too: one whose
	 * order is paid later becomes unpaid and must wait out the current hold.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $hold_days The hold period in days.
	 *
	 * @return int How many rows were updated.
	 */
	public function reschedule( int $hold_days ): int {
		global $wpdb;

		$table = Commission::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlyAffiliate's own table; the only interpolation is its name, the values are placeholders.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET matures_at = DATE_ADD( created_at, INTERVAL %d DAY ) WHERE status IN ( %s, %s, %s ) AND created_at IS NOT NULL AND ( matures_at IS NULL OR matures_at <> DATE_ADD( created_at, INTERVAL %d DAY ) )",
				max( 0, $hold_days ),
				Commission::STATUS_PENDING,
				Commission::STATUS_UNPAID,
				Commission::STATUS_REJECTED,
				max( 0, $hold_days )
			)
		);
		// phpcs:enable

		return (int) $updated;
	}
}
