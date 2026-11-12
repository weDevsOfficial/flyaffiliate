/**
 * Referral tracking in a real browser: the link, the visit row, the cookie.
 *
 * ADR-0005 (first-party cookie, last click wins), ADR-0007 (hashed visitor
 * data), CONTEXT.md money rules 8–9. Every check uses an affiliate the test
 * creates and a fresh guest context, so no earlier cookie leaks in.
 * Scenario ids: references/scenarios.md §1 (TRK-*).
 */
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { BASE_URL } from '../utils/api';
import {
	affiliateVisits,
	createAffiliate,
	createOrder,
	createProduct,
	loginAs,
	restoreSettings,
	setSettings,
	withSettings,
} from '../utils/fixtures';
import { ListPage } from '../pages/listPage';
import { urls } from '../utils/site';

const COOKIE = 'flyaffiliate_ref';

test.describe( 'Referral tracking', () => {
	let guest: BrowserContext;
	let page: Page;

	withSettings( {
		referral_variable: 'affiliate',
		cookie_duration: 30,
		block_self_referral: 'on',
	} );

	test.beforeAll( () => {} );

	test.beforeEach( async ( { browser } ) => {
		guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		page = await guest.newPage();
	} );

	test.afterEach( async () => guest.close() );

	const refCookie = async () =>
		( await guest.cookies() ).find( ( c ) => c.name === COOKIE );

	test( '[TRK-01] a referral link records a visit and sets a 30-day HttpOnly Lax cookie', async () => {
		const aff = createAffiliate();

		const res = await page.goto( `/?affiliate=${ aff.id }` );
		expect( res?.status() ).toBe( 200 );

		expect( affiliateVisits( aff.id ) ).toHaveLength( 1 );
		const cookie = await refCookie();
		expect( cookie ).toBeTruthy();
		expect( cookie!.httpOnly ).toBe( true );
		expect( cookie!.sameSite ).toBe( 'Lax' );
		expect( cookie!.value.split( /\||%7C/i )[ 0 ] ).toBe(
			String( aff.id )
		);
		expect(
			Math.abs( cookie!.expires - ( Date.now() / 1000 + 30 * 86400 ) )
		).toBeLessThan( 300 );
	} );

	test( '[TRK-14] page scripts cannot read the attribution cookie', async () => {
		const aff = createAffiliate();
		await page.goto( `/?affiliate=${ aff.id }` );

		expect( await refCookie() ).toBeTruthy();
		expect( await page.evaluate( () => document.cookie ) ).not.toContain(
			COOKIE
		);
	} );

	test( '[TRK-02][TRK-12] the visit keeps the landing page and hashes, never the raw IP or agent', async () => {
		const aff = createAffiliate();
		const ua = await page.evaluate( () => navigator.userAgent );
		await page.goto( `/?p=1&affiliate=${ aff.id }&utm=pw` );

		const [ visit ] = affiliateVisits( aff.id );
		expect( visit.url ).toContain( 'p=1' );
		expect( visit.url ).toContain( 'utm=pw' );
		expect( visit.url ).not.toContain( 'affiliate=' );
		expect( visit.ip_hash ).toMatch( /^[0-9a-f]{20,}$/i );
		expect( visit.user_agent_hash ).toMatch( /^[0-9a-f]{20,}$/i );
		expect( visit.user_agent_hash ).not.toContain( ua );
		expect( Object.keys( visit ) ).not.toContain( 'ip' );
		expect( Object.keys( visit ) ).not.toContain( 'user_agent' );
		expect( JSON.stringify( visit ) ).not.toMatch(
			/127\.0\.0\.1|172\.\d+\.\d+\.\d+|Mozilla/
		);
	} );

	test( '[TRK-03] pending, inactive, suspended and unknown affiliates are ignored', async () => {
		for ( const status of [ 'pending', 'inactive', 'suspended' ] ) {
			const aff = createAffiliate( status );
			await page.goto( `/?affiliate=${ aff.id }` );
			expect( affiliateVisits( aff.id ), status ).toHaveLength( 0 );
		}

		await page.goto( '/?affiliate=99999999' );
		expect( await refCookie() ).toBeUndefined();
	} );

	test( '[TRK-04] a malformed value is ignored and the page still renders', async () => {
		// absint() reads a leading number (`-5`, `5abc` → 5), which is harmless;
		// anything without one must track nothing and break nothing.
		for ( const value of [
			'abc',
			"' OR 1=1 --",
			'<script>alert(1)</script>',
			'0',
			'99999999999999999999',
		] ) {
			const res = await page.goto(
				`/?affiliate=${ encodeURIComponent( value ) }`
			);
			expect( res?.status(), value ).toBe( 200 );
		}
		expect( await refCookie() ).toBeUndefined();
	} );

	test( '[TRK-16] clicking the same link again is one visit; only the expiry moves', async () => {
		const aff = createAffiliate();
		await page.goto( `/?affiliate=${ aff.id }` );
		const first = await refCookie();
		await page.goto( `/?affiliate=${ aff.id }&again=1` );

		expect( affiliateVisits( aff.id ) ).toHaveLength( 1 );
		expect( ( await refCookie() )!.value ).toBe( first!.value );
	} );

	test( '[TRK-08] last click wins: a second affiliate takes the cookie and the order @money', async () => {
		const a = createAffiliate();
		const b = createAffiliate();
		await page.goto( `/?affiliate=${ a.id }` );
		await page.goto( `/?affiliate=${ b.id }` );

		expect( affiliateVisits( a.id ) ).toHaveLength( 1 );
		expect( affiliateVisits( b.id ) ).toHaveLength( 1 );

		const cookie = decodeURIComponent( ( await refCookie() )!.value );
		expect( cookie.split( '|' )[ 0 ] ).toBe( String( b.id ) );

		// Check out with what the browser holds: the order and the visit are B's.
		const order = createOrder( {
			cookie,
			items: [ { productId: createProduct( 20 ) } ],
		} );
		const [ visit ] = affiliateVisits( b.id );
		expect( Number( visit.converted ) ).toBe( 1 );
		expect( Number( visit.order_id ) ).toBe( order );
		expect( Number( affiliateVisits( a.id )[ 0 ].converted ) ).toBe( 0 );
	} );

	test( '[TRK-05] the referral variable is a setting', async () => {
		const aff = createAffiliate();
		const before = setSettings( { referral_variable: 'pwref' } );

		try {
			await page.goto( `/?affiliate=${ aff.id }` );
			expect( affiliateVisits( aff.id ) ).toHaveLength( 0 );

			await page.goto( `/?pwref=${ aff.id }` );
			expect( affiliateVisits( aff.id ) ).toHaveLength( 1 );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[TRK-06] a cookie duration of 0 makes a session cookie', async () => {
		const aff = createAffiliate();
		const before = setSettings( { cookie_duration: 0 } );

		try {
			await page.goto( `/?affiliate=${ aff.id }` );
			expect( ( await refCookie() )!.expires ).toBe( -1 );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[TRK-10] an affiliate following their own link is not tracked', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const { context } = await loginAs( browser, aff );

		try {
			const own = await context.newPage();
			await own.goto( `/?affiliate=${ aff.id }` );
			expect( affiliateVisits( aff.id ) ).toHaveLength( 0 );
			expect(
				( await context.cookies() ).some( ( c ) => c.name === COOKIE )
			).toBe( false );
		} finally {
			await context.close();
		}
	} );

	test( '[TRK-13] the visit shows on the admin Visits screen', async ( {
		page: admin,
	} ) => {
		const aff = createAffiliate();
		await page.goto( `/?affiliate=${ aff.id }` );

		const list = new ListPage( admin );
		await list.open(
			`${ urls.visits }?affiliate_id=${ aff.id }`,
			'Visits'
		);
		await expect( list.app ).toContainText( aff.login.slice( 0, 12 ) );
	} );
} );
