/**
 * A commission's status. A paid one no payout paid, recorded as paid by
 * hand, says so behind an info icon beside the pill; a details view says
 * what paid every paid one.
 */
import { __, sprintf } from '@wordpress/i18n';
import Hint from '@/components/Hint';
import StatusBadge from '@/components/StatusBadge';
import type { Commission } from '@/lib/types';

/**
 * How a paid commission was paid, in a sentence.
 *
 * @param {Commission} commission The commission.
 * @return {string} Empty for a commission that is not paid.
 */
export function paidBySentence(
	commission: Pick< Commission, 'status' | 'paid_by' >
): string {
	if ( commission.status !== 'paid' || commission.paid_by === undefined ) {
		return '';
	}

	return commission.paid_by
		? sprintf(
				/* translators: %s: payout method, e.g. "PayPal" */
				__( 'Paid in a %s payout.', 'flyaffiliate' ),
				commission.paid_by.method_label
		  )
		: __( 'Recorded as paid outside a payout.', 'flyaffiliate' );
}

export default function CommissionStatus( {
	commission,
	detailed = false,
}: {
	commission: Commission;
	/** Explain every paid commission, not only one recorded by hand: a details view. */
	detailed?: boolean;
} ) {
	const hint =
		detailed || commission.paid_by === null
			? paidBySentence( commission )
			: '';

	return (
		<span className="inline-flex items-center gap-1.5">
			<StatusBadge status={ commission.status } kind="commission" />
			{ hint && <Hint text={ hint } /> }
		</span>
	);
}
