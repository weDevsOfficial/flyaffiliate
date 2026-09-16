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

use FlyAffiliate\Commission\HoldPeriod;
use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;
use WC_Order;
use WP_Error;

/**
 * Rejects and restores commissions as their order fails and recovers, the way
 * SliceWP's WooCommerce integration does.
 *
 * - An order that fails, is cancelled or is trashed rejects its pending and
 *   unpaid commissions.
 * - An order that is refunded does the same when the "reject commissions on
 *   refund" switch is on (off by default, as in SliceWP): otherwise a refund
 *   changes nothing and is handled by hand.
 * - An order reaching processing or completed (cash on delivery: completed)
 *   puts its rejected commissions back to pending, whoever rejected them —
 *   SliceWP marks every unpaid commission of an accepted order unpaid, and
 *   `Commission\HoldPeriod` then matures the restored ones on the same change
 *   once their hold is over.
 * - An order leaving failed, cancelled or refunded for any other status puts
 *   its rejected commissions back to pending too.
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
	 * The hold period, which owns the "may this order's commissions mature" rule.
	 *
	 * @var HoldPeriod
	 */
	protected HoldPeriod $hold_period;

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param HoldPeriod $hold_period The hold period.
	 */
	public function __construct( HoldPeriod $hold_period ) {
		$this->hold_period = $hold_period;
	}

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
		// Before Commission\HoldPeriod (priority 20), so a restored commission can mature on the same change.
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 4 );
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

		$order    = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		$accepted = $order instanceof WC_Order && $this->hold_period->order_can_mature( $order );

		if ( $accepted || ( in_array( $from, self::RECOVERABLE_STATUSES, true ) && ! in_array( $to, self::RECOVERABLE_STATUSES, true ) ) ) {
			return $this->move( $order_id, [ Commission::STATUS_REJECTED ], Commission::STATUS_PENDING );
		}

		return 0;
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
	 * Record a commission the order could not move, so the admin can see why.
	 *
	 * The usual reason is a payment already counting it: the order's change is
	 * real, the money is not clawed back, and someone has to know.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission that stayed put.
	 * @param string     $to         The status the order asked for.
	 * @param WP_Error   $error      Why it was refused.
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
	 * @param string   $to       The status to move them to.
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
			$result = flyaffiliate()->commission->set_status( $commission->get_id(), $to );

			if ( ! is_wp_error( $result ) ) {
				++$moved;
				continue;
			}

			$this->log_refusal( $commission, $to, $result );
		}

		return $moved;
	}
}
