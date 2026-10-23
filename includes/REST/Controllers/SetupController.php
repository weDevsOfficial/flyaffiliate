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
 * The wizard's settings go through the settings endpoint; this takes the
 * two things it needs that are not settings: the done flag, and Share
 * Essentials, the Appsero opt-in Dokan Lite's wizard asks for.
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
					'args'                => [
						'share_essentials' => [
							'description' => __( 'Whether to share usage data with Appsero. Left out, the current choice stands.', 'flyaffiliate' ),
							'type'        => 'boolean',
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Mark the wizard done, and opt in to Appsero or out of it as Share
	 * Essentials says.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function complete( $request ) {
		SetupWizard::mark_done();

		if ( $request->has_param( 'share_essentials' ) ) {
			if ( $request->get_param( 'share_essentials' ) ) {
				flyaffiliate()->tracker->optin();
			} else {
				flyaffiliate()->tracker->optout();
			}
		}

		return $this->prepare_item_for_response(
			[
				'done'             => true,
				'share_essentials' => flyaffiliate()->tracker->is_allowed(),
			],
			$request
		);
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
				'done'             => [
					'description' => __( 'Whether the setup wizard has been completed or dismissed.', 'flyaffiliate' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'share_essentials' => [
					'description' => __( 'Whether the site shares usage data with Appsero.', 'flyaffiliate' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			],
		];
	}
}
