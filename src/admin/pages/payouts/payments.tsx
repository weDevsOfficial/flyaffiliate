/**
 * Payments: one row per affiliate per payout. Created unpaid when a payout is
 * made, marked paid once the money has been sent — which is what marks the
 * commissions in it paid — the way SliceWP's payments work. A payment an
 * automatic payout method sends shows its transfer, is locked while the money
 * is on its way, and can be sent again when the transfer failed.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { Check, Download, Eye, Layers, RotateCw, Trash2 } from 'lucide-react';
import {
	Badge,
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
import Hint from '@/components/Hint';
import PaymentStatus from '@/components/PaymentStatus';
import { useCounts } from '@/hooks/useCounts';
import { useListView } from '@/hooks/useListView';
import { errorMessage, send } from '@/lib/api';
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

/**
 * One payout's payments, on the payout's own page: no Payout column, which
 * would only link back to the page it is on.
 */
const BATCH_VIEW: DataViewState = {
	...DEFAULT_VIEW,
	fields: DEFAULT_VIEW.fields?.filter( ( field ) => field !== 'batch' ),
	layout: {
		styles: {
			id: { width: '8%' },
			affiliate: { width: '14%' },
			amount: { width: '10%' },
			commissions: { width: '10%' },
			payment_email: { width: '15%' },
			reference: { width: '10%' },
			status: { width: '12%' },
			method: { width: '9%' },
			created_at: { width: '12%' },
		},
	},
};

const STATUSES = [ 'unpaid', 'paid' ];

/**
 * A column a payout method adds to the payments table, drawn with
 * FlyAffiliate's own cells: its text, and an info tip when it explains it.
 * Added through the `flyaffiliate_payment_columns` filter; it reads what the
 * method put in each payment's `transfer`.
 */
export type PaymentColumn = {
	id: string;
	label: string;
	/** Its width, as the other columns give theirs (`'9%'`). */
	width?: string;
	text: ( item: Payout ) => string;
	hint?: ( item: Payout ) => string;
	/** Whether the page's rows need the column at all; always when absent. */
	isVisible?: ( items: Payout[] ) => boolean;
};

/**
 * The columns payout methods add, read once: the filter is registered before FlyAffiliate's app runs.
 *
 * @return {PaymentColumn[]} The columns.
 */
function getExtraColumns(): PaymentColumn[] {
	const columns = applyFilters( 'flyaffiliate_payment_columns', [] );

	return Array.isArray( columns )
		? columns.filter(
				( column ): column is PaymentColumn =>
					!! column?.id && typeof column.text === 'function'
		  )
		: [];
}

/**
 * The view with the added columns that the rows need, after Status. The
 * columns' widths shrink in proportion to make room, so the table keeps its
 * width and no column is cut short.
 *
 * @param {DataViewState}   view    The view.
 * @param {PaymentColumn[]} columns The added columns that show.
 * @return {DataViewState} The view.
 */
function withColumns(
	view: DataViewState,
	columns: PaymentColumn[]
): DataViewState {
	if ( ! columns.length ) {
		return view;
	}

	const fields = ( view.fields ?? [] ).filter(
		( field ) => ! columns.some( ( column ) => column.id === field )
	);
	const at = fields.indexOf( 'status' ) + 1 || fields.length;
	const styles: Record< string, { width?: string } > =
		( view.layout as { styles?: Record< string, { width?: string } > } )
			?.styles ?? {};
	const widths: Record< string, number > = Object.fromEntries( [
		...fields.map( ( field ) => [
			field,
			parseFloat( styles[ field ]?.width ?? '' ) || 0,
		] ),
		...columns.map( ( column ) => [
			column.id,
			parseFloat( column.width ?? '9%' ),
		] ),
	] );
	const total = Object.values( widths ).reduce( ( a, b ) => a + b, 0 );
	const before = total - columns.reduce( ( a, c ) => a + widths[ c.id ], 0 );

	fields.splice( at, 0, ...columns.map( ( column ) => column.id ) );

	return {
		...view,
		fields,
		layout: {
			...view.layout,
			styles: {
				...styles,
				...Object.fromEntries(
					Object.entries( widths )
						.filter( ( [ , width ] ) => width > 0 )
						.map( ( [ id, width ] ) => [
							id,
							{
								...styles[ id ],
								width: `${ (
									( width * before ) /
									total
								).toFixed( 2 ) }%`,
							},
						] )
				),
			},
		},
	} as DataViewState;
}

type TableProps = {
	affiliateId?: number;
	batchKey?: string;
	headerContent?: React.ReactNode[];
	/** Bump to reload the rows after something outside the table changed them. */
	refreshKey?: number;
	/** Bump to reload the rows quietly, keeping them on screen (polling). */
	pollKey?: number;
	onChanged?: () => void;
};

export function PaymentsTable( {
	affiliateId,
	batchKey,
	headerContent = [],
	refreshKey = 0,
	pollKey = 0,
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
		defaultView: batchKey ? BATCH_VIEW : DEFAULT_VIEW,
		filters,
	} );
	const counts = useCounts( '/payouts', 'status', STATUSES, baseFilters );
	const extraColumns = useMemo( getExtraColumns, [] );
	const shownColumns = extraColumns.filter(
		( column ) => ! column.isVisible || column.isVisible( list.items )
	);

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

	useEffect( () => {
		if ( pollKey > 0 ) {
			list.refresh( { silent: true } );
			counts.refresh( { silent: true } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pollKey ] );

	const retry = async ( items: Payout[] ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					send( `/payouts/${ item.id }/retry`, 'POST' )
				)
			);
			toast.success(
				sprintf(
					/* translators: %d: number of payments */
					_n(
						'%d payment sent again.',
						'%d payments sent again.',
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
					__( 'The payment could not be sent again.', 'flyaffiliate' )
				)
			);
			refresh();
		}
	};

	// Paid is the end of a payment's life, as in SliceWP: the row menu offers
	// "Mark as paid" and nothing to take it back. `PUT { status: 'unpaid' }`
	// still exists for the API.
	const markPaid = async ( items: Payout[] ) => {
		try {
			await Promise.all(
				items.map( ( item ) =>
					send( `/payouts/${ item.id }`, 'PUT', { status: 'paid' } )
				)
			);
			toast.success(
				sprintf(
					/* translators: %d: number of payments */
					_n(
						'%d payment marked paid.',
						'%d payments marked paid.',
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

	const fields: DataViewField< Payout >[] = [
		{
			id: 'id',
			label: __( 'Payment ID', 'flyaffiliate' ),
			render: ( { item } ) => (
				<Link
					to={ `/payouts/payment/${ item.id }` }
					className="font-medium text-primary hover:underline"
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
					text={ item.reference || item.transfer?.reference || '—' }
					max={ 18 }
				/>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => <PaymentStatus item={ item } />,
		},
		{
			id: 'method',
			label: __( 'Paid via', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.method,
			render: ( { item } ) => (
				<Badge variant="secondary">
					{ item.method_label || item.method || '—' }
				</Badge>
			),
		},
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			render: ( { item } ) => (
				<DateTime value={ item.created_at } withTime />
			),
		},
		...extraColumns.map(
			( column ): DataViewField< Payout > => ( {
				id: column.id,
				label: column.label,
				enableSorting: false,
				render: ( { item } ) => {
					const hint = column.hint?.( item ) ?? '';

					return (
						<span className="inline-flex items-center gap-1.5 tabular-nums">
							{ column.text( item ) || (
								<span className="text-muted-foreground">—</span>
							) }
							{ hint && <Hint text={ hint } /> }
						</span>
					);
				},
			} )
		),
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
				'Marks every commission in the payment as paid. Do this once the money has been sent.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status === 'unpaid' && ! item.locked,
			callback: markPaid,
		},
		{
			id: 'retry',
			label: __( 'Retry', 'flyaffiliate' ),
			icon: <RotateCw size={ 16 } />,
			supportsBulk: true,
			isEligible: ( item ) => !! item.can_retry,
			callback: retry,
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
				'The commissions in it go back to unpaid and into the next payout. A paid payment can’t be deleted.',
				'flyaffiliate'
			),
			isEligible: ( item ) => item.status === 'unpaid' && ! item.locked,
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
			view={ withColumns( list.view, shownColumns ) }
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
					'Every payment across all payouts. Mark a payment paid once the money has been sent. That also marks its commissions paid.',
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
