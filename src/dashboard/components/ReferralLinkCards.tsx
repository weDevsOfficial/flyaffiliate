/**
 * The affiliate's own referral link beside the generator, as SliceWP's
 * Affiliate Links tab has them. They sit on the Overview, between the
 * figures and the programme's terms; the links the generator saves are
 * listed on the Referral links tab.
 */
import { __ } from '@wordpress/i18n';
import { Card } from '@wedevs/plugin-ui';
import CopyField from '@/components/CopyField';
import { FormCardSkeleton } from '@/components/PageSkeleton';
import GenerateLink from './GenerateLink';
import type { AffiliateProfile } from '../types';

export default function ReferralLinkCards( {
	profile,
}: {
	profile: AffiliateProfile | null;
} ) {
	if ( ! profile ) {
		return (
			<div className="grid gap-4 lg:grid-cols-2">
				<FormCardSkeleton rows={ 1 } />
				<FormCardSkeleton rows={ 1 } />
			</div>
		);
	}

	return (
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
	);
}
