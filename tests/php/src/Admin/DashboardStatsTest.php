<?php
/**
 * Tests for the Dashboard figures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Admin;

use FlyAffiliate\Admin\Dashboard\Stats;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The Dashboard sums, counts and lists, with and without a range.
 *
 * @group admin
 *
 * @since FLYAFFILIATE_SINCE
 */
class DashboardStatsTest extends FlyAffiliateTestCase {

	/**
	 * Revenue and commissions add up per status, rejected counts for nothing, and the range holds.
	 *
	 * @return void
	 */
	public function test_earnings_and_performance(): void {
		$a = $this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_ACTIVE ] );
		$b = $this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'base_amount' => 100, 'amount' => 10, 'status' => Commission::STATUS_PAID, 'created_at' => '2026-03-10 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'base_amount' => 50, 'amount' => 5, 'status' => Commission::STATUS_UNPAID, 'created_at' => '2026-03-11 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'base_amount' => 20, 'amount' => 2, 'status' => Commission::STATUS_PENDING, 'created_at' => '2026-03-12 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'base_amount' => 999, 'amount' => 99, 'status' => Commission::STATUS_REJECTED, 'created_at' => '2026-03-12 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'base_amount' => 30, 'amount' => 3, 'status' => Commission::STATUS_UNPAID, 'created_at' => '2026-01-01 12:00:00' ] );

		$this->factory()->visit->create( [ 'affiliate_id' => $a, 'converted' => 1, 'created_at' => '2026-03-10 11:00:00' ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $a, 'converted' => 0, 'created_at' => '2026-03-10 11:30:00' ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $b, 'converted' => 0, 'created_at' => '2026-01-01 11:00:00' ] );

		$all = ( new Stats() )->get();

		$this->assertCentsEquals( 20000, $all['earnings']['referral_revenue'] );
		$this->assertCentsEquals( 2000, $all['earnings']['commissions'] );
		$this->assertCentsEquals( 18000, $all['earnings']['net_revenue'] );
		$this->assertCentsEquals( 1000, $all['earnings']['paid'] );
		$this->assertCentsEquals( 800, $all['earnings']['unpaid'] );
		$this->assertCentsEquals( 200, $all['earnings']['pending'] );
		$this->assertSame( 4, $all['performance']['commissions'] );
		$this->assertSame( 3, $all['performance']['visits'] );
		$this->assertSame( 1, $all['performance']['converted'] );
		$this->assertSame( 33.3, $all['performance']['conversion_rate'] );
		$this->assertSame(
			[
				'total'          => 2,
				'pending'        => 1,
				'active'         => 1,
				'pending_notice' => true,
			],
			$all['affiliates']
		);

		$march = ( new Stats() )->get( '2026-03-01 00:00:00', '2026-03-31 23:59:59' );

		$this->assertCentsEquals( 17000, $march['earnings']['referral_revenue'] );
		$this->assertSame( 3, $march['performance']['commissions'] );
		$this->assertSame( 2, $march['performance']['visits'] );
		$this->assertSame( 50.0, $march['performance']['conversion_rate'] );
		$this->assertCount( 1, $march['trend'] );
		$this->assertSame( [ 'date' => '2026-03-10', 'visits' => 2, 'converted' => 1 ], $march['trend'][0] );
	}

	/**
	 * The lists: top affiliates by earnings, top products by commissions, newest visits and commissions.
	 *
	 * @return void
	 */
	public function test_lists(): void {
		$a       = $this->factory()->affiliate->create();
		$b       = $this->factory()->affiliate->create();
		$product = $this->factory()->product->create( [ 'name' => 'Dashboard widget' ] );

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'product_id' => $product, 'base_amount' => 10, 'amount' => 1, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'product_id' => $product, 'base_amount' => 10, 'amount' => 1, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'product_id' => 0, 'base_amount' => 100, 'amount' => 20, 'status' => Commission::STATUS_PAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'product_id' => $product, 'base_amount' => 1, 'amount' => 50, 'status' => Commission::STATUS_REJECTED ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $a, 'url' => 'https://example.test/shop', 'converted' => 1 ] );

		$stats = ( new Stats() )->get();

		$this->assertSame( [ $b, $a ], array_column( $stats['top_affiliates'], 'id' ), 'by amount earned, rejected ignored' );
		$this->assertCentsEquals( 2000, $stats['top_affiliates'][0]['earned'] );
		$this->assertSame( 1, $stats['top_affiliates'][0]['visits'] + $stats['top_affiliates'][1]['visits'] );

		$this->assertCount( 1, $stats['top_products'] );
		$this->assertSame( 'Dashboard widget', $stats['top_products'][0]['name'] );
		$this->assertSame( 2, $stats['top_products'][0]['commissions'] );

		$this->assertCount( 1, $stats['recent_visits'] );
		$this->assertTrue( $stats['recent_visits'][0]['converted'] );
		$this->assertCount( 4, $stats['recent_commissions'] );
		$this->assertSame( Commission::STATUS_REJECTED, $stats['recent_commissions'][0]['status'], 'newest first' );
	}

	/**
	 * The route is admin-only and answers with every block.
	 *
	 * @return void
	 */
	public function test_rest_route(): void {
		$this->set_up_users();

		$this->acting_as( $this->customer_id );
		$this->assertSame( 403, $this->get_request( '/dashboard' )->get_status() );

		$this->acting_as( $this->admin_id );
		$response = $this->get_request( '/dashboard', [ 'after' => '2026-01-01', 'before' => '2026-12-31' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'range', 'earnings', 'performance', 'trend', 'top_affiliates', 'top_products', 'recent_visits', 'recent_commissions', 'affiliates' ], array_keys( $response->get_data() ) );
		$this->assertSame( 400, $this->get_request( '/dashboard', [ 'after' => 'yesterday' ] )->get_status(), 'days only' );
	}

	/**
	 * Closing the pending-review notice quiets it for two days, unless someone new applies.
	 *
	 * @return void
	 */
	public function test_the_pending_notice_stays_closed_for_two_days(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );

		$stats = new Stats();
		$this->assertTrue( $stats->get_affiliate_counts()['pending_notice'] );

		$stats->dismiss_pending_notice();
		$this->assertFalse( $stats->get_affiliate_counts()['pending_notice'] );

		// Someone new applying is news the admin has not seen.
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );
		$this->assertTrue( $stats->get_affiliate_counts()['pending_notice'] );

		// And two days later it is due again on its own.
		$stats->dismiss_pending_notice();
		$dismissed = get_user_meta( $admin, Stats::NOTICE_META, true );
		update_user_meta(
			$admin,
			Stats::NOTICE_META,
			[
				'at'   => $dismissed['at'] - Stats::NOTICE_SILENCE - 1,
				'seen' => $dismissed['seen'],
			]
		);

		$this->assertTrue( $stats->get_affiliate_counts()['pending_notice'] );
	}
}
