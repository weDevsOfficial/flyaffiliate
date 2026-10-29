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
 * a marketplace integration can supply a store rate without this class
 * knowing the marketplace exists.
 *
 * @since 1.0.0
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
	 * @since 1.0.0
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
			 * @since 1.0.0
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

		/**
		 * Filters the maximum rate a product's sale can earn.
		 *
		 * The global maximum by default. A marketplace that sets its own
		 * maximum for vendor sales replaces it here, for those sales only; the
		 * rate is clamped to the result before and after
		 * `flyaffiliate_commission_rate`.
		 *
		 * @since 1.0.0
		 *
		 * @param float $max        The maximum rate, in percent.
		 * @param int   $product_id The product.
		 * @param int   $vendor_id  The vendor. 0 on a single-merchant store.
		 */
		$max  = max( 0.0, (float) apply_filters( 'flyaffiliate_max_rate', (float) flyaffiliate_get_option( 'max_rate', 50 ), $product_id, $vendor_id ) );
		$rate = max( 0.0, min( (float) $rate, $max ) );

		/**
		 * Filters the final rate applied to a product's sale.
		 *
		 * The result is clamped to the maximum rate again, so a filter cannot
		 * exceed it.
		 *
		 * @since 1.0.0
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
	 * @since 1.0.0
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
