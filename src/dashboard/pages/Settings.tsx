/**
 * Settings: what an affiliate can change about themselves — how they get
 * paid, where, and how they promote the store.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	Field,
	FieldLabel,
	Input,
	LabeledRadio,
	RadioGroup,
	Skeleton,
	Spinner,
	Textarea,
	toast,
} from '@wedevs/plugin-ui';
import Hint from '@/components/Hint';
import MethodName, { methodLogo } from '@/components/MethodName';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import type {
	ConnectedAccount as ConnectedAccountData,
	PayoutMethodField,
} from '@/lib/types';
import type { AffiliateProfile } from '../types';

/**
 * A field's label, with its help text in a tooltip beside it.
 *
 * @param {Object} props         The props.
 * @param {string} props.label   The label.
 * @param {string} props.hint    The help text; none for no icon.
 * @param {string} props.htmlFor The input it labels.
 * @return {JSX.Element} The label.
 */
function HintedLabel( {
	label,
	hint,
	htmlFor,
}: {
	label: string;
	hint?: string;
	htmlFor?: string;
} ) {
	return (
		<span className="flex items-center gap-1.5">
			<FieldLabel htmlFor={ htmlFor }>{ label }</FieldLabel>
			{ hint && <Hint text={ hint } /> }
		</span>
	);
}

/**
 * One detail a payout method asks for.
 *
 * @param {Object}   props          The props.
 * @param {string}   props.method   The method's id.
 * @param {Object}   props.field    The field.
 * @param {string}   props.value    Its value.
 * @param {Function} props.onChange Called with the new value.
 * @return {JSX.Element} The field.
 */
function DetailField( {
	method,
	field,
	value,
	onChange,
}: {
	method: string;
	field: PayoutMethodField;
	value: string;
	onChange: ( value: string ) => void;
} ) {
	const id = `flyaffiliate-payout-${ method }-${ field.id }`;

	if ( field.type === 'select' ) {
		return (
			<Field>
				<HintedLabel label={ field.label } hint={ field.description } />
				<RadioGroup value={ value } onValueChange={ onChange }>
					{ Object.entries( field.options ).map(
						( [ option, label ] ) => (
							<LabeledRadio
								key={ option }
								value={ option }
								label={ label }
							/>
						)
					) }
				</RadioGroup>
			</Field>
		);
	}

	return (
		<Field>
			<HintedLabel
				label={ field.label }
				hint={ field.description }
				htmlFor={ id }
			/>
			<Input
				id={ id }
				type={ field.type === 'email' ? 'email' : 'text' }
				required={ field.required }
				placeholder={ field.placeholder }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</Field>
	);
}

/**
 * The account a method pays the affiliate to without asking them for
 * anything (a vendor's store account), in place of the method's fields;
 * or, not connected yet, offered with Connect above them.
 *
 * @param {Object} props         The props.
 * @param {string} props.method  The method's id.
 * @param {Object} props.account The account.
 * @return {JSX.Element} The account.
 */
function ConnectedAccount( {
	method,
	account,
}: {
	method: string;
	account: ConnectedAccountData;
} ) {
	const logo = methodLogo( method );
	const isConnected = false !== account.connected;

	return (
		<Field
			data-testid={ `flyaffiliate-payout-${ method }-${
				isConnected ? 'connected' : 'offered'
			}` }
		>
			<HintedLabel
				label={ __( 'Where you get paid', 'flyaffiliate' ) }
				hint={
					isConnected
						? __(
								'Your payouts are sent to this account, so there is nothing to enter.',
								'flyaffiliate'
						  )
						: __(
								'Connect it and your payouts are sent there, with nothing to enter. Until then they go to the details below.',
								'flyaffiliate'
						  )
				}
			/>
			<div className="flex items-center gap-3 rounded-md border border-border p-3">
				{ logo && (
					<div className="flex size-10 shrink-0 items-center justify-center rounded bg-primary/10">
						<img
							src={ logo }
							alt=""
							aria-hidden="true"
							className="size-5 object-contain"
						/>
					</div>
				) }
				<div className="grid min-w-0 flex-1 gap-0.5">
					<span className="text-sm font-medium text-foreground">
						{ account.label }
					</span>
					<span className="truncate text-sm text-muted-foreground">
						{ isConnected
							? account.detail
							: __( 'Not connected yet', 'flyaffiliate' ) }
					</span>
				</div>
				{ account.url && (
					<Button
						variant={ isConnected ? 'secondary' : 'default' }
						size="sm"
						className="shrink-0"
						render={
							// eslint-disable-next-line jsx-a11y/anchor-has-content
							<a href={ account.url } />
						}
						data-testid={ `flyaffiliate-payout-${ method }-${
							isConnected ? 'change' : 'connect'
						}` }
					>
						{ isConnected
							? __( 'Change', 'flyaffiliate' )
							: __( 'Connect', 'flyaffiliate' ) }
					</Button>
				) }
			</div>
		</Field>
	);
}

type Props = {
	profile: AffiliateProfile | null;
	onSaved: ( profile: AffiliateProfile ) => void;
};

export default function SettingsPage( { profile, onSaved }: Props ) {
	const { payoutMethods, payoutDefault = 'manual' } = getGlobals();
	const [ paymentEmail, setPaymentEmail ] = useState( '' );
	const [ promoMethod, setPromoMethod ] = useState( '' );
	const [ payoutMethod, setPayoutMethod ] = useState( '' );
	const [ details, setDetails ] = useState<
		Record< string, Record< string, string > >
	>( {} );
	const [ saving, setSaving ] = useState( false );

	// The methods the affiliate can choose; the picker only shows when there is a choice.
	const choosable = payoutMethods.filter( ( method ) => method.enabled );
	const chosen =
		choosable.find( ( method ) => method.id === payoutMethod ) ??
		choosable.find( ( method ) => method.id === payoutDefault ) ??
		choosable[ 0 ];
	// The payment email is where Manual pays; another method has its own
	// details, so it only shows while they are paid by hand.
	const paidByHand = ! chosen || chosen.id === 'manual';
	// An account the method already pays them to stands in for its fields;
	// one they could connect is offered above them.
	const account = chosen
		? profile?.connected_accounts?.[ chosen.id ]
		: undefined;
	const connected =
		account && false !== account.connected ? account : undefined;

	useEffect( () => {
		if ( profile ) {
			setPaymentEmail( profile.payment_email ?? '' );
			setPromoMethod( profile.promo_method ?? '' );
			setPayoutMethod( profile.payout_method ?? '' );
			setDetails( profile.payout_details ?? {} );
		}
	}, [ profile ] );

	const setDetail = ( method: string, field: string, value: string ) =>
		setDetails( {
			...details,
			[ method ]: { ...( details[ method ] ?? {} ), [ field ]: value },
		} );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();
		setSaving( true );

		try {
			const saved = await send< AffiliateProfile >( '/me', 'PUT', {
				payment_email: paymentEmail,
				promo_method: promoMethod,
				...( choosable.length > 1 && chosen
					? { payout_method: chosen.id }
					: {} ),
				// Only the chosen method's details: another method's empty
				// required field is not this save's business.
				...( chosen && chosen.fields.length > 0 && ! connected
					? {
							payout_details: {
								[ chosen.id ]: details[ chosen.id ] ?? {},
							},
					  }
					: {} ),
			} );
			onSaved( saved );
			toast.success( __( 'Settings saved.', 'flyaffiliate' ) );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The settings could not be saved.', 'flyaffiliate' )
				)
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card className="max-w-2xl gap-5 rounded-md border border-border shadow ring-0">
			<CardHeader className="border-b border-border pb-5">
				<CardTitle className="text-base font-semibold">
					{ __( 'Your details', 'flyaffiliate' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Where payouts go, and how you tell people about us.',
						'flyaffiliate'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ ! profile ? (
					<div className="grid gap-5">
						<Skeleton className="h-9 w-full" />
						<Skeleton className="h-24 w-full" />
					</div>
				) : (
					<form
						onSubmit={ submit }
						className="grid gap-5"
						data-testid="flyaffiliate-dashboard-settings"
					>
						{ choosable.length > 1 && chosen && (
							<Field data-testid="flyaffiliate-payout-method">
								<FieldLabel>
									{ __( 'How you get paid', 'flyaffiliate' ) }
								</FieldLabel>
								<RadioGroup
									value={ chosen.id }
									onValueChange={ ( value ) =>
										setPayoutMethod( String( value ) )
									}
									className="grid gap-2"
								>
									{ choosable.map( ( method ) => (
										<LabeledRadio
											key={ method.id }
											value={ method.id }
											label={
												<MethodName
													id={ method.id }
													label={ method.label }
												/>
											}
										/>
									) ) }
								</RadioGroup>
							</Field>
						) }

						{ chosen && account && (
							<ConnectedAccount
								method={ chosen.id }
								account={ account }
							/>
						) }

						{ ! connected &&
							chosen?.fields.map( ( field ) => (
								<DetailField
									key={ `${ chosen.id }-${ field.id }` }
									method={ chosen.id }
									field={ field }
									value={
										details[ chosen.id ]?.[ field.id ] ?? ''
									}
									onChange={ ( value ) =>
										setDetail( chosen.id, field.id, value )
									}
								/>
							) ) }

						{ paidByHand && (
							<Field>
								<HintedLabel
									label={ __(
										'Payment email',
										'flyaffiliate'
									) }
									hint={ __(
										'Where payouts made by hand are sent.',
										'flyaffiliate'
									) }
									htmlFor="flyaffiliate-payment-email"
								/>
								<Input
									id="flyaffiliate-payment-email"
									type="email"
									value={ paymentEmail }
									onChange={ ( e ) =>
										setPaymentEmail( e.target.value )
									}
								/>
							</Field>
						) }
						<Field>
							<FieldLabel htmlFor="flyaffiliate-promo-method">
								{ __(
									'How do you promote us?',
									'flyaffiliate'
								) }
							</FieldLabel>
							<Textarea
								id="flyaffiliate-promo-method"
								rows={ 4 }
								value={ promoMethod }
								onChange={ ( e ) =>
									setPromoMethod( e.target.value )
								}
							/>
						</Field>
						<Button
							type="submit"
							disabled={ saving }
							className="justify-self-start"
						>
							{ saving && <Spinner className="size-4" /> }
							{ __( 'Save', 'flyaffiliate' ) }
						</Button>
					</form>
				) }
			</CardContent>
		</Card>
	);
}
