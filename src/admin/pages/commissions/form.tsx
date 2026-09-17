/**
 * Add or edit one commission, on its own page — the fields SliceWP's
 * commission form has, in its order: ID, affiliate, amount, reference,
 * reference amount, origin, date, type, status.
 *
 * On edit the ID, the affiliate, the origin and the date are fixed, as in
 * SliceWP. A paid commission, or one inside a payment, is shown but cannot
 * change at all (CONTEXT.md money rule 7); the page says which lock holds it.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Link,
	useLocation,
	useNavigate,
	useParams,
	useSearchParams,
} from 'react-router-dom';
import { ArrowLeft, Lock, ReceiptText } from 'lucide-react';
import {
	Alert,
	AlertDescription,
	AlertTitle,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardFooter,
	CardHeader,
	CardTitle,
	Field,
	FieldDescription,
	FieldLabel,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	Spinner,
	toast,
} from '@wedevs/plugin-ui';
import AffiliatePicker from '@/components/AffiliatePicker';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';
import type { Commission } from '@/lib/types';

/** Where a status edit may go, mirroring `Commission\Manager::can_transition()`. */
const TRANSITIONS: Record< string, string[] > = {
	pending: [ 'unpaid', 'rejected' ],
	unpaid: [ 'pending', 'rejected' ],
	rejected: [ 'pending', 'unpaid' ],
	paid: [],
};

type Values = {
	affiliate_id: number | null;
	amount: string;
	base_amount: string;
	order_id: string;
	source: string;
	created_at: string;
	type: string;
	status: string;
};

/** A `datetime-local` value for now, in the browser's own timezone. */
function nowLocal(): string {
	const now = new Date();
	now.setSeconds( 0, 0 );
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );

	return `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad(
		now.getDate()
	) }T${ pad( now.getHours() ) }:${ pad( now.getMinutes() ) }`;
}

/**
 * A `datetime-local` value as `Y-m-d H:i:s` in GMT, what the API stores.
 * @param local
 */
function toGmt( local: string ): string {
	const date = new Date( local );

	return Number.isNaN( date.getTime() )
		? ''
		: date.toISOString().slice( 0, 19 ).replace( 'T', ' ' );
}

function Required() {
	return (
		<span className="text-destructive" aria-hidden="true">
			*
		</span>
	);
}

function FormSkeleton() {
	return (
		<div data-testid="flyaffiliate-commission-loading">
			<Skeleton className="mb-3 h-4 w-28" />
			<Skeleton className="mb-6 h-8 w-64" />
			<Card className="gap-0 rounded-md shadow ring-0">
				<CardHeader className="border-b border-border pb-5">
					<Skeleton className="h-5 w-40" />
					<Skeleton className="h-4 w-80" />
				</CardHeader>
				<CardContent className="grid gap-5 pt-5 sm:grid-cols-2">
					{ Array.from( { length: 8 } ).map( ( _, i ) => (
						<div key={ i } className="flex flex-col gap-2">
							<Skeleton className="h-4 w-24" />
							<Skeleton className="h-9 w-full" />
						</div>
					) ) }
				</CardContent>
			</Card>
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
	const { currency, statuses, sources, types } = getGlobals();

	const from =
		( location.state as { from?: string } | null )?.from ?? '/commissions';
	const presetAffiliate = Number( searchParams.get( 'affiliate' ) ) || null;

	const [ commission, setCommission ] = useState< Commission | null >( null );
	const [ loading, setLoading ] = useState( editing );
	const [ saving, setSaving ] = useState( false );
	const [ values, setValues ] = useState< Values >( {
		affiliate_id: presetAffiliate,
		amount: '',
		base_amount: '',
		order_id: '',
		source: 'woocommerce',
		created_at: nowLocal(),
		type: 'sale',
		// SliceWP's form starts on Paid; ours on Unpaid, so a slip does not
		// create a row nothing can change (a paid commission is terminal).
		status: 'unpaid',
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
				setValues( {
					affiliate_id: item.affiliate_id,
					amount: String( item.amount ),
					base_amount:
						Number( item.base_amount ) > 0
							? String( item.base_amount )
							: '',
					order_id: item.order_id > 0 ? String( item.order_id ) : '',
					source: item.source,
					created_at: item.created_at ?? '',
					type: item.type,
					status: item.status,
				} );
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

	const inPayout = Boolean( commission?.payout_id );
	const locked = editing && ( commission?.status === 'paid' || inPayout );
	// The reference of a commission that came from checkout is its order item's.
	const referenceFixed = editing && commission?.order_item_id !== null;

	const set = < K extends keyof Values >( key: K, value: Values[ K ] ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const statusOptions = useMemo( () => {
		const all = Object.entries( statuses.commission );

		if ( ! editing || ! commission ) {
			return all.map( ( [ value, label ] ) => ( {
				value,
				label,
				disabled: false,
			} ) );
		}

		const allowed = TRANSITIONS[ commission.status ] ?? [];

		return all.map( ( [ value, label ] ) => ( {
			value,
			label,
			disabled:
				value !== commission.status && ! allowed.includes( value ),
		} ) );
	}, [ editing, commission, statuses.commission ] );

	const statusHint: Record< string, string > = {
		pending: __(
			'Goes through the hold period first, and waits for its order to be paid.',
			'flyaffiliate'
		),
		unpaid: __( 'Ready to go out in the next payout.', 'flyaffiliate' ),
		paid: __(
			'Already paid outside FlyAffiliate. A paid commission cannot be changed afterwards.',
			'flyaffiliate'
		),
		rejected: __( 'Never paid.', 'flyaffiliate' ),
	};

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		if ( locked ) {
			return;
		}

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
			payload.affiliate_id = values.affiliate_id;
			payload.source = values.source;
			payload.created_at = toGmt( values.created_at );
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

	const disabled = locked || saving;

	let description: string = __(
		'For a commission WooCommerce did not work out itself, such as a bonus, a correction or an offline sale.',
		'flyaffiliate'
	);

	if ( locked ) {
		description = __(
			'Shown for the record: nothing on a locked commission can change.',
			'flyaffiliate'
		);
	} else if ( editing ) {
		description = __(
			'The affiliate, the origin and the date are fixed; the rest can change until the commission is paid.',
			'flyaffiliate'
		);
	}

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
			/>

			<form
				onSubmit={ submit }
				data-testid="flyaffiliate-commission-form"
			>
				<Card className="gap-0 rounded-md shadow ring-0">
					<CardHeader className="border-b border-border pb-5">
						<CardTitle className="text-base font-semibold">
							{ __( 'Commission details', 'flyaffiliate' ) }
						</CardTitle>
						<CardDescription>
							{ __(
								'A WooCommerce commission with an order follows that order: it becomes unpaid when the order is paid, and is rejected when the order fails or is refunded. A manual commission only follows the hold period.',
								'flyaffiliate'
							) }
						</CardDescription>
					</CardHeader>

					<CardContent className="grid gap-5 pt-5 sm:grid-cols-2">
						{ locked && commission && (
							<Alert
								className="sm:col-span-2"
								data-testid="flyaffiliate-commission-locked"
							>
								<Lock className="size-4" />
								<AlertTitle>
									{ inPayout
										? __(
												'This commission belongs to a payment',
												'flyaffiliate'
										  )
										: __(
												'This commission has been paid',
												'flyaffiliate'
										  ) }
								</AlertTitle>
								<AlertDescription>
									{ inPayout ? (
										<span>
											{ __(
												'Take it out of the payment first, or delete the payment.',
												'flyaffiliate'
											) }{ ' ' }
											<Link
												to={ `/payouts/payment/${ commission.payout_id }` }
												className="font-medium text-primary hover:underline"
											>
												{ sprintf(
													/* translators: %d: payment id */
													__(
														'Open payment #%d',
														'flyaffiliate'
													),
													commission.payout_id ?? 0
												) }
											</Link>
										</span>
									) : (
										__(
											'A paid commission is never edited, rescaled or deleted.',
											'flyaffiliate'
										)
									) }
								</AlertDescription>
							</Alert>
						) }

						{ editing && (
							<Field>
								<FieldLabel htmlFor="flyaffiliate-commission-id">
									{ __( 'Commission ID', 'flyaffiliate' ) }
								</FieldLabel>
								<Input
									id="flyaffiliate-commission-id"
									value={ `#${ commissionId }` }
									readOnly
									disabled
								/>
							</Field>
						) }

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-affiliate">
								{ __( 'Affiliate', 'flyaffiliate' ) }{ ' ' }
								{ ! editing && <Required /> }
							</FieldLabel>
							{ editing && commission ? (
								<>
									<Input
										id="flyaffiliate-commission-affiliate"
										value={
											commission.affiliate_name ||
											`#${ commission.affiliate_id }`
										}
										readOnly
										disabled
									/>
									<FieldDescription>
										<Link
											to={ `/affiliates/${ commission.affiliate_id }` }
											className="text-primary hover:underline"
										>
											{ __(
												'View affiliate',
												'flyaffiliate'
											) }
										</Link>
									</FieldDescription>
								</>
							) : (
								<AffiliatePicker
									value={ values.affiliate_id }
									onChange={ ( v ) =>
										set( 'affiliate_id', v )
									}
								/>
							) }
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-amount">
								{ __( 'Amount', 'flyaffiliate' ) } (
								{ currency.code }) <Required />
							</FieldLabel>
							<Input
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
							<FieldDescription>
								{ __(
									'What the affiliate earns.',
									'flyaffiliate'
								) }
							</FieldDescription>
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-order">
								{ __( 'Reference', 'flyaffiliate' ) }
							</FieldLabel>
							<Input
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
							<FieldDescription>
								{ referenceFixed
									? __(
											'The order item this commission came from. It cannot change.',
											'flyaffiliate'
									  )
									: __(
											'The WooCommerce order ID this commission belongs to, if there is one.',
											'flyaffiliate'
									  ) }
							</FieldDescription>
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-base">
								{ __( 'Reference amount', 'flyaffiliate' ) } (
								{ currency.code })
							</FieldLabel>
							<Input
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
							<FieldDescription>
								{ __(
									'The sale amount the commission is on. Leave it empty when there is no sale behind it.',
									'flyaffiliate'
								) }
							</FieldDescription>
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-source">
								{ __( 'Origin', 'flyaffiliate' ) }
							</FieldLabel>
							<Select
								value={ values.source }
								onValueChange={ ( v ) =>
									set( 'source', String( v ) )
								}
								disabled={ disabled || editing }
							>
								<SelectTrigger
									id="flyaffiliate-commission-source"
									className="w-full"
								>
									<SelectValue>
										{ sources[ values.source ] ??
											values.source }
									</SelectValue>
								</SelectTrigger>
								<SelectContent>
									{ Object.entries( sources ).map(
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
							<FieldDescription>
								{ editing
									? __(
											'Set when the commission was created.',
											'flyaffiliate'
									  )
									: __(
											'Where the commission comes from. More platforms can be added later.',
											'flyaffiliate'
									  ) }
							</FieldDescription>
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-date">
								{ __( 'Date', 'flyaffiliate' ) }{ ' ' }
								{ ! editing && <Required /> }
							</FieldLabel>
							{ editing ? (
								<Input
									id="flyaffiliate-commission-date"
									value={ formatDate(
										values.created_at,
										true
									) }
									readOnly
									disabled
								/>
							) : (
								<Input
									id="flyaffiliate-commission-date"
									type="datetime-local"
									value={ values.created_at }
									onChange={ ( e ) =>
										set( 'created_at', e.target.value )
									}
									disabled={ disabled }
									required
								/>
							) }
							<FieldDescription>
								{ editing
									? __(
											'When the commission was created.',
											'flyaffiliate'
									  )
									: __(
											'A pending commission matures the hold period after this date.',
											'flyaffiliate'
									  ) }
							</FieldDescription>
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-type">
								{ __( 'Type', 'flyaffiliate' ) }
							</FieldLabel>
							<Select
								value={ values.type }
								onValueChange={ ( v ) =>
									set( 'type', String( v ) )
								}
								disabled={ disabled }
							>
								<SelectTrigger
									id="flyaffiliate-commission-type"
									className="w-full"
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
						</Field>

						<Field>
							<FieldLabel htmlFor="flyaffiliate-commission-status">
								{ __( 'Status', 'flyaffiliate' ) } <Required />
							</FieldLabel>
							<Select
								value={ values.status }
								onValueChange={ ( v ) =>
									set( 'status', String( v ) )
								}
								disabled={ disabled }
							>
								<SelectTrigger
									id="flyaffiliate-commission-status"
									className="w-full"
								>
									<SelectValue>
										{ statuses.commission[
											values.status
										] ?? values.status }
									</SelectValue>
								</SelectTrigger>
								<SelectContent>
									{ statusOptions.map( ( option ) => (
										<SelectItem
											key={ option.value }
											value={ option.value }
											disabled={ option.disabled }
										>
											{ option.label }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
							<FieldDescription>
								{ editing && values.status !== 'paid'
									? __(
											'Paid is set by a payout, never here.',
											'flyaffiliate'
									  )
									: statusHint[ values.status ] ?? '' }
							</FieldDescription>
						</Field>
					</CardContent>

					<CardFooter className="justify-end gap-3 border-t border-border pt-5">
						<Button
							type="button"
							variant="outline"
							className={ BRAND_OUTLINE }
							onClick={ () => navigate( from ) }
						>
							{ locked
								? __( 'Back', 'flyaffiliate' )
								: __( 'Cancel', 'flyaffiliate' ) }
						</Button>
						{ ! locked && (
							<Button type="submit" disabled={ saving }>
								{ saving && <Spinner className="size-4" /> }
								{ editing
									? __( 'Save', 'flyaffiliate' )
									: __( 'Add commission', 'flyaffiliate' ) }
							</Button>
						) }
					</CardFooter>
				</Card>
			</form>
		</>
	);
}
