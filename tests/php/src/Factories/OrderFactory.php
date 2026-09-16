<?php
/**
 * Order fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use WC_Order;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates WooCommerce orders for tests.
 *
 * Orders are created through `WC_Order`, so they land in whichever storage
 * WooCommerce is configured for. The suite runs with HPOS on.
 *
 * @since FLYAFFILIATE_SINCE
 */
class OrderFactory extends WP_UnitTest_Factory_For_Thing {

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
			'status' => 'pending',
		];
	}

	/**
	 * Create an order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Order fields.
	 *
	 *     @type int    $customer_id Customer user id.
	 *     @type string $status      Order status.
	 *     @type array  $items       List of `[ 'product_id' => int, 'quantity' => int ]`.
	 *     @type array  $meta        Order meta to set.
	 * }
	 *
	 * @return int The order id.
	 */
	public function create_object( $args ) {
		$order = new WC_Order();

		if ( ! empty( $args['customer_id'] ) ) {
			$order->set_customer_id( (int) $args['customer_id'] );
		}

		foreach ( (array) ( $args['items'] ?? [] ) as $item ) {
			$product = wc_get_product( (int) $item['product_id'] );

			if ( ! $product ) {
				continue;
			}

			$order->add_product( $product, (int) ( $item['quantity'] ?? 1 ) );
		}

		foreach ( (array) ( $args['meta'] ?? [] ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->calculate_totals();
		$order->set_status( $args['status'] ?? 'pending' );

		return (int) $order->save();
	}

	/**
	 * Update an order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Order id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$order = wc_get_order( (int) $object_id );

		if ( ! $order ) {
			return (int) $object_id;
		}

		foreach ( $fields as $key => $value ) {
			$setter = 'set_' . $key;

			if ( is_callable( [ $order, $setter ] ) ) {
				$order->{$setter}( $value );
			}
		}

		$order->save();

		return (int) $object_id;
	}

	/**
	 * Get an order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Order id.
	 *
	 * @return \WC_Order|false
	 */
	public function get_object_by_id( $object_id ) {
		return wc_get_order( (int) $object_id );
	}
}
