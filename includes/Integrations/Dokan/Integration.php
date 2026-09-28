<?php
/**
 * What the Dokan integration adds to the plugin's own screens.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Schema\SettingsSchema;
use FlyAffiliate\Contracts\Hookable;

/**
 * The integration announces itself: its settings.
 *
 * Registered by {@see \FlyAffiliate\DependencyManagement\Providers\DokanServiceProvider},
 * so it only exists while Dokan is active and an admin without a marketplace
 * never sees vendor programs.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Integration implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 */
	public function register_hooks(): void {
		add_filter( 'flyaffiliate_settings_schema', [ $this, 'add_settings' ] );
	}

	/**
	 * The Integrations → Dokan subpage.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema elements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( array $elements ): array {
		$show_with_programs = [
			'key'        => 'dokan_vendor_programs',
			'value'      => 'on',
			'comparison' => '==',
			'to_self'    => true,
			'attribute'  => 'display',
			'effect'     => 'show',
		];

		$elements[] = [
			'id'          => 'dokan',
			'type'        => 'subpage',
			'page_id'     => 'integrations',
			'title'       => __( 'Dokan', 'flyaffiliate' ),
			'description' => __( 'Vendors can run their own affiliate programs. The commission comes out of the vendor’s earning, not the marketplace’s.', 'flyaffiliate' ),
			'priority'    => 20,
		];
		$elements[] = [
			'id'          => 'dokan_settings',
			'type'        => 'section',
			'subpage_id'  => 'dokan',
			'title'       => __( 'Vendor programs', 'flyaffiliate' ),
			'description' => __( 'Whether vendors can run affiliate programs for their own stores, and how much say they have over the rate.', 'flyaffiliate' ),
		];
		$elements[] = SettingsSchema::switch_field(
			'dokan_vendor_programs',
			'dokan_settings',
			__( 'Let vendors run affiliate programs', 'flyaffiliate' ),
			__( 'Each vendor can opt their store in from their store settings.', 'flyaffiliate' ),
			true
		);
		$elements[] = array_merge(
			SettingsSchema::switch_field(
				'dokan_vendor_can_set_rate',
				'dokan_settings',
				__( 'Let vendors set their own rate', 'flyaffiliate' ),
				__( 'When off, every vendor program uses the default rate.', 'flyaffiliate' ),
				true
			),
			[ 'dependencies' => [ $show_with_programs ] ]
		);
		$elements[] = array_merge(
			SettingsSchema::percent_field(
				'dokan_vendor_max_rate',
				'dokan_settings',
				__( 'Maximum vendor rate', 'flyaffiliate' ),
				__( 'A vendor cannot set a rate above this.', 'flyaffiliate' ),
				50.0
			),
			[
				// Both switches must be on: no programs means no vendor rate to cap.
				'dependencies' => [
					$show_with_programs,
					array_merge( $show_with_programs, [ 'key' => 'dokan_vendor_can_set_rate' ] ),
				],
			]
		);

		return $elements;
	}
}
