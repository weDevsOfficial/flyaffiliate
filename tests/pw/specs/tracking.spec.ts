/**
 * A referral link records a visit that shows up on the Visits screen.
 */
import { expect, test, type Page } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import { urls } from '../utils/site';

type WindowWithApiFetch = Window & {
	wp?: {
		apiFetch?: < T = unknown >( options: {
			path: string;
			parse?: boolean;
		} ) => Promise< T >;
	};
};

/**
 * How many visits the REST API lists for an affiliate.
 *
 * @param {Page}   page        The page, logged in as an admin.
 * @param {number} affiliateId The affiliate.
 * @return {Promise<number>} The total.
 */
async function countVisits(
	page: Page,
	affiliateId: number
): Promise< number > {
	// wp.apiFetch carries the REST root and nonce, whatever the permalink setting.
	return page.evaluate( async ( id: number ) => {
		const apiFetch = ( window as WindowWithApiFetch ).wp?.apiFetch;

		if ( ! apiFetch ) {
			throw new Error( 'wp.apiFetch is not on the page' );
		}

		const response = await apiFetch< Response >( {
			path: `/flyaffiliate/v1/visits?affiliate_id=${ id }&per_page=1`,
			parse: false,
		} );

		return Number( response.headers.get( 'X-WP-Total' ) );
	}, affiliateId );
}

/**
 * The attribution window, in days, from the settings API.
 *
 * The endpoint returns the whole schema with values filled in, so the field
 * is found by its id wherever it sits.
 *
 * @param {Page} page The page, logged in as an admin.
 * @return {Promise<number>} The days.
 */
async function cookieDays( page: Page ): Promise< number > {
	return page.evaluate( async () => {
		const apiFetch = ( window as WindowWithApiFetch ).wp?.apiFetch;

		if ( ! apiFetch ) {
			throw new Error( 'wp.apiFetch is not on the page' );
		}

		const response = await apiFetch( {
			path: '/flyaffiliate/v1/settings',
			parse: false,
		} );
		const schema: unknown = await response.json();

		const find = ( node: unknown ): unknown => {
			if ( Array.isArray( node ) ) {
				for ( const child of node ) {
					const found = find( child );

					if ( found !== undefined ) {
						return found;
					}
				}

				return undefined;
			}

			if ( node && typeof node === 'object' ) {
				const element = node as Record< string, unknown >;

				if ( element.id === 'cookie_duration' ) {
					return element.value ?? element.default;
				}

				return find( Object.values( element ) );
			}

			return undefined;
		};

		return Number( find( schema ) ?? 30 );
	} );
}

test.describe( 'Referral tracking', () => {
	test( 'a click on a referral link becomes a visit', async ( { page } ) => {
		const list = new ListPage( page );
		await list.open( urls.affiliates, 'Affiliates' );

		// An *active* affiliate's link, read through REST: the tracker ignores a
		// pending affiliate, and the list no longer prints the link itself.
		await list.selectTab( 'Active' );
		const referralUrl = await page.evaluate( async () => {
			const apiFetch = ( window as WindowWithApiFetch ).wp?.apiFetch;

			if ( ! apiFetch ) {
				throw new Error( 'wp.apiFetch is not on the page' );
			}

			const response = await apiFetch< { referral_url?: string }[] >( {
				path: '/flyaffiliate/v1/affiliates?status=active&per_page=1',
			} );

			return String( response?.[ 0 ]?.referral_url ?? '' );
		} );
		const affiliateId = Number(
			referralUrl.match( /affiliate=(\d+)/ )?.[ 1 ]
		);
		expect( affiliateId ).toBeGreaterThan( 0 );

		const before = await countVisits( page, affiliateId );
		// Read while still in the admin: the front end does not load wp.apiFetch.
		const days = await cookieDays( page );

		// The stamp keeps the click distinct from an earlier run's cookie.
		await page.context().clearCookies( { name: 'flyaffiliate_ref' } );
		await page.goto( `/?affiliate=${ affiliateId }&pw=${ Date.now() }` );

		const cookies = await page.context().cookies();
		const cookie = cookies.find( ( c ) => c.name === 'flyaffiliate_ref' );
		expect( cookie ).toBeTruthy();

		// The cookie lives as long as the attribution window says (30 days by
		// default), and the browser cannot read or change it.
		const expected = Date.now() / 1000 + days * 24 * 60 * 60;
		expect( cookie?.httpOnly ).toBe( true );
		expect( Math.abs( ( cookie?.expires ?? 0 ) - expected ) ).toBeLessThan(
			120
		);

		await list.open( urls.visits, 'Visits' );
		const after = await countVisits( page, affiliateId );

		expect( after ).toBe( before + 1 );
	} );
} );
