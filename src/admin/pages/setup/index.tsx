/**
 * The setup wizard: three short steps over the settings schema, then done.
 *
 * Every field is rendered from the same schema the settings screen uses, so
 * labels, descriptions and validation are the ones the admin meets later.
 * Nothing is written until a step is continued.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, Check, PartyPopper } from 'lucide-react';
import {
	Button,
	Card,
	CardContent,
	CardFooter,
	CardHeader,
	Field,
	FieldDescription,
	FieldError,
	FieldLabel,
	Input,
	LabeledSwitch,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	Spinner,
	cn,
	toast,
	type SettingsElement,
} from '@wedevs/plugin-ui';
import CopyButton from '@/components/CopyButton';
import { errorMessage, fetchOne, send, type ApiError } from '@/lib/api';
import { getGlobals, markSetupDone } from '@/lib/globals';

type StepId = 'commissions' | 'tracking' | 'email' | 'done';

type Step = {
	id: StepId;
	label: string;
	title: string;
	description: string;
	fields: string[];
};

const STEPS: Step[] = [
	{
		id: 'commissions',
		label: __( 'Commissions', 'flyaffiliate' ),
		title: __( 'Commission basics', 'flyaffiliate' ),
		description: __(
			'How much do affiliates earn, and how long do you hold it?',
			'flyaffiliate'
		),
		fields: [ 'currency', 'default_rate', 'max_rate', 'hold_days' ],
	},
	{
		id: 'tracking',
		label: __( 'Tracking', 'flyaffiliate' ),
		title: __( 'Track referrals', 'flyaffiliate' ),
		description: __(
			'FlyAffiliate credits affiliates for the WooCommerce orders they refer.',
			'flyaffiliate'
		),
		fields: [ 'woocommerce_enabled', 'cookie_duration' ],
	},
	{
		id: 'email',
		label: __( 'Email', 'flyaffiliate' ),
		title: __( 'Affiliate email', 'flyaffiliate' ),
		description: __(
			'The activation link is the only email this version sends.',
			'flyaffiliate'
		),
		fields: [ 'activation_email_enabled' ],
	},
	{
		id: 'done',
		label: __( 'Done', 'flyaffiliate' ),
		title: __( 'You’re all set', 'flyaffiliate' ),
		description: __(
			'FlyAffiliate is ready. Two pages were created for your affiliates.',
			'flyaffiliate'
		),
		fields: [],
	},
];

type FieldElement = SettingsElement & {
	page_id?: string;
	subpage_id?: string;
	section_id?: string;
	title?: string;
	description?: string;
	value?: unknown;
	postfix?: string;
};

/**
 * The numbered step indicator.
 *
 * @param {Object} props         Props.
 * @param {number} props.current Index of the current step.
 * @return {JSX.Element} The indicator.
 */
function Stepper( { current }: { current: number } ) {
	return (
		<ol className="m-0 flex w-full list-none items-center p-0">
			{ STEPS.map( ( step, index ) => {
				const done = index < current;
				const active = index === current;
				let state = 'upcoming';

				if ( done ) {
					state = 'done';
				} else if ( active ) {
					state = 'active';
				}

				return (
					<li
						key={ step.id }
						className={ cn(
							'flex items-center gap-2',
							index < STEPS.length - 1 && 'flex-1'
						) }
						aria-current={ active ? 'step' : undefined }
						data-testid={ `flyaffiliate-setup-step-${ step.id }` }
						data-state={ state }
					>
						<span
							className={ cn(
								'flex size-7 items-center justify-center rounded-full text-xs font-semibold',
								done && 'bg-primary text-primary-foreground',
								active &&
									'bg-primary text-primary-foreground ring-4 ring-primary/20',
								! done &&
									! active &&
									'bg-muted text-muted-foreground'
							) }
						>
							{ done ? (
								<Check className="size-4" aria-hidden="true" />
							) : (
								index + 1
							) }
						</span>
						<span
							className={ cn(
								'whitespace-nowrap text-sm',
								active
									? 'font-semibold text-foreground'
									: 'text-muted-foreground'
							) }
						>
							{ step.label }
						</span>
						{ index < STEPS.length - 1 && (
							<span
								className={ cn(
									'mx-3 h-px min-w-6 flex-1',
									done ? 'bg-primary' : 'bg-border'
								) }
								aria-hidden="true"
							/>
						) }
					</li>
				);
			} ) }
		</ol>
	);
}

export default function SetupPage() {
	const navigate = useNavigate();
	const { setup, urls } = getGlobals();
	const [ schema, setSchema ] = useState< FieldElement[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ current, setCurrent ] = useState( 0 );
	const [ values, setValues ] = useState< Record< string, unknown > >( {} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState< 'continue' | 'skip' | null >( null );

	const step = STEPS[ current ];

	const elements = useMemo( () => {
		const byId: Record< string, FieldElement > = {};

		schema.forEach( ( element ) => {
			byId[ element.id ] = element;
		} );

		return byId;
	}, [ schema ] );

	const fields = useMemo( () => {
		const byId: Record< string, FieldElement > = {};

		schema.forEach( ( element ) => {
			if ( element.type === 'field' ) {
				byId[ element.id ] = element;
			}
		} );

		return byId;
	}, [ schema ] );

	/**
	 * The settings page a field belongs to, found by walking up its parents.
	 *
	 * @param {string} id A field id.
	 * @return {string} The page id, or an empty string.
	 */
	const pageOf = ( id: string ): string => {
		let element: FieldElement | undefined = elements[ id ];

		while ( element ) {
			if ( element.type === 'page' ) {
				return element.id;
			}

			const parent: string | undefined =
				element.section_id ?? element.subpage_id ?? element.page_id;

			element = parent ? elements[ parent ] : undefined;
		}

		return '';
	};

	useEffect( () => {
		fetchOne< FieldElement[] >( '/settings' )
			.then( ( response ) => {
				setSchema( response );

				const initial: Record< string, unknown > = {};

				response.forEach( ( element ) => {
					if ( element.type === 'field' ) {
						initial[ element.id ] = element.value;
					}
				} );

				setValues( initial );
			} )
			.catch( ( error ) =>
				toast.error(
					errorMessage(
						error,
						__( 'Could not load the settings.', 'flyaffiliate' )
					)
				)
			)
			.finally( () => setLoading( false ) );
	}, [] );

	const setValue = ( id: string, value: unknown ) => {
		setValues( ( previous ) => ( { ...previous, [ id ]: value } ) );
		setErrors( ( previous ) => {
			if ( ! previous[ id ] ) {
				return previous;
			}

			const next = { ...previous };
			delete next[ id ];

			return next;
		} );
	};

	const complete = async () => {
		await send( '/setup/complete', 'POST' );
		markSetupDone();
	};

	/**
	 * Save the current step's fields, one request per settings page.
	 */
	const saveStep = async () => {
		const byPage: Record< string, Record< string, unknown > > = {};

		step.fields.forEach( ( id ) => {
			const page = pageOf( id );

			if ( ! page ) {
				return;
			}

			byPage[ page ] = byPage[ page ] ?? {};
			byPage[ page ][ id ] = values[ id ];
		} );

		for ( const [ page, pageValues ] of Object.entries( byPage ) ) {
			const response = await send< FieldElement[] >(
				`/settings/${ page }`,
				'PUT',
				{ values: pageValues }
			);

			setSchema( response );
		}
	};

	const handleContinue = async () => {
		setBusy( 'continue' );

		try {
			await saveStep();

			if ( current === STEPS.length - 2 ) {
				await complete();
			}

			setCurrent( current + 1 );
		} catch ( error ) {
			const fieldErrors = ( error as ApiError )?.data?.errors;

			if ( fieldErrors ) {
				const next: Record< string, string > = {};

				Object.entries( fieldErrors ).forEach( ( [ id, messages ] ) => {
					next[ id ] = ( messages as string[] )[ 0 ];
				} );

				setErrors( next );
			} else {
				toast.error(
					errorMessage(
						error,
						__( 'The step could not be saved.', 'flyaffiliate' )
					)
				);
			}
		} finally {
			setBusy( null );
		}
	};

	// Skipping does not finish the wizard: Settings keeps offering it until
	// the steps are actually completed.
	const handleSkip = () => {
		setBusy( 'skip' );
		navigate( '/' );
	};

	const renderNumber = ( id: string, className = '' ) => {
		const field = fields[ id ];

		if ( ! field ) {
			return null;
		}

		const inputId = `flyaffiliate-setup-${ id }`;

		return (
			<Field className={ className } data-invalid={ !! errors[ id ] }>
				<FieldLabel htmlFor={ inputId }>
					{ field.title }
					{ field.postfix && (
						<span className="ml-1 font-normal text-muted-foreground">
							({ field.postfix })
						</span>
					) }
				</FieldLabel>
				<Input
					id={ inputId }
					type="number"
					min={ 0 }
					step="any"
					value={ String( values[ id ] ?? '' ) }
					aria-invalid={ !! errors[ id ] }
					onChange={ ( e ) => setValue( id, e.target.value ) }
				/>
				{ errors[ id ] ? (
					<FieldError>{ errors[ id ] }</FieldError>
				) : (
					field.description && (
						<FieldDescription>
							{ field.description }
						</FieldDescription>
					)
				) }
			</Field>
		);
	};

	const renderSelect = ( id: string, className = '' ) => {
		const field = fields[ id ];

		if ( ! field ) {
			return null;
		}

		const options =
			(
				field as FieldElement & {
					options?: { value: string; label: string }[];
				}
			 ).options ?? [];
		const inputId = `flyaffiliate-setup-${ id }`;
		const selected = String( values[ id ] ?? '' );

		return (
			<Field className={ className } data-invalid={ !! errors[ id ] }>
				<FieldLabel htmlFor={ inputId }>{ field.title }</FieldLabel>
				<Select
					value={ selected }
					onValueChange={ ( value ) =>
						setValue( id, String( value ?? '' ) )
					}
				>
					<SelectTrigger
						id={ inputId }
						aria-invalid={ !! errors[ id ] }
					>
						<SelectValue>
							{ options.find(
								( option ) => option.value === selected
							)?.label ?? selected }
						</SelectValue>
					</SelectTrigger>
					<SelectContent>
						{ options.map( ( option ) => (
							<SelectItem
								key={ option.value }
								value={ option.value }
							>
								{ option.label }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				{ errors[ id ] ? (
					<FieldError>{ errors[ id ] }</FieldError>
				) : (
					field.description && (
						<FieldDescription>
							{ field.description }
						</FieldDescription>
					)
				) }
			</Field>
		);
	};

	const renderSwitch = ( id: string ) => {
		const field = fields[ id ];

		if ( ! field ) {
			return null;
		}

		return (
			<LabeledSwitch
				id={ `flyaffiliate-setup-${ id }` }
				label={ field.title }
				description={ field.description }
				checked={ values[ id ] === 'on' }
				onCheckedChange={ ( checked: boolean ) =>
					setValue( id, checked ? 'on' : 'off' )
				}
			/>
		);
	};

	const renderStep = () => {
		if ( loading ) {
			return (
				<div className="grid gap-5">
					<Skeleton className="h-16 w-full" />
					<Skeleton className="h-16 w-full" />
				</div>
			);
		}

		switch ( step.id ) {
			case 'commissions':
				return (
					<div className="grid gap-5">
						<div className="grid gap-5 sm:grid-cols-2">
							{ renderSelect( 'currency' ) }
							{ renderNumber( 'default_rate' ) }
						</div>
						<div className="grid gap-5 sm:grid-cols-2">
							{ renderNumber( 'max_rate' ) }
							{ renderNumber( 'hold_days' ) }
						</div>
					</div>
				);
			case 'tracking':
				return (
					<div className="grid gap-5">
						{ renderSwitch( 'woocommerce_enabled' ) }
						{ renderNumber( 'cookie_duration' ) }
					</div>
				);
			case 'email':
				return renderSwitch( 'activation_email_enabled' );
			case 'done':
				return (
					<div className="grid gap-4">
						{ [
							{
								label: __(
									'Affiliate registration',
									'flyaffiliate'
								),
								url: setup.pages.register,
								shortcode: '[flyaffiliate_register]',
							},
							{
								label: __(
									'Affiliate dashboard',
									'flyaffiliate'
								),
								url: setup.pages.dashboard,
								shortcode: '[flyaffiliate_dashboard]',
							},
						].map( ( page ) => (
							<div
								key={ page.shortcode }
								className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border px-4 py-3"
							>
								<div>
									<div className="font-medium text-foreground">
										{ page.url ? (
											<a
												href={ page.url }
												target="_blank"
												rel="noopener noreferrer"
												className="text-primary hover:underline"
											>
												{ page.label }
											</a>
										) : (
											page.label
										) }
									</div>
									<code className="text-xs text-muted-foreground">
										{ page.shortcode }
									</code>
								</div>
								<CopyButton
									value={ page.shortcode }
									label={ __(
										'Copy shortcode',
										'flyaffiliate'
									) }
								/>
							</div>
						) ) }
					</div>
				);
			default:
				return null;
		}
	};

	const isDone = step.id === 'done';

	return (
		<div className="mx-auto max-w-3xl" data-testid="flyaffiliate-setup">
			<h1 className="sr-only">{ __( 'Setup', 'flyaffiliate' ) }</h1>
			<Card>
				<CardHeader className="gap-6 border-b border-border">
					<Stepper current={ current } />
					<div>
						<h2 className="m-0 flex items-center gap-2 text-xl font-semibold text-foreground">
							{ isDone && (
								<PartyPopper
									className="size-5 text-primary"
									aria-hidden="true"
								/>
							) }
							{ step.title }
						</h2>
						<p className="mt-1 mb-0 text-sm text-muted-foreground">
							{ step.description }
						</p>
					</div>
				</CardHeader>
				<CardContent>{ renderStep() }</CardContent>
				<CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t border-border">
					{ isDone ? (
						<>
							<Button
								variant="ghost"
								className="-ml-4"
								render={ <Link to="/settings" /> }
							>
								{ __( 'Go to settings', 'flyaffiliate' ) }
							</Button>
							<Button
								render={ <Link to="/affiliates?new=1" /> }
								data-testid="flyaffiliate-setup-finish"
							>
								{ __(
									'Add your first affiliate',
									'flyaffiliate'
								) }
								<ArrowRight className="size-4" />
							</Button>
						</>
					) : (
						<>
							<Button
								variant="ghost"
								className="-ml-4"
								onClick={ handleSkip }
								disabled={ busy !== null }
								data-testid="flyaffiliate-setup-skip"
							>
								{ busy === 'skip' && (
									<Spinner className="size-4" />
								) }
								{ __( 'Skip setup', 'flyaffiliate' ) }
							</Button>
							<div className="flex items-center gap-5">
								<span className="text-xs text-muted-foreground">
									{ sprintf(
										/* translators: 1: current step number, 2: number of steps */
										__(
											'Step %1$d of %2$d',
											'flyaffiliate'
										),
										current + 1,
										STEPS.length
									) }
								</span>
								<Button
									onClick={ handleContinue }
									disabled={ busy !== null || loading }
									data-testid="flyaffiliate-setup-continue"
								>
									{ busy === 'continue' && (
										<Spinner className="size-4" />
									) }
									{ __( 'Continue', 'flyaffiliate' ) }
									<ArrowRight className="size-4" />
								</Button>
							</div>
						</>
					) }
				</CardFooter>
			</Card>
			{ ! isDone && (
				<p className="mt-4 mb-0 text-center text-xs text-muted-foreground">
					{ __(
						'Everything here can be changed later under Settings.',
						'flyaffiliate'
					) }{ ' ' }
					<a
						href={ urls.docs }
						target="_blank"
						rel="noopener noreferrer"
						className="text-primary hover:underline"
					>
						{ __( 'Read the documentation', 'flyaffiliate' ) }
					</a>
				</p>
			) }
		</div>
	);
}
