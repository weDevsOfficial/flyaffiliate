/**
 * Add or edit one commission, on its own page — the fields SliceWP's
 * commission form has: ID, affiliate, amount, reference, reference amount,
 * origin, date, type, status — laid out the way Dokan's edit screens are: the
 * details in a card of rows, the status and the origin in a sidebar, the
 * buttons top right, and every explanation behind an info icon beside its
 * label.
 *
 * On edit the ID, the affiliate, the origin and the date are fixed, as in
 * SliceWP; the rest stays open even inside a payment, which follows the edit
 * while unpaid and keeps its amount once paid (CONTEXT.md money rule 7).
 */
import { useEffect, useState } from '@wordpress/element';
import { dateI18n, getDate, gmdate } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';
import {
	Link,
	useLocation,
	useNavigate,
	useParams,
	useSearchParams,
} from 'react-router-dom';
import { ArrowLeft, ExternalLink, ReceiptText, Wallet } from 'lucide-react';
import {
	Alert,
	AlertDescription,
	AlertTitle,
	Button,
	Card,
	CardContent,
	CardHeader,
	CardTitle,
	DatePicker,
	Field,
	FieldLabel,
	Input,
	InputGroup,
	InputGroupAddon,
	InputGroupInput,
	InputGroupText,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Spinner,
	Tooltip,
	TooltipContent,
	TooltipTrigger,
	cn,
	toast,
} from '@wedevs/plugin-ui';
import AffiliatePicker from '@/components/AffiliatePicker';
import EmptyState from '@/components/EmptyState';
import Hint from '@/components/Hint';
import PageHeader from '@/components/PageHeader';
import { FormCardSkeleton, HeaderSkeleton } from '@/components/PageSkeleton';
import StatusBadge from '@/components/StatusBadge';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { formatDate, toYmd } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Commission, Payout } from '@/lib/types';

const FORM_ID = 'flyaffiliate-commission-form';

type Values = {
	affiliate_id: number | null;
	amount: string;
	base_amount: string;
	order_id: string;
	source: string;
	/** The calendar day, `Y-m-d`, in the site's timezone. */
	date: string;
	/** The time of day, `H:i`, in the site's timezone. */
	time: string;
	type: string;
	status: string;
};

/**
 * A site-timezone day and time as `Y-m-d H:i:s` in GMT, what the API stores.
 *
 * `getDate()` reads the string in the WordPress timezone (Settings → General),
 * not the browser's, so an admin in another zone still records the date the
 * site shows.
 *
 * @param {string} day  `Y-m-d`.
 * @param {string} time `H:i`.
 * @return {string} The GMT date-time, or '' when the input is not a date.
 */
function toGmt( day: string, time: string ): string {
	try {
		return gmdate(
			'Y-m-d H:i:s',
			getDate( `${ day } ${ time || '00:00' }:00` )
		);
	} catch {
		return '';
	}
}

/**
 * The `Date` whose local calendar day is the `Y-m-d` given, for the picker.
 *
 * @param {string} day `Y-m-d`.
 * @return {Date|undefined} Noon on that day, or nothing for a bad value.
 */
function dayToDate( day: string ): Date | undefined {
	const [ y, m, d ] = day.split( '-' ).map( Number );

	return y && m && d ? new Date( y, m - 1, d, 12 ) : undefined;
}

function Required() {
	return (
		<span className="text-destructive" aria-hidden="true">
			*
		</span>
	);
}

/**
 * One row of a card: the label with its hint, then the control.
 * @param root0
 * @param root0.id
 * @param root0.label
 * @param root0.hint
 * @param root0.required
 * @param root0.children
 */
function Row( {
	id,
	label,
	hint,
	required = false,
	children,
}: {
	/** The control the label points at; none for a control that is not labelable, like the date picker's trigger. */
	id?: string;
	label: string;
	hint?: string;
	required?: boolean;
	children: React.ReactNode;
} ) {
	return (
		<Field className="gap-2.5 px-5 py-4">
			<div className="flex items-center gap-1.5">
				<FieldLabel
					htmlFor={ id }
					className="text-sm font-medium text-foreground"
				>
					{ label }
					{ required && (
						<>
							{ ' ' }
							<Required />
						</>
					) }
				</FieldLabel>
				{ hint && <Hint text={ hint } /> }
			</div>
			{ children }
		</Field>
	);
}

/**
 * A card with a title bar and bordered rows, as on Dokan's edit screens.
 * @param root0
 * @param root0.title
 * @param root0.children
 * @param root0.className
 */
function Section( {
	title,
	children,
	className,
}: {
	title: string;
	children: React.ReactNode;
	className?: string;
} ) {
	return (
		<Card
			className={ cn( 'gap-0 rounded-md py-0 shadow ring-0', className ) }
		>
			<CardHeader className="border-b border-border px-5 py-4">
				<CardTitle className="text-base font-semibold">
					{ title }
				</CardTitle>
			</CardHeader>
			<CardContent className="p-0 divide-y divide-border">
				{ children }
			</CardContent>
		</Card>
	);
}

/**
 * A link icon at the end of a field, with its purpose on hover.
 * @param root0
 * @param root0.label
 * @param root0.to
 * @param root0.href
 * @param root0.testId
 */
function SuffixLink( {
	label,
	to,
	href,
	testId,
}: {
	label: string;
	to?: string;
	href?: string;
	testId?: string;
} ) {
	// The anchor is the tooltip's trigger itself: a component in between
	// would drop the ref the tooltip positions itself by (React 18 passes a
	// ref only through forwardRef).
	const anchor = cn(
		'inline-flex size-6 items-center justify-center rounded-sm text-muted-foreground',
		'hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50'
	);

	return (
		<InputGroupAddon align="inline-end">
			<Tooltip>
				<TooltipTrigger
					render={
						to ? (
							<Link
								to={ to }
								className={ anchor }
								aria-label={ label }
								data-testid={ testId }
							/>
						) : (
							// eslint-disable-next-line jsx-a11y/anchor-has-content
							<a
								href={ href }
								className={ anchor }
								aria-label={ label }
								data-testid={ testId }
							/>
						)
					}
				>
					<ExternalLink className="size-4" aria-hidden="true" />
				</TooltipTrigger>
				<TooltipContent>{ label }</TooltipContent>
			</Tooltip>
		</InputGroupAddon>
	);
}

/**
 * The payment holding the commission, and what an edit means for it. An
 * unpaid payment re-sums to follow the edit; a paid one keeps the amount it
 * was paid with, so the edit corrects the commission's own record only.
 *
 * @param {Object}      props           Props.
 * @param {number}      props.paymentId The payment's id.
 * @param {Payout|null} props.payment   The payment, once loaded.
 */
function PaymentNotice( {
	paymentId,
	payment,
}: {
	paymentId: number;
	payment: Payout | null;
} ) {
	let title: string = sprintf(
		/* translators: %d: payment id */
		__( 'In payment #%d', 'flyaffiliate' ),
		paymentId
	);
	let text = '';

	if ( payment?.status === 'paid' ) {
		title = sprintf(
			/* translators: %d: payment id */
			__( 'Paid in payment #%d', 'flyaffiliate' ),
			paymentId
		);
		text = __(
			'The payment was already sent, so its amount stays as it was. Changes here only update this commission.',
			'flyaffiliate'
		);
	} else if ( payment ) {
		title = sprintf(
			/* translators: %d: payment id */
			__( 'Waiting in payment #%d', 'flyaffiliate' ),
			paymentId
		);
		text = __(
			'The payment has not been sent yet, so its amount updates with your changes.',
			'flyaffiliate'
		);
	}

	return (
		<Alert data-testid="flyaffiliate-commission-payment">
			<Wallet className="size-4" />
			<AlertTitle>{ title }</AlertTitle>
			<AlertDescription>
				<span>
					{ text }
					{ text && ' ' }
					<Link
						to={ `/payouts/payment/${ paymentId }` }
						className="font-medium text-primary hover:underline"
					>
						{ __( 'View payment', 'flyaffiliate' ) }
					</Link>
				</span>
			</AlertDescription>
		</Alert>
	);
}

function FormSkeleton() {
	return (
		<div data-testid="flyaffiliate-commission-loading">
			<HeaderSkeleton actions={ 2 } />
			<div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
				<FormCardSkeleton rows={ 5 } />
				<div className="grid content-start gap-6">
					<FormCardSkeleton rows={ 1 } />
					<FormCardSkeleton rows={ 3 } />
				</div>
			</div>
		</div>
	);
}

export default function CommissionFormPage() {
	const { id } = useParams();
	const editing = Boolean( id );
	const commissionId = Number( id );
	const navigate = useNavigate();
	const location = useLocation();
	const [ searchParams ] = useSearchParams();
	const { currency, statuses, sources, availableSources, types } =
		getGlobals();

	const from =
		( location.state as { from?: string } | null )?.from ?? '/commissions';
	const presetAffiliate = Number( searchParams.get( 'affiliate' ) ) || null;

	const [ commission, setCommission ] = useState< Commission | null >( null );
	// The payment holding the commission, when one does: paid or still waiting.
	const [ payment, setPayment ] = useState< Payout | null >( null );
	const [ loading, setLoading ] = useState( editing );
	const [ saving, setSaving ] = useState( false );
	const [ values, setValues ] = useState< Values >( {
		affiliate_id: presetAffiliate,
		amount: '',
		base_amount: '',
		order_id: '',
		// The platform's origin when its integration is active, otherwise manual.
		source:
			'woocommerce' in availableSources
				? 'woocommerce'
				: Object.keys( availableSources )[ 0 ] ?? 'manual',
		// Now, in the site's timezone.
		date: dateI18n( 'Y-m-d' ),
		time: dateI18n( 'H:i' ),
		type: 'sale',
		// Paid, as SliceWP's form starts: a commission added by hand is most
		// often money that already went outside the plugin.
		status: 'paid',
	} );

	useEffect( () => {
		if ( ! editing ) {
			return;
		}

		setLoading( true );
		fetchOne< Commission >( `/commissions/${ commissionId }`, {
			context: 'edit',
		} )
			.then( ( item ) => {
				setCommission( item );

				if ( item.payout_id ) {
					fetchOne< Payout >( `/payouts/${ item.payout_id }` )
						.then( setPayment )
						.catch( () => setPayment( null ) );
				}

				setValues( ( current ) => ( {
					...current,
					affiliate_id: item.affiliate_id,
					amount: String( item.amount ),
					base_amount:
						Number( item.base_amount ) > 0
							? String( item.base_amount )
							: '',
					order_id: item.order_id > 0 ? String( item.order_id ) : '',
					source: item.source,
					type: item.type,
					status: item.status,
				} ) );
			} )
			.catch( ( error ) => {
				setCommission( null );
				toast.error(
					errorMessage(
						error,
						__(
							'That commission could not be loaded.',
							'flyaffiliate'
						)
					)
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ editing, commissionId ] );

	// A payment holding the commission follows the edit; it does not block it.
	const inPayment = editing && Boolean( commission?.payout_id );
	// The reference of a commission that came from checkout is its order item's.
	const referenceFixed = editing && commission?.order_item_id !== null;
	// The saved reference points at an order that exists.
	const orderUrl =
		commission?.order_url &&
		values.order_id === String( commission.order_id )
			? commission.order_url
			: null;

	const set = < K extends keyof Values >( key: K, value: Values[ K ] ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const statusHint: Record< string, string > = {
		pending: __(
			'Waits for the hold period to end and for the order to be paid.',
			'flyaffiliate'
		),
		unpaid: __( 'Ready to go out in the next payout.', 'flyaffiliate' ),
		paid: __(
			'Already paid outside FlyAffiliate. You can still edit it here.',
			'flyaffiliate'
		),
		rejected: __(
			'Will not be paid. You can set it back to pending or unpaid at any time.',
			'flyaffiliate'
		),
	};

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		if ( ! editing && ! values.affiliate_id ) {
			toast.error(
				__(
					'Choose the affiliate who earns this commission.',
					'flyaffiliate'
				)
			);
			return;
		}

		if ( values.amount === '' || Number( values.amount ) <= 0 ) {
			toast.error(
				__( 'Enter an amount of more than zero.', 'flyaffiliate' )
			);
			return;
		}

		const payload: Record< string, unknown > = {
			amount: Number( values.amount ),
			base_amount:
				values.base_amount === '' ? 0 : Number( values.base_amount ),
			type: values.type,
			status: values.status,
		};

		if ( ! referenceFixed ) {
			payload.order_id =
				values.order_id === '' ? 0 : Number( values.order_id );
		}

		if ( ! editing ) {
			const createdAt = toGmt( values.date, values.time );

			if ( createdAt === '' ) {
				toast.error(
					__( 'Pick the date of the commission.', 'flyaffiliate' )
				);
				return;
			}

			payload.affiliate_id = values.affiliate_id;
			payload.source = values.source;
			payload.created_at = createdAt;
		}

		setSaving( true );

		try {
			if ( editing ) {
				await send( `/commissions/${ commissionId }`, 'PUT', payload );
				toast.success( __( 'Commission updated.', 'flyaffiliate' ) );
			} else {
				await send( '/commissions', 'POST', payload );
				toast.success( __( 'Commission added.', 'flyaffiliate' ) );
			}

			navigate( from );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The commission could not be saved.', 'flyaffiliate' )
				)
			);
			setSaving( false );
		}
	};

	if ( loading ) {
		return <FormSkeleton />;
	}

	if ( editing && ! commission ) {
		return (
			<Card className="py-0">
				<EmptyState
					icon={ ReceiptText }
					title={ __( 'No commission with that ID', 'flyaffiliate' ) }
					description={ __(
						'It may have been deleted, or the link is wrong.',
						'flyaffiliate'
					) }
					action={
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							render={ <Link to="/commissions" /> }
						>
							<ArrowLeft className="size-4" />
							{ __( 'Back to commissions', 'flyaffiliate' ) }
						</Button>
					}
				/>
			</Card>
		);
	}

	const disabled = saving;

	let description: string = __(
		'Add a commission by hand, for a bonus, a correction or an offline sale.',
		'flyaffiliate'
	);

	if ( editing ) {
		description = __(
			'Edit the amount, reference, type or status. The affiliate, origin and date can’t be changed.',
			'flyaffiliate'
		);
	}

	const selectClass = 'w-full';

	return (
		<>
			<PageHeader
				backTo={ {
					to: from,
					label:
						from === '/commissions'
							? __( 'All commissions', 'flyaffiliate' )
							: __( 'Back', 'flyaffiliate' ),
				} }
				title={
					editing
						? sprintf(
								/* translators: %d: commission id */
								__( 'Commission #%d', 'flyaffiliate' ),
								commissionId
						  )
						: __( 'Add commission', 'flyaffiliate' )
				}
				badge={
					commission && (
						<StatusBadge
							status={ commission.status }
							kind="commission"
						/>
					)
				}
				description={ description }
				actions={
					<>
						<Button
							type="submit"
							form={ FORM_ID }
							disabled={ saving }
							data-testid="flyaffiliate-commission-save"
						>
							{ saving && <Spinner className="size-4" /> }
							{ editing
								? __( 'Save', 'flyaffiliate' )
								: __( 'Add commission', 'flyaffiliate' ) }
						</Button>
					</>
				}
			/>

			<form
				id={ FORM_ID }
				onSubmit={ submit }
				data-testid="flyaffiliate-commission-form"
				className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]"
			>
				<div className="grid content-start gap-6">
					{ inPayment && commission && (
						<PaymentNotice
							paymentId={ commission.payout_id ?? 0 }
							payment={ payment }
						/>
					) }

					<Section
						title={ __( 'Commission details', 'flyaffiliate' ) }
					>
						{ editing && (
							<Row
								id="flyaffiliate-commission-id"
								label={ __( 'Commission ID', 'flyaffiliate' ) }
							>
								<Input
									id="flyaffiliate-commission-id"
									value={ `#${ commissionId }` }
									readOnly
									disabled
								/>
							</Row>
						) }

						<Row
							id="flyaffiliate-commission-affiliate"
							label={ __( 'Affiliate', 'flyaffiliate' ) }
							hint={
								editing
									? __(
											'Who earns this commission. This can’t be changed.',
											'flyaffiliate'
									  )
									: __(
											'Who earns this commission.',
											'flyaffiliate'
									  )
							}
							required={ ! editing }
						>
							{ editing && commission ? (
								<InputGroup>
									<InputGroupInput
										id="flyaffiliate-commission-affiliate"
										value={
											commission.affiliate_name ||
											`#${ commission.affiliate_id }`
										}
										readOnly
										disabled
									/>
									<SuffixLink
										label={ __(
											'View affiliate',
											'flyaffiliate'
										) }
										to={ `/affiliates/${ commission.affiliate_id }` }
										testId="flyaffiliate-commission-view-affiliate"
									/>
								</InputGroup>
							) : (
								<AffiliatePicker
									value={ values.affiliate_id }
									onChange={ ( v ) =>
										set( 'affiliate_id', v )
									}
								/>
							) }
						</Row>

						<Row
							id="flyaffiliate-commission-amount"
							label={ __( 'Amount', 'flyaffiliate' ) }
							hint={ __(
								'What the affiliate earns.',
								'flyaffiliate'
							) }
							required
						>
							<InputGroup>
								<InputGroupAddon align="inline-start">
									<InputGroupText>
										{ currency.symbol || currency.code }
									</InputGroupText>
								</InputGroupAddon>
								<InputGroupInput
									id="flyaffiliate-commission-amount"
									type="number"
									min={ 0 }
									step="0.01"
									value={ values.amount }
									onChange={ ( e ) =>
										set( 'amount', e.target.value )
									}
									placeholder="0.00"
									disabled={ disabled }
									required
								/>
							</InputGroup>
						</Row>

						<Row
							id="flyaffiliate-commission-base"
							label={ __( 'Reference amount', 'flyaffiliate' ) }
							hint={ __(
								'The sale amount this commission is based on. Leave it empty if there is no sale.',
								'flyaffiliate'
							) }
						>
							<InputGroup>
								<InputGroupAddon align="inline-start">
									<InputGroupText>
										{ currency.symbol || currency.code }
									</InputGroupText>
								</InputGroupAddon>
								<InputGroupInput
									id="flyaffiliate-commission-base"
									type="number"
									min={ 0 }
									step="0.01"
									value={ values.base_amount }
									onChange={ ( e ) =>
										set( 'base_amount', e.target.value )
									}
									placeholder="0.00"
									disabled={ disabled }
								/>
							</InputGroup>
						</Row>

						<Row
							id="flyaffiliate-commission-order"
							label={ __( 'Reference', 'flyaffiliate' ) }
							hint={
								referenceFixed
									? __(
											'The order this commission came from. This can’t be changed.',
											'flyaffiliate'
									  )
									: __(
											'The order this commission is for, usually a WooCommerce order ID. With the WooCommerce origin, the order must exist.',
											'flyaffiliate'
									  )
							}
						>
							<InputGroup>
								<InputGroupInput
									id="flyaffiliate-commission-order"
									type="number"
									min={ 0 }
									value={ values.order_id }
									onChange={ ( e ) =>
										set( 'order_id', e.target.value )
									}
									placeholder={ __(
										'e.g. 1400',
										'flyaffiliate'
									) }
									disabled={ disabled || referenceFixed }
								/>
								{ orderUrl && (
									<SuffixLink
										label={ __(
											'View order',
											'flyaffiliate'
										) }
										href={ orderUrl }
										testId="flyaffiliate-commission-view-order"
									/>
								) }
							</InputGroup>
						</Row>

						<Row
							id="flyaffiliate-commission-type"
							label={ __( 'Type', 'flyaffiliate' ) }
							hint={ __(
								'What the commission is for.',
								'flyaffiliate'
							) }
						>
							<Select
								value={ values.type }
								onValueChange={ ( v ) =>
									set( 'type', String( v ) )
								}
								disabled={ disabled }
							>
								<SelectTrigger
									id="flyaffiliate-commission-type"
									className={ selectClass }
								>
									<SelectValue>
										{ types[ values.type ] ?? values.type }
									</SelectValue>
								</SelectTrigger>
								<SelectContent>
									{ Object.entries( types ).map(
										( [ value, label ] ) => (
											<SelectItem
												key={ value }
												value={ value }
											>
												{ label }
											</SelectItem>
										)
									) }
								</SelectContent>
							</Select>
						</Row>
					</Section>
				</div>

				<div className="grid content-start gap-6">
					<Section title={ __( 'Status', 'flyaffiliate' ) }>
						<Row
							id="flyaffiliate-commission-status"
							label={ __( 'Status', 'flyaffiliate' ) }
							hint={ statusHint[ values.status ] }
							required
						>
							<Select
								value={ values.status }
								onValueChange={ ( v ) =>
									set( 'status', String( v ) )
								}
								disabled={ disabled }
							>
								<SelectTrigger
									id="flyaffiliate-commission-status"
									className={ selectClass }
								>
									<SelectValue>
										{ statuses.commission[
											values.status
										] ?? values.status }
									</SelectValue>
								</SelectTrigger>
								<SelectContent>
									{ Object.entries( statuses.commission ).map(
										( [ value, label ] ) => (
											<SelectItem
												key={ value }
												value={ value }
											>
												{ label }
											</SelectItem>
										)
									) }
								</SelectContent>
							</Select>
						</Row>
					</Section>

					<Section title={ __( 'Origin', 'flyaffiliate' ) }>
						<Row
							id="flyaffiliate-commission-source"
							label={ __( 'Origin', 'flyaffiliate' ) }
							hint={
								editing
									? __(
											'Set when the commission was created.',
											'flyaffiliate'
									  )
									: __(
											'Where the commission comes from. A WooCommerce commission follows its order: unpaid once the order is paid, rejected if the order fails. A manual commission only follows the hold period.',
											'flyaffiliate'
									  )
							}
						>
							<Select
								value={ values.source }
								onValueChange={ ( v ) =>
									set( 'source', String( v ) )
								}
								disabled={ disabled || editing }
							>
								<SelectTrigger
									id="flyaffiliate-commission-source"
									className={ selectClass }
								>
									<SelectValue>
										{ sources[ values.source ] ??
											values.source }
									</SelectValue>
								</SelectTrigger>
								<SelectContent>
									{ Object.entries(
										editing ? sources : availableSources
									).map( ( [ value, label ] ) => (
										<SelectItem
											key={ value }
											value={ value }
										>
											{ label }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
						</Row>

						<Row
							id={
								editing
									? 'flyaffiliate-commission-date'
									: undefined
							}
							label={ __( 'Date', 'flyaffiliate' ) }
							hint={
								editing
									? __(
											'When the commission was earned, in your site’s timezone.',
											'flyaffiliate'
									  )
									: __(
											'When the commission was earned, in your site’s timezone. The hold period counts from this date.',
											'flyaffiliate'
									  )
							}
							required={ ! editing }
						>
							{ editing && commission ? (
								<Input
									id="flyaffiliate-commission-date"
									value={ formatDate(
										commission.created_at,
										true
									) }
									readOnly
									disabled
								/>
							) : (
								<div className="grid grid-cols-[minmax(0,1fr)_7.5rem] gap-2">
									<DatePicker
										value={ dayToDate( values.date ) }
										onChange={ ( next ) =>
											next && set( 'date', toYmd( next ) )
										}
										placeholder={ __(
											'Pick a date',
											'flyaffiliate'
										) }
										className="min-w-0"
									/>
									<Input
										id="flyaffiliate-commission-time"
										type="time"
										value={ values.time }
										onChange={ ( e ) =>
											set( 'time', e.target.value )
										}
										disabled={ disabled }
										aria-label={ __(
											'Time of day',
											'flyaffiliate'
										) }
										required
									/>
								</div>
							) }
						</Row>
					</Section>
				</div>
			</form>
		</>
	);
}
