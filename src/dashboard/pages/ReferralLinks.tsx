/**
 * Referral links: the affiliate's own link beside the generator, as SliceWP's
 * Affiliate Links tab has them, then every link they generated with the
 * visits it brought. Removing a link only takes it off the list.
 */
import { __ } from '@wordpress/i18n';
import { Copy, ExternalLink, Trash2 } from 'lucide-react';
import {
	Card,
	DataViews,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import CopyButton from '@/components/CopyButton';
import CopyField from '@/components/CopyField';
import DateTime from '@/components/DateTime';
import { FormCardSkeleton } from '@/components/PageSkeleton';
import TruncatedLine from '@/components/TruncatedLine';
import { useListView } from '@/hooks/useListView';
import { withIconLabels } from '@/lib/actions';
import { errorMessage, send } from '@/lib/api';
import { copyText } from '@/lib/clipboard';
import { toSitePath } from '@/lib/format';
import type { ReferralLink } from '@/lib/types';
import GenerateLink from '../components/GenerateLink';
import type { AffiliateProfile } from '../types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	fields: [ 'referral_url', 'visits', 'conversions', 'created_at' ],
	titleField: 'url',
	layout: {
		// The page, the title column, takes what these and the row menu leave.
		styles: {
			referral_url: { width: '32%' },
			visits: { width: '10%' },
			conversions: { width: '12%' },
			created_at: { width: '16%' },
		},
	},
};

export default function ReferralLinksPage( {
	profile,
}: {
	profile: AffiliateProfile | null;
} ) {
	const list = useListView< ReferralLink >( {
		path: '/me/referral-links',
		defaultView: DEFAULT_VIEW,
	} );

	const fields: DataViewField< ReferralLink >[] = [
		{
			id: 'url',
			label: __( 'Page', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<TruncatedLine
					tooltip={ item.url }
					className="font-medium text-foreground"
				>
					{ toSitePath( item.url ) }
				</TruncatedLine>
			),
		},
		{
			id: 'referral_url',
			label: __( 'Referral link', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span className="flex min-w-0 items-center gap-1">
					<TruncatedLine
						tooltip={ item.referral_url }
						className="text-muted-foreground"
					>
						{ toSitePath( item.referral_url ) }
					</TruncatedLine>
					<CopyButton
						value={ item.referral_url }
						size="icon-xs"
						className="shrink-0"
					/>
				</span>
			),
		},
		{
			id: 'visits',
			label: __( 'Visits', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span className="font-medium">{ item.visits }</span>
			),
		},
		{
			id: 'conversions',
			label: __( 'Conversions', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span className="font-medium">{ item.conversions }</span>
			),
		},
		{
			id: 'created_at',
			label: __( 'Created', 'flyaffiliate' ),
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
	];

	const actions: DataViewAction< ReferralLink >[] = [
		{
			id: 'copy',
			label: __( 'Copy link', 'flyaffiliate' ),
			icon: <Copy size={ 16 } />,
			callback: async ( [ item ] ) => {
				if ( await copyText( item.referral_url ) ) {
					toast.success(
						__( 'Referral link copied.', 'flyaffiliate' )
					);
					return;
				}

				toast.error(
					__( 'The link could not be copied.', 'flyaffiliate' )
				);
			},
		},
		{
			id: 'open',
			label: __( 'Open page', 'flyaffiliate' ),
			icon: <ExternalLink size={ 16 } />,
			// The page itself: following the referral link would be a self-referral.
			callback: ( [ item ] ) =>
				window.open( item.url, '_blank', 'noopener,noreferrer' ),
		},
		{
			id: 'remove',
			label: __( 'Remove from list', 'flyaffiliate' ),
			icon: <Trash2 size={ 16 } />,
			isDestructive: true,
			confirmTitle: __( 'Remove from list', 'flyaffiliate' ),
			confirmMessage: __(
				'The link leaves this list only. Wherever you have shared it, it keeps working and earning, and its visits and commissions stay.',
				'flyaffiliate'
			),
			callback: async ( [ item ] ) => {
				try {
					await send( `/me/referral-links/${ item.id }`, 'DELETE' );
					toast.success(
						__( 'Link removed from the list.', 'flyaffiliate' )
					);
					list.refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The link could not be removed.',
								'flyaffiliate'
							)
						)
					);
				}
			},
		},
	];

	return (
		<div className="flyaffiliate-fixed-columns flex flex-col gap-6">
			{ profile ? (
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

					<GenerateLink
						referralUrl={ profile.referral_url }
						onSaved={ list.refresh }
					/>
				</div>
			) : (
				<div className="grid gap-4 lg:grid-cols-2">
					<FormCardSkeleton rows={ 1 } />
					<FormCardSkeleton rows={ 1 } />
				</div>
			) }

			<DataViews< ReferralLink >
				namespace="flyaffiliate-my-referral-links"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				isLoading={ list.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				search={ false }
				emptyTitle={ __( 'No referral links yet', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Generate a link to any page of this site above; it is kept here with the visits it brings.',
					'flyaffiliate'
				) }
			/>
		</div>
	);
}
