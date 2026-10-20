<?php
/**
 * Contract for a payout method.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Payout;
use WP_Error;

/**
 * A way of paying affiliates: by hand (Manual), or through a service that sends
 * the money itself (an automatic method, which another plugin adds through the
 * `flyaffiliate_payout_methods` filter).
 *
 * FlyAffiliate keeps the ledger: a payment is `unpaid` until it is marked paid,
 * which is what pays its commissions (ADR-0012). An automatic method sends the
 * money and keeps its own record of each attempt — a *transfer* — and reports
 * the transfer's state through `get_states()`. It never writes the ledger
 * except through `flyaffiliate()->payout->mark_paid( $id, true )` once the
 * money has arrived, and `mark_unpaid( $id, true )` when it came back.
 *
 * Rules every method keeps:
 *
 * - `pay()` and `refresh()` never throw into FlyAffiliate's request; a payment
 *   the method cannot send ends up `failed` with the reason.
 * - `failed` means the money certainly did not move. When the method is not
 *   sure, it reports `pending`.
 * - `retry()` checks its own records again before sending: FlyAffiliate's
 *   check that nothing is in flight happens before the call.
 * - `is_enabled()` must not read a setting that is not stored yet: it can run
 *   while the settings schema is being built.
 *
 * @since FLYAFFILIATE_SINCE
 */
interface PayoutMethod {

	/**
	 * Accepted for sending, not sent yet.
	 *
	 * @var string
	 */
	const STATE_QUEUED = 'queued';

	/**
	 * Sent; the service has not answered for this payment yet.
	 *
	 * @var string
	 */
	const STATE_PROCESSING = 'processing';

	/**
	 * The service has the payment and has not settled it (unclaimed, on hold).
	 *
	 * @var string
	 */
	const STATE_PENDING = 'pending';

	/**
	 * The money arrived.
	 *
	 * @var string
	 */
	const STATE_PAID = 'paid';

	/**
	 * The money did not move. The payment stays unpaid and can be sent again.
	 *
	 * @var string
	 */
	const STATE_FAILED = 'failed';

	/**
	 * The method's id, stored on every payment it pays. Lowercase `a-z0-9_`, never changes.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * The method's name, translated.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * The method's logo, shown beside its name wherever a payment says how it
	 * is paid. Empty for none: the name stands alone.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string An image URL, or an empty string.
	 */
	public function get_logo_url(): string;

	/**
	 * Whether the method sends the money itself.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_automatic(): bool;

	/**
	 * Whether affiliates can be paid this way right now (switched on and ready).
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * The details an affiliate gives to be paid this way.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<int, array{id: string, type: string, label: string, description?: string, required?: bool, placeholder?: string, options?: array<string, string>}>
	 *         `type` is `email`, `text` or `select`; `options` (value => label) is for `select`.
	 */
	public function get_affiliate_fields(): array;

	/**
	 * The details an affiliate has given for this method.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return array<string, string> Field id => value.
	 */
	public function get_affiliate_details( Affiliate $affiliate ): array;

	/**
	 * Store an affiliate's details for this method.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate            $affiliate The affiliate.
	 * @param array<string, mixed> $details   Field id => value, unsanitized.
	 *
	 * @return true|WP_Error
	 */
	public function save_affiliate_details( Affiliate $affiliate, array $details );

	/**
	 * Whether this affiliate can be paid this way now. The error message is
	 * shown on the payout preview, before any money moves.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return true|WP_Error
	 */
	public function check_affiliate( Affiliate $affiliate );

	/**
	 * Where the money goes, shown under the affiliate's name.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return string An empty string when the affiliate has not said.
	 */
	public function get_recipient( Affiliate $affiliate ): string;

	/**
	 * An account this affiliate is paid to without entering anything, because
	 * they connected it elsewhere (a vendor's store account). The affiliate
	 * dashboard shows it in place of the method's fields. One they could
	 * connect there but have not (`connected` false) is offered beside the
	 * fields, with a link to connect it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Affiliate $affiliate The affiliate.
	 *
	 * @return array{label: string, detail: string, url: string, connected?: bool}|array{} What it is ("Your store's PayPal account"), which one (its email), where they change or connect it, and whether it is connected (true when left out); empty for none.
	 */
	public function get_connected_account( Affiliate $affiliate ): array;

	/**
	 * Start sending a payout's payments that use this method.
	 *
	 * Called once per method, right after the payout is recorded. Manual does
	 * nothing: the admin sends the money and marks the payments paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string   $batch_key The payout.
	 * @param Payout[] $payouts   This method's payments in it, unpaid.
	 *
	 * @return void
	 */
	public function pay( string $batch_key, array $payouts ): void;

	/**
	 * Send one unpaid payment again after its transfer failed, or was never sent.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $payout The payment, re-summed to its unpaid commissions.
	 *
	 * @return true|WP_Error
	 */
	public function retry( Payout $payout );

	/**
	 * Ask the service for the latest state of these payments and settle what it reports.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts Unpaid payments that use this method.
	 *
	 * @return void
	 */
	public function refresh( array $payouts ): void;

	/**
	 * The latest transfer of each payment, as the method recorded it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts Payments that use this method.
	 *
	 * @return array<int, array{status: string, message?: string, retryable?: bool, reference?: string, recipient?: string}>
	 *         Payment id => state, `status` one of the `STATE_*` constants; `recipient`
	 *         is where the transfer sent the money (an email, an account id). A payment
	 *         with no transfer is left out. A method may add keys of its own, passed
	 *         through as they are, for the columns it adds to the payments table.
	 */
	public function get_states( array $payouts ): array;
}
