<?php
/**
 * Payout REST tests: methods, transfer states and sending again.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\REST;

use FlyAffiliate\Contracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\Fixtures\FakeAutomaticMethod;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * What the payout screens and the affiliate dashboard read and send.
 *
 * @group payout
 * @group rest
 *
 * @since FLYAFFILIATE_SINCE
 */
class PayoutsControllerTest extends FlyAffiliateTestCase {

	/**
	 * The automatic method under test.
	 *
	 * @var FakeAutomaticMethod
	 */
	protected FakeAutomaticMethod $fake;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->fake = new FakeAutomaticMethod();

		add_filter(
			'flyaffiliate_payout_methods',
			function ( array $methods ): array {
				$methods['fake'] = $this->fake;

				return $methods;
			}
		);
	}

	/**
	 * An affiliate on the fake method, owed something.
	 *
	 * @param float  $owed  What they are owed.
	 * @param string $email Their fake-method email; empty for none.
	 *
	 * @return int The affiliate id.
	 */
	protected function fake_affiliate( float $owed, string $email = 'paid@example.test' ): int {
		$user = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$id   = $this->factory()->affiliate->create(
			[
				'user_id'       => $user,
				'status'        => Affiliate::STATUS_ACTIVE,
				'payout_method' => 'fake',
			]
		);

		if ( '' !== $email ) {
			$this->fake->save_affiliate_details( Affiliate::find( $id ), [ 'email' => $email ] );
		}

		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => $owed, 'status' => Commission::STATUS_UNPAID ] );

		return $id;
	}

	/**
	 * The new routes answer administrators only.
	 *
	 * @return void
	 */
	public function test_the_new_routes_are_for_administrators(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/flyaffiliate/v1/payouts/(?P<id>[\d]+)/retry', $routes );
		$this->assertArrayHasKey( '/flyaffiliate/v1/payouts/batch/(?P<batch_key>[A-Za-z0-9-]+)/refresh', $routes );

		$this->acting_as( $this->customer_id );

		$this->assertSame( 403, $this->post_request( '/payouts/1/retry' )->get_status() );
		$this->assertSame( 403, $this->get_request( '/payouts/batch/abc' )->get_status() );
		$this->assertSame( 403, $this->post_request( '/payouts/batch/abc/refresh' )->get_status() );
	}

	/**
	 * The preview carries each row's method, recipient and warning, and the groups with subtotals.
	 *
	 * @return void
	 */
	public function test_preview_rows_and_groups(): void {
		$this->acting_as( $this->admin_id );

		$ready   = $this->fake_affiliate( 40.5 );
		$missing = $this->fake_affiliate( 9.5, '' );

		$data = $this->post_request(
			'/payouts/preview',
			[
				'minimum_amount' => 0,
				'methods'        => [ (string) $missing => 'manual' ],
			]
		)->get_data();

		$rows = array_column( $data['rows'], null, 'affiliate_id' );

		$this->assertSame( 'fake', $rows[ $ready ]['method'] );
		$this->assertSame( 'Fake', $rows[ $ready ]['method_label'] );
		$this->assertTrue( $rows[ $ready ]['automatic'] );
		$this->assertSame( 'paid@example.test', $rows[ $ready ]['recipient'] );
		$this->assertSame( 'manual', $rows[ $missing ]['method'], 'switched to Manual for this payout' );
		$this->assertSame( [ 'manual', 'fake' ], array_column( $data['groups'], 'method' ) );
		$this->assertCentsEquals( 4050, $data['groups'][1]['total'] );

		$unswitched = $this->post_request( '/payouts/preview', [ 'minimum_amount' => 0 ] )->get_data();
		$rows       = array_column( $unswitched['rows'], null, 'affiliate_id' );

		$this->assertSame( 'Fake email missing.', $rows[ $missing ]['warning'] );
	}

	/**
	 * Paying lands the payments with their method and transfer; a stale preview is refused.
	 *
	 * @return void
	 */
	public function test_create_reports_each_payment_and_its_transfer(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->fake_affiliate( 25 );

		$this->fake->state_on_pay = PayoutMethod::STATE_PROCESSING;

		$stale = $this->post_request(
			'/payouts',
			[
				'mode'           => 'selected',
				'affiliate_ids'  => [ $affiliate ],
				'minimum_amount' => 0,
				'expected'       => [ (string) $affiliate => 20 ],
			]
		);

		$this->assertSame( 409, $stale->get_status() );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 0 );

		$created = $this->post_request(
			'/payouts',
			[
				'mode'           => 'selected',
				'affiliate_ids'  => [ $affiliate ],
				'minimum_amount' => 0,
				'expected'       => [ (string) $affiliate => 25 ],
			]
		);

		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );

		$payment = $created->get_data()['payouts'][0];

		$this->assertSame( 'fake', $payment['method'] );
		$this->assertSame( 'Fake', $payment['method_label'] );
		$this->assertSame( 'processing', $payment['transfer']['status'] );
		$this->assertTrue( $payment['locked'] );
		$this->assertFalse( $payment['can_retry'] );
		$this->assertNull( $payment['paid_at'] );

		$this->assertSame( 409, $this->put_request( '/payouts/' . $payment['id'], [ 'status' => 'paid' ] )->get_status(), 'locked against marking by hand' );
		$this->assertSame( 409, $this->delete_request( '/payouts/' . $payment['id'] )->get_status() );

		$batch = $this->get_request( '/payouts/batch/' . $created->get_data()['batch_key'] )->get_data();

		$this->assertSame( 'processing', $batch['status'] );
		$this->assertSame( 1, $batch['counts']['processing'] );
		$this->assertSame( [ [ 'id' => 'fake', 'label' => 'Fake' ] ], $batch['methods'] );

		$batches = $this->get_request( '/payouts/batches' )->get_data();

		$this->assertSame( 'processing', $batches[0]['status'] );
		$this->assertSame( 1, $batches[0]['in_flight'] );
	}

	/**
	 * A failed payment is sent again from its own route; refresh asks the method and answers with the summary.
	 *
	 * @return void
	 */
	public function test_retry_and_refresh(): void {
		$this->acting_as( $this->admin_id );

		$this->fake_affiliate( 30 );

		$created = $this->post_request( '/payouts', [ 'minimum_amount' => 0 ] )->get_data();
		$id      = $created['payouts'][0]['id'];

		$this->fake->states[ $id ] = [
			'status'    => PayoutMethod::STATE_FAILED,
			'message'   => 'Receiver unregistered',
			'retryable' => true,
		];

		$listed = $this->get_request( '/payouts', [ 'batch_key' => $created['batch_key'] ] )->get_data();

		$this->assertSame( 'failed', $listed[0]['transfer']['status'] );
		$this->assertSame( 'Receiver unregistered', $listed[0]['transfer']['message'] );
		$this->assertFalse( $listed[0]['locked'] );
		$this->assertTrue( $listed[0]['can_retry'] );

		$retried = $this->post_request( '/payouts/' . $id . '/retry' );

		$this->assertSame( 200, $retried->get_status() );
		$this->assertSame( 'processing', $retried->get_data()['transfer']['status'] );
		$this->assertSame( [ $id => 3000 ], $this->fake->retried );
		$this->assertSame( 409, $this->post_request( '/payouts/' . $id . '/retry' )->get_status(), 'not while it is in flight' );

		$refreshed = $this->post_request( '/payouts/batch/' . $created['batch_key'] . '/refresh' );

		$this->assertSame( 200, $refreshed->get_status() );
		$this->assertSame( [ [ $id ] ], $this->fake->refreshed );
		$this->assertSame( 1, $refreshed->get_data()['in_flight'] );
		$this->assertSame( 404, $this->get_request( '/payouts/batch/nope' )->get_status() );
	}

	/**
	 * The affiliate sees where their money is, not the admin's reasons or actions.
	 *
	 * @return void
	 */
	public function test_the_affiliate_sees_the_transfer_status_only(): void {
		$affiliate = $this->fake_affiliate( 30 );

		$this->acting_as( $this->admin_id );

		$created = $this->post_request( '/payouts', [ 'minimum_amount' => 0 ] )->get_data();
		$id      = $created['payouts'][0]['id'];

		$this->fake->states[ $id ] = [
			'status'  => PayoutMethod::STATE_FAILED,
			'message' => 'The store account is restricted',
		];

		$this->acting_as( (int) Affiliate::find( $affiliate )->get( 'user_id' ) );

		$rows = $this->get_request( '/me/payouts' )->get_data();

		$this->assertSame( [ 'status' => 'failed' ], $rows[0]['transfer'] );
		$this->assertArrayNotHasKey( 'locked', $rows[0] );
		$this->assertArrayNotHasKey( 'can_retry', $rows[0] );
	}

	/**
	 * The affiliate chooses a method and gives its details; a method switched off or a bad detail is refused.
	 *
	 * @return void
	 */
	public function test_the_affiliate_chooses_how_they_are_paid(): void {
		$user = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$id   = $this->factory()->affiliate->create(
			[
				'user_id' => $user,
				'status'  => Affiliate::STATUS_ACTIVE,
			]
		);

		$this->acting_as( $user );

		$profile = $this->get_request( '/me' )->get_data();

		$this->assertSame( '', $profile['payout_method'] );
		$this->assertSame( [ 'fake' => [ 'email' => '' ] ], $profile['payout_details'] );

		$missing = $this->put_request(
			'/me',
			[
				'payout_method'  => 'fake',
				'payout_details' => [ 'fake' => [ 'email' => '' ] ],
			]
		);

		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( '', Affiliate::find( $id )->get_payout_method(), 'nothing saved' );

		$this->assertSame(
			400,
			$this->put_request(
				'/me',
				[
					'payout_method'  => 'fake',
					'payout_details' => [ 'fake' => [ 'email' => 'not-an-email' ] ],
				]
			)->get_status()
		);

		$saved = $this->put_request(
			'/me',
			[
				'payout_method'  => 'fake',
				'payout_details' => [ 'fake' => [ 'email' => 'me@paypal.test' ] ],
			]
		);

		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'fake', $saved->get_data()['payout_method'] );
		$this->assertSame( [ 'email' => 'me@paypal.test' ], $saved->get_data()['payout_details']['fake'] );

		// Switched off since: keeping the choice is fine, choosing it anew is not.
		$this->fake->enabled = false;

		$this->assertSame( 200, $this->put_request( '/me', [ 'promo_method' => 'Blog', 'payout_method' => 'fake' ] )->get_status() );
		$this->assertSame( 200, $this->put_request( '/me', [ 'payout_method' => '' ] )->get_status() );
		$this->assertSame( 400, $this->put_request( '/me', [ 'payout_method' => 'fake' ] )->get_status() );
	}

	/**
	 * An admin sees and sets an affiliate's method too; the details only in the edit context.
	 *
	 * @return void
	 */
	public function test_the_admin_sees_and_sets_the_method(): void {
		$this->acting_as( $this->admin_id );

		$id = $this->fake_affiliate( 10 );

		$view = $this->get_request( '/affiliates/' . $id )->get_data();

		$this->assertSame( 'fake', $view['payout_method'] );
		$this->assertArrayNotHasKey( 'payout_details', $view );

		$edit = $this->get_request( '/affiliates/' . $id, [ 'context' => 'edit' ] )->get_data();

		$this->assertSame( 'paid@example.test', $edit['payout_details']['fake']['email'] );

		$updated = $this->put_request( '/affiliates/' . $id, [ 'payout_method' => 'manual' ] );

		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'manual', $updated->get_data()['payout_method'] );
		$this->assertSame( 400, $this->put_request( '/affiliates/' . $id, [ 'payout_method' => 'nope' ] )->get_status() );
	}
}
