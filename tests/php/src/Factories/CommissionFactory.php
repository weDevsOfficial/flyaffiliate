<?php
/**
 * Commission fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use FlyAffiliate\Models\Commission;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates commission rows for tests.
 *
 * `order_item_id` is unique in the schema, so the factory generates a distinct
 * one per row unless the test supplies it — which is exactly what an idempotency
 * test does when it deliberately reuses one.
 *
 * @since FLYAFFILIATE_SINCE
 */
class CommissionFactory extends WP_UnitTest_Factory_For_Thing {

	/**
	 * The next generated order item id.
	 *
	 * @var int
	 */
	protected static int $next_order_item_id = 90000;

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
			'base_amount' => 100.0,
			'rate'        => 15.0,
			'rate_type'   => Commission::RATE_PERCENTAGE,
			'amount'      => 15.0,
			'status'      => Commission::STATUS_PENDING,
			'source'      => 'woocommerce',
			'type'        => 'sale',
			'vendor_id'   => 0,
		];
	}

	/**
	 * Create a commission row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Commission fields.
	 *
	 * @return int The commission id.
	 */
	public function create_object( $args ) {
		if ( empty( $args['affiliate_id'] ) ) {
			$args['affiliate_id'] = $this->factory->affiliate->create();
		}

		if ( empty( $args['order_item_id'] ) ) {
			$args['order_item_id'] = ++self::$next_order_item_id;
		}

		if ( empty( $args['currency'] ) ) {
			$args['currency'] = get_woocommerce_currency();
		}

		$commission = new Commission();
		$commission->fill( $args );

		return $commission->save();
	}

	/**
	 * Update a commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Commission id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$commission = Commission::find( (int) $object_id );

		if ( null !== $commission ) {
			$commission->fill( $fields )->save();
		}

		return (int) $object_id;
	}

	/**
	 * Get a commission.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Commission id.
	 *
	 * @return Commission|null
	 */
	public function get_object_by_id( $object_id ) {
		return Commission::find( (int) $object_id );
	}

	/**
	 * Create a commission and return the model.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Commission fields.
	 *
	 * @return Commission|null
	 */
	public function create_and_get_model( array $args = [] ): ?Commission {
		return $this->get_object_by_id( $this->create( $args ) );
	}
}
