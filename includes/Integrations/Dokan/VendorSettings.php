<?php
/**
 * Where a vendor sets their own affiliate rate and commission lock.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;

/**
 * The vendor's side of "Allow vendor settings", on both of Dokan's vendor
 * settings screens.
 *
 * On the legacy dashboard it is a tab of its own under Settings, a PHP form
 * saved by a plain POST, as Dokan Pro's RMA and Delivery Time tabs are. On the
 * new store settings screen it is a "FlyAffiliate" tab, described to Dokan as
 * schema and saved through Dokan's own REST route.
 *
 * Both disappear when the marketplace does not allow vendor settings.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VendorSettings implements Hookable {

	/**
	 * The tab's slug under the vendor dashboard's Settings.
	 *
	 * @var string
	 */
	const SLUG = 'flyaffiliate';

	/**
	 * The capability Dokan asks of anyone opening a settings tab.
	 *
	 * @var string
	 */
	const CAPABILITY = 'dokan_view_store_settings_menu';

	/**
	 * Nonce action of the legacy form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'flyaffiliate_vendor_settings';

	/**
	 * Nonce field of the legacy form.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'flyaffiliate_vendor_settings_nonce';

	/**
	 * Field ids on the new store settings screen.
	 *
	 * @var string
	 */
	const FIELD_OVERRIDE = 'flyaffiliate_override';
	const FIELD_RATE     = 'flyaffiliate_rate';
	const FIELD_LOCK     = 'flyaffiliate_lock_days';

	/**
	 * The vendor programs.
	 *
	 * @var VendorProgram
	 */
	protected VendorProgram $program;

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param VendorProgram $program The vendor programs.
	 */
	public function __construct( VendorProgram $program ) {
		$this->program = $program;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// The legacy dashboard.
		add_filter( 'dokan_get_dashboard_settings_nav', [ $this, 'add_settings_tab' ], 20 );
		add_filter( 'dokan_dashboard_settings_heading_title', [ $this, 'get_heading' ], 20, 2 );
		add_filter( 'dokan_dashboard_settings_helper_text', [ $this, 'get_help_text' ], 20, 2 );
		add_action( 'dokan_render_settings_content', [ $this, 'render_form' ], 20 );
		add_action( 'template_redirect', [ $this, 'save_form' ] );

		// The new store settings screen.
		add_filter( 'dokan_get_vendor_settings_schema', [ $this, 'add_schema' ], 20, 2 );
		add_action( 'dokan_after_saving_vendor_settings', [ $this, 'save_schema_values' ], 10, 2 );
	}

	/**
	 * What the vendor's settings say, shared by both screens.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string>
	 */
	protected function get_copy(): array {
		return [
			'help'      => __( 'What affiliates earn when they send a customer to your store, and how long a commission waits before it is paid.', 'flyaffiliate' ),
			'locked'    => __( 'The marketplace sets the affiliate rate and lock for every store.', 'flyaffiliate' ),
			'override'  => sprintf(
				/* translators: 1: the marketplace's rate, e.g. 10; 2: the marketplace's lock in days */
				__( 'While this is off, the marketplace’s settings apply: a rate of %1$s%% and a lock of %2$d days.', 'flyaffiliate' ),
				$this->program->format_number( $this->program->get_marketplace_rate() ),
				Settings::get_lock_days()
			),
			'rate'      => sprintf(
				/* translators: %s: the highest rate a vendor can set, e.g. 50 */
				__( 'The share of a sale an affiliate earns on your products. Up to %s%%.', 'flyaffiliate' ),
				$this->program->format_number( Settings::get_max_rate() )
			),
			'lock_days' => __( 'How many days a commission waits before it can be paid, so a refund lands while it can still be reversed. 0 means no wait.', 'flyaffiliate' ),
		];
	}

	/**
	 * Whether the vendor dashboard shows the new store settings screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	protected function uses_new_store_settings(): bool {
		return 'latest' === dokan_get_option( 'vendor_store_settings', 'dokan_appearance', 'legacy' );
	}

	/**
	 * Whether the current user may change the vendor's affiliate settings.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	protected function current_user_can_edit(): bool {
		return Settings::vendor_can_override()
			&& dokan_is_user_seller( dokan_get_current_user_id() )
			&& current_user_can( self::CAPABILITY );
	}

	/**
	 * The legacy Settings tab.
	 *
	 * Left out where the new store settings screen is in use: its FlyAffiliate
	 * tab holds the same fields, and two entries would only confuse.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, array<string, mixed>> $tabs The settings tabs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function add_settings_tab( $tabs ): array {
		$tabs = (array) $tabs;

		if ( ! Settings::vendor_can_override() || $this->uses_new_store_settings() ) {
			return $tabs;
		}

		$tabs[ self::SLUG ] = [
			'title'      => __( 'FlyAffiliate', 'flyaffiliate' ),
			'icon'       => '<i class="fas fa-percent"></i>',
			'url'        => dokan_get_navigation_url( 'settings/' . self::SLUG ),
			'pos'        => 95,
			'permission' => self::CAPABILITY,
		];

		return $tabs;
	}

	/**
	 * The heading of the legacy tab.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $heading The heading.
	 * @param string $tab     The tab being shown.
	 *
	 * @return string
	 */
	public function get_heading( $heading, $tab ) {
		return self::SLUG === $tab ? __( 'Affiliate Settings', 'flyaffiliate' ) : $heading;
	}

	/**
	 * The help text under the heading of the legacy tab.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $text The help text.
	 * @param string $tab  The tab being shown.
	 *
	 * @return string
	 */
	public function get_help_text( $text, $tab ) {
		return self::SLUG === $tab ? $this->get_copy()['help'] : $text;
	}

	/**
	 * The form of the legacy tab.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $query_vars The request's query vars.
	 *
	 * @return void
	 */
	public function render_form( $query_vars ): void {
		if ( self::SLUG !== ( $query_vars['settings'] ?? '' ) ) {
			return;
		}

		if ( ! $this->current_user_can_edit() ) {
			dokan_get_template_part(
				'global/dokan-error',
				'',
				[
					'deleted' => false,
					'message' => $this->get_copy()['locked'],
				]
			);

			return;
		}

		$vendor_id = dokan_get_current_user_id();

		flyaffiliate_get_template_part(
			'dokan/vendor-settings',
			'',
			[
				'settings'         => $this->program->get_settings( $vendor_id ),
				'copy'             => $this->get_copy(),
				'max_rate'         => $this->program->format_number( Settings::get_max_rate() ),
				'max_lock_days'    => Settings::MAX_LOCK_DAYS,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag set by our own redirect, shown once.
				'saved'            => isset( $_GET['message'] ) && 'success' === sanitize_key( wp_unslash( $_GET['message'] ) ),
				'error'            => (string) get_transient( $this->get_error_key( $vendor_id ) ),
				'nonce_action'     => self::NONCE_ACTION,
				'nonce_field'      => self::NONCE_FIELD,
			]
		);

		delete_transient( $this->get_error_key( $vendor_id ) );
	}

	/**
	 * Save the legacy form.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function save_form(): void {
		if ( ! isset( $_POST['flyaffiliate_save_vendor_settings'], $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! $this->current_user_can_edit() ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$vendor_id = dokan_get_current_user_id();
		$override  = isset( $_POST['flyaffiliate_override'] ) ? 'on' : 'off';
		$values    = [ 'override' => $override ];

		// The marketplace's values stand while the vendor's own are off, so only a vendor using their own sends them.
		if ( 'on' === $override ) {
			$values['rate']      = isset( $_POST['flyaffiliate_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['flyaffiliate_rate'] ) ) : '';
			$values['lock_days'] = isset( $_POST['flyaffiliate_lock_days'] ) ? sanitize_text_field( wp_unslash( $_POST['flyaffiliate_lock_days'] ) ) : '';
		}

		$saved = $this->program->save( $vendor_id, $values );
		$url   = dokan_get_navigation_url( 'settings/' . self::SLUG );

		if ( is_wp_error( $saved ) ) {
			set_transient( $this->get_error_key( $vendor_id ), $saved->get_error_message(), MINUTE_IN_SECONDS );
		} else {
			$url = add_query_arg( 'message', 'success', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Where a failed save keeps its message until the form is shown again.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return string
	 */
	protected function get_error_key( int $vendor_id ): string {
		return 'flyaffiliate_vendor_settings_error_' . $vendor_id;
	}

	/**
	 * The FlyAffiliate tab of the new store settings screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements  Flat schema elements.
	 * @param int                              $vendor_id The vendor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_schema( $elements, $vendor_id = 0 ): array {
		$elements  = (array) $elements;
		$vendor_id = absint( $vendor_id );

		if ( $vendor_id <= 0 || ! Settings::vendor_can_override() ) {
			return $elements;
		}

		$settings = $this->program->get_settings( $vendor_id );
		$copy     = $this->get_copy();
		$program  = $this->program;
		// The rules are the resolver's; a vendor using the marketplace's terms sends nothing to check.
		$check    = static function ( string $rule ) use ( $program ): callable {
			return static function ( $value, $all_values = [] ) use ( $program, $rule ) {
				if ( 'on' !== ( $all_values[ self::FIELD_OVERRIDE ] ?? 'on' ) ) {
					return true;
				}

				return $program->{$rule}( $value ) ?? true;
			};
		};
		$when_on  = [
			[
				'key'        => self::FIELD_OVERRIDE,
				'value'      => 'on',
				'comparison' => '===',
				'effect'     => 'show',
				'attribute'  => 'display',
				'to_self'    => true,
			],
		];

		return array_merge(
			$elements,
			[
				[
					'id'         => 'tab_flyaffiliate',
					'type'       => 'tab',
					'subpage_id' => 'store_settings',
					'title'      => __( 'FlyAffiliate', 'flyaffiliate' ),
					'priority'   => 60,
				],
				[
					'id'          => 'flyaffiliate_program',
					'type'        => 'section',
					'subpage_id'  => 'store_settings',
					'tab_id'      => 'tab_flyaffiliate',
					'title'       => __( 'Affiliate settings', 'flyaffiliate' ),
					'description' => $copy['help'],
					'priority'    => 10,
				],
				[
					'id'            => self::FIELD_OVERRIDE,
					'type'          => 'field',
					'variant'       => 'switch',
					'section_id'    => 'flyaffiliate_program',
					'title'         => __( 'Use my own settings', 'flyaffiliate' ),
					'description'   => $copy['override'],
					'value'         => $settings['override'],
					'default'       => 'off',
					'enable_state'  => [
						'label' => __( 'Enabled', 'flyaffiliate' ),
						'value' => 'on',
					],
					'disable_state' => [
						'label' => __( 'Disabled', 'flyaffiliate' ),
						'value' => 'off',
					],
					'non_meta'      => true,
				],
				[
					'id'              => self::FIELD_RATE,
					'type'            => 'field',
					'variant'         => 'number',
					'section_id'      => 'flyaffiliate_program',
					'title'           => __( 'Affiliate rate', 'flyaffiliate' ),
					'description'     => $copy['rate'],
					'value'           => $settings['rate'],
					'default'         => $program->get_marketplace_rate(),
					'min'             => 0,
					'max'             => Settings::get_max_rate(),
					'increment'       => 0.01,
					'postfix'         => '%',
					'dependencies'    => $when_on,
					'non_meta'        => true,
					'validation_func' => $check( 'validate_rate' ),
				],
				[
					'id'              => self::FIELD_LOCK,
					'type'            => 'field',
					'variant'         => 'number',
					'section_id'      => 'flyaffiliate_program',
					'title'           => __( 'Commission lock', 'flyaffiliate' ),
					'description'     => $copy['lock_days'],
					'value'           => $settings['lock_days'],
					'default'         => Settings::get_lock_days(),
					'min'             => 0,
					'max'             => Settings::MAX_LOCK_DAYS,
					'increment'       => 1,
					'postfix'         => __( 'days', 'flyaffiliate' ),
					'dependencies'    => $when_on,
					'non_meta'        => true,
					'validation_func' => $check( 'validate_lock' ),
				],
			]
		);
	}

	/**
	 * Save what the new store settings screen sent.
	 *
	 * Dokan has already checked the vendor, sanitised the values and run the
	 * validation above; the fields are `non_meta`, so storing them is ours.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int                  $vendor_id The vendor.
	 * @param array<string, mixed> $sanitized The values saved, keyed by field id.
	 *
	 * @return void
	 */
	public function save_schema_values( $vendor_id, $sanitized ): void {
		$sanitized = (array) $sanitized;
		$values    = [];

		if ( array_key_exists( self::FIELD_OVERRIDE, $sanitized ) ) {
			$values['override'] = 'on' === $sanitized[ self::FIELD_OVERRIDE ] ? 'on' : 'off';
		}

		$using_own = 'off' !== ( $values['override'] ?? $this->program->get_settings( absint( $vendor_id ) )['override'] );

		if ( $using_own && array_key_exists( self::FIELD_RATE, $sanitized ) ) {
			$values['rate'] = $sanitized[ self::FIELD_RATE ];
		}

		if ( $using_own && array_key_exists( self::FIELD_LOCK, $sanitized ) ) {
			$values['lock_days'] = $sanitized[ self::FIELD_LOCK ];
		}

		if ( [] !== $values ) {
			$this->program->save( absint( $vendor_id ), $values );
		}
	}
}
