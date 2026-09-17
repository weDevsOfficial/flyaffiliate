/**
 * Create a payout: choose who, preview what they are owed, confirm. Each
 * affiliate gets an unpaid payment to mark paid once the money has been sent.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { ClipboardList, X } from 'lucide-react';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	DataViews,
	type DataViewField,
	type DataViewState,
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
import Money from '@/components/Money';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { formatMoney, toYmd } from '@/lib/format';
import { BRAND_OUTLINE } from '@/lib/ui';
import type {
	Affiliate,
	PayoutBatchResult,
	PayoutPreview,
	PayoutPreviewRow,
} from '@/lib/types';

const PREVIEW_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 100,
	search: '',
	fields: [ 'amount', 'payment_email', 'commissions' ],
	titleField: 'name',
	layout: {
		styles: {
			name: { width: '32%' },
			amount: { width: '20%' },
			payment_email: { width: '30%' },
			commissions: { width: '18%' },
		},
	},
};

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
	const [ previewView, setPreviewView ] =
		useState< DataViewState >( PREVIEW_VIEW );

	const previewFields: DataViewField< PayoutPreviewRow >[] = [
		{
			id: 'name',
			label: __( 'Affiliate', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.name,
			render: ( { item } ) => (
				<Link
					to={ `/affiliates/${ item.affiliate_id }` }
					className="font-medium text-primary hover:underline"
				>
					{ item.name }
				</Link>
			),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'flyaffiliate' ),
			enableSorting: false,
			getValue: ( { item } ) => item.amount,
			render: ( { item } ) => (
				<Money amount={ item.amount } className="font-semibold" />
			),
		},
		{
			id: 'payment_email',
			label: __( 'Payment email', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span className="text-muted-foreground">
					{ item.payment_email || '—' }
				</span>
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
	];

	const selection = () => ( {
		mode,
		affiliate_ids: selected.map( ( a ) => a.id ),
		minimum_amount: minimum === '' ? 0 : Number( minimum ),
		...( period?.from ? { period_start: toYmd( period.from ) } : {} ),
		...( period?.to ? { period_end: toYmd( period.to ) } : {} ),
		note,
		reference,
	} );

	const runPreview = async ( event?: React.FormEvent ) => {
		event?.preventDefault();

		if ( mode !== 'all' && selected.length === 0 ) {
			toast.error(
				__( 'Choose at least one affiliate.', 'flyaffiliate' )
			);
			return;
		}

		setBusy( 'preview' );

		try {
			setPreview(
				await send< PayoutPreview >(
					'/payouts/preview',
					'POST',
					selection()
				)
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

	const create = async () => {
		setBusy( 'create' );

		try {
			const result = await send< PayoutBatchResult >(
				'/payouts',
				'POST',
				selection()
			);

			toast.success(
				sprintf(
					/* translators: %d: number of affiliates */
					_n(
						'Payout created: %d payment to make.',
						'Payout created: %d payments to make.',
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
					'Only unpaid commissions are included. Each affiliate gets one payment. Send the money outside WordPress, then mark the payment paid.',
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
										'Bank transfer, PayPal batch…',
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
										'Who gets paid, and how much, before anything is recorded.',
										'flyaffiliate'
								  ) }
						</CardDescription>
					</CardHeader>
					<CardContent>
						{ ! preview && (
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
											'A commission becomes payable when its hold period ends, or when you mark it unpaid.',
											'flyaffiliate'
										) }
									</p>
								) }
							</div>
						) }

						{ preview && preview.rows.length > 0 && (
							<>
								<div data-testid="flyaffiliate-payout-preview-table">
									<DataViews< PayoutPreviewRow >
										namespace="flyaffiliate-payout-preview"
										data={ preview.rows }
										fields={ previewFields }
										view={ previewView }
										onChangeView={ setPreviewView }
										paginationInfo={ {
											totalItems: preview.rows.length,
											totalPages: 1,
										} }
										defaultLayouts={ { table: {} } }
										getItemId={ ( item ) =>
											String( item.affiliate_id )
										}
										search={ false }
										tabs={ {
											items: [],
											headerContent: [],
										} }
									/>
								</div>

								{ /* The card is padded by 24px; the rule runs edge to edge. */ }
								<dl className="-mx-6 mt-6 flex flex-col gap-2 border-t border-border px-6 pt-4">
									<div className="flex items-center justify-between">
										<dt className="text-muted-foreground">
											{ __(
												'Payments to make',
												'flyaffiliate'
											) }
										</dt>
										<dd className="tabular-nums text-muted-foreground">
											{ preview.rows.length }
										</dd>
									</div>
									<div className="flex items-center justify-between">
										<dt className="font-semibold text-foreground">
											{ __( 'Total', 'flyaffiliate' ) }
										</dt>
										<dd>
											<Money
												amount={ preview.total }
												className="text-base font-semibold tabular-nums text-foreground"
											/>
										</dd>
									</div>
								</dl>

								<div className="mt-6 flex justify-end">
									<Button
										onClick={ create }
										disabled={ busy !== null }
										data-testid="flyaffiliate-payout-confirm"
									>
										{ busy === 'create' && (
											<Spinner className="size-4 mr-2" />
										) }
										{ __(
											'Create payout',
											'flyaffiliate'
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
