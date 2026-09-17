<?php
/**
 * Maturation: pending commissions becoming unpaid.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Commission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Install\Installer;
use FlyAffiliate\Models\Commission;
use WC_Order;

/**
 * Moves commissions from `pending` to `unpaid` once their hold is over.
 *
 * A WooCommerce commission also needs its order to be processing or completed;
 * an order that never got there never matures. A manual commission has no
 * order and matures on the date alone.
 *
 * Maturation happens as soon as both conditions hold, the way SliceWP marks a
 * commission unpaid when its order completes: when the order reaches a paid
 * status, right after checkout for an order that is already paid, and from the
 * daily Action Scheduler job for holds that end later. Changing the hold
 * period moves every pending commission's maturity date with it.
 *
 * A commission an admin adds by hand is left with the status it was given,
 * as in SliceWP: a pending one waits for its order to reach a paid status,
 * or for the job once its hold is over, rather than maturing on the spot.
 *
 * @since FLYAFFILIATE_SINCE
 */
class HoldPeriod implements Hookable {

	/**
	 * Rows read per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 200;

	/**
	 * Order statuses a commission may mature under.
	 *
	 * @var string[]
	 */
	const MATURE_ORDER_STATUSES = [ 'processing', 'completed' ];

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( Installer::MATURATION_HOOK, [ $this, 'run' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 20, 4 );
		add_action( 'flyaffiliate_order_attributed', [ $this, 'mature_for_order' ], 20 );
		add_action( 'flyaffiliate_after_save_settings', [ $this, 'handle_settings_saved' ] );
	}

	/**
	 * Mature every commission whose hold is over.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int How many commissions matured.
	 */
	public function run(): int {
		$matured = $this->mature_where( [ 'status' => Commission::STATUS_PENDING ] );

		/**
		 * Fires after the maturation job ran.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int $matured How many commissions became unpaid.
		 */
		do_action( 'flyaffiliate_commissions_matured', $matured );

		return $matured;
	}

	/**
	 * Mature an order's commissions when the order reaches a paid status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int           $order_id The order.
	 * @param string        $from     The previous status.
	 * @param string        $to       The new status.
	 * @param WC_Order|null $order    The order, when WooCommerce passes it.
	 *
	 * @return int How many commissions matured.
	 */
	public function handle_order_status_change( $order_id, $from, $to, $order = null ): int {
		if ( ! in_array( (string) $to, $this->get_mature_order_statuses(), true ) ) {
			return 0;
		}

		return $this->mature_for_order( $order instanceof WC_Order ? $order : (int) $order_id );
	}

	/**
	 * Mature the due commissions of one order.
	 *
	 * Also runs right after checkout attribution: an order that is already
	 * processing when its commissions are created (a card payment through the
	 * block checkout, say) changed status before the rows existed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order|int $order The order or its id.
	 *
	 * @return int How many commissions matured.
	 */
	public function mature_for_order( $order ): int {
		$order_id = $order instanceof WC_Order ? $order->get_id() : absint( $order );

		if ( 0 === $order_id ) {
			return 0;
		}

		return $this->mature_where(
			[
				'status'   => Commission::STATUS_PENDING,
				'source'   => Commission::SOURCE_WOOCOMMERCE,
				'order_id' => $order_id,
			]
		);
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
		$this->run();
	}

	/**
	 * Recompute the maturity date of every commission that can still mature.
	 *
	 * Money rule 4 matures a commission at `created_at + hold_days`, so the
	 * stored date follows the setting instead of the value it had when the
	 * commission was created. Rejected commissions move too: one whose order
	 * recovers goes back to pending and must mature on the current hold.
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
				"UPDATE {$table} SET matures_at = DATE_ADD( created_at, INTERVAL %d DAY ) WHERE status IN ( %s, %s ) AND created_at IS NOT NULL",
				max( 0, $hold_days ),
				Commission::STATUS_PENDING,
				Commission::STATUS_REJECTED
			)
		);
		// phpcs:enable

		return (int) $updated;
	}

	/**
	 * Whether a due commission may become unpaid now.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return bool
	 */
	public function can_mature( Commission $commission ): bool {
		$order_id = (int) $commission->get( 'order_id', 0 );

		if ( Commission::SOURCE_WOOCOMMERCE !== $commission->get( 'source' ) || 0 === $order_id ) {
			return true;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		return $order instanceof WC_Order && $this->order_can_mature( $order );
	}

	/**
	 * Whether an order's commissions may mature under its current status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return bool
	 */
	public function order_can_mature( WC_Order $order ): bool {
		$status = $order->get_status();

		// Cash on delivery sits in processing before any money has arrived; SliceWP waits for completed too.
		if ( 'processing' === $status && 'cod' === $order->get_payment_method() ) {
			return false;
		}

		return in_array( $status, $this->get_mature_order_statuses(), true );
	}

	/**
	 * The order statuses a commission may mature under.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	protected function get_mature_order_statuses(): array {
		/**
		 * Filters the order statuses a commission may mature under.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string[] $statuses Default processing and completed.
		 */
		return (array) apply_filters( 'flyaffiliate_mature_order_statuses', self::MATURE_ORDER_STATUSES );
	}

	/**
	 * Mature every due commission matching the conditions, a page at a time.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $where Column conditions; `status` should be pending.
	 *
	 * @return int How many commissions matured.
	 */
	protected function mature_where( array $where ): int {
		$now     = current_time( 'mysql', true );
		$matured = 0;
		$blocked = [];
		$page    = 1;

		do {
			$batch = flyaffiliate()->commission->query(
				[
					'where'       => $where,
					'date_column' => 'matures_at',
					'before'      => $now,
					'orderby'     => 'id',
					'order'       => 'ASC',
					'per_page'    => static::PER_PAGE,
					'page'        => $page,
				]
			);

			$fetched = count( $batch );

			foreach ( $batch as $commission ) {
				if ( ! $this->can_mature( $commission ) ) {
					$blocked[ $commission->get_id() ] = true;
					continue;
				}

				$result = flyaffiliate()->commission->set_status( $commission->get_id(), Commission::STATUS_UNPAID, true );

				if ( ! is_wp_error( $result ) ) {
					++$matured;
				} else {
					$blocked[ $commission->get_id() ] = true;
				}
			}

			/*
			 * Matured rows leave the result set and the blocked ones stay, all
			 * of them ahead of whatever is still unread, so the offset is how
			 * many distinct rows are stuck — not how many times we skipped one.
			 */
			$page = 1 + intdiv( count( $blocked ), static::PER_PAGE );
		} while ( static::PER_PAGE === $fetched );

		return $matured;
	}
}
