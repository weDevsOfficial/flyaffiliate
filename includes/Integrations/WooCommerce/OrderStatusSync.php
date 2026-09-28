<?php
/**
 * Keeps a referred order's commissions in step with the order's status.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;
use WC_Order;
use WP_Error;

/**
 * Moves commissions as their order is paid, fails and recovers, the way
 * SliceWP's WooCommerce integration does.
 *
 * - An order reaching processing or completed (cash on delivery: completed)
 *   makes its pending and rejected commissions unpaid on the spot, whoever
 *   rejected them. The hold period does not delay this: it decides when a
 *   payout may take the commission (ADR-0014). The same happens right after
 *   checkout attribution when the order is already paid.
 * - An order that fails, is cancelled or is trashed rejects its pending and
 *   unpaid commissions.
 * - An order that is refunded does the same when the "reject commissions on
 *   refund" switch is on (off by default, as in SliceWP): otherwise a refund
 *   changes nothing and is handled by hand.
 * - An order leaving failed, cancelled or refunded for a status that is not
 *   paid puts its rejected commissions back to pending.
 *
 * Paid commissions and manual commissions are never touched. Every change goes
 * through `Commission\Manager::set_status()`, so the status hook fires, and a
 * second firing finds nothing left to change.
 *
 * @since FLYAFFILIATE_SINCE
 */
class OrderStatusSync implements Hookable {

	/**
	 * The setting that makes a refund reject.
	 *
	 * @var string
	 */
	const REFUND_SETTING = 'reject_commissions_on_refund';

	/**
	 * Order statuses under which the order counts as paid.
	 *
	 * @var string[]
	 */
	const PAID_STATUSES = [ 'processing', 'completed' ];

	/**
	 * Order statuses that reject a commission.
	 *
	 * @var string[]
	 */
	const REJECTING_STATUSES = [ 'failed', 'cancelled' ];

	/**
	 * Order statuses a commission is restored from when the order leaves them.
	 *
	 * @var string[]
	 */
	const RECOVERABLE_STATUSES = [ 'failed', 'cancelled', 'refunded' ];

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 4 );
		// An order that is already paid when its commissions are created (a card payment through the block checkout, say).
		add_action( 'flyaffiliate_order_attributed', [ $this, 'handle_attribution' ] );
		// Both order storages fire this when an order is moved to the trash.
		add_action( 'woocommerce_trash_order', [ $this, 'handle_trash' ] );
	}

	/**
	 * React to an order status change.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int           $order_id The order.
	 * @param string        $from     The previous status.
	 * @param string        $to       The new status.
	 * @param WC_Order|null $order    The order, when WooCommerce passes it.
	 *
	 * @return int How many commissions changed.
	 */
	public function handle_status_change( $order_id, $from, $to, $order = null ): int {
		$order_id = absint( $order_id );
		$from     = (string) $from;
		$to       = (string) $to;

		if ( in_array( $to, self::REJECTING_STATUSES, true ) ) {
			return $this->move( $order_id, [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID ], Commission::STATUS_REJECTED );
		}

		if ( 'refunded' === $to ) {
			return flyaffiliate_option_enabled( self::REFUND_SETTING )
				? $this->move( $order_id, [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID ], Commission::STATUS_REJECTED )
				: 0;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof WC_Order && $this->order_is_paid( $order ) ) {
			return $this->approve( $order );
		}

		if ( in_array( $from, self::RECOVERABLE_STATUSES, true ) && ! in_array( $to, self::RECOVERABLE_STATUSES, true ) ) {
			return $this->move( $order_id, [ Commission::STATUS_REJECTED ], Commission::STATUS_PENDING );
		}

		return 0;
	}

	/**
	 * Make the commissions of an order that was already paid at checkout unpaid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return int How many commissions changed.
	 */
	public function handle_attribution( WC_Order $order ): int {
		return $this->order_is_paid( $order ) ? $this->approve( $order ) : 0;
	}

	/**
	 * Reject the commissions of an order moved to the trash.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $order_id The order.
	 *
	 * @return int How many commissions changed.
	 */
	public function handle_trash( $order_id ): int {
		return $this->move( absint( $order_id ), [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID ], Commission::STATUS_REJECTED );
	}

	/**
	 * Whether an order's status means its commissions are earned.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return bool
	 */
	public function order_is_paid( WC_Order $order ): bool {
		$status = $order->get_status();

		// Cash on delivery sits in processing before any money has arrived; SliceWP waits for completed too.
		if ( 'processing' === $status && 'cod' === $order->get_payment_method() ) {
			return false;
		}

		/**
		 * Filters the order statuses under which a commission becomes unpaid.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string[] $statuses Default processing and completed.
		 */
		return in_array( $status, (array) apply_filters( 'flyaffiliate_paid_order_statuses', self::PAID_STATUSES ), true );
	}

	/**
	 * Make a paid order's pending and rejected commissions unpaid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order $order The order, already known to be paid.
	 *
	 * @return int How many commissions changed.
	 */
	protected function approve( WC_Order $order ): int {
		return $this->move( $order->get_id(), [ Commission::STATUS_PENDING, Commission::STATUS_REJECTED ], Commission::STATUS_UNPAID );
	}

	/**
	 * Record why a commission stayed where it was.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 * @param string     $to         The status it was meant to get.
	 * @param WP_Error   $error      The refusal.
	 *
	 * @return void
	 */
	protected function log_refusal( Commission $commission, string $to, WP_Error $error ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->warning(
			sprintf(
				/* translators: 1: commission id, 2: order id, 3: the status asked for, 4: the reason. */
				'Commission #%1$d on order #%2$d was not moved to %3$s: %4$s',
				$commission->get_id(),
				(int) $commission->get( 'order_id', 0 ),
				$to,
				$error->get_error_message()
			),
			[ 'source' => 'flyaffiliate' ]
		);
	}

	/**
	 * Move an order's WooCommerce commissions from some statuses to another.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int      $order_id The order.
	 * @param string[] $from     The statuses to move.
	 * @param string   $to       The status to give them.
	 *
	 * @return int How many commissions changed.
	 */
	protected function move( int $order_id, array $from, string $to ): int {
		if ( 0 === $order_id ) {
			return 0;
		}

		$commissions = flyaffiliate()->commission->query(
			[
				'where'    => [
					'order_id' => $order_id,
					'source'   => Commission::SOURCE_WOOCOMMERCE,
					'status'   => $from,
				],
				'per_page' => -1,
				'orderby'  => 'id',
				'order'    => 'ASC',
			]
		);

		$moved = 0;

		foreach ( $commissions as $commission ) {
			$result = flyaffiliate()->commission->set_status( $commission->get_id(), $to, true );

			if ( ! is_wp_error( $result ) ) {
				++$moved;
				continue;
			}

			$this->log_refusal( $commission, $to, $result );
		}

		return $moved;
	}
}
