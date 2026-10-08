<?php
/**
 * The marketplace's vendor-program settings.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the settings FlyAffiliate keeps inside Dokan's own settings.
 *
 * They are stored in Dokan's "Selling Options" option under `flyaffiliate_`
 * keys, and read with `dokan_get_option()`, so a value saved from either of
 * Dokan's settings screens is the value every reader sees.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Settings {

	/**
	 * The Dokan settings section, which is also the option name.
	 *
	 * @var string
	 */
	const SECTION = 'dokan_selling';

	/**
	 * Whether vendors run affiliate programs at all.
	 *
	 * @var string
	 */
	const PROGRAMS = 'flyaffiliate_vendor_programs';

	/**
	 * The rate for a vendor who has not set one.
	 *
	 * @var string
	 */
	const DEFAULT_RATE = 'flyaffiliate_vendor_default_rate';

	/**
	 * Whether a vendor may set their own rate and lock.
	 *
	 * @var string
	 */
	const VENDOR_OVERRIDE = 'flyaffiliate_vendor_override';

	/**
	 * The highest rate a vendor may set.
	 *
	 * @var string
	 */
	const MAX_RATE = 'flyaffiliate_vendor_max_rate';

	/**
	 * How long a commission on a vendor's sale waits before it can be paid.
	 *
	 * @var string
	 */
	const LOCK_DAYS = 'flyaffiliate_vendor_lock_days';

	/**
	 * The longest lock, in days.
	 *
	 * @var int
	 */
	const MAX_LOCK_DAYS = 365;

	/**
	 * Every key, for the listeners that watch Dokan's settings being saved.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public static function get_keys(): array {
		return [ self::PROGRAMS, self::DEFAULT_RATE, self::VENDOR_OVERRIDE, self::MAX_RATE, self::LOCK_DAYS ];
	}

	/**
	 * The keys that decide how long a vendor's commission waits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public static function get_lock_keys(): array {
		return [ self::PROGRAMS, self::VENDOR_OVERRIDE, self::LOCK_DAYS ];
	}

	/**
	 * One stored value.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $key      One of the constants above.
	 * @param mixed  $fallback Returned when nothing is stored.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $fallback = '' ) {
		return dokan_get_option( $key, self::SECTION, $fallback );
	}

	/**
	 * Whether vendor programs are on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'on' === self::get( self::PROGRAMS, 'on' );
	}

	/**
	 * Whether a vendor may set their own rate and lock.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public static function vendor_can_override(): bool {
		return self::is_enabled() && 'on' === self::get( self::VENDOR_OVERRIDE, 'on' );
	}

	/**
	 * The rate for a vendor who has not set one.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return float|null Null when the marketplace set none, so FlyAffiliate's default rate applies.
	 */
	public static function get_default_rate(): ?float {
		$rate = self::get( self::DEFAULT_RATE, '' );

		// The legacy screen stores a string, the new one a number.
		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return null;
		}

		return min( 100.0, (float) $rate );
	}

	/**
	 * The highest rate a vendor may set.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return float
	 */
	public static function get_max_rate(): float {
		$rate = self::get( self::MAX_RATE, 50 );
		$rate = is_numeric( $rate ) ? (float) $rate : 50.0;

		return max( 0.0, min( 100.0, $rate ) );
	}

	/**
	 * How long a commission on a vendor's sale waits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int Days. FlyAffiliate's own hold period until the marketplace sets one.
	 */
	public static function get_lock_days(): int {
		// Not get_hold_days(): its filter comes back to this class.
		$fallback = absint( flyaffiliate_get_option( 'hold_days', 30 ) );
		$days     = self::get( self::LOCK_DAYS, $fallback );

		if ( ! is_numeric( $days ) ) {
			return $fallback;
		}

		return min( self::MAX_LOCK_DAYS, absint( $days ) );
	}
}
