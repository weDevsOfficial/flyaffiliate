/**
 * Who may reach what, escaping, and what the pages load.
 *
 * ADR-0008 (one admin capability, self-scoped affiliate routes), ADR-0003
 * (no relaxed security), ADR-0013 (no remote hosts in the shipped assets).
 * Scenario ids: references/scenarios.md §9 (SEC-*).
 */
import { expect, test, type Page } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api, BASE_URL } from '../utils/api';
import {
	createAffiliate,
	createUser,
	loginAs,
	pageUrls,
} from '../utils/fixtures';
import { urls } from '../utils/site';
import { lit, php } from '../utils/wp';

/** Every admin route, with a harmless request for each. */
const ADMIN_ROUTES: [ 'GET' | 'POST' | 'PUT', string ][] = [
	[ 'GET', '/affiliates' ],
	[ 'GET', '/commissions' ],
	[ 'POST', '/commissions' ],
	[ 'GET', '/visits' ],
	[ 'GET', '/payouts' ],
	[ 'GET', '/payouts/batches' ],
	[ 'POST', '/payouts/preview' ],
	[ 'POST', '/payouts' ],
	[ 'GET', '/settings' ],
	[ 'PUT', '/settings/general' ],
	[ 'GET', '/dashboard' ],
	[ 'GET', '/referral-links' ],
	[ 'POST', '/setup/complete' ],
];

/** Well-formed bodies, so a refusal is the permission check and not WordPress's argument validation (which runs first). */
const BODIES: Record< string, unknown > = {
	'/commissions': {
		affiliate_id: 1,
		amount: 1,
		source: 'manual',
		status: 'unpaid',
	},
	'/settings/general': { values: { hold_days: 1 } },
};

async function expectAllRefused( api: Api, who: string ) {
	for ( const [ method, path ] of ADMIN_ROUTES ) {
		const res = await api.call(
			method,
			path,
			method === 'GET' ? undefined : BODIES[ path ] ?? {}
		);
		expect(
			[ 401, 403 ],
			`${ who } ${ method } ${ path } → ${ res.status }`
		).toContain( res.status );
	}
}

test.describe( 'Admin REST permissions', () => {
	test( '[SEC-02] a guest reaches no admin route', async () => {
		const guest = await Api.as( null );
		await expectAllRefused( guest, 'guest' );
		await guest.dispose();
	} );

	test( '[SEC-02] an affiliate reaches no admin route', async ( {
		browser,
	} ) => {
		const { context, state } = await loginAs( browser, createAffiliate() );
		const api = await Api.as( state );
		await expectAllRefused( api, 'affiliate' );
		await api.dispose();
		await context.close();
	} );

	test( '[SEC-02] a shop manager reaches no admin route by default (manage_options)', async ( {
		browser,
	} ) => {
		const { context, state } = await loginAs(
			browser,
			createUser( 'shop_manager' )
		);
		const api = await Api.as( state );
		await expectAllRefused( api, 'shop_manager' );
		await api.dispose();
		await context.close();
	} );

	test( '[SEC-07] an admin cookie without the REST nonce is treated as logged out', async () => {
		const admin = await Api.as();
		const res = await admin.withoutNonce().get( '/affiliates' );
		expect( res.status ).toBe( 401 );
		expect( ( await admin.get( '/affiliates' ) ).status ).toBe( 200 );
		await admin.dispose();
	} );
} );

test.describe( 'Admin screen permissions', () => {
	test( '[SEC-01] a subscriber and a shop manager cannot open the admin app', async ( {
		browser,
	} ) => {
		for ( const role of [ 'subscriber', 'shop_manager' ] ) {
			const { context } = await loginAs( browser, createUser( role ) );
			const page = await context.newPage();
			await page.goto( urls.app, { waitUntil: 'domcontentloaded' } );
			await expect(
				page.locator( '#flyaffiliate-admin-app' ),
				role
			).toHaveCount( 0 );
			await context.close();
		}
	} );
} );

test.describe( 'Escaping', () => {
	const PAYLOAD =
		'<img src=x onerror="window.__pwXss=1">"><svg onload="window.__pwXss=1">';

	const watch = ( page: Page ) => {
		const dialogs: string[] = [];
		page.on( 'dialog', ( d ) => {
			dialogs.push( d.message() );
			void d.dismiss();
		} );
		return async () => {
			expect( dialogs ).toEqual( [] );
			expect(
				await page.evaluate(
					() => ( window as unknown as { __pwXss?: number } ).__pwXss
				)
			).toBeUndefined();
		};
	};

	test( '[SEC-06] hostile affiliate data renders as text in the admin and on the dashboard', async ( {
		page,
		browser,
	} ) => {
		const aff = createAffiliate();
		php(
			`wp_update_user( [ 'ID' => ${
				aff.userId
			}, 'display_name' => ${ lit( PAYLOAD ) }, 'first_name' => ${ lit(
				PAYLOAD
			) } ] ); return true;`
		);
		const admin = await Api.as();
		await admin.put( `/affiliates/${ aff.id }`, {
			promo_method: PAYLOAD,
			payment_email: aff.email,
		} );
		await admin.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 1,
			source: 'manual',
			status: 'unpaid',
		} );

		const check = watch( page );
		const app = new AdminPage( page );
		for ( const route of [
			urls.affiliates,
			`${ urls.app }#/affiliates/${ aff.id }`,
			urls.commissions,
			urls.dashboard,
		] ) {
			await app.goto( route );
			await page.waitForLoadState( 'networkidle' );
			await check();
		}

		const { context } = await loginAs( browser, aff );
		const own = await context.newPage();
		const checkOwn = watch( own );
		await own.goto( pageUrls().dashboard );
		await own.waitForLoadState( 'networkidle' );
		await checkOwn();
		await context.close();
		await admin.dispose();
	} );

	test( '[SEC-06] a hostile payout note renders as text', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		const admin = await Api.as();
		await admin.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 2,
			source: 'manual',
			status: 'unpaid',
			created_at: '2020-01-01 00:00:00',
		} );
		const created = await admin.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
			note: PAYLOAD,
		} );
		expect( created.status ).toBe( 201 );

		const check = watch( page );
		const app = new AdminPage( page );
		await app.goto(
			`${ urls.app }#/payouts/batch/${ created.body.batch_key }`
		);
		// The note is stored through sanitize_textarea_field(), so the tags never reach the page.
		await expect( app.heading ).not.toContainText( 'onerror' );
		await page.waitForLoadState( 'networkidle' );
		await check();
		await app.goto( urls.payouts );
		await page.waitForLoadState( 'networkidle' );
		await check();

		await admin.del( `/payouts/batch/${ created.body.batch_key }` );
		await admin.dispose();
	} );
} );

/**
 * Console warnings that are not ours to fix:
 * - React's invalid-markup warning, pinned on its own by the BUG-003 test below.
 * - WordPress 6.6's element.js bundles the production react-dom/client shim,
 *   so under SCRIPT_DEBUG (development ReactDOM) every wp.element.createRoot
 *   call warns about importing createRoot from "react-dom". Core, not us.
 * @param text
 */
const isKnownReactWarning = ( text: string ) =>
	text.startsWith( 'Warning: validateDOMNesting' ) ||
	text.startsWith( 'Warning: You are importing createRoot from "react-dom"' );

test.describe( 'What the pages load', () => {
	const ROUTES = [
		urls.dashboard,
		urls.affiliates,
		urls.commissions,
		`${ urls.app }#/commissions/new`,
		urls.visits,
		urls.payouts,
		urls.newPayout,
		`${ urls.app }#/payouts/payments`,
		urls.settings,
		urls.setup,
		`${ urls.app }#/extensions`,
	];

	test( '[SEC-09][SEC-11] admin routes call no other host and log no errors', async ( {
		page,
	} ) => {
		const foreign: string[] = [];
		const errors: string[] = [];
		page.on( 'request', ( r ) => {
			const host = new URL( r.url() ).host;
			if (
				/^https?:/.test( r.url() ) &&
				host !== new URL( BASE_URL ).host
			) {
				foreign.push( r.url() );
			}
		} );
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		page.on(
			'console',
			( m ) =>
				m.type() === 'error' &&
				! isKnownReactWarning( m.text() ) &&
				errors.push( m.text() )
		);

		const app = new AdminPage( page );
		for ( const route of ROUTES ) {
			const seen = errors.length;
			await app.goto( route );
			await page.waitForLoadState( 'networkidle' );
			expect( errors.slice( seen ), route ).toEqual( [] );
		}

		// wp-admin itself may reach out (Gravatar for the admin bar avatar);
		// nothing FlyAffiliate ships may.
		expect(
			foreign.filter(
				( u ) =>
					! /gravatar\.com|wordpress\.org|woocommerce\.com|wp\.com/.test(
						u
					)
			)
		).toEqual( [] );
	} );

	test( '[SEC-09][SEC-11] the affiliate dashboard calls no other host and logs no errors', async ( {
		browser,
	} ) => {
		const { context } = await loginAs( browser, createAffiliate() );
		const page = await context.newPage();
		const foreign: string[] = [];
		const errors: string[] = [];
		page.on( 'request', ( r ) => {
			if (
				/^https?:/.test( r.url() ) &&
				new URL( r.url() ).host !== new URL( BASE_URL ).host
			) {
				foreign.push( r.url() );
			}
		} );
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		page.on(
			'console',
			( m ) =>
				m.type() === 'error' &&
				! isKnownReactWarning( m.text() ) &&
				errors.push( m.text() )
		);

		for ( const tab of [
			'overview',
			'referral-links',
			'commissions',
			'visits',
			'payouts',
			'settings',
		] ) {
			// Plain or pretty permalinks: join the tab with ? or &.
			const url = new URL( pageUrls().dashboard );
			url.searchParams.set( 'tab', tab );
			await page.goto( url.toString() );
			await expect(
				page.getByTestId( 'flyaffiliate-dashboard' )
			).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
		}

		expect( errors ).toEqual( [] );
		expect(
			foreign.filter( ( u ) => ! /gravatar\.com|wp\.com/.test( u ) )
		).toEqual( [] );
		await context.close();
	} );

	test( '[SEC-11] no button is nested inside another (valid, accessible markup)', async ( {
		page,
		browser,
	} ) => {
		// Guards BUG-003 (tests/pw/PRODUCT_BUGS.md, fixed): the date-range
		// trigger once rendered a <Button> inside plugin-ui's own trigger button.
		const nested = () =>
			document.querySelectorAll( 'button button' ).length;

		const app = new AdminPage( page );
		await app.goto( urls.dashboard );
		await page.waitForLoadState( 'networkidle' );
		expect( await page.evaluate( nested ), 'admin dashboard' ).toBe( 0 );

		const { context } = await loginAs( browser, createAffiliate() );
		const own = await context.newPage();
		await own.goto( pageUrls().dashboard );
		await expect(
			own.getByTestId( 'flyaffiliate-dashboard' )
		).toBeVisible();
		await own.waitForLoadState( 'networkidle' );
		expect( await own.evaluate( nested ), 'affiliate dashboard' ).toBe( 0 );
		await context.close();
	} );
} );
