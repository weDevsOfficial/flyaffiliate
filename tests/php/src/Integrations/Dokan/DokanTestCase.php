<?php
/**
 * Base for the Dokan integration's tests.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Integrations\Dokan;

use FlyAffiliate\Integrations\Dokan\Settings;
use FlyAffiliate\Integrations\Dokan\VendorProgram;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * A marketplace with vendor programs on, and the helpers to change it.
 */
abstract class DokanTestCase extends FlyAffiliateTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();
		$this->skip_without_dokan();

		flyaffiliate_update_option( 'default_rate', 10 );
		flyaffiliate_update_option( 'max_rate', 50 );
		flyaffiliate_update_option( 'hold_days', 30 );

		$this->marketplace(
			[
				Settings::PROGRAMS        => 'on',
				Settings::DEFAULT_RATE    => '0',
				Settings::VENDOR_OVERRIDE => 'on',
				Settings::MAX_RATE        => '40',
				Settings::LOCK_DAYS       => '14',
			]
		);
	}

	/**
	 * Change the marketplace's vendor-program settings.
	 *
	 * @param array<string, mixed> $values Setting key => value.
	 *
	 * @return void
	 */
	protected function marketplace( array $values ): void {
		$stored = get_option( Settings::SECTION, [] );

		update_option( Settings::SECTION, array_merge( is_array( $stored ) ? $stored : [], $values ) );
	}

	/**
	 * A vendor who may sell.
	 *
	 * @param array<string, mixed> $settings The vendor's own affiliate settings, when they have any.
	 *
	 * @return int The vendor's user id.
	 */
	protected function vendor( array $settings = [] ): int {
		$vendor_id = $this->factory()->user->create( [ 'role' => 'seller' ] );

		update_user_meta( $vendor_id, 'dokan_enable_selling', 'yes' );

		if ( [] !== $settings ) {
			update_user_meta( $vendor_id, VendorProgram::META, $settings );
		}

		return $vendor_id;
	}

	/**
	 * The vendor programs.
	 *
	 * @return VendorProgram
	 */
	protected function program(): VendorProgram {
		return flyaffiliate()->dokan;
	}
}
