<?php
/**
 * What the WooCommerce integration adds to the plugin's own screens.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Schema\SettingsSchema;
use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;

/**
 * The integration announces itself: its commission origin, and its settings.
 *
 * The plugin is standalone (ADR-0013). Nothing in the core lists WooCommerce
 * as an origin or shows its settings; this class does. It is registered
 * whether or not WooCommerce is active — SliceWP lists every integration the
 * same way — while the hooks that act on orders wait for WooCommerce. A
 * future platform integration adds its own origin and subpage the same way.
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
		add_filter( 'flyaffiliate_available_commission_sources', [ $this, 'add_source' ] );
		add_filter( 'flyaffiliate_settings_schema', [ $this, 'add_settings' ] );
	}

	/**
	 * Offer WooCommerce as an origin for new commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, string> $sources Origin => label.
	 *
	 * @return array<string, string>
	 */
	public function add_source( array $sources ): array {
		return [ Commission::SOURCE_WOOCOMMERCE => Commission::get_sources()[ Commission::SOURCE_WOOCOMMERCE ] ] + $sources;
	}

	/**
	 * The Integrations → WooCommerce subpage.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema elements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( array $elements ): array {
		$elements[] = [
			'id'          => 'woocommerce',
			'type'        => 'subpage',
			'page_id'     => 'integrations',
			'title'       => __( 'WooCommerce', 'flyaffiliate' ),
			'description' => __( 'Referred orders become commissions.', 'flyaffiliate' ),
			'priority'    => 10,
		];
		$elements[] = [
			'id'          => 'woocommerce_settings',
			'type'        => 'section',
			'subpage_id'  => 'woocommerce',
			'title'       => __( 'Orders', 'flyaffiliate' ),
			'description' => class_exists( 'WooCommerce' )
				? __( 'Whether orders placed through a referral link create commissions.', 'flyaffiliate' )
				: __( 'Whether orders placed through a referral link create commissions. WooCommerce is not active on this site; this takes effect once it is.', 'flyaffiliate' ),
		];
		$elements[] = SettingsSchema::switch_field(
			'woocommerce_enabled',
			'woocommerce_settings',
			__( 'Track WooCommerce orders', 'flyaffiliate' ),
			__( 'Create commissions for referred WooCommerce orders.', 'flyaffiliate' ),
			true
		);

		return $elements;
	}
}
