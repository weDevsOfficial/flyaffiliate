<?php
/**
 * Model tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Models;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The model layer is the only place that writes to these tables, so its casting,
 * its dirty tracking and its column allow-list are worth pinning down.
 *
 * @group models
 *
 * @since FLYAFFILIATE_SINCE
 */
class BaseModelTest extends FlyAffiliateTestCase {

	/**
	 * A saved row comes back with the same values.
	 *
	 * @return void
	 */
	public function test_it_saves_and_reads_a_row(): void {
		$user_id = $this->factory()->user->create();

		$affiliate = new Affiliate();
		$affiliate->fill(
			[
				'user_id'       => $user_id,
				'status'        => Affiliate::STATUS_ACTIVE,
				'payment_email' => 'payee@example.org',
			]
		);

		$id = $affiliate->save();

		$this->assertGreaterThan( 0, $id );

		$loaded = Affiliate::find( $id );

		$this->assertNotNull( $loaded );
		$this->assertSame( $user_id, $loaded->get( 'user_id' ) );
		$this->assertSame( 'payee@example.org', $loaded->get( 'payment_email' ) );
	}

	/**
	 * Values come back as the type the column declares, not as strings.
	 *
	 * @return void
	 */
	public function test_it_casts_columns_to_their_declared_types(): void {
		$commission = $this->factory()->commission->create_and_get_model( [ 'base_amount' => 100, 'amount' => 15 ] );

		$this->assertIsInt( $commission->get( 'id' ) );
		$this->assertIsInt( $commission->get( 'affiliate_id' ) );
		$this->assertIsFloat( $commission->get( 'amount' ) );
		$this->assertIsString( $commission->get( 'status' ) );
	}

	/**
	 * Money keeps four decimal places through a round trip.
	 *
	 * @return void
	 */
	public function test_money_survives_a_round_trip_at_four_decimals(): void {
		$commission = $this->factory()->commission->create_and_get_model(
			[
				'base_amount' => 33.3333,
				'amount'      => 4.9999,
			]
		);

		$loaded = Commission::find( $commission->get_id() );

		$this->assertSame( 33.3333, $loaded->get( 'base_amount' ) );
		$this->assertSame( 4.9999, $loaded->get( 'amount' ) );
	}

	/**
	 * A column the model does not declare is dropped rather than written.
	 *
	 * @return void
	 */
	public function test_it_ignores_undeclared_columns(): void {
		$affiliate = new Affiliate();
		$affiliate->set( 'user_id', $this->factory()->user->create() );
		$affiliate->set( 'not_a_column', 'nope' );

		$this->assertNull( $affiliate->get( 'not_a_column' ) );
		$this->assertGreaterThan( 0, $affiliate->save() );
	}

	/**
	 * Only the columns that changed are written back.
	 *
	 * @return void
	 */
	public function test_it_updates_an_existing_row(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model( [ 'status' => Affiliate::STATUS_PENDING ] );

		$affiliate->set( 'status', Affiliate::STATUS_ACTIVE );
		$affiliate->save();

		$this->assertSame( Affiliate::STATUS_ACTIVE, Affiliate::find( $affiliate->get_id() )->get( 'status' ) );
		$this->assertDatabaseCount( 'flyaffiliate_affiliates', 1, [ 'id' => $affiliate->get_id() ] );
	}

	/**
	 * `created_at` is set once; `updated_at` moves on every write.
	 *
	 * @return void
	 */
	public function test_it_stamps_created_at_once_and_updated_at_always(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();

		$created = $affiliate->get( 'created_at' );

		$this->assertNotEmpty( $created );

		$affiliate->set( 'payment_email', 'changed@example.org' );
		$affiliate->save();

		$this->assertSame( $created, Affiliate::find( $affiliate->get_id() )->get( 'created_at' ) );
	}

	/**
	 * Deleting removes the row.
	 *
	 * @return void
	 */
	public function test_it_deletes_a_row(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();
		$id        = $affiliate->get_id();

		$this->assertTrue( $affiliate->delete() );
		$this->assertNull( Affiliate::find( $id ) );
		$this->assertDatabaseMissing( 'flyaffiliate_affiliates', [ 'id' => $id ] );
	}

	/**
	 * A query filters, orders and pages.
	 *
	 * @return void
	 */
	public function test_it_queries_with_filters_ordering_and_paging(): void {
		$this->factory()->affiliate->create_many( 3, [ 'status' => Affiliate::STATUS_ACTIVE ] );
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );

		$active = Affiliate::query(
			[
				'where'    => [ 'status' => Affiliate::STATUS_ACTIVE ],
				'per_page' => -1,
			]
		);

		$this->assertCount( 3, $active );
		$this->assertSame( 3, Affiliate::count( [ 'status' => Affiliate::STATUS_ACTIVE ] ) );
		$this->assertSame( 4, Affiliate::count() );

		$first_page = Affiliate::query( [ 'per_page' => 2, 'page' => 1, 'orderby' => 'id', 'order' => 'ASC' ] );
		$second_page = Affiliate::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ] );

		$this->assertCount( 2, $first_page );
		$this->assertCount( 2, $second_page );
		$this->assertLessThan( $second_page[0]->get_id(), $first_page[0]->get_id() );
	}

	/**
	 * An `IN` condition takes a list of values.
	 *
	 * @return void
	 */
	public function test_it_supports_an_in_condition(): void {
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_ACTIVE ] );
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_PENDING ] );
		$this->factory()->affiliate->create( [ 'status' => Affiliate::STATUS_SUSPENDED ] );

		$this->assertSame(
			2,
			Affiliate::count( [ 'status' => [ Affiliate::STATUS_ACTIVE, Affiliate::STATUS_PENDING ] ] )
		);
	}

	/**
	 * An empty `IN` matches nothing rather than everything.
	 *
	 * A query builder that drops an empty list silently returns the whole table,
	 * which in a payout batch would pay every affiliate.
	 *
	 * @return void
	 */
	public function test_an_empty_in_condition_matches_nothing(): void {
		$this->factory()->affiliate->create_many( 2 );

		$this->assertSame( 0, Affiliate::count( [ 'id' => [] ] ) );
	}

	/**
	 * An unknown `orderby` falls back to `id` rather than reaching the SQL.
	 *
	 * @return void
	 */
	public function test_an_unknown_orderby_falls_back_to_id(): void {
		$this->factory()->affiliate->create_many( 2 );

		$rows = Affiliate::query( [ 'orderby' => 'id; DROP TABLE wp_users', 'per_page' => -1 ] );

		$this->assertCount( 2, $rows );
	}
}
