<?php
/**
 * Settings REST controller.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Manager as Settings;
use FlyAffiliate\REST\AdminBaseController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `flyaffiliate/v1/settings` — the flat settings schema with values, and
 * per-page saves.
 *
 * `GET /settings` returns every element the admin UI renders. `PUT
 * /settings/{scope}` saves the fields under one page or subpage; the keys may
 * be field ids or the dot paths plugin-ui emits.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SettingsController extends AdminBaseController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * The settings service.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * Construct the controller.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Settings|null $settings The settings service; resolved from the container when omitted.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? flyaffiliate()->settings;
	}

	/**
	 * Register the routes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scope>[a-z0-9_-]+)',
			[
				'args'   => [
					'scope' => [
						'description' => __( 'The page or subpage whose fields are being saved.', 'flyaffiliate' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => [
						'values' => [
							'description' => __( 'Field id (or dot path) => value.', 'flyaffiliate' ),
							'type'        => 'object',
							'required'    => true,
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * The whole schema, values populated.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		return rest_ensure_response( $this->get_schema_response() );
	}

	/**
	 * Save one page's or subpage's fields.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$values = $request->get_param( 'values' );

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'flyaffiliate_rest_invalid_values', __( 'Values must be an object.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$result = $this->settings->save( $values, (string) $request['scope'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->get_schema_response() );
	}

	/**
	 * The schema as the UI receives it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_schema_response(): array {
		/**
		 * Filters the settings schema the REST API returns.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<int, array<string, mixed>> $schema Flat elements with values.
		 */
		return apply_filters( 'flyaffiliate_rest_settings_schema', $this->settings->get_registry()->get_public_schema() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'flyaffiliate_settings_element',
			'type'    => 'object',
			'properties' => [
				'id'      => [
					'description' => __( 'Element id. For a field this is also its storage key.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'type'    => [
					'description' => __( 'Element type: page, subpage, tab, section, subsection, fieldgroup or field.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'variant' => [
					'description' => __( 'The input a field renders as.', 'flyaffiliate' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'value'   => [
					'description' => __( 'The stored value, or the default.', 'flyaffiliate' ),
					'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
					'context'     => [ 'view', 'edit' ],
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
