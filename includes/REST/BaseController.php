<?php
/**
 * Base REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared behaviour for FlyAffiliate's REST controllers.
 *
 * A subclass says who may call it by implementing a permission callback; there
 * is no default that lets anyone through. `__return_true` is never an acceptable
 * `permission_callback` in this codebase.
 *
 * @since 1.0.0
 */
abstract class BaseController extends WP_REST_Controller {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $namespace = Manager::NAMESPACE;

	/**
	 * Wrap a list of items in a response carrying the pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items    Prepared items.
	 * @param int   $total    Total number of matching rows.
	 * @param int   $per_page Rows per page.
	 *
	 * @return WP_REST_Response
	 */
	protected function prepare_collection_response( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = rest_ensure_response( $items );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 1 ) );

		return $response;
	}

	/**
	 * The request's day range as GMT datetimes: the start of the first day and
	 * the end of the last, in the site's timezone.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array{0: string, 1: string} After and before, each empty when unset.
	 */
	protected function get_date_bounds( WP_REST_Request $request ): array {
		$bound = static function ( $day, string $time ): string {
			$day = (string) $day;

			return 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $day ) ? get_gmt_from_date( $day . ' ' . $time, 'Y-m-d H:i:s' ) : '';
		};

		return [
			$bound( $request['after'] ?? '', '00:00:00' ),
			$bound( $request['before'] ?? '', '23:59:59' ),
		];
	}

	/**
	 * The date range every dashboard route accepts.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function get_date_params(): array {
		return [
			'after'  => [
				'description' => __( 'The first day of the range, as Y-m-d in the site timezone.', 'flyaffiliate' ),
				'type'        => 'string',
				'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
			],
			'before' => [
				'description' => __( 'The last day of the range, as Y-m-d in the site timezone.', 'flyaffiliate' ),
				'type'        => 'string',
				'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
			],
		];
	}

	/**
	 * The `context`, `page`, `per_page` and `search` parameters every collection takes.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		unset( $params['search'] );

		return $params;
	}

	/**
	 * Add the standard `self` and `collection` links to a response.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Response $response The response.
	 * @param array            $links    Prepared links.
	 *
	 * @return WP_REST_Response
	 */
	protected function add_links( WP_REST_Response $response, array $links ): WP_REST_Response {
		$response->add_links( $links );

		return $response;
	}

	/**
	 * The `_fields` filter, applied for the caller's context.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $data    Prepared data.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array
	 */
	protected function filter_response_fields( array $data, WP_REST_Request $request ): array {
		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return $data;
	}

	/**
	 * A stored GMT datetime as RFC 3339, or null when there is none.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value The stored `Y-m-d H:i:s` value, in GMT.
	 *
	 * @return string|null
	 */
	protected function prepare_date( $value ): ?string {
		return empty( $value ) ? null : mysql_to_rfc3339( (string) $value );
	}

	/**
	 * An affiliate, loaded once per request: a page of commissions, visits or
	 * payments names the same few affiliates on many rows.
	 *
	 * @since 1.0.0
	 *
	 * @param int $affiliate_id The affiliate.
	 *
	 * @return \FlyAffiliate\Models\Affiliate|null Null when there is no such affiliate.
	 */
	protected function get_affiliate( int $affiliate_id ): ?\FlyAffiliate\Models\Affiliate {
		static $affiliates = [];

		if ( ! array_key_exists( $affiliate_id, $affiliates ) ) {
			$affiliates[ $affiliate_id ] = \FlyAffiliate\Models\Affiliate::find( $affiliate_id );
		}

		return $affiliates[ $affiliate_id ];
	}

	/**
	 * An affiliate's display name, or an empty string when they are gone.
	 *
	 * @since 1.0.0
	 *
	 * @param int $affiliate_id The affiliate.
	 *
	 * @return string
	 */
	protected function get_affiliate_name( int $affiliate_id ): string {
		$affiliate = $this->get_affiliate( $affiliate_id );

		return null === $affiliate ? '' : $affiliate->get_display_name();
	}
}
