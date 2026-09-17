/**
 * One payment: what it is, the commissions in it, and the actions on it —
 * mark it paid, take a commission out while it is unpaid, delete it. Paid is
 * the end of the road on the screens, as in SliceWP; the API keeps
 * `PUT { status: 'unpaid' }` for a correction.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import {
	Check,
	ClipboardList,
	ExternalLink,
	Hash,
	MinusCircle,
	Pencil,
	Trash2,
	UserRound,
	Wallet,
} from 'lucide-react';
import {
	Button,
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
import Reference from '@/components/Reference';
import {
	HeaderSkeleton,
	StatGridSkeleton,
	TableSkeleton,
} from '@/components/PageSkeleton';
import StatCard, { StatCardSkeleton } from '@/components/StatCard';
import StatusBadge from '@/components/StatusBadge';
import Truncated from '@/components/Truncated';
import { useListView } from '@/hooks/useListView';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { withIconLabels } from '@/lib/actions';
import { formatDate, formatMoney } from '@/lib/format';
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

export default function PaymentPage() {
	const { id = '' } = useParams();
	const paymentId = Number( id );
	const navigate = useNavigate();
	const location = useLocation();
	const { statuses } = getGlobals();
	const [ payment, setPayment ] = useState< Payout | null >( null );
	const [ loading, setLoading ] = useState( true );
	// The commission form comes back here when it is done.
	const here = { from: location.pathname };
	const [ dialog, setDialog ] = useState< 'pay' | 'delete' | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		fetchOne< Payout >( `/payouts/${ paymentId }` )
			.then( setPayment )
			.catch( () => setPayment( null ) )
			.finally( () => setLoading( false ) );
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

	const markPaid = async () => {
		setBusy( true );

		try {
			setPayment(
				await send< Payout >( `/payouts/${ paymentId }`, 'PUT', {
					status: 'paid',
				} )
			);
			toast.success( __( 'Payment marked paid.', 'flyaffiliate' ) );
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
				<Link
					to={ `/commissions/${ item.id }/edit` }
					state={ here }
					className="font-medium text-primary hover:underline"
				>
					#{ item.id }
				</Link>
			),
		},
		{
			id: 'order',
			label: __( 'Reference', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Reference
					commission={ item }
					fallback={ __( 'Manual', 'flyaffiliate' ) }
				/>
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
			id: 'edit',
			label: __( 'Edit commission', 'flyaffiliate' ),
			icon: <Pencil size={ 16 } />,
			callback: ( [ item ] ) =>
				navigate( `/commissions/${ item.id }/edit`, { state: here } ),
		},
		{
			id: 'view-order',
			label: __( 'View order', 'flyaffiliate' ),
			icon: <ExternalLink size={ 16 } />,
			isEligible: ( item ) => Boolean( item.order_url ),
			callback: ( [ item ] ) => {
				window.location.assign( item.order_url ?? '' );
			},
		},
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

	if ( loading ) {
		return (
			<div data-testid="flyaffiliate-payment-loading">
				<HeaderSkeleton actions={ 2 } />
				<StatGridSkeleton />
				<TableSkeleton tabs={ 0 } rows={ 4 } columns={ 7 } />
			</div>
		);
	}

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
							{ ! isPaid && (
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

			<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				{ payment ? (
					<>
						<StatCard
							icon={ UserRound }
							label={ __( 'Affiliate', 'flyaffiliate' ) }
							value={
								payment.affiliate_id > 0 ? (
									<Link
										to={ `/affiliates/${ payment.affiliate_id }` }
										className="block truncate text-primary hover:underline"
									>
										<Truncated
											text={
												payment.affiliate_name ||
												`#${ payment.affiliate_id }`
											}
											max={ 24 }
										/>
									</Link>
								) : (
									<span className="text-muted-foreground">
										{ __(
											'Unknown affiliate',
											'flyaffiliate'
										) }
									</span>
								)
							}
							tooltip={
								payment.payment_email
									? sprintf(
											/* translators: %s: payment email */
											__( 'Paid to %s.', 'flyaffiliate' ),
											payment.payment_email
									  )
									: __(
											'No payment email on file.',
											'flyaffiliate'
									  )
							}
						/>
						<StatCard
							icon={ Wallet }
							label={ __( 'Amount', 'flyaffiliate' ) }
							value={ formatMoney( payment.amount ) }
							tooltip={ sprintf(
								/* translators: %d: number of commissions */
								_n(
									'The sum of %d commission.',
									'The sum of %d commissions.',
									payment.commissions.length,
									'flyaffiliate'
								),
								payment.commissions.length
							) }
						/>
						<StatCard
							icon={ ClipboardList }
							label={ __( 'Payout', 'flyaffiliate' ) }
							value={
								<Link
									to={ `/payouts/batch/${ payment.batch_key }` }
									className="block truncate text-primary hover:underline"
								>
									{ /* The whole name on hover when the tile cuts it, as the lists do. */ }
									<Truncated
										text={
											payment.note ||
											__(
												'Untitled payout',
												'flyaffiliate'
											)
										}
										max={ 24 }
									/>
								</Link>
							}
							tooltip={ sprintf(
								/* translators: %s: payout batch key */
								__(
									'Payout %s — the batch this payment was created in.',
									'flyaffiliate'
								),
								payment.batch_key.slice( 0, 8 )
							) }
						/>
						<StatCard
							icon={ Hash }
							label={ __( 'Reference', 'flyaffiliate' ) }
							value={
								payment.reference ? (
									<span className="block truncate">
										{ payment.reference }
									</span>
								) : (
									<span className="text-muted-foreground">
										—
									</span>
								)
							}
							tooltip={ __(
								'The transaction reference you noted when marking it paid.',
								'flyaffiliate'
							) }
						/>
					</>
				) : (
					<>
						<StatCardSkeleton />
						<StatCardSkeleton />
						<StatCardSkeleton />
						<StatCardSkeleton />
					</>
				) }
			</div>

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
				onConfirm={ markPaid }
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
