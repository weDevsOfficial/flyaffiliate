<?php
/**
 * Tests for the commissions, visits, payouts and settings controllers.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\REST;

use FlyAffiliate\Admin\SetupWizard;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The remaining admin controllers: routes, permissions, the writes that are
 * allowed and the ones that are not.
 *
 * @group rest
 *
 * @since FLYAFFILIATE_SINCE
 */
class ControllersTest extends FlyAffiliateTestCase {

	/**
	 * Every resource is registered.
	 *
	 * @return void
	 */
	public function test_the_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		foreach ( [ 'commissions', 'visits', 'payouts', 'payouts/preview', 'settings', 'setup/complete' ] as $route ) {
			$this->assertArrayHasKey( '/flyaffiliate/v1/' . $route, $routes );
		}
	}

	/**
	 * A customer is refused on every collection.
	 *
	 * @return void
	 */
	public function test_collections_refuse_a_customer(): void {
		$this->acting_as( $this->customer_id );

		foreach ( [ 'commissions', 'visits', 'payouts', 'settings' ] as $route ) {
			$this->assertSame( 403, $this->get_request( '/' . $route )->get_status(), $route );
		}
	}

	/**
	 * Completing the wizard is admin-only and records itself once.
	 *
	 * @return void
	 */
	public function test_setup_complete_marks_the_wizard_done(): void {
		delete_option( SetupWizard::DONE_OPTION );

		$this->acting_as( $this->customer_id );
		$this->assertSame( 403, $this->post_request( '/setup/complete' )->get_status() );
		$this->assertFalse( SetupWizard::is_done() );

		$this->acting_as( $this->admin_id );
		$response = $this->post_request( '/setup/complete' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['done'] );
		$this->assertTrue( SetupWizard::is_done() );
	}

	/**
	 * Commissions list, filter and paginate.
	 *
	 * @return void
	 */
	public function test_commissions_list_and_filter(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create_many( 2, [ 'affiliate_id' => $affiliate, 'status' => Commission::STATUS_UNPAID ] );
		$this->factory()->commission->create( [ 'status' => Commission::STATUS_PENDING ] );

		$response = $this->get_request( '/commissions', [ 'status' => 'unpaid', 'per_page' => 1 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'unpaid', $response->get_data()[0]['status'] );
	}

	/**
	 * The affiliates list searches the user's name and the payment email.
	 *
	 * @return void
	 */
	public function test_affiliates_search_matches_name_and_payment_email(): void {
		$this->acting_as( $this->admin_id );

		$user   = $this->factory()->user->create( [ 'display_name' => 'Zelda Quartermaine', 'user_login' => 'zeldaq' ] );
		$by_name = $this->factory()->affiliate->create( [ 'user_id' => $user ] );
		$by_mail = $this->factory()->affiliate->create( [ 'payment_email' => 'payouts@quartermaine.example' ] );
		$this->factory()->affiliate->create_many( 2 );

		$response = $this->get_request( '/affiliates', [ 'search' => 'quartermaine' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertEqualsCanonicalizing( [ $by_name, $by_mail ], wp_list_pluck( $response->get_data(), 'id' ) );

		$response = $this->get_request( '/affiliates', [ 'search' => 'nobody-has-this-name' ] );

		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( [], $response->get_data() );
	}

	/**
	 * A manual commission can be created and a paid one cannot be changed.
	 *
	 * @return void
	 */
	public function test_commissions_write_rules(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create();
		$created   = $this->post_request( '/commissions', [ 'affiliate_id' => $affiliate, 'amount' => 12.5 ] );

		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 12.5, $created->get_data()['amount'] );
		$this->assertNull( $created->get_data()['order_item_id'] );
		$this->assertSame( Commission::SOURCE_WOOCOMMERCE, $created->get_data()['source'] );
		$this->assertSame( Commission::STATUS_UNPAID, $created->get_data()['status'] );

		$full = $this->post_request(
			'/commissions',
			[
				'affiliate_id' => $affiliate,
				'amount'       => 3,
				'base_amount'  => 30,
				'order_id'     => 1400,
				'source'       => Commission::SOURCE_MANUAL,
				'type'         => Commission::TYPE_SALE,
				'status'       => Commission::STATUS_PENDING,
				'created_at'   => '2026-02-03 04:05:06',
			]
		);

		$this->assertSame( 201, $full->get_status() );
		$this->assertSame( Commission::SOURCE_MANUAL, $full->get_data()['source'] );
		$this->assertSame( Commission::STATUS_PENDING, $full->get_data()['status'] );
		$this->assertSame( 1400, $full->get_data()['order_id'] );
		$this->assertStringStartsWith( '2026-02-03T04:05:06', $full->get_data()['created_at'] );
		$this->assertSame( 400, $this->post_request( '/commissions', [ 'affiliate_id' => $affiliate, 'amount' => 3, 'source' => 'shopify' ] )->get_status() );

		$edited = $this->put_request( '/commissions/' . $full->get_data()['id'], [ 'amount' => 6, 'base_amount' => 60, 'order_id' => 1401 ] );

		$this->assertSame( 200, $edited->get_status() );
		$this->assertSame( 6.0, $edited->get_data()['amount'] );
		$this->assertSame( 1401, $edited->get_data()['order_id'] );

		$payout = $this->factory()->payout->create();
		$paid   = $this->factory()->commission->create( [ 'status' => Commission::STATUS_PAID, 'payout_id' => $payout ] );

		$this->assertSame( 409, $this->put_request( '/commissions/' . $paid, [ 'status' => 'rejected' ] )->get_status(), 'a commission inside a payment holds still' );
		$this->assertSame( 409, $this->put_request( '/commissions/' . $paid, [ 'amount' => 1 ] )->get_status() );
		$this->assertSame( 409, $this->delete_request( '/commissions/' . $paid )->get_status() );

		$by_hand = $this->put_request( '/commissions/' . $created->get_data()['id'], [ 'status' => 'paid' ] );

		$this->assertSame( 200, $by_hand->get_status(), 'outside a payment, paid is a status like any other (SliceWP parity)' );
		$this->assertSame( Commission::STATUS_PAID, $by_hand->get_data()['status'] );
		$this->assertNull( $by_hand->get_data()['payout_id'] );

		$product = $this->factory()->product->create();
		$order   = $this->factory()->order->create( [ 'items' => [ [ 'product_id' => $product ] ], 'status' => 'completed' ] );
		$missing = $this->post_request( '/commissions', [ 'affiliate_id' => $affiliate, 'amount' => 3, 'order_id' => 987654 ] );

		$this->assertSame( 400, $missing->get_status(), 'a WooCommerce-origin commission needs an order that exists' );
		$this->assertSame( 'flyaffiliate_invalid_reference', $missing->get_data()['code'] );

		$linked = $this->post_request( '/commissions', [ 'affiliate_id' => $affiliate, 'amount' => 3, 'order_id' => $order ] );

		$this->assertSame( 201, $linked->get_status() );
		$this->assertSame( wc_get_order( $order )->get_edit_order_url(), $linked->get_data()['order_url'] );
		$this->assertNull( $full->get_data()['order_url'], 'a reference that is not an order is a number, not a link' );
	}

	/**
	 * Visits are read-only and never expose the hashes.
	 *
	 * @return void
	 */
	public function test_visits_are_read_only_and_hash_free(): void {
		$this->acting_as( $this->admin_id );

		$visit    = $this->factory()->visit->create( [ 'converted' => 1 ] );
		$response = $this->get_request( '/visits/' . $visit );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['converted'] );
		$this->assertArrayHasKey( 'order_url', $response->get_data() );
		$this->assertNull( $response->get_data()['order_url'], 'a visit whose order does not exist links to nothing' );
		$this->assertArrayNotHasKey( 'ip_hash', $response->get_data() );
		$this->assertArrayNotHasKey( 'user_agent_hash', $response->get_data() );

		$this->assertSame( 404, $this->post_request( '/visits', [] )->get_status() );
	}

	/**
	 * A payout preview writes nothing; creating one writes unpaid payments; marking a payment
	 * paid is what pays its commissions, and a paid payment cannot be deleted.
	 *
	 * @return void
	 */
	public function test_payout_preview_then_create_then_pay(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 30, 'status' => Commission::STATUS_UNPAID ] );

		$preview = $this->post_request( '/payouts/preview', [ 'minimum_amount' => 0 ] );

		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 1, $preview->get_data()['count'] );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 0 );

		$created = $this->post_request( '/payouts', [ 'minimum_amount' => 0, 'note' => 'API run' ] );

		$this->assertSame( 201, $created->get_status() );
		$this->assertCount( 1, $created->get_data()['payouts'] );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 1 );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 1, [ 'status' => Commission::STATUS_UNPAID ] );

		$payment = $created->get_data()['payouts'][0];

		$this->assertSame( 'unpaid', $payment['status'] );

		$batches = $this->get_request( '/payouts/batches' );

		$this->assertSame( '1', $batches->get_headers()['X-WP-Total'] );
		$this->assertSame( 0, $batches->get_data()[0]['paid'] );

		$paid = $this->put_request( '/payouts/' . $payment['id'], [ 'status' => 'paid' ] );

		$this->assertSame( 200, $paid->get_status() );
		$this->assertSame( 'paid', $paid->get_data()['status'] );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 1, [ 'status' => Commission::STATUS_PAID ] );
		$this->assertSame( '1', $this->get_request( '/commissions', [ 'payout_id' => $payment['id'] ] )->get_headers()['X-WP-Total'] );

		$this->assertSame( 409, $this->delete_request( '/payouts/' . $payment['id'] )->get_status() );
		$this->assertSame( 409, $this->delete_request( '/payouts/batch/' . $created->get_data()['batch_key'] )->get_status() );
	}

	/**
	 * Settings come back as the flat schema with values, and save per scope.
	 *
	 * @return void
	 */
	public function test_settings_read_and_write(): void {
		$this->acting_as( $this->admin_id );

		$all = $this->get_request( '/settings' );

		$this->assertSame( 200, $all->get_status() );

		$fields = [];

		foreach ( $all->get_data() as $element ) {
			$this->assertArrayNotHasKey( 'sanitize_callback', $element, 'callables never leave PHP' );

			if ( 'field' === $element['type'] ) {
				$fields[ $element['id'] ] = $element;
			}
		}

		$this->assertSame( 10.0, $fields['default_rate']['value'] );
		$this->assertSame( 'on', $fields['exclude_tax']['value'] );

		// The subpage is the scope plugin-ui saves; keys may be dot paths.
		$updated = $this->put_request(
			'/settings/rates',
			[
				'values' => [
					'commission.rates.default_rate' => '12.5',
					'max_rate'                      => 30,
					'bogus'                         => 1,
					'hold_days'                     => 99,
				],
			]
		);

		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 12.5, flyaffiliate_get_option( 'default_rate' ) );
		$this->assertSame( 30.0, flyaffiliate_get_option( 'max_rate' ) );
		$this->assertSame( 30, flyaffiliate_get_option( 'hold_days' ), 'a field outside the scope is ignored' );
		$this->assertArrayNotHasKey( 'bogus', get_option( 'flyaffiliate_settings' ) );

		$invalid = $this->put_request( '/settings/rates', [ 'values' => [ 'default_rate' => 80 ] ] );

		$this->assertSame( 400, $invalid->get_status() );
		$this->assertArrayHasKey( 'default_rate', $invalid->get_data()['data']['errors'], 'the default may not exceed the maximum' );
		$this->assertSame( 12.5, flyaffiliate_get_option( 'default_rate' ), 'nothing is stored when validation fails' );

		$this->assertSame( 404, $this->put_request( '/settings/nonsense', [ 'values' => [] ] )->get_status() );
	}
}
