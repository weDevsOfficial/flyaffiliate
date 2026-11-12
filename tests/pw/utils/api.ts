/**
 * The FlyAffiliate REST API as a logged-in user, cookie + nonce, the way the
 * admin app calls it. `?rest_route=` works under any permalink setting.
 *
 * Plain Node `fetch` with the session's cookies, not Playwright's request
 * context: a client made in `beforeAll` lives across tests, and a Playwright
 * context would be tied to (and break with) each test's trace.
 */
import { readFileSync } from 'fs';
import { join } from 'path';

export const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8888';
export const ADMIN_STATE = join( __dirname, '..', '.auth', 'admin.json' );

export type Reply< T = any > = {
	status: number;
	body: T;
	headers: Record< string, string >;
};

type StoredCookie = { name: string; value: string; domain: string };

export class Api {
	private readonly cookie: string;
	private readonly nonce: string;

	private constructor( cookie: string, nonce: string ) {
		this.cookie = cookie;
		this.nonce = nonce;
	}

	/**
	 * A client for a stored session (admin by default), or a logged-out one.
	 *
	 * @param {string|null} storageState Path to a storage state, or null for a guest.
	 * @return {Promise<Api>} The client.
	 */
	static async as(
		storageState: string | null = ADMIN_STATE
	): Promise< Api > {
		if ( ! storageState ) {
			return new Api( '', '' );
		}

		const host = new URL( BASE_URL ).hostname;
		const { cookies } = JSON.parse(
			readFileSync( storageState, 'utf8' )
		) as {
			cookies: StoredCookie[];
		};
		const cookie = cookies
			.filter( ( c ) => c.domain.replace( /^\./, '' ) === host )
			.map( ( c ) => `${ c.name }=${ c.value }` )
			.join( '; ' );

		// Core's own endpoint for a fresh REST nonce (wp_ajax_rest_nonce).
		const nonce = () =>
			fetch( `${ BASE_URL }/wp-admin/admin-ajax.php?action=rest-nonce`, {
				headers: { cookie },
			} );
		// One retry on a socket Apache already closed, as in call().
		const res = await nonce().catch( ( error ) => {
			if (
				( error as { cause?: { code?: string } } ).cause?.code !==
				'UND_ERR_SOCKET'
			) {
				throw error;
			}
			return nonce();
		} );

		return new Api( cookie, ( await res.text() ).trim() );
	}

	/**
	 * Same session, no nonce: what a cross-site request would look like.
	 *
	 * @return {Api} The client.
	 */
	withoutNonce(): Api {
		return new Api( this.cookie, '' );
	}

	/**
	 * A route URL. Paths are under `flyaffiliate/v1`; one that names another
	 * namespace (`/flyaffiliate-pro/v1/…`) is used as given.
	 *
	 * @param {string} path  The route.
	 * @param {Object} query Query arguments.
	 * @return {string} The URL.
	 */
	url( path: string, query: Record< string, unknown > = {} ): string {
		const route = /^\/[a-z0-9-]+\/v\d+\//.test( path )
			? path
			: `/flyaffiliate/v1${ path }`;
		const params = new URLSearchParams( { rest_route: route } );

		for ( const [ key, value ] of Object.entries( query ) ) {
			params.append( key, String( value ) );
		}

		return `${ BASE_URL }/?${ params.toString() }`;
	}

	async call< T = any >(
		method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'OPTIONS',
		path: string,
		// A string is sent as is (e.g. malformed JSON); anything else as JSON.
		data?: unknown,
		query: Record< string, unknown > = {}
	): Promise< Reply< T > > {
		const headers: Record< string, string > = {};

		if ( this.cookie ) {
			headers.cookie = this.cookie;
		}
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		if ( data !== undefined ) {
			headers[ 'content-type' ] = 'application/json';
		}

		const send = () =>
			fetch( this.url( path, query ), {
				method,
				headers,
				body:
					data === undefined || typeof data === 'string'
						? data
						: JSON.stringify( data ),
			} );
		let res: Response;

		try {
			res = await send();
		} catch ( error ) {
			// Node's fetch reuses a kept-alive socket Apache has just closed
			// ("other side closed"): the request never reached WordPress, so
			// sending it once more is safe, POST included.
			if (
				( error as { cause?: { code?: string } } ).cause?.code !==
				'UND_ERR_SOCKET'
			) {
				throw error;
			}
			res = await send();
		}
		const text = await res.text();
		let body: any = text;

		try {
			body = JSON.parse( text );
		} catch {}

		return {
			status: res.status,
			body,
			headers: Object.fromEntries( res.headers.entries() ),
		};
	}

	get< T = any >( path: string, query: Record< string, unknown > = {} ) {
		return this.call< T >( 'GET', path, undefined, query );
	}

	post< T = any >( path: string, data: unknown = {} ) {
		return this.call< T >( 'POST', path, data );
	}

	put< T = any >( path: string, data: unknown = {} ) {
		return this.call< T >( 'PUT', path, data );
	}

	del< T = any >( path: string, query: Record< string, unknown > = {} ) {
		return this.call< T >( 'DELETE', path, undefined, query );
	}

	/** Nothing to release; kept so call sites read the same. */
	async dispose(): Promise< void > {}
}

/**
 * Money as integer cents (CONTEXT.md rule 6): never compare floats.
 *
 * @param {number|string} amount A number, or a formatted amount like "$1,234.50".
 * @return {number} Cents.
 */
export function cents( amount: number | string ): number {
	const value =
		typeof amount === 'number'
			? amount
			: parseFloat( amount.replace( /[^0-9.-]/g, '' ) );

	return Math.round( value * 100 );
}
