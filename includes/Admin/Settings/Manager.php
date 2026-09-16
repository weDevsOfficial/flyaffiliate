<?php
/**
 * The settings service.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Settings\Repository\SettingsRepositoryInterface;
use FlyAffiliate\Admin\Settings\Schema\SettingsRegistry;
use WP_Error;

/**
 * Reads, validates, sanitizes and writes settings.
 *
 * The schema decides what may be stored: an unknown id is dropped, a
 * read-only field is never written, every value passes its field's rules and
 * its sanitizer. The REST controller and the setup wizard both save through
 * `save()`, so there is one path to the database.
 *
 * Read settings through `flyaffiliate_get_option()`, never `get_option()`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * The schema.
	 *
	 * @var SettingsRegistry
	 */
	protected SettingsRegistry $registry;

	/**
	 * Storage.
	 *
	 * @var SettingsRepositoryInterface
	 */
	protected SettingsRepositoryInterface $repository;

	/**
	 * Construct the service.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param SettingsRegistry            $registry   The schema.
	 * @param SettingsRepositoryInterface $repository Storage.
	 */
	public function __construct( SettingsRegistry $registry, SettingsRepositoryInterface $repository ) {
		$this->registry   = $registry;
		$this->repository = $repository;
	}

	/**
	 * The schema registry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return SettingsRegistry
	 */
	public function get_registry(): SettingsRegistry {
		return $this->registry;
	}

	/**
	 * Read one setting.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Field id.
	 * @param mixed  $fallback Returned when the field is neither stored nor declared.
	 *
	 * @return mixed
	 */
	public function get( string $id, $fallback = null ) {
		$stored = $this->repository->all();

		if ( array_key_exists( $id, $stored ) ) {
			return $stored[ $id ];
		}

		$fields = $this->registry->get_fields();

		if ( isset( $fields[ $id ] ) && array_key_exists( 'default', $fields[ $id ] ) ) {
			return $fields[ $id ]['default'];
		}

		return $fallback;
	}

	/**
	 * Whether a switch is on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Field id.
	 *
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		$value = $this->get( $id, 'off' );

		return true === $value || 'on' === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Write one setting through its field's rules.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id    Field id.
	 * @param mixed  $value Value.
	 *
	 * @return true|WP_Error
	 */
	public function update( string $id, $value ) {
		$result = $this->save( [ $id => $value ] );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Validate, sanitize and store a set of values.
	 *
	 * Keys that are not declared fields are ignored. A validation failure
	 * stores nothing and returns a `WP_Error` whose data carries every field's
	 * messages under `errors`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $values   Field id => submitted value. Dot paths are reduced to their last segment.
	 * @param string               $scope_id Restrict the save to one page or subpage. Empty accepts any field.
	 *
	 * @return array<string, mixed>|WP_Error The sanitized values that were stored.
	 */
	public function save( array $values, string $scope_id = '' ) {
		$fields = '' === $scope_id ? $this->registry->get_fields() : $this->registry->get_fields_in_scope( $scope_id );

		if ( '' !== $scope_id && [] === $fields ) {
			return new WP_Error(
				'flyaffiliate_settings_unknown_scope',
				// translators: %s: a settings page id.
				sprintf( __( 'No settings page called "%s".', 'flyaffiliate' ), $scope_id ),
				[ 'status' => 404 ]
			);
		}

		$submitted = [];

		foreach ( $values as $key => $value ) {
			$key = (string) $key;

			// plugin-ui emits dot-path keys reflecting its tree; the leaf is the field id.
			$id = false !== strpos( $key, '.' ) ? substr( $key, strrpos( $key, '.' ) + 1 ) : $key;

			if ( isset( $fields[ $id ] ) && empty( $fields[ $id ]['readonly'] ) ) {
				$submitted[ $id ] = $value;
			}
		}

		$errors    = [];
		$sanitized = [];

		foreach ( $submitted as $id => $value ) {
			$messages = $this->validate_field( $fields[ $id ], $value, $submitted );

			if ( [] !== $messages ) {
				$errors[ $id ] = $messages;
				continue;
			}

			$sanitized[ $id ] = $this->sanitize_field( $fields[ $id ], $value );
		}

		if ( [] !== $errors ) {
			return new WP_Error(
				'flyaffiliate_settings_invalid',
				__( 'Some settings could not be saved.', 'flyaffiliate' ),
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}

		/**
		 * Fires before settings are stored.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, mixed> $sanitized Values about to be stored, keyed by field id.
		 * @param string               $scope_id  The page or subpage being saved, or empty.
		 */
		do_action( 'flyaffiliate_before_save_settings', $sanitized, $scope_id );

		$this->repository->update( $sanitized );
		$this->registry->clear_cache();

		/**
		 * Fires after settings are stored.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, mixed> $sanitized Values that were stored, keyed by field id.
		 * @param string               $scope_id  The page or subpage that was saved, or empty.
		 * @param array<string, mixed> $all       The full payload now stored.
		 */
		do_action( 'flyaffiliate_after_save_settings', $sanitized, $scope_id, $this->repository->all() );

		return $sanitized;
	}

	/**
	 * Check a value against its field's rules.
	 *
	 * Rules come from the field's `validations` (plugin-ui's shape, so the same
	 * rule runs in the browser and here) and its optional `validate_callback`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $field  The field element.
	 * @param mixed                $value  Submitted value.
	 * @param array<string, mixed> $values Every value in the same save.
	 *
	 * @return string[] Error messages; empty when valid.
	 */
	public function validate_field( array $field, $value, array $values = [] ): array {
		$errors = [];

		foreach ( (array) ( $field['validations'] ?? [] ) as $validation ) {
			if ( ! is_array( $validation ) || empty( $validation['rules'] ) ) {
				continue;
			}

			$message = (string) ( $validation['message'] ?? '' );
			$params  = (array) ( $validation['params'] ?? [] );

			foreach ( explode( '|', (string) $validation['rules'] ) as $rule ) {
				$error = $this->apply_rule( trim( $rule ), $value, $params, $message );

				if ( null !== $error ) {
					$errors[] = $error;
					break;
				}
			}
		}

		if ( [] === $errors && ! empty( $field['validate_callback'] ) && is_callable( $field['validate_callback'] ) ) {
			$result = call_user_func( $field['validate_callback'], $value, $values );

			if ( false === $result ) {
				$errors[] = __( 'This value is not allowed.', 'flyaffiliate' );
			} elseif ( is_string( $result ) && '' !== $result ) {
				$errors[] = $result;
			}
		}

		/**
		 * Filters a field's validation errors.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string[]             $errors Messages so far; empty means valid.
		 * @param array<string, mixed> $field  The field element.
		 * @param mixed                $value  The submitted value.
		 * @param array<string, mixed> $values Every value in the same save.
		 */
		return (array) apply_filters( 'flyaffiliate_settings_validate_field', $errors, $field, $value, $values );
	}

	/**
	 * One validation rule.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $rule    Rule name.
	 * @param mixed  $value   Submitted value.
	 * @param array  $params  Rule parameters.
	 * @param string $message The field's message for this rule set.
	 *
	 * @return string|null The error, or null when the rule passes.
	 */
	protected function apply_rule( string $rule, $value, array $params, string $message ): ?string {
		switch ( $rule ) {
			case 'required':
			case 'not_empty':
				if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) || [] === $value ) {
					return '' !== $message ? $message : __( 'This field is required.', 'flyaffiliate' );
				}
				break;

			case 'min':
			case 'min_value':
				$min = $params['min'] ?? ( $params['value'] ?? null );

				if ( null !== $min && ( ! is_numeric( $value ) || (float) $value < (float) $min ) ) {
					// translators: %s: the minimum value.
					return '' !== $message ? $message : sprintf( __( 'Value must be at least %s.', 'flyaffiliate' ), $min );
				}
				break;

			case 'max':
			case 'max_value':
				$max = $params['max'] ?? ( $params['value'] ?? null );

				if ( null !== $max && ( ! is_numeric( $value ) || (float) $value > (float) $max ) ) {
					// translators: %s: the maximum value.
					return '' !== $message ? $message : sprintf( __( 'Value must be at most %s.', 'flyaffiliate' ), $max );
				}
				break;

			case 'not_in':
				if ( in_array( $value, (array) ( $params['values'] ?? [] ), true ) ) {
					return '' !== $message ? str_replace( '%s', (string) $value, $message ) : __( 'This value is not allowed.', 'flyaffiliate' );
				}
				break;
		}

		return null;
	}

	/**
	 * Reduce a value to what its field may store.
	 *
	 * A field's own `sanitize_callback` wins; otherwise the variant decides.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $field The field element.
	 * @param mixed                $value Submitted value.
	 *
	 * @return mixed
	 */
	public function sanitize_field( array $field, $value ) {
		if ( ! empty( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
			return call_user_func( $field['sanitize_callback'], $value );
		}

		$variant = (string) ( $field['variant'] ?? 'text' );

		switch ( $variant ) {
			case 'number':
			case 'currency':
				return is_numeric( $value ) ? $value + 0 : 0;

			case 'switch':
			case 'danger_switch':
				$on  = $field['enable_state']['value'] ?? 'on';
				$off = $field['disable_state']['value'] ?? 'off';

				return in_array( $value, [ $on, $off ], true ) ? $value : $off;

			case 'select':
			case 'radio':
			case 'radio_capsule':
			case 'customize_radio':
				$allowed = array_column( (array) ( $field['options'] ?? [] ), 'value' );
				$clean   = sanitize_text_field( (string) $value );

				if ( [] !== $allowed && ! in_array( $clean, array_map( 'strval', $allowed ), true ) ) {
					return (string) ( $field['default'] ?? '' );
				}

				return $clean;

			case 'multicheck':
			case 'checkbox_group':
				return array_map( 'sanitize_text_field', array_map( 'strval', (array) $value ) );

			case 'textarea':
			case 'rich_text':
				return wp_kses_post( (string) $value );

			case 'html':
			case 'notice':
			case 'info':
			case 'copy_field':
				return $value;

			default:
				/**
				 * Filters the sanitized value of a field variant this class does not know.
				 *
				 * @since FLYAFFILIATE_SINCE
				 *
				 * @param mixed                $value   The value, already passed through `sanitize_text_field()` when a string.
				 * @param array<string, mixed> $field   The field element.
				 * @param string               $variant The variant.
				 */
				return apply_filters( 'flyaffiliate_settings_sanitize_field', is_string( $value ) ? sanitize_text_field( $value ) : $value, $field, $variant );
		}
	}
}
