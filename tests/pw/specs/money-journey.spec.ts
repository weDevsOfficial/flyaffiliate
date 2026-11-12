/**
 * The whole money loop through real screens: a referral click, a block
 * checkout, the order's life, a payout, and the affiliate's dashboard.
 * If only one e2e test may run before a release, it is this one.
 *
 * ADR-0005, ADR-0012, ADR-0014; CONTEXT.md money rules 1–9.
 * Scenario: references/scenarios.md "End-to-end money journey".
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	affiliateVisits,
	createAffiliate,
	loginAs,
	orderCommissions,
	pageUrls,
	placeBlockOrder,
	refireStatus,
	setOrderStatus,
	withSettings,
	type TestAffiliate,
} from '../utils/fixtures';
import { urls } from '../utils/site';
import { lit, php } from '../utils/wp';

test.describe.configure( { mode: 'serial' } );

test.describe( 'Money journey @money @smoke', () => {
	let store: { comingSoon: string; cod: unknown };
	let aff: TestAffiliate;
	let product: number;
	let checkout: string;
	let order: number;
	let api: Api;

	withSettings( {
		hold_days: 0,
		default_rate: 10,
		max_rate: 50,
		exclude_shipping: 'on',
		exclude_tax: 'on',
		block_self_referral: 'on',
		woocommerce_enabled: 'on',
		reject_commissions_on_refund: 'on',
		referral_variable: 'affiliate',
		minimum_amount: 0,
	} );

	test.beforeAll( async () => {
		// A guest must reach the store, and pay on delivery.
		store = php( `
			$before = [ 'comingSoon' => get_option( 'woocommerce_coming_soon', 'no' ), 'cod' => get_option( 'woocommerce_cod_settings', [] ) ];
			update_option( 'woocommerce_coming_soon', 'no' );
			update_option( 'woocommerce_cod_settings', array_merge( (array) $before['cod'], [ 'enabled' => 'yes' ] ) );
			return $before;
		` );
		( { product, checkout } = php( `
			$p = new WC_Product_Simple();
			$p->set_name( 'PW journey product' );
			$p->set_regular_price( '100' );
			$p->set_virtual( true );
			$p->set_status( 'publish' );
			$id = $p->save();
			update_post_meta( $id, '_flyaffiliate_rate', 15 );
			return [ 'product' => $id, 'checkout' => wc_get_checkout_url() ];
		` ) );
		aff = createAffiliate();
		api = await Api.as();
	} );

	test.afterAll( async () => {
		php( `
			update_option( 'woocommerce_coming_soon', ${ lit(
				String( store.comingSoon )
			) } );
			update_option( 'woocommerce_cod_settings', json_decode( ${ lit(
				JSON.stringify( store.cod )
			) }, true ) ?: [] );
			return true;
		` );
		await api.dispose();
	} );

	test( 'a guest follows the link and buys through the block checkout', async ( {
		browser,
	} ) => {
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await guest.newPage();

		await page.goto( `/?affiliate=${ aff.id }` );
		expect(
			( await guest.cookies() ).some(
				( c ) => c.name === 'flyaffiliate_ref'
			)
		).toBe( true );

		await page.goto( `/?add-to-cart=${ product }` );
		await page.goto( checkout );
		await page.locator( '#email' ).fill( `buyer-${ aff.id }@example.test` );
		await page.locator( '#billing-first_name' ).fill( 'Pat' );
		await page.locator( '#billing-last_name' ).fill( 'Buyer' );
		await page.locator( '#billing-address_1' ).fill( '1 Test Street' );
		await page.locator( '#billing-city' ).fill( 'Los Angeles' );
		const state = page.locator( '#billing-state' );
		if ( ( await state.evaluate( ( el ) => el.tagName ) ) === 'SELECT' ) {
			await state.selectOption( 'CA' );
		} else {
			await state.fill( 'CA' );
		}
		await page.locator( '#billing-postcode' ).fill( '90001' );
		order = await placeBlockOrder( page );
		expect( order ).toBeGreaterThan( 0 );
		await guest.close();
	} );

	test( 'the order carries one pending commission at the product rate', () => {
		const rows = orderCommissions( order );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			affiliate_id: aff.id,
			status: 'pending',
			vendor_id: 0,
			rate: 15,
		} );
		expect( cents( rows[ 0 ].amount ) ).toBe( 1500 );
		expect( rows[ 0 ].matures_at ).toBe( rows[ 0 ].created_at );

		const [ visit ] = affiliateVisits( aff.id );
		expect( Number( visit.converted ) ).toBe( 1 );
		expect( Number( visit.order_id ) ).toBe( order );
	} );

	test( 'cash on delivery: processing waits, completed approves, a repeat changes nothing', () => {
		setOrderStatus( order, 'processing' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'pending' );

		setOrderStatus( order, 'completed' );
		const approved = orderCommissions( order );
		expect( approved[ 0 ].status ).toBe( 'unpaid' );

		refireStatus( order, 'processing', 'completed' );
		expect( orderCommissions( order ) ).toEqual( approved );
	} );

	test( 'the admin previews and records the payout on screen', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.newPayout );
		await expect( admin.heading ).toHaveText( 'New payout' );
		await page.locator( '#flyaffiliate-payout-minimum' ).fill( '0' );
		await page
			.locator( '#flyaffiliate-payout-note' )
			.fill( `PW journey ${ aff.id }` );
		await page.getByTestId( 'flyaffiliate-payout-preview' ).click();

		const table = page.getByTestId( 'flyaffiliate-payout-preview-table' );
		await expect( table ).toBeVisible();
		await expect( table ).toContainText( '$15.00' );

		await page.getByTestId( 'flyaffiliate-payout-confirm' ).click();
		await admin.expectToast( /Payout created/ );
		await expect( page ).toHaveURL( /#\/payouts\/batch\// );

		// The screen pays everyone owed; keep only this affiliate's payment so
		// the rest of the suite finds the other commissions where they were.
		const batchKey = decodeURIComponent(
			page.url().split( '#/payouts/batch/' )[ 1 ]
		);
		const payments = (
			await api.get( '/payouts', { batch_key: batchKey, per_page: 100 } )
		).body;
		for ( const payment of payments ) {
			if ( payment.affiliate_id !== aff.id ) {
				expect(
					( await api.del( `/payouts/${ payment.id }` ) ).status
				).toBe( 200 );
			}
		}

		const [ row ] = orderCommissions( order );
		expect( row.status ).toBe( 'unpaid' );
		expect( row.payout_id ).toBeGreaterThan( 0 );
	} );

	test( 'a cancelled order cannot touch a commission a payment holds', () => {
		setOrderStatus( order, 'cancelled' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'unpaid' );
	} );

	test( 'marking the payment paid pays the commission once', async () => {
		const paymentId = orderCommissions( order )[ 0 ].payout_id;
		const first = await api.put( `/payouts/${ paymentId }`, {
			status: 'paid',
		} );
		expect( first.status ).toBe( 200 );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'paid' );

		const again = await api.put( `/payouts/${ paymentId }`, {
			status: 'paid',
		} );
		expect( again.body.paid_at ).toBe( first.body.paid_at );
		expect( cents( again.body.amount ) ).toBe( 1500 );
	} );

	test( 'the affiliate sees the money on their dashboard', async ( {
		browser,
	} ) => {
		const { context, state } = await loginAs( browser, aff );
		const me = await Api.as( state );

		const payouts = ( await me.get( '/me/payouts' ) ).body;
		expect(
			payouts.some(
				( p: { status: string; amount: number } ) =>
					p.status === 'paid' && cents( p.amount ) === 1500
			)
		).toBe( true );

		const page = await context.newPage();
		await page.goto( pageUrls().dashboard );
		await expect(
			page
				.locator( '[data-slot="card"]' )
				.filter( { hasText: /^Paid/ } )
				.first()
		).toContainText( '$15.00' );

		await me.dispose();
		await context.close();
	} );

	test( 'a refund after payout reverses nothing', () => {
		setOrderStatus( order, 'refunded' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'paid' );
	} );
} );
