/**
 * Settings: the flat schema from REST rendered by plugin-ui's <Settings>.
 *
 * Mirrors Dokan's admin settings page. The schema is fetched once, saved per
 * page or subpage, and the active page / subpage / tab is mirrored into the
 * URL so a link can open a specific section.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { Link, useBlocker, useSearchParams } from 'react-router-dom';
import {
	AlertDialog,
	AlertDialogAction,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
	Button,
	Settings,
	Spinner,
	toast,
	useSettings,
	type SettingsElement,
} from '@wedevs/plugin-ui';
import PageHeader from '@/components/PageHeader';
import { errorMessage, fetchOne, send, type ApiError } from '@/lib/api';
import { getGlobals } from '@/lib/globals';
import { BRAND_OUTLINE } from '@/lib/ui';

const PARAM_PAGE = 'page_id';
const PARAM_SUBPAGE = 'subpage_id';
const PARAM_TAB = 'tab_id';

/** The page's "the URL has been read" flag. */
type RestoredRef = { current: boolean };

/**
 * Binds the active subpage and tab to the URL.
 *
 * plugin-ui only exposes `initialPage` / `onNavigate` for the top level, so the
 * rest is driven through its context. Rendered inside the provider via
 * `renderSaveButton`, which is the one render prop mounted within it.
 *
 * That render prop is skipped entirely on a `hide_save` subpage (the affiliate
 * area), so this component unmounts and mounts again as the user moves around.
 * The "already restored" flag therefore belongs to the page, not to this
 * component: kept here, a remount would read the URL a second time and pull the
 * user back to the subpage they came from.
 *
 * @param {Object} props          Props.
 * @param {Object} props.restored Ref owned by the page: true once the URL has
 *                                been read.
 */
function UrlSync( { restored }: { restored: RestoredRef } ): null {
	const {
		activePage,
		activeSubpage,
		activeTab,
		setActiveSubpage,
		setActiveTab,
	} = useSettings();
	const [ searchParams, setSearchParams ] = useSearchParams();
	// Mirrors the ref so the write effect below runs as soon as reading is done,
	// and runs straight away on a remount, when reading is already behind us.
	const [ ready, setReady ] = useState( restored.current );

	useEffect( () => {
		if ( restored.current || ! activePage ) {
			return;
		}

		const urlSubpage = searchParams.get( PARAM_SUBPAGE );
		const urlTab = searchParams.get( PARAM_TAB );

		if ( urlSubpage && urlSubpage !== activeSubpage ) {
			setActiveSubpage( urlSubpage );
		}

		if ( urlTab && urlTab !== activeTab ) {
			setActiveTab( urlTab );
		}

		restored.current = true;
		setReady( true );
	}, [
		activePage,
		activeSubpage,
		activeTab,
		restored,
		searchParams,
		setActiveSubpage,
		setActiveTab,
	] );

	useEffect( () => {
		if ( ! ready ) {
			return;
		}

		// This page may be on its way out: a write that lands after the route
		// changed would replace the new entry and send the browser back here.
		if ( ! window.location.hash.startsWith( '#/settings' ) ) {
			return;
		}

		setSearchParams(
			( previous ) => {
				const next = new URLSearchParams( previous );

				if ( activeSubpage ) {
					next.set( PARAM_SUBPAGE, activeSubpage );
				} else {
					next.delete( PARAM_SUBPAGE );
				}

				if ( activeTab ) {
					next.set( PARAM_TAB, activeTab );
				} else {
					next.delete( PARAM_TAB );
				}

				return next;
			},
			{ replace: true }
		);
	}, [ activeSubpage, activeTab, ready, setSearchParams ] );

	return null;
}

export default function SettingsPage() {
	const [ searchParams, setSearchParams ] = useSearchParams();
	// Read the URL once per visit to this page; see UrlSync.
	const restored = useRef( false );
	const [ schema, setSchema ] = useState< SettingsElement[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	// plugin-ui guards its own sidebar and the browser unload; this guards the
	// app router. Pathname only: UrlSync rewrites the query on every subpage.
	const blocker = useBlocker(
		useCallback(
			( {
				currentLocation,
				nextLocation,
			}: {
				currentLocation: { pathname: string };
				nextLocation: { pathname: string };
			} ) => dirty && currentLocation.pathname !== nextLocation.pathname,
			[ dirty ]
		)
	);

	useEffect( () => {
		fetchOne< SettingsElement[] >( '/settings' )
			.then( ( response ) => setSchema( response ) )
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

	const handleSave = async (
		scopeId: string,
		_tree: Record< string, unknown >,
		flatValues: Record< string, unknown >
	) => {
		setSaving( true );

		try {
			const response = await send< SettingsElement[] >(
				`/settings/${ scopeId }`,
				'PUT',
				{ values: flatValues }
			);

			setSchema( response );
			toast.success( __( 'Settings saved.', 'flyaffiliate' ) );
		} catch ( error ) {
			const fieldErrors = ( error as ApiError )?.data?.errors;
			const detail = fieldErrors
				? Object.values( fieldErrors ).flat()[ 0 ]
				: undefined;

			toast.error(
				detail ||
					errorMessage(
						error,
						__( 'The settings could not be saved.', 'flyaffiliate' )
					)
			);
		} finally {
			setSaving( false );
		}
	};

	const handleNavigate = ( pageId: string ) => {
		setSearchParams(
			( previous ) => {
				const next = new URLSearchParams( previous );
				next.set( PARAM_PAGE, pageId );
				next.delete( PARAM_SUBPAGE );
				next.delete( PARAM_TAB );
				return next;
			},
			{ replace: true }
		);
	};

	return (
		<>
			<PageHeader
				title={ __( 'Settings', 'flyaffiliate' ) }
				description={ __(
					'Rates, the hold period, payouts and the affiliate area. Changes apply to new orders only. Existing commissions keep their numbers.',
					'flyaffiliate'
				) }
				actions={
					! getGlobals().setup.done && (
						<Button
							variant="outline"
							className={ BRAND_OUTLINE }
							render={ <Link to="/setup" /> }
							data-testid="flyaffiliate-run-wizard"
						>
							{ __( 'Run the setup wizard', 'flyaffiliate' ) }
						</Button>
					)
				}
			/>

			<Settings
				schema={ schema }
				loading={ loading }
				hookPrefix="flyaffiliate"
				applyFilters={ applyFilters }
				onSave={ handleSave }
				initialPage={ searchParams.get( PARAM_PAGE ) || undefined }
				onNavigate={ handleNavigate }
				onDirtyChange={ setDirty }
				className="min-h-[calc(100vh-14rem)] rounded-md shadow ring-0 border-0"
				searchPlaceholder={ __( 'Search settings…', 'flyaffiliate' ) }
				unsavedChangesDialog={ {
					title: __( 'Unsaved changes', 'flyaffiliate' ),
					description: __(
						'You have unsaved changes on this page. Leaving now discards them.',
						'flyaffiliate'
					),
					confirmText: __( 'Discard and leave', 'flyaffiliate' ),
					cancelText: __( 'Stay on this page', 'flyaffiliate' ),
				} }
				renderSaveButton={ ( {
					dirty: isDirty,
					hasErrors,
					onSave,
				} ) => (
					<>
						<UrlSync restored={ restored } />
						<Button
							onClick={ onSave }
							disabled={ ! isDirty || hasErrors || saving }
							data-testid="flyaffiliate-settings-save"
						>
							{ saving && <Spinner className="size-4 mr-2" /> }
							{ saving
								? __( 'Saving…', 'flyaffiliate' )
								: __( 'Save changes', 'flyaffiliate' ) }
						</Button>
					</>
				) }
			/>

			<AlertDialog
				open={ blocker.state === 'blocked' }
				onOpenChange={ ( open: boolean ) => {
					if ( ! open ) {
						blocker.reset?.();
					}
				} }
			>
				<AlertDialogContent>
					<AlertDialogHeader>
						<AlertDialogTitle>
							{ __( 'Unsaved changes', 'flyaffiliate' ) }
						</AlertDialogTitle>
						<AlertDialogDescription>
							{ __(
								'You have unsaved settings changes. Leaving this page discards them.',
								'flyaffiliate'
							) }
						</AlertDialogDescription>
					</AlertDialogHeader>
					<AlertDialogFooter>
						<AlertDialogCancel onClick={ () => blocker.reset?.() }>
							{ __( 'Stay on this page', 'flyaffiliate' ) }
						</AlertDialogCancel>
						<AlertDialogAction
							variant="destructive"
							onClick={ () => blocker.proceed?.() }
						>
							{ __( 'Discard and leave', 'flyaffiliate' ) }
						</AlertDialogAction>
					</AlertDialogFooter>
				</AlertDialogContent>
			</AlertDialog>
		</>
	);
}
