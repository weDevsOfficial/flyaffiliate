<?php
/**
 * Tests for the affiliate's self-scoped routes.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\REST;

use FlyAffiliate\Integrations\WooCommerce\OrderAttribution;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * `me`: bound to the caller, refuses everyone else.
 *
 * @group rest
 *
 * @since FLYAFFILIATE_SINCE
 */
class MeControllerTest extends FlyAffiliateTestCase {

	/**
	 * The routes exist and a guest, a plain customer and an inactive affiliate are refused.
	 *
	 * @return void
	 */
	public function test_only_an_active_affiliate_gets_in(): void {
		$routes = $this->server->get_routes();

		foreach ( [ 'me', 'me/commissions', 'me/visits', 'me/payouts' ] as $route ) {
			$this->assertArrayHasKey( '/flyaffiliate/v1/' . $route, $routes );
		}

		$this->assertSame( 401, $this->get_request( '/me' )->get_status() );

		$this->acting_as( $this->customer_id );
		$this->assertSame( 403, $this->get_request( '/me' )->get_status() );

		$pending = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->factory()->affiliate->create( [ 'user_id' => $pending, 'status' => Affiliate::STATUS_PENDING ] );
		$this->acting_as( $pending );
		$this->assertSame( 403, $this->get_request( '/me/commissions' )->get_status() );
	}

	/**
	 * The lists only ever hold the caller's rows, whatever the parameters say.
	 *
	 * @return void
	 */
	public function test_lists_are_scoped_to_the_caller(): void {
		$user  = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$mine  = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => Affiliate::STATUS_ACTIVE ] );
		$other = $this->factory()->affiliate->create();

		$this->factory()->commission->create_many( 2, [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_PENDING ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $other, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $mine ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $other ] );
		$this->factory()->payout->create( [ 'affiliate_id' => $other ] );

		$this->acting_as( $user );

		// An affiliate_id for someone else is ignored, not honoured.
		$response = $this->get_request( '/me/commissions', [ 'affiliate_id' => $other ] );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( [ $mine ], array_values( array_unique( wp_list_pluck( $response->get_data(), 'affiliate_id' ) ) ) );

		$response = $this->get_request( '/me/commissions', [ 'status' => 'unpaid' ] );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		$this->assertSame( '1', $this->get_request( '/me/visits' )->get_headers()['X-WP-Total'] );
		$this->assertSame( '0', $this->get_request( '/me/payouts' )->get_headers()['X-WP-Total'] );
	}

	/**
	 * A payout filter lists the commissions inside the caller's own payment and nothing for anyone else's.
	 *
	 * @return void
	 */
	public function test_a_payout_lists_only_its_own_commissions(): void {
		$user  = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$mine  = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => Affiliate::STATUS_ACTIVE ] );
		$other = $this->factory()->affiliate->create();

		$my_payout    = $this->factory()->payout->create( [ 'affiliate_id' => $mine ] );
		$other_payout = $this->factory()->payout->create( [ 'affiliate_id' => $other ] );

		$inside = $this->factory()->commission->create_many( 2, [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_PAID, 'payout_id' => $my_payout ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $other, 'status' => Commission::STATUS_PAID, 'payout_id' => $other_payout ] );

		$this->acting_as( $user );

		$response = $this->get_request( '/me/commissions', [ 'payout_id' => $my_payout ] );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertEqualsCanonicalizing( $inside, wp_list_pluck( $response->get_data(), 'id' ) );

		// Someone else's payout stays empty: the affiliate scope is still applied.
		$response = $this->get_request( '/me/commissions', [ 'payout_id' => $other_payout ] );
		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * The profile carries the totals and only two fields can change.
	 *
	 * @return void
	 */
	public function test_profile_read_and_write(): void {
		$user = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$id   = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => Affiliate::STATUS_ACTIVE ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'status' => Commission::STATUS_UNPAID, 'amount' => 12.5 ] );

		$this->acting_as( $user );

		$data = $this->get_request( '/me' )->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 12.5, (float) $data['totals']['unpaid'] );
		$this->assertStringContainsString( 'affiliate=' . $id, $data['referral_url'] );

		$response = $this->put_request( '/me', [ 'payment_email' => 'pay@example.test', 'promo_method' => 'Newsletter', 'status' => 'suspended' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pay@example.test', $response->get_data()['payment_email'] );
		$this->assertSame( 'Newsletter', $response->get_data()['promo_method'] );
		$this->assertSame( Affiliate::STATUS_ACTIVE, $response->get_data()['status'] );
	}

	/**
	 * A date range narrows the lists, the counts and the totals; a malformed day is refused.
	 *
	 * @return void
	 */
	public function test_dates_narrow_the_lists_and_the_totals(): void {
		$user = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$mine = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => Affiliate::STATUS_ACTIVE ] );

		$this->factory()->commission->create( [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_UNPAID, 'amount' => 10, 'created_at' => '2026-01-10 12:00:00' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $mine, 'status' => Commission::STATUS_UNPAID, 'amount' => 5, 'created_at' => '2026-03-10 12:00:00' ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $mine, 'created_at' => '2026-01-10 12:00:00' ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $mine, 'created_at' => '2026-03-31 23:30:00' ] );

		$this->acting_as( $user );

		$march = [
			'after'  => '2026-03-01',
			'before' => '2026-03-31',
		];

		$this->assertSame( '1', $this->get_request( '/me/commissions', $march )->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $this->get_request( '/me/visits', $march )->get_headers()['X-WP-Total'], 'the last day counts until its end' );

		$ranged = $this->get_request( '/me', $march )->get_data();
		$this->assertCentsEquals( 500, $ranged['totals']['unpaid'] );
		$this->assertSame( 1, $ranged['visits']['all'] );

		$all = $this->get_request( '/me' )->get_data();
		$this->assertCentsEquals( 1500, $all['totals']['unpaid'] );
		$this->assertSame( 2, $all['visits']['all'] );

		$this->assertSame( 400, $this->get_request( '/me/commissions', [ 'after' => 'March' ] )->get_status() );
	}

	/**
	 * A commission opens with its product and visit, and only for its own affiliate.
	 *
	 * @return void
	 */
	public function test_a_commission_opens_with_its_item_and_visit(): void {
		$user    = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$mine    = $this->factory()->affiliate->create( [ 'user_id' => $user, 'status' => Affiliate::STATUS_ACTIVE ] );
		$product = $this->factory()->product->create();
		$order   = wc_get_order( $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] ) );
		$visit   = $this->factory()->visit->create(
			[
				'affiliate_id' => $mine,
				'url'          => 'http://example.org/landing/',
				'referrer'     => 'https://social.example/post',
			]
		);

		$order->update_meta_data( OrderAttribution::META_VISIT, $visit );
		$order->save();

		$commission = $this->factory()->commission->create(
			[
				'affiliate_id'  => $mine,
				'order_id'      => $order->get_id(),
				'order_item_id' => array_key_first( $order->get_items() ),
				'product_id'    => $product,
			]
		);
		$manual     = $this->factory()->commission->create( [ 'affiliate_id' => $mine, 'order_id' => 0, 'source' => Commission::SOURCE_MANUAL ] );
		$others     = $this->factory()->commission->create( [ 'affiliate_id' => $this->factory()->affiliate->create() ] );

		$this->acting_as( $user );

		$response = $this->get_request( '/me/commissions/' . $commission );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( wc_get_product( $product )->get_name(), $data['product']['name'] );
		$this->assertSame( 'http://example.org/landing/', $data['visit']['url'] );
		$this->assertSame( 'https://social.example/post', $data['visit']['referrer'] );

		$manual_data = $this->get_request( '/me/commissions/' . $manual )->get_data();
		$this->assertNull( $manual_data['visit'] );

		$this->assertSame( 404, $this->get_request( '/me/commissions/' . $others )->get_status(), 'another affiliate\'s commission does not exist for this one' );
	}
}
