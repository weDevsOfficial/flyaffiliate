<?php
/**
 * Tests for the affiliate commission on WooCommerce Analytics' overview.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Integrations;

use FlyAffiliate\Integrations\WooCommerce\Analytics;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;
use WP_REST_Response;

/**
 * The "Affiliate Commission" box.
 */
class AnalyticsTest extends FlyAffiliateTestCase {

	/**
	 * The box.
	 *
	 * @var Analytics
	 */
	protected Analytics $analytics;

	/**
	 * {@inheritDoc}
	 */
	public function set_up() {
		parent::set_up();

		$this->analytics = new Analytics();
	}

	/**
	 * The value for 2026, the year the tests' orders are paid in.
	 *
	 * @return mixed
	 */
	protected function value() {
		return $this->analytics->get_value( null, Analytics::STAT, 'revenue', Analytics::KEY, [ 'after' => '2026-01-01T00:00:00', 'before' => '2026-12-31T23:59:59' ] );
	}

	/**
	 * An order as WooCommerce Analytics has it: in a status, paid on a date
	 * (or not), and imported into `wc_order_stats`.
	 *
	 * @param string      $status The order's status.
	 * @param string|null $paid   When it was paid, in the site's time zone; null for not paid.
	 *
	 * @return int The order's id.
	 */
	protected function report_order( string $status, ?string $paid ): int {
		$product  = $this->factory()->product->create( [ 'regular_price' => 100 ] );
		$order_id = $this->factory()->order->create(
			[
				'items'  => [ [ 'product_id' => $product ] ],
				'status' => $status,
			]
		);
		$order    = wc_get_order( $order_id );

		$order->set_date_paid( null === $paid ? null : strtotime( get_gmt_from_date( $paid ) ) );
		$order->save();

		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order_id );

		return $order_id;
	}

	/**
	 * It sums the commissions on the orders Analytics reports in the period —
	 * paid in it, in a reported status — leaving out rejected and manual ones.
	 */
	public function test_it_sums_the_commissions_on_the_reported_sales(): void {
		$paid_march   = $this->report_order( 'processing', '2026-03-01 10:00:00' );
		$paid_june    = $this->report_order( 'completed', '2026-06-01 10:00:00' );
		$unpaid       = $this->report_order( 'on-hold', null );
		$cancelled    = $this->report_order( 'cancelled', '2026-03-02 10:00:00' );
		$paid_earlier = $this->report_order( 'completed', '2025-03-01 10:00:00' );

		$this->factory()->commission->create( [ 'amount' => 10.0, 'order_id' => $paid_march ] );
		$this->factory()->commission->create( [ 'amount' => 2.5, 'status' => Commission::STATUS_PAID, 'order_id' => $paid_june ] );
		$this->factory()->commission->create( [ 'amount' => 99.0, 'status' => Commission::STATUS_REJECTED, 'order_id' => $paid_march ] );
		$this->factory()->commission->create( [ 'amount' => 99.0, 'source' => Commission::SOURCE_MANUAL, 'order_id' => $paid_march ] );
		$this->factory()->commission->create( [ 'amount' => 99.0, 'order_id' => $unpaid ] );
		$this->factory()->commission->create( [ 'amount' => 99.0, 'order_id' => $cancelled ] );
		$this->factory()->commission->create( [ 'amount' => 99.0, 'order_id' => $paid_earlier ] );

		wp_set_current_user( $this->admin_id );

		$this->assertSame( 12.5, $this->value() );
		$this->assertSame( 'untouched', $this->analytics->get_value( 'untouched', 'revenue/total_sales' ) );
	}

	/**
	 * A marketplace narrows it to a vendor; someone who cannot read the
	 * store's reports sees it only then.
	 */
	public function test_it_is_narrowed_to_a_vendor_and_hidden_otherwise(): void {
		$order = $this->report_order( 'processing', '2026-03-01 10:00:00' );

		$this->factory()->commission->create( [ 'amount' => 10.0, 'vendor_id' => 7, 'order_id' => $order ] );
		$this->factory()->commission->create( [ 'amount' => 4.0, 'vendor_id' => 8, 'order_id' => $order ] );

		wp_set_current_user( $this->customer_id );

		$this->assertNull( $this->value() );
		$this->assertArrayNotHasKey( Analytics::KEY, $this->analytics->add_to_schema( [ 'totals' => [ 'properties' => [] ] ] )['totals']['properties'] );

		$vendor = static function () {
			return 8;
		};

		add_filter( 'flyaffiliate_analytics_vendor_id', $vendor );

		$this->assertSame( 4.0, $this->value() );
		$this->assertTrue( $this->analytics->add_to_schema( [ 'totals' => [ 'properties' => [] ] ] )['totals']['properties'][ Analytics::KEY ]['indicator'] );

		remove_filter( 'flyaffiliate_analytics_vendor_id', $vendor );
	}

	/**
	 * The box comes right after net sales, and opens FlyAffiliate's
	 * Commissions screen for those who can open it.
	 */
	public function test_it_sits_after_net_sales_and_links_to_the_commissions(): void {
		$order = $this->analytics->sort( [ 'revenue/total_sales', 'revenue/net_revenue', 'orders/orders_count' ] );

		$this->assertSame( [ 'revenue/total_sales', 'revenue/net_revenue', Analytics::STAT, 'orders/orders_count' ], $order );

		$link = function (): string {
			$response = new WP_REST_Response( [ 'stat' => Analytics::STAT ] );
			$response->add_link( 'report', '/analytics/revenue' );

			return $this->analytics->link_report( $response )->get_links()['report'][0]['href'];
		};

		wp_set_current_user( $this->admin_id );
		$this->assertSame( Analytics::REPORT_PATH, $link() );

		wp_set_current_user( $this->customer_id );
		$this->assertSame( '', $link() );
	}
}
