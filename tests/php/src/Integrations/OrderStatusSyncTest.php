<?php
/**
 * Commission statuses follow their order, as in SliceWP.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations;

use FlyAffiliate\Integrations\WooCommerce\OrderStatusSync;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * A paid order makes commissions unpaid at once; failed, cancelled and trashed orders reject; a recovering order restores; a refund rejects only when the switch is on.
 */
class OrderStatusSyncTest extends FlyAffiliateTestCase {

	/**
	 * An order with a pending, an unpaid and a paid WooCommerce commission, and a manual one.
	 *
	 * Every row is still inside a 30-day hold: the status must not care.
	 *
	 * @param string $status The order's starting status.
	 *
	 * @return array{order: \WC_Order, pending: int, unpaid: int, paid: int, manual: int}
	 */
	private function referred_order( string $status = 'on-hold' ): array {
		$product    = $this->factory()->product->create();
		$order      = wc_get_order( $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => $status ] ) );
		$matures_at = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$row        = static function ( array $args ) use ( $order, $matures_at ): array {
			return array_merge(
				[
					'order_id'   => $order->get_id(),
					'source'     => Commission::SOURCE_WOOCOMMERCE,
					'matures_at' => $matures_at,
				],
				$args
			);
		};

		return [
			'order'   => $order,
			'pending' => $this->factory()->commission->create( $row( [ 'status' => Commission::STATUS_PENDING ] ) ),
			'unpaid'  => $this->factory()->commission->create( $row( [ 'status' => Commission::STATUS_UNPAID ] ) ),
			'paid'    => $this->factory()->commission->create( $row( [ 'status' => Commission::STATUS_PAID ] ) ),
			'manual'  => $this->factory()->commission->create( $row( [ 'status' => Commission::STATUS_PENDING, 'source' => Commission::SOURCE_MANUAL ] ) ),
		];
	}

	/**
	 * The status of a commission.
	 *
	 * @param int $id The commission.
	 *
	 * @return string
	 */
	private function status( int $id ): string {
		return (string) flyaffiliate()->commission->get( $id )->get( 'status' );
	}

	/**
	 * The sync the container built, with its hooks registered.
	 *
	 * @return OrderStatusSync
	 */
	private function sync(): OrderStatusSync {
		return flyaffiliate()->get_container()->get( OrderStatusSync::class );
	}

	/**
	 * A failed or cancelled order rejects what is not paid yet.
	 *
	 * @return void
	 */
	public function test_failed_and_cancelled_orders_reject_unpaid_commissions(): void {
		foreach ( [ 'failed', 'cancelled' ] as $status ) {
			$rows = $this->referred_order();

			$rows['order']->update_status( $status );

			$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['pending'] ), $status );
			$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['unpaid'] ), $status );
			$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ), 'paid is never touched' );
			$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['manual'] ), 'a manual commission is never touched' );
		}
	}

	/**
	 * A paid order makes its pending commissions unpaid on the spot, whatever the hold (ADR-0014).
	 *
	 * @return void
	 */
	public function test_a_paid_order_makes_its_commissions_unpaid_at_once(): void {
		$rows = $this->referred_order( 'on-hold' );

		$rows['order']->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['pending'] ), 'the hold period does not delay the status' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['unpaid'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ), 'paid is never touched' );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['manual'] ), 'a manual commission is never touched' );
		$this->assertFalse( flyaffiliate()->commission->get( $rows['pending'] )->is_matured(), 'still inside the hold, so a payout leaves it out' );

		// Firing again changes nothing.
		$this->assertSame( 0, $this->sync()->handle_status_change( $rows['order']->get_id(), 'on-hold', 'completed', $rows['order'] ) );

		// Processing counts as paid too, except for cash on delivery, which waits for completed.
		$card = $this->referred_order( 'on-hold' );
		$card['order']->update_status( 'processing' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $card['pending'] ) );

		$cod = $this->referred_order( 'on-hold' );
		$cod['order']->set_payment_method( 'cod' );
		$cod['order']->save();
		$cod['order']->update_status( 'processing' );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $cod['pending'] ), 'cash on delivery in processing has not been paid' );
		$cod['order']->update_status( 'completed' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $cod['pending'] ) );
	}

	/**
	 * An order that is already paid when its commissions are created makes them unpaid right after attribution.
	 *
	 * @return void
	 */
	public function test_attribution_on_an_order_already_paid_makes_the_commissions_unpaid(): void {
		$paid    = $this->referred_order( 'processing' );
		$waiting = $this->referred_order( 'on-hold' );

		do_action( 'flyaffiliate_order_attributed', $paid['order'], null, [] );
		do_action( 'flyaffiliate_order_attributed', $waiting['order'], null, [] );

		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $paid['pending'] ) );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $paid['manual'] ) );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $waiting['pending'] ), 'an unpaid order keeps its commissions pending' );
	}

	/**
	 * A pending commission whose order cannot be found is never made unpaid.
	 *
	 * @return void
	 */
	public function test_a_commission_whose_order_cannot_be_found_stays_pending(): void {
		$commission = $this->factory()->commission->create( [ 'order_id' => 999999, 'source' => Commission::SOURCE_WOOCOMMERCE, 'status' => Commission::STATUS_PENDING ] );

		$this->assertSame( 0, $this->sync()->handle_status_change( 999999, 'on-hold', 'completed' ) );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $commission ), 'no order to confirm, so the commission holds' );
	}

	/**
	 * An order leaving failed for a status that is not paid restores its commissions to pending; paying it makes them unpaid.
	 *
	 * @return void
	 */
	public function test_a_recovering_order_restores_its_commissions(): void {
		$rows = $this->referred_order( 'pending' );

		$rows['order']->update_status( 'failed' );
		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['pending'] ) );

		$rows['order']->update_status( 'on-hold' );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['pending'] ) );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['unpaid'] ), 'restored to pending, as SliceWP does' );

		// Cancelled, then straight to completed: unpaid on the same change, hold or no hold.
		$rows['order']->update_status( 'cancelled' );
		$rows['order']->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['pending'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ) );
	}

	/**
	 * A refund changes nothing until the switch is on; then it rejects what is not paid, like a failed order.
	 *
	 * @return void
	 */
	public function test_a_refund_rejects_only_when_the_setting_is_on(): void {
		$rows = $this->referred_order( 'completed' );

		$rows['order']->update_status( 'refunded' );

		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['pending'] ), 'off by default, as in SliceWP' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['unpaid'] ) );

		flyaffiliate()->settings->save( [ 'reject_commissions_on_refund' => 'on' ] );
		$rows = $this->referred_order( 'completed' );

		$rows['order']->update_status( 'refunded' );

		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['pending'] ) );
		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['unpaid'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ), 'paid is never touched' );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['manual'] ), 'a manual commission is never touched' );

		// Back from refunded: restored to pending, as SliceWP does.
		$rows['order']->update_status( 'on-hold' );
		$this->assertSame( Commission::STATUS_PENDING, $this->status( $rows['unpaid'] ) );
	}

	/**
	 * An order being paid restores a commission an admin rejected — SliceWP marks every
	 * unpaid commission of an accepted order unpaid, whoever rejected it. Cash on delivery
	 * waits for completed.
	 *
	 * @return void
	 */
	public function test_a_paid_order_restores_an_admin_rejected_commission(): void {
		$rows = $this->referred_order( 'on-hold' );
		flyaffiliate()->commission->set_status( $rows['pending'], Commission::STATUS_REJECTED );
		flyaffiliate()->commission->set_status( $rows['unpaid'], Commission::STATUS_REJECTED );

		$rows['order']->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['pending'] ), 'straight to unpaid, whatever the hold' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['unpaid'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ) );

		$cod = $this->referred_order( 'on-hold' );
		$cod['order']->set_payment_method( 'cod' );
		$cod['order']->save();
		flyaffiliate()->commission->set_status( $cod['pending'], Commission::STATUS_REJECTED );

		$cod['order']->update_status( 'processing' );
		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $cod['pending'] ), 'cash on delivery in processing is not accepted yet' );

		$cod['order']->update_status( 'completed' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $cod['pending'] ) );
	}

	/**
	 * A trashed order rejects what is not paid yet, and a second firing changes nothing.
	 *
	 * @return void
	 */
	public function test_a_trashed_order_rejects_unpaid_commissions(): void {
		$rows = $this->referred_order( 'processing' );

		$rows['order']->delete( false );

		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['pending'] ) );
		$this->assertSame( Commission::STATUS_REJECTED, $this->status( $rows['unpaid'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ) );

		$this->assertSame( 0, $this->sync()->handle_trash( $rows['order']->get_id() ) );
	}
}
