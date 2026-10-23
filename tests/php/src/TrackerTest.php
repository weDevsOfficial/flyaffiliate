<?php
/**
 * Tests for the Appsero tracker and Share Essentials.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test;

use FlyAffiliate\Tracker;

/**
 * Usage data is sent only once the site allows it, and the wizard's Share
 * Essentials is what allows or declines it. No test reaches Appsero: every
 * request is answered on `pre_http_request` and recorded.
 *
 * @since FLYAFFILIATE_SINCE
 */
class TrackerTest extends FlyAffiliateTestCase {

	/**
	 * The SDK's options and event, named after the plugin's directory.
	 *
	 * @var string
	 */
	const ALLOW_OPTION = 'flyaffiliate_allow_tracking';

	/**
	 * The weekly send.
	 *
	 * @var string
	 */
	const SEND_EVENT = 'flyaffiliate_tracker_send_event';

	/**
	 * The URLs requested during the test, with their bodies.
	 *
	 * @var array<int, array{url: string, body: mixed}>
	 */
	protected array $requests = [];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		foreach ( [ self::ALLOW_OPTION, 'flyaffiliate_tracking_notice', 'flyaffiliate_tracking_last_send', 'flyaffiliate_tracking_skipped' ] as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( self::SEND_EVENT );

		$this->requests = [];
		add_filter( 'pre_http_request', [ $this, 'record_request' ], 10, 3 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'record_request' ] );
		wp_clear_scheduled_hook( self::SEND_EVENT );

		parent::tear_down();
	}

	/**
	 * Answer a request without sending it, and remember it.
	 *
	 * @param false|array $response Short-circuit value.
	 * @param array       $args     Request arguments.
	 * @param string      $url      The URL.
	 *
	 * @return array
	 */
	public function record_request( $response, $args, $url ): array {
		$this->requests[] = [
			'url'  => $url,
			'body' => $args['body'] ?? null,
		];

		return [
			'headers'  => [],
			'body'     => '203.0.113.7',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	}

	/**
	 * The requests sent to one Appsero route.
	 *
	 * @param string $route `track`, `tracking-skipped`, …
	 *
	 * @return array<int, array{url: string, body: mixed}>
	 */
	protected function appsero_requests( string $route ): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn( array $request ): bool => 'https://api.appsero.com/' . $route === $request['url']
			)
		);
	}

	/**
	 * The service.
	 *
	 * @return Tracker
	 */
	protected function tracker(): Tracker {
		return flyaffiliate()->tracker;
	}

	/**
	 * Nothing is allowed, scheduled or sent on a site that was never asked.
	 *
	 * @return void
	 */
	public function test_nothing_is_sent_until_allowed(): void {
		$this->assertFalse( $this->tracker()->is_allowed() );

		do_action( self::SEND_EVENT );

		$this->assertFalse( wp_next_scheduled( self::SEND_EVENT ) );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Opting in allows sending, schedules the weekly send and sends at once,
	 * with FlyAffiliate's product hash and its counts.
	 *
	 * @return void
	 */
	public function test_optin_schedules_and_sends(): void {
		$affiliate = $this->factory()->affiliate->create();
		$this->factory()->commission->create_many( 2, [ 'affiliate_id' => $affiliate ] );

		$this->tracker()->optin();

		$this->assertTrue( $this->tracker()->is_allowed() );
		$this->assertNotFalse( wp_next_scheduled( self::SEND_EVENT ) );

		$sent = $this->appsero_requests( 'track' );

		$this->assertCount( 1, $sent );
		$this->assertSame( Tracker::APPSERO_HASH, $sent[0]['body']['hash'] );
		$this->assertSame( 1, $sent[0]['body']['extra']['affiliates'] );
		$this->assertSame( 2, $sent[0]['body']['extra']['commissions'] );
		$this->assertSame( FLYAFFILIATE_VERSION, $sent[0]['body']['extra']['flyaffiliate_version'] );
	}

	/**
	 * Opting out stops the weekly send and tells Appsero only that the site
	 * declined.
	 *
	 * @return void
	 */
	public function test_optout_stops_sending(): void {
		$this->tracker()->optin();
		$this->requests = [];

		$this->tracker()->optout();

		$this->assertFalse( $this->tracker()->is_allowed() );
		$this->assertFalse( wp_next_scheduled( self::SEND_EVENT ) );
		$this->assertSame( [], $this->appsero_requests( 'track' ) );

		$skipped = $this->appsero_requests( 'tracking-skipped' );

		$this->assertCount( 1, $skipped );
		$this->assertSame( [ 'hash', 'previously_skipped', 'client' ], array_keys( $skipped[0]['body'] ) );
	}

	/**
	 * Activation resumes the weekly send on a site that allowed it, and does
	 * nothing on one that did not.
	 *
	 * @return void
	 */
	public function test_activation_resumes_only_an_allowed_send(): void {
		flyaffiliate()->activate();

		$this->assertFalse( wp_next_scheduled( self::SEND_EVENT ) );
		$this->assertSame( [], $this->appsero_requests( 'track' ) );

		update_option( self::ALLOW_OPTION, 'yes' );

		flyaffiliate()->activate();

		$this->assertNotFalse( wp_next_scheduled( self::SEND_EVENT ) );
		$this->assertCount( 1, $this->appsero_requests( 'track' ) );
	}

	/**
	 * Share Essentials on the wizard's last step opts in, or out; a request
	 * without it leaves the choice alone.
	 *
	 * @return void
	 */
	public function test_setup_complete_follows_share_essentials(): void {
		$this->acting_as( $this->admin_id );

		$response = $this->post_request( '/setup/complete', [ 'share_essentials' => true ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['share_essentials'] );
		$this->assertTrue( $this->tracker()->is_allowed() );

		$this->post_request( '/setup/complete' );
		$this->assertTrue( $this->tracker()->is_allowed() );

		$response = $this->post_request( '/setup/complete', [ 'share_essentials' => false ] );

		$this->assertFalse( $response->get_data()['share_essentials'] );
		$this->assertFalse( $this->tracker()->is_allowed() );
	}
}
