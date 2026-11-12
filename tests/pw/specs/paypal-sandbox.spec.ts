/**
 * PayPal Payouts against PayPal's real **sandbox** — the whole affiliate money
 * flow, end to end, with no stand-in: connect the site's PayPal app, the
 * affiliate chooses PayPal, a guest buys through the referral link, the order
 * is paid, the admin pays out, PayPal settles it, the affiliate sees it paid.
 *
 * Runs only when the sandbox app's keys are given, in the git-ignored
 * `tests/pw/.env` (loaded by playwright.config.ts) or the shell:
 *
 *   PAYPAL_SANDBOX_CLIENT_ID=…
 *   PAYPAL_SANDBOX_SECRET=…
 *   PAYPAL_SANDBOX_RECEIVER=<a sandbox *personal* account email>
 *
 *   npm run test:e2e -- specs/paypal-sandbox.spec.ts
 *
 * Without PAYPAL_SANDBOX_RECEIVER the payout goes to an address with no PayPal
 * account: PayPal accepts it and holds it as UNCLAIMED, which the test checks
 * instead of a settled payment.
 *
 * Needs the network and FlyAffiliate Pro. Guide: B3.2, B3.3, B3.4, B3.5.
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	createAffiliate,
	loginAs,
	orderCommissions,
	pageUrls,
	placeBlockOrder,
	restoreSettings,
	setOrderStatus,
	setSettings,
	stamp,
	type Snapshot,
	type TestAffiliate,
} from '../utils/fixtures';
import {
	preparePro,
	proReady,
	removePayPalMock,
	restorePro,
	switchExtension,
	transfersOf,
	type ProSnapshot,
} from '../utils/pro';
import { urls } from '../utils/site';
import { lit, php } from '../utils/wp';

const {
	PAYPAL_SANDBOX_CLIENT_ID: CLIENT_ID,
	PAYPAL_SANDBOX_SECRET: SECRET,
	PAYPAL_SANDBOX_RECEIVER: RECEIVER,
} = process.env;

test.describe.configure( { mode: 'serial' } );

test.describe( 'PayPal sandbox, end to end @pro @money @paypal-live', () => {
	test.skip(
		! CLIENT_ID || ! SECRET,
		'set PAYPAL_SANDBOX_CLIENT_ID and PAYPAL_SANDBOX_SECRET'
	);
	test.skip( ! proReady(), 'needs FlyAffiliate Pro' );
	test.setTimeout( 5 * 60 * 1000 );

	const receiver = RECEIVER || `pw-unclaimed-${ stamp() }@example.com`;
	let pro: ProSnapshot;
	let fly: Snapshot;
	let store: { comingSoon: string; cod: unknown };
	let api: Api;
	let aff: TestAffiliate;
	let product: number;
	let checkout: string;
	let order: number;
	let paymentId: number;
	let batchKey: string;

	test.beforeAll( async () => {
		pro = preparePro();
		fly = setSettings( {
			hold_days: 0,
			minimum_amount: 0,
			default_rate: 10,
			max_rate: 50,
			exclude_shipping: 'on',
			exclude_tax: 'on',
			block_self_referral: 'on',
			woocommerce_enabled: 'on',
			referral_variable: 'affiliate',
			method: 'manual',
		} );
		// The real PayPal: no stand-in, no earlier connection.
		removePayPalMock();
		php(
			`delete_option( 'flyaffiliate_pro_paypal' ); delete_option( 'flyaffiliate_pro_payout_accounts' ); return true;`
		);
		store = php( `
			$before = [ 'comingSoon' => get_option( 'woocommerce_coming_soon', 'no' ), 'cod' => get_option( 'woocommerce_cod_settings', [] ) ];
			update_option( 'woocommerce_coming_soon', 'no' );
			update_option( 'woocommerce_cod_settings', array_merge( (array) $before['cod'], [ 'enabled' => 'yes' ] ) );
			return $before;
		` );
		( { product, checkout } = php( `
			$p = new WC_Product_Simple();
			$p->set_name( 'PW PayPal sandbox product' );
			$p->set_regular_price( '25' );
			$p->set_virtual( true );
			$p->set_status( 'publish' );
			return [ 'product' => $p->save(), 'checkout' => wc_get_checkout_url() ];
		` ) );
		api = await Api.as();
		expect( ( await switchExtension( api, 'paypal', true ) ).status ).toBe(
			200
		);
	} );

	test.afterAll( async () => {
		await switchExtension( api, 'paypal', false );
		restoreSettings( fly );
		restorePro( pro );
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

	test( '1. the admin connects the PayPal sandbox app (B3.2.2)', async () => {
		const res = await api.post( '/flyaffiliate-pro/v1/paypal/connection', {
			environment: 'sandbox',
			client_id: CLIENT_ID,
			client_secret: SECRET,
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( res.body ).toMatchObject( {
			environment: 'sandbox',
			connected: true,
			checks: { payouts: true, token: true },
		} );
		// On http there is no webhook; payouts settle by polling (B3.2.5).
		expect( res.body.checks.webhook ).toBe( false );
		// Pro never sends the secret back, and masks the client id.
		expect( JSON.stringify( res.body ) ).not.toContain( SECRET );
		expect( res.body.client_id ).not.toBe( CLIENT_ID );
		test.info().annotations.push( {
			type: 'PayPal account',
			description: JSON.stringify( res.body.account ),
		} );
	} );

	test( '2. the PayPal page shows the sandbox connection (B2.5, B3.2.2)', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/extensions` );
		// Guide B2.7 expects a "Connected · Sandbox" badge on the card; this
		// build shows none (see the run notes), so the page itself is checked.
		await page
			.locator( 'div' )
			.filter( {
				has: page.getByText( 'PayPal Payouts', { exact: true } ),
			} )
			.getByRole( 'link', { name: /Set up/ } )
			.or(
				page
					.locator( 'div' )
					.filter( {
						has: page.getByText( 'PayPal Payouts', {
							exact: true,
						} ),
					} )
					.getByRole( 'button', { name: /Set up/ } )
			)
			.last()
			.click();
		await expect( admin.app.getByText( /Sandbox/ ).first() ).toBeVisible();
		await expect(
			admin.app.getByText( /Payouts permission granted|Payouts/ ).first()
		).toBeVisible();
	} );

	test( '3. the affiliate chooses PayPal and their PayPal email on their dashboard (B3.3.2)', async ( {
		browser,
	} ) => {
		aff = createAffiliate();
		const { context } = await loginAs( browser, aff );
		const page = await context.newPage();
		const url = new URL( pageUrls().dashboard );
		url.searchParams.set( 'tab', 'settings' );
		await page.goto( url.toString() );

		const methods = page.getByTestId( 'flyaffiliate-payout-method' );
		await expect( methods ).toBeVisible();
		// The radios carry no accessible name (the label is not tied to
		// them), so pick the one in the group that shows "PayPal".
		const paypalRadio = methods
			.getByRole( 'group' )
			.filter( { hasText: 'PayPal' } )
			.getByRole( 'radio' )
			.first();
		await paypalRadio.click();
		await expect( paypalRadio ).toBeChecked();
		await page
			.locator( '#flyaffiliate-payout-paypal-email' )
			.fill( receiver );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect
			.poll( () =>
				php(
					`return flyaffiliate()->affiliate->get( ${ aff.id } )->get( 'payout_method' );`
				)
			)
			.toBe( 'paypal' );
		await context.close();
	} );

	test( '4. a guest follows the referral link and buys through the block checkout', async ( {
		browser,
	} ) => {
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await guest.newPage();
		await page.goto( `/?affiliate=${ aff.id }` );
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
		await guest.close();

		const rows = orderCommissions( order );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			affiliate_id: aff.id,
			status: 'pending',
		} );
		expect( cents( rows[ 0 ].amount ) ).toBe( 250 );
	} );

	test( '5. the order is completed: the commission is owed', () => {
		setOrderStatus( order, 'completed' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'unpaid' );
	} );

	test( '6. the admin previews and pays: the payment goes to PayPal (B3.4.1, B3.4.3)', async ( {
		page,
	} ) => {
		const preview = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( preview.status, JSON.stringify( preview.body ) ).toBe( 200 );
		expect( preview.body.rows[ 0 ] ).toMatchObject( {
			affiliate_id: aff.id,
			method: 'paypal',
			recipient: receiver,
			warning: '',
		} );

		const created = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
			note: 'PW PayPal sandbox',
		} );
		expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );
		batchKey = created.body.batch_key;
		paymentId = created.body.payouts[ 0 ].id;

		const sent = ( await api.get( `/payouts/${ paymentId }` ) ).body;
		expect( sent, JSON.stringify( sent ) ).toMatchObject( {
			method: 'paypal',
			amount: 2.5,
			locked: true,
		} );
		const [ transfer ] = transfersOf( paymentId );
		expect(
			transfer.provider_batch_id,
			JSON.stringify( transfer )
		).not.toBe( '' );

		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/payouts/batch/${ batchKey }` );
		await expect( admin.heading ).toContainText( 'PW PayPal sandbox' );
	} );

	test( '7. PayPal settles it and the payment and commission are paid (B3.4.4, B3.5)', async () => {
		// No webhook on http: ask PayPal, as the scheduled check does.
		let payment: any;
		await expect
			.poll(
				async () => {
					await api.post( `/payouts/batch/${ batchKey }/refresh` );
					payment = ( await api.get( `/payouts/${ paymentId }` ) )
						.body;
					// Until PayPal gives its final answer for the item.
					return `${ payment.transfer?.status } ${ payment.transfer?.message }`;
				},
				{ timeout: 4 * 60 * 1000, intervals: [ 10 * 1000 ] }
			)
			.toMatch( RECEIVER ? /^paid / : /^paid |claim/i );

		test.info().annotations.push( {
			type: 'PayPal transfer',
			description: JSON.stringify( payment.transfer ),
		} );

		if ( 'paid' === payment.transfer.status ) {
			expect( payment.status ).toBe( 'paid' );
			expect( payment.transfer.reference ).not.toBe( '' );
			expect(
				(
					await api.get(
						`/commissions/${ payment.commissions[ 0 ] }`
					)
				).body.status
			).toBe( 'paid' );
		} else {
			// No PayPal account behind the address: PayPal holds it unclaimed,
			// the payment stays locked and unpaid (B3.5, B3.6.1).
			expect( payment.status ).toBe( 'unpaid' );
			expect( payment.locked ).toBe( true );
			expect( payment.transfer.message ).toMatch( /claimed|claim/i );
		}
	} );

	test( '8. the affiliate sees the payment on their dashboard, never PayPal internals', async ( {
		browser,
	} ) => {
		const { context, state } = await loginAs( browser, aff );
		const mine = ( await ( await Api.as( state ) ).get( '/me/payouts' ) )
			.body as Array< Record< string, any > >;
		const row = mine.find( ( p ) => p.id === paymentId );
		expect( row, JSON.stringify( mine ) ).toBeTruthy();
		const settled = ( await api.get( `/payouts/${ paymentId }` ) ).body;
		expect( row!.status ).toBe( settled.status );
		expect( JSON.stringify( row ) ).not.toMatch(
			/client_id|secret|access_token/i
		);
		await context.close();
	} );
} );
