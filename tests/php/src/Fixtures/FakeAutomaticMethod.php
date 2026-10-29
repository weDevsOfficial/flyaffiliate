<?php
/**
 * An automatic payout method for tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Fixtures;

use FlyAffiliate\Abstracts\PayoutMethod;
use FlyAffiliate\Models\Payout;

/**
 * Sends nothing: records what FlyAffiliate asks of it, and reports whatever
 * transfer states a test gives it.
 *
 * @since FLYAFFILIATE_SINCE
 */
class FakeAutomaticMethod extends PayoutMethod {

	/**
	 * Whether the method is switched on.
	 *
	 * @var bool
	 */
	public bool $enabled = true;

	/**
	 * Payment id => state, as `get_states()` reports it.
	 *
	 * @var array<int, array>
	 */
	public array $states = [];

	/**
	 * Every `pay()` call: batch key and the payments' ids and amounts in cents.
	 *
	 * @var array<int, array{batch_key: string, payouts: array<int, int>}>
	 */
	public array $paid = [];

	/**
	 * Every `retry()` call: payment id => amount in cents when it was asked.
	 *
	 * @var array<int, int>
	 */
	public array $retried = [];

	/**
	 * Every `refresh()` call: the payment ids.
	 *
	 * @var array<int, int[]>
	 */
	public array $refreshed = [];

	/**
	 * When set, `pay()` throws it.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throw = null;

	/**
	 * When set, `pay()` reports every payment it is given in this state.
	 *
	 * @var string
	 */
	public string $state_on_pay = '';

	/**
	 * What `get_connected_account()` answers.
	 *
	 * @var array
	 */
	public array $connected = [];

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'fake';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Fake';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_automatic(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_affiliate_fields(): array {
		return [
			[
				'id'       => 'email',
				'type'     => 'email',
				'label'    => 'Fake email',
				'required' => true,
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \FlyAffiliate\Models\Affiliate $affiliate The affiliate.
	 *
	 * @return array
	 */
	public function get_connected_account( \FlyAffiliate\Models\Affiliate $affiliate ): array {
		return $this->connected;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $batch_key The payout.
	 * @param Payout[] $payouts   The payments.
	 *
	 * @return void
	 */
	public function pay( string $batch_key, array $payouts ): void {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		$amounts = [];

		foreach ( $payouts as $payout ) {
			$amounts[ $payout->get_id() ] = \FlyAffiliate\Utilities\Money::to_cents( $payout->get( 'amount' ) );

			if ( '' !== $this->state_on_pay ) {
				$this->states[ $payout->get_id() ] = [ 'status' => $this->state_on_pay ];
			}
		}

		$this->paid[] = [
			'batch_key' => $batch_key,
			'payouts'   => $amounts,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Payout $payout The payment.
	 *
	 * @return true
	 */
	public function retry( Payout $payout ) {
		$this->retried[ $payout->get_id() ] = \FlyAffiliate\Utilities\Money::to_cents( $payout->get( 'amount' ) );
		$this->states[ $payout->get_id() ]  = [ 'status' => self::STATE_PROCESSING ];

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Payout[] $payouts The payments.
	 *
	 * @return void
	 */
	public function refresh( array $payouts ): void {
		$this->refreshed[] = array_map( static fn( Payout $payout ) => $payout->get_id(), $payouts );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Payout[] $payouts The payments.
	 *
	 * @return array
	 */
	public function get_states( array $payouts ): array {
		$states = [];

		foreach ( $payouts as $payout ) {
			if ( isset( $this->states[ $payout->get_id() ] ) ) {
				$states[ $payout->get_id() ] = $this->states[ $payout->get_id() ];
			}
		}

		return $states;
	}
}
