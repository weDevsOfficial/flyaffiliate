/**
 * The terms of the programme an affiliate works under: what they earn, how
 * long a click keeps counting, how long a commission waits and when it is paid.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card } from '@wedevs/plugin-ui';
import { formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';

/**
 * A number of days, or the words for none.
 *
 * @param {number} count The days.
 * @param {string} none  What zero means here.
 * @return {string} The phrase.
 */
function days( count: number, none: string ): string {
	if ( count <= 0 ) {
		return none;
	}

	return sprintf(
		/* translators: %d: a number of days */
		_n( '%d day', '%d days', count, 'flyaffiliate' ),
		count
	);
}

function Detail( {
	label,
	value,
	hint,
}: {
	label: string;
	value: string;
	hint: string;
} ) {
	return (
		<div className="flex min-w-0 flex-1 flex-col gap-1">
			<dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
				{ label }
			</dt>
			<dd className="m-0 text-lg font-semibold text-foreground">
				{ value }
			</dd>
			<dd className="m-0 text-xs text-muted-foreground">{ hint }</dd>
		</div>
	);
}

/** A short, centred rule between two terms on wide screens. */
function Divider() {
	return (
		<div
			className="hidden h-16 w-px shrink-0 self-center bg-border/60 lg:block"
			aria-hidden="true"
		/>
	);
}

export default function ProgramDetails() {
	const { program } = getGlobals();
	const rate =
		program.rateType === 'fixed'
			? formatMoney( program.rate )
			: `${ Number( program.rate ) }%`;

	return (
		<Card
			className="rounded-md border border-border px-5 py-4 shadow ring-0"
			data-testid="flyaffiliate-program-details"
			aria-label={ __( 'Program details', 'flyaffiliate' ) }
		>
			<dl className="m-0 flex flex-col gap-4 lg:flex-row lg:gap-6">
				<Detail
					label={ __( 'Commission rate', 'flyaffiliate' ) }
					value={ rate }
					hint={ __(
						'Of each product sold through your link, unless the product sets its own rate.',
						'flyaffiliate'
					) }
				/>
				<Divider />
				<Detail
					label={ __( 'Cookie duration', 'flyaffiliate' ) }
					value={ days(
						program.cookieDays,
						__( 'Until the browser closes', 'flyaffiliate' )
					) }
					hint={ __(
						'How long after a click a purchase still counts for you.',
						'flyaffiliate'
					) }
				/>
				<Divider />
				<Detail
					label={ __( 'Hold period', 'flyaffiliate' ) }
					value={ days(
						program.holdDays,
						__( 'None', 'flyaffiliate' )
					) }
					hint={ __(
						'How long a commission stays pending before it can be paid.',
						'flyaffiliate'
					) }
				/>
				<Divider />
				<Detail
					label={ __( 'Minimum payout', 'flyaffiliate' ) }
					value={ formatMoney( program.payoutMinimum ) }
					hint={ __(
						'Your unpaid balance is paid once it reaches this.',
						'flyaffiliate'
					) }
				/>
			</dl>
		</Card>
	);
}
