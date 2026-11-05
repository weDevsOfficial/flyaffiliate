/**
 * FlyAffiliate without WooCommerce: the programme still runs, and data the
 * integration recorded survives (ADR-0013).
 *
 * Deactivates WooCommerce for the length of this file and always turns it
 * back on, even when a test fails.
 * Scenario ids: references/scenarios.md §12 (STD-*).
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { SettingsPage } from '../pages/settingsPage';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	affiliateVisits,
	createAffiliate,
	createOrder,
	createProduct,
	loginAs,
	orderCommissions,
	pageUrls,
	setOrderStatus,
	withSettings,
	type TestAffiliate,
} from '../utils/fixtures';
import { urls } from '../utils/site';
import { php, wp } from '../utils/wp';

test.describe.configure( { mode: 'serial' } );

test.describe( 'Without WooCommerce', () => {
	let api: Api;
	let aff: TestAffiliate;
	let order: number;
	let wcCommission: number;

	withSettings( {
		hold_days: 0,
		minimum_amount: 0,
		woocommerce_enabled: 'on',
	} );

	test.beforeAll( async () => {
		aff = createAffiliate();
		// Recorded while WooCommerce was here: a pending order commission.
		order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: createProduct( 50 ) } ],
		} );
		wcCommission = orderCommissions( order )[ 0 ].id;
		wp( [ 'plugin', 'deactivate', 'woocommerce' ] );
		api = await Api.as();
	} );

	test.afterAll( async () => {
		wp( [ 'plugin', 'activate', 'woocommerce' ] );
		await api.dispose();
	} );

	test( '[STD-01] the admin app loads every screen', async ( { page } ) => {
		const errors: string[] = [];
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		const admin = new AdminPage( page );

		for ( const [ route, heading ] of [
			[ urls.dashboard, 'Dashboard' ],
			[ urls.affiliates, 'Affiliates' ],
			[ urls.commissions, 'Commissions' ],
			[ urls.visits, 'Visits' ],
			[ urls.payouts, 'Payouts' ],
			[ urls.settings, 'Settings' ],
		] ) {
			await admin.goto( route );
			await expect( admin.heading, route ).toHaveText( heading );
		}
		expect( errors ).toEqual( [] );
	} );

	test( '[STD-03] order commissions keep their rows, stay editable, and wait for their order', async () => {
		const res = await api.get( `/commissions/${ wcCommission }` );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toMatchObject( {
			source: 'woocommerce',
			status: 'pending',
			order_id: order,
		} );

		const edited = await api.put( `/commissions/${ wcCommission }`, {
			amount: 4.5,
		} );
		expect( edited.status ).toBe( 200 );
		expect( cents( edited.body.amount ) ).toBe( 450 );
	} );

	test( '[STD-02] hand-entered commissions and payouts work', async () => {
		const manual = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 8,
			source: 'manual',
			status: 'unpaid',
		} );
		expect( manual.status ).toBe( 201 );

		// The WooCommerce origin stays listed; its reference is kept as given.
		const woo = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 1,
			source: 'woocommerce',
			order_id: 987654,
			status: 'pending',
		} );
		expect( woo.status ).toBe( 201 );
		expect( woo.body.order_id ).toBe( 987654 );

		const payout = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( payout.status, JSON.stringify( payout.body ) ).toBe( 201 );
		expect( cents( payout.body.total ) ).toBe( 800 );
		const paid = await api.put(
			`/payouts/${ payout.body.payouts[ 0 ].id }`,
			{ status: 'paid' }
		);
		expect( paid.body.status ).toBe( 'paid' );
	} );

	test( '[STD-02] referral links still track', async ( { browser } ) => {
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		const before = affiliateVisits( aff.id ).length;
		await ( await guest.newPage() ).goto( `/?affiliate=${ aff.id }&std=1` );
		expect( affiliateVisits( aff.id ).length ).toBe( before + 1 );
		await guest.close();
	} );

	test( '[STD-02] the affiliate dashboard works', async ( { browser } ) => {
		const { context } = await loginAs( browser, aff );
		const page = await context.newPage();
		await page.goto( pageUrls().dashboard );
		await expect(
			page.getByTestId( 'flyaffiliate-dashboard' )
		).toBeVisible();
		await context.close();
	} );

	test( '[STD-04][SET-11] currency uses the built-in list; the WooCommerce subpage says it is off', async ( {
		page,
	} ) => {
		const currencies = php< string[] >( `
			foreach ( flyaffiliate()->settings->get_registry()->get_schema() as $e ) { if ( ( $e['id'] ?? '' ) === 'currency' ) { return array_column( $e['options'] ?? [], 'value' ); } }
			return [];
		` );
		expect( currencies ).toContain( 'USD' );

		const settings = new SettingsPage( page );
		await settings.open();
		await settings.navigate( 'integrations', 'woocommerce' );
		await expect(
			page.getByText( /WooCommerce is not active on this site/ )
		).toBeVisible();
	} );

	test( '[STD-05] with WooCommerce back, a pending order commission follows its order again', () => {
		wp( [ 'plugin', 'activate', 'woocommerce' ] );
		setOrderStatus( order, 'processing' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'unpaid' );
	} );
} );
