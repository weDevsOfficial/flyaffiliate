<?php
/**
 * Referral link fixtures.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use FlyAffiliate\Models\ReferralLink;
use WP_UnitTest_Factory_For_Thing;
use WP_UnitTest_Generator_Sequence;

/**
 * Creates referral links for tests, through the manager so the URL is
 * normalized the way the affiliate's own saves are.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ReferralLinkFactory extends WP_UnitTest_Factory_For_Thing {

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
			'url' => new WP_UnitTest_Generator_Sequence( '/shop/product-%s/' ),
		];
	}

	/**
	 * Create a referral link.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args `affiliate_id`, `url`, and optionally `created_at`.
	 *
	 * @return int|\WP_Error The link id.
	 */
	public function create_object( $args ) {
		if ( empty( $args['affiliate_id'] ) ) {
			$args['affiliate_id'] = $this->factory->affiliate->create();
		}

		$link = flyaffiliate()->referral_link->create( (int) $args['affiliate_id'], (string) $args['url'] );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		if ( ! empty( $args['created_at'] ) ) {
			$link->set( 'created_at', (string) $args['created_at'] )->save();
		}

		return $link->get_id();
	}

	/**
	 * Update a referral link.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int   $object_id Link id.
	 * @param array $fields    Fields to change.
	 *
	 * @return int
	 */
	public function update_object( $object_id, $fields ) {
		$link = ReferralLink::find( (int) $object_id );

		if ( null !== $link ) {
			$link->fill( $fields )->save();
		}

		return (int) $object_id;
	}

	/**
	 * Get a referral link.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $object_id Link id.
	 *
	 * @return ReferralLink|null
	 */
	public function get_object_by_id( $object_id ) {
		return ReferralLink::find( (int) $object_id );
	}
}
