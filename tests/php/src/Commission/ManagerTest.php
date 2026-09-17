<?php
/**
 * Commission manager tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Commission;

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Payout;
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
	 * An admin-created commission records the effective rate and a NULL order item.
	 *
	 * The defaults are SliceWP's add form: WooCommerce origin, sale, payable now.
	 *
	 * @return void
	 */
	public function test_it_creates_a_manual_commission(): void {
		$affiliate  = $this->factory()->affiliate->create_and_get_model();
		$commission = flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 15, 'base_amount' => 100 ] );

		$this->assertInstanceOf( Commission::class, $commission );
		$this->assertCentsEquals( 1500, $commission->get( 'amount' ) );
		$this->assertSame( 15.0, $commission->get( 'rate' ) );
		$this->assertNull( $commission->get( 'order_item_id' ) );
		$this->assertSame( Commission::SOURCE_WOOCOMMERCE, $commission->get( 'source' ) );
		$this->assertSame( Commission::TYPE_SALE, $commission->get( 'type' ) );
		$this->assertSame( Commission::STATUS_UNPAID, $commission->get( 'status' ) );
	}

	/**
	 * Every field of SliceWP's add form is honoured: origin, type, status and date.
	 *
	 * @return void
	 */
	public function test_it_records_the_origin_status_and_date_it_is_given(): void {
		flyaffiliate()->settings->save( [ 'hold_days' => 10 ] );

		$affiliate  = $this->factory()->affiliate->create_and_get_model();
		$commission = flyaffiliate()->commission->create(
			[
				'affiliate_id' => $affiliate->get_id(),
				'amount'       => 4,
				'base_amount'  => 40,
				'order_id'     => 777,
				'source'       => Commission::SOURCE_MANUAL,
				'status'       => Commission::STATUS_PENDING,
				'created_at'   => '2026-01-05 10:00:00',
			]
		);

		$this->assertInstanceOf( Commission::class, $commission );
		$this->assertSame( Commission::SOURCE_MANUAL, $commission->get( 'source' ) );
		$this->assertSame( Commission::STATUS_PENDING, $commission->get( 'status' ) );
		$this->assertSame( 777, $commission->get( 'order_id' ) );
		$this->assertSame( '2026-01-05 10:00:00', $commission->get( 'created_at' ) );
		$this->assertSame( '2026-01-15 10:00:00', $commission->get( 'matures_at' ), 'a pending commission matures hold_days after the date it was given' );

		$paid = flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 4, 'status' => Commission::STATUS_PAID ] );

		$this->assertSame( Commission::STATUS_PAID, $paid->get( 'status' ), 'a commission already paid outside the plugin can be recorded as paid' );
		$this->assertWPError( flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 4, 'source' => 'shopify' ] ) );
		$this->assertWPError( flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 4, 'status' => 'approved' ] ) );
	}

	/**
	 * Editing changes the amount, the reference, the reference amount and the
	 * type, re-derives the rate, and routes a status change through the
	 * transition rules.
	 *
	 * @return void
	 */
	public function test_it_edits_the_fields_of_slicewps_form(): void {
		$affiliate  = $this->factory()->affiliate->create_and_get_model();
		$commission = flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 10, 'base_amount' => 100, 'source' => Commission::SOURCE_MANUAL, 'status' => Commission::STATUS_PENDING ] );

		$edited = flyaffiliate()->commission->update(
			$commission->get_id(),
			[
				'amount'      => 25,
				'base_amount' => 200,
				'order_id'    => 4242,
				'status'      => Commission::STATUS_UNPAID,
			]
		);

		$this->assertInstanceOf( Commission::class, $edited );
		$this->assertCentsEquals( 2500, $edited->get( 'amount' ) );
		$this->assertCentsEquals( 20000, $edited->get( 'base_amount' ) );
		$this->assertSame( 12.5, $edited->get( 'rate' ) );
		$this->assertSame( 4242, $edited->get( 'order_id' ) );
		$this->assertSame( Commission::STATUS_UNPAID, $edited->get( 'status' ) );

		$this->assertWPError( flyaffiliate()->commission->update( $commission->get_id(), [ 'amount' => 0 ] ) );
		$this->assertWPError( flyaffiliate()->commission->update( 999999, [ 'amount' => 1 ] ) );

		$paid = flyaffiliate()->commission->update( $commission->get_id(), [ 'status' => Commission::STATUS_PAID ] );

		$this->assertInstanceOf( Commission::class, $paid, 'outside a payment an admin can record a commission as paid, as in SliceWP' );
		$this->assertSame( Commission::STATUS_PAID, $paid->get( 'status' ) );
		$this->assertInstanceOf( Commission::class, flyaffiliate()->commission->update( $commission->get_id(), [ 'amount' => 26, 'status' => Commission::STATUS_UNPAID ] ), 'and correct it again: the lock is the payment, not the status' );
	}

	/**
	 * A WooCommerce-origin commission refers to an order that exists; a manual one refers to anything.
	 *
	 * @return void
	 */
	public function test_a_woocommerce_reference_must_be_an_existing_order(): void {
		$affiliate = $this->factory()->affiliate->create();
		$product   = $this->factory()->product->create();
		$order     = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$manager   = flyaffiliate()->commission;

		$missing = $manager->create( [ 'affiliate_id' => $affiliate, 'amount' => 5, 'order_id' => 987654 ] );

		$this->assertWPError( $missing );
		$this->assertSame( 'flyaffiliate_invalid_reference', $missing->get_error_code() );

		$real = $manager->create( [ 'affiliate_id' => $affiliate, 'amount' => 5, 'order_id' => $order ] );

		$this->assertInstanceOf( Commission::class, $real );
		$this->assertSame( $order, $real->get( 'order_id' ) );

		$none = $manager->create( [ 'affiliate_id' => $affiliate, 'amount' => 5 ] );

		$this->assertInstanceOf( Commission::class, $none, 'no reference at all is fine' );

		$manual = $manager->create( [ 'affiliate_id' => $affiliate, 'amount' => 5, 'source' => Commission::SOURCE_MANUAL, 'order_id' => 987654 ] );

		$this->assertInstanceOf( Commission::class, $manual, 'the manual origin refers to nothing the plugin can check' );

		$this->assertWPError( $manager->update( $real->get_id(), [ 'order_id' => 987655 ] ), 'the check holds on edit too' );
		$this->assertSame( $order, $manager->get( $real->get_id() )->get( 'order_id' ) );
		$this->assertInstanceOf( Commission::class, $manager->update( $manual->get_id(), [ 'order_id' => 987655 ] ) );
	}

	/**
	 * A commission that came from checkout keeps the order item's reference.
	 *
	 * @return void
	 */
	public function test_the_reference_of_an_attributed_commission_is_fixed(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'source' => Commission::SOURCE_WOOCOMMERCE, 'order_id' => 55, 'order_item_id' => 9001 ] );

		$this->assertWPError( flyaffiliate()->commission->update( $commission->get_id(), [ 'order_id' => 56 ] ) );
		$this->assertSame( 55, flyaffiliate()->commission->get( $commission->get_id() )->get( 'order_id' ) );

		// The same reference is not a change.
		$this->assertInstanceOf( Commission::class, flyaffiliate()->commission->update( $commission->get_id(), [ 'order_id' => 55, 'amount' => 3 ] ) );
	}

	/**
	 * Two manual commissions do not collide on the unique order item constraint.
	 *
	 * @return void
	 */
	public function test_two_manual_commissions_can_coexist(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$first  = flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 5 ] );
		$second = flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 7 ] );

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

		$this->assertWPError( flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => 0 ] ) );
		$this->assertWPError( flyaffiliate()->commission->create( [ 'affiliate_id' => $affiliate->get_id(), 'amount' => -3 ] ) );
	}

	/**
	 * Outside a payment, any status can become any other, as in SliceWP.
	 *
	 * @return void
	 */
	public function test_allowed_transitions(): void {
		$manager = flyaffiliate()->commission;

		$this->assertTrue( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_UNPAID ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_REJECTED ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_UNPAID, Commission::STATUS_REJECTED ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_UNPAID, Commission::STATUS_PENDING ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_PENDING, Commission::STATUS_PAID ), 'an admin can record a commission as paid by hand' );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_REJECTED, Commission::STATUS_PENDING ), 'a rejected commission can come back, as in SliceWP' );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_REJECTED, Commission::STATUS_UNPAID ) );
		$this->assertTrue( $manager->can_transition( Commission::STATUS_PAID, Commission::STATUS_UNPAID ), 'and take that back: the lock is the payment, not the status' );
		$this->assertFalse( $manager->can_transition( Commission::STATUS_PAID, Commission::STATUS_PAID ), 'no move at all' );
		$this->assertFalse( $manager->can_transition( Commission::STATUS_UNPAID, 'approved' ), 'only the four statuses' );
	}

	/**
	 * A commission inside a payment is edited like any other, as in SliceWP; the
	 * payment follows while unpaid, keeps its amount once paid, and keeps the
	 * automatic movers and deletion off the commission.
	 *
	 * @return void
	 */
	public function test_a_commission_inside_a_payment_is_edited_by_the_admin_only(): void {
		$manager   = flyaffiliate()->commission;
		$affiliate = $this->factory()->affiliate->create();
		$payout    = $this->factory()->payout->create( [ 'affiliate_id' => $affiliate, 'status' => Payout::STATUS_UNPAID, 'amount' => 50 ] );
		$first     = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'status' => Commission::STATUS_UNPAID, 'source' => Commission::SOURCE_MANUAL, 'amount' => 20, 'payout_id' => $payout ] );
		$second    = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'status' => Commission::STATUS_UNPAID, 'source' => Commission::SOURCE_MANUAL, 'amount' => 30, 'payout_id' => $payout ] );

		$this->assertInstanceOf( Commission::class, $manager->update( $first, [ 'amount' => 25 ] ), 'the amount is open to the admin' );
		$this->assertCentsEquals( 5500, flyaffiliate()->payout->get( $payout )->get( 'amount' ), 'an unpaid payment re-sums to follow' );

		$this->assertInstanceOf( Commission::class, $manager->set_status( $second, Commission::STATUS_REJECTED ), 'and so is the status' );
		$this->assertCentsEquals( 2500, flyaffiliate()->payout->get( $payout )->get( 'amount' ), 'a rejected commission no longer counts' );

		$automatic = $manager->set_status( $first, Commission::STATUS_REJECTED, true );

		$this->assertWPError( $automatic, 'an order status change or the maturation job leaves it alone' );
		$this->assertSame( 'flyaffiliate_commission_in_payout', $automatic->get_error_code() );
		$this->assertSame( Commission::STATUS_UNPAID, $manager->get( $first )->get( 'status' ) );

		$this->assertFalse( $manager->delete( $first ), 'a held commission is not deleted' );

		flyaffiliate()->payout->mark_paid( $payout );

		$this->assertInstanceOf( Commission::class, $manager->update( $first, [ 'amount' => 1 ] ), 'a paid payment keeps the commission editable, as in SliceWP' );
		$this->assertCentsEquals( 2500, flyaffiliate()->payout->get( $payout )->get( 'amount' ), 'but keeps the amount it was paid with' );

		$by_hand = $this->factory()->commission->create_and_get_model( [ 'status' => Commission::STATUS_PAID, 'source' => Commission::SOURCE_MANUAL, 'amount' => 20 ] );

		$this->assertFalse( $by_hand->is_locked(), 'a paid row recorded outside any payment is held by nothing' );
		$this->assertTrue( $manager->delete( $by_hand->get_id() ) );
	}

	/**
	 * Rows that tie on the sort column keep a stable order: by id, in the same direction.
	 *
	 * @return void
	 */
	public function test_query_breaks_ties_by_id(): void {
		$affiliate = $this->factory()->affiliate->create();
		$ids       = [];

		foreach ( [ 1, 2, 3 ] as $i ) {
			$ids[] = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'created_at' => '2026-03-01 12:00:00', 'amount' => $i ] );
		}

		$pluck = static fn( array $rows ): array => array_map( static fn( Commission $row ): int => $row->get_id(), $rows );

		$this->assertSame( array_reverse( $ids ), $pluck( Commission::query( [ 'where' => [ 'affiliate_id' => $affiliate ], 'orderby' => 'created_at', 'order' => 'DESC' ] ) ) );
		$this->assertSame( $ids, $pluck( Commission::query( [ 'where' => [ 'affiliate_id' => $affiliate ], 'orderby' => 'created_at', 'order' => 'ASC' ] ) ) );
	}

	/**
	 * The amount of a WooCommerce commission is editable too, as in SliceWP (ADR-0011).
	 *
	 * @return void
	 */
	public function test_a_calculated_commission_amount_can_be_edited(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'source' => Commission::SOURCE_WOOCOMMERCE, 'base_amount' => 200, 'status' => Commission::STATUS_UNPAID ] );

		$edited = flyaffiliate()->commission->update( $commission->get_id(), [ 'amount' => 99 ] );

		$this->assertInstanceOf( Commission::class, $edited );
		$this->assertCentsEquals( 9900, $edited->get( 'amount' ) );
		$this->assertSame( 49.5, $edited->get( 'rate' ) );
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
