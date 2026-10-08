<?php
/**
 * REST: the affiliates who sent customers to a vendor's store.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations\Dokan;

use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Models\Commission;

/**
 * Self-scoped, grouped per affiliate, highest earner first, paged.
 *
 * @group dokan
 */
class VendorAffiliatesControllerTest extends DokanTestCase {

	/**
	 * A commission on a vendor's sale.
	 *
	 * @param int    $vendor_id    The vendor.
	 * @param int    $affiliate_id The affiliate.
	 * @param float  $amount       The commission.
	 * @param string $status       Its status.
	 * @param int    $order_id     Its order.
	 *
	 * @return int
	 */
	private function commission( int $vendor_id, int $affiliate_id, float $amount, string $status = Commission::STATUS_UNPAID, int $order_id = 0 ): int {
		static $order = 5000;

		return $this->factory()->commission->create(
			[
				'vendor_id'    => $vendor_id,
				'affiliate_id' => $affiliate_id,
				'base_amount'  => $amount * 10,
				'amount'       => $amount,
				'status'       => $status,
				'order_id'     => $order_id > 0 ? $order_id : ++$order,
			]
		);
	}

	/**
	 * Only a vendor sees the list, and only while vendor programs are on.
	 *
	 * @return void
	 */
	public function test_only_a_vendor_may_read_it(): void {
		$this->assertSame( 401, $this->get_request( '/vendor/affiliates' )->get_status() );

		$this->acting_as( $this->factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( 403, $this->get_request( '/vendor/affiliates' )->get_status() );

		$this->acting_as( $this->vendor() );
		$this->assertSame( 200, $this->get_request( '/vendor/affiliates' )->get_status() );

		$this->marketplace( [ Settings::PROGRAMS => 'off' ] );
		$this->assertSame( 403, $this->get_request( '/vendor/affiliates' )->get_status() );
	}

	/**
	 * One row per affiliate with exact sums, the highest earner first, other vendors and rejected rows left out.
	 *
	 * @return void
	 */
	public function test_it_groups_per_affiliate_highest_earner_first(): void {
		$vendor = $this->vendor();
		$other  = $this->vendor();
		$small  = $this->factory()->affiliate->create();
		$big    = $this->factory()->affiliate->create();

		$this->commission( $vendor, $small, 10.10, Commission::STATUS_PAID );
		$this->commission( $vendor, $small, 0.20, Commission::STATUS_PENDING );
		$this->commission( $vendor, $small, 99.00, Commission::STATUS_REJECTED );
		// Two items of one order: one order, two commissions.
		$this->commission( $vendor, $big, 33.33, Commission::STATUS_UNPAID, 7001 );
		$this->commission( $vendor, $big, 0.01, Commission::STATUS_UNPAID, 7001 );
		$this->commission( $other, $big, 500.00 );

		$this->acting_as( $vendor );

		$response = $this->get_request( '/vendor/affiliates' );
		$rows     = $response->get_data();

		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( [ $big, $small ], array_column( $rows, 'id' ) );

		$this->assertCentsEquals( 3334, $rows[0]['earned'] );
		$this->assertCentsEquals( 3334, $rows[0]['unpaid'] );
		$this->assertCentsEquals( 33340, $rows[0]['sales'] );
		$this->assertSame( 1, $rows[0]['orders'] );
		$this->assertSame( 2, $rows[0]['commissions'] );

		$this->assertCentsEquals( 1030, $rows[1]['earned'], 'the rejected commission is money nobody earned' );
		$this->assertCentsEquals( 1010, $rows[1]['paid'] );
		$this->assertCentsEquals( 20, $rows[1]['pending'] );
		$this->assertSame( 2, $rows[1]['orders'] );
		$this->assertNotEmpty( $rows[1]['name'] );
		$this->assertArrayNotHasKey( 'payment_email', $rows[1], 'a vendor does not see where an affiliate is paid' );

		$ascending = $this->get_request(
			'/vendor/affiliates',
			[
				'orderby' => 'earned',
				'order'   => 'asc',
			]
		)->get_data();

		$this->assertSame( [ $small, $big ], array_column( $ascending, 'id' ) );
	}

	/**
	 * The list is paged.
	 *
	 * @return void
	 */
	public function test_it_pages(): void {
		$vendor = $this->vendor();

		foreach ( [ 5, 4, 3 ] as $amount ) {
			$this->commission( $vendor, $this->factory()->affiliate->create(), (float) $amount );
		}

		$this->acting_as( $vendor );

		$first  = $this->get_request(
			'/vendor/affiliates',
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$second = $this->get_request(
			'/vendor/affiliates',
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertSame( 3, (int) $first->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, (int) $first->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 2, $first->get_data() );
		$this->assertCount( 1, $second->get_data() );
		$this->assertCentsEquals( 300, $second->get_data()[0]['earned'] );
	}

	/**
	 * The list can be searched by the affiliate's name, and the total follows the search.
	 *
	 * @return void
	 */
	public function test_it_searches_by_name(): void {
		$vendor = $this->vendor();
		$maya   = $this->factory()->affiliate->create( [ 'user_id' => $this->factory()->user->create( [ 'display_name' => 'Maya Chen' ] ) ] );
		$omar   = $this->factory()->affiliate->create( [ 'user_id' => $this->factory()->user->create( [ 'display_name' => 'Omar Haddad' ] ) ] );

		$this->commission( $vendor, $maya, 5.00 );
		$this->commission( $vendor, $omar, 7.00 );

		$this->acting_as( $vendor );

		$found = $this->get_request( '/vendor/affiliates', [ 'search' => 'maya' ] );

		$this->assertSame( [ $maya ], array_column( $found->get_data(), 'id' ) );
		$this->assertSame( 1, (int) $found->get_headers()['X-WP-Total'] );

		$none = $this->get_request( '/vendor/affiliates', [ 'search' => 'nobody-here' ] );

		$this->assertSame( [], $none->get_data() );
		$this->assertSame( 0, (int) $none->get_headers()['X-WP-Total'] );
		$this->assertCount( 2, $this->get_request( '/vendor/affiliates', [ 'search' => '' ] )->get_data() );
	}

	/**
	 * A staff member reads their vendor's list, not their own.
	 *
	 * @return void
	 */
	public function test_a_vendor_sees_only_their_own_store(): void {
		$vendor = $this->vendor();
		$other  = $this->vendor();

		$this->commission( $other, $this->factory()->affiliate->create(), 12.00 );

		$this->acting_as( $vendor );

		$this->assertSame( [], $this->get_request( '/vendor/affiliates' )->get_data() );
		$this->assertSame( 0, (int) $this->get_request( '/vendor/affiliates' )->get_headers()['X-WP-Total'] );
	}
}
