<?php
/**
 * Assertions about rows in a table.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\CustomAssertion;

/**
 * Assertions that read the database directly.
 *
 * A money test that asserts through the model can pass while the row is wrong.
 * These read the table.
 *
 * @since FLYAFFILIATE_SINCE
 */
trait DBAssertionTrait {

	/**
	 * Count rows matching a set of column values.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $table Table name, with or without the `$wpdb` prefix.
	 * @param array  $data  Column => value.
	 *
	 * @return int
	 */
	protected function get_database_count( string $table, array $data = [] ): int {
		global $wpdb;

		if ( 0 !== strpos( $table, $wpdb->prefix ) ) {
			$table = $wpdb->prefix . $table;
		}

		if ( [] === $data ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$clauses = [];

		foreach ( array_keys( $data ) as $column ) {
			$clauses[] = "{$column} = %s";
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $clauses );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_values( $data ) ) );
	}

	/**
	 * Assert that at least one row matches.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column => value.
	 *
	 * @return void
	 */
	public function assertDatabaseHas( string $table, array $data = [] ): void {
		$this->assertGreaterThanOrEqual(
			1,
			$this->get_database_count( $table, $data ),
			sprintf( 'No row in `%s` matches %s', $table, wp_json_encode( $data ) )
		);
	}

	/**
	 * Assert that exactly this many rows match.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $table    Table name.
	 * @param int    $expected Expected number of rows.
	 * @param array  $data     Column => value.
	 *
	 * @return void
	 */
	public function assertDatabaseCount( string $table, int $expected, array $data = [] ): void {
		$this->assertSame(
			$expected,
			$this->get_database_count( $table, $data ),
			sprintf( 'Wrong number of rows in `%s` matching %s', $table, wp_json_encode( $data ) )
		);
	}

	/**
	 * Assert that no row matches.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column => value.
	 *
	 * @return void
	 */
	public function assertDatabaseMissing( string $table, array $data = [] ): void {
		$this->assertSame(
			0,
			$this->get_database_count( $table, $data ),
			sprintf( 'A row in `%s` matches %s and should not', $table, wp_json_encode( $data ) )
		);
	}
}
