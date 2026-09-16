/**
 * One commission opened up, as SliceWP's affiliate account expands a row: the
 * item it paid on and the visit that brought the sale.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Dialog,
	DialogContent,
	DialogHeader,
	DialogTitle,
	Skeleton,
} from '@wedevs/plugin-ui';
import DateTime from '@/components/DateTime';
import Money from '@/components/Money';
import StatusBadge from '@/components/StatusBadge';
import { errorMessage, fetchOne } from '@/lib/api';
import type { CommissionDetail } from '../types';

type Props = {
	commissionId: number | null;
	onClose: () => void;
};

function Row( {
	label,
	children,
}: {
	label: string;
	children: React.ReactNode;
} ) {
	return (
		<div className="grid grid-cols-[9rem_1fr] gap-3 py-2 text-sm">
			<dt className="text-muted-foreground">{ label }</dt>
			<dd className="min-w-0 break-words text-foreground">
				{ children }
			</dd>
		</div>
	);
}

function Section( {
	title,
	children,
}: {
	title: string;
	children: React.ReactNode;
} ) {
	return (
		<section className="flex flex-col gap-1">
			<h4 className="text-sm font-semibold text-foreground">{ title }</h4>
			<dl className="divide-y divide-border rounded-md border border-border px-4">
				{ children }
			</dl>
		</section>
	);
}

export default function CommissionDetails( { commissionId, onClose }: Props ) {
	const [ detail, setDetail ] = useState< CommissionDetail | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! commissionId ) {
			return;
		}

		let cancelled = false;

		setDetail( null );
		setError( '' );

		fetchOne< CommissionDetail >( `/me/commissions/${ commissionId }` )
			.then( ( next ) => ! cancelled && setDetail( next ) )
			.catch(
				( failure ) =>
					! cancelled &&
					setError(
						errorMessage(
							failure,
							__(
								'The commission could not be loaded.',
								'flyaffiliate'
							)
						)
					)
			);

		return () => {
			cancelled = true;
		};
	}, [ commissionId ] );

	return (
		<Dialog
			open={ Boolean( commissionId ) }
			onOpenChange={ ( open ) => ! open && onClose() }
		>
			<DialogContent
				className="gap-0 p-0 sm:max-w-lg"
				data-testid="flyaffiliate-commission-details"
			>
				<DialogHeader className="border-b border-border px-6 py-4 pr-14">
					<DialogTitle className="text-lg font-semibold leading-6 text-foreground">
						{ detail?.order_id
							? `${ __(
									'Commission for order',
									'flyaffiliate'
							  ) } #${ detail.order_id }`
							: __( 'Commission', 'flyaffiliate' ) }
					</DialogTitle>
				</DialogHeader>

				<div className="flex flex-col gap-5 px-6 py-5">
					{ error && (
						<p className="text-sm text-destructive">{ error }</p>
					) }
					{ ! error && ! detail && (
						<div className="flex flex-col gap-3">
							<Skeleton className="h-4 w-24" />
							<Skeleton className="h-24 w-full" />
							<Skeleton className="h-4 w-24" />
							<Skeleton className="h-20 w-full" />
						</div>
					) }
					{ detail && (
						<>
							<Section title={ __( 'Item', 'flyaffiliate' ) }>
								<Row label={ __( 'Product', 'flyaffiliate' ) }>
									{ detail.product?.url ? (
										<a
											href={ detail.product.url }
											className="text-primary hover:underline"
										>
											{ detail.product.name }
										</a>
									) : (
										detail.product?.name ?? '—'
									) }
								</Row>
								<Row label={ __( 'Sale', 'flyaffiliate' ) }>
									{ Number( detail.base_amount ) > 0 ? (
										<Money amount={ detail.base_amount } />
									) : (
										'—'
									) }
								</Row>
								<Row
									label={ __( 'Commission', 'flyaffiliate' ) }
								>
									<Money
										amount={ detail.amount }
										className="font-semibold"
									/>
								</Row>
								<Row label={ __( 'Status', 'flyaffiliate' ) }>
									<StatusBadge
										status={ detail.status }
										kind="commission"
									/>
								</Row>
								<Row label={ __( 'Date', 'flyaffiliate' ) }>
									<DateTime
										value={ detail.created_at }
										withTime
									/>
								</Row>
							</Section>

							<Section title={ __( 'Visit', 'flyaffiliate' ) }>
								{ detail.visit ? (
									<>
										<Row
											label={ __(
												'Landing page',
												'flyaffiliate'
											) }
										>
											{ detail.visit.url || '—' }
										</Row>
										<Row
											label={ __(
												'Referrer',
												'flyaffiliate'
											) }
										>
											{ detail.visit.referrer ||
												__( 'Direct', 'flyaffiliate' ) }
										</Row>
										<Row
											label={ __(
												'Date',
												'flyaffiliate'
											) }
										>
											<DateTime
												value={
													detail.visit.created_at
												}
												withTime
											/>
										</Row>
									</>
								) : (
									<p className="py-3 text-sm text-muted-foreground">
										{ __(
											'This commission did not come from a tracked visit.',
											'flyaffiliate'
										) }
									</p>
								) }
							</Section>
						</>
					) }
				</div>
			</DialogContent>
		</Dialog>
	);
}
