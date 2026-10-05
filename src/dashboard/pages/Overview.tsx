/**
 * Overview: the balance figures, then the terms of the programme in one card.
 * The referral link and the generator live on the Referral links tab.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Clock, MousePointerClick, Wallet, WalletCards } from 'lucide-react';
import StatCard, { StatCardSkeleton } from '@/components/StatCard';
import { formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import ProgramDetails from '../components/ProgramDetails';
import type { AffiliateProfile } from '../types';

type Props = {
	profile: AffiliateProfile | null;
	/** Whether the figures are for a picked date range rather than all time. */
	hasRange: boolean;
};

export default function OverviewPage( { profile, hasRange }: Props ) {
	const { program } = getGlobals();

	if ( ! profile ) {
		return (
			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCardSkeleton />
				<StatCardSkeleton />
				<StatCardSkeleton />
				<StatCardSkeleton />
			</div>
		);
	}

	const { totals, visits } = profile;
	const period = hasRange
		? __( 'in the selected dates', 'flyaffiliate' )
		: __( 'all time', 'flyaffiliate' );
	const converted =
		visits.all > 0
			? sprintf(
					/* translators: 1: converted visits, 2: all visits, 3: percentage */
					__(
						'%1$d of %2$d ended in an order (%3$d%%).',
						'flyaffiliate'
					),
					visits.converted,
					visits.all,
					Math.round( ( visits.converted / visits.all ) * 100 )
			  )
			: __( 'None has led to an order yet.', 'flyaffiliate' );

	return (
		<div className="flex flex-col gap-6">
			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					icon={ Wallet }
					label={ __( 'Unpaid balance', 'flyaffiliate' ) }
					value={ formatMoney( totals.unpaid ?? 0 ) }
					tooltip={ sprintf(
						/* translators: %s: the minimum payout amount */
						__(
							'Commissions that are approved and waiting to be paid out. They go into the next payout once your balance reaches %s.',
							'flyaffiliate'
						),
						formatMoney( program.payoutMinimum )
					) }
				/>
				<StatCard
					icon={ Clock }
					label={ __( 'Pending', 'flyaffiliate' ) }
					value={ formatMoney( totals.pending ?? 0 ) }
					tooltip={ __(
						'Commissions whose order is not completed yet, or that are still inside the hold period. They join your unpaid balance after that.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ WalletCards }
					label={ __( 'Paid', 'flyaffiliate' ) }
					value={ formatMoney( totals.paid ?? 0 ) }
					tooltip={ sprintf(
						/* translators: %s: "all time" or "in the selected dates" */
						__(
							'Commissions that have been paid out to you, %s.',
							'flyaffiliate'
						),
						period
					) }
				/>
				<StatCard
					icon={ MousePointerClick }
					label={ __( 'Visits', 'flyaffiliate' ) }
					value={ visits.all }
					tooltip={ `${ __(
						'Clicks on your referral links.',
						'flyaffiliate'
					) } ${ converted }` }
				/>
			</div>

			<ProgramDetails />
		</div>
	);
}
