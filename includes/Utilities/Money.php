<?php
/**
 * Money helpers.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts between the decimal amounts stored in the database and the integer
 * cents everything in this plugin compares and sums with.
 *
 * CONTEXT.md money rule 6: compare and sum money in integer cents, never as
 * floats. `0.1 + 0.2 !== 0.3` is not a rounding curiosity when the result
 * decides what an affiliate is paid.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Money {

	/**
	 * Decimal places used by the `DECIMAL(19,4)` money columns.
	 *
	 * Four, not two, so that a rate applied to a base amount keeps its precision
	 * until the value is rounded once, on the way out.
	 *
	 * @var int
	 */
	const SCALE = 4;

	/**
	 * Convert an amount to integer cents.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $amount The amount.
	 *
	 * @return int
	 */
	public static function to_cents( $amount ): int {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Convert integer cents back to an amount.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 */
	public static function from_cents( int $cents ): float {
		return $cents / 100;
	}


	/**
	 * Round an amount to the currency's own precision.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $amount The amount.
	 *
	 * @return float
	 */
	public static function round( $amount ): float {
		return round( (float) $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	}

	/**
	 * Format an amount for a `DECIMAL(19,4)` column.
	 *
	 * Returned as a string so the value reaches MySQL exactly as computed,
	 * without a float round-trip.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $amount The amount.
	 *
	 * @return string
	 */
	public static function for_db( $amount ): string {
		return number_format( (float) $amount, self::SCALE, '.', '' );
	}

	/**
	 * Scale an amount by a fraction, rounded to the currency's precision.
	 *
	 * Used when a partial refund rescales a commission by the unrefunded
	 * fraction of its own order item (CONTEXT.md money rule 5).
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $amount   The amount.
	 * @param float            $fraction The fraction to keep, between 0 and 1.
	 *
	 * @return float
	 */
	public static function scale( $amount, float $fraction ): float {
		return self::round( (float) $amount * max( 0.0, min( 1.0, $fraction ) ) );
	}
}
