<?php
/**
 * Assertions about money.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\CustomAssertion;

use FlyAffiliate\Utilities\Money;

/**
 * Money assertions that compare integer cents.
 *
 * `assertEquals( 15.00, $commission )` passes on 15.000000000000002 and fails on
 * a value that is right. `assertMoneyEquals()` compares what the affiliate is
 * actually owed (CONTEXT.md money rule 6).
 *
 * @since FLYAFFILIATE_SINCE
 */
trait MoneyAssertionTrait {

	/**
	 * Assert two amounts are the same amount of money.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $expected Expected amount.
	 * @param float|int|string $actual   Actual amount.
	 * @param string           $message  Optional failure message.
	 *
	 * @return void
	 */
	public function assertMoneyEquals( $expected, $actual, string $message = '' ): void {
		$this->assertSame(
			Money::to_cents( $expected ),
			Money::to_cents( $actual ),
			'' !== $message ? $message : sprintf( 'Expected %s, got %s.', (string) $expected, (string) $actual )
		);
	}

	/**
	 * Assert an amount equals a number of cents.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int              $expected_cents Expected integer cents.
	 * @param float|int|string $actual         Actual amount.
	 * @param string           $message        Optional failure message.
	 *
	 * @return void
	 */
	public function assertCentsEquals( int $expected_cents, $actual, string $message = '' ): void {
		$this->assertSame(
			$expected_cents,
			Money::to_cents( $actual ),
			'' !== $message ? $message : sprintf( 'Expected %d cents, got %d.', $expected_cents, Money::to_cents( $actual ) )
		);
	}

	/**
	 * Assert an amount is zero.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|int|string $actual  Actual amount.
	 * @param string           $message Optional failure message.
	 *
	 * @return void
	 */
	public function assertMoneyZero( $actual, string $message = '' ): void {
		$this->assertCentsEquals( 0, $actual, $message );
	}
}
