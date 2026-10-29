<?php
/**
 * Payout method registry tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\PayoutMethod;

use FlyAffiliate\Contracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Payout;
use FlyAffiliate\PayoutMethod\Manager;
use FlyAffiliate\PayoutMethod\Manual;
use FlyAffiliate\Test\Fixtures\FakeAutomaticMethod;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Which methods exist, which one pays an affiliate, and when a payment is locked.
 *
 * @group payout
 *
 * @since FLYAFFILIATE_SINCE
 */
class ManagerTest extends FlyAffiliateTestCase {

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
	 * The registry.
	 *
	 * @return Manager
	 */
	protected function methods(): Manager {
		return flyaffiliate()->payout_method;
	}

	/**
	 * Manual is always there, first, and cannot be replaced; anything that is not a method is dropped.
	 *
	 * @return void
	 */
	public function test_manual_is_always_registered_and_bad_entries_are_dropped(): void {
		add_filter(
			'flyaffiliate_payout_methods',
			static function ( array $methods ): array {
				unset( $methods['manual'] );

				$methods['junk']      = new \stdClass();
				$methods['impostor']  = new class() extends Manual {};
				$methods['Bad Id']    = new class() extends Manual {
					/**
					 * {@inheritDoc}
					 *
					 * @return string
					 */
					public function get_id(): string {
						return 'Bad Id';
					}
				};

				return $methods;
			},
			20
		);

		$all = $this->methods()->all();

		$this->assertSame( [ 'manual', 'fake' ], array_keys( $all ) );
		$this->assertInstanceOf( Manual::class, $all['manual'] );
		$this->assertSame( 'Manual', $this->methods()->get_labels()['manual'] );
		$this->assertSame( [ 'manual' => 'Manual', 'fake' => 'Fake' ], $this->methods()->get_labels() );
	}

	/**
	 * The list is read on every call: a method switched on mid-request is there at once.
	 *
	 * @return void
	 */
	public function test_the_list_is_not_cached(): void {
		$this->assertTrue( $this->methods()->is_enabled( 'fake' ) );

		$this->fake->enabled = false;

		$this->assertFalse( $this->methods()->is_enabled( 'fake' ) );
		$this->assertTrue( $this->methods()->has( 'fake' ), 'switched off is still registered' );
		$this->assertSame( [ 'manual' ], array_keys( $this->methods()->enabled() ) );
	}

	/**
	 * Override, then the affiliate's choice, then the default — each only when switched on — then Manual.
	 *
	 * @return void
	 */
	public function test_resolve_order(): void {
		$affiliate = Affiliate::find( $this->factory()->affiliate->create() );

		$this->assertSame( 'manual', $this->methods()->resolve_for( $affiliate )->get_id() );

		flyaffiliate_update_option( 'method', 'fake' );
		flyaffiliate()->settings->get_registry()->clear_cache();

		$this->assertSame( 'fake', $this->methods()->get_default()->get_id() );
		$this->assertSame( 'fake', $this->methods()->resolve_for( $affiliate )->get_id(), 'the default' );
		$this->assertSame( 'manual', $this->methods()->resolve_for( $affiliate, 'manual' )->get_id(), 'the override wins' );

		$affiliate->set( 'payout_method', 'manual' );
		$this->assertSame( 'manual', $this->methods()->resolve_for( $affiliate )->get_id(), 'the affiliate\'s choice beats the default' );
		$this->assertSame( 'fake', $this->methods()->resolve_for( $affiliate, 'fake' )->get_id() );

		$this->fake->enabled = false;
		$affiliate->set( 'payout_method', 'fake' );

		$this->assertSame( 'manual', $this->methods()->get_default()->get_id(), 'a default switched off falls back to Manual' );
		$this->assertSame( 'manual', $this->methods()->resolve_for( $affiliate, 'fake' )->get_id(), 'a choice switched off is ignored' );
	}

	/**
	 * The default payout method setting saves any registered method.
	 *
	 * @return void
	 */
	public function test_the_default_method_setting_accepts_a_registered_method(): void {
		$this->acting_as( $this->admin_id );

		$response = $this->put_request( '/settings/payout', [ 'values' => [ 'method' => 'fake' ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'fake', flyaffiliate_get_option( 'method' ) );
	}

	/**
	 * In flight and method-paid lock a payment; failed and nothing sent do not; neither does Manual.
	 *
	 * @return void
	 */
	public function test_locks_follow_the_transfer(): void {
		$payout = Payout::find( $this->factory()->payout->create( [ 'method' => 'fake', 'status' => Payout::STATUS_UNPAID ] ) );

		$this->assertSame( '', $this->methods()->get_lock( $payout ), 'nothing sent' );
		$this->assertTrue( $this->methods()->can_retry( $payout ) );

		foreach ( [ PayoutMethod::STATE_QUEUED, PayoutMethod::STATE_PROCESSING, PayoutMethod::STATE_PENDING ] as $state ) {
			$this->fake->states[ $payout->get_id() ] = [ 'status' => $state ];

			$this->assertSame( Manager::LOCK_IN_FLIGHT, $this->methods()->get_lock( $payout ), $state );
			$this->assertFalse( $this->methods()->can_retry( $payout ), $state );
		}

		$this->fake->states[ $payout->get_id() ] = [ 'status' => PayoutMethod::STATE_PAID ];
		$this->assertSame( Manager::LOCK_SETTLED, $this->methods()->get_lock( $payout ) );

		$this->fake->states[ $payout->get_id() ] = [
			'status'    => PayoutMethod::STATE_FAILED,
			'retryable' => true,
		];
		$this->assertSame( '', $this->methods()->get_lock( $payout ) );
		$this->assertTrue( $this->methods()->can_retry( $payout ) );

		$this->fake->states[ $payout->get_id() ] = [ 'status' => PayoutMethod::STATE_FAILED ];
		$this->assertFalse( $this->methods()->can_retry( $payout ), 'a failure the method says not to retry' );

		$this->fake->states[ $payout->get_id() ] = [ 'status' => 'bogus' ];
		$this->assertNull( $this->methods()->get_state( $payout ), 'an unknown state is not a state' );

		$manual = Payout::find( $this->factory()->payout->create( [ 'status' => Payout::STATUS_PAID ] ) );
		$this->assertSame( '', $this->methods()->get_lock( $manual ) );
		$this->assertFalse( $this->methods()->can_retry( $manual ) );
	}

	/**
	 * Where a payment goes: the address its transfer used once one was sent,
	 * otherwise where its method pays the affiliate.
	 *
	 * @return void
	 */
	public function test_the_payment_recipient_follows_the_transfer(): void {
		$affiliate = Affiliate::find( $this->factory()->affiliate->create( [ 'payment_email' => 'hand@example.org' ] ) );
		$manual    = Payout::find( $this->factory()->payout->create( [ 'affiliate_id' => $affiliate->get_id() ] ) );
		$sent      = Payout::find(
			$this->factory()->payout->create(
				[
					'affiliate_id' => $affiliate->get_id(),
					'method'       => 'fake',
				]
			)
		);

		$this->assertSame( 'hand@example.org', $this->methods()->get_payment_recipient( $manual, $affiliate ) );
		$this->assertSame( '', $this->methods()->get_payment_recipient( $sent, $affiliate ), 'nothing sent yet, and no address for the method' );

		$this->fake->save_affiliate_details( $affiliate, [ 'email' => 'fake@example.org' ] );

		$this->assertSame( 'fake@example.org', $this->methods()->get_payment_recipient( $sent, $affiliate ), 'nothing sent yet: where the method pays them' );

		$this->fake->states[ $sent->get_id() ] = [
			'status'    => PayoutMethod::STATE_PAID,
			'recipient' => 'paypal@example.org',
		];

		$this->assertSame( 'paypal@example.org', $this->methods()->get_payment_recipient( $sent, $affiliate ) );
		$this->assertSame( 'paypal@example.org', $this->methods()->get_state( $sent )['recipient'] );
		$this->assertSame( '', $this->methods()->get_payment_recipient( $manual, null ) );
	}

	/**
	 * A payment whose method is not registered is locked, until a site releases it on purpose.
	 *
	 * @return void
	 */
	public function test_an_unregistered_method_locks_until_released(): void {
		$payout = Payout::find( $this->factory()->payout->create( [ 'method' => 'gone', 'status' => Payout::STATUS_UNPAID ] ) );

		$this->assertSame( Manager::LOCK_UNAVAILABLE, $this->methods()->get_lock( $payout ) );
		$this->assertSame( 'gone', $this->methods()->get_label( 'gone' ) );

		add_filter( 'flyaffiliate_payout_locked', '__return_false' );

		$this->assertSame( '', $this->methods()->get_lock( $payout ) );
	}

	/**
	 * A method that throws while reporting reports nothing; the screen that asked still renders.
	 *
	 * @return void
	 */
	public function test_a_method_that_throws_reports_nothing(): void {
		$payout = Payout::find( $this->factory()->payout->create( [ 'method' => 'fake', 'status' => Payout::STATUS_UNPAID ] ) );

		add_filter(
			'flyaffiliate_payout_methods',
			static function ( array $methods ): array {
				$methods['fake'] = new class() extends FakeAutomaticMethod {
					/**
					 * {@inheritDoc}
					 *
					 * @param array $payouts The payments.
					 *
					 * @return array
					 */
					public function get_states( array $payouts ): array {
						throw new \RuntimeException( 'down' );
					}
				};

				return $methods;
			},
			5
		);

		$this->assertSame( [], $this->methods()->get_states( [ $payout ] ) );
	}

	/**
	 * The default details store keeps one row per method, checks the type, and refuses an empty required field.
	 *
	 * @return void
	 */
	public function test_affiliate_details_are_stored_and_checked(): void {
		$affiliate = Affiliate::find( $this->factory()->affiliate->create() );

		$this->assertSame( [ 'email' => '' ], $this->fake->get_affiliate_details( $affiliate ) );
		$this->assertWPError( $this->fake->check_affiliate( $affiliate ) );
		$this->assertSame( 'Fake email missing.', $this->fake->check_affiliate( $affiliate )->get_error_message() );
		$this->assertSame( '', $this->fake->get_recipient( $affiliate ) );

		$this->assertWPError( $this->fake->save_affiliate_details( $affiliate, [ 'email' => 'not an email' ] ) );
		$this->assertWPError( $this->fake->save_affiliate_details( $affiliate, [ 'email' => '' ] ) );
		$this->assertTrue( $this->fake->save_affiliate_details( $affiliate, [ 'email' => 'me@example.test' ] ) );

		$this->assertSame( [ 'email' => 'me@example.test' ], $this->fake->get_affiliate_details( $affiliate ) );
		$this->assertTrue( $this->fake->check_affiliate( $affiliate ) );
		$this->assertSame( 'me@example.test', $this->fake->get_recipient( $affiliate ) );
		$this->assertSame( $affiliate->get_payment_email(), ( new Manual() )->get_recipient( $affiliate ), 'Manual pays the payment email' );
	}

	/**
	 * The apps get every method for the admin, and only the enabled ones for the affiliate.
	 *
	 * @return void
	 */
	public function test_script_data(): void {
		$this->fake->enabled = false;

		$admin = $this->methods()->get_script_data();

		$this->assertSame( [ 'manual', 'fake' ], array_column( $admin, 'id' ) );
		$this->assertFalse( $admin[1]['enabled'] );
		$this->assertTrue( $admin[1]['automatic'] );
		$this->assertSame( 'email', $admin[1]['fields'][0]['type'] );
		$this->assertSame( [ 'manual' ], array_column( $this->methods()->get_script_data( true ), 'id' ) );
	}
}
