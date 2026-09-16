<?php
/**
 * The `wp flyaffiliate seed` command.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use WP_CLI;

/**
 * Generates sample affiliates, commissions and visits for a development site.
 *
 * This is the only place sample data is ever created. It is resolved only under
 * WP-CLI, it refuses to run on a production site, and nothing about it touches
 * an ordinary request. The prototype seeded on `admin_init`; that is exactly
 * what WordPress.org rejects.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Seeder implements Hookable {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'flyaffiliate seed', [ $this, 'seed' ] );
		}
	}

	/**
	 * Create sample data.
	 *
	 * ## OPTIONS
	 *
	 * [--affiliates=<number>]
	 * : How many affiliates to create. Default 5.
	 *
	 * [--commissions=<number>]
	 * : How many commissions to spread across them. Default 30.
	 *
	 * [--visits=<number>]
	 * : How many visits to spread across them. Default 60.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flyaffiliate seed --affiliates=10 --commissions=100
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( 'production' === wp_get_environment_type() ) {
			WP_CLI::error( 'Refusing to seed sample data on a production site. Set WP_ENVIRONMENT_TYPE to development or staging.' );
		}

		WP_CLI::confirm( 'Create sample affiliates, commissions and visits on this site?', $assoc_args );

		$affiliate_count  = max( 1, (int) ( $assoc_args['affiliates'] ?? 5 ) );
		$commission_count = max( 0, (int) ( $assoc_args['commissions'] ?? 30 ) );
		$visit_count      = max( 0, (int) ( $assoc_args['visits'] ?? 60 ) );
		$statuses         = [ Affiliate::STATUS_ACTIVE, Affiliate::STATUS_ACTIVE, Affiliate::STATUS_ACTIVE, Affiliate::STATUS_PENDING, Affiliate::STATUS_INACTIVE ];
		$affiliate_ids    = [];

		for ( $i = 1; $i <= $affiliate_count; $i++ ) {
			$email   = sprintf( 'affiliate%d@example.test', wp_rand( 1000, 999999 ) );
			$user_id = wp_insert_user(
				[
					'user_login' => sanitize_user( strstr( $email, '@', true ), true ),
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24, true, true ),
					'first_name' => 'Sample',
					'last_name'  => 'Affiliate ' . $i,
					'role'       => 'subscriber',
				]
			);

			if ( is_wp_error( $user_id ) ) {
				continue;
			}

			$affiliate = flyaffiliate()->affiliate->create(
				[
					'user_id'      => $user_id,
					'status'       => $statuses[ array_rand( $statuses ) ],
					'promo_method' => 'Sample data from wp flyaffiliate seed.',
				]
			);

			if ( ! is_wp_error( $affiliate ) ) {
				$affiliate_ids[] = $affiliate->get_id();
			}
		}

		if ( [] === $affiliate_ids ) {
			WP_CLI::error( 'No affiliates could be created.' );
		}

		$commission_statuses = [ Commission::STATUS_PENDING, Commission::STATUS_UNPAID, Commission::STATUS_UNPAID, Commission::STATUS_REJECTED ];

		$commissions_created = 0;
		$commission_failures = 0;

		for ( $i = 0; $i < $commission_count; $i++ ) {
			$base = wp_rand( 2000, 50000 ) / 100;
			$days = wp_rand( 0, 60 );

			$created = flyaffiliate()->commission->create_manual(
				[
					'affiliate_id' => $affiliate_ids[ array_rand( $affiliate_ids ) ],
					'base_amount'  => $base,
					'amount'       => round( $base * wp_rand( 5, 20 ) / 100, 2 ),
					'order_id'     => wp_rand( 1000, 9999 ),
					'status'       => $commission_statuses[ array_rand( $commission_statuses ) ],
					'created_at'   => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				]
			);

			if ( is_wp_error( $created ) ) {
				// One warning is enough; the cause is the same for every row.
				if ( 0 === $commission_failures ) {
					WP_CLI::warning( 'Could not create a commission: ' . $created->get_error_message() );
				}

				++$commission_failures;
				continue;
			}

			++$commissions_created;
		}

		$pages = [ '/shop/', '/product/sample-hoodie/', '/product/sample-mug/', '/blog/why-we-love-this/', '/' ];
		$refs  = [ '', 'https://example.com/review', 'https://social.example/post/1', 'https://newsletter.example/issue-7' ];

		for ( $i = 0; $i < $visit_count; $i++ ) {
			$visit = flyaffiliate()->tracking->create(
				[
					'affiliate_id' => $affiliate_ids[ array_rand( $affiliate_ids ) ],
					'url'          => home_url( $pages[ array_rand( $pages ) ] ),
					'referrer'     => $refs[ array_rand( $refs ) ],
					'ip'           => '203.0.113.' . wp_rand( 1, 254 ),
					'user_agent'   => 'Sample/1.0',
					'created_at'   => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 60 ) * DAY_IN_SECONDS ),
				]
			);

			if ( null !== $visit && 0 === wp_rand( 0, 3 ) ) {
				flyaffiliate()->tracking->mark_converted( $visit->get_id(), wp_rand( 1000, 9999 ) );
			}
		}

		if ( $commission_failures > 0 ) {
			WP_CLI::warning( sprintf( '%d commission(s) could not be created.', $commission_failures ) );
		}

		WP_CLI::success( sprintf( 'Created %d affiliates, %d commissions and %d visits.', count( $affiliate_ids ), $commissions_created, $visit_count ) );
	}
}
