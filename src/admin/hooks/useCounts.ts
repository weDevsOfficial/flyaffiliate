/**
 * Per-status totals for the tabs above a list.
 *
 * Every list endpoint reports `X-WP-Total`; one request per status with a
 * page size of one is cheap and keeps the counts on the same query path as
 * the rows themselves.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { fetchList } from '@/lib/api';

export function useCounts(
	path: string,
	field: string,
	values: string[],
	extra: Record< string, unknown > = {}
) {
	const [ counts, setCounts ] = useState< Record< string, number > >( {} );
	const [ loading, setLoading ] = useState( true );
	const [ version, setVersion ] = useState( 0 );
	// A silent refresh (polling) keeps the counts on screen while it fetches.
	const silent = useRef( false );
	const extraKey = JSON.stringify( extra );

	useEffect( () => {
		let cancelled = false;
		const base = JSON.parse( extraKey );

		if ( ! silent.current ) {
			setLoading( true );
		}

		silent.current = false;

		Promise.all(
			[ 'all', ...values ].map( ( value ) =>
				fetchList( path, {
					per_page: 1,
					...base,
					...( value === 'all' ? {} : { [ field ]: value } ),
				} )
					.then( ( result ) => [ value, result.total ] as const )
					.catch( () => [ value, 0 ] as const )
			)
		).then( ( entries ) => {
			if ( ! cancelled ) {
				setCounts( Object.fromEntries( entries ) );
				setLoading( false );
			}
		} );

		return () => {
			cancelled = true;
		};
		// values is a constant list per page; extraKey covers the filters.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ path, field, extraKey, version ] );

	const refresh = useCallback( ( options?: { silent?: boolean } ) => {
		silent.current = !! options?.silent;
		setVersion( ( v ) => v + 1 );
	}, [] );

	return { counts, loading, refresh };
}
