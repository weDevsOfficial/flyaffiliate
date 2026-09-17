/**
 * Dashboard: the programme at a glance — money, performance, the products
 * and affiliates doing the work, and what happened last — over a date range.
 * Laid out the way Dokan's dashboard is: a heading row, then the tiles on the
 * page itself; the chart in its own card; the lists as DataViews; and the
 * prototype's ranked sidebar.
 */
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { dateI18n, getSettings } from '@wordpress/date';
import { Link } from 'react-router-dom';
import {
	ArrowRight,
	BadgePercent,
	CalendarDays,
	CircleDollarSign,
	Coins,
	ExternalLink,
	Info,
	MousePointerClick,
	Receipt,
	Scale,
	UserRound,
	Users,
	Wallet,
	X,
} from 'lucide-react';
import {
	Button,
	Card,
	ChartContainer,
	ChartTooltip,
	ChartTooltipContent,
	DataViews,
	DateRangePicker,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	Tooltip,
	TooltipContent,
	TooltipTrigger,
	recharts,
	toast,
	type DataViewField,
	type DataViewState,
	type DateRange,
} from '@wedevs/plugin-ui';
import DateTime from '@/components/DateTime';
import EmptyState from '@/components/EmptyState';
import Money from '@/components/Money';
import PageHeader from '@/components/PageHeader';
import StatCard, { StatCardSkeleton } from '@/components/StatCard';
import TruncatedLine from '@/components/TruncatedLine';
import { errorMessage, fetchOne, send } from '@/lib/api';
import { formatDate, formatMoney, toYmd } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import type { DashboardStats } from '@/lib/types';

const { Area, AreaChart, CartesianGrid, XAxis } = recharts;

type Preset = '7' | '30' | 'month' | 'year' | 'all' | 'custom';
type Range = { after?: string; before?: string };
type TopProduct = DashboardStats[ 'top_products' ][ number ];
type RecentVisit = DashboardStats[ 'recent_visits' ][ number ];

/**
 * The `after`/`before` days a preset stands for.
 *
 * @param {Preset}              preset The preset.
 * @param {DateRange|undefined} custom The picked range, for the custom preset.
 * @return {Range} Query arguments, empty for all time.
 */
function presetRange( preset: Preset, custom?: DateRange ): Range {
	const now = new Date();
	const start = new Date( now );

	switch ( preset ) {
		case '7':
			start.setDate( now.getDate() - 6 );
			break;
		case '30':
			start.setDate( now.getDate() - 29 );
			break;
		case 'month':
			start.setDate( 1 );
			break;
		case 'year':
			start.setMonth( 0, 1 );
			break;
		case 'custom':
			if ( ! custom?.from ) {
				return {};
			}
			return {
				after: toYmd( custom.from ),
				before: toYmd( custom.to ?? custom.from ),
			};
		default:
			return {};
	}

	return { after: toYmd( start ), before: toYmd( now ) };
}

/**
 * A picked range as the select shows it, in the site's date format
 * (Settings → General), so it reads like every other date in the admin.
 *
 * @param {DateRange|undefined} range The picked range.
 * @return {string} "September 1, 2026 – September 16, 2026", or an empty string.
 */
function rangeLabel( range?: DateRange ): string {
	if ( ! range?.from ) {
		return '';
	}

	const format = getSettings().formats.date;
	const from = dateI18n( format, range.from );
	const to = dateI18n( format, range.to ?? range.from );

	return from === to ? from : `${ from } – ${ to }`;
}

const PRODUCTS_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	fields: [ 'commissions', 'revenue' ],
	titleField: 'name',
	layout: {
		styles: {
			name: { width: '48%' },
			commissions: { width: '32%' },
			revenue: { width: '20%' },
		},
	},
};

const VISITS_VIEW: DataViewState = {
	type: 'table',
	page: 1,
	perPage: 10,
	search: '',
	fields: [ 'affiliate', 'created_at', 'converted' ],
	titleField: 'url',
	layout: {
		styles: {
			url: { width: '34%' },
			affiliate: { width: '24%' },
			created_at: { width: '22%' },
			converted: { width: '20%' },
		},
	},
};

/** How many characters of a sidebar line show before the tooltip takes over. */
const LINE_MAX = 28;

/**
 * Cut a line short the way the lists cut a long cell.
 *
 * @param {string} text The whole line.
 * @param {number} max  How many characters may show.
 * @return {string} The line, with an ellipsis when it was cut.
 */
function cutTo( text: string, max: number ): string {
	return text.length <= max
		? text
		: `${ text.slice( 0, max - 1 ).trimEnd() }…`;
}

function ViewLink( { to, label }: { to: string; label: string } ) {
	return (
		<Link
			to={ to }
			className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
		>
			{ label }
			<ArrowRight className="size-3.5" aria-hidden="true" />
		</Link>
	);
}

// A section heading row, as on Dokan's dashboard: the title, an optional
// info icon, and a link on the right. The content sits on the page itself.
function SectionHeading( {
	title,
	tooltip,
	action,
}: {
	title: string;
	tooltip?: string;
	action?: React.ReactNode;
} ) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-3">
			<h3 className="m-0 flex items-center gap-1.5 text-base font-semibold text-foreground">
				{ title }
				{ tooltip && (
					<Tooltip>
						<TooltipTrigger
							render={
								<button
									type="button"
									className="inline-flex cursor-default text-muted-foreground"
									aria-label={ tooltip }
								/>
							}
						>
							<Info className="size-3.5" aria-hidden="true" />
						</TooltipTrigger>
						<TooltipContent className="max-w-xs">
							{ tooltip }
						</TooltipContent>
					</Tooltip>
				) }
			</h3>
			{ action }
		</div>
	);
}

function Section( {
	title,
	tooltip,
	action,
	children,
}: {
	title: string;
	tooltip?: string;
	action?: React.ReactNode;
	children: React.ReactNode;
} ) {
	return (
		<section className="flex min-w-0 flex-col gap-4">
			<SectionHeading
				title={ title }
				tooltip={ tooltip }
				action={ action }
			/>
			{ children }
		</section>
	);
}

function TileGrid( {
	loading,
	children,
}: {
	loading: boolean;
	children: React.ReactNode;
} ) {
	return (
		<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
			{ loading ? (
				<>
					<StatCardSkeleton />
					<StatCardSkeleton />
					<StatCardSkeleton />
					<StatCardSkeleton />
				</>
			) : (
				children
			) }
		</div>
	);
}

// A sidebar card, as in the prototype: the title and its link in the card's
// own header, the rows under it divided by a rule.
function SideCard( {
	title,
	action,
	children,
}: {
	title: string;
	action?: React.ReactNode;
	children: React.ReactNode;
} ) {
	return (
		<Card className="gap-0 overflow-hidden rounded-md border border-border py-0 shadow ring-0">
			<div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
				<h3 className="m-0 text-base font-semibold text-foreground">
					{ title }
				</h3>
				{ action }
			</div>
			{ children }
		</Card>
	);
}

export default function DashboardPage() {
	const { statuses } = getGlobals();
	const [ preset, setPreset ] = useState< Preset >( '30' );
	const [ custom, setCustom ] = useState< DateRange | undefined >();
	const [ stats, setStats ] = useState< DashboardStats | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ dismissed, setDismissed ] = useState( false );
	const [ productsView, setProductsView ] = useState( PRODUCTS_VIEW );
	const [ visitsView, setVisitsView ] = useState( VISITS_VIEW );
	const range = useMemo(
		() => presetRange( preset, custom ),
		[ preset, custom ]
	);

	useEffect( () => {
		let cancelled = false;

		setLoading( true );
		fetchOne< DashboardStats >( addQueryArgs( '/dashboard', range ) )
			.then( ( next ) => {
				if ( ! cancelled ) {
					setStats( next );
				}
			} )
			.catch( ( error ) =>
				toast.error(
					errorMessage(
						error,
						__(
							'The dashboard could not be loaded.',
							'flyaffiliate'
						)
					)
				)
			)
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ range ] );

	/** Closing the notice quiets it for two days, unless someone new applies. */
	const dismissNotice = () => {
		setDismissed( true );
		send( '/dashboard/pending-notice', 'POST' ).catch( () => {
			// The notice is already gone for this visit; nothing to report.
		} );
	};

	const presets: { value: Preset; label: string }[] = [
		{ value: '7', label: __( 'Last 7 days', 'flyaffiliate' ) },
		{ value: '30', label: __( 'Last 30 days', 'flyaffiliate' ) },
		{ value: 'month', label: __( 'This month', 'flyaffiliate' ) },
		{ value: 'year', label: __( 'This year', 'flyaffiliate' ) },
		{ value: 'all', label: __( 'All time', 'flyaffiliate' ) },
	];
	const customLabel = rangeLabel( custom );
	if ( customLabel ) {
		presets.push( { value: 'custom', label: customLabel } );
	}

	// Two hues of their own, the way Dokan's chart tells its series apart.
	const chartConfig = {
		visits: {
			label: __( 'Visits', 'flyaffiliate' ),
			color: '#7047eb',
		},
		converted: {
			label: __( 'Converted', 'flyaffiliate' ),
			color: '#12b76a',
		},
	};

	const products = stats?.top_products ?? [];
	const visits = stats?.recent_visits ?? [];
	const skeleton = loading || ! stats;

	const productFields: DataViewField< TopProduct >[] = [
		{
			id: 'name',
			label: __( 'Product', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) =>
				item.url ? (
					<TruncatedLine tooltip={ item.name }>
						<a
							href={ item.url }
							target="_blank"
							rel="noreferrer"
							className="inline-flex max-w-full items-center gap-1 align-bottom font-medium text-primary hover:underline"
						>
							<span className="truncate">{ item.name }</span>
							<ExternalLink
								className="size-3 shrink-0"
								aria-hidden="true"
							/>
						</a>
					</TruncatedLine>
				) : (
					<span className="text-muted-foreground">
						{ item.name ||
							sprintf(
								/* translators: %d: product id */
								__( 'Product #%d', 'flyaffiliate' ),
								item.product_id
							) }
					</span>
				),
		},
		{
			id: 'commissions',
			label: __( 'Commissions', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => item.commissions,
		},
		{
			id: 'revenue',
			label: __( 'Revenue', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Money amount={ item.revenue } className="font-semibold" />
			),
		},
	];

	const visitFields: DataViewField< RecentVisit >[] = [
		{
			id: 'url',
			label: __( 'Page', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<TruncatedLine
					tooltip={ item.url }
					className="cursor-default font-mono text-xs"
				>
					{ item.url }
				</TruncatedLine>
			),
		},
		{
			id: 'affiliate',
			label: __( 'Affiliate', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<Link
					to={ `/affiliates/${ item.affiliate_id }` }
					className="font-medium text-primary hover:underline"
				>
					{ item.affiliate_name || `#${ item.affiliate_id }` }
				</Link>
			),
		},
		{
			id: 'created_at',
			label: __( 'Date', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => <DateTime value={ item.created_at } />,
		},
		{
			id: 'converted',
			label: __( 'Result', 'flyaffiliate' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span
					className={
						item.converted
							? 'font-medium text-foreground'
							: 'text-muted-foreground'
					}
				>
					{ item.converted
						? __( 'Converted', 'flyaffiliate' )
						: __( 'Not converted', 'flyaffiliate' ) }
				</span>
			),
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'flyaffiliate' ) }
				description={ __(
					'How the affiliate programme is doing.',
					'flyaffiliate'
				) }
				actions={
					<div className="flex items-center gap-2">
						<Select
							value={ preset }
							onValueChange={ ( value ) => {
								const next = ( value ?? '30' ) as Preset;
								setPreset( next );
								if ( next !== 'custom' ) {
									setCustom( undefined );
								}
							} }
						>
							<SelectTrigger
								className="h-9 w-auto min-w-44 bg-background"
								aria-label={ __(
									'Date range',
									'flyaffiliate'
								) }
								data-testid="flyaffiliate-dashboard-range"
							>
								<SelectValue className="overflow-visible">
									{
										presets.find(
											( option ) =>
												option.value === preset
										)?.label
									}
								</SelectValue>
							</SelectTrigger>
							<SelectContent>
								{ presets.map( ( option ) => (
									<SelectItem
										key={ option.value }
										value={ option.value }
									>
										{ option.label }
									</SelectItem>
								) ) }
							</SelectContent>
						</Select>
						<DateRangePicker
							mode="range"
							value={ custom }
							onChange={ ( next ) => {
								setCustom( next );
								setPreset( next?.from ? 'custom' : '30' );
							} }
							align="end"
							placeholder={ __( 'Pick dates', 'flyaffiliate' ) }
							confirmLabel={ __( 'Apply', 'flyaffiliate' ) }
							cancelLabel={ __( 'Cancel', 'flyaffiliate' ) }
							trigger={ ( { open } ) => (
								<Button
									variant="outline"
									size="icon"
									className="size-9 shrink-0 border-input bg-background text-foreground shadow-xs hover:border-primary hover:bg-primary hover:text-primary-foreground aria-expanded:border-primary aria-expanded:bg-primary aria-expanded:text-primary-foreground"
									aria-expanded={ open }
									aria-label={ __(
										'Pick a date range',
										'flyaffiliate'
									) }
									data-testid="flyaffiliate-dashboard-calendar"
								>
									<CalendarDays
										className="size-4"
										aria-hidden="true"
									/>
								</Button>
							) }
						/>
					</div>
				}
			/>

			{ stats && stats.affiliates.pending_notice && ! dismissed && (
				<div
					role="status"
					className="mb-6 flex items-center justify-between gap-3 rounded-md border border-primary/40 border-l-4 border-l-primary bg-background px-4 py-3 text-sm text-foreground shadow-sm"
					data-testid="flyaffiliate-dashboard-pending"
				>
					<p className="m-0">
						{ createInterpolateElement(
							sprintf(
								/* translators: %d: number of affiliates waiting for review */
								_n(
									'You have <strong>%d affiliate</strong> pending review.',
									'You have <strong>%d affiliates</strong> pending review.',
									stats.affiliates.pending,
									'flyaffiliate'
								),
								stats.affiliates.pending
							),
							{ strong: <strong className="font-bold" /> }
						) }{ ' ' }
						<Link
							to="/affiliates?status=pending"
							className="inline-flex items-center gap-1 font-medium text-primary underline underline-offset-2 hover:no-underline"
						>
							{ __( 'Review now', 'flyaffiliate' ) }
							<ArrowRight
								className="size-3.5"
								aria-hidden="true"
							/>
						</Link>
					</p>
					<button
						type="button"
						onClick={ dismissNotice }
						className="inline-flex shrink-0 cursor-pointer text-muted-foreground hover:text-foreground"
						aria-label={ __( 'Dismiss', 'flyaffiliate' ) }
					>
						<X className="size-4" aria-hidden="true" />
					</button>
				</div>
			) }

			<div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
				<div className="flex min-w-0 flex-col gap-8">
					<Section
						title={ __( 'Earnings & payouts', 'flyaffiliate' ) }
						tooltip={ __(
							'Money in the selected dates: what the referred sales were worth and what the commissions on them cost.',
							'flyaffiliate'
						) }
						action={
							<ViewLink
								to="/payouts"
								label={ __( 'All payouts', 'flyaffiliate' ) }
							/>
						}
					>
						<TileGrid loading={ skeleton }>
							{ stats && (
								<>
									<StatCard
										icon={ CircleDollarSign }
										label={ __(
											'Referral revenue',
											'flyaffiliate'
										) }
										value={ formatMoney(
											stats.earnings.referral_revenue
										) }
										tooltip={ __(
											'What the referred order items were worth before commissions, in the selected dates.',
											'flyaffiliate'
										) }
									/>
									<StatCard
										icon={ Scale }
										label={ __(
											'Net revenue',
											'flyaffiliate'
										) }
										value={ formatMoney(
											stats.earnings.net_revenue
										) }
										tooltip={ __(
											'Referral revenue minus the commissions it earned.',
											'flyaffiliate'
										) }
									/>
									<StatCard
										icon={ Wallet }
										label={ __(
											'Total paid',
											'flyaffiliate'
										) }
										value={ formatMoney(
											stats.earnings.paid
										) }
										tooltip={ __(
											'Commissions whose payment has been marked paid.',
											'flyaffiliate'
										) }
									/>
									<StatCard
										icon={ Coins }
										label={ __(
											'Total unpaid',
											'flyaffiliate'
										) }
										value={ formatMoney(
											stats.earnings.unpaid
										) }
										tooltip={ sprintf(
											/* translators: %s: amount still pending */
											__(
												'Approved commissions waiting for a payout. Another %s is still inside its hold period.',
												'flyaffiliate'
											),
											formatMoney(
												stats.earnings.pending
											)
										) }
									/>
								</>
							) }
						</TileGrid>
					</Section>

					<Section
						title={ __(
							'Performance & conversions',
							'flyaffiliate'
						) }
						tooltip={ __(
							'How the referral links performed in the selected dates.',
							'flyaffiliate'
						) }
						action={
							<ViewLink
								to="/visits"
								label={ __( 'All visits', 'flyaffiliate' ) }
							/>
						}
					>
						<TileGrid loading={ skeleton }>
							{ stats && (
								<>
									<StatCard
										icon={ Receipt }
										label={ __(
											'Commissions',
											'flyaffiliate'
										) }
										value={ stats.performance.commissions }
										tooltip={ __(
											'Order items that earned a commission in the selected dates. Rejected ones are not counted.',
											'flyaffiliate'
										) }
									/>
									<StatCard
										icon={ MousePointerClick }
										label={ __( 'Visits', 'flyaffiliate' ) }
										value={ stats.performance.visits }
										tooltip={ __(
											'Clicks on referral links in the selected dates.',
											'flyaffiliate'
										) }
									/>
									<StatCard
										icon={ BadgePercent }
										label={ __(
											'Conversion rate',
											'flyaffiliate'
										) }
										value={ `${ stats.performance.conversion_rate }%` }
										tooltip={ sprintf(
											/* translators: 1: converted visits, 2: all visits */
											__(
												'%1$d of %2$d visits ended in an order.',
												'flyaffiliate'
											),
											stats.performance.converted,
											stats.performance.visits
										) }
									/>
									<StatCard
										icon={ Users }
										label={ __(
											'Affiliates',
											'flyaffiliate'
										) }
										value={ stats.affiliates.total }
										tooltip={ sprintf(
											/* translators: %d: number of active affiliates */
											__(
												'Everyone with an affiliate account, whatever their status. %d of them are active.',
												'flyaffiliate'
											),
											stats.affiliates.active
										) }
									/>
								</>
							) }
						</TileGrid>
					</Section>

					<Card
						className="gap-0 rounded-md border border-border py-0 shadow ring-0"
						data-testid="flyaffiliate-dashboard-chart"
					>
						<div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
							<h3 className="m-0 text-base font-semibold text-foreground">
								{ __( 'Visits per day', 'flyaffiliate' ) }
							</h3>
							<ul className="m-0 flex list-none items-center gap-4 p-0 text-sm text-muted-foreground">
								{ Object.entries( chartConfig ).map(
									( [ key, series ] ) => (
										<li
											key={ key }
											className="flex items-center gap-1.5"
										>
											<span
												className="size-3.5 rounded"
												style={ {
													background: series.color,
												} }
												aria-hidden="true"
											/>
											{ series.label }
										</li>
									)
								) }
							</ul>
						</div>
						<div className="px-5 py-4">
							{ stats && stats.trend.length > 0 ? (
								<ChartContainer
									config={ chartConfig }
									className="h-64 w-full"
								>
									<AreaChart
										data={ stats.trend }
										margin={ {
											left: 4,
											right: 4,
											top: 8,
											bottom: 0,
										} }
									>
										<CartesianGrid
											vertical={ false }
											strokeDasharray="3 3"
											stroke="var(--border)"
										/>
										<XAxis
											dataKey="date"
											tickLine={ false }
											axisLine={ false }
											tickMargin={ 8 }
											minTickGap={ 24 }
											tickFormatter={ ( value: string ) =>
												value.slice( 5 )
											}
										/>
										<ChartTooltip
											cursor={ false }
											content={ <ChartTooltipContent /> }
										/>
										<Area
											dataKey="visits"
											type="monotone"
											fill="var(--color-visits)"
											fillOpacity={ 0.15 }
											stroke="var(--color-visits)"
										/>
										<Area
											dataKey="converted"
											type="monotone"
											fill="var(--color-converted)"
											fillOpacity={ 0.25 }
											stroke="var(--color-converted)"
										/>
									</AreaChart>
								</ChartContainer>
							) : (
								<div className="flex h-64 items-center justify-center">
									{ loading ? (
										<Skeleton className="h-56 w-full" />
									) : (
										<EmptyState
											compact
											icon={ MousePointerClick }
											title={ __(
												'No visits in these dates',
												'flyaffiliate'
											) }
											description={ __(
												'Clicks on referral links show up here, day by day.',
												'flyaffiliate'
											) }
										/>
									) }
								</div>
							) }
						</div>
					</Card>

					<div className="grid gap-8 xl:grid-cols-2">
						<Section
							title={ __(
								'Highest converting products',
								'flyaffiliate'
							) }
							action={
								<ViewLink
									to="/commissions"
									label={ __( 'All', 'flyaffiliate' ) }
								/>
							}
						>
							<div
								className="flyaffiliate-dashboard-list"
								data-testid="flyaffiliate-dashboard-products"
							>
								<DataViews< TopProduct >
									namespace="flyaffiliate-dashboard-products"
									data={ products }
									fields={ productFields }
									view={ productsView }
									onChangeView={ setProductsView }
									isLoading={ skeleton }
									paginationInfo={ {
										totalItems: products.length,
										totalPages: 1,
									} }
									defaultLayouts={ { table: {} } }
									getItemId={ ( item ) =>
										String( item.product_id )
									}
									search={ false }
									emptyTitle={ __(
										'No referred sales yet',
										'flyaffiliate'
									) }
									emptyDescription={ __(
										'The products that earn the most commissions appear here.',
										'flyaffiliate'
									) }
									tabs={ { items: [], headerContent: [] } }
								/>
							</div>
						</Section>

						<Section
							title={ __( 'Recent visits', 'flyaffiliate' ) }
							action={
								<ViewLink
									to="/visits"
									label={ __( 'All', 'flyaffiliate' ) }
								/>
							}
						>
							<div
								className="flyaffiliate-dashboard-list"
								data-testid="flyaffiliate-dashboard-visits"
							>
								<DataViews< RecentVisit >
									namespace="flyaffiliate-dashboard-visits"
									data={ visits }
									fields={ visitFields }
									view={ visitsView }
									onChangeView={ setVisitsView }
									isLoading={ skeleton }
									paginationInfo={ {
										totalItems: visits.length,
										totalPages: 1,
									} }
									defaultLayouts={ { table: {} } }
									getItemId={ ( item ) => String( item.id ) }
									search={ false }
									emptyTitle={ __(
										'No visits yet',
										'flyaffiliate'
									) }
									emptyDescription={ __(
										'Clicks on referral links appear here as they happen.',
										'flyaffiliate'
									) }
									tabs={ { items: [], headerContent: [] } }
								/>
							</div>
						</Section>
					</div>
				</div>

				<div className="flex min-w-0 flex-col gap-8">
					<SideCard
						title={ __(
							'Most valuable affiliates',
							'flyaffiliate'
						) }
						action={
							<ViewLink
								to="/affiliates"
								label={ __( 'All', 'flyaffiliate' ) }
							/>
						}
					>
						{ ( stats?.top_affiliates ?? [] ).length === 0 ? (
							<EmptyState
								compact
								className="px-5"
								icon={ UserRound }
								title={ __(
									'Nobody has earned yet',
									'flyaffiliate'
								) }
								description={ __(
									'The affiliates with the highest earnings appear here.',
									'flyaffiliate'
								) }
							/>
						) : (
							<ol
								className="flyaffiliate-sidebar-list m-0 flex list-none flex-col divide-y divide-border p-0"
								data-testid="flyaffiliate-dashboard-top-affiliates"
							>
								{ ( stats?.top_affiliates ?? [] ).map(
									( affiliate, index ) => (
										<li
											key={ affiliate.id }
											className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
										>
											<span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
												#{ index + 1 }
											</span>
											<div className="flex min-w-0 flex-1 flex-col gap-0.5">
												<Link
													to={ `/affiliates/${ affiliate.id }` }
													className="block truncate text-sm font-semibold leading-5 text-foreground hover:text-primary hover:underline"
												>
													{ affiliate.name ||
														`#${ affiliate.id }` }
												</Link>
												<div className="text-xs leading-4 text-muted-foreground">
													{ sprintf(
														/* translators: 1: amount earned, 2: commission count, 3: visit count */
														__(
															'%1$s · %2$s · %3$s',
															'flyaffiliate'
														),
														formatMoney(
															affiliate.earned
														),
														sprintf(
															/* translators: %d: number of commissions */
															_n(
																'%d commission',
																'%d commissions',
																affiliate.commissions,
																'flyaffiliate'
															),
															affiliate.commissions
														),
														sprintf(
															/* translators: %d: number of visits */
															_n(
																'%d visit',
																'%d visits',
																affiliate.visits,
																'flyaffiliate'
															),
															affiliate.visits
														)
													) }
												</div>
											</div>
										</li>
									)
								) }
							</ol>
						) }
					</SideCard>

					<SideCard
						title={ __( 'Recent commissions', 'flyaffiliate' ) }
						action={
							<ViewLink
								to="/commissions"
								label={ __( 'All', 'flyaffiliate' ) }
							/>
						}
					>
						{ ( stats?.recent_commissions ?? [] ).length === 0 ? (
							<p className="m-0 px-5 py-3 text-sm text-muted-foreground">
								{ __(
									'No commissions in these dates.',
									'flyaffiliate'
								) }
							</p>
						) : (
							<ul
								className="flyaffiliate-sidebar-list m-0 flex list-none flex-col divide-y divide-border p-0"
								data-testid="flyaffiliate-dashboard-recent-commissions"
							>
								{ ( stats?.recent_commissions ?? [] ).map(
									( commission ) => {
										const reference =
											commission.order_id > 0
												? sprintf(
														/* translators: %d: order number */
														__(
															'Order #%d',
															'flyaffiliate'
														),
														commission.order_id
												  )
												: __(
														'Manual',
														'flyaffiliate'
												  );
										const status =
											statuses.commission[
												commission.status
											] ?? commission.status;
										const line = `${ reference } · ${ status } · ${ formatDate(
											commission.created_at
										) }`;
										const shown = cutTo( line, LINE_MAX );
										// The order keeps its link for as much
										// of it as the cut leaves standing.
										const linked = shown.slice(
											0,
											Math.min(
												reference.length,
												shown.length
											)
										);

										return (
											<li
												key={ commission.id }
												className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
											>
												<div className="flex min-w-0 flex-1 flex-col gap-1">
													<Link
														to={ `/affiliates/${ commission.affiliate_id }` }
														className="block truncate text-sm font-semibold leading-5 text-foreground hover:text-primary hover:underline"
													>
														{ commission.affiliate_name ||
															`#${ commission.affiliate_id }` }
													</Link>
													{ /* Cut at 28 characters, as the lists cut a long cell; the whole line is the tooltip. */ }
													<TruncatedLine
														className="cursor-default text-xs leading-4 text-muted-foreground"
														tooltip={ `${ reference } · ${ status } · ${ formatDate(
															commission.created_at,
															true
														) }` }
													>
														{ commission.order_url ? (
															<a
																href={
																	commission.order_url
																}
																className="font-medium text-primary hover:underline"
															>
																{ linked }
															</a>
														) : (
															linked
														) }
														{ shown.slice(
															linked.length
														) }
													</TruncatedLine>
												</div>
												<Money
													amount={ commission.amount }
													className="shrink-0 text-sm font-semibold leading-5 tabular-nums"
												/>
											</li>
										);
									}
								) }
							</ul>
						) }
					</SideCard>
				</div>
			</div>
		</>
	);
}
