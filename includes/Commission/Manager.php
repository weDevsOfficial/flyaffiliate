<?php
/**
 * Commission data access.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Commission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Utilities\Money;
use WP_Error;

/**
 * The one way to read and change commission rows from a screen or a controller.
 *
 * The status rules from CONTEXT.md are enforced here, not in the callers:
 * `paid` is terminal and never changes, `rejected` is terminal, and the only
 * moves are `pending` -> `unpaid`, `pending`/`unpaid` -> `rejected`, and a
 * manual `unpaid` -> `pending` for an admin undoing an early maturation.
 * Marking `paid` is not a status edit at all — it happens only through a payout
 * batch (`Payout\Manager`), which is what sets `payout_id`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * Get a commission by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $commission_id Commission id.
	 *
	 * @return Commission|null
	 */
	public function get( int $commission_id ): ?Commission {
		return Commission::find( $commission_id );
	}

	/**
	 * Query commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::query()}.
	 *
	 * @return Commission[]
	 */
	public function query( array $args = [] ): array {
		return Commission::query( $args );
	}

	/**
	 * Count commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::count()}.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		return Commission::count( $args );
	}

	/**
	 * Row counts per status, plus the total, for status tabs.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $where Extra equality conditions applied to every count.
	 *
	 * @return array<string, int> `all` and one key per status.
	 */
	public function count_by_status( array $where = [] ): array {
		$counts = [ 'all' => $this->count( $where ) ];

		foreach ( array_keys( Commission::get_statuses() ) as $status ) {
			$counts[ $status ] = $this->count( array_merge( $where, [ 'status' => $status ] ) );
		}

		return $counts;
	}

	/**
	 * Add a commission by hand.
	 *
	 * A manual commission has no order item, so `order_item_id` stays NULL and
	 * the unique constraint does not apply. The rate is recorded as the
	 * effective percentage of the base amount so the row reads like every other.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Commission data.
	 *
	 *     @type int    $affiliate_id Required.
	 *     @type float  $amount       Required. What the affiliate earns.
	 *     @type float  $base_amount  The sale amount the commission is on. Default equal to `amount`.
	 *     @type int    $order_id     Optional reference order.
	 *     @type string $status       `pending` or `unpaid`. Default `pending`.
	 *     @type string $created_at   Optional `Y-m-d H:i:s` in GMT.
	 * }
	 *
	 * @return Commission|WP_Error
	 */
	public function create_manual( array $args ) {
		$affiliate_id = absint( $args['affiliate_id'] ?? 0 );

		if ( 0 === $affiliate_id || null === flyaffiliate()->affiliate->get( $affiliate_id ) ) {
			return new WP_Error( 'flyaffiliate_invalid_affiliate', __( 'Choose an affiliate.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$amount = Money::round( (float) ( $args['amount'] ?? 0 ) );

		if ( Money::to_cents( $amount ) <= 0 ) {
			return new WP_Error( 'flyaffiliate_invalid_amount', __( 'The commission amount must be more than zero.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$base   = Money::round( (float) ( $args['base_amount'] ?? $amount ) );
		$base   = Money::to_cents( $base ) > 0 ? $base : $amount;
		$status = in_array( $args['status'] ?? '', [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID ], true )
			? $args['status']
			: Commission::STATUS_PENDING;

		$commission = new Commission();

		$commission->fill(
			[
				'affiliate_id'  => $affiliate_id,
				'order_id'      => absint( $args['order_id'] ?? 0 ),
				'order_item_id' => null,
				'product_id'    => 0,
				'vendor_id'     => 0,
				'base_amount'   => $base,
				'rate'          => round( $amount / $base * 100, 4 ),
				'rate_type'     => Commission::RATE_PERCENTAGE,
				'amount'        => $amount,
				'currency'      => flyaffiliate_get_currency(),
				'source'        => Commission::SOURCE_MANUAL,
				'type'          => Commission::TYPE_SALE,
				'status'        => $status,
				'matures_at'    => Commission::STATUS_UNPAID === $status ? current_time( 'mysql', true ) : $this->maturation_date(),
				'created_at'    => ! empty( $args['created_at'] ) ? (string) $args['created_at'] : current_time( 'mysql', true ),
			]
		);

		if ( 0 === $commission->save() ) {
			return new WP_Error( 'flyaffiliate_create_failed', __( 'The commission could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
		}

		/**
		 * Fires after a commission is created, from any source.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Commission $commission The commission.
		 */
		do_action( 'flyaffiliate_commission_created', $commission );

		return $commission;
	}

	/**
	 * Change a commission's status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $commission_id Commission id.
	 * @param string $status        The status to move to: `pending`, `unpaid` or `rejected`.
	 *
	 * @return Commission|WP_Error
	 */
	public function set_status( int $commission_id, string $status ) {
		$commission = $this->get( $commission_id );

		if ( null === $commission ) {
			return new WP_Error( 'flyaffiliate_commission_not_found', __( 'No commission with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		$from = (string) $commission->get( 'status' );

		if ( $from === $status ) {
			return $commission;
		}

		/*
		 * A commission inside a payment does not move on its own: a refunded
		 * order, an admin, or the maturation job would otherwise change a row
		 * whose amount a payment is already promising.
		 */
		if ( $commission->is_in_payout() ) {
			return $this->locked_error( $commission );
		}

		if ( ! $this->can_transition( $from, $status ) ) {
			return new WP_Error(
				'flyaffiliate_locked_commission',
				Commission::STATUS_PAID === $from
					? __( 'A paid commission cannot be changed.', 'flyaffiliate' )
					: __( 'That status change is not allowed.', 'flyaffiliate' ),
				[ 'status' => 409 ]
			);
		}

		$commission->set( 'status', $status );

		if ( 0 === $commission->save() ) {
			return new WP_Error( 'flyaffiliate_update_failed', __( 'The commission could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
		}

		/**
		 * Fires when a commission's status changes.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Commission $commission The commission.
		 * @param string     $status     The status it is now in.
		 * @param string     $from       The status it was in.
		 */
		do_action( 'flyaffiliate_commission_status_changed', $commission, $status, $from );

		return $commission;
	}

	/**
	 * Change the amount of a manual commission.
	 *
	 * A WooCommerce commission's amount is calculated from its order item and
	 * stays that way; only a commission an admin created by hand can be edited.
	 * The stored rate is re-derived from the base amount to keep the row
	 * self-consistent.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $commission_id Commission id.
	 * @param float $amount        The new amount.
	 *
	 * @return Commission|WP_Error
	 */
	public function set_manual_amount( int $commission_id, float $amount ) {
		$commission = $this->get( $commission_id );

		if ( null === $commission ) {
			return new WP_Error( 'flyaffiliate_commission_not_found', __( 'No commission with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		if ( $commission->is_locked() ) {
			return $this->locked_error( $commission );
		}

		if ( Commission::SOURCE_MANUAL !== $commission->get( 'source' ) ) {
			return new WP_Error( 'flyaffiliate_calculated_commission', __( 'Only a manual commission\'s amount can be edited. A WooCommerce commission is calculated from its order item.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$amount = Money::round( $amount );

		if ( Money::to_cents( $amount ) <= 0 ) {
			return new WP_Error( 'flyaffiliate_invalid_amount', __( 'The commission amount must be more than zero.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$base = (float) $commission->get( 'base_amount', 0 );

		$commission->set( 'amount', $amount );
		$commission->set( 'rate', Money::to_cents( $base ) > 0 ? round( $amount / $base * 100, 4 ) : 100 );

		if ( 0 === $commission->save() ) {
			return new WP_Error( 'flyaffiliate_update_failed', __( 'The commission could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
		}

		return $commission;
	}

	/**
	 * The error for a commission that must not change, saying which lock holds it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return WP_Error
	 */
	protected function locked_error( Commission $commission ): WP_Error {
		if ( $commission->is_in_payout() ) {
			return new WP_Error(
				'flyaffiliate_commission_in_payout',
				__( 'This commission belongs to a payment. Take it out of the payment first, or delete the payment.', 'flyaffiliate' ),
				[ 'status' => 409 ]
			);
		}

		return new WP_Error( 'flyaffiliate_locked_commission', __( 'A paid commission cannot be changed.', 'flyaffiliate' ), [ 'status' => 409 ] );
	}

	/**
	 * Whether a status change is allowed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $from The current status.
	 * @param string $to   The requested status.
	 *
	 * @return bool
	 */
	public function can_transition( string $from, string $to ): bool {
		// A rejected commission can come back, as in SliceWP: its order recovered, or an admin changed their mind.
		$allowed = [
			Commission::STATUS_PENDING  => [ Commission::STATUS_UNPAID, Commission::STATUS_REJECTED ],
			Commission::STATUS_UNPAID   => [ Commission::STATUS_PENDING, Commission::STATUS_REJECTED ],
			Commission::STATUS_REJECTED => [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID ],
		];

		return in_array( $to, $allowed[ $from ] ?? [], true );
	}

	/**
	 * Delete a commission that is not paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $commission_id Commission id.
	 *
	 * @return bool
	 */
	public function delete( int $commission_id ): bool {
		$commission = $this->get( $commission_id );

		if ( null === $commission || $commission->is_locked() ) {
			return false;
		}

		return $commission->delete();
	}

	/**
	 * Money and row totals for one affiliate, split the way the screens show them.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $affiliate_id Affiliate id.
	 * @param string $after        Only commissions created on or after this GMT `Y-m-d H:i:s`.
	 * @param string $before       Only commissions created on or before this GMT `Y-m-d H:i:s`.
	 *
	 * @return array{paid: float, unpaid: float, pending: float, total: float, paid_count: int, unpaid_count: int, pending_count: int, count: int}
	 */
	/**
	 * The same totals for a whole page of affiliates, in one query.
	 *
	 * The list screen shows paid and unpaid earnings per row; asking for them
	 * one affiliate at a time would be a query per row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int[] $affiliate_ids The affiliates to total.
	 *
	 * @return array<int, array> Totals keyed by affiliate id; an affiliate with
	 *                           no commissions gets a zeroed set.
	 */
	public function get_totals_for_affiliates( array $affiliate_ids ): array {
		global $wpdb;

		$affiliate_ids = array_values( array_unique( array_map( 'absint', $affiliate_ids ) ) );
		$totals        = [];

		foreach ( $affiliate_ids as $affiliate_id ) {
			$totals[ $affiliate_id ] = $this->empty_totals();
		}

		if ( empty( $affiliate_ids ) ) {
			return $totals;
		}

		$table        = Commission::get_table();
		$placeholders = implode( ', ', array_fill( 0, count( $affiliate_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FlyAffiliate's own table; the interpolations are its name and a list of %d placeholders whose values are passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, status, COUNT(*) AS rows_count, COALESCE( SUM( amount ), 0 ) AS total
				FROM {$table}
				WHERE affiliate_id IN ( {$placeholders} )
				GROUP BY affiliate_id, status",
				$affiliate_ids
			)
		);
		// phpcs:enable

		foreach ( $rows as $row ) {
			$affiliate_id = (int) $row->affiliate_id;
			$status       = (string) $row->status;
			$count        = (int) $row->rows_count;
			$amount       = (float) $row->total;

			if ( ! isset( $totals[ $affiliate_id ] ) ) {
				continue;
			}

			$totals[ $affiliate_id ]['count'] += $count;

			if ( ! array_key_exists( $status, $totals[ $affiliate_id ] ) ) {
				continue;
			}

			$totals[ $affiliate_id ][ $status ]                 = $amount;
			$totals[ $affiliate_id ][ $status . '_count' ]      = $count;
			$totals[ $affiliate_id ]['total']                   = Money::from_cents(
				Money::to_cents( $totals[ $affiliate_id ]['total'] ) + Money::to_cents( $amount )
			);
		}

		return $totals;
	}

	/**
	 * A zeroed totals set, in the shape get_affiliate_totals() returns.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array The zeroed totals.
	 */
	protected function empty_totals(): array {
		return [
			'paid'          => 0.0,
			'unpaid'        => 0.0,
			'pending'       => 0.0,
			'total'         => 0.0,
			'paid_count'    => 0,
			'unpaid_count'  => 0,
			'pending_count' => 0,
			'count'         => 0,
		];
	}

	public function get_affiliate_totals( int $affiliate_id, string $after = '', string $before = '' ): array {
		global $wpdb;

		$table = Commission::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FlyAffiliate's own table; the interpolations are its name and a WHERE built only from literal clauses with %d/%s placeholders, whose values are passed to prepare().
		$where  = 'affiliate_id = %d';
		$values = [ $affiliate_id ];

		if ( '' !== $after ) {
			$where   .= ' AND created_at >= %s';
			$values[] = $after;
		}

		if ( '' !== $before ) {
			$where   .= ' AND created_at <= %s';
			$values[] = $before;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS rows_count, COALESCE( SUM( amount ), 0 ) AS total FROM {$table} WHERE {$where} GROUP BY status",
				$values
			),
			OBJECT_K
		);
		// phpcs:enable

		$sum = static function ( string $status ) use ( $rows ): float {
			return isset( $rows[ $status ] ) ? (float) $rows[ $status ]->total : 0.0;
		};

		$num = static function ( string $status ) use ( $rows ): int {
			return isset( $rows[ $status ] ) ? (int) $rows[ $status ]->rows_count : 0;
		};

		$paid    = $sum( Commission::STATUS_PAID );
		$unpaid  = $sum( Commission::STATUS_UNPAID );
		$pending = $sum( Commission::STATUS_PENDING );

		return [
			'paid'          => $paid,
			'unpaid'        => $unpaid,
			'pending'       => $pending,
			'total'         => Money::from_cents( Money::to_cents( $paid ) + Money::to_cents( $unpaid ) + Money::to_cents( $pending ) ),
			'paid_count'    => $num( Commission::STATUS_PAID ),
			'unpaid_count'  => $num( Commission::STATUS_UNPAID ),
			'pending_count' => $num( Commission::STATUS_PENDING ),
			'count'         => array_sum( array_map( static fn( $row ) => (int) $row->rows_count, $rows ) ),
		];
	}

	/**
	 * Sum of commissions per affiliate, for the statuses given.
	 *
	 * Used to sort the affiliates list by earnings. One grouped query for the
	 * whole table; the map is small enough to order in PHP at the scale a
	 * single-site plugin runs at.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string[] $statuses Statuses to include.
	 *
	 * @return array<int, float> Affiliate id => amount.
	 */
	public function sum_by_affiliate( array $statuses ): array {
		global $wpdb;

		$statuses = array_values( array_intersect( $statuses, array_keys( Commission::get_statuses() ) ) );

		if ( [] === $statuses ) {
			return [];
		}

		$table        = Commission::get_table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FlyAffiliate's own table; the IN list is one %s per status, and every status was validated against the model's list.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT affiliate_id, COALESCE( SUM( amount ), 0 ) AS total FROM {$table} WHERE status IN ({$placeholders}) GROUP BY affiliate_id", $statuses )
		);
		// phpcs:enable

		$sums = [];

		foreach ( (array) $rows as $row ) {
			$sums[ (int) $row->affiliate_id ] = (float) $row->total;
		}

		return $sums;
	}

	/**
	 * The date a commission created now would mature on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $created_at The creation time in GMT. Defaults to now.
	 *
	 * @return string `Y-m-d H:i:s` in GMT.
	 */
	public function maturation_date( string $created_at = '' ): string {
		$hold_days = absint( flyaffiliate_get_option( 'hold_days', 30 ) );
		$base      = '' !== $created_at ? strtotime( $created_at . ' UTC' ) : time();

		return gmdate( 'Y-m-d H:i:s', $base + $hold_days * DAY_IN_SECONDS );
	}
}
