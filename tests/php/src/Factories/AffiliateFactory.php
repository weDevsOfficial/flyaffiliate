<?php
/**
 * Affiliate fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use FlyAffiliate\Models\Affiliate;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates affiliates for tests.
 *
 * Creates the WordPress user too, unless one is passed: an affiliate without a
 * user is not a state the plugin allows, so a factory must not produce one.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AffiliateFactory extends WP_UnitTest_Factory_For_Thing {

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
			'status'        => Affiliate::STATUS_ACTIVE,
			'payment_email' => '',
			'promo_method'  => 'Test promotion plan.',
		];
	}

	/**
	 * Create an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Affiliate fields. `user_id` is created when absent.
	 *
	 * @return int The affiliate id.
	 */
	public function create_object( $args ) {
		if ( empty( $args['user_id'] ) ) {
			$args['user_id'] = $this->factory->user->create( [ 'role' => 'customer' ] );
		}

		$affiliate = flyaffiliate()->affiliate->create( $args );

		if ( is_wp_error( $affiliate ) ) {
			return 0;
		}

		return $affiliate->get_id();
	}

	/**
	 * Update an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Affiliate id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		flyaffiliate()->affiliate->update( (int) $object_id, $fields );

		return (int) $object_id;
	}

	/**
	 * Get an affiliate.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Affiliate id.
	 *
	 * @return Affiliate|null
	 */
	public function get_object_by_id( $object_id ) {
		return flyaffiliate()->affiliate->get( (int) $object_id );
	}

	/**
	 * Create an affiliate and return the model.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Affiliate fields.
	 *
	 * @return Affiliate|null
	 */
	public function create_and_get_model( array $args = [] ): ?Affiliate {
		return $this->get_object_by_id( $this->create( $args ) );
	}
}
