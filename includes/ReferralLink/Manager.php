<?php
/**
 * Referral link data access.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\ReferralLink;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\ReferralLink;
use FlyAffiliate\Models\Visit;
use FlyAffiliate\Tracking\Tracker;
use WP_Error;

/**
 * Saves, lists and removes the referral links an affiliate generates.
 *
 * A saved link is a bookmark. `Tracking\Tracker` attributes a visit by the
 * referral variable alone, on any page, and the cookie carries the affiliate
 * and the visit, never the link. Removing a link from the list therefore stops
 * nothing: a link already shared keeps tracking and earning. Nothing about a
 * link's traffic is stored on it either; {@see self::get_stats()} counts the
 * visits whose landing page is the link's page, so removing a link and saving
 * it again brings its history back.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'flyaffiliate_affiliate_deleted', [ $this, 'delete_for_affiliate' ] );
	}

	/**
	 * Get a referral link by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $link_id Referral link id.
	 *
	 * @return ReferralLink|null
	 */
	public function get( int $link_id ): ?ReferralLink {
		return ReferralLink::find( $link_id );
	}

	/**
	 * Query referral links.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::query()}.
	 *
	 * @return ReferralLink[]
	 */
	public function query( array $args = [] ): array {
		return ReferralLink::query( $args );
	}

	/**
	 * Count referral links.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::count()}.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		return ReferralLink::count( $args );
	}

	/**
	 * The affiliate's saved link to a page, if there is one.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $affiliate_id The affiliate.
	 * @param string $url          The page, in any form {@see self::normalize_url()} accepts.
	 *
	 * @return ReferralLink|null
	 */
	public function find_by_url( int $affiliate_id, string $url ): ?ReferralLink {
		$url = $this->normalize_url( $url );

		if ( is_wp_error( $url ) ) {
			return null;
		}

		return ReferralLink::find_by(
			[
				'affiliate_id' => $affiliate_id,
				'url_hash'     => md5( $url ),
			]
		);
	}

	/**
	 * Save a page of this site as one of an affiliate's referral links.
	 *
	 * Saving the same page twice returns the link saved the first time.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int    $affiliate_id The affiliate.
	 * @param string $url          The page, in any form {@see self::normalize_url()} accepts.
	 *
	 * @return ReferralLink|WP_Error
	 */
	public function create( int $affiliate_id, string $url ) {
		if ( null === flyaffiliate()->affiliate->get( $affiliate_id ) ) {
			return new WP_Error( 'flyaffiliate_referral_link_no_affiliate', __( 'No affiliate with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		$url = $this->normalize_url( $url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$conditions = [
			'affiliate_id' => $affiliate_id,
			'url_hash'     => md5( $url ),
		];

		$existing = ReferralLink::find_by( $conditions );

		if ( null !== $existing ) {
			return $existing;
		}

		$link = new ReferralLink();
		$link->fill( array_merge( $conditions, [ 'url' => $url ] ) );

		if ( 0 === $link->save() ) {
			// Two saves of the same page at once: the unique key let the other one in.
			return ReferralLink::find_by( $conditions ) ?? new WP_Error( 'flyaffiliate_referral_link_not_saved', __( 'The link could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
		}

		/**
		 * Fires after an affiliate saves a referral link.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param ReferralLink $link         The saved link.
		 * @param int          $affiliate_id The affiliate.
		 */
		do_action( 'flyaffiliate_referral_link_created', $link, $affiliate_id );

		return $link;
	}

	/**
	 * Remove a referral link from its affiliate's list.
	 *
	 * The link keeps working wherever it was shared, and its visits and
	 * commissions are untouched.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $link_id Referral link id.
	 *
	 * @return bool
	 */
	public function delete( int $link_id ): bool {
		$link = $this->get( $link_id );

		if ( null === $link ) {
			return false;
		}

		// The model empties itself on delete; listeners get what it was.
		$snapshot = clone $link;
		$deleted  = $link->delete();

		if ( $deleted ) {
			/**
			 * Fires after a referral link is removed from its affiliate's list.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param int          $link_id The id of the removed link.
			 * @param ReferralLink $link    The link as it was before removal.
			 */
			do_action( 'flyaffiliate_referral_link_deleted', $link_id, $snapshot );
		}

		return $deleted;
	}

	/**
	 * Remove every referral link of a deleted affiliate.
	 *
	 * Unlike commissions and visits, a saved link is not a financial record;
	 * with its affiliate gone there is no list to show it in.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $affiliate_id The deleted affiliate.
	 *
	 * @return void
	 */
	public function delete_for_affiliate( int $affiliate_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlyAffiliate's own custom table; no WordPress API reaches it, and delete() prepares the value.
		$wpdb->delete( ReferralLink::get_table(), [ 'affiliate_id' => $affiliate_id ], [ '%d' ] );
	}

	/**
	 * The form a page is saved in: on the home URL's origin, without the
	 * referral variable.
	 *
	 * Accepts what the dashboard's generator accepts: a full URL, a path
	 * starting with `/`, or a URL without a scheme (`example.com/shop/`). The
	 * page must be on this site, `www.` or not, and inside the directory
	 * WordPress lives in; a link anywhere else could never be tracked.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $url The page.
	 *
	 * @return string|WP_Error
	 */
	public function normalize_url( string $url ) {
		$url  = trim( $url );
		$home = wp_parse_url( home_url( '/' ) );

		if ( '' === $url ) {
			return new WP_Error( 'flyaffiliate_referral_link_empty', __( 'Paste a link from this site first.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$scheme = $home['scheme'] ?? 'http';
		$origin = $scheme . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		if ( str_starts_with( $url, '//' ) ) {
			$url = $scheme . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = $origin . $url;
		} elseif ( 1 !== preg_match( '#^https?://#i', $url ) ) {
			$url = $scheme . '://' . $url;
		}

		$parts     = wp_parse_url( $url );
		$path      = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
		$home_path = trailingslashit( $home['path'] ?? '/' );

		if (
			! is_array( $parts )
			|| ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true )
			|| $this->bare_host( $parts['host'] ?? '' ) !== $this->bare_host( $home['host'] ?? '' )
			|| ! str_starts_with( trailingslashit( $path ), $home_path )
		) {
			return new WP_Error( 'flyaffiliate_referral_link_foreign', __( 'Only links to pages of this website can earn a commission.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$url = $origin . $path
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );

		return esc_url_raw( remove_query_arg( Tracker::get_variable(), $url ) );
	}

	/**
	 * The landing URL a visit through this link is recorded under.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReferralLink $link The link.
	 *
	 * @return string
	 */
	public function get_landing_url( ReferralLink $link ): string {
		$parts   = wp_parse_url( (string) $link->get( 'url', '' ) );
		$request = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		// The same two steps a visit's URL goes through: Tracker, then Tracking\Manager::create().
		return esc_url_raw( Tracker::to_landing_url( $request, Tracker::get_variable() ) );
	}

	/**
	 * Visits, conversions and the latest visit of each link, one query per affiliate.
	 *
	 * A visit belongs to a link when it is the link's affiliate's and landed on
	 * the link's page, whichever way the visitor reached it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReferralLink[] $links The links.
	 *
	 * @return array<int, array{visits: int, conversions: int, last_visit_at: string|null}> Keyed by link id.
	 */
	public function get_stats( array $links ): array {
		global $wpdb;

		$stats    = [];
		$landings = [];

		foreach ( $links as $link ) {
			$stats[ $link->get_id() ] = [
				'visits'        => 0,
				'conversions'   => 0,
				'last_visit_at' => null,
			];

			$landings[ (int) $link->get( 'affiliate_id' ) ][ $link->get_id() ] = $this->get_landing_url( $link );
		}

		$table = Visit::get_table();

		foreach ( $landings as $affiliate_id => $urls ) {
			$values       = array_values( array_unique( $urls ) );
			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FlyAffiliate's own table; the interpolations are its name and a list of %s placeholders whose values are passed to prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT url, COUNT(*) AS visits, COALESCE( SUM( converted ), 0 ) AS conversions, MAX( created_at ) AS last_visit_at
					FROM {$table}
					WHERE affiliate_id = %d AND url IN ( {$placeholders} )
					GROUP BY url",
					array_merge( [ $affiliate_id ], $values )
				)
			);
			// phpcs:enable

			// MySQL compares the URLs under the column's collation, usually
			// case-insensitive, so the rows are matched back the same way.
			$totals = [];

			foreach ( (array) $rows as $row ) {
				$key  = strtolower( (string) $row->url );
				$last = (string) $row->last_visit_at;

				$totals[ $key ] = [
					'visits'        => ( $totals[ $key ]['visits'] ?? 0 ) + (int) $row->visits,
					'conversions'   => ( $totals[ $key ]['conversions'] ?? 0 ) + (int) $row->conversions,
					'last_visit_at' => max( $totals[ $key ]['last_visit_at'] ?? '', $last ),
				];
			}

			foreach ( $urls as $link_id => $url ) {
				$key = strtolower( $url );

				if ( isset( $totals[ $key ] ) ) {
					$stats[ $link_id ] = [
						'visits'        => $totals[ $key ]['visits'],
						'conversions'   => $totals[ $key ]['conversions'],
						'last_visit_at' => '' !== $totals[ $key ]['last_visit_at'] ? $totals[ $key ]['last_visit_at'] : null,
					];
				}
			}
		}

		return $stats;
	}

	/**
	 * A host without a leading `www.`, so www and bare domains match.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $host A hostname.
	 *
	 * @return string
	 */
	protected function bare_host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}
}
