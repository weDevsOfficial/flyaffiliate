<?php
/**
 * The settings storage contract.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Settings\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the single flat settings payload.
 *
 * The payload is `array<string, mixed>` keyed by field id — never nested and
 * never per page. Moving a field between pages is a schema edit, not a data
 * migration.
 *
 * @since FLYAFFILIATE_SINCE
 */
interface SettingsRepositoryInterface {

	/**
	 * Every stored value, keyed by field id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array;

	/**
	 * Merge a slice of values into storage.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $slice Field id => value.
	 *
	 * @return array<string, mixed> The keys whose stored value actually changed.
	 */
	public function update( array $slice ): array;

	/**
	 * Forget the in-request snapshot so the next read hits the option again.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function flush_cache(): void;
}
