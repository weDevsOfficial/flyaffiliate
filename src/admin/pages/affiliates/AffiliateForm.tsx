/**
 * Add or edit an affiliate.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Field,
	FieldDescription,
	FieldLabel,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Spinner,
	toast,
} from '@wedevs/plugin-ui';
import FormDialog from '@/components/FormDialog';
import UserPicker from '@/components/UserPicker';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import type { Affiliate } from '@/lib/types';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	affiliate?: Affiliate | null;
	onSaved: ( affiliate: Affiliate ) => void;
};

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
	const [ promoMethod, setPromoMethod ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const editing = Boolean( affiliate );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		setUserId( affiliate?.user_id ?? null );
		setStatus( affiliate?.status ?? 'active' );
		setPaymentEmail( affiliate?.payment_email ?? '' );
		setPromoMethod( affiliate?.promo_method ?? '' );
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
				promo_method: promoMethod,
				...( editing ? {} : { user_id: userId } ),
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
			title={
				editing
					? __( 'Edit affiliate', 'flyaffiliate' )
					: __( 'Add affiliate', 'flyaffiliate' )
			}
			description={
				editing
					? affiliate?.name
					: __(
							'Any WordPress user can be an affiliate. Their referral link is created immediately.',
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
			{ ! editing && (
				<Field>
					<FieldLabel>{ __( 'User', 'flyaffiliate' ) }</FieldLabel>
					<UserPicker value={ userId } onChange={ setUserId } />
				</Field>
			) }

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-status">
					{ __( 'Status', 'flyaffiliate' ) }
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
			</Field>

			<Field>
				<FieldLabel htmlFor="flyaffiliate-affiliate-promo">
					{ __( 'How they promote', 'flyaffiliate' ) }
				</FieldLabel>
				<Input
					id="flyaffiliate-affiliate-promo"
					value={ promoMethod }
					onChange={ ( e ) => setPromoMethod( e.target.value ) }
					placeholder={ __(
						'Blog, newsletter, YouTube…',
						'flyaffiliate'
					) }
				/>
			</Field>
		</FormDialog>
	);
}
