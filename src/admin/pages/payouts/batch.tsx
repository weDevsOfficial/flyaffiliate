/**
 * One payout: where it stands, its payments, and the actions that take it to
 * paid or throw it away while nothing has been sent.
 *
 * Payments an automatic payout method sends settle on their own: while any
 * is on its way the page shows the progress and asks again every few seconds
 * — every third time asking the methods themselves, so a site that does not
 * receive their notifications still settles.
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
import { Button, Card, CardContent, Progress, toast } from '@wedevs/plugin-ui';
import ConfirmDialog from '@/components/ConfirmDialog';
import NotFound from '@/components/NotFound';
import PageHeader from '@/components/PageHeader';
import {
	HeaderSkeleton,
	StatGridSkeleton,
	TableSkeleton,
} from '@/components/PageSkeleton';
import StatCard from '@/components/StatCard';
import StatusBadge from '@/components/StatusBadge';
import { usePolling } from '@/hooks/usePolling';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { PayoutBatch, PayoutSummary } from '@/lib/types';
import { csvUrl } from './index';
import { PaymentsTable } from './payments';

/** How often a payout with money on its way asks again. */
const POLL_MS = 5000;

/**
 * "6 of 8 settled · 5 paid · 1 on the way · 1 failed": the counts that are not zero.
 *
 * @param {PayoutSummary|PayoutBatch} summary The payout.
 * @return {string} The line.
 */
export function progressLine( summary: PayoutSummary | PayoutBatch ): string {
	const counts = summary.counts;

	if ( ! counts ) {
		return '';
	}

	const inFlight = summary.in_flight ?? 0;
	const parts: string[] = [
		sprintf(
			/* translators: 1: payments settled, 2: payments in the payout */
			__( '%1$d of %2$d settled', 'flyaffiliate' ),
			counts.paid + counts.failed,
			summary.affiliates
		),
	];
	const add = ( count: number, text: ( n: number ) => string ) => {
		if ( count > 0 ) {
			parts.push( text( count ) );
		}
	};

	/* translators: %d: number of payments */
	add( counts.paid, ( n ) => sprintf( __( '%d paid', 'flyaffiliate' ), n ) );
	add( inFlight, ( n ) =>
		/* translators: %d: number of payments */
		sprintf( __( '%d on the way', 'flyaffiliate' ), n )
	);
	add( counts.failed, ( n ) =>
		/* translators: %d: number of payments */
		sprintf( __( '%d failed', 'flyaffiliate' ), n )
	);
	add( counts.not_sent, ( n ) =>
		/* translators: %d: number of payments */
		sprintf( __( '%d not sent', 'flyaffiliate' ), n )
	);
	add( counts.manual_unpaid, ( n ) =>
		/* translators: %d: number of payments */
		sprintf( __( '%d to pay by hand', 'flyaffiliate' ), n )
	);

	return parts.join( ' · ' );
}

export default function PayoutBatchPage() {
	const { batchKey = '' } = useParams();
	const navigate = useNavigate();
	const { statuses } = getGlobals();
	const [ summary, setSummary ] = useState< PayoutSummary | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ version, setVersion ] = useState( 0 );
	const [ pollKey, setPollKey ] = useState( 0 );
	const [ dialog, setDialog ] = useState< 'pay' | 'delete' | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback(
		( refresh = false ) =>
			( refresh
				? send< PayoutSummary >(
						`/payouts/batch/${ batchKey }/refresh`,
						'POST'
				  )
				: fetchOne< PayoutSummary >( `/payouts/batch/${ batchKey }` )
			)
				.then( setSummary )
				.catch( () => setSummary( null ) )
				.finally( () => setLoading( false ) ),
		[ batchKey ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	const inFlight = ( summary?.in_flight ?? 0 ) > 0;

	usePolling(
		( tick ) => {
			load( tick % 3 === 0 ).then( () => setPollKey( ( k ) => k + 1 ) );
		},
		POLL_MS,
		inFlight
	);

	const reload = () => {
		load();
		setVersion( ( v ) => v + 1 );
	};

	const payManual = async () => {
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
			reload();
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

	if ( ! summary ) {
		return <NotFound />;
	}

	const { counts } = summary;
	// Anything an automatic method sends, or sent: the page then follows the transfers.
	const automatic =
		summary.in_flight +
			counts.failed +
			counts.not_sent +
			counts.unavailable >
			0 || summary.methods.some( ( method ) => method.id !== 'manual' );
	const settled = counts.paid + counts.failed;
	const canDelete =
		summary.paid === 0 &&
		summary.in_flight === 0 &&
		counts.unavailable === 0;
	const unpaidCount = summary.affiliates - summary.paid;

	return (
		<>
			<PageHeader
				backTo={ {
					to: '/payouts',
					label: __( 'All payouts', 'flyaffiliate' ),
				} }
				title={ summary.note || __( 'Payout', 'flyaffiliate' ) }
				badge={
					<StatusBadge
						status={ summary.status }
						label={ statuses.batch?.[ summary.status ] }
					/>
				}
				description={ `${ formatDate( summary.created_at, true ) }${
					summary.reference ? ` · ${ summary.reference }` : ''
				}` }
				actions={
					<>
						{ canDelete && (
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
						{ counts.manual_unpaid > 0 && (
							<Button
								onClick={ () => setDialog( 'pay' ) }
								data-testid="flyaffiliate-batch-pay"
							>
								<Check className="size-4" />
								{ automatic
									? __(
											'Mark manual payments as paid',
											'flyaffiliate'
									  )
									: __( 'Mark all as paid', 'flyaffiliate' ) }
							</Button>
						) }
					</>
				}
			/>

			<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					icon={ Wallet }
					label={ __( 'Total', 'flyaffiliate' ) }
					value={ formatMoney( summary.total ) }
					tooltip={ __(
						'Everything in this payout, paid or not.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ WalletCards }
					label={ __( 'Paid', 'flyaffiliate' ) }
					value={ formatMoney( summary.paid_total ) }
					tooltip={ __(
						'The payments already paid.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ Wallet }
					label={ __( 'Still to pay', 'flyaffiliate' ) }
					value={ formatMoney(
						( Math.round( summary.total * 100 ) -
							Math.round( summary.paid_total * 100 ) ) /
							100
					) }
					tooltip={ sprintf(
						/* translators: %d: number of unpaid payments */
						_n(
							'%d payment is not paid yet.',
							'%d payments are not paid yet.',
							unpaidCount,
							'flyaffiliate'
						),
						unpaidCount
					) }
				/>
				<StatCard
					icon={ Layers }
					label={ __( 'Paid via', 'flyaffiliate' ) }
					value={
						summary.methods
							.map( ( method ) => method.label )
							.join( ' · ' ) || '—'
					}
					tooltip={ sprintf(
						/* translators: %d: number of affiliates */
						_n(
							'%d payment, by the affiliate’s payout method.',
							'%d payments, one per affiliate, each by their payout method.',
							summary.affiliates,
							'flyaffiliate'
						),
						summary.affiliates
					) }
				/>
			</div>

			{ automatic && (
				<Card
					className="mb-6 gap-3 rounded-md shadow ring-0"
					data-testid="flyaffiliate-batch-progress"
				>
					<CardContent className="flex flex-col gap-3">
						{ inFlight && (
							<p className="text-muted-foreground">
								{ __(
									'Automatic transfers can take a few minutes to settle. They carry on in the background, so it is safe to leave this page and come back.',
									'flyaffiliate'
								) }
							</p>
						) }
						<Progress
							value={
								summary.affiliates > 0
									? Math.round(
											( settled / summary.affiliates ) *
												100
									  )
									: 0
							}
							variant={
								counts.failed > 0 ? 'warning' : 'success'
							}
							aria-label={ __( 'Settled', 'flyaffiliate' ) }
						/>
						<p className="tabular-nums text-foreground">
							{ progressLine( summary ) }
						</p>
					</CardContent>
				</Card>
			) }

			<PaymentsTable
				batchKey={ batchKey }
				refreshKey={ version }
				pollKey={ pollKey }
				onChanged={ () => load() }
			/>

			<ConfirmDialog
				open={ dialog === 'pay' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={
					automatic
						? __( 'Mark manual payments as paid', 'flyaffiliate' )
						: __( 'Mark all as paid', 'flyaffiliate' )
				}
				description={ __(
					'Marks every unpaid manual payment in this payout as paid, along with their commissions. Do this once the money has been sent. Payments an automatic method sends are left to it.',
					'flyaffiliate'
				) }
				confirmLabel={ __( 'Mark as paid', 'flyaffiliate' ) }
				onConfirm={ payManual }
				busy={ busy }
			/>
			<ConfirmDialog
				open={ dialog === 'delete' }
				onOpenChange={ ( open ) => ! open && setDialog( null ) }
				title={ __( 'Delete payout', 'flyaffiliate' ) }
				description={ __(
					'Deletes its payments. Their commissions go back to unpaid and into the next payout.',
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
