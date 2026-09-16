<?php
/**
 * REST endpoint for the setup wizard.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\SetupWizard;
use FlyAffiliate\REST\AdminBaseController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /setup/complete` records that the wizard was finished or skipped.
 *
 * The wizard's settings go through the settings endpoint; this is the one
 * thing it needs that is not a setting.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SetupController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'setup';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/complete',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'complete' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Mark the wizard done.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function complete( $request ) {
		SetupWizard::mark_done();

		return $this->prepare_item_for_response( [ 'done' => true ], $request );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $item    The state.
	 * @param WP_REST_Request      $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$response = rest_ensure_response( $item );
		$response->add_links( $this->prepare_links( $item ) );

		return $response;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $item The state.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function prepare_links( $item ): array {
		return [
			'settings' => [
				'href' => rest_url( $this->namespace . '/settings' ),
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'flyaffiliate_setup',
			'type'       => 'object',
			'properties' => [
				'done' => [
					'description' => __( 'Whether the setup wizard has been completed or dismissed.', 'flyaffiliate' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			],
		];
	}
}
