<?php
/**
 * The settings repository backed by one wp_option.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores every setting in the single, autoloaded `flyaffiliate_settings` option.
 *
 * This is the only source of truth for settings. There is no per-group option
 * and no legacy bridge: the plugin is new, so nothing needs migrating.
 *
 * @since FLYAFFILIATE_SINCE
 */
final class SettingsRepository implements SettingsRepositoryInterface {

	/**
	 * The option every setting lives in.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'flyaffiliate_settings';

	/**
	 * In-request snapshot of the payload. Null means not loaded yet.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Subscribe to the option's own hooks so a write that bypasses this class
	 * (a migration, WP-CLI, a test) still invalidates the snapshot.
	 *
	 * @since FLYAFFILIATE_SINCE
	 */
	public function __construct() {
		add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'flush_cache' ] );
		add_action( 'add_option_' . self::OPTION_KEY, [ $this, 'flush_cache' ] );
		add_action( 'delete_option_' . self::OPTION_KEY, [ $this, 'flush_cache' ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->snapshot ) {
			$stored         = get_option( self::OPTION_KEY, [] );
			$this->snapshot = is_array( $stored ) ? $stored : [];
		}

		return $this->snapshot;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $key      Field id.
	 * @param mixed  $fallback Returned when the id is not stored.
	 *
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $slice Field id => value.
	 *
	 * @return array<string, mixed> The keys whose stored value actually changed.
	 */
	public function update( array $slice ): array {
		$current = $this->all();

		/**
		 * Filters the slice about to be merged into the settings option.
		 *
		 * Return an empty array to block the write.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, mixed> $slice   Incoming values, keyed by field id.
		 * @param array<string, mixed> $current Values currently stored.
		 */
		$slice = (array) apply_filters( 'flyaffiliate_settings_pre_save', $slice, $current );

		$changed = [];

		foreach ( $slice as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) {
				$changed[ $key ] = $value;
			}
		}

		if ( [] === $changed ) {
			return [];
		}

		$merged = array_merge( $current, $changed );

		update_option( self::OPTION_KEY, $merged, true );
		$this->snapshot = $merged;

		/**
		 * Fires after settings changed.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array<string, mixed> $changed The keys that changed, with their new values.
		 * @param array<string, mixed> $merged  The full payload now stored.
		 */
		do_action( 'flyaffiliate_settings_changed', $changed, $merged );

		return $changed;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $values Field id => value.
	 *
	 * @return void
	 */
	public function replace( array $values ): void {
		update_option( self::OPTION_KEY, $values, true );
		$this->snapshot = $values;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->snapshot = null;
	}
}
