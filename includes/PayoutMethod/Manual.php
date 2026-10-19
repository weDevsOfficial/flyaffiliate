<?php
/**
 * The Manual payout method.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\PayoutMethod;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Abstracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Payout;

/**
 * Paid by hand, outside WordPress: the admin sends the money, then marks the
 * payment paid (ADR-0012). Always available, and the fallback for anyone an
 * automatic method cannot pay.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manual extends PayoutMethod {

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_id(): string {
		return Payout::METHOD_MANUAL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Manual', 'flyaffiliate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payment email, which falls back to the account email.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return string
	 */
	public function get_recipient( Affiliate $affiliate ): string {
		return $affiliate->get_payment_email();
	}
}
