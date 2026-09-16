<?php
/**
 * Commission statuses follow their order, as in SliceWP.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations;

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Failed, cancelled and trashed orders reject; an accepted or recovering order restores; a refund rejects only when the switch is on.
 */
class OrderStatusSyncTest extends FlyAffiliateTestCase {

	/**
	 * An order with a pending, an unpaid and a paid WooCommerce commission, and a manual one.
	 *
	 * @param string $status The order's starting status.
	 * @param bool   $due    Whether the hold is already over.
	 *
	 * @return array{order: \WC_Order, pending: int, unpaid: int, paid: int, manual: int}
	 */
	private function referred_order( string $status = 'on-hold', bool $due = false ): array {
		$product    = $this->factory()->product->create();
		$order      = wc_get_order( $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => $status ] ) );
		$matures_at = gmdate( 'Y-m-d H:i:s', time() + ( $due ? -HOUR_IN_SECONDS : 30 * DAY_IN_SECONDS ) );
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
	 * An order leaving failed restores its commissions; completing it with the hold over makes them unpaid.
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

		// Cancelled, then straight to completed with the hold over: restored, then matured on the same change.
		$rows['order']->update_status( 'cancelled' );
		flyaffiliate()->settings->save( [ 'hold_days' => 0 ] );
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
	 * An order being accepted restores a commission an admin rejected — SliceWP marks every
	 * unpaid commission of an accepted order unpaid, whoever rejected it. Cash on delivery
	 * waits for completed, as it does to mature.
	 *
	 * @return void
	 */
	public function test_an_accepted_order_restores_an_admin_rejected_commission(): void {
		$rows = $this->referred_order( 'on-hold', true );
		flyaffiliate()->commission->set_status( $rows['pending'], Commission::STATUS_REJECTED );
		flyaffiliate()->commission->set_status( $rows['unpaid'], Commission::STATUS_REJECTED );

		$rows['order']->update_status( 'completed' );

		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['pending'] ), 'restored, then matured on the same change' );
		$this->assertSame( Commission::STATUS_UNPAID, $this->status( $rows['unpaid'] ) );
		$this->assertSame( Commission::STATUS_PAID, $this->status( $rows['paid'] ) );

		$cod = $this->referred_order( 'on-hold', true );
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

		$sync = flyaffiliate()->get_container()->get( \FlyAffiliate\Integrations\WooCommerce\OrderStatusSync::class );
		$this->assertSame( 0, $sync->handle_trash( $rows['order']->get_id() ) );
	}
}
