<?php
/**
 * One vendor's affiliate program: its rate and its commission lock.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Commission\RateResolver;
use WP_Error;

/**
 * Resolves what applies to a vendor, the way Dokan's Delivery Time module
 * resolves a vendor's delivery settings.
 *
 * The vendor's own values win only when the marketplace allows vendor settings
 * **and** the vendor has switched their own on. Otherwise the marketplace's
 * values apply. Switching the permission off ignores what the vendor saved; it
 * does not delete it.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VendorProgram {

	/**
	 * User meta holding a vendor's own settings.
	 *
	 * @var string
	 */
	const META = '_flyaffiliate_vendor_settings';

	/**
	 * Order item meta recording the terms a commission was created under.
	 *
	 * @var string
	 */
	const TERMS_META = '_flyaffiliate_vendor_terms';

	/**
	 * The rate resolver.
	 *
	 * @var RateResolver
	 */
	protected RateResolver $rates;

	/**
	 * Each vendor's settings, read once per request.
	 *
	 * @var array<int, array{override: string, rate: float, lock_days: int}>
	 */
	protected array $settings = [];

	/**
	 * Each vendor's store name, looked up once per request.
	 *
	 * @var array<int, string>
	 */
	protected array $store_names = [];

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param RateResolver $rates The rate resolver.
	 */
	public function __construct( RateResolver $rates ) {
		$this->rates = $rates;
	}

	/**
	 * What a vendor saved, with the marketplace's values where they saved nothing.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return array{override: string, rate: float, lock_days: int}
	 */
	public function get_settings( int $vendor_id ): array {
		if ( isset( $this->settings[ $vendor_id ] ) ) {
			return $this->settings[ $vendor_id ];
		}

		$stored = $vendor_id > 0 ? get_user_meta( $vendor_id, self::META, true ) : [];
		$stored = is_array( $stored ) ? $stored : [];

		$this->settings[ $vendor_id ] = [
			'override'  => 'on' === ( $stored['override'] ?? 'off' ) ? 'on' : 'off',
			'rate'      => isset( $stored['rate'] ) && is_numeric( $stored['rate'] ) && (float) $stored['rate'] > 0
				? (float) $stored['rate']
				: $this->get_marketplace_rate(),
			'lock_days' => isset( $stored['lock_days'] ) && is_numeric( $stored['lock_days'] )
				? min( Settings::MAX_LOCK_DAYS, absint( $stored['lock_days'] ) )
				: Settings::get_lock_days(),
		];

		return $this->settings[ $vendor_id ];
	}

	/**
	 * Whether the vendor's own settings are the ones in force.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return bool
	 */
	public function has_override( int $vendor_id ): bool {
		if ( $vendor_id <= 0 || ! Settings::vendor_can_override() ) {
			return false;
		}

		return 'on' === $this->get_settings( $vendor_id )['override'];
	}

	/**
	 * The rate the marketplace applies to a vendor who set none.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return float
	 */
	public function get_marketplace_rate(): float {
		return Settings::get_default_rate() ?? (float) flyaffiliate_get_option( 'default_rate', 10 );
	}

	/**
	 * The vendor step of the rate hierarchy.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return float|null Null when neither the vendor nor the marketplace set a vendor rate.
	 */
	public function get_rate( int $vendor_id ): ?float {
		if ( $vendor_id <= 0 || ! Settings::is_enabled() ) {
			return null;
		}

		if ( $this->has_override( $vendor_id ) ) {
			// Clamped on read as well: the maximum may have dropped since the vendor saved.
			return min( $this->get_settings( $vendor_id )['rate'], Settings::get_max_rate() );
		}

		return Settings::get_default_rate();
	}

	/**
	 * How long a commission on the vendor's sale waits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 * @param int $fallback  FlyAffiliate's own hold period.
	 *
	 * @return int Days.
	 */
	public function get_hold_days( int $vendor_id, int $fallback ): int {
		if ( $vendor_id <= 0 || ! Settings::is_enabled() ) {
			return $fallback;
		}

		return $this->has_override( $vendor_id )
			? $this->get_settings( $vendor_id )['lock_days']
			: Settings::get_lock_days();
	}

	/**
	 * What an affiliate earns on a product, and when it can be paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id  The vendor.
	 * @param int $product_id The product, when the rate of one product is wanted.
	 *
	 * @return array{rate: float, hold_days: int, overridden: bool, store_name: string}
	 */
	public function get_terms( int $vendor_id, int $product_id = 0 ): array {
		return [
			'rate'       => $this->rates->resolve( $product_id, $vendor_id ),
			'hold_days'  => flyaffiliate()->commission->get_hold_days( $vendor_id ),
			'overridden' => $this->has_override( $vendor_id ),
			'store_name' => $this->get_store_name( $vendor_id ),
		];
	}

	/**
	 * A vendor's store name.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return string Empty when the vendor is gone.
	 */
	public function get_store_name( int $vendor_id ): string {
		if ( ! isset( $this->store_names[ $vendor_id ] ) ) {
			$vendor                          = $vendor_id > 0 ? dokan()->vendor->get( $vendor_id ) : null;
			$this->store_names[ $vendor_id ] = $vendor ? (string) $vendor->get_shop_name() : '';
		}

		return $this->store_names[ $vendor_id ];
	}

	/**
	 * Why a rate cannot be a vendor's, if it cannot.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $rate The rate, in percent.
	 *
	 * @return string|null The reason, or null when the rate is fine.
	 */
	public function validate_rate( $rate ): ?string {
		$max = Settings::get_max_rate();

		if ( is_numeric( $rate ) && (float) $rate > 0 && (float) $rate <= $max ) {
			return null;
		}

		return sprintf(
			/* translators: %s: the highest rate a vendor can set, e.g. 50 */
			__( 'Enter a rate above 0 and up to %s%%.', 'flyaffiliate' ),
			$this->format_number( $max )
		);
	}

	/**
	 * Why a lock cannot be a vendor's, if it cannot.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $days The lock, in days.
	 *
	 * @return string|null The reason, or null when the lock is fine.
	 */
	public function validate_lock( $days ): ?string {
		if ( is_numeric( $days ) && (float) $days >= 0 && (float) $days <= Settings::MAX_LOCK_DAYS ) {
			return null;
		}

		return sprintf(
			/* translators: %d: the longest lock in days */
			__( 'Enter a lock between 0 and %d days.', 'flyaffiliate' ),
			Settings::MAX_LOCK_DAYS
		);
	}

	/**
	 * Save a vendor's own settings.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int                  $vendor_id The vendor.
	 * @param array<string, mixed> $values    `override`, `rate`, `lock_days`. A key left out keeps its value.
	 *
	 * @return array{override: string, rate: float, lock_days: int}|WP_Error
	 */
	public function save( int $vendor_id, array $values ) {
		if ( $vendor_id <= 0 ) {
			return new WP_Error( 'flyaffiliate_invalid_vendor', __( 'That vendor does not exist.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		if ( ! Settings::vendor_can_override() ) {
			return new WP_Error( 'flyaffiliate_vendor_settings_locked', __( 'The marketplace sets the affiliate rate and lock for every store.', 'flyaffiliate' ), [ 'status' => 403 ] );
		}

		$settings = $this->get_settings( $vendor_id );

		if ( array_key_exists( 'override', $values ) ) {
			$settings['override'] = in_array( $values['override'], [ 'on', true, 1, '1', 'yes' ], true ) ? 'on' : 'off';
		}

		if ( array_key_exists( 'rate', $values ) ) {
			$error = $this->validate_rate( $values['rate'] );

			if ( null !== $error ) {
				return new WP_Error( 'flyaffiliate_invalid_vendor_rate', $error, [ 'status' => 400 ] );
			}

			$settings['rate'] = round( (float) $values['rate'], 4 );
		}

		if ( array_key_exists( 'lock_days', $values ) ) {
			$error = $this->validate_lock( $values['lock_days'] );

			if ( null !== $error ) {
				return new WP_Error( 'flyaffiliate_invalid_vendor_lock', $error, [ 'status' => 400 ] );
			}

			$settings['lock_days'] = absint( $values['lock_days'] );
		}

		update_user_meta( $vendor_id, self::META, $settings );
		$this->settings[ $vendor_id ] = $settings;

		/**
		 * Fires after a vendor saved their affiliate settings.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int                                                 $vendor_id The vendor.
		 * @param array{override: string, rate: float, lock_days: int} $settings  What is stored now.
		 */
		do_action( 'flyaffiliate_dokan_vendor_settings_saved', $vendor_id, $settings );

		return $settings;
	}

	/**
	 * A rate or a number of days without trailing zeros.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float $number The number.
	 *
	 * @return string
	 */
	public function format_number( float $number ): string {
		return (string) wc_format_decimal( $number, 2, true );
	}
}
