/**
 * One payment: what it is, the commissions in it, and the actions on it —
 * mark it paid or unpaid, take a commission out while it is unpaid, delete it.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
	Check,
	ExternalLink,
	MinusCircle,
	RotateCcw,
	Trash2,
} from 'lucide-react';
import {
	Button,
	Card,
	DataViews,
	toast,
	type DataViewAction,
	type DataViewField,
	type DataViewState,
} from '@wedevs/plugin-ui';
import ConfirmDialog from '@/components/ConfirmDialog';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import PageHeader from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import { useListView } from '@/hooks/useListView';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { withIconLabels } from '@/lib/actions';
import { formatDate } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import type { Commission, Payout } from '@/lib/types';

const COMMISSIONS_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	sort: { field: 'created_at', direction: 'desc' },
	// Same order as the Commissions list: amount, reference, date, status.
	fields: [
		'base_amount',
		'rate',
		'amount',
		'order',
		'created_at',
		'status',
	],
	titleField: 'id',
	layout: {
		styles: {
			id: { width: '9%' },
			base_amount: { width: '15%' },
			rate: { width: '11%' },
			amount: { width: '15%' },
			order: { width: '16%' },
			created_at: { width: '22%' },
			status: { width: '12%' },
		},
	},
};

function Detail( {
	label,
	children,
}: {
	label: string;
	children: React.ReactNode;
} ) {
	return (
		<div className="flex flex-col gap-1">
			<dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
				{ label }
			</dt>
			<dd className="m-0 text-sm text-foreground">{ children }</dd>
		</div>
	);
}

export default function PaymentPage() {
	const { id = '' } = useParams();
	const paymentId = Number( id );
	const navigate = useNavigate();
	const { urls, statuses } = getGlobals();
	const [ payment, setPayment ] = useState< Payout | null >( null );
	const [ dialog, setDialog ] = useState< 'pay' | 'unpay' | 'delete' | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		fetchOne< Payout >( `/payouts/${ paymentId }` )
			.then( setPayment )
			.catch( () => setPayment( null ) );
	}, [ paymentId ] );

	useEffect( load, [ load ] );

	const filters = useMemo(
		() => ( { payout_id: paymentId } ),
		[ paymentId ]
	);
	const list = useListView< Commission >( {
		path: '/commissions',
		defaultView: COMMISSIONS_VIEW,
		filters,
	} );

	const isPaid = payment?.status === 'paid';

	const setStatus = async ( next: 'paid' | 'unpaid' ) => {
		setBusy( true );

		try {
			setPayment(
				await send< Payout >( `/payouts/${ paymentId }`, 'PUT', {
					status: next,
				} )
			);
			toast.success(
				next === 'paid'
					? __( 'Payment marked paid.', 'flyaffiliate' )
					: __( 'Payment marked unpaid.', 'flyaffiliate' )
			);
			setDialog( null );
			list.refresh();
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payment could not be updated.', 'flyaffiliate' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const remove = async () => {
		setBusy( true );

		try {
			await send( `/payouts/${ paymentId }`, 'DELETE' );
			toast.success( __( 'Payment deleted.', 'flyaffiliate' ) );
			navigate(
				payment ? `/payouts/batch/${ payment.batch_key }` : '/payouts'
			);
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payment could not be deleted.', 'flyaffiliate' )
				)
			);
			setBusy( false );
		}
	};

	const fields: DataViewField< Commission >[] = [
		{
			id: 'id',
			label: __( 'Commission ID', 'flyaffiliate' ),
			render: ( { item } ) => (
				<span className="font-medium">#{ item.id }</span>
			),
		},
		{
			id: 'order',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.order_id > 0 ? (
					<a
						href={ `${ urls.orders }${ item.order_id }` }
						className="inline-flex items-center gap-1 text-primary hover:underline"
					>
						#{ item.order_id }
						<ExternalLink className="size-3" aria-hidden="true" />
					</a>
				) : (
					<span className="text-muted-foreground">
						{ __( 'Manual', 'flyaffiliate' ) }
					</span>
				),
		},
		{
			id: 'base_amount',
			label: __( 'Sale', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => <Money amount={ item.base_amount } />,
		},
		{
			id: 'rate',
			label: __( 'Rate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.rate_type === 'fixed' ? (
					<Money amount={ item.rate } />
				) : (
					<span>{ Number( item.rate ) }%</span>
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
				<StatusBadge
					status={ item.status }
					label={ statuses.commission[ item.status ] }
				/>
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

	const actions: DataViewAction< Commission >[] = [
		{
			id: 'remove',
			label: __( 'Remove from payment', 'flyaffiliate' ),
			icon: <MinusCircle size={ 16 } />,
			isDestructive: true,
			confirmTitle: __( 'Remove from payment', 'flyaffiliate' ),
			confirmMessage: __(
				'The commission stays unpaid and goes into the next payout; this payment gets smaller by its amount.',
				'flyaffiliate'
			),
			isEligible: () => ! isPaid,
			callback: async ( [ item ] ) => {
				try {
					setPayment(
						await send< Payout >(
							`/payouts/${ paymentId }/commissions/${ item.id }`,
							'DELETE'
						)
					);
					toast.success(
						__(
							'Commission removed from the payment.',
							'flyaffiliate'
						)
					);
					list.refresh();
				} catch ( error ) {
					toast.error(
						errorMessage(
							error,
							__(
								'The commission could not be removed.',
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
				backTo={ {
					to: payment
						? `/payouts/batch/${ payment.batch_key }`
						: '/payouts',
					label: __( 'Back to the payout', 'flyaffiliate' ),
				} }
				title={ sprintf(
					/* translators: %d: payment id */
					__( 'Payment #%d', 'flyaffiliate' ),
					paymentId
				) }
				badge={ payment && <StatusBadge status={ payment.status } /> }
				description={
					payment
						? sprintf(
								/* translators: 1: affiliate name, 2: date */
								__( '%1$s · created %2$s', 'flyaffiliate' ),
								payment.affiliate_name ||
									`#${ payment.affiliate_id }`,
								formatDate( payment.created_at, true )
						  )
						: undefined
				}
				actions={
					payment && (
						<>
							{ ! isPaid && (
								<Button
									variant="outline"
									onClick={ () => setDialog( 'delete' ) }
									data-testid="flyaffiliate-payment-delete"
								>
									<Trash2 className="size-4" />
									{ __( 'Delete', 'flyaffiliate' ) }
								</Button>
							) }
							{ isPaid ? (
								<Button
									variant="outline"
									onClick={ () => setDialog( 'unpay' ) }
									data-testid="flyaffiliate-payment-unpay"
								>
									<RotateCcw className="size-4" />
									{ __( 'Mark as unpaid', 'flyaffiliate' ) }
								</Button>
							) : (
								<Button
									onClick={ () => setDialog( 'pay' ) }
									data-testid="flyaffiliate-payment-pay"
								>
									<Check className="size-4" />
									{ __( 'Mark as paid', 'flyaffiliate' ) }
								</Button>
							) }
						</>
					)
				}
			/>

			{ payment && (
				<Card className="mb-6 rounded-md border border-border px-5 py-4 shadow ring-0">
					<dl className="m-0 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
						<Detail label={ __( 'Affiliate', 'flyaffiliate' ) }>
							{ payment.affiliate_id > 0 ? (
								<Link
									to={ `/affiliates/${ payment.affiliate_id }` }
									className="font-medium text-primary hover:underline"
								>
									{ payment.affiliate_name ||
										`#${ payment.affiliate_id }` }
								</Link>
							) : (
								<span className="text-muted-foreground">
									{ __(
										'Unknown affiliate',
										'flyaffiliate'
									) }
								</span>
							) }
						</Detail>
						<Detail label={ __( 'Amount', 'flyaffiliate' ) }>
							<Money
								amount={ payment.amount }
								className="text-lg font-semibold"
							/>
							<div className="text-xs text-muted-foreground">
								{ sprintf(
									/* translators: %d: number of commissions */
									_n(
										'%d commission',
										'%d commissions',
										payment.commissions.length,
										'flyaffiliate'
									),
									payment.commissions.length
								) }
							</div>
						</Detail>
						<Detail label={ __( 'Payout', 'flyaffiliate' ) }>
							<Link
								to={ `/payouts/batch/${ payment.batch_key }` }
								className="text-primary hover:underline"
							>
								{ payment.note ||
									__( 'Untitled payout', 'flyaffiliate' ) }
							</Link>
							<div className="text-xs text-muted-foreground font-mono">
								{ payment.batch_key.slice( 0, 8 ) }
							</div>
						</Detail>
						<Detail label={ __( 'Reference', 'flyaffiliate' ) }>
							{ payment.reference || (
								<span className="text-muted-foreground">—</span>
							) }
						</Detail>
					</dl>
				</Card>
			) }

			<DataViews< Commission >
				namespace="flyaffiliate-payment-commissions"
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
				emptyTitle={ __(
					'No commissions in this payment',
					'flyaffiliate'
				) }
				emptyDescription={ __(
					'Every commission was removed. Delete the payment, or leave it as a record.',
					'flyaffiliate'
				) }
				tabs={ { items: [], headerContent: [] } }
			/>

			<ConfirmDialog
				open={ dialog === 'pay' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Mark as paid', 'flyaffiliate' ) }
				description={ __(
					'The money has been sent: every commission in this payment is marked paid.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Mark as paid', 'flyaffiliate' ) }
				onConfirm={ () => setStatus( 'paid' ) }
				busy={ busy }
			/>
			<ConfirmDialog
				open={ dialog === 'unpay' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Mark as unpaid', 'flyaffiliate' ) }
				description={ __(
					'Marked paid by mistake? The commissions go back to unpaid. They stay in this payment, so they are not paid twice.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Mark as unpaid', 'flyaffiliate' ) }
				onConfirm={ () => setStatus( 'unpaid' ) }
				busy={ busy }
			/>
			<ConfirmDialog
				open={ dialog === 'delete' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Delete payment', 'flyaffiliate' ) }
				description={ __(
					'The commissions in it go back to unpaid and into the next payout.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Delete payment', 'flyaffiliate' ) }
				onConfirm={ remove }
				destructive
				busy={ busy }
			/>
		</>
	);
}
