/**
 * Payouts: each one a batch of payments, one per affiliate, created unpaid
 * and marked paid once the money has been sent — SliceWP's two steps.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { Check, Download, Eye, Plus, Trash2 } from 'lucide-react';
import {
	Button,
	DataViews,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import Truncated from '@/components/Truncated';
import { useListView } from '@/hooks/useListView';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { withIconLabels } from '@/lib/actions';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { PayoutBatch } from '@/lib/types';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	fields: [ 'total', 'payments', 'progress', 'created_at' ],
	titleField: 'note',
	layout: {
		styles: {
			note: { width: '30%' },
			total: { width: '15%' },
			payments: { width: '15%' },
			progress: { width: '22%' },
			created_at: { width: '18%' },
		},
	},
};

/**
 * The CSV download of a batch.
 *
 * @param {string} batchKey The batch.
 * @return {string} The URL.
 */
export function csvUrl( batchKey: string ): string {
	const { urls } = getGlobals();

	return `${ urls.payoutCsv }&batch=${ encodeURIComponent( batchKey ) }`;
}

/**
 * How many payments of a batch are still to pay.
 *
 * @param {PayoutBatch} batch The batch.
 * @return {number} The count.
 */
export const unpaidCount = ( batch: PayoutBatch ) =>
	batch.affiliates - batch.paid;

export default function PayoutsPage() {
	const navigate = useNavigate();
	const [ selection, setSelection ] = useState< string[] >( [] );
	const list = useListView< PayoutBatch >( {
		path: '/payouts/batches',
		defaultView: DEFAULT_VIEW,
	} );

	const refresh = () => {
		setSelection( [] );
		list.refresh();
	};

	const fields: DataViewField< PayoutBatch >[] = [
		{
			id: 'note',
			label: __( 'Payout', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<div className="flex min-w-0 flex-col gap-1">
					<Link
						to={ `/payouts/batch/${ item.batch_key }` }
						className="font-medium text-primary hover:underline"
					>
						<Truncated
							text={
								item.note ||
								__( 'Untitled payout', 'flyaffiliate' )
							}
							max={ 32 }
						/>
					</Link>
					<span className="block font-mono text-xs text-muted-foreground">
						{ item.batch_key.slice( 0, 8 ) }
					</span>
				</div>
			),
		},
		{
			id: 'total',
			label: __( 'Amount', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Money amount={ item.total } className="font-semibold" />
			),
		},
		{
			id: 'payments',
			label: __( 'Payments', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<div>
					<div>
						{ sprintf(
							/* translators: %d: number of payments */
							_n(
								'%d payment',
								'%d payments',
								item.affiliates,
								'flyaffiliate'
							),
							item.affiliates
						) }
					</div>
					<div className="text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %d: number of paid payments */
							__( '%d paid', 'flyaffiliate' ),
							item.paid
						) }
					</div>
				</div>
			),
		},
		{
			id: 'progress',
			label: __( 'Paid / still to pay', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<div className="flex items-center gap-1.5 tabular-nums">
					<Money
						amount={ item.paid_total }
						className="text-foreground"
					/>
					<span className="text-muted-foreground">/</span>
					<Money
						amount={ item.total - item.paid_total }
						className={
							unpaidCount( item ) > 0
								? 'font-medium text-foreground'
								: 'text-muted-foreground'
						}
					/>
				</div>
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

	const actions: DataViewAction< PayoutBatch >[] = [
		{
			id: 'view',
			label: __( 'View payout', 'flyaffiliate' ),
			icon: <Eye size={ 16 } />,
			callback: ( [ item ] ) =>
				navigate( `/payouts/batch/${ item.batch_key }` ),
		},
		{
			id: 'pay',
			label: __( 'Mark all as paid', 'flyaffiliate' ),
			icon: <Check size={ 16 } />,
			confirmTitle: __( 'Mark all as paid', 'flyaffiliate' ),
			confirmMessage: __(
				'Every unpaid payment in this payout is marked paid, and every commission in them with it.',
				'flyaffiliate'
			),
			isEligible: ( item ) => unpaidCount( item ) > 0,
			callback: async ( [ item ] ) => {
				try {
					const result = await send< { paid: number } >(
						`/payouts/batch/${ item.batch_key }/pay`,
						'POST'
					);
					toast.success(
						sprintf(
							/* translators: %d: number of payments */
							_n(
								'%d payment marked paid.',
								'%d payments marked paid.',
								result.paid,
								'flyaffiliate'
							),
							result.paid
						)
					);
					refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The payout could not be marked paid.',
								'flyaffiliate'
							)
						)
					);
				}
			},
		},
		{
			id: 'csv',
			label: __( 'Download CSV', 'flyaffiliate' ),
			icon: <Download size={ 16 } />,
			callback: ( [ item ] ) => {
				window.location.assign( csvUrl( item.batch_key ) );
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'flyaffiliate' ),
			icon: <Trash2 size={ 16 } />,
			isDestructive: true,
			confirmTitle: __( 'Delete payout', 'flyaffiliate' ),
			confirmMessage: __(
				'Its payments are deleted and their commissions go back to unpaid, ready for the next payout. A payout with a paid payment cannot be deleted.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.paid === 0,
			callback: async ( [ item ] ) => {
				try {
					await send(
						`/payouts/batch/${ item.batch_key }`,
						'DELETE'
					);
					toast.success( __( 'Payout deleted.', 'flyaffiliate' ) );
					refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The payout could not be deleted.',
								'flyaffiliate'
							)
						)
					);
				}
			},
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Payouts', 'flyaffiliate' ) }
				description={ __(
					'A payout turns unpaid commissions into one payment per affiliate. Pay them the way you normally would, then mark each payment paid.',
					'flyaffiliate'
				) }
				actions={
					<>
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							render={ <Link to="/payouts/payments" /> }
							data-testid="flyaffiliate-all-payments"
						>
							{ __( 'All payments', 'flyaffiliate' ) }
						</Button>
						<Button
							data-testid="flyaffiliate-new-payout"
							render={ <Link to="/payouts/new" /> }
						>
							<Plus className="size-4" />
							{ __( 'New payout', 'flyaffiliate' ) }
						</Button>
					</>
				}
			/>
			<DataViews< PayoutBatch >
				namespace="flyaffiliate-payouts"
				data={ list.items }
				fields={ fields }
				view={ list.view }
				onChangeView={ list.setView }
				actions={ withIconLabels( actions ) }
				selection={ selection }
				onChangeSelection={ setSelection }
				isLoading={ list.loading }
				paginationInfo={ list.paginationInfo }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => item.batch_key }
				search={ false }
				emptyTitle={ __( 'No payouts yet', 'flyaffiliate' ) }
				emptyDescription={ __(
					'Create a payout to see who is owed what.',
					'flyaffiliate'
				) }
				tabs={ { items: [], headerContent: [] } }
			/>
		</>
	);
}
