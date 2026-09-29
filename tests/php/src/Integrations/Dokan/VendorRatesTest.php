<?php
/**
 * A vendor's program reaches the commission.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations\Dokan;

use FlyAffiliate\Commission\HoldPeriod;
use FlyAffiliate\Commission\RateResolver;
use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Integrations\Dokan\VendorProgram;
use FlyAffiliate\Integrations\WooCommerce\OrderAttribution;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Tracking\Tracker;

/**
 * Vendor id, rate and lock at checkout; the lock kept when a hold period changes.
 *
 * @group dokan
 */
class VendorRatesTest extends DokanTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$affiliate = $this->factory()->affiliate->create();
		$visit     = $this->factory()->visit->create( [ 'affiliate_id' => $affiliate ] );

		$_COOKIE[ Tracker::COOKIE ] = Tracker::sign( $affiliate, $visit );

		flyaffiliate_update_option( 'exclude_tax', 'on' );
		flyaffiliate_update_option( 'woocommerce_enabled', 'on' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		unset( $_COOKIE[ Tracker::COOKIE ] );
		parent::tear_down();
	}

	/**
	 * Attribute an order of one product each.
	 *
	 * @param int[] $product_ids The products.
	 *
	 * @return Commission[] Keyed by product id.
	 */
	private function attribute( array $product_ids ): array {
		$order_id = $this->factory()->order->create(
			[
				'items' => array_map( static fn( int $id ): array => [ 'product_id' => $id ], $product_ids ),
			]
		);
		$created  = ( new OrderAttribution( new Tracker(), new RateResolver() ) )->attribute( $order_id );
		$keyed    = [];

		foreach ( $created as $commission ) {
			$keyed[ (int) $commission->get( 'product_id' ) ] = flyaffiliate()->commission->get( $commission->get_id() );
		}

		return $keyed;
	}

	/**
	 * The days between a commission's creation and its maturity.
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return int
	 */
	private function held_days( Commission $commission ): int {
		return (int) round( ( strtotime( $commission->get( 'matures_at' ) . ' UTC' ) - strtotime( $commission->get( 'created_at' ) . ' UTC' ) ) / DAY_IN_SECONDS );
	}

	/**
	 * Each item carries its vendor, the vendor's rate and the vendor's lock; the amount is exact in cents.
	 *
	 * @return void
	 */
	public function test_a_commission_carries_its_vendor_rate_and_lock(): void {
		$own      = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);
		$plain    = $this->vendor();
		$by_own   = $this->factory()->product->create(
			[
				'regular_price' => 33.33,
				'vendor_id'     => $own,
			]
		);
		$by_plain = $this->factory()->product->create(
			[
				'regular_price' => 100,
				'vendor_id'     => $plain,
			]
		);

		$commissions = $this->attribute( [ $by_own, $by_plain ] );

		$this->assertSame( $own, (int) $commissions[ $by_own ]->get( 'vendor_id' ) );
		$this->assertSame( 25.0, (float) $commissions[ $by_own ]->get( 'rate' ) );
		// 33.33 × 25% = 8.3325 → 8.33.
		$this->assertCentsEquals( 833, $commissions[ $by_own ]->get( 'amount' ) );
		$this->assertSame( 5, $this->held_days( $commissions[ $by_own ] ) );

		$this->assertSame( $plain, (int) $commissions[ $by_plain ]->get( 'vendor_id' ) );
		$this->assertSame( 10.0, (float) $commissions[ $by_plain ]->get( 'rate' ), 'FlyAffiliate’s default: the marketplace set no vendor rate' );
		$this->assertCentsEquals( 1000, $commissions[ $by_plain ]->get( 'amount' ) );
		$this->assertSame( 14, $this->held_days( $commissions[ $by_plain ] ), 'the marketplace’s lock' );

		$terms = wc_get_order_item_meta( (int) $commissions[ $by_own ]->get( 'order_item_id' ), VendorProgram::TERMS_META, true );

		$this->assertTrue( $terms['overridden'] );
		$this->assertFalse( wc_get_order_item_meta( (int) $commissions[ $by_plain ]->get( 'order_item_id' ), VendorProgram::TERMS_META, true )['overridden'] );
	}

	/**
	 * The hierarchy: product rate, vendor rate, the marketplace's vendor rate, FlyAffiliate's default; clamped twice.
	 *
	 * @return void
	 */
	public function test_the_rate_hierarchy(): void {
		$vendor   = $this->vendor();
		$product  = $this->factory()->product->create( [ 'vendor_id' => $vendor ] );
		$priced   = $this->factory()->product->create(
			[
				'vendor_id'         => $vendor,
				'flyaffiliate_rate' => 7,
			]
		);
		$resolver = new RateResolver();

		$this->assertSame( 10.0, $resolver->resolve( $product, $vendor ) );

		$this->marketplace( [ Settings::DEFAULT_RATE => '12' ] );
		$this->assertSame( 12.0, $resolver->resolve( $product, $vendor ) );

		$this->program()->save(
			$vendor,
			[
				'override' => 'on',
				'rate'     => 40,
			]
		);
		$this->assertSame( 40.0, $resolver->resolve( $product, $vendor ) );
		$this->assertSame( 7.0, $resolver->resolve( $priced, $vendor ), 'a product rate comes first' );
		$this->assertSame( 12.0, $resolver->resolve( $product, $this->vendor() ), 'a vendor without settings gets the marketplace’s vendor rate' );
		$this->assertSame( 10.0, $resolver->resolve( $product, 0 ), 'without a vendor the vendor step is skipped' );

		flyaffiliate_update_option( 'max_rate', 30 );
		$this->assertSame( 30.0, $resolver->resolve( $product, $vendor ), 'FlyAffiliate’s own maximum still caps a vendor' );
	}

	/**
	 * Changing FlyAffiliate's hold period leaves each vendor's lock in place.
	 *
	 * @return void
	 */
	public function test_a_hold_period_change_keeps_each_vendors_lock(): void {
		$own      = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);
		$plain    = $this->vendor();
		$created  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$row      = fn( int $vendor_id ): int => $this->factory()->commission->create(
			[
				'vendor_id'  => $vendor_id,
				'status'     => Commission::STATUS_PENDING,
				'created_at' => $created,
				'matures_at' => $created,
			]
		);
		$by_own   = $row( $own );
		$by_plain = $row( $plain );
		$by_none  = $row( 0 );

		flyaffiliate()->get_container()->get( HoldPeriod::class )->reschedule( 45 );

		$this->assertSame( 5, $this->held_days( flyaffiliate()->commission->get( $by_own ) ), 'the vendor’s own lock' );
		$this->assertSame( 14, $this->held_days( flyaffiliate()->commission->get( $by_plain ) ), 'the marketplace’s lock' );
		$this->assertSame( 45, $this->held_days( flyaffiliate()->commission->get( $by_none ) ), 'FlyAffiliate’s hold period' );

		// The same through the setting being saved, which also matures what is due: nothing here is.
		flyaffiliate()->settings->save( [ 'hold_days' => 60 ] );

		$this->assertSame( 5, $this->held_days( flyaffiliate()->commission->get( $by_own ) ) );
		$this->assertSame( 60, $this->held_days( flyaffiliate()->commission->get( $by_none ) ) );
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $by_own )->get( 'status' ) );
	}

	/**
	 * A vendor changing their lock moves their own waiting commissions and nobody else's.
	 *
	 * @return void
	 */
	public function test_a_vendor_changing_their_lock_moves_their_commissions(): void {
		$vendor  = $this->vendor();
		$other   = $this->vendor();
		$created = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$later   = gmdate( 'Y-m-d H:i:s', time() + 13 * DAY_IN_SECONDS );
		$row     = fn( int $vendor_id, string $status ): int => $this->factory()->commission->create(
			[
				'vendor_id'  => $vendor_id,
				'order_id'   => 0,
				'source'     => Commission::SOURCE_MANUAL,
				'status'     => $status,
				'created_at' => $created,
				'matures_at' => $later,
			]
		);
		$pending = $row( $vendor, Commission::STATUS_PENDING );
		$paid    = $row( $vendor, Commission::STATUS_PAID );
		$theirs  = $row( $other, Commission::STATUS_PENDING );

		$this->program()->save(
			$vendor,
			[
				'override'  => 'on',
				'rate'      => 20,
				'lock_days' => 3,
			]
		);

		$this->assertSame( 3, $this->held_days( flyaffiliate()->commission->get( $pending ) ) );
		$this->assertSame( $later, (string) flyaffiliate()->commission->get( $paid )->get( 'matures_at' ), 'a paid commission keeps its dates' );
		$this->assertSame( $later, (string) flyaffiliate()->commission->get( $theirs )->get( 'matures_at' ), 'another vendor’s commission is not touched' );

		// Saving the same again changes nothing.
		$this->program()->save( $vendor, [ 'lock_days' => 3 ] );
		$this->assertSame( 3, $this->held_days( flyaffiliate()->commission->get( $pending ) ) );
	}

	/**
	 * A commission's REST response says which store it came from and whose terms applied.
	 *
	 * @return void
	 */
	public function test_the_rest_response_carries_the_vendor_terms(): void {
		$vendor  = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);
		$product = $this->factory()->product->create( [ 'vendor_id' => $vendor ] );
		$created = $this->attribute( [ $product ] )[ $product ];
		$plain   = $this->factory()->commission->create( [ 'vendor_id' => 0 ] );
		$by_hand = $this->factory()->commission->create(
			[
				'vendor_id' => $vendor,
				'source'    => Commission::SOURCE_MANUAL,
			]
		);

		// The factory always gives a row an order item; one entered by hand has none.
		Commission::find( $by_hand )->set( 'order_item_id', null )->save();

		// The vendor changes their mind afterwards: the commission keeps the terms it was created under.
		$this->program()->save( $vendor, [ 'override' => 'off' ] );

		$this->acting_as( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = $this->get_request( '/commissions/' . $created->get_id() )->get_data();

		$this->assertSame( $vendor, $data['vendor_program']['vendor_id'] );
		$this->assertSame( 25.0, $data['vendor_program']['rate'] );
		// The lock is read from the commission's dates, which moved to the marketplace's 14 days when the vendor switched back.
		$this->assertSame( 14, $data['vendor_program']['hold_days'] );
		$this->assertTrue( $data['vendor_program']['overridden'] );
		$this->assertArrayNotHasKey( 'vendor_program', $this->get_request( '/commissions/' . $plain )->get_data() );
		$this->assertArrayNotHasKey( 'vendor_program', $this->get_request( '/commissions/' . $by_hand )->get_data(), 'a hand-entered commission was made under nobody’s terms' );
	}
}
