/**
 * The dashboard shell: the theme, a notice from a redirect, the tabs and the
 * date range every tab but Settings follows.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { CalendarDays, CheckCircle2, X } from 'lucide-react';
import {
	Button,
	DateRangePicker,
	Notice,
	NoticeTitle,
	Tabs,
	TabsContent,
	TabsList,
	TabsTrigger,
	ThemeProvider,
	Toaster,
	toast,
	type DateRange,
} from '@wedevs/plugin-ui';
import { theme } from './theme';
import { errorMessage, fetchOne } from '@/lib/api';
import { toYmd } from '@/lib/format';
import { getGlobals } from '@/lib/globals';
import type { AffiliateProfile, DateArgs } from './types';
import OverviewPage from './pages/Overview';
import SettingsPage from './pages/Settings';
import CommissionsTable from './tables/CommissionsTable';
import VisitsTable from './tables/VisitsTable';
import PayoutsTable from './tables/PayoutsTable';

const TABS = [ 'overview', 'commissions', 'visits', 'payouts', 'settings' ];

const TAB_TRIGGER = 'px-4 data-active:text-foreground';

/** The tabs inside the 44px row are 36px tall; the date picker and its clear button match them. */
const CONTROL_HEIGHT = 'h-9';

/** How long a new range waits before the lists refetch. */
const RANGE_DEBOUNCE_MS = 300;

/**
 * The range as the REST routes take it.
 *
 * @param {DateRange|undefined} range The picked range.
 * @return {DateArgs} `after` and `before`, each only when set.
 */
function toDateArgs( range: DateRange | undefined ): DateArgs {
	const from = range?.from;
	const to = range?.to ?? range?.from;

	return {
		...( from ? { after: toYmd( from ) } : {} ),
		...( to ? { before: toYmd( to ) } : {} ),
	};
}

export default function App() {
	const { dashboard } = getGlobals();
	const [ profile, setProfile ] = useState< AffiliateProfile | null >( null );
	const [ range, setRange ] = useState< DateRange | undefined >();
	const [ dates, setDates ] = useState< DateArgs >( {} );
	const [ tab, setTab ] = useState(
		TABS.includes( dashboard.tab ) ? dashboard.tab : 'overview'
	);

	// The picker only reports a range once Apply is pressed; the debounce keeps
	// a quick re-pick or a clear from firing a round of requests each.
	useEffect( () => {
		const timer = window.setTimeout(
			() => setDates( toDateArgs( range ) ),
			RANGE_DEBOUNCE_MS
		);

		return () => window.clearTimeout( timer );
	}, [ range ] );

	useEffect( () => {
		let cancelled = false;

		fetchOne< AffiliateProfile >( addQueryArgs( '/me', dates ) )
			.then( ( next ) => {
				if ( ! cancelled ) {
					setProfile( next );
				}
			} )
			.catch( ( error ) =>
				toast.error(
					errorMessage(
						error,
						__(
							'Your dashboard could not be loaded.',
							'flyaffiliate'
						)
					)
				)
			);

		return () => {
			cancelled = true;
		};
	}, [ dates ] );

	// Keep `?tab=` in step so a reload or a shared link lands on the same tab.
	const selectTab = ( next: string ) => {
		setTab( next );

		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', next );
		[ 'flyaffiliate_saved', 'flyaffiliate_activated', 'tab_page' ].forEach(
			( key ) => url.searchParams.delete( key )
		);
		window.history.replaceState( null, '', url.toString() );
	};

	return (
		<ThemeProvider pluginId="flyaffiliate" tokens={ theme }>
			<div
				className="flex flex-col gap-6"
				data-testid="flyaffiliate-dashboard"
			>
				{ dashboard.notice === 'activated' && (
					<Notice variant="success">
						<CheckCircle2 className="size-5" aria-hidden="true" />
						<NoticeTitle>
							{ __(
								'Your affiliate account is active. Welcome aboard.',
								'flyaffiliate'
							) }
						</NoticeTitle>
					</Notice>
				) }

				<Tabs
					value={ tab }
					onValueChange={ ( value ) => selectTab( String( value ) ) }
					className="gap-6"
				>
					<div className="flex flex-wrap items-center justify-between gap-3">
						<TabsList className="h-11 gap-2 bg-muted p-1">
							<TabsTrigger
								value="overview"
								className={ TAB_TRIGGER }
							>
								{ __( 'Overview', 'flyaffiliate' ) }
							</TabsTrigger>
							<TabsTrigger
								value="commissions"
								className={ TAB_TRIGGER }
							>
								{ __( 'Commissions', 'flyaffiliate' ) }
							</TabsTrigger>
							<TabsTrigger
								value="visits"
								className={ TAB_TRIGGER }
							>
								{ __( 'Visits', 'flyaffiliate' ) }
							</TabsTrigger>
							<TabsTrigger
								value="payouts"
								className={ TAB_TRIGGER }
							>
								{ __( 'Payouts', 'flyaffiliate' ) }
							</TabsTrigger>
							<TabsTrigger
								value="settings"
								className={ TAB_TRIGGER }
							>
								{ __( 'Settings', 'flyaffiliate' ) }
							</TabsTrigger>
						</TabsList>

						{ tab !== 'settings' && (
							<div
								className="flex items-center gap-2"
								data-testid="flyaffiliate-date-range"
							>
								<DateRangePicker
									mode="range"
									value={ range }
									onChange={ setRange }
									align="end"
									placeholder={ __(
										'All time',
										'flyaffiliate'
									) }
									confirmLabel={ __(
										'Apply',
										'flyaffiliate'
									) }
									cancelLabel={ __(
										'Cancel',
										'flyaffiliate'
									) }
									trigger={ ( { value, open } ) => (
										<Button
											variant="outline"
											className={ `${ CONTROL_HEIGHT } gap-2 px-4 font-normal` }
											aria-expanded={ open }
											aria-label={ __(
												'Pick a date range',
												'flyaffiliate'
											) }
										>
											<CalendarDays
												className="size-4 text-muted-foreground"
												aria-hidden="true"
											/>
											{ value ??
												__(
													'All time',
													'flyaffiliate'
												) }
										</Button>
									) }
								/>
								{ range?.from && (
									<Button
										variant="outline"
										size="icon"
										className={ `${ CONTROL_HEIGHT } w-9` }
										onClick={ () => setRange( undefined ) }
										aria-label={ __(
											'Show all time',
											'flyaffiliate'
										) }
									>
										<X
											className="size-4"
											aria-hidden="true"
										/>
									</Button>
								) }
							</div>
						) }
					</div>
					<TabsContent value="overview">
						<OverviewPage
							profile={ profile }
							hasRange={ Boolean( dates.after ) }
						/>
					</TabsContent>
					<TabsContent value="commissions">
						<CommissionsTable dates={ dates } />
					</TabsContent>
					<TabsContent value="visits">
						<VisitsTable dates={ dates } />
					</TabsContent>
					<TabsContent value="payouts">
						<PayoutsTable dates={ dates } />
					</TabsContent>
					<TabsContent value="settings">
						<SettingsPage
							profile={ profile }
							onSaved={ setProfile }
						/>
					</TabsContent>
				</Tabs>
			</div>
			<Toaster richColors position="bottom-right" />
		</ThemeProvider>
	);
}
