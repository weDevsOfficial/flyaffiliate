<?php
/**
 * Product fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use WC_Product_Simple;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates WooCommerce products for tests.
 *
 * `vendor_id` sets the product's author, which is how a product is attributed to
 * the vendor — on a single-merchant store the author is just a user.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ProductFactory extends WP_UnitTest_Factory_For_Thing {

	/**
	 * Construct the factory.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param \WP_UnitTest_Factory|null $factory The parent factory.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		$this->default_generation_definitions = [
			'name'          => 'Test Product',
			'regular_price' => 100.0,
			'price'         => 100.0,
			'status'        => 'publish',
		];
	}

	/**
	 * Create a product.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Product fields.
	 *
	 *     @type float  $regular_price     Price.
	 *     @type int    $vendor_id         Author, which is the vendor.
	 *     @type float  $flyaffiliate_rate Per-product commission rate override.
	 * }
	 *
	 * @return int The product id.
	 */
	public function create_object( $args ) {
		$product = new WC_Product_Simple();

		$product->set_name( $args['name'] ?? 'Test Product' );
		$product->set_regular_price( (string) ( $args['regular_price'] ?? 100.0 ) );
		$product->set_price( (string) ( $args['price'] ?? $args['regular_price'] ?? 100.0 ) );
		$product->set_status( $args['status'] ?? 'publish' );

		$product_id = $product->save();

		if ( ! empty( $args['vendor_id'] ) ) {
			wp_update_post(
				[
					'ID'          => $product_id,
					'post_author' => (int) $args['vendor_id'],
				]
			);
		}

		if ( isset( $args['flyaffiliate_rate'] ) ) {
			update_post_meta( $product_id, '_flyaffiliate_rate', (float) $args['flyaffiliate_rate'] );
		}

		return (int) $product_id;
	}

	/**
	 * Update a product.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Product id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$product = wc_get_product( (int) $object_id );

		if ( ! $product ) {
			return (int) $object_id;
		}

		foreach ( $fields as $key => $value ) {
			$setter = 'set_' . $key;

			if ( is_callable( [ $product, $setter ] ) ) {
				$product->{$setter}( $value );
			}
		}

		$product->save();

		return (int) $object_id;
	}

	/**
	 * Get a product.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Product id.
	 *
	 * @return \WC_Product|false
	 */
	public function get_object_by_id( $object_id ) {
		return wc_get_product( (int) $object_id );
	}
}
