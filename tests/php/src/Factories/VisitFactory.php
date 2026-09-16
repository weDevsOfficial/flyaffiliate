<?php
/**
 * Visit fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use FlyAffiliate\Models\Visit;
use WP_UnitTest_Factory_For_Thing;

/**
 * Creates visit rows for tests.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VisitFactory extends WP_UnitTest_Factory_For_Thing {

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
			'url'       => 'https://example.org/shop/',
			'referrer'  => 'https://example.com/blog/',
			'converted' => 0,
		];
	}

	/**
	 * Create a visit row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Visit fields.
	 *
	 * @return int The visit id.
	 */
	public function create_object( $args ) {
		if ( empty( $args['affiliate_id'] ) ) {
			$args['affiliate_id'] = $this->factory->affiliate->create();
		}

		// Hashes, never raw values (ADR-0007).
		$args['ip_hash']         = $args['ip_hash'] ?? wp_hash( '203.0.113.1' );
		$args['user_agent_hash'] = $args['user_agent_hash'] ?? wp_hash( 'PHPUnit' );

		$visit = new Visit();
		$visit->fill( $args );

		return $visit->save();
	}

	/**
	 * Update a visit.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Visit id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$visit = Visit::find( (int) $object_id );

		if ( null !== $visit ) {
			$visit->fill( $fields )->save();
		}

		return (int) $object_id;
	}

	/**
	 * Get a visit.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Visit id.
	 *
	 * @return Visit|null
	 */
	public function get_object_by_id( $object_id ) {
		return Visit::find( (int) $object_id );
	}
}
