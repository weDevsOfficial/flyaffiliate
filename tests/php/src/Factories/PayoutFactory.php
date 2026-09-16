<?php
/**
 * Payout fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use FlyAffiliate\Models\Payout;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates payout rows for tests.
 *
 * @since FLYAFFILIATE_SINCE
 */
class PayoutFactory extends WP_UnitTest_Factory_For_Thing {

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
			'amount' => 100.0,
			'method' => Payout::METHOD_MANUAL,
			'status' => Payout::STATUS_PAID,
			'note'   => '',
		];
	}

	/**
	 * Create a payout row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Payout fields.
	 *
	 * @return int The payout id.
	 */
	public function create_object( $args ) {
		if ( empty( $args['affiliate_id'] ) ) {
			$args['affiliate_id'] = $this->factory->affiliate->create();
		}

		if ( empty( $args['batch_key'] ) ) {
			$args['batch_key'] = wp_generate_uuid4();
		}

		if ( empty( $args['currency'] ) ) {
			$args['currency'] = get_woocommerce_currency();
		}

		$payout = new Payout();
		$payout->fill( $args );

		return $payout->save();
	}

	/**
	 * Update a payout.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Payout id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$payout = Payout::find( (int) $object_id );

		if ( null !== $payout ) {
			$payout->fill( $fields )->save();
		}

		return (int) $object_id;
	}

	/**
	 * Get a payout.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Payout id.
	 *
	 * @return Payout|null
	 */
	public function get_object_by_id( $object_id ) {
		return Payout::find( (int) $object_id );
	}

	/**
	 * Create a payout and return the model.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Payout fields.
	 *
	 * @return Payout|null
	 */
	public function create_and_get_model( array $args = [] ): ?Payout {
		return $this->get_object_by_id( $this->create( $args ) );
	}
}
