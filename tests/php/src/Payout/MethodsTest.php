<?php
/**
 * Payouts paid by more than one method.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Payout;

use FlyAffiliate\Contracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Payout;
use FlyAffiliate\Test\Fixtures\FakeAutomaticMethod;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Each affiliate is paid by their method; automatic methods get their
 * payments at once; nothing changes a payment by hand while its money is on
 * the way; and nothing is ever paid twice (CONTEXT.md money rule 7).
 *
 * @group payout
 *
 * @since FLYAFFILIATE_SINCE
 */
class MethodsTest extends FlyAffiliateTestCase {

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
	 * An affiliate who chose the fake method, with their details given.
	 *
	 * @param float $owed What they are owed, as one unpaid commission.
	 *
	 * @return int The affiliate id.
	 */
	protected function fake_affiliate( float $owed = 40 ): int {
		$id        = $this->factory()->affiliate->create( [ 'payout_method' => 'fake' ] );
		$affiliate = Affiliate::find( $id );

		$this->fake->save_affiliate_details( $affiliate, [ 'email' => 'fake' . $id . '@example.test' ] );
		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => $owed, 'status' => Commission::STATUS_UNPAID ] );

		return $id;
	}

	/**
	 * A Manual affiliate.
	 *
	 * @param float $owed What they are owed.
	 *
	 * @return int The affiliate id.
	 */
	protected function manual_affiliate( float $owed = 10 ): int {
		$id = $this->factory()->affiliate->create();

		$this->factory()->commission->create( [ 'affiliate_id' => $id, 'amount' => $owed, 'status' => Commission::STATUS_UNPAID ] );

		return $id;
	}

	/**
	 * The preview resolves each affiliate's method, groups them with subtotals, and warns before money moves.
	 *
	 * @return void
	 */
	public function test_preview_groups_by_method_and_warns(): void {
		$auto     = $this->fake_affiliate( 40.25 );
		$no_email = $this->factory()->affiliate->create( [ 'payout_method' => 'fake' ] );
		$manual   = $this->manual_affiliate( 10 );

		$this->factory()->commission->create( [ 'affiliate_id' => $no_email, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );

		$preview = flyaffiliate()->payout->preview( [ 'minimum_amount' => 0 ] );

		$this->assertSame( 'fake', $preview['rows'][ $auto ]['method']->get_id() );
		$this->assertSame( 'fake' . $auto . '@example.test', $preview['rows'][ $auto ]['recipient'] );
		$this->assertSame( '', $preview['rows'][ $auto ]['warning'] );
		$this->assertSame( 'Fake email missing.', $preview['rows'][ $no_email ]['warning'] );
		$this->assertSame( 'manual', $preview['rows'][ $manual ]['method']->get_id() );

		$this->assertSame( [ 'manual', 'fake' ], array_keys( $preview['groups'] ) );
		$this->assertSame( 2, $preview['groups']['fake']['count'] );
		$this->assertCentsEquals( 4525, $preview['groups']['fake']['total'] );
		$this->assertCentsEquals( 1000, $preview['groups']['manual']['total'] );
		$this->assertCentsEquals( 5525, $preview['total'] );

		// Switch to Manual, for this payout only.
		$switched = flyaffiliate()->payout->preview(
			[
				'minimum_amount' => 0,
				'methods'        => [
					$no_email => 'manual',
					$manual   => 'nonsense',
				],
			]
		);

		$this->assertSame( 'manual', $switched['rows'][ $no_email ]['method']->get_id() );
		$this->assertSame( '', $switched['rows'][ $no_email ]['warning'] );
		$this->assertSame( 'manual', $switched['rows'][ $manual ]['method']->get_id(), 'an unknown method is ignored' );
		$this->assertSame( 1, $switched['groups']['fake']['count'] );
		$this->assertSame( 'fake', Affiliate::find( $no_email )->get_payout_method(), 'the override does not change the affiliate' );
	}

	/**
	 * Creating a payout writes each payment's method and hands each automatic method exactly its own payments.
	 *
	 * @return void
	 */
	public function test_create_hands_each_method_its_payments(): void {
		$a      = $this->fake_affiliate( 40 );
		$b      = $this->fake_affiliate( 12.5 );
		$manual = $this->manual_affiliate( 10 );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->fake->paid, 'one call per method' );
		$this->assertSame( $result['batch_key'], $this->fake->paid[0]['batch_key'] );

		$methods = [];

		foreach ( $result['payouts'] as $payout ) {
			$methods[ (int) $payout->get( 'affiliate_id' ) ] = $payout->get( 'method' );
			$this->assertSame( Payout::STATUS_UNPAID, $payout->get( 'status' ), 'nothing is paid until the money arrives' );
		}

		$this->assertSame( 'fake', $methods[ $a ] );
		$this->assertSame( 'fake', $methods[ $b ] );
		$this->assertSame( 'manual', $methods[ $manual ] );
		$this->assertSame( [ 4000, 1250 ], array_values( $this->fake->paid[0]['payouts'] ) );
	}

	/**
	 * A method that throws leaves its payments unpaid and not sent, and the payout still records.
	 *
	 * @return void
	 */
	public function test_a_throwing_method_leaves_its_payments_unpaid(): void {
		$this->fake_affiliate( 40 );
		$this->manual_affiliate( 10 );

		$this->fake->throw = new \RuntimeException( 'service down' );
		$failed            = [];

		add_action(
			'flyaffiliate_payout_method_failed',
			static function ( $method, $batch, $error ) use ( &$failed ) {
				$failed[] = [ $method, $error->getMessage() ];
			},
			10,
			3
		);

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['payouts'] );
		$this->assertSame( [ [ 'fake', 'service down' ] ], $failed );

		$summary = flyaffiliate()->payout->summarize_batch( $result['batch_key'] );

		$this->assertSame( 1, $summary['counts']['not_sent'] );
		$this->assertSame( 1, $summary['counts']['manual_unpaid'] );
		$this->assertSame( 'needs_attention', $summary['status'] );
	}

	/**
	 * What the admin saw must still be what is owed; otherwise nothing is written.
	 *
	 * @return void
	 */
	public function test_create_refuses_when_the_amounts_changed_since_the_preview(): void {
		$a = $this->manual_affiliate( 10 );
		$b = $this->manual_affiliate( 20 );

		$stale = flyaffiliate()->payout->create(
			[
				'minimum_amount' => 0,
				'expected'       => [
					$a => 10,
					$b => 25,
				],
			]
		);

		$this->assertWPError( $stale );
		$this->assertSame( 'flyaffiliate_payout_changed', $stale->get_error_code() );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 0 );

		$missing = flyaffiliate()->payout->create(
			[
				'minimum_amount' => 0,
				'expected'       => [ $a => 10 ],
			]
		);

		$this->assertWPError( $missing, 'an affiliate the admin did not see' );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 0 );

		$fresh = flyaffiliate()->payout->create(
			[
				'minimum_amount' => 0,
				'expected'       => [
					$a => 10.0,
					$b => '20.00',
				],
			]
		);

		$this->assertIsArray( $fresh );
		$this->assertCentsEquals( 3000, $fresh['total'] );
	}

	/**
	 * A commission another payout took between preview and write is never attached twice.
	 *
	 * @return void
	 */
	public function test_a_commission_taken_by_another_payout_is_not_attached_again(): void {
		$affiliate = $this->factory()->affiliate->create();
		$first     = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 10, 'status' => Commission::STATUS_UNPAID ] );
		$second    = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 5, 'status' => Commission::STATUS_UNPAID ] );
		$other     = $this->factory()->payout->create( [ 'status' => Payout::STATUS_UNPAID ] );

		// Another payout attaches the second commission after this one previewed it.
		$manager = new class() extends \FlyAffiliate\Payout\Manager {
			/**
			 * The commission another request takes in the meantime, and by which payment.
			 *
			 * @var array{0: int, 1: int}
			 */
			public array $race = [ 0, 0 ];

			/**
			 * {@inheritDoc}
			 *
			 * @param array $args Selection.
			 *
			 * @return array
			 */
			public function preview( array $args ): array {
				$preview = parent::preview( $args );

				global $wpdb;

				$wpdb->update( Commission::get_table(), [ 'payout_id' => $this->race[1] ], [ 'id' => $this->race[0] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				return $preview;
			}
		};

		$manager->race = [ $second, $other ];
		$result        = $manager->create( [ 'minimum_amount' => 0 ] );

		$this->assertWPError( $result );
		$this->assertSame( 'flyaffiliate_payout_attach_failed', $result->get_error_code() );
		$this->assertDatabaseCount( 'flyaffiliate_payouts', 1 ); // Only the other payout is left.
		$this->assertSame( 0, (int) flyaffiliate()->commission->get( $first )->get( 'payout_id' ), 'the batch unwound' );
		$this->assertSame( $other, (int) flyaffiliate()->commission->get( $second )->get( 'payout_id' ), 'the other payout keeps its commission' );
	}

	/**
	 * While its transfer is in flight, nothing changes a payment by hand — except its method settling it.
	 *
	 * @return void
	 */
	public function test_an_in_flight_payment_is_locked(): void {
		$affiliate = $this->fake_affiliate( 40 );
		$extra     = $this->factory()->commission->create( [ 'affiliate_id' => $affiliate, 'amount' => 2, 'status' => Commission::STATUS_UNPAID ] );

		$this->fake->state_on_pay = PayoutMethod::STATE_PROCESSING;

		$result  = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$payout  = $result['payouts'][0];
		$id      = $payout->get_id();
		$manager = flyaffiliate()->payout;

		$this->assertTrue( $manager->is_locked( $payout ) );

		foreach (
			[
				'mark_paid'         => $manager->mark_paid( $id ),
				'delete'            => $manager->delete( $id ),
				'delete_batch'      => $manager->delete_batch( $result['batch_key'] ),
				'remove_commission' => $manager->remove_commission( $id, $extra ),
				'retry'             => $manager->retry( $id ),
				'commission update' => flyaffiliate()->commission->update( $extra, [ 'amount' => 99 ] ),
				'commission status' => flyaffiliate()->commission->set_status( $extra, Commission::STATUS_REJECTED ),
			] as $action => $outcome
		) {
			$this->assertWPError( $outcome, $action );
			$this->assertSame( 409, $outcome->get_error_data()['status'] ?? 0, $action );
		}

		// A commission edited behind the lock does not change what is being sent.
		$manager->resync( $id );
		$this->assertCentsEquals( 4200, $manager->get( $id )->get( 'amount' ) );

		// The method settles it: paid, commissions paid, once.
		$paid_hooks = 0;
		add_action(
			'flyaffiliate_payout_paid',
			static function () use ( &$paid_hooks ) {
				++$paid_hooks;
			}
		);

		$this->assertInstanceOf( Payout::class, $manager->mark_paid( $id, true ) );
		$this->assertInstanceOf( Payout::class, $manager->mark_paid( $id, true ) );
		$this->assertSame( 1, $paid_hooks, 'the second settle changes nothing' );
		$this->assertSame( 'fake', $manager->get( $id )->get( 'method' ), 'paid via its method' );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 2, [ 'status' => Commission::STATUS_PAID ] );

		// Paid by the method: the admin cannot mark it unpaid; the method can, when the money comes back.
		$this->fake->states[ $id ] = [ 'status' => PayoutMethod::STATE_PAID ];
		$this->assertWPError( $manager->mark_unpaid( $id ) );
		$this->assertInstanceOf( Payout::class, $manager->mark_unpaid( $id, true ) );
		$this->assertDatabaseCount( 'flyaffiliate_commissions', 2, [ 'status' => Commission::STATUS_UNPAID ] );
	}

	/**
	 * A failed transfer frees the payment: it can be sent again at its re-summed amount, or paid by hand as Manual.
	 *
	 * @return void
	 */
	public function test_a_failed_payment_can_be_retried_or_paid_by_hand(): void {
		$affiliate = $this->fake_affiliate( 40 );
		$result    = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$id        = $result['payouts'][0]->get_id();
		$manager   = flyaffiliate()->payout;

		$this->fake->states[ $id ] = [
			'status'    => PayoutMethod::STATE_FAILED,
			'message'   => 'Receiver unregistered',
			'retryable' => true,
		];

		$commission = $manager->get( $id )->get_commissions()[0];

		// Corrected while it waited: the retry sends the new amount.
		$this->assertInstanceOf( Commission::class, flyaffiliate()->commission->update( $commission->get_id(), [ 'amount' => 35 ] ) );
		$this->assertInstanceOf( Payout::class, $manager->retry( $id ) );
		$this->assertSame( [ $id => 3500 ], $this->fake->retried );
		$this->assertTrue( $manager->is_locked( $manager->get( $id ) ), 'sending again' );

		// Failed again, not worth retrying: no retry, but the admin can pay it by hand.
		$this->fake->states[ $id ] = [ 'status' => PayoutMethod::STATE_FAILED ];

		$this->assertWPError( $manager->retry( $id ) );

		$paid = $manager->mark_paid( $id );

		$this->assertInstanceOf( Payout::class, $paid );
		$this->assertSame( Payout::METHOD_MANUAL, $paid->get( 'method' ), 'paid some other way: recorded as Manual' );
		$this->assertTrue( $paid->is_paid() );
		$this->assertInstanceOf( Payout::class, $manager->mark_unpaid( $id ), 'a Manual payment can be undone by hand' );
		$this->assertSame( 'fake', Affiliate::find( $affiliate )->get_payout_method(), 'the affiliate keeps their choice' );
	}

	/**
	 * Retrying is for automatic payments only.
	 *
	 * @return void
	 */
	public function test_retry_refuses_manual_and_paid_payments(): void {
		$this->manual_affiliate( 10 );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$id     = $result['payouts'][0]->get_id();

		$this->assertSame( 'flyaffiliate_payout_not_retryable', flyaffiliate()->payout->retry( $id )->get_error_code() );

		flyaffiliate()->payout->mark_paid( $id );

		$this->assertSame( 'flyaffiliate_payout_paid', flyaffiliate()->payout->retry( $id )->get_error_code() );
		$this->assertSame( 404, flyaffiliate()->payout->retry( 999999 )->get_error_data()['status'] );
	}

	/**
	 * "Mark all as paid" marks the Manual payments and leaves the automatic ones to their method.
	 *
	 * @return void
	 */
	public function test_pay_batch_marks_only_manual_payments(): void {
		$this->fake_affiliate( 40 );
		$this->manual_affiliate( 10 );
		$this->manual_affiliate( 15 );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );

		$this->assertSame( 2, flyaffiliate()->payout->pay_batch( $result['batch_key'] ) );
		$this->assertSame( 0, flyaffiliate()->payout->pay_batch( $result['batch_key'] ), 'a second call pays nothing' );

		$summary = flyaffiliate()->payout->summarize_batch( $result['batch_key'] );

		$this->assertSame( 2, $summary['paid'] );
		$this->assertSame( 1, $summary['counts']['not_sent'] );
		$this->assertCentsEquals( 2500, $summary['paid_total'] );
		$this->assertCentsEquals( 6500, $summary['total'] );
	}

	/**
	 * A payment sent by a method that is gone stays locked: nobody can pay it twice by hand.
	 *
	 * @return void
	 */
	public function test_a_payment_whose_method_is_gone_is_locked(): void {
		$payout = $this->factory()->payout->create( [ 'method' => 'gone', 'status' => Payout::STATUS_UNPAID ] );

		$this->assertSame( 409, flyaffiliate()->payout->mark_paid( $payout )->get_error_data()['status'] );
		$this->assertSame( 409, flyaffiliate()->payout->delete( $payout )->get_error_data()['status'] );
		$this->assertStringContainsString( 'not available', flyaffiliate()->payout->delete( $payout )->get_error_message() );
	}

	/**
	 * A payout's status follows its payments: processing, needs attention, awaiting payment, paid.
	 *
	 * @return void
	 */
	public function test_the_payout_status_follows_its_payments(): void {
		$this->fake_affiliate( 40 );
		$this->manual_affiliate( 10 );

		$this->fake->state_on_pay = PayoutMethod::STATE_PENDING;

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$key    = $result['batch_key'];
		$auto   = 0;
		$manual = 0;

		foreach ( $result['payouts'] as $payout ) {
			if ( 'fake' === $payout->get( 'method' ) ) {
				$auto = $payout->get_id();
			} else {
				$manual = $payout->get_id();
			}
		}

		$summary = flyaffiliate()->payout->summarize_batch( $key );

		$this->assertSame( 'processing', $summary['status'] );
		$this->assertSame( 1, $summary['in_flight'] );
		$this->assertSame( [ 'fake', 'manual' ], array_values( array_intersect( [ 'fake', 'manual' ], $summary['methods'] ) ) );

		$this->fake->states[ $auto ] = [
			'status'    => PayoutMethod::STATE_FAILED,
			'retryable' => true,
		];
		$this->assertSame( 'needs_attention', flyaffiliate()->payout->summarize_batch( $key )['status'] );

		$this->fake->states[ $auto ] = [ 'status' => PayoutMethod::STATE_PAID ];
		flyaffiliate()->payout->mark_paid( $auto, true );
		$this->assertSame( 'unpaid', flyaffiliate()->payout->summarize_batch( $key )['status'], 'only the Manual payment is left' );

		flyaffiliate()->payout->mark_paid( $manual );
		$this->assertSame( 'paid', flyaffiliate()->payout->summarize_batch( $key )['status'] );

		$batches = flyaffiliate()->payout->get_batches();

		$this->assertSame( 'paid', $batches['batches'][0]['status'] );
		$this->assertSame( 2, $batches['batches'][0]['counts']['paid'] );
	}

	/**
	 * Refreshing a payout asks each automatic method about its unpaid payments only.
	 *
	 * @return void
	 */
	public function test_refresh_asks_each_method_about_its_unpaid_payments(): void {
		$this->fake_affiliate( 40 );
		$this->fake_affiliate( 20 );
		$this->manual_affiliate( 10 );

		$result = flyaffiliate()->payout->create( [ 'minimum_amount' => 0 ] );
		$fakes  = array_values( array_filter( $result['payouts'], static fn( $payout ) => 'fake' === $payout->get( 'method' ) ) );

		$this->fake->states[ $fakes[0]->get_id() ] = [ 'status' => PayoutMethod::STATE_PAID ];
		flyaffiliate()->payout->mark_paid( $fakes[0]->get_id(), true );

		flyaffiliate()->payout->refresh_batch( $result['batch_key'] );

		$this->assertSame( [ [ $fakes[1]->get_id() ] ], $this->fake->refreshed );
	}
}
