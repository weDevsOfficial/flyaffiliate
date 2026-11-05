/**
 * The affiliate dashboard (`[flyaffiliate_dashboard]`): the states around
 * it, the figures, the referral link, saved links and the self-scoped API.
 *
 * ADR-0006 (no withdraw), ADR-0008 (self-scoped `me` routes), ADR-0010,
 * ADR-0015 (a saved link is a bookmark), ADR-0016 (the affiliate never sees
 * a transfer's failure).
 * Scenario ids: references/scenarios.md §8 (DSH-*), §9 (SEC-04, SEC-05).
 */
import {
	expect,
	test,
	type Browser,
	type BrowserContext,
} from '@playwright/test';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	affiliateVisits,
	createAffiliate,
	createUser,
	loginAs,
	pageUrls,
	restoreSettings,
	setSettings,
	withSettings,
	type TestAffiliate,
} from '../utils/fixtures';

let admin: Api;
let dashboard: string;

/**
 * A logged-in affiliate: their browser context and their REST client.
 * @param {Browser} browser       The test browser.
 * @param {Object}  user          Login and password.
 * @param           user.login
 * @param           user.password
 */
async function session(
	browser: Browser,
	user: { login: string; password: string }
) {
	const { context, state } = await loginAs( browser, user );
	return { context, api: await Api.as( state ) };
}

async function commission(
	affiliateId: number,
	amount: number,
	status: string
) {
	const res = await admin.post( '/commissions', {
		affiliate_id: affiliateId,
		amount,
		source: 'manual',
		status,
	} );
	expect( res.status ).toBe( 201 );
	return res.body.id as number;
}

withSettings( {
	referral_variable: 'affiliate',
	hold_days: 0,
	currency: 'USD',
	currency_position: 'left',
	currency_thousands_separator: ',',
	currency_decimal_separator: '.',
} );

test.beforeAll( async () => {
	dashboard = pageUrls().dashboard;
	admin = await Api.as();
} );

test.afterAll( async () => {
	await admin.dispose();
} );

test.describe( 'Dashboard states', () => {
	test( '[DSH-01] a guest is asked to log in', async ( { browser } ) => {
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await guest.newPage();
		await page.goto( dashboard );
		await expect(
			page.getByText( 'Log in to see your dashboard' )
		).toBeVisible();
		await expect(
			page.getByTestId( 'flyaffiliate-dashboard' )
		).toHaveCount( 0 );
		await guest.close();
	} );

	test( '[DSH-02] a member who is not an affiliate is invited to join', async ( {
		browser,
	} ) => {
		const { context } = await loginAs( browser, createUser() );
		const page = await context.newPage();
		await page.goto( dashboard );
		await expect(
			page.getByText( 'You are not an affiliate yet' )
		).toBeVisible();
		await context.close();
	} );

	test( '[DSH-03] pending and inactive affiliates see their state, not the app', async ( {
		browser,
	} ) => {
		for ( const [ status, text ] of [
			[ 'pending', 'waiting to be activated' ],
			[ 'inactive', 'Your affiliate account is not active' ],
			[ 'suspended', 'Your affiliate account is not active' ],
		] ) {
			const { context } = await loginAs(
				browser,
				createAffiliate( status )
			);
			const page = await context.newPage();
			await page.goto( dashboard );
			await expect(
				page.getByText( text ).first(),
				status
			).toBeVisible();
			await expect(
				page.getByTestId( 'flyaffiliate-dashboard' )
			).toHaveCount( 0 );
			await context.close();
		}
	} );
} );

test.describe( 'An active affiliate', () => {
	let aff: TestAffiliate;
	let context: BrowserContext;
	let me: Api;

	test.beforeAll( async ( { browser } ) => {
		aff = createAffiliate();
		( { context, api: me } = await session( browser, aff ) );
	} );

	test.afterAll( async () => {
		await me.dispose();
		await context.close();
	} );

	test( '[DSH-04] the overview figures match the ledger @money', async () => {
		await commission( aff.id, 12.34, 'unpaid' );
		await commission( aff.id, 5, 'pending' );
		await commission( aff.id, 1.5, 'rejected' );

		const page = await context.newPage();
		await page.goto( dashboard );
		const app = page.getByTestId( 'flyaffiliate-dashboard' );
		await expect( app ).toBeVisible();

		const card = ( label: string ) =>
			app
				.locator( '[data-slot="card"]' )
				.filter( { hasText: new RegExp( `^${ label }` ) } )
				.first();
		await expect( card( 'Unpaid balance' ) ).toContainText( '$12.34' );
		await expect( card( 'Pending' ) ).toContainText( '$5.00' );
		await expect( card( 'Paid' ) ).toContainText( '$0.00' );
		await page.close();
	} );

	test( '[DSH-05] the referral link is the site with the referral variable', async () => {
		const page = await context.newPage();
		await page.goto( dashboard );
		await expect(
			page.locator( '#flyaffiliate-referral-link' )
		).toHaveValue( new RegExp( `[?&]affiliate=${ aff.id }(&|$)` ) );
		await page.close();
	} );

	test( '[DSH-06][DSH-07] a generated link is saved once, removed from the list only', async ( {
		browser,
	} ) => {
		const target = `${ BASE_URL }/?p=1`;
		const page = await context.newPage();
		await page.goto( dashboard );
		await page
			.locator( '#flyaffiliate-generate-link-input' )
			.fill( target );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		await expect
			.poll(
				async () => ( await me.get( '/me/referral-links' ) ).body.length
			)
			.toBe( 1 );

		// The same page again is the same link (idempotent).
		const again = await me.post( '/me/referral-links', { url: target } );
		expect( again.status ).toBe( 200 );
		const links = ( await me.get( '/me/referral-links' ) ).body;
		expect( links ).toHaveLength( 1 );
		expect( again.body.id ).toBe( links[ 0 ].id );
		expect( links[ 0 ].referral_url ).toContain( `affiliate=${ aff.id }` );

		// A visit through it counts on the link.
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		await ( await guest.newPage() ).goto( links[ 0 ].referral_url );
		await guest.close();
		await expect
			.poll(
				async () =>
					( await me.get( '/me/referral-links' ) ).body[ 0 ].visits
			)
			.toBe( 1 );

		// Removing it takes it off the list; the link still tracks.
		expect(
			( await me.del( `/me/referral-links/${ links[ 0 ].id }` ) ).status
		).toBe( 200 );
		expect( ( await me.get( '/me/referral-links' ) ).body ).toHaveLength(
			0
		);
		const before = affiliateVisits( aff.id ).length;
		const later = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		await ( await later.newPage() ).goto( links[ 0 ].referral_url );
		await later.close();
		expect( affiliateVisits( aff.id ).length ).toBe( before + 1 );

		// Saving the page again brings its history back.
		await me.post( '/me/referral-links', { url: target } );
		expect(
			( await me.get( '/me/referral-links' ) ).body[ 0 ].visits
		).toBe( 2 );
		await page.close();
	} );

	test( '[DSH-06] a link to another site is refused', async () => {
		const res = await me.post( '/me/referral-links', {
			url: 'https://evil.example/',
		} );
		expect( res.status ).toBeGreaterThanOrEqual( 400 );
	} );

	test( '[DSH-09] saved links follow a new referral variable', async () => {
		await me.post( '/me/referral-links', { url: `${ BASE_URL }/?p=2` } );
		const before = setSettings( { referral_variable: 'pwref' } );

		try {
			for ( const link of ( await me.get( '/me/referral-links' ) )
				.body ) {
				expect( link.referral_url ).toContain( `pwref=${ aff.id }` );
			}
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[DSH-11] the affiliate updates their payment email', async () => {
		const email = `paid-${ aff.login }@example.test`;
		const res = await me.put( '/me', { payment_email: email } );
		expect( res.status ).toBe( 200 );
		expect( ( await me.get( '/me' ) ).body.payment_email ).toBe( email );
	} );

	test( '[DSH-10] unpaid payments show as on their way; no failure detail leaks', async () => {
		const id = await commission( aff.id, 9, 'unpaid' );
		const created = await admin.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( created.status ).toBe( 201 );

		const payouts = ( await me.get( '/me/payouts' ) ).body;
		const mine = payouts.find(
			( p: { id: number } ) => p.id === created.body.payouts[ 0 ].id
		);
		expect( mine.status ).toBe( 'unpaid' );
		expect( JSON.stringify( mine ) ).not.toMatch( /"message"|"error"/ );

		await admin.del( `/payouts/batch/${ created.body.batch_key }` );
		expect(
			( await admin.get( `/commissions/${ id }` ) ).body.payout_id
		).toBeNull();
	} );

	test( '[DSH-12] the dashboard offers no withdrawal', async () => {
		const page = await context.newPage();
		await page.goto( dashboard );
		await expect(
			page.getByTestId( 'flyaffiliate-dashboard' )
		).toBeVisible();
		await expect( page.getByText( /withdraw/i ) ).toHaveCount( 0 );
		await page.close();
	} );

	test( '[DSH-15] the dashboard fits a phone screen', async () => {
		// Known bug (found 2026-11-04): the tab list (Overview … Settings) does
		// not wrap or scroll inside its row, so at 375px the page itself scrolls
		// sideways to 662px. Remove `test.fail()` once the tabs fit.
		test.fail();
		const page = await context.newPage();
		await page.setViewportSize( { width: 375, height: 800 } );
		await page.goto( dashboard );
		await expect(
			page.getByText( 'Unpaid balance', { exact: true } )
		).toBeVisible();
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - window.innerWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 1 );
		await page.close();
	} );
} );

test.describe( 'The me routes are self-scoped', () => {
	test( '[SEC-04][SEC-05] an affiliate sees and changes only their own rows', async ( {
		browser,
	} ) => {
		const a = createAffiliate();
		const b = createAffiliate();
		const mine = await commission( a.id, 2, 'unpaid' );
		const theirs = await commission( b.id, 3, 'unpaid' );
		const { context, api } = await session( browser, a );

		try {
			const list = (
				await api.get( '/me/commissions', { affiliate_id: b.id } )
			).body;
			expect( list.map( ( c: { id: number } ) => c.id ) ).toEqual( [
				mine,
			] );
			expect(
				( await api.get( `/me/commissions/${ theirs }` ) ).status
			).toBe( 404 );
			expect(
				cents(
					( await api.get( `/me/commissions/${ mine }` ) ).body.amount
				)
			).toBe( 200 );

			const { context: bContext, api: bApi } = await session(
				browser,
				b
			);
			const link = (
				await bApi.post( '/me/referral-links', {
					url: `${ BASE_URL }/?p=1`,
				} )
			).body;
			expect(
				( await api.del( `/me/referral-links/${ link.id }` ) ).status
			).toBe( 404 );
			expect(
				( await bApi.get( '/me/referral-links' ) ).body
			).toHaveLength( 1 );
			await bApi.dispose();
			await bContext.close();
		} finally {
			await api.dispose();
			await context.close();
		}
	} );

	test( '[SEC-04] a member who is not an affiliate gets no me data', async ( {
		browser,
	} ) => {
		const { context, api } = await session( browser, createUser() );
		expect( ( await api.get( '/me' ) ).status ).toBe( 403 );
		expect( ( await api.get( '/me/commissions' ) ).status ).toBe( 403 );
		await api.dispose();
		await context.close();
	} );
} );
