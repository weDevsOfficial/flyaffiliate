<?php
/**
 * Assertions about nested arrays.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\CustomAssertion;

/**
 * Assertions for REST responses and other nested structures.
 *
 * @since FLYAFFILIATE_SINCE
 */
trait NestedArrayAssertionTrait {

	/**
	 * Assert every key and value in `$expected` appears in `$actual`.
	 *
	 * Keys `$actual` has and `$expected` does not are ignored, so a test states
	 * the fields it is about rather than the whole payload.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array  $expected The subset that must be present.
	 * @param array  $actual   The structure to look in.
	 * @param string $path     Internal: the key path, for the failure message.
	 *
	 * @return void
	 */
	public function assertArraySubset( array $expected, array $actual, string $path = '' ): void {
		foreach ( $expected as $key => $value ) {
			$current = '' === $path ? (string) $key : $path . '.' . $key;

			$this->assertArrayHasKey( $key, $actual, sprintf( 'Missing key `%s`.', $current ) );

			if ( is_array( $value ) && is_array( $actual[ $key ] ) ) {
				$this->assertArraySubset( $value, $actual[ $key ], $current );
				continue;
			}

			$this->assertSame( $value, $actual[ $key ], sprintf( 'Wrong value at `%s`.', $current ) );
		}
	}

	/**
	 * Assert a structure has every one of these keys.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string[] $keys   Expected keys.
	 * @param array    $actual The structure.
	 *
	 * @return void
	 */
	public function assertArrayHasKeys( array $keys, array $actual ): void {
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $actual, sprintf( 'Missing key `%s`.', $key ) );
		}
	}
}
