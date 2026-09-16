/**
 * Server-driven DataViews state.
 *
 * DataViews owns the view (page, per page, sort, search); this hook turns it
 * into REST query arguments, fetches, and exposes the rows with their totals.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import type { DataViewState } from '@wedevs/plugin-ui';
import { fetchList } from '@/lib/api';

type Options = {
	path: string;
	defaultView: DataViewState;
	/** Extra query arguments merged into every request. */
	filters?: Record< string, unknown >;
	/** Map a DataViews sort field to the REST `orderby` value. */
	sortField?: ( field: string ) => string;
};

export function useListView< Item >( {
	path,
	defaultView,
	filters = {},
	sortField = ( field ) => field,
}: Options ) {
	const [ view, setView ] = useState< DataViewState >( defaultView );
	const [ items, setItems ] = useState< Item[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ version, setVersion ] = useState( 0 );

	const filterKey = JSON.stringify( filters );

	const args = useMemo( () => {
		const query: Record< string, unknown > = {
			page: view.page ?? 1,
			per_page: view.perPage ?? 20,
			...JSON.parse( filterKey ),
		};

		if ( view.search ) {
			query.search = view.search;
		}

		if ( view.sort?.field ) {
			query.orderby = sortField( view.sort.field );
			query.order = view.sort.direction ?? 'desc';
		}

		return query;
		// filterKey stands in for filters so a new object with equal content does not refetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ view.page, view.perPage, view.search, view.sort, filterKey ] );

	useEffect( () => {
		let cancelled = false;

		setLoading( true );

		fetchList< Item >( path, args )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}

				setItems( result.items );
				setTotal( result.total );
				setTotalPages( result.totalPages );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setItems( [] );
					setTotal( 0 );
					setTotalPages( 1 );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ path, args, version ] );

	// A filter change belongs on page one.
	useEffect( () => {
		setView( ( previous ) =>
			previous.page === 1 ? previous : { ...previous, page: 1 }
		);
	}, [ filterKey ] );

	const refresh = useCallback( () => setVersion( ( v ) => v + 1 ), [] );

	return {
		view,
		setView,
		items,
		total,
		totalPages,
		loading,
		refresh,
		paginationInfo: { totalItems: total, totalPages },
	};
}
