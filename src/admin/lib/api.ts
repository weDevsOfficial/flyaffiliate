/**
 * The REST client: a thin layer over @wordpress/api-fetch that knows the
 * namespace and reads the pagination headers every collection carries.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const NAMESPACE = '/flyaffiliate/v1';

export type ListResult< Item > = {
	items: Item[];
	total: number;
	totalPages: number;
};

export type ApiError = {
	code?: string;
	message?: string;
	data?: { status?: number; errors?: Record< string, string[] > };
};

/**
 * Fetch a collection with its `X-WP-Total` headers.
 *
 * @param {string} path The route below the namespace, e.g. `/affiliates`.
 * @param {Object} args Query arguments.
 * @return {Promise<ListResult>} The items and totals.
 */
export async function fetchList< Item >(
	path: string,
	args: Record< string, unknown > = {}
): Promise< ListResult< Item > > {
	// `parse: false` hands back the Response so the headers are readable; the
	// package's overloads do not model that, hence the cast.
	const response = ( await apiFetch( {
		path: addQueryArgs( NAMESPACE + path, args ),
		parse: false,
	} as Parameters< typeof apiFetch >[ 0 ] ) ) as unknown as Response;

	const items = ( await response.json() ) as Item[];

	return {
		items,
		total: Number( response.headers.get( 'X-WP-Total' ) ) || items.length,
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1,
	};
}

/**
 * Fetch one resource.
 *
 * @param {string} path The route below the namespace.
 * @param {Object} args Query arguments.
 * @return {Promise<*>} The parsed body.
 */
export function fetchOne< Result >(
	path: string,
	args: Record< string, unknown > = {}
): Promise< Result > {
	return apiFetch< Result >( {
		path: addQueryArgs( NAMESPACE + path, args ),
	} );
}

/**
 * Create, update or delete.
 *
 * @param {string} path   The route below the namespace.
 * @param {string} method HTTP method.
 * @param {Object} data   Body.
 * @return {Promise<*>} The parsed body.
 */
export function send< Result >(
	path: string,
	method: 'POST' | 'PUT' | 'DELETE',
	data: Record< string, unknown > = {}
): Promise< Result > {
	return apiFetch< Result >( { path: NAMESPACE + path, method, data } );
}

/**
 * A readable message from a failed request.
 *
 * @param {unknown} error    What apiFetch rejected with.
 * @param {string}  fallback Used when the error carries no message.
 * @return {string} The message.
 */
export function errorMessage( error: unknown, fallback: string ): string {
	const typed = error as ApiError;

	return typed?.message || fallback;
}
