<?php
/**
 * The referral tracker.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Tracking;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;
use FlyAffiliate\Tracking\Tracker;

/**
 * A referral link records a visit and sets a signed cookie.
 */
class TrackerTest extends FlyAffiliateTestCase {

	/**
	 * The tracker under test.
	 *
	 * @var Tracker
	 */
	protected Tracker $tracker;

	/**
	 * {@inheritDoc}
	 */
	public function set_up() {
		parent::set_up();

		$this->tracker = new Tracker();
		unset( $_COOKIE[ Tracker::COOKIE ], $_GET['affiliate'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down() {
		unset( $_COOKIE[ Tracker::COOKIE ], $_GET['affiliate'] );

		parent::tear_down();
	}

	/**
	 * A click records a visit and a cookie that parses back to it.
	 *
	 * @return void
	 */
	public function test_a_click_records_a_visit_and_sets_the_cookie(): void {
		$affiliate_id = $this->factory()->affiliate->create();

		$_GET['affiliate']       = (string) $affiliate_id;
		$_SERVER['REQUEST_URI']  = '/shop/?affiliate=' . $affiliate_id;
		$_SERVER['HTTP_REFERER'] = 'https://blog.example/post';

		$this->tracker->track();

		$visits = flyaffiliate()->tracking->query( [ 'where' => [ 'affiliate_id' => $affiliate_id ] ] );

		$this->assertCount( 1, $visits );
		$this->assertSame( home_url( '/shop/' ), $visits[0]->get( 'url' ) );
		$this->assertSame( 'https://blog.example/post', $visits[0]->get( 'referrer' ) );

		$parsed = Tracker::parse( (string) $_COOKIE[ Tracker::COOKIE ] );

		$this->assertSame( $affiliate_id, $parsed['affiliate_id'] );
		$this->assertSame( $visits[0]->get_id(), $parsed['visit_id'] );
		$this->assertSame( $parsed, $this->tracker->get_attribution() );
	}

	/**
	 * A second click on the same link is one visit.
	 *
	 * @return void
	 */
	public function test_a_repeat_click_does_not_add_a_visit(): void {
		$affiliate_id = $this->factory()->affiliate->create();

		$_GET['affiliate'] = (string) $affiliate_id;

		$this->tracker->track();
		$this->tracker->track();

		$this->assertSame( 1, flyaffiliate()->tracking->count( [ 'where' => [ 'affiliate_id' => $affiliate_id ] ] ) );
	}

	/**
	 * Only an active affiliate is tracked.
	 *
	 * @return void
	 */
	public function test_inactive_and_unknown_affiliates_are_ignored(): void {
		$affiliate_id = $this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );

		foreach ( [ (string) $affiliate_id, '999999', 'abc' ] as $value ) {
			$_GET['affiliate'] = $value;
			$this->tracker->track();
		}

		$this->assertSame( 0, flyaffiliate()->tracking->count() );
		$this->assertArrayNotHasKey( Tracker::COOKIE, $_COOKIE );
	}

	/**
	 * An affiliate following their own link is not attributed.
	 *
	 * @return void
	 */
	public function test_self_referral_is_not_tracked(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$affiliate    = flyaffiliate()->affiliate->get( $affiliate_id );

		$this->acting_as( (int) $affiliate->get( 'user_id' ) );
		$_GET['affiliate'] = (string) $affiliate_id;

		$this->tracker->track();

		$this->assertSame( 0, flyaffiliate()->tracking->count() );
		$this->assertArrayNotHasKey( Tracker::COOKIE, $_COOKIE );
	}

	/**
	 * A tampered cookie is worthless.
	 *
	 * @return void
	 */
	public function test_a_forged_cookie_is_rejected(): void {
		$this->assertNull( Tracker::parse( '5|9|notasignature' ) );
		$this->assertNull( Tracker::parse( '' ) );

		$valid = Tracker::sign( 5, 9 );
		$this->assertNotNull( Tracker::parse( $valid ) );
		$this->assertNull( Tracker::parse( str_replace( '5|9', '6|9', $valid ) ) );
	}
}
