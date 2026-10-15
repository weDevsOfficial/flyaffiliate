/**
 * Extensions, while FlyAffiliate Pro is not active: what Pro's extensions
 * add, as Dokan Lite's Modules page shows Dokan Pro's modules (ADR-0005).
 * The same banner, bar and cards as Pro's page, so nothing moves after the
 * upgrade, but the cards only preview: a switch or Upgrade opens the
 * upgrade dialog. With Pro running, Pro's own page takes this route.
 *
 * The cards come from PHP (`Admin\ProExtensions`), translated there.
 */
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	BadgePercent,
	ChevronDown,
	Crown,
	Landmark,
	LayoutGrid,
	Link2,
	List,
	Search,
	SearchX,
	Sparkles,
	X,
	type LucideIcon,
} from 'lucide-react';
import {
	Button,
	Card,
	DropdownMenu,
	DropdownMenuCheckboxItem,
	DropdownMenuContent,
	DropdownMenuGroup,
	DropdownMenuLabel,
	DropdownMenuTrigger,
	InputGroup,
	InputGroupAddon,
	InputGroupButton,
	InputGroupInput,
	cn,
} from '@wedevs/plugin-ui';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import { getGlobals, type ProExtensionsData } from '@/lib/globals';
import {
	dokanLogo,
	fluentCartLogo,
	payPalLogo,
	stripeLogo,
	sureCartLogo,
	wooCommerceLogo,
} from '@/assets/logos';
import PreviewCard, { categoryLabel, type ExtensionView } from './PreviewCard';
import UpgradeDialog from './UpgradeDialog';

/** Where the chosen layout is remembered, per browser, as on Pro's page. */
const VIEW_KEY = 'flyaffiliate-extensions-view';

export default function ExtensionsPage() {
	const data = getGlobals().proExtensions as ProExtensionsData;
	const all = data.extensions;
	const [ categories, setCategories ] = useState< string[] >( [] );
	const [ search, setSearch ] = useState( '' );
	const [ view, setView ] = useState< ExtensionView >( readView );
	const [ upgrading, setUpgrading ] = useState( false );

	const categoryOptions = useMemo( () => {
		const options: { value: string; label: string; count: number }[] = [];

		all.forEach( ( item ) => {
			const option = options.find(
				( entry ) => entry.value === item.category
			);

			if ( option ) {
				option.count++;
			} else {
				options.push( {
					value: item.category,
					label: categoryLabel( item.category ),
					count: 1,
				} );
			}
		} );

		return options;
	}, [ all ] );

	const visible = useMemo( () => {
		const term = search.trim().toLowerCase();

		return all.filter(
			( item ) =>
				( 0 === categories.length ||
					categories.includes( item.category ) ) &&
				( '' === term ||
					item.name.toLowerCase().includes( term ) ||
					item.description.toLowerCase().includes( term ) )
		);
	}, [ all, categories, search ] );

	const toggleCategory = ( value: string, checked: boolean ) =>
		setCategories( ( current ) =>
			checked
				? [ ...current, value ]
				: current.filter( ( item ) => item !== value )
		);

	const changeView = ( next: ExtensionView ) => {
		setView( next );

		try {
			window.localStorage.setItem( VIEW_KEY, next );
		} catch {
			// Storage blocked: the layout just is not remembered.
		}
	};

	return (
		<div data-testid="flyaffiliate-extensions">
			<PageHeader
				title={ __( 'Extensions', 'flyaffiliate' ) }
				description={ __(
					'Connect FlyAffiliate to your marketplace and the services that pay your affiliates.',
					'flyaffiliate'
				) }
			/>

			<Banner onUpgrade={ () => setUpgrading( true ) } />

			<div className="mb-5 flex flex-wrap items-center justify-between gap-4">
				<div className="flex min-h-9 items-center">
					<span className="border-b-2 border-primary pb-1 text-sm font-medium text-primary">
						{ sprintf(
							/* translators: %d: how many Pro extensions there are. */
							__( 'Pro Extensions (%d)', 'flyaffiliate' ),
							all.length
						) }
					</span>
				</div>

				<div className="flex flex-wrap items-center gap-3">
					<DropdownMenu>
						<DropdownMenuTrigger
							render={
								<Button
									variant="ghost"
									className="gap-1.5 text-muted-foreground hover:bg-card aria-expanded:bg-card"
								/>
							}
							data-testid="flyaffiliate-extensions-category"
						>
							{ __( 'Select', 'flyaffiliate' ) }{ ' ' }
							<span className="text-foreground">
								{ __( 'Category', 'flyaffiliate' ) }
							</span>
							<ChevronDown
								className="size-4"
								aria-hidden="true"
							/>
						</DropdownMenuTrigger>
						<DropdownMenuContent align="end" className="w-56">
							<DropdownMenuGroup>
								<DropdownMenuLabel>
									{ __( 'All categories', 'flyaffiliate' ) }
								</DropdownMenuLabel>
								{ categoryOptions.map( ( category ) => (
									<DropdownMenuCheckboxItem
										key={ category.value }
										checked={ categories.includes(
											category.value
										) }
										onCheckedChange={ ( checked ) =>
											toggleCategory(
												category.value,
												checked
											)
										}
									>
										{ category.label }
										<span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/10 px-1.5 text-xs font-medium text-primary tabular-nums">
											{ category.count }
										</span>
									</DropdownMenuCheckboxItem>
								) ) }
							</DropdownMenuGroup>
						</DropdownMenuContent>
					</DropdownMenu>

					<InputGroup className="w-64 bg-card">
						<InputGroupInput
							type="search"
							value={ search }
							onChange={ ( event ) =>
								setSearch( event.target.value )
							}
							placeholder={ __( 'Search…', 'flyaffiliate' ) }
							aria-label={ __(
								'Search extensions',
								'flyaffiliate'
							) }
							data-testid="flyaffiliate-extensions-search"
						/>
						<InputGroupAddon align="inline-end">
							{ search ? (
								<InputGroupButton
									size="icon-xs"
									onClick={ () => setSearch( '' ) }
									aria-label={ __(
										'Clear search',
										'flyaffiliate'
									) }
								>
									<X aria-hidden="true" />
								</InputGroupButton>
							) : (
								<Search aria-hidden="true" />
							) }
						</InputGroupAddon>
					</InputGroup>

					<div
						className="flex items-center gap-1"
						role="group"
						aria-label={ __( 'Layout', 'flyaffiliate' ) }
					>
						<ViewButton
							value="grid"
							current={ view }
							onView={ changeView }
							label={ __( 'Grid view', 'flyaffiliate' ) }
							icon={ <LayoutGrid aria-hidden="true" /> }
						/>
						<ViewButton
							value="list"
							current={ view }
							onView={ changeView }
							label={ __( 'List view', 'flyaffiliate' ) }
							icon={ <List aria-hidden="true" /> }
						/>
					</div>
				</div>
			</div>

			{ categories.length > 0 && (
				<div className="-mt-1 mb-5 flex flex-wrap items-center gap-2">
					{ categories.map( ( value ) => (
						<Button
							key={ value }
							variant="outline"
							size="xs"
							className="gap-1.5 bg-card"
							onClick={ () => toggleCategory( value, false ) }
							aria-label={ sprintf(
								/* translators: %s: a category, e.g. Integration. */
								__( 'Remove %s filter', 'flyaffiliate' ),
								categoryLabel( value )
							) }
						>
							{ categoryLabel( value ) }
							<X aria-hidden="true" />
						</Button>
					) ) }
					<Button
						variant="link"
						size="xs"
						onClick={ () => setCategories( [] ) }
					>
						{ __( 'Clear filter', 'flyaffiliate' ) }
					</Button>
				</div>
			) }

			{ 0 === visible.length ? (
				<EmptyState
					icon={ SearchX }
					title={ __( 'No extensions found', 'flyaffiliate' ) }
					description={ __(
						'Try another search or category.',
						'flyaffiliate'
					) }
					action={
						<Button
							variant="outline"
							onClick={ () => {
								setCategories( [] );
								setSearch( '' );
							} }
						>
							{ __( 'Reset filters', 'flyaffiliate' ) }
						</Button>
					}
					compact
				/>
			) : (
				<div
					className={
						'list' === view
							? 'flex flex-col gap-3'
							: 'grid grid-cols-[repeat(auto-fill,minmax(min(100%,28rem),1fr))] gap-5'
					}
				>
					{ visible.map( ( item ) => (
						<PreviewCard
							key={ item.id }
							extension={ item }
							view={ view }
							onUpgrade={ () => setUpgrading( true ) }
							onCategory={ ( category ) =>
								toggleCategory( category, true )
							}
						/>
					) ) }
				</div>
			) }

			<UpgradeDialog
				open={ upgrading }
				onOpenChange={ setUpgrading }
				data={ data }
			/>
		</div>
	);
}

/**
 * What extensions are for, with the marks of what they connect on the
 * right, as Pro's banner draws them, and the way to Pro.
 *
 * @param {Object}   props           The props.
 * @param {Function} props.onUpgrade Opens the upgrade dialog.
 * @return {JSX.Element} The banner.
 */
function Banner( { onUpgrade }: { onUpgrade: () => void } ) {
	return (
		<Card className="relative mb-7 gap-0 overflow-hidden rounded-xl border border-border p-0 py-0 shadow-xs ring-0">
			<div
				aria-hidden="true"
				className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-primary/10 blur-3xl"
			/>
			<div className="relative flex items-center justify-between gap-6 bg-linear-to-l from-primary/10 via-primary/[0.03] to-card px-8 py-7">
				<div className="max-w-md">
					<span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary">
						<Sparkles className="size-3.5" aria-hidden="true" />
						FlyAffiliate Pro
					</span>
					<h2 className="mt-3 text-2xl font-bold text-foreground">
						{ __( 'Grow your affiliate program', 'flyaffiliate' ) }
					</h2>
					<p className="mt-2 text-sm leading-relaxed text-muted-foreground">
						{ __(
							'Run affiliate programs on more platforms and pay affiliates the way they prefer. Pro brings every extension, one switch at a time.',
							'flyaffiliate'
						) }
					</p>
					<Button
						className="mt-5 gap-2"
						onClick={ onUpgrade }
						data-testid="flyaffiliate-extensions-upgrade-open"
					>
						<Crown className="size-4" aria-hidden="true" />
						{ __( 'Upgrade to Pro', 'flyaffiliate' ) }
					</Button>
				</div>
				<div
					aria-hidden="true"
					className="hidden flex-col items-center gap-2.5 md:flex"
				>
					{ BANNER_TILES.map( ( row, index ) => (
						<div key={ index } className="flex gap-2.5">
							{ row.map( ( tile ) => (
								<span
									key={ tile.name }
									title={ tile.name }
									className="flex size-11 items-center justify-center rounded-xl bg-card text-primary shadow-sm ring-1 ring-border/70"
								>
									{ tile.src ? (
										<img
											src={ tile.src }
											alt=""
											className="size-6"
										/>
									) : (
										tile.icon && (
											<tile.icon className="size-5" />
										)
									) }
								</span>
							) ) }
						</div>
					) ) }
				</div>
			</div>
		</Card>
	);
}

type BannerTile = {
	name: string;
	/** A brand's mark, or… */
	src?: string;
	/** …a FlyAffiliate feature's icon, in the brand colour. */
	icon?: LucideIcon;
};

/** The same tiles as Pro's banner: where sales come from, then how affiliates get paid. */
const BANNER_TILES: BannerTile[][] = [
	[
		{ name: 'WooCommerce', src: wooCommerceLogo },
		{ name: 'Dokan', src: dokanLogo },
		{ name: 'SureCart', src: sureCartLogo },
		{ name: 'FluentCart', src: fluentCartLogo },
		{ name: __( 'Referral links', 'flyaffiliate' ), icon: Link2 },
	],
	[
		{ name: 'PayPal', src: payPalLogo },
		{ name: 'Stripe', src: stripeLogo },
		{ name: __( 'Bank transfer', 'flyaffiliate' ), icon: Landmark },
		{ name: __( 'Commissions', 'flyaffiliate' ), icon: BadgePercent },
	],
];

function ViewButton( {
	value,
	current,
	onView,
	label,
	icon,
}: {
	value: ExtensionView;
	current: ExtensionView;
	onView: ( view: ExtensionView ) => void;
	label: string;
	icon: JSX.Element;
} ) {
	const pressed = value === current;

	return (
		<Button
			variant={ pressed ? 'outline' : 'ghost' }
			size="icon-sm"
			className={ cn(
				pressed
					? 'bg-card text-primary shadow-xs hover:bg-card'
					: 'text-muted-foreground hover:bg-card/60'
			) }
			onClick={ () => onView( value ) }
			aria-label={ label }
			aria-pressed={ pressed }
			data-testid={ `flyaffiliate-extensions-view-${ value }` }
		>
			{ icon }
		</Button>
	);
}

/**
 * The layout this browser chose last time.
 *
 * @return {ExtensionView} Grid unless list was chosen.
 */
function readView(): ExtensionView {
	try {
		return 'list' === window.localStorage.getItem( VIEW_KEY )
			? 'list'
			: 'grid';
	} catch {
		return 'grid';
	}
}
