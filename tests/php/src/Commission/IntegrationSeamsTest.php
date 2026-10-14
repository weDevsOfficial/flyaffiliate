<?php
/**
 * Tests for the seams a marketplace integration plugs into.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Commission;

use FlyAffiliate\Commission\RateResolver;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * `flyaffiliate_max_rate`, `flyaffiliate_commission_deleted` and
 * `flyaffiliate_commission_note`.
 */
class IntegrationSeamsTest extends FlyAffiliateTestCase {

	/**
	 * A marketplace's maximum replaces the global one for its sales only,
	 * before and after `flyaffiliate_commission_rate`.
	 */
	public function test_the_maximum_rate_can_be_set_per_sale(): void {
		flyaffiliate_update_option( 'default_rate', 30 );
		flyaffiliate_update_option( 'max_rate', 20 );

		$max = static function ( $max, $product_id, $vendor_id ) {
			return 5 === $vendor_id ? 25.0 : $max;
		};

		$rate = static function () {
			return 90.0;
		};

		add_filter( 'flyaffiliate_max_rate', $max, 10, 3 );

		$this->assertSame( 25.0, ( new RateResolver() )->resolve( 0, 5 ) );
		$this->assertSame( 20.0, ( new RateResolver() )->resolve( 0, 0 ) );

		add_filter( 'flyaffiliate_commission_rate', $rate );

		$this->assertSame( 25.0, ( new RateResolver() )->resolve( 0, 5 ), 'A filter cannot exceed the maximum.' );

		remove_filter( 'flyaffiliate_commission_rate', $rate );
		remove_filter( 'flyaffiliate_max_rate', $max, 10 );
	}

	/**
	 * Deleting a commission announces it, with the fields it had.
	 */
	public function test_a_deleted_commission_is_announced_as_it_was(): void {
		$id   = $this->factory()->commission->create( [ 'vendor_id' => 9, 'order_id' => 123 ] );
		$seen = null;

		$listener = static function ( $commission ) use ( &$seen ) {
			$seen = $commission;
		};

		add_action( 'flyaffiliate_commission_deleted', $listener );

		$this->assertTrue( flyaffiliate()->commission->delete( $id ) );
		$this->assertNull( flyaffiliate()->commission->get( $id ) );
		$this->assertInstanceOf( Commission::class, $seen );
		$this->assertSame( 9, (int) $seen->get( 'vendor_id' ) );
		$this->assertSame( 123, (int) $seen->get( 'order_id' ) );

		remove_action( 'flyaffiliate_commission_deleted', $listener );
	}

	/**
	 * The commission resource carries a note, worded for the admin on the
	 * admin's routes and for the affiliate on their own.
	 */
	public function test_a_commission_carries_a_note_per_audience(): void {
		$user      = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$affiliate = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => 'active' ] );
		$id        = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate ] );

		$note = static function ( $note, $commission, $audience ) {
			return 'For the ' . $audience . '.';
		};

		add_filter( 'flyaffiliate_commission_note', $note, 10, 3 );

		$this->acting_as( $this->admin_id );
		$this->assertSame( 'For the admin.', $this->get_request( '/commissions/' . $id )->get_data()['note'] );

		$this->acting_as( $user );
		$this->assertSame( 'For the affiliate.', $this->get_request( '/me/commissions/' . $id )->get_data()['note'] );

		remove_filter( 'flyaffiliate_commission_note', $note, 10 );

		$this->assertSame( '', $this->get_request( '/me/commissions/' . $id )->get_data()['note'] );
	}
}
