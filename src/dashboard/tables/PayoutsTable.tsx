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
import PaymentStatus from '@/components/PaymentStatus';
import DateTime from '@/components/DateTime';
import MethodName from '@/components/MethodName';
import Money from '@/components/Money';
import TruncatedLine from '@/components/TruncatedLine';
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
	// One fact per column: what it was, how much, how it is paid, where it
	// stands, when. What it covers (its commissions, its reference) sits
	// quietly under the title; a line too long for the column ends in an
	// ellipsis, whole in a tooltip. Five columns fit a theme's content
	// column beside a sidebar.
	fields: [ 'amount', 'method', 'status', 'created_at' ],
	titleField: 'note',
	layout: {
		styles: {
			note: { width: '32%' },
			amount: { width: '14%' },
			method: { width: '16%' },
			status: { width: '16%' },
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
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
		{
			id: 'amount',
			label: __( 'Amount', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Money amount={ item.amount } className="font-semibold" />
			),
		},
		{
			id: 'method',
			label: __( 'Method', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.method_label ? (
					<TruncatedLine tooltip={ item.method_label }>
						<MethodName
							id={ item.method }
							label={ item.method_label }
						/>
					</TruncatedLine>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'note',
			label: __( 'Payout', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => {
				const title =
					item.note || __( 'Untitled payout', 'flyaffiliate' );
				const covers: string[] = [
					sprintf(
						/* translators: %d: number of commissions */
						_n(
							'%d commission',
							'%d commissions',
							item.commissions.length,
							'flyaffiliate'
						),
						item.commissions.length
					),
				];

				if ( item.reference ) {
					covers.push(
						sprintf(
							/* translators: %s: the payment's reference, e.g. a bank transfer id */
							__( 'Reference %s', 'flyaffiliate' ),
							item.reference
						)
					);
				}

				const detail = covers.join( ' · ' );

				return (
					<div className="min-w-0">
						<TruncatedLine
							tooltip={ title }
							className="font-medium text-foreground"
						>
							{ title }
						</TruncatedLine>
						<TruncatedLine
							tooltip={ detail }
							className="text-xs text-muted-foreground"
						>
							{ detail }
						</TruncatedLine>
					</div>
				);
			},
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<PaymentStatus item={ item } forAffiliate badgeOnly />
			),
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
			<div className="flyaffiliate-fixed-columns">
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
			</div>
			<PayoutCommissions
				payout={ open }
				onClose={ () => setOpen( null ) }
			/>
		</>
	);
}
