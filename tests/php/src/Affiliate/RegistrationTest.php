<?php
/**
 * Registration tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Affiliate;

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Test\FlyAffiliateTestCase;

/**
 * Email-only signup and the one-time activation link.
 *
 * @group registration
 *
 * @since FLYAFFILIATE_SINCE
 */
class RegistrationTest extends FlyAffiliateTestCase {

	/**
	 * Sent emails, captured from wp_mail.
	 *
	 * @var array
	 */
	protected array $sent = [];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->sent = [];

		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->sent[] = $atts;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * The activation key out of the email that carried it.
	 *
	 * The row only holds the key's hash, so the email is the only place the key
	 * itself exists — which is the point of hashing it.
	 *
	 * @param int $index Which sent email to read.
	 *
	 * @return string
	 */
	protected function key_from_email( int $index = 0 ): string {
		$matched = preg_match(
			'/flyaffiliate_activate=([A-Za-z0-9]+)/',
			(string) ( $this->sent[ $index ]['message'] ?? '' ),
			$matches
		);

		return 1 === $matched ? $matches[1] : '';
	}

	/**
	 * A new address gets a user, a pending affiliate and an activation email.
	 *
	 * @return void
	 */
	public function test_it_registers_a_new_address(): void {
		$affiliate = flyaffiliate()->registration->register( 'newbie@example.org', 'Blog posts' );

		$this->assertInstanceOf( Affiliate::class, $affiliate );
		$this->assertSame( Affiliate::STATUS_PENDING, $affiliate->get( 'status' ) );
		$this->assertNotEmpty( $affiliate->get( 'activation_key' ) );
		$this->assertSame( 'Blog posts', $affiliate->get( 'promo_method' ) );

		$user = get_user_by( 'email', 'newbie@example.org' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( $user->ID, (int) $affiliate->get( 'user_id' ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'newbie@example.org', $this->sent[0]['to'] );

		$key = $this->key_from_email();

		$this->assertNotSame( '', $key );
		// The email carries the key; the row carries its hash, and never the key.
		$this->assertStringNotContainsString( (string) $affiliate->get( 'activation_key' ), $this->sent[0]['message'] );
		$this->assertSame( wp_hash( $key ), (string) $affiliate->get( 'activation_key' ) );
		$this->assertNotEmpty( $affiliate->get( 'activation_expires_at' ) );
	}

	/**
	 * An existing user is reused, not duplicated.
	 *
	 * @return void
	 */
	public function test_it_reuses_an_existing_user(): void {
		$user_id   = $this->factory()->user->create( [ 'user_email' => 'known@example.org' ] );
		$affiliate = flyaffiliate()->registration->register( 'known@example.org' );

		$this->assertInstanceOf( Affiliate::class, $affiliate );
		$this->assertSame( $user_id, (int) $affiliate->get( 'user_id' ) );
	}

	/**
	 * One user is one affiliate: a second signup is refused.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_second_signup(): void {
		flyaffiliate()->registration->register( 'twice@example.org' );

		$result = flyaffiliate()->registration->register( 'twice@example.org' );

		$this->assertWPError( $result );
		$this->assertSame( 'already_registered', $result->get_error_code() );
		$this->assertDatabaseCount( 'flyaffiliate_affiliates', 1 );
	}

	/**
	 * A bad address is refused before anything is created.
	 *
	 * @return void
	 */
	public function test_it_refuses_an_invalid_email(): void {
		$this->assertWPError( flyaffiliate()->registration->register( 'not-an-email' ) );
		$this->assertDatabaseCount( 'flyaffiliate_affiliates', 0 );
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * The activation key activates once and is then gone.
	 *
	 * @return void
	 */
	public function test_activation_is_single_use(): void {
		flyaffiliate()->registration->register( 'activate@example.org' );

		$key       = $this->key_from_email();
		$activated = flyaffiliate()->registration->activate( $key );

		$this->assertInstanceOf( Affiliate::class, $activated );
		$this->assertTrue( $activated->is_active() );
		$this->assertSame( '', $activated->get( 'activation_key' ) );
		$this->assertNull( $activated->get( 'activation_expires_at' ) );

		$this->assertWPError( flyaffiliate()->registration->activate( $key ) );
		$this->assertWPError( flyaffiliate()->registration->activate( 'nonsense' ) );
	}

	/**
	 * An empty key never matches the rows that carry no key.
	 *
	 * @return void
	 */
	public function test_an_empty_key_activates_nobody(): void {
		flyaffiliate_update_option( 'activation_email_enabled', 'off' );

		$affiliate = flyaffiliate()->registration->register( 'keyless@example.org' );

		$this->assertSame( '', $affiliate->get( 'activation_key' ) );
		$this->assertWPError( flyaffiliate()->registration->activate( '' ) );

		$reloaded = flyaffiliate()->affiliate->get( $affiliate->get_id() );

		$this->assertSame( Affiliate::STATUS_PENDING, $reloaded->get( 'status' ) );
	}

	/**
	 * A link past its lifetime is refused, and the affiliate stays pending.
	 *
	 * @return void
	 */
	public function test_an_expired_key_is_refused(): void {
		flyaffiliate()->registration->register( 'late@example.org' );

		$key       = $this->key_from_email();
		$affiliate = flyaffiliate()->affiliate->get_by_activation_key( wp_hash( $key ) );

		$this->assertInstanceOf( Affiliate::class, $affiliate );

		flyaffiliate()->affiliate->update(
			$affiliate->get_id(),
			[ 'activation_expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ]
		);

		$result = flyaffiliate()->registration->activate( $key );

		$this->assertWPError( $result );
		$this->assertSame( 'expired_key', $result->get_error_code() );

		$reloaded = flyaffiliate()->affiliate->get( $affiliate->get_id() );

		$this->assertSame( Affiliate::STATUS_PENDING, $reloaded->get( 'status' ) );
	}

	/**
	 * With the activation email off, no key is issued and nothing is sent.
	 *
	 * @return void
	 */
	public function test_no_email_when_disabled(): void {
		flyaffiliate_update_option( 'activation_email_enabled', 'off' );

		$affiliate = flyaffiliate()->registration->register( 'quiet@example.org' );

		$this->assertSame( '', $affiliate->get( 'activation_key' ) );
		$this->assertCount( 0, $this->sent );
	}
}
