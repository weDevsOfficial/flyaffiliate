<?php
/**
 * The maturation job.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Commission;

use FlyAffiliate\Commission\HoldPeriod;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Pending becomes unpaid when the hold is over and the order allows it.
 */
class HoldPeriodTest extends FlyAffiliateTestCase {

	/**
	 * A due WooCommerce commission on a completed order matures; a pending order holds it.
	 *
	 * @return void
	 */
	public function test_due_commissions_mature_only_with_a_paid_order(): void {
		$product   = $this->factory()->product->create();
		$completed = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$pending   = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'pending' ] );
		$past      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$future    = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$due       = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
		$held      = $this->factory()->commission->create( [ 'order_id' => $pending, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
		$early     = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $future ] );
		$manual    = $this->factory()->commission->create( [ 'order_id' => 0, 'source' => Commission::SOURCE_MANUAL, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );

		$matured = ( new HoldPeriod() )->run();

		$this->assertSame( 2, $matured );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $due )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $manual )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $held )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $early )->get( 'status' ) );

		// Running again changes nothing.
		$this->assertSame( 0, ( new HoldPeriod() )->run() );
	}

	/**
	 * A due commission behind a full page of held ones is still reached.
	 *
	 * @return void
	 */
	public function test_pages_past_a_full_page_of_held_commissions(): void {
		$product   = $this->factory()->product->create();
		$pending   = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'pending' ] );
		$completed = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$past      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->factory()->commission->create_many(
			HoldPeriod::PER_PAGE + 1,
			[ 'order_id' => $pending, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ]
		);
		$due = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );

		$this->assertSame( 1, ( new HoldPeriod() )->run() );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $due )->get( 'status' ) );
		$this->assertSame( HoldPeriod::PER_PAGE + 1, flyaffiliate()->commission->count( [ 'where' => [ 'status' => Commission::STATUS_PENDING ] ] ) );
	}

	/**
	 * Held commissions scattered through the pages do not hide the due ones behind them.
	 *
	 * The job pages through the due rows while maturing them, so the matured
	 * ones leave the result set and the held ones stay at its head. The offset
	 * has to be how many rows are stuck, not how many times one was skipped.
	 *
	 * @return void
	 */
	public function test_held_commissions_do_not_hide_the_due_ones_behind_them(): void {
		$product   = $this->factory()->product->create();
		$pending   = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'pending' ] );
		$completed = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$past      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$due       = [];

		// Held, due, held, due, held, due — read two rows at a time.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory()->commission->create( [ 'order_id' => $pending, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
			$due[] = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
		}

		$job = new class() extends HoldPeriod {
			const PER_PAGE = 2;
		};

		$this->assertSame( 3, $job->run() );

		foreach ( $due as $id ) {
			$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $id )->get( 'status' ) );
		}

		$this->assertSame( 3, flyaffiliate()->commission->count( [ 'where' => [ 'status' => Commission::STATUS_PENDING ] ] ), 'the held ones are all that is left' );
	}

	/**
	 * An order reaching completed matures its due commissions at once, like
	 * SliceWP marking them unpaid; a hold that has not ended keeps them pending.
	 *
	 * @return void
	 */
	public function test_an_order_completing_matures_its_due_commissions(): void {
		$product = $this->factory()->product->create();
		$order   = wc_get_order( $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'on-hold' ] ) );
		$past    = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$future  = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$due    = $this->factory()->commission->create( [ 'order_id' => $order->get_id(), 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
		$held   = $this->factory()->commission->create( [ 'order_id' => $order->get_id(), 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $future ] );
		$manual = $this->factory()->commission->create( [ 'order_id' => $order->get_id(), 'source' => Commission::SOURCE_MANUAL, 'status' => Commission::STATUS_PENDING, 'matures_at' => $future ] );

		// The real status change, through the hook the plugin registered.
		$order->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $due )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $held )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $manual )->get( 'status' ), 'a manual commission waits for its own date' );

		// Firing again changes nothing.
		$this->assertSame( 0, ( new HoldPeriod() )->handle_order_status_change( $order->get_id(), 'on-hold', 'completed', $order ) );
	}

	/**
	 * A status that is not paid yet, and cash on delivery in processing, mature nothing.
	 *
	 * @return void
	 */
	public function test_unpaid_statuses_and_cash_on_delivery_wait(): void {
		$product = $this->factory()->product->create();
		$past    = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$cod     = wc_get_order( $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'processing' ] ) );
		$cod->set_payment_method( 'cod' );
		$cod->save();

		$commission = $this->factory()->commission->create( [ 'order_id' => $cod->get_id(), 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'matures_at' => $past ] );
		$period     = new HoldPeriod();

		$this->assertSame( 0, $period->handle_order_status_change( $cod->get_id(), 'pending', 'on-hold', $cod ) );
		$this->assertSame( 0, $period->handle_order_status_change( $cod->get_id(), 'pending', 'processing', $cod ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $commission )->get( 'status' ) );

		$cod->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $commission )->get( 'status' ) );
	}

	/**
	 * A pending commission added by hand keeps its status, as in SliceWP: the
	 * order reaching a paid status, or the job, is what matures it.
	 *
	 * @return void
	 */
	public function test_a_pending_commission_added_by_hand_stays_pending_until_its_order_is_paid(): void {
		flyaffiliate()->settings->save( [ 'hold_days' => 0 ] );

		$affiliate = $this->factory()->affiliate->create();
		$product   = $this->factory()->product->create();
		$completed = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$on_hold   = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'on-hold' ] );

		// Bug 1: the order is already complete and the hold is zero, yet the
		// commission is created pending and shows up pending.
		$settled = flyaffiliate()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'amount'       => 25,
				'order_id'     => $completed,
				'source'       => Commission::SOURCE_WOOCOMMERCE,
				'status'       => Commission::STATUS_PENDING,
			]
		);

		$this->assertNotWPError( $settled );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $settled->get_id() )->get( 'status' ) );

		// Bug 2: a pending commission on an order that is not paid yet becomes
		// unpaid when the order completes.
		$waiting = flyaffiliate()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'amount'       => 25,
				'order_id'     => $on_hold,
				'source'       => Commission::SOURCE_WOOCOMMERCE,
				'status'       => Commission::STATUS_PENDING,
			]
		);
		$manual  = flyaffiliate()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'amount'       => 25,
				'order_id'     => $on_hold,
				'source'       => Commission::SOURCE_MANUAL,
				'status'       => Commission::STATUS_PENDING,
			]
		);

		wc_get_order( $on_hold )->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $waiting->get_id() )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $manual->get_id() )->get( 'status' ), 'a manual-origin commission does not follow the order' );

		// The job matures what is due once the hold is over (money rule 4).
		( new HoldPeriod() )->run();

		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $settled->get_id() )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $manual->get_id() )->get( 'status' ) );
	}

	/**
	 * A pending commission whose order cannot be found never matures.
	 *
	 * The same branch answers for a site with no WooCommerce at all, where
	 * `wc_get_order()` does not exist: `can_mature()` cannot confirm the order,
	 * so it holds. That is deliberate — a store that deactivates WooCommerce for
	 * an hour must not have unverified commissions mature — and it is why
	 * `Integration::add_source()` keeps an inactive platform out of first place,
	 * so nobody creates a row that can never move without meaning to.
	 *
	 * @return void
	 */
	public function test_a_pending_commission_whose_order_cannot_be_found_never_matures(): void {
		flyaffiliate()->settings->save( [ 'hold_days' => 0 ] );

		$affiliate  = $this->factory()->affiliate->create();
		$commission = $this->factory()->commission->create(
			[
				'affiliate_id' => $affiliate,
				'order_id'     => 999999,
				'source'       => Commission::SOURCE_WOOCOMMERCE,
				'status'       => Commission::STATUS_PENDING,
				'matures_at'   => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			]
		);

		( new HoldPeriod() )->run();

		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $commission )->get( 'status' ), 'no order to confirm, so the commission holds' );
	}

	/**
	 * Changing the hold period moves pending maturity dates and matures what is now due.
	 *
	 * @return void
	 */
	public function test_changing_the_hold_period_reschedules_pending_commissions(): void {
		$product   = $this->factory()->product->create();
		$completed = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$created   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$later     = gmdate( 'Y-m-d H:i:s', time() + 28 * DAY_IN_SECONDS );

		$pending = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING, 'created_at' => $created, 'matures_at' => $later ] );
		$paid    = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PAID, 'created_at' => $created, 'matures_at' => $later ] );

		$saved = flyaffiliate()->settings->save( [ 'hold_days' => 0 ] );

		$this->assertNotWPError( $saved );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $pending )->get( 'status' ) );
		$this->assertSame( $later, (string) flyaffiliate()->commission->get( $paid )->get( 'matures_at' ), 'a paid commission keeps its dates' );

		// Back to a longer hold: a still-pending commission moves out again.
		$another = $this->factory()->commission->create( [ 'order_id' => $completed, 'source' => Commission::SOURCE_MANUAL, 'status' => Commission::STATUS_PENDING, 'created_at' => $created, 'matures_at' => $created ] );

		flyaffiliate()->settings->save( [ 'hold_days' => 10 ] );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $created . ' UTC' ) + 10 * DAY_IN_SECONDS ), (string) flyaffiliate()->commission->get( $another )->get( 'matures_at' ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $another )->get( 'status' ) );
	}
}
