/**
 * Payments: one row per affiliate per payout. Created unpaid when a payout is
 * made, marked paid once the money has been sent — which is what marks the
 * commissions in it paid — the way SliceWP's payments work.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { Check, Download, Eye, Layers, RotateCcw, Trash2 } from 'lucide-react';
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
import StatusBadge from '@/components/StatusBadge';
import Truncated from '@/components/Truncated';
import { useCounts } from '@/hooks/useCounts';
import { useListView } from '@/hooks/useListView';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { withIconLabels } from '@/lib/actions';
import { buildTabs } from '@/lib/tabs';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Payout } from '@/lib/types';
import { csvUrl } from './index';

const DEFAULT_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Column order follows the prototype's payouts table: affiliate, topic,
	// amount, commissions, payment email, status, date.
	fields: [
		'affiliate',
		'batch',
		'amount',
		'commissions',
		'payment_email',
		'reference',
		'status',
		'method',
		'created_at',
	],
	titleField: 'id',
	layout: {
		// Explicit widths, or the last column absorbs the leftover space.
		styles: {
			id: { width: '7%' },
			affiliate: { width: '12%' },
			batch: { width: '14%' },
			amount: { width: '9%' },
			commissions: { width: '9%' },
			payment_email: { width: '13%' },
			reference: { width: '9%' },
			status: { width: '8%' },
			method: { width: '8%' },
			created_at: { width: '11%' },
		},
	},
};

const STATUSES = [ 'unpaid', 'paid' ];

type TableProps = {
	affiliateId?: number;
	batchKey?: string;
	headerContent?: React.ReactNode[];
	/** Bump to reload the rows after something outside the table changed them. */
	refreshKey?: number;
	onChanged?: () => void;
};

export function PaymentsTable( {
	affiliateId,
	batchKey,
	headerContent = [],
	refreshKey = 0,
	onChanged,
}: TableProps ) {
	const navigate = useNavigate();
	const [ status, setStatus ] = useState( 'all' );
	// Controlled, or plugin-ui never shows the bulk toolbar (as in Dokan).
	const [ selection, setSelection ] = useState< string[] >( [] );
	const baseFilters = useMemo(
		() => ( {
			...( affiliateId ? { affiliate_id: affiliateId } : {} ),
			...( batchKey ? { batch_key: batchKey } : {} ),
		} ),
		[ affiliateId, batchKey ]
	);
	const filters = useMemo(
		() => ( status === 'all' ? baseFilters : { ...baseFilters, status } ),
		[ baseFilters, status ]
	);
	const list = useListView< Payout >( {
		path: '/payouts',
		defaultView: DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts( '/payouts', 'status', STATUSES, baseFilters );

	const refresh = () => {
		setSelection( [] );
		list.refresh();
		counts.refresh();
		onChanged?.();
	};

	useEffect( () => {
		if ( refreshKey > 0 ) {
			setSelection( [] );
			list.refresh();
			counts.refresh();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ refreshKey ] );

	const setStatuses = async ( items: Payout[], next: 'paid' | 'unpaid' ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					send( `/payouts/${ item.id }`, 'PUT', { status: next } )
				)
			);
			toast.success(
				next === 'paid'
					? sprintf(
							/* translators: %d: number of payments */
							_n(
								'%d payment marked paid.',
								'%d payments marked paid.',
								items.length,
								'flyaffiliate'
							),
							items.length
					  )
					: sprintf(
							/* translators: %d: number of payments */
							_n(
								'%d payment marked unpaid.',
								'%d payments marked unpaid.',
								items.length,
								'flyaffiliate'
							),
							items.length
					  )
			);
			refresh();
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payment could not be updated.', 'flyaffiliate' )
				)
			);
		}
	};

	const { methods } = getGlobals();

	const fields: DataViewField< Payout >[] = [
		{
			id: 'id',
			label: __( 'Payment ID', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Link
					to={ `/payouts/payment/${ item.id }` }
					className="font-medium text-foreground hover:text-primary hover:underline"
				>
					#{ item.id }
				</Link>
			),
		},
		{
			id: 'affiliate',
			label: __( 'Affiliate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.affiliate_id > 0 ? (
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
				) : (
					<span className="text-muted-foreground">
						{ __( 'Unknown affiliate', 'flyaffiliate' ) }
					</span>
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
			id: 'commissions',
			label: __( 'Commissions', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.commissions.length,
			render: ( { item } ) => (
				<span className="tabular-nums">
					{ item.commissions.length }
				</span>
			),
		},
		{
			id: 'payment_email',
			label: __( 'Payment email', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.payment_email ? (
					<Truncated
						text={ item.payment_email }
						max={ 24 }
						className="text-muted-foreground"
					/>
				) : (
					<span className="text-muted-foreground">—</span>
				),
		},
		{
			id: 'batch',
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
							max={ 24 }
						/>
					</Link>
					<span className="block font-mono text-xs text-muted-foreground">
						{ item.batch_key.slice( 0, 8 ) }
					</span>
				</div>
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
					max={ 18 }
				/>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => <StatusBadge status={ item.status } />,
		},
		{
			id: 'method',
			label: __( 'Method', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.method,
			render: ( { item } ) => (
				<span className="text-muted-foreground">
					{ methods[ item.method ] ?? item.method ?? '—' }
				</span>
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

	const actions: DataViewAction< Payout >[] = [
		{
			id: 'view',
			label: __( 'View payment', 'flyaffiliate' ),
			icon: <Eye size={ 16 } />,
			callback: ( [ item ] ) =>
				navigate( `/payouts/payment/${ item.id }` ),
		},
		{
			id: 'pay',
			label: __( 'Mark as paid', 'flyaffiliate' ),
			icon: <Check size={ 16 } />,
			supportsBulk: true,
			confirmTitle: __( 'Mark as paid', 'flyaffiliate' ),
			confirmMessage: __(
				'The money has been sent: every commission in the payment is marked paid.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status === 'unpaid',
			callback: ( items ) => setStatuses( items, 'paid' ),
		},
		{
			id: 'unpay',
			label: __( 'Mark as unpaid', 'flyaffiliate' ),
			icon: <RotateCcw size={ 16 } />,
			supportsBulk: true,
			confirmTitle: __( 'Mark as unpaid', 'flyaffiliate' ),
			confirmMessage: __(
				'Marked paid by mistake? The commissions go back to unpaid. They stay in this payment, so they are not paid twice.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status === 'paid',
			callback: ( items ) => setStatuses( items, 'unpaid' ),
		},
		{
			id: 'batch',
			label: __( 'View payout', 'flyaffiliate' ),
			icon: <Layers size={ 16 } />,
			isEligible: () => ! batchKey,
			callback: ( [ item ] ) =>
				navigate( `/payouts/batch/${ item.batch_key }` ),
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
			supportsBulk: true,
			confirmTitle: __( 'Delete payment', 'flyaffiliate' ),
			confirmMessage: __(
				'The commissions in it go back to unpaid and into the next payout. A paid payment cannot be deleted.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status === 'unpaid',
			callback: async ( items ) => {
				try {
					await Promise.all(
						items.map( ( item ) =>
							send( `/payouts/${ item.id }`, 'DELETE' )
						)
					);
					toast.success(
						sprintf(
							/* translators: %d: number of payments */
							_n(
								'%d payment deleted.',
								'%d payments deleted.',
								items.length,
								'flyaffiliate'
							),
							items.length
						)
					);
					refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The payment could not be deleted.',
								'flyaffiliate'
							)
						)
					);
				}
			},
		},
	];

	const tabs = buildTabs(
		[
			{ value: 'all', label: __( 'All', 'flyaffiliate' ) },
			{ value: 'unpaid', label: __( 'Unpaid', 'flyaffiliate' ) },
			{ value: 'paid', label: __( 'Paid', 'flyaffiliate' ) },
		],
		counts.counts,
		counts.loading
	);

	return (
		<DataViews< Payout >
			namespace="flyaffiliate-payments"
			data={ list.items }
			fields={ fields }
			view={ list.view }
			onChangeView={ list.setView }
			actions={ withIconLabels( actions ) }
			selection={ selection }
			onChangeSelection={ setSelection }
			isLoading={ list.loading || counts.loading }
			paginationInfo={ list.paginationInfo }
			defaultLayouts={ { table: {} } }
			getItemId={ ( item ) => String( item.id ) }
			search={ false }
			emptyTitle={
				batchKey
					? __( 'Nothing in this payout', 'flyaffiliate' )
					: __( 'No payments found', 'flyaffiliate' )
			}
			emptyDescription={
				batchKey
					? __(
							'The link may be wrong, or the payout was deleted.',
							'flyaffiliate'
					  )
					: __(
							'Create a payout to turn unpaid commissions into payments.',
							'flyaffiliate'
					  )
			}
			tabs={ {
				items: tabs,
				defaultValue: status,
				onSelect: setStatus,
				headerContent,
			} }
		/>
	);
}

export default function PaymentsPage() {
	return (
		<>
			<PageHeader
				backTo={ {
					to: '/payouts',
					label: __( 'All payouts', 'flyaffiliate' ),
				} }
				title={ __( 'Payments', 'flyaffiliate' ) }
				description={ __(
					'Every payment across every payout. Mark one paid once the money has been sent; that is what marks its commissions paid.',
					'flyaffiliate'
				) }
				actions={
					<Button
						variant="outline"
						className={ BRAND_OUTLINE }
						render={ <Link to="/payouts/new" /> }
					>
						{ __( 'New payout', 'flyaffiliate' ) }
					</Button>
				}
			/>
			<PaymentsTable />
		</>
	);
}
