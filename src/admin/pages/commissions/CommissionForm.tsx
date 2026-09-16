/**
 * Add a manual commission, or change a manual commission's amount.
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
import AffiliatePicker from '@/components/AffiliatePicker';
import FormDialog from '@/components/FormDialog';
import { errorMessage, send } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import type { Commission } from '@/lib/types';

type Props = {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	commission?: Commission | null;
	affiliateId?: number;
	onSaved: () => void;
};

export default function CommissionForm( {
	open,
	onOpenChange,
	commission,
	affiliateId,
	onSaved,
}: Props ) {
	const { currency, statuses } = getGlobals();
	const [ affiliate, setAffiliate ] = useState< number | null >(
		affiliateId ?? null
	);
	const [ amount, setAmount ] = useState( '' );
	const [ baseAmount, setBaseAmount ] = useState( '' );
	const [ orderId, setOrderId ] = useState( '' );
	const [ status, setStatus ] = useState( 'pending' );
	const [ saving, setSaving ] = useState( false );
	const editing = Boolean( commission );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		setAffiliate( commission?.affiliate_id ?? affiliateId ?? null );
		setAmount( commission ? String( commission.amount ) : '' );
		setBaseAmount( commission ? String( commission.base_amount ) : '' );
		setOrderId( commission?.order_id ? String( commission.order_id ) : '' );
		setStatus( commission?.status ?? 'pending' );
	}, [ open, commission, affiliateId ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		if ( ! editing && ! affiliate ) {
			toast.error(
				__(
					'Choose the affiliate who earns this commission.',
					'flyaffiliate'
				)
			);
			return;
		}

		if ( amount === '' || Number( amount ) < 0 ) {
			toast.error(
				__( 'Enter an amount of zero or more.', 'flyaffiliate' )
			);
			return;
		}

		setSaving( true );

		try {
			if ( editing ) {
				await send( `/commissions/${ commission!.id }`, 'PUT', {
					amount: Number( amount ),
				} );
				toast.success( __( 'Commission updated.', 'flyaffiliate' ) );
			} else {
				await send( '/commissions', 'POST', {
					affiliate_id: affiliate,
					amount: Number( amount ),
					base_amount: baseAmount === '' ? 0 : Number( baseAmount ),
					order_id: orderId === '' ? 0 : Number( orderId ),
					status,
				} );
				toast.success( __( 'Commission added.', 'flyaffiliate' ) );
			}

			onSaved();
			onOpenChange( false );
		} catch ( error ) {
			toast.error(
				errorMessage(
					error,
					__( 'The commission could not be saved.', 'flyaffiliate' )
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
			testId="flyaffiliate-commission-form"
			title={
				editing
					? __( 'Edit commission', 'flyaffiliate' )
					: __( 'Add commission', 'flyaffiliate' )
			}
			description={
				editing
					? __(
							'Only the amount of a manual commission can change. Paid commissions are locked.',
							'flyaffiliate'
					  )
					: __(
							'For a commission WooCommerce did not work out itself, such as a bonus, a correction or an offline sale. It goes through the same hold period as any other.',
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
							: __( 'Add commission', 'flyaffiliate' ) }
					</Button>
				</>
			}
		>
			{ ! editing && ! affiliateId && (
				<Field>
					<FieldLabel>
						{ __( 'Affiliate', 'flyaffiliate' ) }
					</FieldLabel>
					<AffiliatePicker
						value={ affiliate }
						onChange={ setAffiliate }
					/>
				</Field>
			) }

			<Field>
				<FieldLabel htmlFor="flyaffiliate-commission-amount">
					{ __( 'Amount', 'flyaffiliate' ) } ({ currency.code })
				</FieldLabel>
				<Input
					id="flyaffiliate-commission-amount"
					type="number"
					min={ 0 }
					step="0.01"
					value={ amount }
					onChange={ ( e ) => setAmount( e.target.value ) }
					placeholder="0.00"
					required
				/>
			</Field>

			{ ! editing && (
				<>
					<Field>
						<FieldLabel htmlFor="flyaffiliate-commission-base">
							{ __( 'Sale amount', 'flyaffiliate' ) }
						</FieldLabel>
						<Input
							id="flyaffiliate-commission-base"
							type="number"
							min={ 0 }
							step="0.01"
							value={ baseAmount }
							onChange={ ( e ) =>
								setBaseAmount( e.target.value )
							}
							placeholder="0.00"
						/>
						<FieldDescription>
							{ __(
								'The order total this commission is based on. Leave it empty when there is no sale behind it.',
								'flyaffiliate'
							) }
						</FieldDescription>
					</Field>

					<Field>
						<FieldLabel htmlFor="flyaffiliate-commission-order">
							{ __( 'Order ID', 'flyaffiliate' ) }
						</FieldLabel>
						<Input
							id="flyaffiliate-commission-order"
							type="number"
							min={ 0 }
							value={ orderId }
							onChange={ ( e ) => setOrderId( e.target.value ) }
							placeholder={ __( 'e.g. 1400', 'flyaffiliate' ) }
						/>
						<FieldDescription>
							{ __(
								'The WooCommerce order this commission belongs to, if there is one.',
								'flyaffiliate'
							) }
						</FieldDescription>
					</Field>

					<Field>
						<FieldLabel htmlFor="flyaffiliate-commission-status">
							{ __( 'Status', 'flyaffiliate' ) }
						</FieldLabel>
						<Select
							value={ status }
							onValueChange={ ( v ) => setStatus( String( v ) ) }
						>
							<SelectTrigger
								id="flyaffiliate-commission-status"
								className="w-full"
							>
								<SelectValue>
									{ statuses.commission[ status ] ?? status }
								</SelectValue>
							</SelectTrigger>
							<SelectContent>
								<SelectItem value="pending">
									{ statuses.commission.pending }
								</SelectItem>
								<SelectItem value="unpaid">
									{ statuses.commission.unpaid }
								</SelectItem>
							</SelectContent>
						</Select>
						<FieldDescription>
							{ __(
								'Pending goes through the hold period first. Unpaid can go out in the next payout.',
								'flyaffiliate'
							) }
						</FieldDescription>
					</Field>
				</>
			) }
		</FormDialog>
	);
}
