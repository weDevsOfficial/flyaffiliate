<?php
/**
 * The settings registry.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Repository\SettingsRepositoryInterface;

/**
 * Collects, processes and serves the settings schema.
 *
 * In order: collect the flat schema, generate a `hook_key` for every element,
 * fire that key as a filter on structural nodes so extensions can inject
 * children, fill default properties, populate field values from storage, and
 * validate the result while `WP_DEBUG` is on.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SettingsRegistry {

	/**
	 * Element types that can hold children, mapped to the pointer their children carry.
	 *
	 * @var array<string, string>
	 */
	const PARENT_POINTERS = [
		'page'       => 'page_id',
		'subpage'    => 'subpage_id',
		'tab'        => 'tab_id',
		'section'    => 'section_id',
		'subsection' => 'subsection_id',
		'fieldgroup' => 'field_group_id',
	];

	/**
	 * Schema keys that hold PHP callables and must never leave PHP.
	 *
	 * @var string[]
	 */
	const PRIVATE_KEYS = [ 'sanitize_callback', 'validate_callback' ];

	/**
	 * The processed schema, or null before the first build.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $cache = null;

	/**
	 * Where values come from.
	 *
	 * @var SettingsRepositoryInterface
	 */
	private SettingsRepositoryInterface $repository;

	/**
	 * Construct the registry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param SettingsRepositoryInterface $repository Settings storage.
	 */
	public function __construct( SettingsRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * The fully processed schema, with values populated.
	 *
	 * Callables stay in this copy so the sanitizer can use them; call
	 * `get_public_schema()` for anything that leaves PHP.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param bool $force_refresh Rebuild even when cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_schema( bool $force_refresh = false ): array {
		if ( null !== $this->cache && ! $force_refresh ) {
			return $this->cache;
		}

		$elements = SettingsSchema::get_schema();
		$elements = $this->generate_keys( $elements );
		$elements = $this->fire_hook_key_filters( $elements );
		$elements = $this->fill_defaults( $elements );
		$elements = $this->populate_values( $elements );
		$elements = $this->resolve_dynamic_options( $elements );

		$this->maybe_validate( $elements );

		$this->cache = array_values( $elements );

		return $this->cache;
	}

	/**
	 * The schema as the REST API and the admin UI receive it: no callables.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_public_schema(): array {
		return array_map(
			static function ( array $element ): array {
				foreach ( self::PRIVATE_KEYS as $key ) {
					unset( $element[ $key ] );
				}

				return $element;
			},
			$this->get_schema()
		);
	}

	/**
	 * Every field element, keyed by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		$fields = [];

		foreach ( $this->get_schema() as $element ) {
			if ( 'field' === ( $element['type'] ?? '' ) && ! empty( $element['id'] ) ) {
				$fields[ $element['id'] ] = $element;
			}
		}

		return $fields;
	}

	/**
	 * The fields under one page or subpage, keyed by id.
	 *
	 * The scope may be a page id or a subpage id: plugin-ui saves per subpage
	 * when one is active and per page otherwise.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $scope_id A page or subpage id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields_in_scope( string $scope_id ): array {
		$descendants = $this->collect_descendants( $this->get_schema(), $scope_id );
		$fields      = [];

		foreach ( $this->get_fields() as $id => $field ) {
			if ( in_array( $id, $descendants, true ) ) {
				$fields[ $id ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Forget the processed schema so the next read rebuilds it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->cache = null;
	}

	/**
	 * Every element id below a node, recursively.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements  Flat schema.
	 * @param string                           $parent_id The node to start from.
	 *
	 * @return string[]
	 */
	public function collect_descendants( array $elements, string $parent_id ): array {
		$ids   = [];
		$queue = [ $parent_id ];

		while ( [] !== $queue ) {
			$current = array_shift( $queue );

			foreach ( $elements as $element ) {
				$id = $element['id'] ?? '';

				if ( '' === $id || in_array( $id, $ids, true ) ) {
					continue;
				}

				foreach ( self::PARENT_POINTERS as $pointer ) {
					if ( ( $element[ $pointer ] ?? '' ) === $current ) {
						$ids[]   = $id;
						$queue[] = $id;
						break;
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Give every element a `hook_key` built from its position in the tree.
	 *
	 * The key reads `flyaffiliate_settings_{page}_{subpage}_{section}_{field}_children`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function generate_keys( array $elements ): array {
		$lookup = [];

		foreach ( $elements as $element ) {
			if ( isset( $element['id'], $element['type'] ) ) {
				$lookup[ $element['type'] . ':' . $element['id'] ] = $element;
			}
		}

		foreach ( $elements as &$element ) {
			if ( ! empty( $element['hook_key'] ) || empty( $element['id'] ) ) {
				continue;
			}

			$path    = [ $element['id'] ];
			$current = $element;

			// Walk up at most ten levels; a real tree is four deep.
			for ( $depth = 0; $depth < 10; $depth++ ) {
				$parent = $this->find_parent( $current, $lookup );

				if ( null === $parent ) {
					break;
				}

				array_unshift( $path, $parent['id'] );
				$current = $parent;

				if ( 'page' === ( $current['type'] ?? '' ) ) {
					break;
				}
			}

			$element['hook_key'] = 'flyaffiliate_settings_' . implode( '_', $path ) . '_children';
		}
		unset( $element );

		return $elements;
	}

	/**
	 * The parent of an element, resolved through its pointer.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed>                $element The element.
	 * @param array<string, array<string, mixed>> $lookup  `type:id` => element.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_parent( array $element, array $lookup ): ?array {
		foreach ( self::PARENT_POINTERS as $type => $pointer ) {
			if ( empty( $element[ $pointer ] ) ) {
				continue;
			}

			$key = $type . ':' . $element[ $pointer ];

			// A pointer names a parent that is not declared: still climb by id
			// so the hook key stays stable.
			return $lookup[ $key ] ?? [
				'id'   => $element[ $pointer ],
				'type' => $type,
			];
		}

		return null;
	}

	/**
	 * Let extensions inject children under every structural node.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fire_hook_key_filters( array $elements ): array {
		foreach ( $elements as $element ) {
			$type = $element['type'] ?? '';

			if ( ! isset( self::PARENT_POINTERS[ $type ] ) || empty( $element['hook_key'] ) ) {
				continue;
			}

			$pointer  = self::PARENT_POINTERS[ $type ];
			$children = array_values(
				array_filter(
					$elements,
					static fn( $candidate ) => ( $candidate[ $pointer ] ?? '' ) === $element['id']
				)
			);

			/**
			 * Filters the children of one structural settings node.
			 *
			 * The hook name is dynamic: `flyaffiliate_settings_{path}_children`,
			 * e.g. `flyaffiliate_settings_commission_rates_rate_settings_children`.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param array<int, array<string, mixed>> $children The node's current children.
			 * @param array<string, mixed>             $node     The node itself.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- generate_keys() builds every hook_key with the flyaffiliate_settings_ prefix.
			$filtered = apply_filters( $element['hook_key'], $children, $element );

			if ( ! is_array( $filtered ) ) {
				continue;
			}

			$existing = array_column( $children, 'id' );

			foreach ( $filtered as $child ) {
				if ( is_array( $child ) && isset( $child['id'] ) && ! in_array( $child['id'], $existing, true ) ) {
					$elements[] = $child;
				}
			}
		}

		return $elements;
	}

	/**
	 * Give every element the properties the UI expects, even when the schema omitted them.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fill_defaults( array $elements ): array {
		$field_defaults = [
			'display'      => true,
			'dependencies' => [],
			'validations'  => [],
			'readonly'     => false,
			'disabled'     => false,
		];

		$structural_defaults = [
			'display'      => true,
			'dependencies' => [],
		];

		foreach ( $elements as &$element ) {
			$defaults = 'field' === ( $element['type'] ?? '' ) ? $field_defaults : $structural_defaults;

			foreach ( $defaults as $key => $value ) {
				if ( ! array_key_exists( $key, $element ) ) {
					$element[ $key ] = $value;
				}
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Fill each field's `value` from storage, falling back to its default.
	 *
	 * A field that already declares a value (a read-only display field) keeps it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function populate_values( array $elements ): array {
		$stored = $this->repository->all();

		foreach ( $elements as &$element ) {
			if ( 'field' !== ( $element['type'] ?? '' ) || array_key_exists( 'value', $element ) || empty( $element['id'] ) ) {
				continue;
			}

			$element['value'] = array_key_exists( $element['id'], $stored ) ? $stored[ $element['id'] ] : ( $element['default'] ?? '' );
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Turn lazily declared option lists into arrays.
	 *
	 * An expensive option list (pages from the database, say) is declared as a
	 * closure so the query only runs when the schema is built for the UI.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_dynamic_options( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( isset( $element['options'] ) && $element['options'] instanceof \Closure ) {
				$element['options'] = (array) call_user_func( $element['options'] );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Validate the schema while debugging, logging every problem found.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return void
	 */
	private function maybe_validate( array $elements ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$result = ( new SchemaValidator() )->validate( $elements );

		foreach ( array_merge( $result['errors'], $result['warnings'] ) as $message ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only schema diagnostics.
			error_log( 'FlyAffiliate settings schema: ' . $message );
		}
	}
}
