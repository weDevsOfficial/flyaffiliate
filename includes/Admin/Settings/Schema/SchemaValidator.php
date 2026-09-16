<?php
/**
 * Structural validation of the settings schema.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a flat schema for the mistakes that silently break the UI: missing
 * ids, unknown types, dangling parent pointers, duplicate field ids and
 * dependencies on fields that do not exist.
 *
 * @since FLYAFFILIATE_SINCE
 */
class SchemaValidator {

	/**
	 * Element types the UI knows how to render.
	 *
	 * @var string[]
	 */
	const TYPES = [ 'page', 'subpage', 'tab', 'section', 'subsection', 'field', 'fieldgroup' ];

	/**
	 * Validate a flat schema.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<int, array<string, mixed>> $elements Flat schema.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate( array $elements ): array {
		$errors   = [];
		$warnings = [];
		$ids      = [];
		$fields   = [];

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				$errors[] = sprintf( 'Element #%d is not an array.', $index );
				continue;
			}

			$id   = (string) ( $element['id'] ?? '' );
			$type = (string) ( $element['type'] ?? '' );

			if ( '' === $id ) {
				$errors[] = sprintf( 'Element #%d has no id.', $index );
				continue;
			}

			if ( ! in_array( $type, self::TYPES, true ) ) {
				$errors[] = sprintf( 'Element "%s" has unknown type "%s".', $id, $type );
			}

			$ids[ $type . ':' . $id ] = $element;

			if ( 'field' !== $type ) {
				continue;
			}

			if ( isset( $fields[ $id ] ) ) {
				$errors[] = sprintf( 'Field id "%s" is declared more than once. Field ids are storage keys and must be unique.', $id );
			}

			$fields[ $id ] = $element;

			if ( empty( $element['variant'] ) ) {
				$errors[] = sprintf( 'Field "%s" has no variant.', $id );
			}

			if ( empty( $element['readonly'] ) && ! array_key_exists( 'default', $element ) ) {
				$warnings[] = sprintf( 'Field "%s" has no default.', $id );
			}

			foreach ( SettingsRegistry::PARENT_POINTERS as $pointer ) {
				if ( ! empty( $element[ $pointer ] ) ) {
					continue 2;
				}
			}

			$errors[] = sprintf( 'Field "%s" has no parent pointer.', $id );
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}

			foreach ( SettingsRegistry::PARENT_POINTERS as $parent_type => $pointer ) {
				if ( empty( $element[ $pointer ] ) ) {
					continue;
				}

				// A section may sit under a page or a subpage; a field under a
				// section or a subsection. Accept any structural parent.
				$found = false;

				foreach ( array_keys( SettingsRegistry::PARENT_POINTERS ) as $candidate_type ) {
					if ( isset( $ids[ $candidate_type . ':' . $element[ $pointer ] ] ) ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$errors[] = sprintf( 'Element "%s" points at %s "%s", which is not declared.', $element['id'], $parent_type, $element[ $pointer ] );
				}
			}

			foreach ( (array) ( $element['dependencies'] ?? [] ) as $dependency ) {
				$key = (string) ( $dependency['key'] ?? '' );

				if ( '' !== $key && false === strpos( $key, '.' ) && ! isset( $fields[ $key ] ) ) {
					$errors[] = sprintf( 'Element "%s" depends on field "%s", which is not declared.', $element['id'], $key );
				}
			}
		}

		return [
			'valid'    => [] === $errors,
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}
}
