<?php
/**
 * Visit data access.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Visit;

/**
 * Reads and records visits.
 *
 * The request-time tracker — query variable to cookie to visit row — is Phase 3
 * work and lives in `Tracker`. This class is what the screens, the controllers
 * and the tracker itself write and read through.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * Get a visit by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $visit_id Visit id.
	 *
	 * @return Visit|null
	 */
	public function get( int $visit_id ): ?Visit {
		return Visit::find( $visit_id );
	}

	/**
	 * Query visits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::query()}.
	 *
	 * @return Visit[]
	 */
	public function query( array $args = [] ): array {
		return Visit::query( $args );
	}

	/**
	 * Count visits.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::count()}.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		return Visit::count( $args );
	}

	/**
	 * Record a visit.
	 *
	 * The IP address and user agent are hashed here, on the way in, so no caller
	 * can store the raw values by mistake (ADR-0007).
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Visit data.
	 *
	 *     @type int    $affiliate_id Required.
	 *     @type string $url          The landing URL.
	 *     @type string $referrer     The HTTP referrer, or an empty string.
	 *     @type string $ip           The visitor's IP address. Stored hashed.
	 *     @type string $user_agent   The visitor's user agent. Stored hashed.
	 * }
	 *
	 * @return Visit|null The visit, or null when it could not be saved.
	 */
	public function create( array $args ): ?Visit {
		$affiliate_id = absint( $args['affiliate_id'] ?? 0 );

		if ( 0 === $affiliate_id ) {
			return null;
		}

		$visit = new Visit();

		$visit->fill(
			[
				'affiliate_id'    => $affiliate_id,
				'url'             => esc_url_raw( (string) ( $args['url'] ?? '' ) ),
				'referrer'        => esc_url_raw( (string) ( $args['referrer'] ?? '' ) ),
				'ip_hash'         => '' !== (string) ( $args['ip'] ?? '' ) ? wp_hash( (string) $args['ip'] ) : '',
				'user_agent_hash' => '' !== (string) ( $args['user_agent'] ?? '' ) ? wp_hash( (string) $args['user_agent'] ) : '',
				'converted'       => 0,
				'order_id'        => null,
				'created_at'      => ! empty( $args['created_at'] ) ? (string) $args['created_at'] : current_time( 'mysql', true ),
			]
		);

		return 0 === $visit->save() ? null : $visit;
	}

	/**
	 * Mark a visit as having led to an order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $visit_id Visit id.
	 * @param int $order_id The order.
	 *
	 * @return bool
	 */
	public function mark_converted( int $visit_id, int $order_id ): bool {
		$visit = $this->get( $visit_id );

		if ( null === $visit ) {
			return false;
		}

		$visit->set( 'converted', 1 );
		$visit->set( 'order_id', $order_id );

		return $visit->save() > 0;
	}

	/**
	 * Row counts for the All / Converted / Not converted tabs.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Query arguments applied to every count.
	 *
	 * @return array{all: int, converted: int, not_converted: int}
	 */
	public function count_by_result( array $args = [] ): array {
		$where = (array) ( $args['where'] ?? [] );

		return [
			'all'           => $this->count( $args ),
			'converted'     => $this->count( array_merge( $args, [ 'where' => array_merge( $where, [ 'converted' => 1 ] ) ] ) ),
			'not_converted' => $this->count( array_merge( $args, [ 'where' => array_merge( $where, [ 'converted' => 0 ] ) ] ) ),
		];
	}

	/**
	 * The affiliate ids whose user matches a search term.
	 *
	 * Lets a visits list be filtered by affiliate name without joining the
	 * users table into the model's query.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $term The search term.
	 *
	 * @return int[] Affiliate ids. Empty when nothing matches, which callers turn into an empty result.
	 */
	public function find_affiliate_ids_by_user_search( string $term ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			return [];
		}

		$user_ids = get_users(
			[
				'search'         => '*' . $term . '*',
				'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
				'fields'         => 'ID',
				'number'         => 200,
			]
		);

		if ( [] === $user_ids ) {
			return [];
		}

		$affiliates = flyaffiliate()->affiliate->query(
			[
				'where'    => [ 'user_id' => array_map( 'intval', $user_ids ) ],
				'per_page' => -1,
			]
		);

		return array_map( static fn( $affiliate ) => $affiliate->get_id(), $affiliates );
	}
}
