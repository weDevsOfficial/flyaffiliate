<?php
/**
 * Commission manager tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Commission;

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The status rules and the manual-commission path.
 *
 * @group commission
 *
 * @since FLYAFFILIATE_SINCE
 */
class ManagerTest extends FlyAffiliateTestCase {

	/**
	 * A manual commission records the effective rate and a NULL order item.
	 *
	 * @return void
	 */
	public function test_it_creates_a_manual_commission(): void {
		$affiliate  = $this->factory()->affiliate->create_and_get_model();
		$commission = flyaffiliate()->commission->create_manual( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 15, 'base_amount' => 100 ] );

		$this->assertInstanceOf( Commission::class, $commission );
		$this->assertCentsEquals( 1500, $commission->get( 'amount' ) );
		$this->assertSame( 15.0, $commission->get( 'rate' ) );
		$this->assertNull( $commission->get( 'order_item_id' ) );
		$this->assertSame( Commission::SOURCE_MANUAL, $commission->get( 'source' ) );
		$this->assertSame( Commission::STATUS_PENDING, $commission->get( 'status' ) );
	}

	/**
	 * Two manual commissions do not collide on the unique order item constraint.
	 *
	 * @return void
	 */
	public function test_two_manual_commissions_can_coexist(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$first  = flyaffiliate()->commission->create_manual( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 5 ] );
		$second = flyaffiliate()->commission->create_manual( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 7 ] );

		$this->assertInstanceOf( Commission::class, $first );
		$this->assertInstanceOf( Commission::class, $second );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 2, [ 'affiliate_id' => $affiliate->get_id() ] );
	}

	/**
	 * A zero or negative amount is refused.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_non_positive_amount(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$this->assertWPError( flyaffiliate()->commission->create_manual( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 0 ] ) );
		$this->assertWPError( flyaffiliate()->commission->create_manual( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => -3 ] ) );
	}

	/**
	 * Pending can become unpaid or rejected; unpaid can go back to pending or be rejected.
	 *
	 * @return void
	 */
	public function test_allowed_transitions(): void {
		$manager = flyaffiliate()->commission;

		$this->assertTrue( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_UNPAID ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_REJECTED ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_UNPAID, Commission::STATUS_REJECTED ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_UNPAID, Commission::STATUS_PENDING ) );
		$this->assertFalse( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_PAID ), 'paid is set by a payout, never by a status edit' );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_REJECTED, Commission::STATUS_PENDING ), 'a rejected commission can come back, as in SliceWP' );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_REJECTED, Commission::STATUS_UNPAID ) );
		$this->assertFalse( $manager->can_transition( Commission::STATUS_REJECTED, Commission::STATUS_PAID ), 'paid is set by a payout, never by a status edit' );
		$this->assertFalse( $manager->can_transition( Commission::STATUS_PAID, Commission::STATUS_UNPAID ), 'paid is terminal' );
	}

	/**
	 * A paid commission cannot be changed, by status or by amount.
	 *
	 * @return void
	 */
	public function test_a_paid_commission_is_locked(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'status' => Commission::STATUS_PAID, 'source' => Commission::SOURCE_MANUAL, 'amount' => 20 ] );

		$this->assertWPError( flyaffiliate()->commission->set_status( $commission->get_id(), Commission::STATUS_REJECTED ) );
		$this->assertWPError( flyaffiliate()->commission->set_manual_amount( $commission->get_id(), 1 ) );
		$this->assertFalse( flyaffiliate()->commission->delete( $commission->get_id() ) );

		$reloaded = flyaffiliate()->commission->get( $commission->get_id() );

		$this->assertSame( Commission::STATUS_PAID, $reloaded->get( 'status' ) );
		$this->assertCentsEquals( 2000, $reloaded->get( 'amount' ) );
	}

	/**
	 * A WooCommerce commission's amount is owned by the calculator.
	 *
	 * @return void
	 */
	public function test_a_calculated_commission_amount_cannot_be_edited(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'source' => Commission::SOURCE_WOOCOMMERCE ] );

		$this->assertWPError( flyaffiliate()->commission->set_manual_amount( $commission->get_id(), 99 ) );
	}

	/**
	 * Setting the same status twice is a no-op, not an error.
	 *
	 * @return void
	 */
	public function test_setting_the_same_status_is_idempotent(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'status' => Commission::STATUS_PENDING ] );

		$first  = flyaffiliate()->commission->set_status( $commission->get_id(), Commission::STATUS_UNPAID );
		$second = flyaffiliate()->commission->set_status( $commission->get_id(), Commission::STATUS_UNPAID );

		$this->assertInstanceOf( Commission::class, $first );
		$this->assertInstanceOf( Commission::class, $second );
		$this->assertSame( Commission::STATUS_UNPAID, $second->get( 'status' ) );
	}

	/**
	 * Totals per affiliate are summed in cents, split by status.
	 *
	 * @return void
	 */
	public function test_affiliate_totals(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();
		$id        = $affiliate->get_id();

		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => 10.10, 'status' => Commission::STATUS_PAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => 20.20, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => 0.30, 'status' => Commission::STATUS_PENDING ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => 99, 'status' => Commission::STATUS_REJECTED ] );

		$totals = flyaffiliate()->commission->get_affiliate_totals( $id );

		$this->assertCentsEquals( 1010, $totals['paid'] );
		$this->assertCentsEquals( 2020, $totals['unpaid'] );
		$this->assertCentsEquals( 30, $totals['pending'] );
		$this->assertCentsEquals( 3060, $totals['total'] );
		$this->assertSame( 4, $totals['count'] );
	}

	/**
	 * A page of affiliates is totalled in one pass, zeroes included.
	 *
	 * @return void
	 */
	public function test_totals_for_affiliates(): void {
		$a     = $this->factory()->affiliate->create();
		$b     = $this->factory()->affiliate->create();
		$empty = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 10.10, 'status' => Commission::STATUS_PAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 20.20, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 99, 'status' => Commission::STATUS_REJECTED ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'amount' => 7, 'status' => Commission::STATUS_PAID ] );

		$totals = flyaffiliate()->commission->get_totals_for_affiliates( [ $a, $b, $empty ] );

		$this->assertCentsEquals( 1010, $totals[ $a ]['paid'] );
		$this->assertCentsEquals( 2520, $totals[ $a ]['unpaid'] );
		$this->assertSame( 1, $totals[ $a ]['paid_count'] );
		$this->assertSame( 2, $totals[ $a ]['unpaid_count'] );
		$this->assertSame( 4, $totals[ $a ]['count'] );
		$this->assertCentsEquals( 700, $totals[ $b ]['paid'] );
		$this->assertCentsEquals( 0, $totals[ $empty ]['unpaid'] );
		$this->assertSame( 0, $totals[ $empty ]['count'] );

		// The same numbers the single-affiliate helper reports.
		$single = flyaffiliate()->commission->get_affiliate_totals( $a );

		$this->assertEqualsWithDelta( $single['paid'], $totals[ $a ]['paid'], 0.0001 );
		$this->assertEqualsWithDelta( $single['unpaid'], $totals[ $a ]['unpaid'], 0.0001 );
		$this->assertSame( $single['count'], $totals[ $a ]['count'] );
	}

	/**
	 * Sums by affiliate honour the status filter.
	 *
	 * @return void
	 */
	public function test_sum_by_affiliate(): void {
		$a = $this->factory()->affiliate->create();
		$b = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 5, 'status' => Commission::STATUS_PAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 6, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'amount' => 7, 'status' => Commission::STATUS_PAID ] );

		$paid = flyaffiliate()->commission->sum_by_affiliate( [ Commission::STATUS_PAID ] );

		$this->assertCentsEquals( 500, $paid[ $a ] );
		$this->assertCentsEquals( 700, $paid[ $b ] );
		$this->assertSame( [], flyaffiliate()->commission->sum_by_affiliate( [ 'nonsense' ] ) );
	}
}
