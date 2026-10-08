<?php
/**
 * A vendor's rate and lock, resolved against the marketplace's.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations\Dokan;

use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Integrations\Dokan\VendorProgram;

/**
 * The vendor's values win only when the marketplace allows them and the vendor switched them on.
 *
 * @group dokan
 */
class VendorProgramTest extends DokanTestCase {

	/**
	 * A vendor who saved nothing gets the marketplace's terms.
	 *
	 * @return void
	 */
	public function test_a_vendor_without_settings_gets_the_marketplace_terms(): void {
		$vendor = $this->vendor();

		$this->assertFalse( $this->program()->has_override( $vendor ) );
		$this->assertNull( $this->program()->get_rate( $vendor ), 'no vendor rate anywhere: FlyAffiliate’s default applies' );
		$this->assertSame( 14, $this->program()->get_hold_days( $vendor, 30 ) );

		$this->marketplace( [ Settings::DEFAULT_RATE => '12.5' ] );

		$this->assertSame( 12.5, $this->program()->get_rate( $vendor ) );
		$this->assertSame( 12.5, $this->program()->get_terms( $vendor )['rate'] );
	}

	/**
	 * A vendor using their own settings gets them, clamped to the maximum.
	 *
	 * @return void
	 */
	public function test_a_vendor_using_their_own_settings_gets_them(): void {
		$vendor = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);

		$this->assertTrue( $this->program()->has_override( $vendor ) );
		$this->assertSame( 25.0, $this->program()->get_rate( $vendor ) );
		$this->assertSame( 5, $this->program()->get_hold_days( $vendor, 30 ) );

		// The maximum dropped after the vendor saved: the stored rate is clamped on read.
		$this->marketplace( [ Settings::MAX_RATE => '20' ] );

		$this->assertSame( 20.0, $this->program()->get_rate( $vendor ) );
	}

	/**
	 * Saved settings are ignored, not deleted, while the vendor's switch or the marketplace's is off.
	 *
	 * @return void
	 */
	public function test_saved_settings_are_ignored_while_switched_off(): void {
		$stored = [
			'override'  => 'off',
			'rate'      => 25,
			'lock_days' => 5,
		];
		$vendor = $this->vendor( $stored );

		$this->assertFalse( $this->program()->has_override( $vendor ) );
		$this->assertSame( 14, $this->program()->get_hold_days( $vendor, 30 ) );

		update_user_meta( $vendor, VendorProgram::META, array_merge( $stored, [ 'override' => 'on' ] ) );
		$this->marketplace( [ Settings::VENDOR_OVERRIDE => 'off' ] );

		$this->assertFalse( $this->program()->has_override( $vendor ) );
		$this->assertNull( $this->program()->get_rate( $vendor ) );
		$this->assertSame( 25.0, (float) get_user_meta( $vendor, VendorProgram::META, true )['rate'], 'what the vendor saved is still there' );
	}

	/**
	 * With vendor programs off nothing about a vendor applies.
	 *
	 * @return void
	 */
	public function test_nothing_applies_with_vendor_programs_off(): void {
		$vendor = $this->vendor(
			[
				'override'  => 'on',
				'rate'      => 25,
				'lock_days' => 5,
			]
		);

		$this->marketplace( [ Settings::PROGRAMS => 'off' ] );

		$this->assertNull( $this->program()->get_rate( $vendor ) );
		$this->assertSame( 30, $this->program()->get_hold_days( $vendor, 30 ), 'FlyAffiliate’s own hold period' );
		$this->assertSame( 30, flyaffiliate()->commission->get_hold_days( $vendor ) );
	}

	/**
	 * Saving validates the rate against the maximum and the lock against its range.
	 *
	 * @return void
	 */
	public function test_save_validates_and_stores(): void {
		$vendor = $this->vendor();

		$this->assertWPError( $this->program()->save( $vendor, [ 'rate' => 41 ] ), 'above the maximum of 40' );
		$this->assertWPError( $this->program()->save( $vendor, [ 'rate' => 0 ] ) );
		$this->assertWPError( $this->program()->save( $vendor, [ 'lock_days' => 366 ] ) );
		$this->assertWPError( $this->program()->save( $vendor, [ 'lock_days' => -1 ] ) );
		$this->assertSame( '', get_user_meta( $vendor, VendorProgram::META, true ), 'a refused save stores nothing' );

		$saved = $this->program()->save(
			$vendor,
			[
				'override'  => 'on',
				'rate'      => '17.5',
				'lock_days' => '0',
			]
		);

		$this->assertNotWPError( $saved );
		$this->assertSame( 17.5, $this->program()->get_rate( $vendor ) );
		$this->assertSame( 0, $this->program()->get_hold_days( $vendor, 30 ), 'a lock of zero is a lock, not a missing value' );

		$this->marketplace( [ Settings::VENDOR_OVERRIDE => 'off' ] );

		$this->assertWPError( $this->program()->save( $vendor, [ 'rate' => 5 ] ), 'the marketplace decides for every store' );
	}
}
