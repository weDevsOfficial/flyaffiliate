/**
 * The affiliate's payouts: what has been paid, and when. A payout opens into
 * the commissions it paid, as SliceWP's affiliate account expands a payment.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ListOrdered } from 'lucide-react';
import {
	DataViews,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import StatusBadge from '@/components/StatusBadge';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import Truncated from '@/components/Truncated';
import { useListView } from '@/hooks/useListView';
import { withIconLabels } from '@/lib/actions';
import type { Payout } from '@/lib/types';
import PayoutCommissions from '../components/PayoutCommissions';
import type { DateArgs } from '../types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype's affiliate area: topic, amount,
	// reference, status, date.
	fields: [ 'amount', 'reference', 'status', 'created_at' ],
	titleField: 'note',
	layout: {
		styles: {
			note: { width: '26%' },
			amount: { width: '18%' },
			reference: { width: '20%' },
			status: { width: '14%' },
			created_at: { width: '22%' },
		},
	},
};

export default function PayoutsTable( { dates }: { dates: DateArgs } ) {
	const [ open, setOpen ] = useState< Payout | null >( null );
	const list = useListView< Payout >( {
		path: '/me/payouts',
		defaultView: DEFAULT_VIEW,
		filters: dates,
	} );

	const fields: DataViewField< Payout >[] = [
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => (
				<DateTime value={ item.created_at } withTime />
			),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'flyaffiliate' ),
			render: ( { item } ) => (
				<div>
					<Money amount={ item.amount } className="font-semibold" />
					<div className="text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %d: number of commissions */
							_n(
								'%d commission',
								'%d commissions',
								item.commissions.length,
								'flyaffiliate'
							),
							item.commissions.length
						) }
					</div>
				</div>
			),
		},
		{
			id: 'note',
			label: __( 'Payout', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Truncated
					text={
						item.note || __( 'Untitled payout', 'flyaffiliate' )
					}
					max={ 26 }
				/>
			),
		},
		{
			id: 'reference',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Truncated
					className="text-muted-foreground"
					text={ item.reference || '—' }
					max={ 22 }
				/>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => <StatusBadge status={ item.status } />,
		},
	];

	// The row's action menu, as on the commissions list; the row itself
	// opens the same dialog, the way SliceWP's row expands.
	const actions: DataViewAction< Payout >[] = [
		{
			id: 'commissions',
			label: __( 'View commissions', 'flyaffiliate' ),
			icon: <ListOrdered size={ 16 } />,
			callback: ( [ item ] ) => setOpen( item ),
		},
	];

	return (
		<>
			<DataViews< Payout >
				namespace="flyaffiliate-my-payouts"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				isLoading={ list.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				onClickItem={ setOpen }
				isItemClickable={ () => true }
				search={ false }
				emptyTitle={ __( 'No payouts yet', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Once your unpaid balance is paid out, the record appears here.',
					'flyaffiliate'
				) }
				tabs={ { items: [] } }
			/>
			<PayoutCommissions
				payout={ open }
				onClose={ () => setOpen( null ) }
			/>
		</>
	);
}
