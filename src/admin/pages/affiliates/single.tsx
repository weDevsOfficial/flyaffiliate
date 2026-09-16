/**
 * One affiliate: totals, the referral link, and their ledger.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Link, useParams } from 'react-router-dom';
import {
	ArrowLeft,
	Clock,
	MousePointerClick,
	Pencil,
	UserRound,
	Wallet,
	WalletCards,
} from 'lucide-react';
import {
	Button,
	Card,
	Skeleton,
	Tabs,
	TabsContent,
	TabsList,
	TabsTrigger,
	toast,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import StatCard, { StatCardSkeleton } from '@/components/StatCard';
import CopyField from '@/components/CopyField';
import EmptyState from '@/components/EmptyState';
import { errorMessage, fetchOne } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Affiliate } from '@/lib/types';
import { CommissionsTable } from '../commissions';
import { VisitsTable } from '../visits';
import { PaymentsTable } from '../payouts/payments';
import AffiliateForm from './AffiliateForm';

type Detail = Affiliate & {
	visits?: { all: number; converted: number; not_converted: number };
};

const TAB_TRIGGER = 'px-4 data-active:text-primary';

export default function AffiliatePage() {
	const { id } = useParams();
	const { urls } = getGlobals();
	const [ affiliate, setAffiliate ] = useState< Detail | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ formOpen, setFormOpen ] = useState( false );

	const load = useCallback( () => {
		fetchOne< Detail >( `/affiliates/${ id }`, { context: 'edit' } )
			.then( setAffiliate )
			.catch( ( error ) => {
				setAffiliate( null );
				toast.error(
					errorMessage(
						error,
						__(
							'That affiliate could not be loaded.',
							'flyaffiliate'
						)
					)
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ id ] );

	useEffect( () => {
		setLoading( true );
		load();
	}, [ load ] );

	if ( loading ) {
		return (
			<div data-testid="flyaffiliate-affiliate-loading">
				<Skeleton className="mb-3 h-4 w-24" />
				<div className="mb-6 flex items-center justify-between">
					<Skeleton className="h-8 w-64" />
					<Skeleton className="h-9 w-40" />
				</div>
				<Skeleton className="mb-6 h-20 w-full rounded-md" />
				<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
					<StatCardSkeleton />
					<StatCardSkeleton />
					<StatCardSkeleton />
					<StatCardSkeleton />
				</div>
				<Skeleton className="h-11 w-72 rounded-lg" />
			</div>
		);
	}

	if ( ! affiliate ) {
		return (
			<Card className="py-0">
				<EmptyState
					icon={ UserRound }
					title={ __( 'No affiliate with that ID', 'flyaffiliate' ) }
					description={ __(
						'It may have been deleted, or the link is wrong.',
						'flyaffiliate'
					) }
					action={
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							render={ <Link to="/affiliates" /> }
						>
							<ArrowLeft className="size-4" />
							{ __( 'Back to affiliates', 'flyaffiliate' ) }
						</Button>
					}
				/>
			</Card>
		);
	}

	const totals = affiliate.totals;
	const visits = affiliate.visits ?? {
		all: 0,
		converted: 0,
		not_converted: 0,
	};
	const numericId = Number( id );

	return (
		<>
			<PageHeader
				backTo={ {
					to: '/affiliates',
					label: __( 'All affiliates', 'flyaffiliate' ),
				} }
				title={ affiliate.name }
				badge={
					<StatusBadge status={ affiliate.status } kind="affiliate" />
				}
				description={ `#${ affiliate.id } · ${
					affiliate.payment_email ||
					__( 'No payment email', 'flyaffiliate' )
				}` }
				actions={
					<>
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							render={
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a
									href={ `${ urls.users }${ affiliate.user_id }` }
								/>
							}
						>
							<UserRound className="size-4" />
							{ __( 'WordPress profile', 'flyaffiliate' ) }
						</Button>
						<Button onClick={ () => setFormOpen( true ) }>
							<Pencil className="size-4" />
							{ __( 'Edit', 'flyaffiliate' ) }
						</Button>
					</>
				}
			/>

			<Card className="mb-6 gap-3 rounded-md px-5 py-4 shadow ring-0">
				<div className="flex flex-wrap items-center justify-between gap-2">
					<label
						htmlFor="flyaffiliate-referral-link"
						className="text-sm font-semibold text-foreground"
					>
						{ __( 'Referral link', 'flyaffiliate' ) }
					</label>
					<span className="text-xs text-muted-foreground">
						{ __(
							'Add ?affiliate=ID to any product URL to link straight to it.',
							'flyaffiliate'
						) }
					</span>
				</div>
				<CopyField
					id="flyaffiliate-referral-link"
					value={ affiliate.referral_url }
					className="max-w-2xl bg-muted/40"
				/>
			</Card>

			<div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					icon={ Wallet }
					label={ __( 'Unpaid', 'flyaffiliate' ) }
					value={ formatMoney( totals?.unpaid ?? 0 ) }
					tooltip={ __(
						'Approved commissions, ready for the next payout.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ Clock }
					label={ __( 'Pending', 'flyaffiliate' ) }
					value={ formatMoney( totals?.pending ?? 0 ) }
					tooltip={ __(
						'Commissions still inside their hold period.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ WalletCards }
					label={ __( 'Paid', 'flyaffiliate' ) }
					value={ formatMoney( totals?.paid ?? 0 ) }
					tooltip={ __(
						'Everything paid to this affiliate, all time.',
						'flyaffiliate'
					) }
				/>
				<StatCard
					icon={ MousePointerClick }
					label={ __( 'Visits', 'flyaffiliate' ) }
					value={ visits.all }
					tooltip={
						visits.all > 0
							? sprintf(
									/* translators: 1: converted visits, 2: all visits, 3: conversion rate */
									__(
										'%1$d of %2$d clicks on the referral link ended in an order (%3$d%%).',
										'flyaffiliate'
									),
									visits.converted,
									visits.all,
									Math.round(
										( visits.converted / visits.all ) * 100
									)
							  )
							: __(
									'Clicks on the referral link. None yet.',
									'flyaffiliate'
							  )
					}
				/>
			</div>

			<Tabs defaultValue="commissions" className="gap-6">
				<TabsList className="h-11 gap-2 bg-muted p-1">
					<TabsTrigger value="commissions" className={ TAB_TRIGGER }>
						{ __( 'Commissions', 'flyaffiliate' ) }
					</TabsTrigger>
					<TabsTrigger value="visits" className={ TAB_TRIGGER }>
						{ __( 'Visits', 'flyaffiliate' ) }
					</TabsTrigger>
					<TabsTrigger value="payouts" className={ TAB_TRIGGER }>
						{ __( 'Payouts', 'flyaffiliate' ) }
					</TabsTrigger>
				</TabsList>
				<TabsContent value="commissions">
					<CommissionsTable
						affiliateId={ numericId }
						onChanged={ load }
					/>
				</TabsContent>
				<TabsContent value="visits">
					<VisitsTable affiliateId={ numericId } />
				</TabsContent>
				<TabsContent value="payouts">
					<PaymentsTable affiliateId={ numericId } />
				</TabsContent>
			</Tabs>

			<AffiliateForm
				open={ formOpen }
				onOpenChange={ setFormOpen }
				affiliate={ affiliate }
				onSaved={ ( saved ) =>
					setAffiliate( ( current ) =>
						current ? { ...current, ...saved } : current
					)
				}
			/>
		</>
	);
}
