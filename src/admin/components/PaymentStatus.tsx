/**
 * Where a payment stands: paid, the state of the transfer its payout method
 * is making, not sent, its method gone, or unpaid — waiting to be paid by hand.
 *
 * Beside the pill, for a payment not paid yet, an info icon whose tooltip is
 * the line from its method: why the transfer failed (the icon in red), or
 * what a transfer on its way waits for. The affiliate's view keeps the
 * method, with its logo, under the pill.
 */
import { __ } from '@wordpress/i18n';
import Hint from '@/components/Hint';
import MethodName from '@/components/MethodName';
import StatusBadge from '@/components/StatusBadge';
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
	const method = forAffiliate && ! badgeOnly ? item.method_label : '';
	const message =
		! forAffiliate && ! badgeOnly && item.status !== 'paid'
			? item.transfer?.message ?? ''
			: '';

	// Only a failure is an error; a transfer on its way is information.
	const failed = ! forAffiliate && state === 'failed';

	return (
		<div className="flex min-w-0 flex-col items-start gap-1">
			<span className="inline-flex items-center gap-1.5">
				<StatusBadge status={ state } label={ label } />
				{ message && (
					<Hint
						text={ message }
						className={ failed ? 'text-destructive' : undefined }
					/>
				) }
			</span>
			{ method && (
				<MethodName
					id={ item.method }
					label={ method }
					className="text-xs text-muted-foreground"
					logoClassName="size-3"
				/>
			) }
		</div>
	);
}
