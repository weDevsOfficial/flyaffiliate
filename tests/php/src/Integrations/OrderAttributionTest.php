<?php
/**
 * Referred orders become commissions.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations;

use FlyAffiliate\Commission\RateResolver;
use FlyAffiliate\Integrations\WooCommerce\OrderAttribution;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;
use FlyAffiliate\Tracking\Tracker;

/**
 * Per-item commissions, clamped rates, idempotency and the guards.
 */
class OrderAttributionTest extends FlyAffiliateTestCase {

	/**
	 * The attribution under test.
	 *
	 * @var OrderAttribution
	 */
	protected OrderAttribution $attribution;

	/**
	 * The affiliate the cookie points at.
	 *
	 * @var int
	 */
	protected int $affiliate_id = 0;

	/**
	 * The visit the cookie points at.
	 *
	 * @var int
	 */
	protected int $visit_id = 0;

	/**
	 * {@inheritDoc}
	 */
	public function set_up() {
		parent::set_up();

		$this->attribution  = new OrderAttribution( new Tracker(), new RateResolver() );
		$this->affiliate_id = $this->factory()->affiliate->create();
		$this->visit_id     = $this->factory()->visit->create( [ 'affiliate_id' => $this->affiliate_id ] );

		$_COOKIE[ Tracker::COOKIE ] = Tracker::sign( $this->affiliate_id, $this->visit_id );

		flyaffiliate_update_option( 'default_rate', 10 );
		flyaffiliate_update_option( 'max_rate', 50 );
		flyaffiliate_update_option( 'exclude_tax', 'on' );
		flyaffiliate_update_option( 'woocommerce_enabled', 'on' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down() {
		unset( $_COOKIE[ Tracker::COOKIE ] );

		parent::tear_down();
	}

	/**
	 * An order with the cookie present.
	 *
	 * @param array $items Product id => quantity.
	 * @param array $args  Extra order args.
	 *
	 * @return int The order id.
	 */
	protected function order( array $items, array $args = [] ): int {
		$lines = [];

		foreach ( $items as $product_id => $quantity ) {
			$lines[] = [
				'product_id' => $product_id,
				'quantity'   => $quantity,
			];
		}

		return $this->factory()->order->create( array_merge( [ 'items' => $lines ], $args ) );
	}

	/**
	 * One pending commission per item, in cents, and the visit converts.
	 *
	 * @return void
	 */
	public function test_creates_one_pending_commission_per_item(): void {
		$product_a = $this->factory()->product->create( [ 'regular_price' => 100 ] );
		$product_b = $this->factory()->product->create( [ 'regular_price' => 33.33 ] );
		$order_id  = $this->order( [ $product_a => 1, $product_b => 2 ] );

		$created = $this->attribution->attribute( $order_id );

		$this->assertCount( 2, $created );

		$by_product = [];

		foreach ( $created as $commission ) {
			$by_product[ (int) $commission->get( 'product_id' ) ] = $commission;
		}

		$this->assertMoneyEquals( 10.00, $by_product[ $product_a ]->get( 'amount' ) );
		$this->assertMoneyEquals( 100.00, $by_product[ $product_a ]->get( 'base_amount' ) );
		// 66.66 × 10% = 6.666 → 6.67.
		$this->assertMoneyEquals( 6.67, $by_product[ $product_b ]->get( 'amount' ) );
		$this->assertSame( Commission::STATUS_PENDING, $by_product[ $product_a ]->get( 'status' ) );
		$this->assertSame( Commission::SOURCE_WOOCOMMERCE, $by_product[ $product_a ]->get( 'source' ) );
		$this->assertSame( $order_id, (int) $by_product[ $product_a ]->get( 'order_id' ) );

		$order = wc_get_order( $order_id );
		$this->assertSame( $this->affiliate_id, (int) $order->get_meta( OrderAttribution::META_AFFILIATE ) );

		$visit = flyaffiliate()->tracking->get( $this->visit_id );
		$this->assertTrue( $visit->is_converted() );
		$this->assertSame( $order_id, (int) $visit->get( 'order_id' ) );
	}

	/**
	 * The hook firing twice creates nothing new.
	 *
	 * @return void
	 */
	public function test_attribution_is_idempotent(): void {
		$product  = $this->factory()->product->create( [ 'regular_price' => 50 ] );
		$order_id = $this->order( [ $product => 1 ] );

		$this->assertCount( 1, $this->attribution->attribute( $order_id ) );
		$this->assertCount( 0, $this->attribution->attribute( $order_id ) );
		$this->assertCount( 0, $this->attribution->attribute( wc_get_order( $order_id ) ) );

		$this->assertSame( 1, flyaffiliate()->commission->count( [ 'where' => [ 'order_id' => $order_id ] ] ) );
	}

	/**
	 * A product rate wins over the default and is clamped to the maximum.
	 *
	 * @return void
	 */
	public function test_product_rate_is_used_and_clamped(): void {
		$capped  = $this->factory()->product->create( [ 'regular_price' => 100 ] );
		$special = $this->factory()->product->create( [ 'regular_price' => 100 ] );

		update_post_meta( $capped, RateResolver::PRODUCT_META, '80' );
		update_post_meta( $special, RateResolver::PRODUCT_META, '25' );

		$order_id = $this->order( [ $capped => 1, $special => 1 ] );
		$created  = $this->attribution->attribute( $order_id );

		$amounts = [];

		foreach ( $created as $commission ) {
			$amounts[ (int) $commission->get( 'product_id' ) ] = (float) $commission->get( 'amount' );
		}

		$this->assertMoneyEquals( 50.00, $amounts[ $capped ], 'clamped to max_rate' );
		$this->assertMoneyEquals( 25.00, $amounts[ $special ] );
	}

	/**
	 * Tax is part of the base only when the exclusion is off.
	 *
	 * @return void
	 */
	public function test_exclude_tax_setting_changes_the_base(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		\WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			]
		);

		$product = $this->factory()->product->create( [ 'regular_price' => 100 ] );

		flyaffiliate_update_option( 'exclude_tax', 'on' );
		$excluded = $this->attribution->attribute( $this->order( [ $product => 1 ] ) );
		$this->assertMoneyEquals( 100.00, $excluded[0]->get( 'base_amount' ) );

		flyaffiliate_update_option( 'exclude_tax', 'off' );
		$included = $this->attribution->attribute( $this->order( [ $product => 1 ] ) );
		$this->assertMoneyEquals( 120.00, $included[0]->get( 'base_amount' ) );
		$this->assertMoneyEquals( 12.00, $included[0]->get( 'amount' ) );
	}

	/**
	 * The affiliate buying through their own link earns nothing.
	 *
	 * @return void
	 */
	public function test_self_referral_earns_nothing(): void {
		$affiliate = flyaffiliate()->affiliate->get( $this->affiliate_id );
		$product   = $this->factory()->product->create();
		$order_id  = $this->order( [ $product => 1 ], [ 'customer_id' => (int) $affiliate->get( 'user_id' ) ] );

		$this->assertCount( 0, $this->attribution->attribute( $order_id ) );
		$this->assertSame( 0, flyaffiliate()->commission->count() );
	}

	/**
	 * Nothing happens without a cookie, with the integration off, or for an inactive affiliate.
	 *
	 * @return void
	 */
	public function test_guards(): void {
		$product = $this->factory()->product->create();

		flyaffiliate_update_option( 'woocommerce_enabled', 'off' );
		$this->assertCount( 0, $this->attribution->attribute( $this->order( [ $product => 1 ] ) ) );
		flyaffiliate_update_option( 'woocommerce_enabled', 'on' );

		flyaffiliate()->affiliate->update( $this->affiliate_id, [ 'status' => 'suspended' ] );
		$this->assertCount( 0, $this->attribution->attribute( $this->order( [ $product => 1 ] ) ) );
		flyaffiliate()->affiliate->update( $this->affiliate_id, [ 'status' => 'active' ] );

		unset( $_COOKIE[ Tracker::COOKIE ] );
		$this->assertCount( 0, $this->attribution->attribute( $this->order( [ $product => 1 ] ) ) );

		$this->assertSame( 0, flyaffiliate()->commission->count() );
	}
}
