/**
 * Where a payment stands: paid, the state of the transfer its payout method
 * is making, not sent, its method gone, or unpaid — waiting to be paid by hand.
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
};

export default function PaymentStatus( { item, forAffiliate = false }: Props ) {
	const { statuses } = getGlobals();
	const state = paymentState( item, forAffiliate );
	const label =
		statuses.transfer?.[ state ] ??
		( state === 'unpaid' ? __( 'Unpaid', 'flyaffiliate' ) : undefined );
	const note = forAffiliate
		? item.method_label
		: item.status !== 'paid' && item.transfer?.message;

	return (
		<div className="flex min-w-0 flex-col items-start gap-1">
			<StatusBadge status={ state } label={ label } />
			{ note && (
				<TruncatedLine
					tooltip={ note }
					className={
						forAffiliate
							? 'text-xs text-muted-foreground'
							: 'text-xs text-destructive'
					}
				>
					{ note }
				</TruncatedLine>
			) }
		</div>
	);
}
