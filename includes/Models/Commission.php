<?php
/**
 * The commission model.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of `{prefix}flyaffiliate_commissions`: money owed to one affiliate for
 * one **order item**.
 *
 * Per item, never per order (CONTEXT.md money rule 1). `order_item_id` is unique,
 * which is what makes commission creation idempotent when a hook fires twice. It
 * is NULL — not 0 — on a commission an admin adds by hand, so two manual rows
 * cannot collide on the constraint.
 *
 * The lifecycle is `pending` -> `unpaid` -> `paid`, with either of the first two
 * able to become `rejected`. A commission inside a payment (`payout_id` set)
 * is edited the way SliceWP edits one — amount, reference, type and status
 * stay open to the admin, and an unpaid payment re-sums to follow — while the
 * automatic movers (order status, maturation job) keep off it and it cannot be
 * deleted; a paid payment keeps the amount it was paid with.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Commission extends BaseModel {

	/**
	 * Created with the order, inside the hold period. Nothing has moved yet.
	 *
	 * @var string
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Matured: the hold period elapsed and the order reached a paying status.
	 *
	 * @var string
	 */
	const STATUS_UNPAID = 'unpaid';

	/**
	 * Included in a payout. Terminal.
	 *
	 * @var string
	 */
	const STATUS_PAID = 'paid';

	/**
	 * Cancelled by a full refund, or by an admin. Terminal.
	 *
	 * @var string
	 */
	const STATUS_REJECTED = 'rejected';

	/**
	 * A percentage of the base amount.
	 *
	 * @var string
	 */
	const RATE_PERCENTAGE = 'percentage';

	/**
	 * A fixed amount per item. Supported by the schema and the calculator; not
	 * offered by the Phase 1 UI (ADR-0009).
	 *
	 * @var string
	 */
	const RATE_FIXED = 'fixed';

	/**
	 * Created from a WooCommerce order item.
	 *
	 * @var string
	 */
	const SOURCE_WOOCOMMERCE = 'woocommerce';

	/**
	 * Added by an admin by hand.
	 *
	 * @var string
	 */
	const SOURCE_MANUAL = 'manual';

	/**
	 * A commission on a sale. The only Phase 1 type.
	 *
	 * @var string
	 */
	const TYPE_SALE = 'sale';

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static string $table = 'flyaffiliate_commissions';

	/**
	 * {@inheritDoc}
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [
		'id'            => 'int',
		'affiliate_id'  => 'int',
		'order_id'      => 'int',
		'order_item_id' => 'int',
		'product_id'    => 'int',
		'vendor_id'     => 'int',
		'base_amount'   => 'money',
		'rate'          => 'money',
		'rate_type'     => 'string',
		'amount'        => 'money',
		'currency'      => 'string',
		'source'        => 'string',
		'type'          => 'string',
		'status'        => 'string',
		'payout_id'     => 'int',
		'matures_at'    => 'datetime',
		'created_at'    => 'datetime',
		'updated_at'    => 'datetime',
	];

	/**
	 * Every commission status.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Status => translated label.
	 */
	public static function get_statuses(): array {
		return [
			self::STATUS_PENDING  => __( 'Pending', 'flyaffiliate' ),
			self::STATUS_UNPAID   => __( 'Unpaid', 'flyaffiliate' ),
			self::STATUS_PAID     => __( 'Paid', 'flyaffiliate' ),
			self::STATUS_REJECTED => __( 'Rejected', 'flyaffiliate' ),
		];
	}

	/**
	 * Every commission source.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Source => translated label.
	 */
	public static function get_sources(): array {
		return [
			self::SOURCE_WOOCOMMERCE => __( 'WooCommerce', 'flyaffiliate' ),
			self::SOURCE_MANUAL      => __( 'Manual', 'flyaffiliate' ),
		];
	}

	/**
	 * The origins a new commission can be given right now.
	 *
	 * The manual origin is always there. A platform's origin is added by its
	 * integration while that platform is active (ADR-0013), so a commission is
	 * never recorded against an origin nothing can check or follow. Rows that
	 * already carry an origin keep their label from `get_sources()`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Origin => label, the preferred one first.
	 */
	public static function get_available_sources(): array {
		/**
		 * Filters the origins open to a new commission.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, string> $sources Origin => label. Manual only, until an integration adds its own.
		 */
		$sources = (array) apply_filters( 'flyaffiliate_available_commission_sources', [ self::SOURCE_MANUAL => self::get_sources()[ self::SOURCE_MANUAL ] ] );

		return array_intersect_key( $sources, self::get_sources() );
	}

	/**
	 * Every commission type.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Type => translated label.
	 */
	public static function get_types(): array {
		return [
			self::TYPE_SALE => __( 'Sale', 'flyaffiliate' ),
		];
	}

	/**
	 * Whether a payment holds this commission.
	 *
	 * A held commission (CONTEXT.md money rule 7) is not deleted, not moved by
	 * an order status change or the maturation job, and never put into a second
	 * payment. An admin can still edit it, as in SliceWP. A paid row recorded by
	 * hand, outside any payment, is held by nothing.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		return $this->is_in_payout();
	}

	/**
	 * Whether this commission is attached to a payment.
	 *
	 * A payment counts its commissions towards an amount an admin is about to
	 * send, so the rows under it hold still: the payment is what changes them,
	 * and `Payout\Manager::remove_commission()` is how one gets out.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_in_payout(): bool {
		return (int) $this->get( 'payout_id', 0 ) > 0;
	}

	/**
	 * The commission amount in integer cents.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int
	 */
	public function get_amount_in_cents(): int {
		return \FlyAffiliate\Utilities\Money::to_cents( $this->get( 'amount', 0 ) );
	}

	/**
	 * The order this commission came from.
	 *
	 * Goes through `wc_get_order()` so the lookup is correct under HPOS.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return \WC_Order|null
	 */
	public function get_order(): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $this->get( 'order_id', 0 ) );

		return $order instanceof \WC_Order ? $order : null;
	}
}
