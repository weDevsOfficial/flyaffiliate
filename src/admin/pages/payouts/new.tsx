/**
 * Create a payout: choose who, preview what they are owed — grouped by the
 * payout method that pays each affiliate — tick who to pay, and pay them in
 * one go. Manual payments wait to be marked paid; an automatic method starts
 * sending its payments at once, and the payout's page follows them.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate } from 'react-router-dom';
import { ClipboardList, X } from 'lucide-react';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	DateRangePicker,
	type DateRange,
	Field,
	FieldDescription,
	FieldLabel,
	Input,
	RadioGroup,
	RadioGroupItem,
	Label,
	Spinner,
	toast,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import AffiliatePicker from '@/components/AffiliatePicker';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { formatMoney, toYmd } from '@/lib/format';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Affiliate, PayoutBatchResult, PayoutPreview } from '@/lib/types';
import PreviewList from './PreviewList';

/**
 * The affiliates ticked by default: everyone a method can pay right now.
 *
 * @param {PayoutPreview} preview The preview.
 * @return {Set<number>} Affiliate ids.
 */
function payable( preview: PayoutPreview ): Set< number > {
	return new Set(
		preview.rows
			.filter( ( row ) => ! row.warning )
			.map( ( row ) => row.affiliate_id )
	);
}

// Unpaid commissions the selection caught that are still inside their hold period: earned, not payable yet.
function HeldNote( { held }: { held: PayoutPreview[ 'held' ] } ) {
	if ( held.count === 0 ) {
		return null;
	}

	return (
		<p
			className="text-muted-foreground mb-4"
			data-testid="flyaffiliate-payout-held"
			data-count={ held.count }
		>
			{ sprintf(
				/* translators: 1: number of commissions, 2: their total */
				_n(
					'%1$d unpaid commission worth %2$s is still inside its hold period, so it is not in this payout.',
					'%1$d unpaid commissions worth %2$s are still inside their hold period, so they are not in this payout.',
					held.count,
					'flyaffiliate'
				),
				held.count,
				formatMoney( held.amount )
			) }
		</p>
	);
}

export default function NewPayoutPage() {
	const navigate = useNavigate();
	const { settings, currency } = getGlobals();
	const [ mode, setMode ] = useState< 'all' | 'selected' | 'except' >(
		'all'
	);
	const [ selected, setSelected ] = useState< Affiliate[] >( [] );
	const [ minimum, setMinimum ] = useState(
		String( settings?.payoutMinimum ?? 0 )
	);
	const [ period, setPeriod ] = useState< DateRange | undefined >();
	const [ note, setNote ] = useState( '' );
	const [ reference, setReference ] = useState( '' );
	const [ preview, setPreview ] = useState< PayoutPreview | null >( null );
	const [ busy, setBusy ] = useState< 'preview' | 'create' | null >( null );
	// Who to pay in this run, and the methods switched for it ("Switch to Manual").
	const [ ticked, setTicked ] = useState< Set< number > >( new Set() );
	const [ overrides, setOverrides ] = useState< Record< number, string > >(
		{}
	);

	const selection = ( methods: Record< number, string > = overrides ) => ( {
		mode,
		affiliate_ids: selected.map( ( a ) => a.id ),
		minimum_amount: minimum === '' ? 0 : Number( minimum ),
		...( period?.from ? { period_start: toYmd( period.from ) } : {} ),
		...( period?.to ? { period_end: toYmd( period.to ) } : {} ),
		note,
		reference,
		methods,
	} );

	const fetchPreview = async (
		methods: Record< number, string >,
		keep: Set< number > | null
	) => {
		setBusy( 'preview' );

		try {
			const result = await send< PayoutPreview >(
				'/payouts/preview',
				'POST',
				selection( methods )
			);

			setPreview( result );
			setTicked(
				keep
					? new Set(
							result.rows
								.map( ( row ) => row.affiliate_id )
								.filter( ( id ) => keep.has( id ) )
					  )
					: payable( result )
			);
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The preview could not be built.', 'flyaffiliate' )
				)
			);
		} finally {
			setBusy( null );
		}
	};

	const runPreview = ( event?: React.FormEvent ) => {
		event?.preventDefault();

		if ( mode !== 'all' && selected.length === 0 ) {
			toast.error(
				__( 'Choose at least one affiliate.', 'flyaffiliate' )
			);
			return;
		}

		setOverrides( {} );
		fetchPreview( {}, null );
	};

	// Everyone owed, straight away: the filters only narrow it.
	useEffect( () => {
		fetchPreview( {}, null );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const toggle = ( ids: number[], checked: boolean ) => {
		const next = new Set( ticked );

		ids.forEach( ( id ) =>
			checked ? next.add( id ) : next.delete( id )
		);
		setTicked( next );
	};

	// Paid by hand this time: the preview is rebuilt so the groups, totals and
	// warnings come from the server, and the affiliate is ticked.
	const switchToManual = ( affiliateId: number ) => {
		const methods = { ...overrides, [ affiliateId ]: 'manual' };

		setOverrides( methods );
		fetchPreview( methods, new Set( [ ...ticked, affiliateId ] ) );
	};

	const payingRows = ( preview?.rows ?? [] ).filter( ( row ) =>
		ticked.has( row.affiliate_id )
	);
	const payingTotal = payingRows.reduce(
		( sum, row ) => sum + Math.round( row.amount * 100 ),
		0
	);

	const create = async () => {
		if ( payingRows.length === 0 ) {
			return;
		}

		setBusy( 'create' );

		try {
			const result = await send< PayoutBatchResult >(
				'/payouts',
				'POST',
				{
					...selection(),
					mode: 'selected',
					affiliate_ids: payingRows.map(
						( row ) => row.affiliate_id
					),
					// What the admin saw: the server refuses if it changed meanwhile.
					expected: Object.fromEntries(
						payingRows.map( ( row ) => [
							row.affiliate_id,
							row.amount,
						] )
					),
				}
			);

			toast.success(
				sprintf(
					/* translators: %d: number of affiliates */
					_n(
						'Payout created with %d payment to make.',
						'Payout created with %d payments to make.',
						result.payouts.length,
						'flyaffiliate'
					),
					result.payouts.length
				)
			);
			navigate( `/payouts/batch/${ result.batch_key }` );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The payout could not be recorded.', 'flyaffiliate' )
				)
			);
			setBusy( null );
		}
	};

	return (
		<>
			<PageHeader
				backTo={ {
					to: '/payouts',
					label: __( 'All payouts', 'flyaffiliate' ),
				} }
				title={ __( 'New payout', 'flyaffiliate' ) }
				description={ __(
					'Only unpaid commissions are included. Each affiliate gets one payment, by their payout method. You send manual payments and mark them paid. Automatic methods send the money themselves.',
					'flyaffiliate'
				) }
			/>

			<div className="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
				<Card className="gap-5 rounded-md shadow ring-0">
					<CardHeader className="border-b border-border pb-5">
						<CardTitle className="text-base font-semibold">
							{ __( 'Who to pay', 'flyaffiliate' ) }
						</CardTitle>
						<CardDescription>
							{ __(
								'Narrow the batch, then preview it.',
								'flyaffiliate'
							) }
						</CardDescription>
					</CardHeader>
					<CardContent>
						<form
							onSubmit={ runPreview }
							className="grid gap-5"
							data-testid="flyaffiliate-payout-form"
						>
							<Field>
								<FieldLabel>
									{ __( 'Affiliates', 'flyaffiliate' ) }
								</FieldLabel>
								<RadioGroup
									value={ mode }
									onValueChange={ ( v ) =>
										setMode( v as typeof mode )
									}
									className="grid gap-2"
								>
									{ [
										[
											'all',
											__(
												'Everyone with an unpaid balance',
												'flyaffiliate'
											),
										],
										[
											'selected',
											__(
												'Only the affiliates I choose',
												'flyaffiliate'
											),
										],
										[
											'except',
											__(
												'Everyone except the affiliates I choose',
												'flyaffiliate'
											),
										],
									].map( ( [ value, label ] ) => (
										<div
											key={ value }
											className="flex items-center gap-2"
										>
											<RadioGroupItem
												value={ value }
												id={ `flyaffiliate-mode-${ value }` }
											/>
											<Label
												htmlFor={ `flyaffiliate-mode-${ value }` }
											>
												{ label }
											</Label>
										</div>
									) ) }
								</RadioGroup>
							</Field>

							{ mode !== 'all' && (
								<Field>
									<FieldLabel>
										{ __(
											'Choose affiliates',
											'flyaffiliate'
										) }
									</FieldLabel>
									<AffiliatePicker
										value={ null }
										onChange={ ( id, affiliate ) => {
											if (
												id &&
												affiliate &&
												! selected.some(
													( a ) => a.id === id
												)
											) {
												setSelected( [
													...selected,
													affiliate,
												] );
											}
										} }
										placeholder={ __(
											'Add an affiliate…',
											'flyaffiliate'
										) }
									/>
									{ selected.length > 0 && (
										<div className="mt-2 flex flex-wrap gap-2">
											{ selected.map( ( affiliate ) => (
												<Badge
													key={ affiliate.id }
													variant="secondary"
													className="gap-1"
												>
													{ affiliate.name }
													<button
														type="button"
														className="ml-1 inline-flex"
														aria-label={ __(
															'Remove',
															'flyaffiliate'
														) }
														onClick={ () =>
															setSelected(
																selected.filter(
																	( a ) =>
																		a.id !==
																		affiliate.id
																)
															)
														}
													>
														<X className="size-3" />
													</button>
												</Badge>
											) ) }
										</div>
									) }
								</Field>
							) }

							<Field>
								<FieldLabel htmlFor="flyaffiliate-payout-minimum">
									{ __( 'Minimum amount', 'flyaffiliate' ) } (
									{ currency.code })
								</FieldLabel>
								<Input
									id="flyaffiliate-payout-minimum"
									type="number"
									min={ 0 }
									step="0.01"
									value={ minimum }
									onChange={ ( e ) =>
										setMinimum( e.target.value )
									}
								/>
								<FieldDescription>
									{ __(
										'Affiliates below this amount wait for the next payout.',
										'flyaffiliate'
									) }
								</FieldDescription>
							</Field>

							<Field>
								<FieldLabel>
									{ __( 'Period', 'flyaffiliate' ) }
								</FieldLabel>
								<DateRangePicker
									mode="range"
									value={ period }
									onChange={ setPeriod }
									placeholder={ __(
										'Any time',
										'flyaffiliate'
									) }
								/>
								<FieldDescription>
									{ __(
										'Only commissions from this period are included.',
										'flyaffiliate'
									) }
								</FieldDescription>
							</Field>

							<Field>
								<FieldLabel htmlFor="flyaffiliate-payout-note">
									{ __( 'Title', 'flyaffiliate' ) }
								</FieldLabel>
								<Input
									id="flyaffiliate-payout-note"
									value={ note }
									onChange={ ( e ) =>
										setNote( e.target.value )
									}
									placeholder={ __(
										'e.g. September payout',
										'flyaffiliate'
									) }
								/>
							</Field>

							<Field>
								<FieldLabel htmlFor="flyaffiliate-payout-reference">
									{ __(
										'Payment reference',
										'flyaffiliate'
									) }
								</FieldLabel>
								<Input
									id="flyaffiliate-payout-reference"
									value={ reference }
									onChange={ ( e ) =>
										setReference( e.target.value )
									}
									placeholder={ __(
										'e.g. a bank transfer reference',
										'flyaffiliate'
									) }
								/>
							</Field>

							<Button
								type="submit"
								variant="outline"
								className={ `justify-self-start ${ BRAND_OUTLINE }` }
								disabled={ busy !== null }
								data-testid="flyaffiliate-payout-preview"
							>
								{ busy === 'preview' && (
									<Spinner className="size-4" />
								) }
								{ __( 'Preview', 'flyaffiliate' ) }
							</Button>
						</form>
					</CardContent>
				</Card>

				<Card className="gap-5 self-start rounded-md shadow ring-0">
					<CardHeader className="border-b border-border pb-5">
						<CardTitle className="text-base font-semibold">
							{ __( 'Preview', 'flyaffiliate' ) }
						</CardTitle>
						<CardDescription>
							{ preview
								? sprintf(
										/* translators: 1: affiliate count with unit, 2: commission count with unit */
										__( '%1$s, %2$s', 'flyaffiliate' ),
										sprintf(
											/* translators: %d: number of affiliates */
											_n(
												'%d affiliate',
												'%d affiliates',
												preview.rows.length,
												'flyaffiliate'
											),
											preview.rows.length
										),
										sprintf(
											/* translators: %d: number of commissions */
											_n(
												'%d commission',
												'%d commissions',
												preview.count,
												'flyaffiliate'
											),
											preview.count
										)
								  )
								: __(
										'Every affiliate owed, with their payout method. Pick who to pay, then pay them in one go.',
										'flyaffiliate'
								  ) }
						</CardDescription>
					</CardHeader>
					<CardContent>
						{ ! preview && busy === 'preview' && (
							<div className="flex justify-center py-10">
								<Spinner className="size-6" />
							</div>
						) }

						{ ! preview && busy !== 'preview' && (
							<EmptyState
								compact
								icon={ ClipboardList }
								title={ __(
									'Nothing previewed yet',
									'flyaffiliate'
								) }
								description={ __(
									'Set the filters on the left and click Preview.',
									'flyaffiliate'
								) }
							/>
						) }

						{ preview && preview.rows.length === 0 && (
							<div className="flex flex-col gap-2">
								<p className="text-muted-foreground">
									{ __(
										'No affiliate has unpaid commissions above the minimum.',
										'flyaffiliate'
									) }
								</p>
								{ preview.pending.count > 0 && (
									<p className="text-muted-foreground">
										{ sprintf(
											/* translators: 1: number of commissions, 2: their total */
											_n(
												'%1$d commission worth %2$s is still pending, so it is not in this payout.',
												'%1$d commissions worth %2$s are still pending, so they are not in this payout.',
												preview.pending.count,
												'flyaffiliate'
											),
											preview.pending.count,
											formatMoney(
												preview.pending.amount
											)
										) }{ ' ' }
										{ __(
											'A commission becomes unpaid when its order is paid, or when you mark it unpaid.',
											'flyaffiliate'
										) }
									</p>
								) }
							</div>
						) }

						{ preview && <HeldNote held={ preview.held } /> }

						{ preview && preview.rows.length > 0 && (
							<>
								<PreviewList
									preview={ preview }
									selected={ ticked }
									onToggle={ toggle }
									onSwitchToManual={ switchToManual }
									busy={ busy !== null }
								/>

								{ /* The card is padded by 24px; the rule runs edge to edge. */ }
								<div className="-mx-6 mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-border px-6 pt-4">
									<p
										className="text-muted-foreground"
										data-testid="flyaffiliate-payout-paying"
									>
										{ payingRows.length > 0
											? sprintf(
													/* translators: 1: total amount, 2: number of affiliates */
													_n(
														'Paying %1$s to %2$d affiliate',
														'Paying %1$s to %2$d affiliates',
														payingRows.length,
														'flyaffiliate'
													),
													formatMoney(
														payingTotal / 100
													),
													payingRows.length
											  )
											: __(
													'Tick the affiliates to pay.',
													'flyaffiliate'
											  ) }
									</p>
									<Button
										onClick={ create }
										disabled={
											busy !== null ||
											payingRows.length === 0
										}
										data-testid="flyaffiliate-payout-confirm"
									>
										{ busy === 'create' && (
											<Spinner className="size-4 mr-2" />
										) }
										{ sprintf(
											/* translators: %d: number of affiliates ticked */
											__(
												'Pay %d selected',
												'flyaffiliate'
											),
											payingRows.length
										) }
									</Button>
								</div>
							</>
						) }
					</CardContent>
				</Card>
			</div>
		</>
	);
}
