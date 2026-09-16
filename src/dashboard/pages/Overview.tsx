/**
 * Overview: the balance figures, then the referral link beside the link
 * generator, then the terms of the programme in one card.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Clock, MousePointerClick, Wallet, WalletCards } from 'lucide-react';
import { Card } from '@wedevs/plugin-ui';
import StatCard, { StatCardSkeleton } from '@/components/StatCard';
import CopyField from '@/components/CopyField';
import { formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import GenerateLink from '../components/GenerateLink';
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

			<div className="grid gap-4 lg:grid-cols-2">
				<Card className="gap-3 rounded-md border border-border px-5 py-4 shadow ring-0">
					<label
						htmlFor="flyaffiliate-referral-link"
						className="text-sm font-semibold text-foreground"
					>
						{ __( 'Your referral link', 'flyaffiliate' ) }
					</label>
					<p className="text-sm text-muted-foreground">
						{ __(
							'Share this link. When someone follows it and buys, you earn a commission on what they buy.',
							'flyaffiliate'
						) }
					</p>
					<CopyField
						id="flyaffiliate-referral-link"
						value={ profile.referral_url }
						className="bg-muted/40"
					/>
				</Card>

				<GenerateLink referralUrl={ profile.referral_url } />
			</div>

			<ProgramDetails />
		</div>
	);
}
