<?php
/**
 * Tests for the referral link routes.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\REST;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * `referral-links` for the admin, `me/referral-links` for the affiliate.
 *
 * @group rest
 * @group referral-links
 *
 * @since FLYAFFILIATE_SINCE
 */
class ReferralLinksControllerTest extends FlyAffiliateTestCase {

	/**
	 * The admin list is admin-only, filters by affiliate and pages.
	 *
	 * @return void
	 */
	public function test_the_admin_lists_an_affiliates_links_page_by_page(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$other_id     = $this->factory()->affiliate->create();

		$this->factory()->referral_link->create_many( 3, [ 'affiliate_id' => $affiliate_id ] );
		$this->factory()->referral_link->create( [ 'affiliate_id' => $other_id ] );

		$this->acting_as( $this->customer_id );
		$this->assertSame( 403, $this->get_request( '/referral-links' )->get_status() );

		$this->acting_as( $this->admin_id );

		$response = $this->get_request(
			'/referral-links',
			[
				'affiliate_id' => $affiliate_id,
				'per_page'     => 2,
				'page'         => 2,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( $affiliate_id, $response->get_data()[0]['affiliate_id'] );

		$this->assertSame( '4', $this->get_request( '/referral-links' )->get_headers()['X-WP-Total'] );
	}

	/**
	 * A row carries the page, the link to share and the page's visits.
	 *
	 * @return void
	 */
	public function test_a_link_carries_its_referral_url_and_visits(): void {
		$affiliate_id = $this->factory()->affiliate->create();
		$link_id      = $this->factory()->referral_link->create(
			[
				'affiliate_id' => $affiliate_id,
				'url'          => '/shop/?utm_source=news',
			]
		);
		$link         = flyaffiliate()->referral_link->get( $link_id );

		$this->factory()->visit->create_many(
			2,
			[
				'affiliate_id' => $affiliate_id,
				'url'          => flyaffiliate()->referral_link->get_landing_url( $link ),
			]
		);

		$this->acting_as( $this->admin_id );

		$data = $this->get_request( '/referral-links/' . $link_id )->get_data();

		$this->assertSame( home_url( '/shop/?utm_source=news' ), $data['url'] );
		$this->assertSame( Affiliate::find( $affiliate_id )->get_referral_url( $data['url'] ), $data['referral_url'] );
		$this->assertStringContainsString( 'affiliate=' . $affiliate_id, $data['referral_url'] );
		$this->assertSame( 2, $data['visits'] );
		$this->assertSame( 0, $data['conversions'] );
		$this->assertNotNull( $data['last_visit_at'] );

		$this->assertSame( 404, $this->get_request( '/referral-links/999999' )->get_status() );
	}

	/**
	 * Only an active affiliate saves links; a guest and a pending affiliate are refused.
	 *
	 * @return void
	 */
	public function test_only_an_active_affiliate_saves_links(): void {
		$this->assertSame( 401, $this->post_request( '/me/referral-links', [ 'url' => '/shop/' ] )->get_status() );

		$pending = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->factory()->affiliate->create(
			[
				'user_id' => $pending,
				'status'  => Affiliate::STATUS_PENDING,
			]
		);
		$this->acting_as( $pending );

		$this->assertSame( 403, $this->post_request( '/me/referral-links', [ 'url' => '/shop/' ] )->get_status() );
		$this->assertSame( 0, flyaffiliate()->referral_link->count() );
	}

	/**
	 * An affiliate saves a page once, lists only their own links, and
	 * removes only their own.
	 *
	 * @return void
	 */
	public function test_an_affiliate_saves_lists_and_removes_their_own_links(): void {
		$user     = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$mine     = $this->factory()->affiliate->create(
			[
				'user_id' => $user,
				'status'  => Affiliate::STATUS_ACTIVE,
			]
		);
		$other    = $this->factory()->affiliate->create();
		$theirs   = $this->factory()->referral_link->create( [ 'affiliate_id' => $other ] );
		$existing = $this->factory()->referral_link->create(
			[
				'affiliate_id' => $mine,
				'created_at'   => '2020-01-01 00:00:00',
			]
		);

		$this->acting_as( $user );

		$created = $this->post_request( '/me/referral-links', [ 'url' => home_url( '/shop/' ) ] );

		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( $mine, $created->get_data()['affiliate_id'] );
		$this->assertStringContainsString( 'affiliate=' . $mine, $created->get_data()['referral_url'] );

		$again = $this->post_request( '/me/referral-links', [ 'url' => '/shop/' ] );

		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $created->get_data()['id'], $again->get_data()['id'] );

		$foreign = $this->post_request( '/me/referral-links', [ 'url' => 'https://shop.example/' ] );

		$this->assertSame( 400, $foreign->get_status() );
		$this->assertSame( 'flyaffiliate_referral_link_foreign', $foreign->get_data()['code'] );

		// Newest first, someone else's never, and an affiliate_id is not honoured.
		$list = $this->get_request(
			'/me/referral-links',
			[
				'affiliate_id' => $other,
				'per_page'     => 1,
			]
		);

		$this->assertSame( '2', $list->get_headers()['X-WP-Total'] );
		$this->assertSame( [ $created->get_data()['id'] ], wp_list_pluck( $list->get_data(), 'id' ) );

		$this->assertSame( 404, $this->delete_request( '/me/referral-links/' . $theirs )->get_status() );
		$this->assertNotNull( flyaffiliate()->referral_link->get( $theirs ) );

		$removed = $this->delete_request( '/me/referral-links/' . $existing );

		$this->assertSame( 200, $removed->get_status() );
		$this->assertTrue( $removed->get_data()['deleted'] );
		$this->assertSame( $existing, $removed->get_data()['previous']['id'] );
		$this->assertSame( '1', $this->get_request( '/me/referral-links' )->get_headers()['X-WP-Total'] );
	}
}
