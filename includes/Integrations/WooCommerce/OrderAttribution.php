<?php
/**
 * Referred WooCommerce orders become commissions.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Commission\RateResolver;
use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Tracking\Tracker;
use FlyAffiliate\Utilities\Money;
use WC_Order;
use WC_Order_Item;

/**
 * At checkout, attribute the order to the cookie's affiliate and create one
 * pending commission per order item.
 *
 * Both checkouts are covered: the classic shortcode fires
 * `woocommerce_checkout_order_created`, the block checkout fires
 * `woocommerce_store_api_checkout_order_processed`. Everything here is
 * idempotent: the order remembers its affiliate, and the commissions table
 * refuses a second row for an order item.
 *
 * @since FLYAFFILIATE_SINCE
 */
class OrderAttribution implements Hookable {

	/**
	 * Order meta holding the attributed affiliate.
	 *
	 * @var string
	 */
	const META_AFFILIATE = '_flyaffiliate_affiliate_id';

	/**
	 * Order meta holding the visit that led to the order.
	 *
	 * @var string
	 */
	const META_VISIT = '_flyaffiliate_visit_id';

	/**
	 * The tracker, for the cookie.
	 *
	 * @var Tracker
	 */
	protected Tracker $tracker;

	/**
	 * The rate resolver.
	 *
	 * @var RateResolver
	 */
	protected RateResolver $rates;

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Tracker      $tracker The tracker.
	 * @param RateResolver $rates   The rate resolver.
	 */
	public function __construct( Tracker $tracker, RateResolver $rates ) {
		$this->tracker = $tracker;
		$this->rates   = $rates;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_created', [ $this, 'attribute' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'attribute' ] );
	}

	/**
	 * Attribute an order and create its commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order|int $order The order.
	 *
	 * @return Commission[] The commissions created on this call.
	 */
	public function attribute( $order ): array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof WC_Order || ! flyaffiliate_option_enabled( 'woocommerce_enabled' ) ) {
			return [];
		}

		// Already attributed: a hook firing twice changes nothing.
		if ( (int) $order->get_meta( self::META_AFFILIATE ) > 0 ) {
			return [];
		}

		$attribution = $this->tracker->get_attribution();

		if ( null === $attribution ) {
			return [];
		}

		$affiliate = flyaffiliate()->affiliate->get( $attribution['affiliate_id'] );

		if ( null === $affiliate || Affiliate::STATUS_ACTIVE !== $affiliate->get( 'status' ) ) {
			return [];
		}

		if ( $this->is_self_referral( $order, $affiliate ) ) {
			return [];
		}

		/**
		 * Filters whether a referred order is attributed at all.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param bool      $attribute Default true.
		 * @param WC_Order  $order     The order.
		 * @param Affiliate $affiliate The affiliate.
		 */
		if ( ! apply_filters( 'flyaffiliate_attribute_order', true, $order, $affiliate ) ) {
			return [];
		}

		$created = [];

		foreach ( $this->get_commissionable_items( $order ) as $item ) {
			$commission = $this->create_commission( $order, $item, $affiliate );

			if ( null !== $commission ) {
				$created[] = $commission;
			}
		}

		$order->update_meta_data( self::META_AFFILIATE, (int) $affiliate->get_id() );
		$order->update_meta_data( self::META_VISIT, (int) $attribution['visit_id'] );
		$order->save();

		if ( $attribution['visit_id'] > 0 ) {
			flyaffiliate()->tracking->mark_converted( $attribution['visit_id'], $order->get_id() );
		}

		/**
		 * Fires after an order is attributed to an affiliate.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param WC_Order     $order       The order.
		 * @param Affiliate    $affiliate   The affiliate.
		 * @param Commission[] $commissions The commissions created.
		 */
		do_action( 'flyaffiliate_order_attributed', $order, $affiliate, $created );

		return $created;
	}

	/**
	 * Whether the buyer is the affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order  $order     The order.
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return bool
	 */
	protected function is_self_referral( WC_Order $order, Affiliate $affiliate ): bool {
		if ( ! flyaffiliate_option_enabled( 'block_self_referral' ) ) {
			return false;
		}

		$user_id = (int) $affiliate->get( 'user_id' );

		if ( $order->get_customer_id() > 0 && $order->get_customer_id() === $user_id ) {
			return true;
		}

		$user  = get_user_by( 'id', $user_id );
		$email = strtolower( trim( $order->get_billing_email() ) );

		return $user instanceof \WP_User && '' !== $email && strtolower( $user->user_email ) === $email;
	}

	/**
	 * The order items a commission is calculated on.
	 *
	 * Line items always; shipping items only when shipping is not excluded.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return WC_Order_Item[]
	 */
	protected function get_commissionable_items( WC_Order $order ): array {
		$types = [ 'line_item' ];

		if ( ! flyaffiliate_option_enabled( 'exclude_shipping' ) ) {
			$types[] = 'shipping';
		}

		return array_values( $order->get_items( $types ) );
	}

	/**
	 * Create the commission for one order item.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order      $order     The order.
	 * @param WC_Order_Item $item      The item.
	 * @param Affiliate     $affiliate The affiliate.
	 *
	 * @return Commission|null Null when the item earns nothing or already has a row.
	 */
	protected function create_commission( WC_Order $order, WC_Order_Item $item, Affiliate $affiliate ): ?Commission {
		$base = (float) $item->get_total();

		if ( ! flyaffiliate_option_enabled( 'exclude_tax' ) ) {
			$base += (float) $item->get_total_tax();
		}

		$base = Money::round( $base );

		if ( Money::to_cents( $base ) <= 0 ) {
			return null;
		}

		$product_id = method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() > 0
			? (int) $item->get_variation_id()
			: ( method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0 );

		/**
		 * Filters the vendor an order item belongs to.
		 *
		 * 0 on a single-merchant store; a marketplace integration resolves the
		 * product's store owner.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int           $vendor_id  Default 0.
		 * @param WC_Order_Item $item       The item.
		 * @param WC_Order      $order      The order.
		 */
		$vendor_id = (int) apply_filters( 'flyaffiliate_order_item_vendor_id', 0, $item, $order );
		$rate      = $this->rates->resolve( $product_id, $vendor_id );
		$amount    = Money::round( $base * $rate / 100 );

		if ( Money::to_cents( $amount ) <= 0 ) {
			return null;
		}

		$commission = new Commission();

		$commission->fill(
			[
				'affiliate_id'  => (int) $affiliate->get_id(),
				'order_id'      => $order->get_id(),
				'order_item_id' => $item->get_id(),
				'product_id'    => $product_id,
				'vendor_id'     => $vendor_id,
				'base_amount'   => $base,
				'rate'          => round( $rate, 4 ),
				'rate_type'     => Commission::RATE_PERCENTAGE,
				'amount'        => $amount,
				'currency'      => $order->get_currency(),
				'source'        => Commission::SOURCE_WOOCOMMERCE,
				'type'          => Commission::TYPE_SALE,
				'status'        => Commission::STATUS_PENDING,
				'matures_at'    => flyaffiliate()->commission->maturation_date(),
				'created_at'    => current_time( 'mysql', true ),
			]
		);

		// A second row for the same order item is refused by the unique key.
		if ( 0 === $commission->save() ) {
			return null;
		}

		/** This action is documented in includes/Commission/Manager.php */
		do_action( 'flyaffiliate_commission_created', $commission );

		return $commission;
	}
}
