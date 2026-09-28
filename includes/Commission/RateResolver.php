<?php
/**
 * Which rate applies to a sale.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Commission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product rate, then vendor rate, then the default — clamped to the maximum.
 *
 * The hierarchy is the one `CONTEXT.md` fixes. The vendor step is a filter so
 * the Dokan integration can supply a store rate without this class knowing
 * Dokan exists.
 *
 * @since FLYAFFILIATE_SINCE
 */
class RateResolver {

	/**
	 * Product meta holding a per-product rate.
	 *
	 * @var string
	 */
	const PRODUCT_META = '_flyaffiliate_rate';

	/**
	 * The percentage rate for a product.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $product_id The product (or variation) id.
	 * @param int $vendor_id  The vendor, on a marketplace. 0 elsewhere.
	 *
	 * @return float A percentage between 0 and the maximum rate.
	 */
	public function resolve( int $product_id, int $vendor_id = 0 ): float {
		$rate = $this->get_product_rate( $product_id );

		if ( null === $rate ) {
			/**
			 * Filters the rate a vendor's program applies, before the default.
			 *
			 * Return null to fall through to the default rate.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param float|null $rate       The vendor rate, or null.
			 * @param int        $vendor_id  The vendor.
			 * @param int        $product_id The product.
			 */
			$rate = apply_filters( 'flyaffiliate_vendor_rate', null, $vendor_id, $product_id );
		}

		if ( null === $rate ) {
			$rate = (float) flyaffiliate_get_option( 'default_rate', 10 );
		}

		$max  = (float) flyaffiliate_get_option( 'max_rate', 50 );
		$rate = max( 0.0, min( (float) $rate, $max ) );

		/**
		 * Filters the final rate applied to a product's sale.
		 *
		 * The result is clamped to the maximum rate again, so a filter cannot
		 * exceed it.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param float $rate       The rate, in percent.
		 * @param int   $product_id The product.
		 * @param int   $vendor_id  The vendor.
		 */
		$rate = (float) apply_filters( 'flyaffiliate_commission_rate', $rate, $product_id, $vendor_id );

		return max( 0.0, min( $rate, $max ) );
	}

	/**
	 * A per-product rate, from the product or its parent.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $product_id The product or variation id.
	 *
	 * @return float|null Null when neither sets one.
	 */
	protected function get_product_rate( int $product_id ): ?float {
		if ( 0 === $product_id ) {
			return null;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			return null;
		}

		$ids = [ $product->get_id() ];

		if ( $product->get_parent_id() > 0 ) {
			$ids[] = $product->get_parent_id();
		}

		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, self::PRODUCT_META, true );

			if ( '' !== $value && null !== $value && is_numeric( $value ) ) {
				return (float) $value;
			}
		}

		return null;
	}
}
