/**
 * Add or edit an affiliate — the fields SliceWP's affiliate form has.
 *
 * Adding: the user, their payment email, website and promotion method, the
 * status, and whether to send a welcome email. Editing: the name, email, IDs
 * and registration date are shown but fixed, as in SliceWP; only the payment
 * email, website, promotion method and status change.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Field,
	FieldDescription,
	FieldLabel,
	Input,
	LabeledSwitch,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Spinner,
	Textarea,
	toast,
} from '@wedevs/plugin-ui';
import FormDialog from '@/components/FormDialog';
import UserPicker from '@/components/UserPicker';
import { errorMessage, send } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import type { Affiliate } from '@/lib/types';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	affiliate?: Affiliate | null;
	onSaved: ( affiliate: Affiliate ) => void;
};

function Required() {
	return (
		<span className="text-destructive" aria-hidden="true">
			*
		</span>
	);
}

/**
 * A fact about the affiliate that the form shows but does not change.
 * @param root0
 * @param root0.id
 * @param root0.label
 * @param root0.value
 */
function Fixed( {
	id,
	label,
	value,
}: {
	id: string;
	label: string;
	value: string;
} ) {
	return (
		<Field>
			<FieldLabel htmlFor={ id }>{ label }</FieldLabel>
			<Input id={ id } value={ value } readOnly disabled />
		</Field>
	);
}

export default function AffiliateForm( {
	open,
	onOpenChange,
	affiliate,
	onSaved,
}: Props ) {
	const { statuses } = getGlobals();
	const [ userId, setUserId ] = useState< number | null >( null );
	const [ status, setStatus ] = useState( 'active' );
	const [ paymentEmail, setPaymentEmail ] = useState( '' );
	const [ website, setWebsite ] = useState( '' );
	const [ promoMethod, setPromoMethod ] = useState( '' );
	const [ welcomeEmail, setWelcomeEmail ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const editing = Boolean( affiliate );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		setUserId( affiliate?.user_id ?? null );
		setStatus( affiliate?.status ?? 'active' );
		setPaymentEmail( affiliate?.payment_email ?? '' );
		setWebsite( affiliate?.website ?? '' );
		setPromoMethod( affiliate?.promo_method ?? '' );
		setWelcomeEmail( false );
	}, [ open, affiliate ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		if ( ! editing && ! userId ) {
			toast.error(
				__(
					'Choose the user who becomes the affiliate.',
					'flyaffiliate'
				)
			);
			return;
		}

		setSaving( true );

		try {
			const payload = {
				status,
				payment_email: paymentEmail,
				website,
				promo_method: promoMethod,
				...( editing
					? {}
					: { user_id: userId, send_welcome_email: welcomeEmail } ),
			};
			const saved = editing
				? await send< Affiliate >(
						`/affiliates/${ affiliate!.id }`,
						'PUT',
						payload
				  )
				: await send< Affiliate >( '/affiliates', 'POST', payload );

			toast.success(
				editing
					? __( 'Affiliate updated.', 'flyaffiliate' )
					: __( 'Affiliate added.', 'flyaffiliate' )
			);
			onSaved( saved );
			onOpenChange( false );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The affiliate could not be saved.', 'flyaffiliate' )
				)
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<FormDialog
			open={ open }
			onOpenChange={ onOpenChange }
			onSubmit={ submit }
			testId="flyaffiliate-affiliate-form"
			className="sm:max-w-xl"
			title={
				editing
					? __( 'Edit affiliate', 'flyaffiliate' )
					: __( 'Add affiliate', 'flyaffiliate' )
			}
			description={
				editing
					? __(
							'The user account can’t be changed. The payment details and the status can.',
							'flyaffiliate'
					  )
					: __(
							'Any WordPress user can become an affiliate. Their referral link is ready right away.',
							'flyaffiliate'
					  )
			}
			footer={
				<>
					<Button
						type="button"
						variant="outline"
						onClick={ () => onOpenChange( false ) }
					>
						{ __( 'Cancel', 'flyaffiliate' ) }
					</Button>
					<Button type="submit" disabled={ saving }>
						{ saving && <Spinner className="size-4" /> }
						{ editing
							? __( 'Save', 'flyaffiliate' )
							: __( 'Add affiliate', 'flyaffiliate' ) }
					</Button>
				</>
			}
		>
			{ editing && affiliate ? (
				<div className="grid gap-5 sm:grid-cols-2">
					<Fixed
						id="flyaffiliate-affiliate-name"
						label={ __( 'Affiliate name', 'flyaffiliate' ) }
						value={ affiliate.name }
					/>
					<Fixed
						id="flyaffiliate-affiliate-account-email"
						label={ __( 'Email', 'flyaffiliate' ) }
						value={ affiliate.email }
					/>
					<Fixed
						id="flyaffiliate-affiliate-id"
						label={ __( 'Affiliate ID', 'flyaffiliate' ) }
						value={ `#${ affiliate.id }` }
					/>
					<Fixed
						id="flyaffiliate-affiliate-user-id"
						label={ __( 'User ID', 'flyaffiliate' ) }
						value={ `#${ affiliate.user_id }` }
					/>
					<Fixed
						id="flyaffiliate-affiliate-registered"
						label={ __( 'Registration date', 'flyaffiliate' ) }
						value={ formatDate( affiliate.created_at, true ) }
					/>
				</div>
			) : (
				<Field>
					<FieldLabel>
						{ __( 'User', 'flyaffiliate' ) } <Required />
					</FieldLabel>
					<UserPicker value={ userId } onChange={ setUserId } />
				</Field>
			) }

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-email">
					{ __( 'Payment email', 'flyaffiliate' ) }
				</FieldLabel>
				<Input
					id="flyaffiliate-affiliate-email"
					type="email"
					value={ paymentEmail }
					onChange={ ( e ) => setPaymentEmail( e.target.value ) }
					placeholder={ __(
						'Defaults to the account email',
						'flyaffiliate'
					) }
				/>
				<FieldDescription>
					{ __(
						'Where payouts for this affiliate are sent.',
						'flyaffiliate'
					) }
				</FieldDescription>
			</Field>

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-website">
					{ __( 'Website', 'flyaffiliate' ) }
				</FieldLabel>
				<Input
					id="flyaffiliate-affiliate-website"
					type="url"
					value={ website }
					onChange={ ( e ) => setWebsite( e.target.value ) }
					placeholder="example.com"
				/>
			</Field>

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-promo">
					{ __( 'How will they promote you?', 'flyaffiliate' ) }
				</FieldLabel>
				<Textarea
					id="flyaffiliate-affiliate-promo"
					value={ promoMethod }
					onChange={ ( e ) => setPromoMethod( e.target.value ) }
					placeholder={ __(
						'Blog, newsletter, YouTube…',
						'flyaffiliate'
					) }
					rows={ 3 }
				/>
			</Field>

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-status">
					{ __( 'Status', 'flyaffiliate' ) } <Required />
				</FieldLabel>
				<Select
					value={ status }
					items={ statuses.affiliate }
					onValueChange={ ( v ) => setStatus( String( v ) ) }
				>
					<SelectTrigger
						id="flyaffiliate-affiliate-status"
						className="w-full"
					>
						<SelectValue>
							{ statuses.affiliate[ status ] ?? status }
						</SelectValue>
					</SelectTrigger>
					<SelectContent>
						{ Object.entries( statuses.affiliate ).map(
							( [ value, label ] ) => (
								<SelectItem key={ value } value={ value }>
									{ label }
								</SelectItem>
							)
						) }
					</SelectContent>
				</Select>
				<FieldDescription>
					{ __(
						'Only an active affiliate earns commissions.',
						'flyaffiliate'
					) }
				</FieldDescription>
			</Field>

			{ ! editing && (
				<LabeledSwitch
					id="flyaffiliate-affiliate-welcome"
					label={ __( 'Send welcome email', 'flyaffiliate' ) }
					description={ __(
						'Email the new affiliate their referral link and dashboard address.',
						'flyaffiliate'
					) }
					checked={ welcomeEmail }
					onCheckedChange={ ( checked ) =>
						setWelcomeEmail( Boolean( checked ) )
					}
				/>
			) }
		</FormDialog>
	);
}
