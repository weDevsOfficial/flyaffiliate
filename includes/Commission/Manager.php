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
 * The rules from CONTEXT.md are enforced here, not in the callers. The lock is
 * the payment: a commission inside one (`payout_id` set) is not edited, moved
 * or deleted until the payment lets it go, and that covers every commission the
 * plugin paid. Outside a payment an admin can move a commission between the
 * four statuses the way SliceWP allows — recording one as paid by hand, or
 * correcting a row that was — while the order and the maturation job only ever
 * move `pending`, `unpaid` and `rejected` rows. A WooCommerce-origin commission
 * refers to an order that exists; the reference is checked on create and edit.
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
	 * The row is shaped the way SliceWP's "add commission" form shapes it: an
	 * affiliate, an amount, a reference (the order id), the reference amount the
	 * commission is on, an origin, a type, a status and a date. An admin-created
	 * commission never has an order item, so `order_item_id` stays NULL and the
	 * unique constraint does not apply; the rate is recorded as the effective
	 * percentage of the base amount so the row reads like every other.
	 *
	 * The origin decides who moves the commission later. One created under the
	 * WooCommerce origin with an order id follows that order the way an
	 * attributed commission does — it matures when the order is paid and is
	 * rejected when the order fails — while one under the manual origin only
	 * follows the hold period and the admin. Whatever the origin, the status it
	 * is given is the status it keeps until the order, the job or the admin
	 * changes it: a pending commission added by hand stays pending, as in SliceWP.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Commission data.
	 *
	 *     @type int    $affiliate_id Required.
	 *     @type float  $amount       Required. What the affiliate earns.
	 *     @type float  $base_amount  The sale amount the commission is on. Default equal to `amount`.
	 *     @type int    $order_id     Optional reference order. Under the WooCommerce origin it must be an existing order.
	 *     @type string $source       `woocommerce` or `manual`. Default `woocommerce`.
	 *     @type string $type         A key of `Commission::get_types()`. Default `sale`.
	 *     @type string $status       Any commission status. Default `unpaid`.
	 *     @type string $created_at   Optional `Y-m-d H:i:s` in GMT. Default now.
	 * }
	 *
	 * @return Commission|WP_Error
	 */
	public function create( array $args ) {
		$affiliate_id = absint( $args['affiliate_id'] ?? 0 );

		if ( 0 === $affiliate_id || null === flyaffiliate()->affiliate->get( $affiliate_id ) ) {
			return new WP_Error( 'flyaffiliate_invalid_affiliate', __( 'Choose an affiliate.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$amount = Money::round( (float) ( $args['amount'] ?? 0 ) );

		if ( Money::to_cents( $amount ) <= 0 ) {
			return new WP_Error( 'flyaffiliate_invalid_amount', __( 'The commission amount must be more than zero.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$base = Money::round( (float) ( $args['base_amount'] ?? $amount ) );
		$base = Money::to_cents( $base ) > 0 ? $base : $amount;

		$source = $this->choice( $args['source'] ?? Commission::SOURCE_WOOCOMMERCE, Commission::get_sources(), 'flyaffiliate_invalid_source', __( 'Choose where the commission comes from.', 'flyaffiliate' ) );
		$type   = $this->choice( $args['type'] ?? Commission::TYPE_SALE, Commission::get_types(), 'flyaffiliate_invalid_type', __( 'Choose a commission type.', 'flyaffiliate' ) );
		$status = $this->choice( $args['status'] ?? Commission::STATUS_UNPAID, Commission::get_statuses(), 'flyaffiliate_invalid_status', __( 'Choose a commission status.', 'flyaffiliate' ) );

		foreach ( [ $source, $type, $status ] as $checked ) {
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		$order_id  = absint( $args['order_id'] ?? 0 );
		$reference = $this->check_reference( $source, $order_id );

		if ( is_wp_error( $reference ) ) {
			return $reference;
		}

		$created_at = $this->sanitize_datetime( (string) ( $args['created_at'] ?? '' ) );
		$commission = new Commission();

		$commission->fill(
			[
				'affiliate_id'  => $affiliate_id,
				'order_id'      => $order_id,
				'order_item_id' => null,
				'product_id'    => 0,
				'vendor_id'     => 0,
				'base_amount'   => $base,
				'rate'          => round( $amount / $base * 100, 4 ),
				'rate_type'     => Commission::RATE_PERCENTAGE,
				'amount'        => $amount,
				'currency'      => flyaffiliate_get_currency(),
				'source'        => $source,
				'type'          => $type,
				'status'        => $status,
				'matures_at'    => Commission::STATUS_PENDING === $status ? $this->maturation_date( $created_at ) : $created_at,
				'created_at'    => $created_at,
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
	 * Edit a commission the way SliceWP's "edit commission" form does.
	 *
	 * The amount, the reference, the reference amount, the type and the status
	 * can change; the affiliate, the origin and the date are fixed for the life
	 * of the row. The amount is editable whatever the origin (ADR-0011): an
	 * admin correcting a calculated commission is the reason to edit one at
	 * all. The reference of a commission that came from checkout stays with its
	 * order item. The rate is re-derived from the base amount so the row stays
	 * self-consistent.
	 *
	 * A commission inside a payment does not change at all (CONTEXT.md money
	 * rule 7). A status change goes through `set_status()`, so the transition
	 * rules and the status-change hook hold.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $commission_id Commission id.
	 * @param array $args {
	 *     Fields to change. A missing key leaves the field alone.
	 *
	 *     @type float  $amount      What the affiliate earns.
	 *     @type float  $base_amount The sale amount the commission is on.
	 *     @type int    $order_id    The reference order, on an admin-created commission. Under the WooCommerce origin it must be an existing order.
	 *     @type string $type        A key of `Commission::get_types()`.
	 *     @type string $status      Any commission status.
	 * }
	 *
	 * @return Commission|WP_Error
	 */
	public function update( int $commission_id, array $args ) {
		$commission = $this->get( $commission_id );

		if ( null === $commission ) {
			return new WP_Error( 'flyaffiliate_commission_not_found', __( 'No commission with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		if ( $commission->is_locked() ) {
			return $this->locked_error( $commission );
		}

		$changed = false;

		if ( isset( $args['amount'] ) ) {
			$amount = Money::round( (float) $args['amount'] );

			if ( Money::to_cents( $amount ) <= 0 ) {
				return new WP_Error( 'flyaffiliate_invalid_amount', __( 'The commission amount must be more than zero.', 'flyaffiliate' ), [ 'status' => 400 ] );
			}

			$commission->set( 'amount', $amount );
			$changed = true;
		}

		if ( isset( $args['base_amount'] ) ) {
			$base = Money::round( (float) $args['base_amount'] );

			$commission->set( 'base_amount', Money::to_cents( $base ) > 0 ? $base : (float) $commission->get( 'amount' ) );
			$changed = true;
		}

		if ( isset( $args['order_id'] ) && absint( $args['order_id'] ) !== (int) $commission->get( 'order_id', 0 ) ) {
			if ( null !== $commission->get( 'order_item_id' ) ) {
				return new WP_Error( 'flyaffiliate_reference_locked', __( 'This commission came from an order item, so its reference cannot change.', 'flyaffiliate' ), [ 'status' => 409 ] );
			}

			$reference = $this->check_reference( (string) $commission->get( 'source' ), absint( $args['order_id'] ) );

			if ( is_wp_error( $reference ) ) {
				return $reference;
			}

			$commission->set( 'order_id', absint( $args['order_id'] ) );
			$changed = true;
		}

		if ( isset( $args['type'] ) ) {
			$type = $this->choice( $args['type'], Commission::get_types(), 'flyaffiliate_invalid_type', __( 'Choose a commission type.', 'flyaffiliate' ) );

			if ( is_wp_error( $type ) ) {
				return $type;
			}

			$commission->set( 'type', $type );
			$changed = true;
		}

		if ( $changed ) {
			$base   = (float) $commission->get( 'base_amount', 0 );
			$amount = (float) $commission->get( 'amount', 0 );

			$commission->set( 'rate', Money::to_cents( $base ) > 0 ? round( $amount / $base * 100, 4 ) : 100 );

			if ( 0 === $commission->save() ) {
				return new WP_Error( 'flyaffiliate_update_failed', __( 'The commission could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
			}

			/**
			 * Fires after an admin edits a commission's fields.
			 *
			 * Not fired for a status change alone; that is
			 * `flyaffiliate_commission_status_changed`.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param Commission $commission The commission, saved.
			 */
			do_action( 'flyaffiliate_commission_updated', $commission );
		}

		if ( isset( $args['status'] ) && (string) $args['status'] !== (string) $commission->get( 'status' ) ) {
			return $this->set_status( $commission_id, (string) $args['status'] );
		}

		return $commission;
	}

	/**
	 * One of the allowed values, or an error naming the field.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed                 $value   The value given.
	 * @param array<string, string> $choices Allowed value => label.
	 * @param string                $code    The error code.
	 * @param string                $message The error message.
	 *
	 * @return string|WP_Error
	 */
	protected function choice( $value, array $choices, string $code, string $message ) {
		return is_string( $value ) && array_key_exists( $value, $choices )
			? $value
			: new WP_Error( $code, $message, [ 'status' => 400 ] );
	}

	/**
	 * Whether a reference is acceptable for the origin.
	 *
	 * A commission under the WooCommerce origin follows its order — the order
	 * status moves it, and the screens link to it — so the order has to exist.
	 * A refund is not an order. The manual origin refers to nothing the plugin
	 * can check, so any reference is kept as given.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $source   The commission's origin.
	 * @param int    $order_id The reference given; 0 for none.
	 *
	 * @return true|WP_Error
	 */
	protected function check_reference( string $source, int $order_id ) {
		if ( Commission::SOURCE_WOOCOMMERCE !== $source || 0 === $order_id || ! function_exists( 'wc_get_order' ) ) {
			return true;
		}

		if ( wc_get_order( $order_id ) instanceof \WC_Order ) {
			return true;
		}

		return new WP_Error(
			'flyaffiliate_invalid_reference',
			/* translators: %d: the order id given */
			sprintf( __( 'There is no WooCommerce order #%d. Enter the number of an existing order, or change the origin.', 'flyaffiliate' ), $order_id ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * A `Y-m-d H:i:s` GMT date from what was given, or now.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $value A date-time string, read as GMT.
	 *
	 * @return string
	 */
	protected function sanitize_datetime( string $value ): string {
		$time = '' !== $value ? strtotime( $value . ' UTC' ) : false;

		return false === $time ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Change a commission's status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $commission_id Commission id.
	 * @param string $status        The status to move to.
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
			return new WP_Error( 'flyaffiliate_locked_commission', __( 'That status change is not allowed.', 'flyaffiliate' ), [ 'status' => 409 ] );
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
	 * The error for a commission a payment holds.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return WP_Error
	 */
	protected function locked_error( Commission $commission ): WP_Error {
		$payout_id = (int) $commission->get( 'payout_id', 0 );
		$payout    = flyaffiliate()->payout->get( $payout_id );

		if ( null !== $payout && $payout->is_paid() ) {
			/* translators: %d: the payment id */
			$message = sprintf( __( 'This commission was paid in payment #%d and is kept as it was paid.', 'flyaffiliate' ), $payout_id );
		} else {
			/* translators: %d: the payment id */
			$message = sprintf( __( 'This commission is waiting in payment #%d. Take it out of the payment, or delete the payment, to change it.', 'flyaffiliate' ), $payout_id );
		}

		return new WP_Error( 'flyaffiliate_commission_in_payout', $message, [ 'status' => 409 ] );
	}

	/**
	 * Whether a status change is allowed for a commission outside a payment.
	 *
	 * Any of the four statuses can become any other, as in SliceWP: a rejected
	 * commission comes back when its order recovers or an admin changes their
	 * mind, and an admin can record a commission as paid by hand or take that
	 * back. A commission inside a payment never gets this far — `set_status()`
	 * refuses it first — which is what keeps the money a payment promised
	 * still.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $from The current status.
	 * @param string $to   The requested status.
	 *
	 * @return bool
	 */
	public function can_transition( string $from, string $to ): bool {
		$statuses = Commission::get_statuses();

		return $from !== $to && isset( $statuses[ $from ], $statuses[ $to ] );
	}

	/**
	 * Delete a commission that no payment holds.
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
