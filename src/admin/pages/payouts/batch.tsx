/**
 * One payout: how much of it is paid, its payments, and the actions that take
 * the whole batch to paid or throw it away while nothing has been sent.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useParams } from 'react-router-dom';
import {
	Check,
	Download,
	Layers,
	Trash2,
	Wallet,
	WalletCards,
} from 'lucide-react';
import { Button, toast } from '@wedevs/plugin-ui';
import ConfirmDialog from '@/components/ConfirmDialog';
import PageHeader from '@/components/PageHeader';
import {
	HeaderSkeleton,
	StatGridSkeleton,
	TableSkeleton,
} from '@/components/PageSkeleton';
import StatCard from '@/components/StatCard';
import { errorMessage, fetchList, send } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Payout } from '@/lib/types';
import { csvUrl } from './index';
import { PaymentsTable } from './payments';

export default function PayoutBatchPage() {
	const { batchKey = '' } = useParams();
	const navigate = useNavigate();
	const [ rows, setRows ] = useState< Payout[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ version, setVersion ] = useState( 0 );
	const [ dialog, setDialog ] = useState< 'pay' | 'delete' | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		fetchList< Payout >( '/payouts', {
			batch_key: batchKey,
			per_page: 100,
		} )
			.then( ( result ) => setRows( result.items ) )
			.catch( () => setRows( [] ) )
			.finally( () => setLoading( false ) );
	}, [ batchKey ] );

	useEffect( load, [ load ] );

	const total = rows.reduce( ( sum, row ) => sum + Number( row.amount ), 0 );
	const paid = rows
		.filter( ( row ) => row.status === 'paid' )
		.reduce( ( sum, row ) => sum + Number( row.amount ), 0 );
	const unpaidRows = rows.filter( ( row ) => row.status !== 'paid' ).length;
	const first = rows[ 0 ];

	const payAll = async () => {
		setBusy( true );

		try {
			const result = await send< { paid: number } >(
				`/payouts/batch/${ batchKey }/pay`,
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
			setDialog( null );
			load();
			setVersion( ( v ) => v + 1 );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payout could not be marked paid.', 'flyaffiliate' )
				)
			);
		} finally {
			setBusy( false );
		}
	};

	const remove = async () => {
		setBusy( true );

		try {
			await send( `/payouts/batch/${ batchKey }`, 'DELETE' );
			toast.success( __( 'Payout deleted.', 'flyaffiliate' ) );
			navigate( '/payouts' );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payout could not be deleted.', 'flyaffiliate' )
				)
			);
			setBusy( false );
		}
	};

	if ( loading ) {
		return (
			<div data-testid="flyaffiliate-batch-loading">
				<HeaderSkeleton actions={ 2 } />
				<StatGridSkeleton />
				<TableSkeleton tabs={ 3 } rows={ 3 } columns={ 8 } />
			</div>
		);
	}

	return (
		<>
			<PageHeader
				backTo={ {
					to: '/payouts',
					label: __( 'All payouts', 'flyaffiliate' ),
				} }
				title={ first?.note || __( 'Payout', 'flyaffiliate' ) }
				description={
					first
						? `${ formatDate( first.created_at, true ) }${
								first.reference ? ` · ${ first.reference }` : ''
						  }`
						: batchKey
				}
				actions={
					<>
						{ rows.length > 0 && paid === 0 && (
							<Button
								variant="outline"
								onClick={ () => setDialog( 'delete' ) }
								data-testid="flyaffiliate-batch-delete"
							>
								<Trash2 className="size-4" />
								{ __( 'Delete payout', 'flyaffiliate' ) }
							</Button>
						) }
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							disabled={ rows.length === 0 }
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ csvUrl( batchKey ) }
									data-testid="flyaffiliate-batch-csv"
								/>
							}
						>
							<Download className="size-4" />
							{ __( 'Download CSV', 'flyaffiliate' ) }
						</Button>
						{ unpaidRows > 0 && (
							<Button
								onClick={ () => setDialog( 'pay' ) }
								data-testid="flyaffiliate-batch-pay"
							>
								<Check className="size-4" />
								{ __( 'Mark all as paid', 'flyaffiliate' ) }
							</Button>
						) }
					</>
				}
			/>

			<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					icon={ Wallet }
					label={ __( 'Total', 'flyaffiliate' ) }
					value={ formatMoney( total ) }
					tooltip={ __(
						'Everything in this payout, paid or not.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ WalletCards }
					label={ __( 'Paid', 'flyaffiliate' ) }
					value={ formatMoney( paid ) }
					tooltip={ __(
						'The payments already marked paid.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ Wallet }
					label={ __( 'Still to pay', 'flyaffiliate' ) }
					value={ formatMoney( total - paid ) }
					tooltip={ sprintf(
						/* translators: %d: number of unpaid payments */
						_n(
							'%d payment is still to be marked paid.',
							'%d payments are still to be marked paid.',
							unpaidRows,
							'flyaffiliate'
						),
						unpaidRows
					) }
				/>
				<StatCard
					icon={ Layers }
					label={ __( 'Payments', 'flyaffiliate' ) }
					value={ rows.length }
					tooltip={ __(
						'One payment per affiliate in this payout.',
						'flyaffiliate'
					) }
				/>
			</div>

			<PaymentsTable
				batchKey={ batchKey }
				refreshKey={ version }
				onChanged={ load }
			/>

			<ConfirmDialog
				open={ dialog === 'pay' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Mark all as paid', 'flyaffiliate' ) }
				description={ __(
					'Every unpaid payment in this payout is marked paid, and every commission in them with it. Do this once the money has been sent.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Mark as paid', 'flyaffiliate' ) }
				onConfirm={ payAll }
				busy={ busy }
			/>
			<ConfirmDialog
				open={ dialog === 'delete' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Delete payout', 'flyaffiliate' ) }
				description={ __(
					'Its payments are deleted and their commissions go back to unpaid, ready for the next payout.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Delete payout', 'flyaffiliate' ) }
				onConfirm={ remove }
				destructive
				busy={ busy }
			/>
		</>
	);
}
