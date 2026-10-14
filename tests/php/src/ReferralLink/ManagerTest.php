<?php
/**
 * Saved referral links.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\ReferralLink;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;
use FlyAffiliate\Tracking\Tracker;

/**
 * A saved link is the affiliate's list, and its figures come from the visits.
 *
 * @group referral-links
 */
class ManagerTest extends FlyAffiliateTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function tear_down() {
		unset( $_COOKIE[ Tracker::COOKIE ], $_GET['affiliate'] );

		parent::tear_down();
	}

	/**
	 * Every accepted form of a page is saved on the site's origin, without
	 * anyone's referral variable, keeping its own query and fragment.
	 *
	 * @return void
	 */
	public function test_a_page_is_saved_on_the_site_origin_without_the_referral_variable(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$home         = wp_parse_url( home_url( '/' ) );
		$host         = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$manager      = flyaffiliate()->referral_link;

		$this->assertSame(
			home_url( '/shop/?utm_source=news#reviews' ),
			$manager->normalize_url( 'https://www.' . $host . '/shop/?utm_source=news&affiliate=99#reviews' )
		);
		$this->assertSame( home_url( '/cart/' ), $manager->normalize_url( '  /cart/ ' ) );
		$this->assertSame( home_url( '/sale/' ), $manager->normalize_url( $host . '/sale/' ) );

		$link = $manager->create( $affiliate_id, '/shop/?affiliate=' . $affiliate_id );

		$this->assertSame( home_url( '/shop/' ), $link->get( 'url' ) );
		$this->assertSame( md5( home_url( '/shop/' ) ), $link->get( 'url_hash' ) );
	}

	/**
	 * Pages of other sites, other schemes and nothing at all are refused.
	 *
	 * @return void
	 */
	public function test_anything_but_a_page_of_this_site_is_refused(): void {
		$affiliate_id = $this->factory()->affiliate->create();

		$refused = [
			''                             => 'flyaffiliate_referral_link_empty',
			'https://shop.example/product' => 'flyaffiliate_referral_link_foreign',
			'//shop.example/product'       => 'flyaffiliate_referral_link_foreign',
			'javascript:alert(1)'          => 'flyaffiliate_referral_link_foreign',
			'ftp://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/' => 'flyaffiliate_referral_link_foreign',
		];

		foreach ( $refused as $url => $code ) {
			$result = flyaffiliate()->referral_link->create( $affiliate_id, $url );

			$this->assertWPError( $result, $url );
			$this->assertSame( $code, $result->get_error_code(), $url );
		}

		$this->assertSame( 0, flyaffiliate()->referral_link->count() );
	}

	/**
	 * On a site in a directory, a page outside that directory is not on the site.
	 *
	 * @return void
	 */
	public function test_a_page_outside_the_site_directory_is_refused(): void {
		$affiliate_id = $this->factory()->affiliate->create();

		add_filter(
			'home_url',
			static function ( string $url, string $path ): string {
				return 'http://example.org/wp' . $path;
			},
			10,
			2
		);

		$this->assertWPError( flyaffiliate()->referral_link->create( $affiliate_id, 'http://example.org/blog/' ) );
		$this->assertSame( 'http://example.org/wp/shop/', flyaffiliate()->referral_link->create( $affiliate_id, '/wp/shop/' )->get( 'url' ) );
	}

	/**
	 * Saving a page twice keeps one link; another affiliate gets their own.
	 *
	 * @return void
	 */
	public function test_saving_a_page_twice_returns_the_first_link(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$other_id     = $this->factory()->affiliate->create();

		$first  = flyaffiliate()->referral_link->create( $affiliate_id, home_url( '/shop/' ) );
		$second = flyaffiliate()->referral_link->create( $affiliate_id, '/shop/?affiliate=' . $affiliate_id );
		$theirs = flyaffiliate()->referral_link->create( $other_id, home_url( '/shop/' ) );

		$this->assertSame( $first->get_id(), $second->get_id() );
		$this->assertNotSame( $first->get_id(), $theirs->get_id() );
		$this->assertSame( 1, flyaffiliate()->referral_link->count( [ 'where' => [ 'affiliate_id' => $affiliate_id ] ] ) );
		$this->assertSame( $first->get_id(), flyaffiliate()->referral_link->find_by_url( $affiliate_id, '/shop/' )->get_id() );
	}

	/**
	 * A click on the shared link is a visit the link counts, and so is any
	 * other visit of the affiliate's to that page; nobody else's is.
	 *
	 * @return void
	 */
	public function test_visits_to_the_page_are_counted_on_the_link(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$other_id     = $this->factory()->affiliate->create();
		$link         = flyaffiliate()->referral_link->create( $affiliate_id, '/shop/?utm_campaign=spring' );
		$referral_url = Affiliate::find( $affiliate_id )->get_referral_url( $link->get( 'url' ) );
		$parts        = wp_parse_url( $referral_url );

		// Follow the link the dashboard hands out.
		$_GET['affiliate']      = (string) $affiliate_id;
		$_SERVER['REQUEST_URI'] = $parts['path'] . '?' . $parts['query'];
		( new Tracker() )->track();

		$landing = flyaffiliate()->referral_link->get_landing_url( $link );

		$this->factory()->visit->create(
			[
				'affiliate_id' => $affiliate_id,
				'url'          => $landing,
				'converted'    => 1,
				'created_at'   => '2030-01-02 03:04:05',
			]
		);
		$this->factory()->visit->create( [ 'affiliate_id' => $other_id, 'url' => $landing ] );
		$this->factory()->visit->create( [ 'affiliate_id' => $affiliate_id, 'url' => home_url( '/cart/' ) ] );

		$stats = flyaffiliate()->referral_link->get_stats( [ $link ] );

		$this->assertSame(
			[
				'visits'        => 2,
				'conversions'   => 1,
				'last_visit_at' => '2030-01-02 03:04:05',
			],
			$stats[ $link->get_id() ]
		);
	}

	/**
	 * A link nobody followed reports zeros, not a missing row.
	 *
	 * @return void
	 */
	public function test_a_link_without_visits_reports_zeros(): void {
		$link = flyaffiliate()->referral_link->get( $this->factory()->referral_link->create() );

		$this->assertSame(
			[
				'visits'        => 0,
				'conversions'   => 0,
				'last_visit_at' => null,
			],
			flyaffiliate()->referral_link->get_stats( [ $link ] )[ $link->get_id() ]
		);
		$this->assertSame( [], flyaffiliate()->referral_link->get_stats( [] ) );
	}

	/**
	 * Removing a link takes it off the list and nothing else: its visits stay,
	 * and saving the page again brings its figures back.
	 *
	 * @return void
	 */
	public function test_removing_a_link_keeps_its_visits(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$link         = flyaffiliate()->referral_link->create( $affiliate_id, '/shop/' );

		$this->factory()->visit->create( [ 'affiliate_id' => $affiliate_id, 'url' => flyaffiliate()->referral_link->get_landing_url( $link ) ] );

		$fired = did_action( 'flyaffiliate_referral_link_deleted' );

		$this->assertTrue( flyaffiliate()->referral_link->delete( $link->get_id() ) );
		$this->assertSame( 0, flyaffiliate()->referral_link->count() );
		$this->assertSame( 1, flyaffiliate()->tracking->count( [ 'where' => [ 'affiliate_id' => $affiliate_id ] ] ) );
		$this->assertSame( $fired + 1, did_action( 'flyaffiliate_referral_link_deleted' ) );

		$again = flyaffiliate()->referral_link->create( $affiliate_id, '/shop/' );

		$this->assertSame( 1, flyaffiliate()->referral_link->get_stats( [ $again ] )[ $again->get_id() ]['visits'] );
		$this->assertFalse( flyaffiliate()->referral_link->delete( 999999 ) );
	}

	/**
	 * Deleting an affiliate removes their links and nobody else's.
	 *
	 * @return void
	 */
	public function test_deleting_an_affiliate_removes_their_links(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$other_id     = $this->factory()->affiliate->create();

		$this->factory()->referral_link->create_many( 2, [ 'affiliate_id' => $affiliate_id ] );
		$this->factory()->referral_link->create( [ 'affiliate_id' => $other_id ] );

		flyaffiliate()->affiliate->delete( $affiliate_id );

		$this->assertSame( 0, flyaffiliate()->referral_link->count( [ 'where' => [ 'affiliate_id' => $affiliate_id ] ] ) );
		$this->assertSame( 1, flyaffiliate()->referral_link->count( [ 'where' => [ 'affiliate_id' => $other_id ] ] ) );
	}
}
