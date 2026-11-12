<?php
/**
 * What a settings save refuses.
 *
 * @package FlyAffiliate\Test
 */

namespace FlyAffiliate\Test\Admin;

use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * A value a field cannot hold is refused with 400 and nothing is stored (ADR-0010).
 */
class SettingsValidationTest extends FlyAffiliateTestCase {

	/**
	 * A switch takes on or off; anything else is refused rather than read as off.
	 *
	 * @return void
	 */
	public function test_a_switch_takes_only_on_or_off(): void {
		$this->assertSame( 'on', flyaffiliate_get_option( 'block_self_referral' ) );

		$result = flyaffiliate()->settings->save( [ 'block_self_referral' => 'maybe' ], 'rules' );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'block_self_referral', $result->get_error_data()['errors'] );
		$this->assertSame( 'on', flyaffiliate_get_option( 'block_self_referral' ), 'nothing stored' );

		$this->acting_as( $this->admin_id );

		$response = $this->put_request( '/settings/rules', [ 'values' => [ 'block_self_referral' => 'maybe' ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'on', flyaffiliate_get_option( 'block_self_referral' ) );

		$this->assertNotWPError( flyaffiliate()->settings->save( [ 'block_self_referral' => 'off' ], 'rules' ) );
		$this->assertSame( 'off', flyaffiliate_get_option( 'block_self_referral' ) );
	}

	/**
	 * A WordPress query variable cannot become the referral variable: with `p`,
	 * every plain post link would credit the affiliate whose id matches the post.
	 *
	 * @return void
	 */
	public function test_a_wordpress_query_variable_cannot_be_the_referral_variable(): void {
		foreach ( [ 'p', 's', 'page_id', 'name', 'cat', 'author', 'year', 'posts_per_page' ] as $reserved ) {
			$result = flyaffiliate()->settings->save( [ 'referral_variable' => $reserved ] );

			$this->assertWPError( $result, $reserved );
			$this->assertSame( 400, $result->get_error_data()['status'], $reserved );
			$this->assertArrayHasKey( 'referral_variable', $result->get_error_data()['errors'], $reserved );
			$this->assertSame( 'affiliate', flyaffiliate_get_option( 'referral_variable' ), $reserved );
		}

		foreach ( [ 'ref', 'aff', 'partner_id', 'affiliate' ] as $allowed ) {
			$this->assertNotWPError( flyaffiliate()->settings->save( [ 'referral_variable' => $allowed ] ), $allowed );
			$this->assertSame( $allowed, flyaffiliate_get_option( 'referral_variable' ) );
		}
	}
}
