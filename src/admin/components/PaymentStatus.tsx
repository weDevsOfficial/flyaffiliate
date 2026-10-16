/**
 * Where a payment stands: paid, the state of the transfer its payout method
 * is making, not sent, its method gone, or unpaid — waiting to be paid by hand.
 *
 * Under the pill, for a payment not paid yet, one line from its method: why
 * the transfer failed (in red), or what a transfer on its way waits for.
 */
import { __ } from '@wordpress/i18n';
import StatusBadge from '@/components/StatusBadge';
import TruncatedLine from '@/components/TruncatedLine';
import { getGlobals } from '@/lib/globals';
import type { Payout } from '@/lib/types';

/**
 * A payment's state, in one word.
 *
 * @param {Payout}  item         The payment.
 * @param {boolean} forAffiliate The affiliate's view: no admin states (not sent, method gone).
 * @return {string} A status key, labelled by `statuses.transfer` or the payment statuses.
 */
export function paymentState( item: Payout, forAffiliate = false ): string {
	if ( item.status === 'paid' ) {
		return 'paid';
	}

	if ( item.transfer ) {
		return item.transfer.status;
	}

	if ( forAffiliate ) {
		return 'unpaid';
	}

	const method = getGlobals().payoutMethods.find(
		( candidate ) => candidate.id === item.method
	);

	if ( ! method ) {
		return item.method === 'manual' ? 'unpaid' : 'unavailable';
	}

	return method.automatic ? 'not_sent' : 'unpaid';
}

type Props = {
	item: Payout;
	/** The affiliate's view: the state only, with the method beneath. */
	forAffiliate?: boolean;
	/** The pill alone, where the page says the rest (a payment's own page). */
	badgeOnly?: boolean;
};

export default function PaymentStatus( {
	item,
	forAffiliate = false,
	badgeOnly = false,
}: Props ) {
	const { statuses } = getGlobals();
	const state = paymentState( item, forAffiliate );
	const label =
		statuses.transfer?.[ state ] ??
		( state === 'unpaid' ? __( 'Unpaid', 'flyaffiliate' ) : undefined );
	let note = '';

	if ( badgeOnly ) {
		note = '';
	} else if ( forAffiliate ) {
		note = item.method_label;
	} else if ( item.status !== 'paid' ) {
		note = item.transfer?.message ?? '';
	}

	// Only a failure is an error; a transfer on its way is information.
	const failed = ! forAffiliate && state === 'failed';

	return (
		<div className="flex min-w-0 flex-col items-start gap-1">
			<StatusBadge status={ state } label={ label } />
			{ note && (
				<TruncatedLine
					tooltip={ note }
					className={
						failed
							? 'text-xs text-destructive'
							: 'text-xs text-muted-foreground'
					}
				>
					{ note }
				</TruncatedLine>
			) }
		</div>
	);
}
