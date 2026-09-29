<?php
/**
 * FlyAffiliate's settings inside Dokan's admin settings.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * Puts the vendor-program settings where a marketplace admin looks for them.
 *
 * Dokan has two settings screens. On the legacy one the fields are a
 * sub-section of "Selling Options", the section that holds the product and
 * vendor capability settings. On the new one they are a page of their own,
 * "FlyAffiliate". Both edit the same stored values: every new field names its
 * legacy key, and Dokan keeps the two in step.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AdminSettings implements Hookable {

	/**
	 * The page on Dokan's new settings screen.
	 *
	 * @var string
	 */
	const PAGE = 'flyaffiliate';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'dokan_settings_selling_options', [ $this, 'add_legacy_fields' ], 20 );
		add_filter( 'dokan_get_admin_settings_schema', [ $this, 'add_schema' ], 20 );
	}

	/**
	 * What each field says, shared by both screens.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array{title: string, description: string}>
	 */
	protected function get_copy(): array {
		return [
			Settings::PROGRAMS        => [
				'title'       => __( 'Vendor affiliate programs', 'flyaffiliate' ),
				'description' => __( 'Let vendors run affiliate programs for their own stores.', 'flyaffiliate' ),
			],
			Settings::DEFAULT_RATE    => [
				'title'       => __( 'Default affiliate rate', 'flyaffiliate' ),
				'description' => __( 'The rate for a vendor who has not set one. Leave it at 0 to use FlyAffiliate’s default rate.', 'flyaffiliate' ),
			],
			Settings::VENDOR_OVERRIDE => [
				'title'       => __( 'Allow vendor settings', 'flyaffiliate' ),
				'description' => __( 'Let vendors set their own rate and commission lock.', 'flyaffiliate' ),
			],
			Settings::MAX_RATE        => [
				'title'       => __( 'Maximum affiliate rate', 'flyaffiliate' ),
				'description' => __( 'The highest rate a vendor can set.', 'flyaffiliate' ),
			],
			Settings::LOCK_DAYS       => [
				'title'       => __( 'Commission lock', 'flyaffiliate' ),
				'description' => __( 'How many days a commission waits before it can be paid, so a refund lands while it can still be reversed. 0 means no wait.', 'flyaffiliate' ),
			],
		];
	}

	/**
	 * The sub-section under Selling Options on the legacy screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, array<string, mixed>> $fields The section's fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function add_legacy_fields( $fields ): array {
		$fields       = (array) $fields;
		$copy         = $this->get_copy();
		$with_program = [ Settings::PROGRAMS => [ 'equal' => 'on' ] ];
		$number       = static function ( string $key, array $extra ) use ( $copy, $with_program ): array {
			return array_merge(
				[
					'name'    => $key,
					'label'   => $copy[ $key ]['title'],
					'desc'    => $copy[ $key ]['description'],
					'type'    => 'number',
					'min'     => '0',
					'show_if' => $with_program,
				],
				$extra
			);
		};
		$switcher     = static function ( string $key, array $extra = [] ) use ( $copy ): array {
			return array_merge(
				[
					'name'    => $key,
					'label'   => $copy[ $key ]['title'],
					'desc'    => $copy[ $key ]['description'],
					'type'    => 'switcher',
					'default' => 'on',
				],
				$extra
			);
		};

		$fields['flyaffiliate_section'] = [
			'name'          => 'flyaffiliate_section',
			'label'         => __( 'FlyAffiliate', 'flyaffiliate' ),
			'type'          => 'sub_section',
			'description'   => __( 'Affiliate commissions on vendors’ products.', 'flyaffiliate' ),
			'content_class' => 'sub-section-styles',
		];

		$fields[ Settings::PROGRAMS ]        = $switcher( Settings::PROGRAMS );
		$fields[ Settings::DEFAULT_RATE ]    = $number(
			Settings::DEFAULT_RATE,
			[
				'max'     => '100',
				'step'    => '0.01',
				'default' => '0',
			]
		);
		$fields[ Settings::VENDOR_OVERRIDE ] = $switcher( Settings::VENDOR_OVERRIDE, [ 'show_if' => $with_program ] );
		$fields[ Settings::MAX_RATE ]        = $number(
			Settings::MAX_RATE,
			[
				'max'     => '100',
				'step'    => '0.01',
				'default' => '50',
				'show_if' => array_merge( $with_program, [ Settings::VENDOR_OVERRIDE => [ 'equal' => 'on' ] ] ),
			]
		);
		$fields[ Settings::LOCK_DAYS ]       = $number(
			Settings::LOCK_DAYS,
			[
				'max'     => (string) Settings::MAX_LOCK_DAYS,
				'step'    => '1',
				'default' => (string) absint( flyaffiliate_get_option( 'hold_days', 30 ) ),
			]
		);

		return $fields;
	}

	/**
	 * The FlyAffiliate page on the new screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema elements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_schema( $elements ): array {
		$elements     = (array) $elements;
		$copy         = $this->get_copy();
		$section      = 'flyaffiliate_vendor_programs_section';
		$show_when    = static function ( string $key ): array {
			return [
				'key'        => $key,
				'value'      => 'on',
				'comparison' => '===',
				'effect'     => 'show',
				'attribute'  => 'display',
				'to_self'    => true,
			];
		};
		$with_program = [ $show_when( Settings::PROGRAMS ) ];
		$field        = static function ( string $key, string $variant, array $extra ) use ( $copy, $section ): array {
			return array_merge(
				[
					'id'          => $key,
					'type'        => 'field',
					'variant'     => $variant,
					'section_id'  => $section,
					'title'       => $copy[ $key ]['title'],
					'description' => $copy[ $key ]['description'],
					'legacy_key'  => Settings::SECTION . '.' . $key,
				],
				$extra
			);
		};
		$switch       = [
			'default'       => 'on',
			'enable_state'  => [
				'label' => __( 'Enabled', 'flyaffiliate' ),
				'value' => 'on',
			],
			'disable_state' => [
				'label' => __( 'Disabled', 'flyaffiliate' ),
				'value' => 'off',
			],
		];

		return array_merge(
			$elements,
			[
				[
					'id'          => self::PAGE,
					'type'        => 'page',
					'title'       => __( 'FlyAffiliate', 'flyaffiliate' ),
					'description' => __( 'Affiliate commissions on vendors’ products.', 'flyaffiliate' ),
					'icon'        => 'Handshake',
					'priority'    => 650,
				],
				[
					'id'          => 'flyaffiliate_vendor_programs_page',
					'type'        => 'subpage',
					'page_id'     => self::PAGE,
					'title'       => __( 'Vendor programs', 'flyaffiliate' ),
					'description' => __( 'What affiliates earn on vendors’ products, and how much of it a vendor can decide.', 'flyaffiliate' ),
					'priority'    => 100,
				],
				[
					'id'         => $section,
					'type'       => 'section',
					'subpage_id' => 'flyaffiliate_vendor_programs_page',
				],
				$field( Settings::PROGRAMS, 'switch', $switch ),
				$field(
					Settings::DEFAULT_RATE,
					'number',
					[
						'default'      => 0,
						'min'          => 0,
						'max'          => 100,
						'increment'    => 0.01,
						'postfix'      => '%',
						'dependencies' => $with_program,
						'validations'  => [ [ 'min_value' => 0 ], [ 'max_value' => 100 ] ],
					]
				),
				$field( Settings::VENDOR_OVERRIDE, 'switch', array_merge( $switch, [ 'dependencies' => $with_program ] ) ),
				$field(
					Settings::MAX_RATE,
					'number',
					[
						'default'      => 50,
						'min'          => 0,
						'max'          => 100,
						'increment'    => 0.01,
						'postfix'      => '%',
						'dependencies' => [ $show_when( Settings::PROGRAMS ), $show_when( Settings::VENDOR_OVERRIDE ) ],
						'validations'  => [ [ 'min_value' => 0 ], [ 'max_value' => 100 ] ],
					]
				),
				$field(
					Settings::LOCK_DAYS,
					'number',
					[
						'default'      => absint( flyaffiliate_get_option( 'hold_days', 30 ) ),
						'min'          => 0,
						'max'          => Settings::MAX_LOCK_DAYS,
						'increment'    => 1,
						'postfix'      => __( 'days', 'flyaffiliate' ),
						'dependencies' => $with_program,
						'validations'  => [ [ 'min_value' => 0 ], [ 'max_value' => Settings::MAX_LOCK_DAYS ] ],
					]
				),
			]
		);
	}
}
