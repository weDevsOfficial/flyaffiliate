<?php
/**
 * The hold period: a payout rule, not a status rule.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Commission;

use FlyAffiliate\Models\Commission;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Changing the hold period moves every payable commission's date and nothing else (ADR-0014).
 */
class HoldPeriodTest extends FlyAffiliateTestCase {

	/**
	 * Saving `hold_days` reschedules pending, unpaid and rejected rows; paid rows keep their dates; no status moves.
	 *
	 * @return void
	 */
	public function test_changing_the_hold_period_reschedules_every_payable_commission(): void {
		$created = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$later   = gmdate( 'Y-m-d H:i:s', time() + 28 * DAY_IN_SECONDS );
		$row     = fn( string $status, string $source = Commission::SOURCE_WOOCOMMERCE ) => $this->factory()->commission->create( [ 'source' => $source, 'status' => $status, 'created_at' => $created, 'matures_at' => $later ] );

		$pending  = $row( Commission::STATUS_PENDING );
		$unpaid   = $row( Commission::STATUS_UNPAID );
		$rejected = $row( Commission::STATUS_REJECTED );
		$manual   = $row( Commission::STATUS_UNPAID, Commission::SOURCE_MANUAL );
		$paid     = $row( Commission::STATUS_PAID );

		$saved = flyaffiliate()->settings->save( [ 'hold_days' => 10 ] );
		$this->assertNotWPError( $saved );

		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $created . ' UTC' ) + 10 * DAY_IN_SECONDS );

		foreach ( [ $pending, $unpaid, $rejected, $manual ] as $id ) {
			$this->assertSame( $expected, (string) flyaffiliate()->commission->get( $id )->get( 'matures_at' ), "commission {$id} follows the new hold" );
		}

		$this->assertSame( $later, (string) flyaffiliate()->commission->get( $paid )->get( 'matures_at' ), 'a paid commission keeps its dates' );

		// The hold period never changes a status.
		$this->assertSame( Commission::STATUS_PENDING, flyaffiliate()->commission->get( $pending )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_UNPAID, flyaffiliate()->commission->get( $unpaid )->get( 'status' ) );
		$this->assertSame( Commission::STATUS_REJECTED, flyaffiliate()->commission->get( $rejected )->get( 'status' ) );

		// A hold of zero makes every one of them payable now.
		flyaffiliate()->settings->save( [ 'hold_days' => 0 ] );

		$this->assertSame( $created, (string) flyaffiliate()->commission->get( $unpaid )->get( 'matures_at' ) );
		$this->assertTrue( flyaffiliate()->commission->get( $unpaid )->is_matured() );
	}

	/**
	 * A commission is matured once its date is past; one with no date has nothing to wait for.
	 *
	 * @return void
	 */
	public function test_is_matured_reads_the_maturity_date(): void {
		$past    = $this->factory()->commission->create( [ 'matures_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );
		$future  = $this->factory()->commission->create( [ 'matures_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );
		$undated = $this->factory()->commission->create();

		$this->assertTrue( flyaffiliate()->commission->get( $past )->is_matured() );
		$this->assertFalse( flyaffiliate()->commission->get( $future )->is_matured() );
		$this->assertTrue( flyaffiliate()->commission->get( $future )->is_matured( gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ), 'the moment to compare with can be given' );
		$this->assertTrue( flyaffiliate()->commission->get( $undated )->is_matured() );
	}
}
