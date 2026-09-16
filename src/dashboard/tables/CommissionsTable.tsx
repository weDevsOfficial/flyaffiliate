/**
 * The affiliate's commissions, by status, each one open to its details.
 */
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Eye } from 'lucide-react';
import {
	DataViews,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import StatusBadge from '@/components/StatusBadge';
import DateTime from '@/components/DateTime';
import Matures from '@/components/Matures';
import Money from '@/components/Money';
import { useListView } from '@/hooks/useListView';
import { useCounts } from '@/hooks/useCounts';
import { withIconLabels } from '@/lib/actions';
import { getGlobals } from '@/lib/globals';
import { buildTabs } from '@/lib/tabs';
import type { Commission } from '@/lib/types';
import CommissionDetails from '../components/CommissionDetails';
import type { DateArgs } from '../types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype's affiliate area: reference, amount,
	// status, date.
	fields: [ 'base_amount', 'amount', 'status', 'created_at', 'matures_at' ],
	titleField: 'order',
	layout: {
		styles: {
			order: { width: '14%' },
			base_amount: { width: '17%' },
			amount: { width: '17%' },
			status: { width: '13%' },
			created_at: { width: '20%' },
			matures_at: { width: '19%' },
		},
	},
};

export default function CommissionsTable( { dates }: { dates: DateArgs } ) {
	const { statuses, sources } = getGlobals();
	const [ status, setStatus ] = useState( 'all' );
	const [ detailId, setDetailId ] = useState< number | null >( null );
	const filters = useMemo(
		() => ( status === 'all' ? dates : { ...dates, status } ),
		[ status, dates ]
	);
	const list = useListView< Commission >( {
		path: '/me/commissions',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts(
		'/me/commissions',
		'status',
		Object.keys( statuses.commission ),
		dates
	);

	const fields: DataViewField< Commission >[] = [
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
		{
			id: 'order',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.order_id ? (
					<span className="font-medium">#{ item.order_id }</span>
				) : (
					<span className="text-muted-foreground">
						{ sources[ item.source ] ?? item.source }
					</span>
				),
		},
		{
			id: 'base_amount',
			label: __( 'Sale', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				Number( item.base_amount ) > 0 ? (
					<Money
						amount={ item.base_amount }
						className="text-muted-foreground"
					/>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Money amount={ item.amount } className="font-semibold" />
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<StatusBadge status={ item.status } kind="commission" />
			),
		},
		{
			id: 'matures_at',
			label: __( 'Matures', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Matures
					maturesAt={ item.matures_at }
					createdAt={ item.created_at }
				/>
			),
		},
	];

	// The row's action menu, as on the admin lists, rather than a link column.
	const actions: DataViewAction< Commission >[] = [
		{
			id: 'details',
			label: __( 'Details', 'flyaffiliate' ),
			icon: <Eye size={ 16 } />,
			callback: ( [ item ] ) => setDetailId( item.id ),
		},
	];

	const tabs = buildTabs(
		[
			{ value: 'all', label: __( 'All', 'flyaffiliate' ) },
			...Object.entries( statuses.commission ).map(
				( [ value, label ] ) => ( { value, label } )
			),
		],
		counts.counts,
		counts.loading
	);

	return (
		<>
			<DataViews< Commission >
				namespace="flyaffiliate-my-commissions"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				isLoading={ list.loading || counts.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				search={ false }
				emptyTitle={ __( 'No commissions yet', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Every sale made through your link shows up here.',
					'flyaffiliate'
				) }
				tabs={ {
					items: tabs,
					defaultValue: status,
					onSelect: setStatus,
				} }
			/>
			<CommissionDetails
				commissionId={ detailId }
				onClose={ () => setDetailId( null ) }
			/>
		</>
	);
}
