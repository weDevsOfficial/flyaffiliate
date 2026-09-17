<?php
/**
 * The figures behind the admin Dashboard.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Visit;

/**
 * Aggregates commissions, visits and affiliates for the Dashboard screen.
 *
 * Every figure is one grouped query over the plugin's own tables, bounded by
 * an optional GMT date range on `created_at`. Rejected commissions count for
 * nothing; a paid commission is revenue that has been paid out.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Stats {

	/**
	 * How many rows the lists carry. The screen shows five at a time and
	 * scrolls through the rest.
	 *
	 * @var int
	 */
	const LIST_SIZE = 10;

	/**
	 * User meta recording that an admin closed the pending-review notice.
	 *
	 * @var string
	 */
	const NOTICE_META = '_flyaffiliate_pending_notice_dismissed';

	/**
	 * How long a closed pending-review notice stays closed, in seconds.
	 *
	 * @var int
	 */
	const NOTICE_SILENCE = 48 * HOUR_IN_SECONDS;

	/**
	 * Everything the Dashboard shows, for a range.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  GMT `Y-m-d H:i:s` lower bound, or empty.
	 * @param string $before GMT `Y-m-d H:i:s` upper bound, or empty.
	 *
	 * @return array<string, mixed>
	 */
	public function get( string $after = '', string $before = '' ): array {
		return [
			'range'              => [
				'after'  => $after,
				'before' => $before,
			],
			'earnings'           => $this->get_earnings( $after, $before ),
			'performance'        => $this->get_performance( $after, $before ),
			'trend'              => $this->get_trend( $after, $before ),
			/*
			 * The lists stand apart from the range: they answer "who and what
			 * is doing the work", which is an all-time question.
			 */
			'top_affiliates'     => $this->get_top_affiliates(),
			'top_products'       => $this->get_top_products(),
			'recent_visits'      => $this->get_recent_visits(),
			'recent_commissions' => $this->get_recent_commissions(),
			'affiliates'         => $this->get_affiliate_counts(),
		];
	}

	/**
	 * Money: what referred orders were worth, what that cost in commissions,
	 * what has been paid and what is still owed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array{referral_revenue: float, commissions: float, net_revenue: float, paid: float, unpaid: float, pending: float}
	 */
	public function get_earnings( string $after = '', string $before = '' ): array {
		global $wpdb;

		list( $where, $values ) = $this->range_where( 'created_at', $after, $before );
		$table                  = Commission::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own tables: the only interpolations are their names and a WHERE built from placeholders, whose values follow.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE( SUM( CASE WHEN status <> %s THEN base_amount ELSE 0 END ), 0 ) AS revenue,
					COALESCE( SUM( CASE WHEN status <> %s THEN amount ELSE 0 END ), 0 ) AS commissions,
					COALESCE( SUM( CASE WHEN status = %s THEN amount ELSE 0 END ), 0 ) AS paid,
					COALESCE( SUM( CASE WHEN status = %s THEN amount ELSE 0 END ), 0 ) AS unpaid,
					COALESCE( SUM( CASE WHEN status = %s THEN amount ELSE 0 END ), 0 ) AS pending
				FROM {$table} {$where}",
				array_merge(
					[ Commission::STATUS_REJECTED, Commission::STATUS_REJECTED, Commission::STATUS_PAID, Commission::STATUS_UNPAID, Commission::STATUS_PENDING ],
					$values
				)
			)
		);
		// phpcs:enable

		$revenue     = (float) ( $row->revenue ?? 0 );
		$commissions = (float) ( $row->commissions ?? 0 );

		return [
			'referral_revenue' => $revenue,
			'commissions'      => $commissions,
			'net_revenue'      => $revenue - $commissions,
			'paid'             => (float) ( $row->paid ?? 0 ),
			'unpaid'           => (float) ( $row->unpaid ?? 0 ),
			'pending'          => (float) ( $row->pending ?? 0 ),
		];
	}

	/**
	 * Counts: commissions earned, visits, how many converted.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array{commissions: int, visits: int, converted: int, conversion_rate: float}
	 */
	public function get_performance( string $after = '', string $before = '' ): array {
		global $wpdb;

		list( $where, $values ) = $this->range_where( 'created_at', $after, $before );
		$commissions_table      = Commission::get_table();
		$visits_table           = Visit::get_table();
		$and                    = '' === $where ? 'WHERE' : 'AND';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own tables: the only interpolations are their names and a WHERE built from placeholders, whose values follow.
		$commissions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$commissions_table} {$where} {$and} status <> %s",
				array_merge( $values, [ Commission::STATUS_REJECTED ] )
			)
		);

		$visits = '' === $where
			? $wpdb->get_row( "SELECT COUNT(*) AS visits, COALESCE( SUM( converted ), 0 ) AS converted FROM {$visits_table}" )
			: $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS visits, COALESCE( SUM( converted ), 0 ) AS converted FROM {$visits_table} {$where}", $values ) );
		// phpcs:enable

		$all       = (int) ( $visits->visits ?? 0 );
		$converted = (int) ( $visits->converted ?? 0 );

		return [
			'commissions'     => $commissions,
			'visits'          => $all,
			'converted'       => $converted,
			'conversion_rate' => $all > 0 ? round( $converted / $all * 100, 1 ) : 0.0,
		];
	}

	/**
	 * Visits and conversions per day, for the sparkline.
	 *
	 * Without a range, the last 30 days.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array<int, array{date: string, visits: int, converted: int}>
	 */
	public function get_trend( string $after = '', string $before = '' ): array {
		global $wpdb;

		if ( '' === $after && '' === $before ) {
			$after = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		}

		list( $where, $values ) = $this->range_where( 'created_at', $after, $before );
		$table                  = Visit::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own tables: the only interpolations are their names and a WHERE built from placeholders, whose values follow.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( created_at ) AS day, COUNT(*) AS visits, COALESCE( SUM( converted ), 0 ) AS converted FROM {$table} {$where} GROUP BY DATE( created_at ) ORDER BY day ASC",
				$values
			)
		);
		// phpcs:enable

		$by_day = [];

		foreach ( (array) $rows as $row ) {
			$by_day[ (string) $row->day ] = [
				'date'      => (string) $row->day,
				'visits'    => (int) $row->visits,
				'converted' => (int) $row->converted,
			];
		}

		/*
		 * One point per day of the range, zero where nothing happened, so the
		 * chart's axis spans the dates asked for rather than the days with
		 * visits. A range wider than a year is left to the days that have rows.
		 */
		$start = strtotime( ( '' !== $after ? $after : ( array_key_first( $by_day ) ?? gmdate( 'Y-m-d' ) ) ) . ' UTC' );
		$end   = strtotime( ( '' !== $before ? $before : gmdate( 'Y-m-d H:i:s' ) ) . ' UTC' );

		if ( false === $start || false === $end || $end < $start || ( $end - $start ) > YEAR_IN_SECONDS ) {
			return array_values( $by_day );
		}

		$trend = [];

		for ( $day = strtotime( gmdate( 'Y-m-d', $start ) . ' UTC' ); $day <= $end; $day += DAY_IN_SECONDS ) {
			$key     = gmdate( 'Y-m-d', $day );
			$trend[] = $by_day[ $key ] ?? [
				'date'      => $key,
				'visits'    => 0,
				'converted' => 0,
			];
		}

		return $trend;
	}

	/**
	 * The affiliates who earned the most, with their commission and visit counts.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array<int, array{id: int, name: string, earned: float, commissions: int, visits: int}>
	 */
	public function get_top_affiliates( string $after = '', string $before = '' ): array {
		global $wpdb;

		list( $where, $values ) = $this->range_where( 'c.created_at', $after, $before );
		$commissions            = Commission::get_table();
		$visits                 = Visit::get_table();
		$and                    = '' === $where ? 'WHERE' : 'AND';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own tables: the only interpolations are their names and a WHERE built from placeholders, whose values follow.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.affiliate_id, COALESCE( SUM( c.amount ), 0 ) AS earned, COUNT(*) AS commissions,
					( SELECT COUNT(*) FROM {$visits} v WHERE v.affiliate_id = c.affiliate_id ) AS visits
				FROM {$commissions} c {$where} {$and} c.status <> %s
				GROUP BY c.affiliate_id ORDER BY earned DESC, commissions DESC LIMIT %d",
				array_merge( $values, [ Commission::STATUS_REJECTED, self::LIST_SIZE ] )
			)
		);
		// phpcs:enable

		$top = [];

		foreach ( (array) $rows as $row ) {
			$affiliate = Affiliate::find( (int) $row->affiliate_id );

			$top[] = [
				'id'          => (int) $row->affiliate_id,
				'name'        => null === $affiliate ? '' : $affiliate->get_display_name(),
				'earned'      => (float) $row->earned,
				'commissions' => (int) $row->commissions,
				'visits'      => (int) $row->visits,
			];
		}

		return $top;
	}

	/**
	 * The products that earned the most commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array<int, array{product_id: int, name: string, url: string, commissions: int, revenue: float}>
	 */
	public function get_top_products( string $after = '', string $before = '' ): array {
		global $wpdb;

		list( $where, $values ) = $this->range_where( 'created_at', $after, $before );
		$table                  = Commission::get_table();
		$and                    = '' === $where ? 'WHERE' : 'AND';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own tables: the only interpolations are their names and a WHERE built from placeholders, whose values follow.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS commissions, COALESCE( SUM( base_amount ), 0 ) AS revenue FROM {$table} {$where} {$and} status <> %s AND product_id > 0
				GROUP BY product_id ORDER BY commissions DESC, revenue DESC LIMIT %d",
				array_merge( $values, [ Commission::STATUS_REJECTED, self::LIST_SIZE ] )
			)
		);
		// phpcs:enable

		$top = [];

		foreach ( (array) $rows as $row ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row->product_id ) : null;

			$top[] = [
				'product_id'  => (int) $row->product_id,
				'name'        => $product ? $product->get_name() : '',
				'url'         => $product && 'publish' === $product->get_status() ? (string) $product->get_permalink() : '',
				'commissions' => (int) $row->commissions,
				'revenue'     => (float) $row->revenue,
			];
		}

		return $top;
	}

	/**
	 * The newest visits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array<int, array{id: int, affiliate_id: int, affiliate_name: string, url: string, referrer: string, converted: bool, created_at: string}>
	 */
	public function get_recent_visits( string $after = '', string $before = '' ): array {
		$rows = [];

		foreach ( Visit::query(
			[
				'after' => $after,
				'before' => $before,
				'orderby' => 'id',
				'order' => 'DESC',
				'per_page' => self::LIST_SIZE,
			]
		) as $visit ) {
			$affiliate = Affiliate::find( (int) $visit->get( 'affiliate_id' ) );

			$rows[] = [
				'id'             => $visit->get_id(),
				'affiliate_id'   => (int) $visit->get( 'affiliate_id' ),
				'affiliate_name' => null === $affiliate ? '' : $affiliate->get_display_name(),
				'url'            => (string) $visit->get( 'url', '' ),
				'referrer'       => (string) $visit->get( 'referrer', '' ),
				'converted'      => 1 === (int) $visit->get( 'converted', 0 ),
				'created_at'     => empty( $visit->get( 'created_at' ) ) ? '' : mysql_to_rfc3339( (string) $visit->get( 'created_at' ) ),
			];
		}

		return $rows;
	}

	/**
	 * The newest commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array<int, array{id: int, affiliate_id: int, affiliate_name: string, order_id: int, order_url: string|null, amount: float, status: string, created_at: string}>
	 */
	public function get_recent_commissions( string $after = '', string $before = '' ): array {
		$rows = [];

		foreach ( Commission::query(
			[
				'after' => $after,
				'before' => $before,
				'orderby' => 'id',
				'order' => 'DESC',
				'per_page' => self::LIST_SIZE,
			]
		) as $commission ) {
			$affiliate = Affiliate::find( (int) $commission->get( 'affiliate_id' ) );
			$order     = (int) $commission->get( 'order_id', 0 ) > 0 ? $commission->get_order() : null;

			$rows[] = [
				'id'             => $commission->get_id(),
				'affiliate_id'   => (int) $commission->get( 'affiliate_id' ),
				'affiliate_name' => null === $affiliate ? '' : $affiliate->get_display_name(),
				'order_id'       => (int) $commission->get( 'order_id', 0 ),
				'order_url'      => null === $order ? null : $order->get_edit_order_url(),
				'amount'         => (float) $commission->get( 'amount', 0 ),
				'status'         => (string) $commission->get( 'status' ),
				'created_at'     => empty( $commission->get( 'created_at' ) ) ? '' : mysql_to_rfc3339( (string) $commission->get( 'created_at' ) ),
			];
		}

		return $rows;
	}

	/**
	 * How many affiliates there are, and how many wait for review.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array{total: int, pending: int, active: int, pending_notice: bool}
	 */
	public function get_affiliate_counts(): array {
		$pending = Affiliate::count( [ 'status' => Affiliate::STATUS_PENDING ] );

		return [
			'total'          => Affiliate::count(),
			'pending'        => $pending,
			'active'         => Affiliate::count( [ 'status' => Affiliate::STATUS_ACTIVE ] ),
			'pending_notice' => $pending > 0 && $this->pending_notice_is_due(),
		];
	}

	/**
	 * The newest affiliate waiting for review.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int The affiliate id, or 0 when nobody is waiting.
	 */
	public function latest_pending_affiliate(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlyAffiliate's own table, read once per dashboard load to see whether anyone new is waiting.
		$latest = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$wpdb->flyaffiliate_affiliates} WHERE status = %s",
				Affiliate::STATUS_PENDING
			)
		);
		// phpcs:enable

		return $latest;
	}

	/**
	 * Whether the pending-review notice is due for the current user.
	 *
	 * Closing it quiets the notice for two days. Someone new applying in the
	 * meantime brings it back, because that is news the admin has not seen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function pending_notice_is_due(): bool {
		$dismissed = get_user_meta( get_current_user_id(), self::NOTICE_META, true );

		if ( ! is_array( $dismissed ) || empty( $dismissed['at'] ) ) {
			return true;
		}

		if ( time() - (int) $dismissed['at'] >= self::NOTICE_SILENCE ) {
			return true;
		}

		return $this->latest_pending_affiliate() > (int) ( $dismissed['seen'] ?? 0 );
	}

	/**
	 * Record that the current user closed the pending-review notice.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function dismiss_pending_notice(): void {
		update_user_meta(
			get_current_user_id(),
			self::NOTICE_META,
			[
				'at'   => time(),
				'seen' => $this->latest_pending_affiliate(),
			]
		);
	}

	/**
	 * A WHERE clause for the range, with its placeholder values.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column The date column, table alias included when joined.
	 * @param string $after  Lower bound.
	 * @param string $before Upper bound.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	protected function range_where( string $column, string $after, string $before ): array {
		$clauses = [];
		$values  = [];

		if ( '' !== $after ) {
			$clauses[] = "{$column} >= %s";
			$values[]  = $after;
		}

		if ( '' !== $before ) {
			$clauses[] = "{$column} <= %s";
			$values[]  = $before;
		}

		return [ [] === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ), $values ];
	}
}
