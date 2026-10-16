/**
 * A payout's payments by what is happening to them — paid, on the way,
 * failed, not sent, to pay by hand — the one wording the payouts list and a
 * payout's page both use.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { PayoutCounts } from '@/lib/types';

/** One kind of payment: its colour, how many, what that means, and whether it needs someone. */
export type PaymentPart = {
	key: string;
	tone: string;
	count: number;
	text: string;
	trouble: boolean;
};

type Source = { paid: number; in_flight?: number; counts?: PayoutCounts };

/**
 * The kinds a payout's payments are in, those with any payments.
 *
 * @param {Source} payout The payout's counts.
 * @return {PaymentPart[]} The parts, in that order.
 */
export function paymentParts( payout: Source ): PaymentPart[] {
	const counts = payout.counts;
	const parts: PaymentPart[] = [];
	const add = (
		key: string,
		tone: string,
		count: number,
		text: ( n: number ) => string,
		trouble = false
	) => {
		if ( count > 0 ) {
			parts.push( { key, tone, count, text: text( count ), trouble } );
		}
	};

	add( 'paid', 'bg-success', payout.paid, ( n ) =>
		/* translators: %d: number of payments */
		sprintf( __( '%d paid', 'flyaffiliate' ), n )
	);

	if ( counts ) {
		add( 'on-the-way', 'bg-info', payout.in_flight ?? 0, ( n ) =>
			/* translators: %d: number of payments */
			sprintf( __( '%d on the way', 'flyaffiliate' ), n )
		);
		add(
			'failed',
			'bg-destructive',
			counts.failed,
			( n ) =>
				/* translators: %d: number of payments */
				sprintf( __( '%d failed', 'flyaffiliate' ), n ),
			true
		);
		add(
			'not-sent',
			'bg-warning',
			counts.not_sent + counts.unavailable,
			( n ) =>
				/* translators: %d: number of payments */
				sprintf( __( '%d not sent', 'flyaffiliate' ), n ),
			true
		);
		add( 'manual', 'bg-muted-foreground/40', counts.manual_unpaid, ( n ) =>
			/* translators: %d: number of payments */
			sprintf( __( '%d to pay by hand', 'flyaffiliate' ), n )
		);
	}

	return parts;
}
