<?php
/**
 * Affiliates REST controller tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\REST;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The admin REST surface, end to end: routes, permissions, schema, pagination
 * and links.
 *
 * @group rest
 *
 * @since FLYAFFILIATE_SINCE
 */
class AffiliatesControllerTest extends FlyAffiliateTestCase {

	/**
	 * The routes are registered.
	 *
	 * @return void
	 */
	public function test_the_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/flyaffiliate/v1/affiliates', $routes );
		$this->assertArrayHasKey( '/flyaffiliate/v1/affiliates/(?P<id>[\d]+)', $routes );
	}

	/**
	 * Every route this plugin registers has a real permission callback.
	 * `__return_true` is never acceptable here, and a route registered without
	 * one is a security bug.
	 *
	 * `/flyaffiliate/v1` itself is skipped: that is the namespace index, which
	 * WordPress core registers for every namespace and serves publicly.
	 *
	 * @return void
	 */
	public function test_every_route_has_a_permission_callback(): void {
		$namespace = '/' . $this->namespace;

		foreach ( $this->server->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, $namespace ) || $route === $namespace ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, sprintf( '%s has no permission callback.', $route ) );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], sprintf( '%s is open to everyone.', $route ) );
			}
		}
	}

	/**
	 * A logged-out request is refused.
	 *
	 * @return void
	 */
	public function test_it_refuses_an_anonymous_request(): void {
		$this->acting_as( 0 );

		$this->assertSame( 401, $this->get_request( '/affiliates' )->get_status() );
	}

	/**
	 * A customer is refused.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_user_without_the_capability(): void {
		$this->acting_as( $this->customer_id );

		$this->assertSame( 403, $this->get_request( '/affiliates' )->get_status() );
	}

	/**
	 * A shop manager is allowed, because they are who processes these orders.
	 *
	 * @return void
	 */
	public function test_it_allows_a_shop_manager(): void {
		$this->acting_as( $this->factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertSame( 200, $this->get_request( '/affiliates' )->get_status() );
	}

	/**
	 * Listing returns the affiliates with the pagination headers.
	 *
	 * @return void
	 */
	public function test_it_lists_affiliates_with_pagination_headers(): void {
		$this->acting_as( $this->admin_id );
		$this->factory()->affiliate->create_many( 3 );

		$response = $this->get_request( '/affiliates', [ 'per_page' => 2 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * The list filters by status.
	 *
	 * @return void
	 */
	public function test_it_filters_by_status(): void {
		$this->acting_as( $this->admin_id );
		$this->factory()->affiliate->create_many( 2, [ 'status' => Affiliate::STATUS_ACTIVE ] );
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );

		$response = $this->get_request( '/affiliates', [ 'status' => Affiliate::STATUS_PENDING ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( Affiliate::STATUS_PENDING, $response->get_data()[0]['status'] );
	}

	/**
	 * An unknown status is rejected by the schema rather than ignored.
	 *
	 * @return void
	 */
	public function test_it_rejects_an_unknown_status_filter(): void {
		$this->acting_as( $this->admin_id );

		$this->assertSame( 400, $this->get_request( '/affiliates', [ 'status' => 'nonsense' ] )->get_status() );
	}

	/**
	 * A single affiliate comes back with its fields and its links.
	 *
	 * @return void
	 */
	public function test_it_returns_a_single_affiliate(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create_and_get_model( [ 'payment_email' => 'payee@example.org' ] );

		$response = $this->get_request( '/affiliates/' . $affiliate->get_id() );

		$this->assertSame( 200, $response->get_status() );

		$this->assertArrayHasKeys(
			[ 'id', 'user_id', 'name', 'status', 'payment_email', 'promo_method', 'referral_url', 'created_at', 'updated_at' ],
			$response->get_data()
		);

		$this->assertArraySubset(
			[
				'id'            => $affiliate->get_id(),
				'payment_email' => 'payee@example.org',
			],
			$response->get_data()
		);

		$this->assertArrayHasKey( 'self', $response->get_links() );
		$this->assertArrayHasKey( 'collection', $response->get_links() );
	}

	/**
	 * A missing affiliate is a 404.
	 *
	 * @return void
	 */
	public function test_it_returns_404_for_an_unknown_affiliate(): void {
		$this->acting_as( $this->admin_id );

		$this->assertSame( 404, $this->get_request( '/affiliates/999999' )->get_status() );
	}

	/**
	 * Creating an affiliate returns 201 and a Location header.
	 *
	 * @return void
	 */
	public function test_it_creates_an_affiliate(): void {
		$this->acting_as( $this->admin_id );

		$user_id = $this->factory()->user->create();

		$response = $this->post_request(
			'/affiliates',
			[
				'user_id'       => $user_id,
				'status'        => Affiliate::STATUS_ACTIVE,
				'payment_email' => 'new@example.org',
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayHasKey( 'Location', $response->get_headers() );
		$this->assertSame( $user_id, $response->get_data()['user_id'] );
		$this->assertDatabaseHas( 'flyaffiliate_affiliates', [ 'user_id' => $user_id ] );
	}

	/**
	 * One WordPress user is one affiliate: a second attempt is a conflict.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_second_affiliate_for_the_same_user(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$response = $this->post_request( '/affiliates', [ 'user_id' => $affiliate->get( 'user_id' ) ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertDatabaseCount( 'flyaffiliate_affiliates', 1, [ 'user_id' => $affiliate->get( 'user_id' ) ] );
	}

	/**
	 * Creating for a user that does not exist is a 400.
	 *
	 * @return void
	 */
	public function test_it_refuses_an_affiliate_for_a_missing_user(): void {
		$this->acting_as( $this->admin_id );

		$this->assertSame( 400, $this->post_request( '/affiliates', [ 'user_id' => 999999 ] )->get_status() );
	}

	/**
	 * Updating changes the fields it was given.
	 *
	 * @return void
	 */
	public function test_it_updates_an_affiliate(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create_and_get_model( [ 'status' => Affiliate::STATUS_PENDING ] );

		$response = $this->put_request(
			'/affiliates/' . $affiliate->get_id(),
			[
				'status'        => Affiliate::STATUS_ACTIVE,
				'payment_email' => 'updated@example.org',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Affiliate::STATUS_ACTIVE, $response->get_data()['status'] );
		$this->assertSame( 'updated@example.org', $response->get_data()['payment_email'] );
	}

	/**
	 * The user an affiliate belongs to cannot be changed through the API.
	 *
	 * Moving an affiliate to another user would move their commissions with it.
	 *
	 * @return void
	 */
	public function test_it_does_not_move_an_affiliate_to_another_user(): void {
		$this->acting_as( $this->admin_id );

		$affiliate       = $this->factory()->affiliate->create_and_get_model();
		$original_user   = (int) $affiliate->get( 'user_id' );
		$other_user      = $this->factory()->user->create();

		$this->put_request( '/affiliates/' . $affiliate->get_id(), [ 'user_id' => $other_user ] );

		$this->assertSame( $original_user, (int) Affiliate::find( $affiliate->get_id() )->get( 'user_id' ) );
	}

	/**
	 * Deleting removes the row and reports what was deleted.
	 *
	 * @return void
	 */
	public function test_it_deletes_an_affiliate(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$response = $this->delete_request( '/affiliates/' . $affiliate->get_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertDatabaseMissing( 'flyaffiliate_affiliates', [ 'id' => $affiliate->get_id() ] );
	}

	/**
	 * The item schema describes every field the controller returns.
	 *
	 * @return void
	 */
	public function test_the_schema_covers_every_returned_field(): void {
		$this->acting_as( $this->admin_id );

		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$request  = new \WP_REST_Request( 'OPTIONS', '/flyaffiliate/v1/affiliates' );
		$schema   = $this->server->dispatch( $request )->get_data()['schema'];
		$returned = $this->get_request( '/affiliates/' . $affiliate->get_id() )->get_data();

		foreach ( array_keys( $returned ) as $field ) {
			$this->assertArrayHasKey( $field, $schema['properties'], sprintf( '`%s` is returned but not in the schema.', $field ) );
		}
	}

	/**
	 * The payment email is optional: empty falls back to the account email, and a bad address is refused.
	 *
	 * @return void
	 */
	public function test_the_payment_email_may_be_empty(): void {
		$this->acting_as( $this->admin_id );

		$user    = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$account = get_userdata( $user )->user_email;

		// The add form leaves the field empty when the account email will do.
		$response = $this->post_request(
			'/affiliates',
			[
				'user_id'       => $user,
				'status'        => Affiliate::STATUS_PENDING,
				'payment_email' => '',
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $account, $response->get_data()['payment_email'] );

		$id = $response->get_data()['id'];

		// An address still has to be one.
		$this->assertSame(
			400,
			$this->put_request( "/affiliates/{$id}", [ 'payment_email' => 'not-an-email' ] )->get_status()
		);

		$response = $this->put_request( "/affiliates/{$id}", [ 'payment_email' => 'payee@example.com' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'payee@example.com', $response->get_data()['payment_email'] );

		// Clearing it sends payouts back to the account email.
		$response = $this->put_request( "/affiliates/{$id}", [ 'payment_email' => '' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $account, $response->get_data()['payment_email'] );
	}

	/**
	 * The website lives on the user, and the welcome email is sent only when asked.
	 *
	 * @return void
	 */
	public function test_the_website_and_the_welcome_email(): void {
		$this->acting_as( $this->admin_id );

		$sent = [];
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( &$sent ) {
				$sent[] = $atts;

				return true;
			},
			10,
			2
		);

		$quiet = $this->post_request( '/affiliates', [ 'user_id' => $this->factory()->user->create(), 'status' => Affiliate::STATUS_ACTIVE ] );

		$this->assertSame( 201, $quiet->get_status() );
		$this->assertSame( '', $quiet->get_data()['website'] );
		$this->assertCount( 0, $sent, 'no welcome email unless the form asks for one' );

		$user     = $this->factory()->user->create();
		$response = $this->post_request(
			'/affiliates',
			[
				'user_id'            => $user,
				'status'             => Affiliate::STATUS_ACTIVE,
				'website'            => 'https://example.org/blog',
				'send_welcome_email' => true,
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'https://example.org/blog', $response->get_data()['website'] );
		$this->assertSame( 'https://example.org/blog', get_userdata( $user )->user_url );
		$this->assertSame( get_userdata( $user )->user_email, $response->get_data()['email'] );
		$this->assertCount( 1, $sent );
		$this->assertSame( get_userdata( $user )->user_email, $sent[0]['to'] );
		$this->assertStringContainsString( $response->get_data()['referral_url'], $sent[0]['message'] );

		$id      = $response->get_data()['id'];
		$updated = $this->put_request( "/affiliates/{$id}", [ 'website' => 'https://example.org/new' ] );

		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'https://example.org/new', get_userdata( $user )->user_url );
	}
}
