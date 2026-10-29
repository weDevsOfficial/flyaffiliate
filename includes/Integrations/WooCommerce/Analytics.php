<?php
/**
 * The affiliate commission on WooCommerce Analytics' overview.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;
use WP_REST_Response;

/**
 * An "Affiliate Commission" box in Analytics → Overview → Performance.
 *
 * WooCommerce builds that section from the stats its reports declare as
 * indicators, so the box is one more total on the revenue report's schema
 * (`revenue/affiliate_commission`). Its value is not the revenue report's:
 * it is the sum of the affiliate commissions, read from this plugin's table
 * (every commission except a rejected one), on the sales the report itself
 * counts — the orders imported into `wc_order_stats`, dated by the report's
 * date type (`date_paid` by default) and without its excluded statuses. So it
 * moves with the report: a new order shows once the analytics import has run,
 * as it does in every other box.
 *
 * Everyone who can read the store's reports sees the store's total. A
 * marketplace integration may narrow the total to one vendor
 * (`flyaffiliate_analytics_vendor_id`): the vendor an admin filtered the
 * report by, or the vendor reading their own dashboard, who sees the box only
 * then.
 *
 * The box opens FlyAffiliate's Commissions screen. WooCommerce links a box to
 * a page of its own admin app, so it links to `/flyaffiliate/commissions`, a
 * page `src/analytics` adds to that app, which hands over to the screen. The
 * link is there for those who can open FlyAffiliate's admin; an integration
 * may give it to others (`flyaffiliate_analytics_report_path`) and take them
 * where they belong.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Analytics implements Hookable {

	/**
	 * The total's key on the revenue report.
	 *
	 * @var string
	 */
	const KEY = 'affiliate_commission';

	/**
	 * The indicator's id: the report, then the total.
	 *
	 * @var string
	 */
	const STAT = 'revenue/' . self::KEY;

	/**
	 * The box's page in WooCommerce's admin app, which opens FlyAffiliate's Commissions screen.
	 *
	 * @var string
	 */
	const REPORT_PATH = '/flyaffiliate/commissions';

	/**
	 * The script that adds that page.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'flyaffiliate-analytics';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_rest_report_revenue_stats_schema', [ $this, 'add_to_schema' ] );
		// After WooCommerce's own callback (10), which reads the revenue report's totals.
		add_filter( 'woocommerce_rest_performance_indicators_data_value', [ $this, 'get_value' ], 20, 5 );
		add_filter( 'woocommerce_rest_prepare_report_performance_indicators', [ $this, 'link_report' ] );
		add_filter( 'woocommerce_rest_report_sort_performance_indicators', [ $this, 'sort' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_page' ] );
	}

	/**
	 * Load the page the box links to, on WooCommerce's admin app.
	 *
	 * In the header, so its page is added before WooCommerce's app, loaded in
	 * the footer, builds its routes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function enqueue_page(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'woocommerce_page_wc-admin' !== $screen->id || ! file_exists( FLYAFFILIATE_DIR . '/assets/js/analytics.js' ) || ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		// Registered by `Assets`, in the header.
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'flyaffiliateAnalytics',
			[
				'path'           => self::REPORT_PATH,
				'commissionsUrl' => admin_url( 'admin.php?page=flyaffiliate#/commissions' ),
				'title'          => __( 'Affiliate Commissions', 'flyaffiliate' ),
			]
		);
	}

	/**
	 * Declare the total on the revenue report, as an indicator.
	 *
	 * Only for someone who may see it (`can_view()`): WooCommerce lists an
	 * indicator only when the report declares it, and refuses a request for
	 * one it does not list.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $properties The schema's properties: `totals` and `intervals`.
	 *
	 * @return array<string, mixed>
	 */
	public function add_to_schema( $properties ): array {
		$properties = (array) $properties;

		if ( ! isset( $properties['totals']['properties'] ) || ! $this->can_view( $this->get_vendor_id() ) ) {
			return $properties;
		}

		$properties['totals']['properties'][ self::KEY ] = [
			'title'       => __( 'Affiliate Commission', 'flyaffiliate' ),
			'description' => __( 'Affiliate commission on the sales in the period, except rejected commissions.', 'flyaffiliate' ),
			'type'        => 'number',
			'context'     => [ 'view', 'edit' ],
			'readonly'    => true,
			'indicator'   => true,
			'format'      => 'currency',
		];

		return $properties;
	}

	/**
	 * The indicator's value for one period.
	 *
	 * WooCommerce asks once for the period picked and once for the period it
	 * is compared with.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed                $value      The value so far: none, the revenue report has no such total.
	 * @param string               $stat       The indicator asked for.
	 * @param string               $report     The report, `revenue`.
	 * @param string               $chart      The total, `affiliate_commission`.
	 * @param array<string, mixed> $query_args The request's `after` and `before`, in the site's time zone.
	 *
	 * @return mixed The total for this indicator; the value as given for any other.
	 */
	public function get_value( $value, $stat, $report = '', $chart = '', $query_args = [] ) {
		if ( self::STAT !== $stat ) {
			return $value;
		}

		$vendor_id = $this->get_vendor_id();

		if ( ! $this->can_view( $vendor_id ) ) {
			return null;
		}

		$query_args = (array) $query_args;

		return $this->get_total( (string) ( $query_args['after'] ?? '' ), (string) ( $query_args['before'] ?? '' ), $vendor_id );
	}

	/**
	 * Link the box to the commissions.
	 *
	 * WooCommerce would link it to the revenue report's chart of the same name,
	 * which does not exist. It links to the page that opens FlyAffiliate's
	 * Commissions screen instead, for those who can open it; for anyone else
	 * it is unlinked unless an integration gives it a page of its own. The link
	 * is kept, empty when unlinked, because the dashboards read it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Response $response The indicator's response.
	 *
	 * @return WP_REST_Response
	 */
	public function link_report( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = (array) $response->get_data();

		if ( self::STAT !== ( $data['stat'] ?? '' ) ) {
			return $response;
		}

		/**
		 * Filters the page of WooCommerce's admin app the "Affiliate Commission" box links to.
		 *
		 * FlyAffiliate's Commissions screen for those who can open it, nothing
		 * for anyone else. A marketplace integration may link it for a vendor
		 * on their own dashboard and take them to their affiliates.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $path The page's path, or empty for no link.
		 */
		$path = (string) apply_filters( 'flyaffiliate_analytics_report_path', current_user_can( flyaffiliate_admin_capability() ) ? self::REPORT_PATH : '' );

		$response->remove_link( 'report' );
		$response->add_link( 'report', $path );

		return $response;
	}

	/**
	 * Show the box right after net sales.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string[] $order Indicator ids, in the order they show.
	 *
	 * @return string[]
	 */
	public function sort( $order ): array {
		$order = array_values( array_diff( (array) $order, [ self::STAT ] ) );
		$after = array_search( 'revenue/net_revenue', $order, true );

		array_splice( $order, false === $after ? count( $order ) : $after + 1, 0, [ self::STAT ] );

		return $order;
	}

	/**
	 * The vendor the total is narrowed to.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int|null Null for the whole store.
	 */
	protected function get_vendor_id(): ?int {
		/**
		 * Filters the vendor the "Affiliate Commission" indicator is narrowed to.
		 *
		 * Null for the whole store, which only users who can read the store's
		 * reports see. A marketplace integration returns the vendor an admin
		 * filtered the report by, or, for a vendor reading their own dashboard,
		 * that vendor — never another vendor for someone who cannot read the
		 * store's reports.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int|null $vendor_id Default null.
		 */
		$vendor_id = apply_filters( 'flyaffiliate_analytics_vendor_id', null );

		return null === $vendor_id ? null : absint( $vendor_id );
	}

	/**
	 * Whether the current user may see the total.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int|null $vendor_id The vendor it is narrowed to, or null.
	 *
	 * @return bool
	 */
	protected function can_view( ?int $vendor_id ): bool {
		return current_user_can( 'view_woocommerce_reports' ) || ( null !== $vendor_id && $vendor_id > 0 );
	}

	/**
	 * The affiliate commission on the sales between two dates.
	 *
	 * The same sales the boxes beside it count: orders WooCommerce Analytics
	 * has imported (`wc_order_stats`), outside the statuses it leaves out of
	 * its reports, dated by its date type (paid, by default). An order not
	 * paid yet counts once it is, and the box follows Analytics' imports — its
	 * "Update now" included — as every other box does. Rejected commissions
	 * are left out.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string   $after     The period's start, in the site's time zone. Empty for no start.
	 * @param string   $before    The period's end, in the site's time zone. Empty for no end.
	 * @param int|null $vendor_id One vendor's commissions only, or null for all.
	 *
	 * @return float
	 */
	public function get_total( string $after, string $before, ?int $vendor_id = null ): float {
		global $wpdb;

		$table    = Commission::get_table();
		$stats    = $wpdb->prefix . 'wc_order_stats';
		$column   = $this->get_date_column();
		$from     = '' !== $after ? $this->to_local_datetime( $after ) : '0000-00-00 00:00:00';
		$to       = '' !== $before ? $this->to_local_datetime( $before ) : '9999-12-31 23:59:59';
		$excluded = implode( ',', $this->get_excluded_statuses() );
		$vendor   = (int) $vendor_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- an aggregate over this plugin's own table joined to WooCommerce's analytics table, which no model offers; the table names are not user input and the date column comes from a fixed list.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( commissions.amount )
				FROM {$table} commissions
				INNER JOIN {$stats} stats ON stats.order_id = commissions.order_id
				WHERE commissions.status <> %s AND commissions.source = %s
					AND stats.{$column} >= %s AND stats.{$column} <= %s
					AND FIND_IN_SET( stats.status, %s ) = 0
					AND ( 0 = %d OR commissions.vendor_id = %d )",
				Commission::STATUS_REJECTED,
				Commission::SOURCE_WOOCOMMERCE,
				$from,
				$to,
				$excluded,
				$vendor,
				$vendor
			)
		);
		// phpcs:enable

		return round( (float) $total, 2 );
	}

	/**
	 * The `wc_order_stats` column WooCommerce Analytics dates its reports by.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string `date_paid` (WooCommerce's default), `date_created` or `date_completed`.
	 */
	protected function get_date_column(): string {
		$type = (string) get_option( 'woocommerce_date_type', 'date_paid' );

		return in_array( $type, [ 'date_created', 'date_paid', 'date_completed' ], true ) ? $type : 'date_paid';
	}

	/**
	 * The order statuses WooCommerce Analytics leaves out of its reports, as stored.
	 *
	 * The list WooCommerce's report data stores build
	 * (`DataStore::get_excluded_report_order_statuses()`, which is protected):
	 * drafts and trashed orders, the statuses picked in its settings, and its
	 * filter.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[] With the `wc-` prefix, as `wc_order_stats` stores them.
	 */
	protected function get_excluded_statuses(): array {
		$picked = (array) get_option( 'woocommerce_excluded_report_order_statuses', [ 'pending', 'failed', 'cancelled' ] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as its report data stores apply it, so the box leaves out the orders the report leaves out.
		$statuses = (array) apply_filters( 'woocommerce_analytics_excluded_order_statuses', array_merge( [ 'auto-draft', 'trash' ], $picked ) );

		return array_values(
			array_map(
				static function ( $status ): string {
					$status = sanitize_key( (string) $status );

					return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
				},
				array_filter( $statuses )
			)
		);
	}

	/**
	 * A report's date, as `wc_order_stats` stores dates: in the site's time zone.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $date An ISO 8601 date, in the site's time zone unless it says otherwise.
	 *
	 * @return string `Y-m-d H:i:s`.
	 */
	protected function to_local_datetime( string $date ): string {
		try {
			$datetime = new \DateTime( $date, wp_timezone() );
			$datetime->setTimezone( wp_timezone() );

			return $datetime->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '0000-00-00 00:00:00';
		}
	}
}
