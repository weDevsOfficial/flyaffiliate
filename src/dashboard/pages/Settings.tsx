/**
 * Settings: what an affiliate can change about themselves — how they get
 * paid, where, and how they promote the store.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	Field,
	FieldDescription,
	FieldLabel,
	Input,
	LabeledRadio,
	RadioGroup,
	Skeleton,
	Spinner,
	Textarea,
	toast,
} from '@wedevs/plugin-ui';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import type { PayoutMethodField } from '@/lib/types';
import type { AffiliateProfile } from '../types';

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
				<FieldLabel>{ field.label }</FieldLabel>
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
				{ field.description && (
					<FieldDescription>{ field.description }</FieldDescription>
				) }
			</Field>
		);
	}

	return (
		<Field>
			<FieldLabel htmlFor={ id }>{ field.label }</FieldLabel>
			<Input
				id={ id }
				type={ field.type === 'email' ? 'email' : 'text' }
				required={ field.required }
				placeholder={ field.placeholder }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
			{ field.description && (
				<FieldDescription>{ field.description }</FieldDescription>
			) }
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
				...( chosen && chosen.fields.length > 0
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
											label={ method.label }
										/>
									) ) }
								</RadioGroup>
								<FieldDescription>
									{ sprintf(
										/* translators: %s: payout method */
										__(
											'Your payouts are sent by %s.',
											'flyaffiliate'
										),
										chosen.label
									) }
								</FieldDescription>
							</Field>
						) }

						{ chosen?.fields.map( ( field ) => (
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

						<Field>
							<FieldLabel htmlFor="flyaffiliate-payment-email">
								{ __( 'Payment email', 'flyaffiliate' ) }
							</FieldLabel>
							<Input
								id="flyaffiliate-payment-email"
								type="email"
								value={ paymentEmail }
								onChange={ ( e ) =>
									setPaymentEmail( e.target.value )
								}
							/>
							<FieldDescription>
								{ __(
									'Where payouts made by hand are sent.',
									'flyaffiliate'
								) }
							</FieldDescription>
						</Field>
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
