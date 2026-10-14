/**
 * The referral links an affiliate saved, with the visits each brought.
 * Read-only: the affiliate adds and removes their own from the dashboard.
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Copy, ExternalLink } from 'lucide-react';
import {
	DataViews,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import CopyButton from '@/components/CopyButton';
import DateTime from '@/components/DateTime';
import TruncatedLine from '@/components/TruncatedLine';
import { useListView } from '@/hooks/useListView';
import { withIconLabels } from '@/lib/actions';
import { copyText } from '@/lib/clipboard';
import { toSitePath } from '@/lib/format';
import type { ReferralLink } from '@/lib/types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	fields: [
		'referral_url',
		'visits',
		'conversions',
		'last_visit_at',
		'created_at',
	],
	titleField: 'url',
	layout: {
		// The page, the title column, takes what these and the row menu leave.
		styles: {
			referral_url: { width: '27%' },
			visits: { width: '9%' },
			conversions: { width: '11%' },
			last_visit_at: { width: '14%' },
			created_at: { width: '14%' },
		},
	},
};

export function ReferralLinksTable( { affiliateId }: { affiliateId: number } ) {
	const filters = useMemo(
		() => ( { affiliate_id: affiliateId } ),
		[ affiliateId ]
	);
	const list = useListView< ReferralLink >( {
		path: '/referral-links',
		defaultView: DEFAULT_VIEW,
		filters,
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
			id: 'last_visit_at',
			label: __( 'Last visit', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.last_visit_at ? (
					<DateTime value={ item.last_visit_at } />
				) : (
					<span className="text-muted-foreground">—</span>
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
			label: __( 'Copy referral link', 'flyaffiliate' ),
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
			// The page itself, so opening it does not record a visit.
			callback: ( [ item ] ) =>
				window.open( item.url, '_blank', 'noopener,noreferrer' ),
		},
	];

	return (
		<div className="flyaffiliate-fixed-columns">
			<DataViews< ReferralLink >
				namespace="flyaffiliate-referral-links"
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
				emptyTitle={ __( 'No referral links saved', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Links the affiliate generates on their dashboard are listed here with the visits they bring.',
					'flyaffiliate'
				) }
			/>
		</div>
	);
}
