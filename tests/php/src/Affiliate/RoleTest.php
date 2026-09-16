<?php
/**
 * Tests for the affiliate role.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Affiliate;

use FlyAffiliate\Affiliate\Role;
use FlyAffiliate\Install\Installer;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * The role follows the affiliates table, and a profile pick creates an affiliate.
 *
 * @group affiliate
 *
 * @since FLYAFFILIATE_SINCE
 */
class RoleTest extends FlyAffiliateTestCase {

	/**
	 * The roles a user holds right now.
	 *
	 * @param int $user_id The user.
	 *
	 * @return string[]
	 */
	private function roles( int $user_id ): array {
		return (array) get_user_by( 'id', $user_id )->roles;
	}

	/**
	 * Installing registers the role with nothing but `read`.
	 *
	 * @return void
	 */
	public function test_the_role_is_registered_with_read_only(): void {
		$role = get_role( Role::ROLE );

		$this->assertNotNull( $role );
		$this->assertSame( [ 'read' => true ], $role->capabilities );
	}

	/**
	 * Creating an affiliate adds the role; deleting the affiliate removes it.
	 *
	 * @return void
	 */
	public function test_the_role_follows_the_affiliate_row(): void {
		$user_id      = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$affiliate_id = $this->factory()->affiliate->create( [ 'user_id' => $user_id, 'status' => Affiliate::STATUS_PENDING ] );

		$this->assertSame( [ 'subscriber', Role::ROLE ], $this->roles( $user_id ), 'added whatever the status, as in SliceWP' );

		flyaffiliate()->affiliate->delete( $affiliate_id );

		$this->assertSame( [ 'subscriber' ], $this->roles( $user_id ) );
	}

	/**
	 * A profile save replaces the roles with the dropdown's pick; the affiliate role comes back.
	 *
	 * @return void
	 */
	public function test_saving_a_profile_keeps_the_role(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->factory()->affiliate->create( [ 'user_id' => $user_id ] );

		wp_update_user( [ 'ID' => $user_id, 'role' => 'customer' ] );

		$this->assertSame( [ 'customer', Role::ROLE ], $this->roles( $user_id ) );
	}

	/**
	 * Giving a user the role on their profile, or creating them with it, makes them an active affiliate.
	 *
	 * @return void
	 */
	public function test_picking_the_role_makes_the_user_an_active_affiliate(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertFalse( flyaffiliate()->affiliate->is_affiliate( $user_id ) );

		wp_update_user( [ 'ID' => $user_id, 'role' => Role::ROLE ] );

		$affiliate = flyaffiliate()->affiliate->get_by_user( $user_id );
		$this->assertNotNull( $affiliate );
		$this->assertSame( Affiliate::STATUS_ACTIVE, $affiliate->get( 'status' ) );

		$new_user_id = $this->factory()->user->create( [ 'role' => Role::ROLE ] );
		$this->assertTrue( flyaffiliate()->affiliate->is_affiliate( $new_user_id ) );
	}

	/**
	 * An upgrade gives the role to affiliates from before it existed, and changes nothing else.
	 *
	 * @return void
	 */
	public function test_install_backfills_existing_affiliates(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->factory()->affiliate->create( [ 'user_id' => $user_id ] );
		get_user_by( 'id', $user_id )->remove_role( Role::ROLE );
		$this->assertSame( [ 'subscriber' ], $this->roles( $user_id ) );

		( new Installer() )->create_roles();
		( new Installer() )->create_roles();

		$this->assertSame( [ 'subscriber', Role::ROLE ], $this->roles( $user_id ) );
	}
}
