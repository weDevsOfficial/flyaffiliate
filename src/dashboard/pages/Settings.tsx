/**
 * Settings: the two things an affiliate can change about themselves.
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
	FieldDescription,
	FieldLabel,
	Input,
	Skeleton,
	Spinner,
	Textarea,
	toast,
} from '@wedevs/plugin-ui';
import { errorMessage, send } from '@/lib/api';
import type { AffiliateProfile } from '../types';

type Props = {
	profile: AffiliateProfile | null;
	onSaved: ( profile: AffiliateProfile ) => void;
};

export default function SettingsPage( { profile, onSaved }: Props ) {
	const [ paymentEmail, setPaymentEmail ] = useState( '' );
	const [ promoMethod, setPromoMethod ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( profile ) {
			setPaymentEmail( profile.payment_email ?? '' );
			setPromoMethod( profile.promo_method ?? '' );
		}
	}, [ profile ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();
		setSaving( true );

		try {
			const saved = await send< AffiliateProfile >( '/me', 'PUT', {
				payment_email: paymentEmail,
				promo_method: promoMethod,
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
									'Where your payouts are sent.',
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
