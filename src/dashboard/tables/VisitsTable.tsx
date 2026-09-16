/**
 * The affiliate's visits: every click on their link.
 */
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	DataViews,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import VisitResult from '@/components/VisitResult';
import DateTime from '@/components/DateTime';
import Truncated from '@/components/Truncated';
import { useListView } from '@/hooks/useListView';
import { useCounts } from '@/hooks/useCounts';
import { buildTabs } from '@/lib/tabs';
import type { Visit } from '@/lib/types';
import type { DateArgs } from '../types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype's affiliate area: the URL leads, the
	// date closes.
	fields: [ 'referrer', 'converted', 'order', 'created_at' ],
	titleField: 'url',
	layout: {
		styles: {
			url: { width: '32%' },
			referrer: { width: '20%' },
			converted: { width: '14%' },
			order: { width: '12%' },
			created_at: { width: '22%' },
		},
	},
};

export default function VisitsTable( { dates }: { dates: DateArgs } ) {
	const [ result, setResult ] = useState( 'all' );
	const filters = useMemo(
		() =>
			result === 'all'
				? dates
				: { ...dates, converted: result === 'converted' ? 1 : 0 },
		[ result, dates ]
	);
	const list = useListView< Visit >( {
		path: '/me/visits',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts( '/me/visits', 'converted', [ '1', '0' ], dates );

	const fields: DataViewField< Visit >[] = [
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => (
				<DateTime value={ item.created_at } withTime />
			),
		},
		{
			id: 'url',
			label: __( 'Landing page', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Truncated
					text={ item.url.replace( /^https?:\/\//, '' ) }
					max={ 44 }
					className="text-foreground"
				/>
			),
		},
		{
			id: 'referrer',
			label: __( 'Referrer', 'flyaffiliate' ),
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
					<span className="font-medium">#{ item.order_id }</span>
				) : (
					<span className="text-muted-foreground">—</span>
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
			namespace="flyaffiliate-my-visits"
			data={ list.items }
			fields={ fields }
			view={ list.view }
			onChangeView={ list.setView }
			isLoading={ list.loading || counts.loading }
			paginationInfo={ list.paginationInfo }
			defaultLayouts={ { table: {} } }
			getItemId={ ( item ) => String( item.id ) }
			search={ false }
			emptyTitle={ __( 'No visits yet', 'flyaffiliate' ) }
			emptyDescription={ __(
				'A visit is recorded every time someone follows your link.',
				'flyaffiliate'
			) }
			tabs={ { items: tabs, defaultValue: result, onSelect: setResult } }
		/>
	);
}
