<?php
/**
 * The flat-array settings schema.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every settings page, subpage, section and field, as plain arrays.
 *
 * Each element matches `@wedevs/plugin-ui`'s `SettingsElement` shape one to
 * one, so the admin UI renders straight from the REST payload. The tree is
 * expressed with parent pointers (`page_id`, `subpage_id`, `section_id`) rather
 * than nesting; `SettingsRegistry` resolves it.
 *
 * Rules:
 * - A field `id` is globally unique. It is also the storage key.
 * - Switches store `'on'` / `'off'`; read them through `flyaffiliate_option_enabled()`.
 * - Dependencies reference a plain field id, never a dot path.
 * - `sanitize_callback` and `validate_callback` are PHP callables and are
 *   stripped before the schema leaves PHP.
 *
 * Extension point: `flyaffiliate_settings_schema`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SettingsSchema {

	/**
	 * The currency symbol positions, in WooCommerce's vocabulary.
	 *
	 * @var string[]
	 */
	const CURRENCY_POSITIONS = [ 'left', 'right', 'left_space', 'right_space' ];

	/**
	 * The complete schema, before the registry processes it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_schema(): array {
		$elements = array_merge(
			self::general_page(),
			self::commission_page(),
			self::payout_page(),
			self::integrations_page(),
			self::email_page()
		);

		/**
		 * Filters the settings schema.
		 *
		 * Append flat elements to add pages, subpages, sections or fields.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<int, array<string, mixed>> $elements Flat schema elements.
		 */
		return apply_filters( 'flyaffiliate_settings_schema', $elements );
	}

	/**
	 * Every field's default, keyed by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		$defaults = [];

		foreach ( self::get_schema() as $element ) {
			if ( 'field' !== ( $element['type'] ?? '' ) || empty( $element['id'] ) || ! empty( $element['readonly'] ) ) {
				continue;
			}

			$defaults[ $element['id'] ] = $element['default'] ?? '';
		}

		return $defaults;
	}

	/**
	 * A switch's enabled state.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array{value: string, title: string}
	 */
	private static function on(): array {
		return [
			'value' => 'on',
			'title' => __( 'Enabled', 'flyaffiliate' ),
		];
	}

	/**
	 * A switch's disabled state.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array{value: string, title: string}
	 */
	private static function off(): array {
		return [
			'value' => 'off',
			'title' => __( 'Disabled', 'flyaffiliate' ),
		];
	}

	/**
	 * A validation entry in plugin-ui's shape.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $rules   Pipe-separated rule names: `required`, `min`, `max`, `not_in`.
	 * @param string $message Error shown when the rule fails.
	 * @param array  $params  Rule parameters, e.g. `[ 'min' => 0, 'max' => 100 ]`.
	 *
	 * @return array{rules: string, message: string, params: array}
	 */
	private static function rule( string $rules, string $message, array $params = [] ): array {
		return [
			'rules'   => $rules,
			'message' => $message,
			'params'  => $params,
		];
	}

	/**
	 * A percentage field: 0 to 100 with two decimals.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id          Field id.
	 * @param string $section_id  Parent section.
	 * @param string $title       Label.
	 * @param string $description Help text.
	 * @param float  $default_value Default value.
	 *
	 * @return array<string, mixed>
	 */
	private static function percent_field( string $id, string $section_id, string $title, string $description, float $default_value ): array {
		return [
			'id'                => $id,
			'type'              => 'field',
			'variant'           => 'number',
			'section_id'        => $section_id,
			'title'             => $title,
			'description'       => $description,
			'default'           => $default_value,
			'min'               => 0,
			'max'               => 100,
			'increment'         => 0.01,
			'postfix'           => '%',
			'validations'       => [
				self::rule(
					'required|min|max',
					__( 'Enter a percentage between 0 and 100.', 'flyaffiliate' ),
					[
						'min' => 0,
						'max' => 100,
					]
				),
			],
			'sanitize_callback' => [ self::class, 'sanitize_percent' ],
		];
	}

	/**
	 * A whole-number-of-days field.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id          Field id.
	 * @param string $section_id  Parent section.
	 * @param string $title       Label.
	 * @param string $description Help text.
	 * @param int    $default_value Default value.
	 *
	 * @return array<string, mixed>
	 */
	private static function days_field( string $id, string $section_id, string $title, string $description, int $default_value ): array {
		return [
			'id'                => $id,
			'type'              => 'field',
			'variant'           => 'number',
			'section_id'        => $section_id,
			'title'             => $title,
			'description'       => $description,
			'default'           => $default_value,
			'min'               => 0,
			'increment'         => 1,
			'postfix'           => __( 'days', 'flyaffiliate' ),
			'validations'       => [
				self::rule( 'required|min', __( 'Enter zero or a positive number of days.', 'flyaffiliate' ), [ 'min' => 0 ] ),
			],
			'sanitize_callback' => 'absint',
		];
	}

	/**
	 * A switch field.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id          Field id.
	 * @param string $section_id  Parent section.
	 * @param string $title       Label.
	 * @param string $description Help text.
	 * @param bool   $default_value Whether the switch starts on.
	 *
	 * @return array<string, mixed>
	 */
	private static function switch_field( string $id, string $section_id, string $title, string $description, bool $default_value ): array {
		return [
			'id'            => $id,
			'type'          => 'field',
			'variant'       => 'switch',
			'section_id'    => $section_id,
			'title'         => $title,
			'description'   => $description,
			'default'       => $default_value ? 'on' : 'off',
			'enable_state'  => self::on(),
			'disable_state' => self::off(),
		];
	}

	/**
	 * The General page: tracking, the affiliate area and data handling.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function general_page(): array {
		return [
			[
				'id'          => 'general',
				'type'        => 'page',
				'title'       => __( 'General', 'flyaffiliate' ),
				'icon'        => 'Settings',
				'description' => __( 'How referrals are tracked and where affiliates sign in.', 'flyaffiliate' ),
				'priority'    => 10,
			],

			// Subpage: Tracking.
			[
				'id'          => 'tracking',
				'type'        => 'subpage',
				'page_id'     => 'general',
				'title'       => __( 'Tracking', 'flyaffiliate' ),
				'description' => __( 'The referral link and how long a click keeps earning.', 'flyaffiliate' ),
				'priority'    => 10,
			],
			[
				'id'          => 'tracking_settings',
				'type'        => 'section',
				'subpage_id'  => 'tracking',
				'title'       => __( 'Referral links', 'flyaffiliate' ),
				'description' => __( 'Every affiliate gets a link built from these rules.', 'flyaffiliate' ),
			],
			[
				'id'                => 'referral_variable',
				'type'              => 'field',
				'variant'           => 'text',
				'section_id'        => 'tracking_settings',
				'title'             => __( 'Referral variable', 'flyaffiliate' ),
				// translators: %s: an example referral link.
				'description'       => sprintf( __( 'The query variable a referral link carries, e.g. %s', 'flyaffiliate' ), home_url( '/?affiliate=1' ) ),
				'default'           => 'affiliate',
				'placeholder'       => 'affiliate',
				'validations'       => [
					self::rule( 'required', __( 'The referral variable cannot be empty.', 'flyaffiliate' ) ),
				],
				'sanitize_callback' => [ self::class, 'sanitize_referral_variable' ],
			],
			self::days_field(
				'cookie_duration',
				'tracking_settings',
				__( 'Attribution window', 'flyaffiliate' ),
				__( 'How long after clicking a referral link a purchase is still credited to the affiliate. 0 means until the browser closes.', 'flyaffiliate' ),
				30
			),

			// Subpage: Currency.
			[
				'id'          => 'currency_display',
				'type'        => 'subpage',
				'page_id'     => 'general',
				'title'       => __( 'Currency', 'flyaffiliate' ),
				'description' => __( 'How commission and payout amounts are written. This is FlyAffiliate\'s own setting: changing the WooCommerce currency does not change it.', 'flyaffiliate' ),
				'priority'    => 15,
			],
			[
				'id'         => 'currency_settings',
				'type'       => 'section',
				'subpage_id' => 'currency_display',
				'title'      => __( 'Currency settings', 'flyaffiliate' ),
				'description' => __( 'The currency amounts are shown in, and the characters they are written with, in the admin and on the affiliate dashboard.', 'flyaffiliate' ),
			],
			[
				'id'                => 'currency',
				'type'              => 'field',
				'variant'           => 'select',
				'section_id'        => 'currency_settings',
				'title'             => __( 'Currency', 'flyaffiliate' ),
				'description'       => __( 'The currency amounts are shown in.', 'flyaffiliate' ),
				'default'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'options'           => self::currency_options(),
				'validations'       => [
					self::rule( 'required', __( 'Choose a currency.', 'flyaffiliate' ) ),
				],
				'sanitize_callback' => [ self::class, 'sanitize_currency' ],
			],
			[
				'id'                => 'currency_position',
				'type'              => 'field',
				'variant'           => 'select',
				'section_id'        => 'currency_settings',
				'title'             => __( 'Currency symbol position', 'flyaffiliate' ),
				'description'       => __( 'Where the currency symbol sits next to an amount.', 'flyaffiliate' ),
				'default'           => self::woocommerce_currency_position(),
				'options'           => [
					[
						'value' => 'left',
						'label' => __( 'Before amount', 'flyaffiliate' ),
					],
					[
						'value' => 'right',
						'label' => __( 'After amount', 'flyaffiliate' ),
					],
					[
						'value' => 'left_space',
						'label' => __( 'Before amount with space', 'flyaffiliate' ),
					],
					[
						'value' => 'right_space',
						'label' => __( 'After amount with space', 'flyaffiliate' ),
					],
				],
				'validations'       => [
					self::rule( 'required', __( 'Choose where the symbol goes.', 'flyaffiliate' ) ),
				],
				'sanitize_callback' => [ self::class, 'sanitize_currency_position' ],
			],
			[
				'id'                => 'currency_thousands_separator',
				'type'              => 'field',
				'variant'           => 'text',
				'section_id'        => 'currency_settings',
				'title'             => __( 'Thousands separator', 'flyaffiliate' ),
				'description'       => __( 'The character between thousands, e.g. the comma in 1,000. Leave empty for none.', 'flyaffiliate' ),
				'default'           => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
				'sanitize_callback' => [ self::class, 'sanitize_separator' ],
			],
			[
				'id'                => 'currency_decimal_separator',
				'type'              => 'field',
				'variant'           => 'text',
				'section_id'        => 'currency_settings',
				'title'             => __( 'Decimal separator', 'flyaffiliate' ),
				'description'       => __( 'The character before the cents, e.g. the point in 9.99.', 'flyaffiliate' ),
				'default'           => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
				'validations'       => [
					self::rule( 'required', __( 'Enter a decimal separator.', 'flyaffiliate' ) ),
				],
				'sanitize_callback' => [ self::class, 'sanitize_separator' ],
			],

			// Subpage: Affiliate area.
			[
				'id'          => 'affiliate_area',
				'type'        => 'subpage',
				'page_id'     => 'general',
				'title'       => __( 'Affiliate area', 'flyaffiliate' ),
				'description' => __( 'The affiliate-facing pages were created on activation. Paste a shortcode into any other page to move them.', 'flyaffiliate' ),
				'priority'    => 20,
				// Nothing on this subpage is editable, so a save button would only confuse.
				'hide_save'   => true,
			],
			[
				'id'         => 'affiliate_area_pages',
				'type'       => 'section',
				'subpage_id' => 'affiliate_area',
				'title'      => __( 'Shortcodes', 'flyaffiliate' ),
				'description' => __( 'Paste a shortcode into any page to show the affiliate dashboard or the registration form there.', 'flyaffiliate' ),
			],
			[
				'id'          => 'dashboard_shortcode',
				'type'        => 'field',
				'variant'     => 'copy_field',
				'section_id'  => 'affiliate_area_pages',
				'title'       => __( 'Affiliate dashboard', 'flyaffiliate' ),
				'description' => __( 'Referral link, balance, commissions, visits, payouts and the affiliate’s own settings.', 'flyaffiliate' ),
				'value'       => '[flyaffiliate_dashboard]',
				'readonly'    => true,
			],
			[
				'id'          => 'register_shortcode',
				'type'        => 'field',
				'variant'     => 'copy_field',
				'section_id'  => 'affiliate_area_pages',
				'title'       => __( 'Registration form', 'flyaffiliate' ),
				'description' => __( 'Email-only signup with an activation link.', 'flyaffiliate' ),
				'value'       => '[flyaffiliate_register]',
				'readonly'    => true,
			],

			// Subpage: Data.
			[
				'id'          => 'data',
				'type'        => 'subpage',
				'page_id'     => 'general',
				'title'       => __( 'Data', 'flyaffiliate' ),
				'description' => __( 'What happens to the ledger when the plugin is removed.', 'flyaffiliate' ),
				'priority'    => 30,
			],
			[
				'id'         => 'data_settings',
				'type'       => 'section',
				'subpage_id' => 'data',
				'title'      => __( 'Uninstall', 'flyaffiliate' ),
				'description' => __( 'What happens to FlyAffiliate\'s affiliates, commissions, visits and payouts when the plugin is deleted.', 'flyaffiliate' ),
			],
			[
				'id'            => 'data_clear_on_uninstall',
				'type'          => 'field',
				'variant'       => 'switch',
				'section_id'    => 'data_settings',
				'title'         => __( 'Remove all data on uninstall', 'flyaffiliate' ),
				'description'   => __( 'Delete every affiliate, commission, visit, payout and setting when the plugin is deleted. Off by default so a reinstall finds the ledger intact.', 'flyaffiliate' ),
				'default'       => 'off',
				'enable_state'  => self::on(),
				'disable_state' => self::off(),
				'is_danger'     => true,
			],
		];
	}

	/**
	 * The Commissions page: rates and the rules they are applied under.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function commission_page(): array {
		return [
			[
				'id'          => 'commission',
				'type'        => 'page',
				'title'       => __( 'Commissions', 'flyaffiliate' ),
				'icon'        => 'Percent',
				'description' => __( 'Commissions are calculated per order item. The rate comes from the product, then the default — and is always clamped to the maximum.', 'flyaffiliate' ),
				'priority'    => 20,
			],

			// Subpage: Rates.
			[
				'id'          => 'rates',
				'type'        => 'subpage',
				'page_id'     => 'commission',
				'title'       => __( 'Rates', 'flyaffiliate' ),
				'description' => __( 'The fallback rate and the ceiling every rate is clamped to.', 'flyaffiliate' ),
				'priority'    => 10,
			],
			[
				'id'         => 'rate_settings',
				'type'       => 'section',
				'subpage_id' => 'rates',
				'title'      => __( 'Rates', 'flyaffiliate' ),
				'description' => __( 'What an affiliate earns on each product sold through their link.', 'flyaffiliate' ),
			],
			array_merge(
				self::percent_field(
					'default_rate',
					'rate_settings',
					__( 'Default rate', 'flyaffiliate' ),
					__( 'Used when neither the product nor the vendor sets a rate.', 'flyaffiliate' ),
					10.0
				),
				[
					'validate_callback' => [ self::class, 'validate_default_rate' ],
				]
			),
			self::percent_field(
				'max_rate',
				'rate_settings',
				__( 'Maximum rate', 'flyaffiliate' ),
				__( 'No commission is ever calculated above this, whatever a product or vendor rate says.', 'flyaffiliate' ),
				50.0
			),
			[
				'id'          => 'rate_type',
				'type'        => 'field',
				'variant'     => 'select',
				'section_id'  => 'rate_settings',
				'title'       => __( 'Rate type', 'flyaffiliate' ),
				'description' => __( 'Percentages only for now. Fixed amounts come with product-level rates.', 'flyaffiliate' ),
				'default'     => 'percentage',
				'options'     => [
					[
						'value' => 'percentage',
						'label' => __( 'Percentage of the item total', 'flyaffiliate' ),
					],
				],
				'validations' => [
					self::rule( 'required', __( 'Choose a rate type.', 'flyaffiliate' ) ),
				],
			],

			// Subpage: Rules.
			[
				'id'          => 'rules',
				'type'        => 'subpage',
				'page_id'     => 'commission',
				'title'       => __( 'Rules', 'flyaffiliate' ),
				'description' => __( 'When a commission matures and what it is calculated on.', 'flyaffiliate' ),
				'priority'    => 20,
			],
			[
				'id'         => 'maturation_settings',
				'type'       => 'section',
				'subpage_id' => 'rules',
				'title'      => __( 'Hold period', 'flyaffiliate' ),
				'description' => __( 'How long a commission waits before it can be paid out, which leaves time to reject it if the order is returned.', 'flyaffiliate' ),
			],
			self::days_field(
				'hold_days',
				'maturation_settings',
				__( 'Hold period', 'flyaffiliate' ),
				__( 'A commission stays pending this long after the sale before it becomes unpaid and can be paid out. With 0, a commission becomes unpaid as soon as its order is processing or completed. Changing this moves every pending commission.', 'flyaffiliate' ),
				30
			),
			[
				'id'         => 'base_amount_settings',
				'type'       => 'section',
				'subpage_id' => 'rules',
				'title'      => __( 'Base amount', 'flyaffiliate' ),
				'description' => __( 'What the rate is applied to on each order item.', 'flyaffiliate' ),
			],
			self::switch_field(
				'exclude_shipping',
				'base_amount_settings',
				__( 'Exclude shipping', 'flyaffiliate' ),
				__( 'Shipping is left out of the amount a commission is calculated on.', 'flyaffiliate' ),
				true
			),
			self::switch_field(
				'exclude_tax',
				'base_amount_settings',
				__( 'Exclude tax', 'flyaffiliate' ),
				__( 'Tax is left out of the amount a commission is calculated on.', 'flyaffiliate' ),
				true
			),
			[
				'id'         => 'eligibility_settings',
				'type'       => 'section',
				'subpage_id' => 'rules',
				'title'      => __( 'Eligibility', 'flyaffiliate' ),
				'description' => __( 'Which referred orders can earn a commission at all.', 'flyaffiliate' ),
			],
			self::switch_field(
				'block_self_referral',
				'eligibility_settings',
				__( 'Block self-referrals', 'flyaffiliate' ),
				__( 'An affiliate buying through their own link earns nothing.', 'flyaffiliate' ),
				true
			),
			[
				'id'          => 'refund_settings',
				'type'        => 'section',
				'subpage_id'  => 'rules',
				'title'       => __( 'Refunds', 'flyaffiliate' ),
				'description' => __( 'What happens to a commission when its order is refunded. A failed or cancelled order always rejects its commissions, and a commission that has been paid is never changed.', 'flyaffiliate' ),
			],
			self::switch_field(
				'reject_commissions_on_refund',
				'refund_settings',
				__( 'Reject commissions on refund', 'flyaffiliate' ),
				__( 'Mark unpaid commissions as rejected if the originating purchase is refunded.', 'flyaffiliate' ),
				false
			),
		];
	}

	/**
	 * The Payouts page.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function payout_page(): array {
		return [
			[
				'id'          => 'payout',
				'type'        => 'page',
				'title'       => __( 'Payouts', 'flyaffiliate' ),
				'icon'        => 'Wallet',
				'description' => __( 'Payouts are recorded by hand in this version: FlyAffiliate tells you who is owed what, you pay them, and it marks the commissions paid.', 'flyaffiliate' ),
				'priority'    => 30,
			],
			// Subpage: Rules. Every page has a subpage so the sidebar reads the
			// same way throughout — the selected pill is always a sub-item.
			[
				'id'          => 'payout_rules',
				'type'        => 'subpage',
				'page_id'     => 'payout',
				'title'       => __( 'Rules', 'flyaffiliate' ),
				'description' => __( 'Who is paid, and how.', 'flyaffiliate' ),
				'priority'    => 10,
			],
			[
				'id'         => 'payout_settings',
				'type'       => 'section',
				'subpage_id' => 'payout_rules',
				'title'      => __( 'Payout rules', 'flyaffiliate' ),
				'description' => __( 'Who is included when you create a payout, and how they are paid.', 'flyaffiliate' ),
			],
			[
				'id'                => 'minimum_amount',
				'type'              => 'field',
				'variant'           => 'number',
				'section_id'        => 'payout_settings',
				'title'             => __( 'Minimum payout', 'flyaffiliate' ),
				'description'       => __( 'The default minimum when creating a payout. An affiliate below it is carried over to the next run.', 'flyaffiliate' ),
				'default'           => 50.0,
				'min'               => 0,
				'increment'         => 0.01,
				'prefix'            => self::stored_currency_symbol(),
				'validations'       => [
					self::rule( 'required|min', __( 'Enter zero or a positive amount.', 'flyaffiliate' ), [ 'min' => 0 ] ),
				],
				'sanitize_callback' => [ self::class, 'sanitize_amount' ],
			],
			[
				'id'          => 'method',
				'type'        => 'field',
				'variant'     => 'select',
				'section_id'  => 'payout_settings',
				'title'       => __( 'Payout method', 'flyaffiliate' ),
				'description' => __( 'How affiliates are paid. Automatic methods arrive in a later version.', 'flyaffiliate' ),
				'default'     => 'manual',
				'options'     => [
					[
						'value' => 'manual',
						'label' => __( 'Manual — pay outside WordPress and record it here', 'flyaffiliate' ),
					],
				],
				'validations' => [
					self::rule( 'required', __( 'Choose a payout method.', 'flyaffiliate' ) ),
				],
			],
		];
	}

	/**
	 * The Integrations page: WooCommerce.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function integrations_page(): array {
		return [
			[
				'id'          => 'integrations',
				'type'        => 'page',
				'title'       => __( 'Integrations', 'flyaffiliate' ),
				'icon'        => 'Plug',
				'description' => __( 'Which platforms create commissions.', 'flyaffiliate' ),
				'priority'    => 40,
			],
			[
				'id'          => 'woocommerce',
				'type'        => 'subpage',
				'page_id'     => 'integrations',
				'title'       => __( 'WooCommerce', 'flyaffiliate' ),
				'description' => __( 'Referred orders become commissions.', 'flyaffiliate' ),
				'priority'    => 10,
			],
			[
				'id'         => 'woocommerce_settings',
				'type'       => 'section',
				'subpage_id' => 'woocommerce',
				'title'      => __( 'Orders', 'flyaffiliate' ),
				'description' => __( 'Whether orders placed through a referral link create commissions.', 'flyaffiliate' ),
			],
			self::switch_field(
				'woocommerce_enabled',
				'woocommerce_settings',
				__( 'Track WooCommerce orders', 'flyaffiliate' ),
				__( 'Create commissions for referred WooCommerce orders.', 'flyaffiliate' ),
				true
			),
		];
	}

	/**
	 * The Email page.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function email_page(): array {
		return [
			[
				'id'          => 'email',
				'type'        => 'page',
				'title'       => __( 'Email', 'flyaffiliate' ),
				'icon'        => 'Mail',
				'description' => __( 'The activation link is the only email this version sends.', 'flyaffiliate' ),
				'priority'    => 50,
			],
			// Subpage: Notifications.
			[
				'id'          => 'email_notifications',
				'type'        => 'subpage',
				'page_id'     => 'email',
				'title'       => __( 'Notifications', 'flyaffiliate' ),
				'description' => __( 'The emails the plugin sends.', 'flyaffiliate' ),
				'priority'    => 10,
			],
			[
				'id'         => 'email_settings',
				'type'       => 'section',
				'subpage_id' => 'email_notifications',
				'title'      => __( 'Notifications', 'flyaffiliate' ),
				'description' => __( 'The emails FlyAffiliate sends to affiliates.', 'flyaffiliate' ),
			],
			self::switch_field(
				'activation_email_enabled',
				'email_settings',
				__( 'Send an activation link on signup', 'flyaffiliate' ),
				__( 'When off, new affiliates stay pending until you activate them.', 'flyaffiliate' ),
				true
			),
		];
	}

	/**
	 * Every WooCommerce currency as a select option: "US Dollar ($)".
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function currency_options(): array {
		if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
			return [
				[
					'value' => 'USD',
					'label' => 'USD',
				],
			];
		}

		$options = [];

		foreach ( get_woocommerce_currencies() as $code => $name ) {
			$symbol    = html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
			$options[] = [
				'value' => (string) $code,
				'label' => '' !== $symbol ? sprintf( '%1$s (%2$s)', $name, $symbol ) : (string) $name,
			];
		}

		return $options;
	}

	/**
	 * The symbol of the currency saved in the settings.
	 *
	 * Read from the stored option directly: the schema is what defines the
	 * defaults, so going through the settings service here would recurse.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	private static function stored_currency_symbol(): string {
		$stored = (array) get_option( 'flyaffiliate_settings', [] );
		$code   = ! empty( $stored['currency'] ) ? (string) $stored['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $code ) : '';

		return html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * WooCommerce's symbol position, the first value of FlyAffiliate's own.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	private static function woocommerce_currency_position(): string {
		$position = (string) get_option( 'woocommerce_currency_pos', 'left' );

		return in_array( $position, self::CURRENCY_POSITIONS, true ) ? $position : 'left';
	}

	/**
	 * Keep a currency code WooCommerce knows.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_currency( $value ): string {
		$code  = strtoupper( sanitize_key( (string) $value ) );
		$known = function_exists( 'get_woocommerce_currencies' ) ? array_keys( get_woocommerce_currencies() ) : [ $code ];

		return in_array( $code, $known, true ) ? $code : 'USD';
	}

	/**
	 * Keep one of the four symbol positions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_currency_position( $value ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, self::CURRENCY_POSITIONS, true ) ? $value : 'left';
	}

	/**
	 * A separator: up to three characters, spaces kept.
	 *
	 * `sanitize_text_field()` and `wp_strip_all_tags()` both trim, and a space
	 * is a real thousands separator in much of Europe, so tags are removed with
	 * `wp_kses()` instead.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_separator( $value ): string {
		$value = wp_kses( (string) $value, [] );
		$value = preg_replace( '/[\r\n\t]+/', '', $value );

		return mb_substr( (string) $value, 0, 3 );
	}

	/**
	 * Reduce a referral variable to a safe query key.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_referral_variable( $value ): string {
		$key = sanitize_key( (string) $value );

		return '' !== $key ? $key : 'affiliate';
	}

	/**
	 * Clamp a percentage to 0–100 with two decimals.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return float
	 */
	public static function sanitize_percent( $value ): float {
		return round( min( 100.0, max( 0.0, (float) $value ) ), 2 );
	}

	/**
	 * A non-negative money amount with two decimals.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return float
	 */
	public static function sanitize_amount( $value ): float {
		return round( max( 0.0, (float) $value ), 2 );
	}

	/**
	 * The default rate may not exceed the maximum rate.
	 *
	 * A resolved rate is clamped at calculation time anyway, but a default above
	 * the maximum is a configuration mistake worth refusing rather than silently
	 * clamping every commission later.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed                $value  The submitted default rate.
	 * @param array<string, mixed> $values Every value in the same save, keyed by id.
	 *
	 * @return true|string True when valid, otherwise the error message.
	 */
	public static function validate_default_rate( $value, array $values ) {
		$max = array_key_exists( 'max_rate', $values ) ? $values['max_rate'] : flyaffiliate_get_option( 'max_rate', 100 );

		if ( (float) $value > (float) $max ) {
			return __( 'The default rate cannot be higher than the maximum rate.', 'flyaffiliate' );
		}

		return true;
	}
}
