<?php
/**
 * Payout manager tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Payout;

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Payout;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Batches: what gets included, what gets written, the two steps to paid, and that nothing is paid twice.
 *
 * @group payout
 *
 * @since FLYAFFILIATE_SINCE
 */
class ManagerTest extends FlyAffiliateTestCase {

	/**
	 * Only unpaid commissions are paid. Pending, rejected and paid are left alone.
	 *
	 * @return void
	 */
	public function test_preview_includes_only_unpaid_commissions(): void {
		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 20, 'status' => Commission::STATUS_PENDING ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 30, 'status' => Commission::STATUS_REJECTED ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 40, 'status' => Commission::STATUS_PAID ] );

		$preview = flyaffiliate()->payout->preview( [ 'minimum_amount' => 0 ] );

		$this->assertSame( 1, $preview['count'] );
		$this->assertCentsEquals( 1000, $preview['total'] );
		$this->assertCount( 1, $preview['rows'][ $affiliate ]['commissions'] );
	}

	/**
	 * The minimum leaves an affiliate out; the selection modes include and exclude.
	 *
	 * @return void
	 */
	public function test_minimum_and_selection_modes(): void {
		$rich = $this->factory()->affiliate->create();
		$poor = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $rich, 'amount' => 100, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $poor, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );

		$this->assertSame( [ $rich ], array_keys( flyaffiliate()->payout->preview( [ 'minimum_amount' => 50 ] )['rows'] ) );
		$this->assertSame( [ $poor ], array_keys( flyaffiliate()->payout->preview( [ 'minimum_amount' => 0, 'mode' => 'selected', 'affiliate_ids' => [ $poor ] ] )['rows'] ) );
		$this->assertSame( [ $rich ], array_keys( flyaffiliate()->payout->preview( [ 'minimum_amount' => 0, 'mode' => 'except', 'affiliate_ids' => [ $poor ] ] )['rows'] ) );
		$this->assertSame( 0, flyaffiliate()->payout->preview( [ 'minimum_amount' => 0, 'mode' => 'selected', 'affiliate_ids' => [] ] )['count'], 'selected with nobody selected pays nobody' );
	}

	/**
	 * A period bounds the commissions by creation date.
	 *
	 * @return void
	 */
	public function test_period_bounds(): void {
		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID, 'created_at' => '2026-01-15 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 20, 'status' => Commission::STATUS_UNPAID, 'created_at' => '2026-03-15 12:00:00' ] );

		$preview = flyaffiliate()->payout->preview( [ 'minimum_amount' => 0, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31' ] );

		$this->assertCentsEquals( 2000, $preview['total'] );
	}

	/**
	 * Creating a batch writes one unpaid payment per affiliate and attaches the commissions through
	 * payout_id; they stay unpaid until the payment is marked paid.
	 *
	 * @return void
	 */
	public function test_create_writes_unpaid_payments_and_attaches_commissions(): void {
		$a = $this->factory()->affiliate->create();
		$b = $this->factory()->affiliate->create();

		$c1 = $this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$c2 = $this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );
		$c3 = $this->factory()->commission->create( [ 'affiliate_id' => $b, 'amount' => 7, 'status' => Commission::STATUS_UNPAID ] );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0, 'note' => 'March' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['payouts'] );
		$this->assertCentsEquals( 2200, $result['total'] );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 2, [ 'batch_key' => $result['batch_key'] ] );

		foreach ( [ $c1, $c2, $c3 ] as $id ) {
			$commission = flyaffiliate()->commission->get( $id );

			$this->assertSame( Commission::STATUS_UNPAID, $commission->get( 'status' ), 'attached, not paid' );
			$this->assertGreaterThan( 0, (int) $commission->get( 'payout_id' ) );
		}

		$payout_a = $result['payouts'][0];

		$this->assertSame( Payout::STATUS_UNPAID, $payout_a->get( 'status' ) );
		$this->assertCentsEquals( 1500, $payout_a->get( 'amount' ) );
		$this->assertCount( 2, $payout_a->get_commissions() );
	}

	/**
	 * Marking a payment paid marks its commissions paid; a second call changes nothing;
	 * marking it unpaid again puts them back, still attached.
	 *
	 * @return void
	 */
	public function test_mark_paid_and_unpaid_move_the_commissions(): void {
		$affiliate = $this->factory()->affiliate->create();
		$c1        = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$result    = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$payout_id = $result['payouts'][0]->get_id();
		$changes   = [];

		add_action(
			'flyaffiliate_commission_status_changed',
			static function ( $commission, $status, $from ) use ( &$changes ) {
				$changes[] = $from . '>' . $status;
			},
			10,
			3
		);

		$paid = flyaffiliate()->payout->mark_paid( $payout_id );

		$this->assertInstanceOf( Payout::class, $paid );
		$this->assertTrue( $paid->is_paid() );
		$this->assertSame( Commission::STATUS_PAID, flyaffiliate()->commission->get( $c1 )->get( 'status' ) );

		flyaffiliate()->payout->mark_paid( $payout_id );
		$this->assertSame( [ 'unpaid>paid' ], $changes, 'a second marking fires nothing' );

		$unpaid = flyaffiliate()->payout->mark_unpaid( $payout_id );

		$this->assertFalse( $unpaid->is_paid() );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $c1 )->get( 'status' ) );
		$this->assertSame( $payout_id, (int) flyaffiliate()->commission->get( $c1 )->get( 'payout_id' ), 'still attached, so never paid twice' );
		$this->assertSame( 0, flyaffiliate()->payout->preview( [ 'minimum_amount' => 0 ] )['count'] );
	}

	/**
	 * Paying a batch marks every unpaid payment in it paid.
	 *
	 * @return void
	 */
	public function test_pay_batch_marks_every_payment_paid(): void {
		$a = $this->factory()->affiliate->create();
		$b = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'amount' => 20, 'status' => Commission::STATUS_UNPAID ] );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertSame( 2, flyaffiliate()->payout->pay_batch( $result['batch_key'] ) );
		$this->assertSame( 0, flyaffiliate()->payout->pay_batch( $result['batch_key'] ), 'nothing left to pay' );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 2, [ 'status' => Commission::STATUS_PAID ] );

		$batches = flyaffiliate()->payout->get_batches();

		$this->assertSame( 2, $batches['batches'][0]['paid'] );
		$this->assertCentsEquals( 3000, $batches['batches'][0]['paid_total'] );
	}

	/**
	 * A commission taken out of an unpaid payment goes back into the next batch, and the
	 * payment's amount drops by it; a paid payment refuses.
	 *
	 * @return void
	 */
	public function test_remove_commission_from_an_unpaid_payment(): void {
		$affiliate = $this->factory()->affiliate->create();
		$c1        = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$c2        = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );
		$result    = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$payout_id = $result['payouts'][0]->get_id();

		$payout = flyaffiliate()->payout->remove_commission( $payout_id, $c2 );

		$this->assertInstanceOf( Payout::class, $payout );
		$this->assertCentsEquals( 1000, $payout->get( 'amount' ) );
		$this->assertSame( 0, (int) flyaffiliate()->commission->get( $c2 )->get( 'payout_id' ) );
		$this->assertCentsEquals( 500, flyaffiliate()->payout->preview( [ 'minimum_amount' => 0 ] )['total'], 'back in the next batch' );
		$this->assertWPError( flyaffiliate()->payout->remove_commission( $payout_id, $c2 ), 'no longer part of it' );

		flyaffiliate()->payout->mark_paid( $payout_id );

		$this->assertWPError( flyaffiliate()->payout->remove_commission( $payout_id, $c1 ), 'paid payments are locked' );
	}

	/**
	 * Deleting an unpaid payment detaches its commissions; a paid one, or a batch with a paid
	 * payment, cannot be deleted.
	 *
	 * @return void
	 */
	public function test_delete_detaches_and_paid_refuses(): void {
		$a = $this->factory()->affiliate->create();
		$b = $this->factory()->affiliate->create();

		$c1 = $this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $b, 'amount' => 20, 'status' => Commission::STATUS_UNPAID ] );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		list( $payout_a, $payout_b ) = $result['payouts'];

		$this->assertTrue( flyaffiliate()->payout->delete( $payout_a->get_id() ) );
		$this->assertSame( 0, (int) flyaffiliate()->commission->get( $c1 )->get( 'payout_id' ) );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $c1 )->get( 'status' ) );

		flyaffiliate()->payout->mark_paid( $payout_b->get_id() );

		$this->assertWPError( flyaffiliate()->payout->delete( $payout_b->get_id() ) );
		$this->assertWPError( flyaffiliate()->payout->delete_batch( $result['batch_key'] ) );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 1 );

		$this->factory()->commission->create( [ 'affiliate_id' => $a, 'amount' => 1, 'status' => Commission::STATUS_UNPAID ] );
		$second = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertSame( 1, flyaffiliate()->payout->delete_batch( $second['batch_key'] ) );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 1 );
	}

	/**
	 * Running the same batch again pays nothing: every commission already carries a payout_id.
	 *
	 * @return void
	 */
	public function test_a_second_batch_pays_nothing(): void {
		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );

		$first  = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$second = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertIsArray( $first );
		$this->assertWPError( $second );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 1 );
	}

	/**
	 * A commission that somehow reads unpaid but already has a payout_id is never paid again.
	 *
	 * @return void
	 */
	public function test_a_commission_with_a_payout_id_is_never_included(): void {
		$affiliate = $this->factory()->affiliate->create();
		$payout    = $this->factory()->payout->create( [ 'affiliate_id' => $affiliate ] );

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID, 'payout_id' => $payout ] );

		$this->assertSame( 0, flyaffiliate()->payout->preview( [ 'minimum_amount' => 0 ] )['count'] );
	}

	/**
	 * Batches are listed newest first with their totals.
	 *
	 * @return void
	 */
	public function test_get_batches(): void {
		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		flyaffiliate()->payout->create( [ 'minimum_amount' => 0, 'note' => 'First' ] );

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 20, 'status' => Commission::STATUS_UNPAID ] );
		flyaffiliate()->payout->create( [ 'minimum_amount' => 0, 'note' => 'Second' ] );

		$batches = flyaffiliate()->payout->get_batches();

		$this->assertSame( 2, $batches['total'] );
		$this->assertCount( 2, $batches['batches'] );
		$this->assertSame( 1, $batches['batches'][0]['affiliates'] );
	}

	/**
	 * The CSV export neutralises formula-shaped cells.
	 *
	 * @return void
	 */
	public function test_csv_rows_escape_formulas(): void {
		$affiliate = $this->factory()->affiliate->create();
		$payout    = $this->factory()->payout->create_and_get_model( [ 'affiliate_id' => $affiliate, 'reference' => '=HYPERLINK("x")' ] );

		$rows = ( new \FlyAffiliate\Payout\CsvExporter() )->get_rows( [ $payout ] );

		$this->assertSame( "'=HYPERLINK(\"x\")", $rows[0][8] );
	}

	/**
	 * A refunded order leaves a commission that a payment is already counting alone.
	 *
	 * @return void
	 */
	public function test_an_order_cannot_reject_a_commission_inside_an_unpaid_payment(): void {
		$affiliate  = $this->factory()->affiliate->create();
		$commission = $this->factory()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'amount'       => 40,
				'status'       => Commission::STATUS_UNPAID,
				'order_id'     => 4242,
				'source'       => Commission::SOURCE_WOOCOMMERCE,
			]
		);

		flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		// What a refund, a cancellation or a trashed order asks for.
		$refused = flyaffiliate()->commission->set_status( $commission, Commission::STATUS_REJECTED );

		$this->assertWPError( $refused );
		$this->assertSame( 'flyaffiliate_commission_in_payout', $refused->get_error_code() );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $commission )->get( 'status' ) );
	}

	/**
	 * Marking a payment paid pays what is still owed, and nothing else.
	 *
	 * @return void
	 */
	public function test_mark_paid_pays_only_the_commissions_still_unpaid(): void {
		$affiliate = $this->factory()->affiliate->create();
		$owed      = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$dead      = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 20, 'status' => Commission::STATUS_UNPAID ] );

		$payout = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] )['payouts'][0];

		/*
		 * A row rejected while the payment waited: the lock keeps this from
		 * happening now, and rows written before it must not be paid either.
		 */
		$rejected = flyaffiliate()->commission->get( $dead );
		$rejected->set( 'status', Commission::STATUS_REJECTED );
		$rejected->save();

		flyaffiliate()->payout->mark_paid( $payout->get_id() );

		$this->assertSame( Commission::STATUS_PAID, flyaffiliate()->commission->get( $owed )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_REJECTED, flyaffiliate()->commission->get( $dead )->get( 'status' ), 'a rejected commission is never paid' );
	}

	/**
	 * A commission inside an unpaid payment cannot be edited or deleted, and leaving the payment frees it.
	 *
	 * @return void
	 */
	public function test_a_commission_inside_a_payment_holds_still(): void {
		$affiliate  = $this->factory()->affiliate->create();
		$commission = $this->factory()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'amount'       => 25,
				'base_amount'  => 100,
				'status'       => Commission::STATUS_UNPAID,
				'source'       => Commission::SOURCE_MANUAL,
			]
		);

		$payout = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] )['payouts'][0];

		$this->assertWPError( flyaffiliate()->commission->update( $commission, [ 'amount' => 5 ] ) );
		$this->assertFalse( flyaffiliate()->commission->delete( $commission ) );
		$this->assertCentsEquals( 2500, flyaffiliate()->commission->get( $commission )->get( 'amount' ) );

		// Out of the payment, it is an ordinary unpaid commission again.
		flyaffiliate()->payout->remove_commission( $payout->get_id(), $commission );

		$this->assertNotWPError( flyaffiliate()->commission->set_status( $commission, Commission::STATUS_REJECTED ) );
		$this->assertTrue( flyaffiliate()->commission->delete( $commission ) );
	}
}
