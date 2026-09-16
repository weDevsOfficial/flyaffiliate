/**
 * Visits: every referral click, converted or not.
 */
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import {
	DataViews,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import VisitResult from '@/components/VisitResult';
import DateTime from '@/components/DateTime';
import AffiliatePicker from '@/components/AffiliatePicker';
import { useListView } from '@/hooks/useListView';
import { useCounts } from '@/hooks/useCounts';
import { getGlobals } from '@/lib/globals';
import { buildTabs } from '@/lib/tabs';
import type { Visit } from '@/lib/types';
import Truncated from '@/components/Truncated';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype: the date sits second, the result last.
	fields: [
		'created_at',
		'affiliate',
		'url',
		'referrer',
		'converted',
		'order',
	],
	titleField: 'id',
	layout: {
		// Explicit widths, or the last column absorbs the leftover space.
		styles: {
			id: { width: '7%' },
			created_at: { width: '15%' },
			affiliate: { width: '16%' },
			url: { width: '26%' },
			referrer: { width: '14%' },
			converted: { width: '12%' },
			order: { width: '10%' },
		},
	},
};

type TableProps = {
	affiliateId?: number;
	headerContent?: React.ReactNode[];
};

export function VisitsTable( { affiliateId, headerContent = [] }: TableProps ) {
	const { urls } = getGlobals();
	const [ result, setResult ] = useState( 'all' );

	const baseFilters = useMemo(
		() => ( affiliateId ? { affiliate_id: affiliateId } : {} ),
		[ affiliateId ]
	);
	const filters = useMemo(
		() =>
			result === 'all'
				? baseFilters
				: { ...baseFilters, converted: result === 'converted' ? 1 : 0 },
		[ baseFilters, result ]
	);

	const list = useListView< Visit >( {
		path: '/visits',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts(
		'/visits',
		'converted',
		[ '1', '0' ],
		baseFilters
	);

	const fields: DataViewField< Visit >[] = [
		{
			id: 'id',
			label: __( 'Visit ID', 'flyaffiliate' ),
			render: ( { item } ) => (
				<span className="font-medium">#{ item.id }</span>
			),
		},
		{
			id: 'affiliate',
			label: __( 'Affiliate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Link
					to={ `/affiliates/${ item.affiliate_id }` }
					className="font-medium text-primary hover:underline"
				>
					<Truncated
						text={
							item.affiliate_name || `#${ item.affiliate_id }`
						}
						max={ 22 }
					/>
				</Link>
			),
		},
		{
			id: 'url',
			label: __( 'Visitor Landing Page', 'flyaffiliate' ),
			enableGlobalSearch: true,
			enableSorting: false,
			render: ( { item } ) => (
				<a
					href={ item.url }
					target="_blank"
					rel="noopener noreferrer"
					className="text-foreground hover:text-primary hover:underline"
				>
					<Truncated
						text={ item.url.replace( /^https?:\/\//, '' ) }
						max={ 40 }
					/>
				</a>
			),
		},
		{
			id: 'referrer',
			label: __( 'Referrer URL', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Truncated
					className="text-muted-foreground"
					text={
						item.referrer
							? item.referrer.replace( /^https?:\/\//, '' )
							: __( 'Direct', 'flyaffiliate' )
					}
					max={ 24 }
				/>
			),
		},
		{
			id: 'converted',
			label: __( 'Result', 'flyaffiliate' ),
			render: ( { item } ) => <VisitResult visit={ item } />,
		},
		{
			id: 'order',
			label: __( 'Order', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.order_id ? (
					<a
						href={ `${ urls.orders }${ item.order_id }` }
						className="font-medium text-primary hover:underline"
					>
						#{ item.order_id }
					</a>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => (
				<DateTime value={ item.created_at } withTime />
			),
		},
	];

	const tabs = buildTabs(
		[
			{ value: 'all', label: __( 'All', 'flyaffiliate' ) },
			{ value: 'converted', label: __( 'Converted', 'flyaffiliate' ) },
			{
				value: 'not_converted',
				label: __( 'Not converted', 'flyaffiliate' ),
			},
		],
		{
			all: counts.counts.all,
			converted: counts.counts[ '1' ],
			not_converted: counts.counts[ '0' ],
		},
		counts.loading
	);

	return (
		<DataViews< Visit >
			namespace="flyaffiliate-visits"
			data={ list.items }
			fields={ fields }
			view={ list.view }
			onChangeView={ list.setView }
			isLoading={ list.loading || counts.loading }
			paginationInfo={ list.paginationInfo }
			defaultLayouts={ { table: {} } }
			getItemId={ ( item ) => String( item.id ) }
			search
			searchPlaceholder={ __( 'Search URLs…', 'flyaffiliate' ) }
			emptyTitle={ __( 'No visits found', 'flyaffiliate' ) }
			emptyDescription={ __(
				'A visit is recorded every time a referral link is followed.',
				'flyaffiliate'
			) }
			tabs={ {
				items: tabs,
				defaultValue: result,
				onSelect: setResult,
				headerContent,
			} }
		/>
	);
}

export default function VisitsPage() {
	const [ affiliate, setAffiliate ] = useState< number | null >( null );

	return (
		<>
			<PageHeader
				title={ __( 'Visits', 'flyaffiliate' ) }
				description={ __(
					'IP addresses and user agents are stored as hashes. Only the pages visited are kept as they are.',
					'flyaffiliate'
				) }
			/>
			<VisitsTable
				affiliateId={ affiliate ?? undefined }
				headerContent={ [
					<div key="affiliate" className="w-52">
						<AffiliatePicker
							value={ affiliate }
							onChange={ setAffiliate }
							placeholder={ __(
								'All affiliates',
								'flyaffiliate'
							) }
						/>
					</div>,
				] }
			/>
		</>
	);
}
