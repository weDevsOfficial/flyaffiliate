/**
 * Release-critical paths the other specs leave out: who may download a
 * payout CSV (bank data), an order coming back from the trash, and what
 * deactivating or deleting the plugin does to the owner's data.
 *
 * Scenario ids: references/scenarios.md SEC-08, SYN-07, STD-06/07/08.
 */
import { expect, test, type APIRequestContext } from '@playwright/test';
import { Api } from '../utils/api';
import {
	createAffiliate,
	createOrder,
	createProduct,
	createUser,
	loginAs,
	orderCommissions,
	restoreSettings,
	setOrderStatus,
	setSettings,
	trashOrder,
	withSettings,
} from '../utils/fixtures';
import { urls } from '../utils/site';
import { php, wp } from '../utils/wp';

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8888';

test.describe( 'Payout CSV download [SEC-08] @money', () => {
	withSettings( { hold_days: 0, minimum_amount: 0, method: 'manual' } );

	let api: Api;
	let csvUrl: string;
	let email: string;
	let batchKey: string;

	test.beforeAll( async () => {
		api = await Api.as();
		const aff = createAffiliate();
		email = aff.email;
		const made = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 12.34,
			source: 'manual',
			status: 'unpaid',
		} );
		expect( made.status ).toBe( 201 );
		const res = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
			note: 'PW csv',
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
		batchKey = res.body.batch_key;
	} );

	test.afterAll( async () => {
		await api.del( `/payouts/batch/${ batchKey }` );
		await api.dispose();
	} );

	const get = ( request: APIRequestContext, url: string ) =>
		request.get( url, { maxRedirects: 0, failOnStatusCode: false } );

	test( 'the administrator downloads the batch as CSV through the page link', async ( {
		page,
	} ) => {
		await page.goto( `${ urls.app }#/payouts/batch/${ batchKey }` );
		const link = page.getByTestId( 'flyaffiliate-batch-csv' );
		await expect( link ).toHaveAttribute( 'href', /_wpnonce=/ );
		csvUrl = new URL(
			( await link.getAttribute( 'href' ) ) as string,
			BASE_URL
		).toString();

		const res = await get( page.request, csvUrl );
		expect( res.status() ).toBe( 200 );
		expect( res.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		const body = await res.text();
		expect( body ).toContain( email );
		expect( body ).toContain( '12.34' );
	} );

	test( 'no nonce, a forged nonce, or another batch key gets no CSV', async ( {
		page,
	} ) => {
		test.skip( ! csvUrl, 'needs the link from the first test' );
		const noNonce = new URL( csvUrl );
		noNonce.searchParams.delete( '_wpnonce' );
		const forged = new URL( csvUrl );
		forged.searchParams.set( '_wpnonce', 'deadbeef00' );
		const unknown = new URL( csvUrl );
		unknown.searchParams.set( 'batch', 'pw-no-such-batch' );

		for ( const [ url, status ] of [
			[ noNonce, 403 ],
			[ forged, 403 ],
			[ unknown, 404 ],
		] as const ) {
			const res = await get( page.request, url.toString() );
			expect( res.status(), url.search ).toBe( status );
			expect( await res.text() ).not.toContain( email );
		}
	} );

	test( 'a guest, a subscriber and an affiliate get no CSV, even with the link', async ( {
		browser,
		playwright,
	} ) => {
		test.skip( ! csvUrl, 'needs the link from the first test' );

		const guest = await playwright.request.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const asGuest = await get( guest, csvUrl );
		expect( await asGuest.text() ).not.toContain( email );
		expect( asGuest.headers()[ 'content-type' ] ?? '' ).not.toContain(
			'text/csv'
		);
		await guest.dispose();

		for ( const user of [ createUser(), createAffiliate() ] ) {
			const { context } = await loginAs( browser, user );
			const res = await get( context.request, csvUrl );
			expect( res.status(), user.login ).toBe( 403 );
			expect( await res.text() ).not.toContain( email );
			await context.close();
		}
	} );
} );

test.describe( 'An order back from the trash [SYN-07] @money', () => {
	withSettings( {
		default_rate: 10,
		max_rate: 50,
		hold_days: 0,
		woocommerce_enabled: 'on',
	} );

	test( 'a paid order trashed and restored owes its commission again', () => {
		const order = createOrder( {
			affiliateId: createAffiliate().id,
			items: [ { productId: createProduct( 100 ) } ],
			paymentMethod: 'bacs',
		} );
		setOrderStatus( order, 'processing' );
		expect( orderCommissions( order ).map( ( r ) => r.status ) ).toEqual( [
			'unpaid',
		] );

		trashOrder( order );
		expect( orderCommissions( order ).map( ( r ) => r.status ) ).toEqual( [
			'rejected',
		] );

		php( `wc_get_order( ${ order } )->untrash(); return true;` );
		expect(
			php< string >( `return wc_get_order( ${ order } )->get_status();` )
		).toBe( 'processing' );
		expect(
			orderCommissions( order ).map( ( r ) => [ r.status, r.amount ] )
		).toEqual( [ [ 'unpaid', 10 ] ] );
	} );
} );

/**
 * Each table the installer owns (`Installer::get_table_names()`, listed here
 * so it also works while the plugin is off), with whether it exists.
 */
const tables = (): Record< string, boolean > =>
	php( `
		global $wpdb;
		$out = [];
		foreach ( [ 'flyaffiliate_affiliates', 'flyaffiliate_commissions', 'flyaffiliate_visits', 'flyaffiliate_payouts', 'flyaffiliate_referral_links' ] as $t ) {
			$out[ $t ] = ( $wpdb->prefix . $t ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
		}
		return $out;
	` );

const scheduled = (): boolean =>
	php(
		`return ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'flyaffiliate_mature_commissions' ) ) || false !== wp_next_scheduled( 'flyaffiliate_mature_commissions' );`
	);

const commissionCount = (): number =>
	php(
		`global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_commissions" );`
	);

/**
 * Run the plugin's uninstall.php as WordPress does on Delete. Returns whether
 * the affiliate role is left, read in the same request: the plugin stays
 * active here, so the next request's `init` would register it again.
 */
const runUninstall = (): boolean =>
	php( `
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'flyaffiliate/flyaffiliate.php' ); }
		include WP_PLUGIN_DIR . '/flyaffiliate/uninstall.php';
		return null !== get_role( 'flyaffiliate_affiliate' );
	` );

const setClear = ( value: 'on' | 'off' ) =>
	php( `
		$s = (array) get_option( 'flyaffiliate_settings', [] );
		$s['data_clear_on_uninstall'] = '${ value }';
		update_option( 'flyaffiliate_settings', $s );
		return true;
	` );

test.describe
	.serial( 'Deactivate and delete keep or remove data as promised', () => {
	test( '[STD-06] deactivating clears the daily job and keeps every row; reactivating schedules it again', () => {
		const before = commissionCount();
		expect( Object.values( tables() ).every( Boolean ) ).toBe( true );

		try {
			wp( [ 'plugin', 'deactivate', 'flyaffiliate' ] );
			expect( scheduled() ).toBe( false );
			expect( Object.values( tables() ).every( Boolean ) ).toBe( true );
			expect( commissionCount() ).toBe( before );
		} finally {
			wp( [ 'plugin', 'activate', 'flyaffiliate' ] );
		}

		expect( scheduled() ).toBe( true );
		expect( commissionCount() ).toBe( before );
	} );

	test( '[STD-07] deleting with "clear data" off keeps tables, settings, pages and role', () => {
		setClear( 'off' );
		const before = commissionCount();

		expect( runUninstall() ).toBe( true );

		expect( Object.values( tables() ).every( Boolean ) ).toBe( true );
		expect( commissionCount() ).toBe( before );
		expect(
			php< boolean >(
				`return false !== get_option( 'flyaffiliate_settings', false ) && null !== get_role( 'flyaffiliate_affiliate' );`
			)
		).toBe( true );
	} );

	test( '[STD-08] deleting with "clear data" on removes tables, options, pages and role', () => {
		const dump = '/tmp/pw-std08.sql';
		wp( [ 'db', 'export', dump ] );

		try {
			const pages = php< number[] >(
				`return array_map( 'intval', array_values( (array) get_option( FlyAffiliate\\Install\\Installer::PAGES_OPTION, [] ) ) );`
			);
			setClear( 'on' );

			expect( runUninstall(), 'role left' ).toBe( false );

			expect( Object.values( tables() ).some( Boolean ) ).toBe( false );
			expect(
				php( `
					return [
						'settings' => get_option( 'flyaffiliate_settings', null ),
						'db_version' => get_option( FlyAffiliate\\Install\\Installer::DB_VERSION_OPTION, null ),
						'pages' => array_values( array_filter( ${ JSON.stringify(
							pages
						) }, 'get_post' ) ),
						'job' => false !== wp_next_scheduled( 'flyaffiliate_mature_commissions' ),
					];
				` )
			).toEqual( {
				settings: null,
				db_version: null,
				pages: [],
				job: false,
			} );
		} finally {
			wp( [ 'db', 'import', dump ] );
			wp( [ 'eval', `@unlink( '${ dump }' );` ] );
		}

		expect( Object.values( tables() ).every( Boolean ) ).toBe( true );
	} );
} );

test.describe( 'Tax in the commission base [WC-08] @money', () => {
	withSettings( {
		default_rate: 10,
		max_rate: 50,
		hold_days: 0,
		woocommerce_enabled: 'on',
	} );

	let rateId: number;
	let before: { calc: string; incl: string };

	test.beforeAll( () => {
		// A 10% standard rate everywhere, prices entered without tax.
		before = php( `
			$b = [ 'calc' => get_option( 'woocommerce_calc_taxes' ), 'incl' => get_option( 'woocommerce_prices_include_tax' ) ];
			update_option( 'woocommerce_calc_taxes', 'yes' );
			update_option( 'woocommerce_prices_include_tax', 'no' );
			return $b;
		` );
		rateId = php< number >(
			`return (int) WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'PW tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );`
		);
	} );

	test.afterAll( () => {
		php( `
			WC_Tax::_delete_tax_rate( ${ rateId } );
			update_option( 'woocommerce_calc_taxes', ${ JSON.stringify(
				before.calc ?? 'no'
			) } );
			update_option( 'woocommerce_prices_include_tax', ${ JSON.stringify(
				before.incl ?? 'no'
			) } );
			return true;
		` );
	} );

	for ( const [ excludeTax, base, amount ] of [
		[ 'on', 100, 10 ],
		[ 'off', 110, 11 ],
	] as const ) {
		test( `exclude_tax ${ excludeTax }: a $100 item with $10 tax earns on $${ base }`, () => {
			const snapshot = setSettings( { exclude_tax: excludeTax } );

			try {
				const order = createOrder( {
					affiliateId: createAffiliate().id,
					items: [ { productId: createProduct( 100 ) } ],
				} );
				expect(
					Number(
						php(
							`return wc_get_order( ${ order } )->get_total_tax();`
						)
					),
					'the order carries the tax'
				).toBe( 10 );
				const rows = orderCommissions( order );
				expect( rows ).toHaveLength( 1 );
				expect( rows[ 0 ] ).toMatchObject( {
					base_amount: base,
					amount,
				} );
			} finally {
				restoreSettings( snapshot );
			}
		} );
	}
} );

test.describe( 'Welcome email [AFF-04]', () => {
	let api: Api;

	test.beforeAll( async () => {
		api = await Api.as();
	} );

	test.afterAll( async () => {
		await api.dispose();
	} );

	const mailsTo = ( email: string ) =>
		php< Array< { to: string; subject: string; message: string } > >(
			`return array_values( array_filter( (array) get_option( 'pw_mail_log', [] ), fn ( $m ) => $m['to'] === ${ JSON.stringify(
				email
			) } ) );`
		);

	test( 'sent with the referral link when ticked, and only then', async () => {
		const ticked = createUser();
		const res = await api.post( '/affiliates', {
			user_id: ticked.userId,
			status: 'active',
			send_welcome_email: true,
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
		const mails = mailsTo( ticked.email );
		expect( mails ).toHaveLength( 1 );
		expect( mails[ 0 ].subject ).toContain( 'affiliate programme' );
		expect( mails[ 0 ].message ).toMatch(
			new RegExp( `[?&]affiliate=${ res.body.id }\\b` )
		);

		const unticked = createUser();
		const quiet = await api.post( '/affiliates', {
			user_id: unticked.userId,
			status: 'active',
		} );
		expect( quiet.status ).toBe( 201 );
		expect( mailsTo( unticked.email ) ).toHaveLength( 0 );
	} );

	test( 'the Add affiliate dialog sends it when its box is ticked', async ( {
		page,
	} ) => {
		const user = createUser();
		await page.goto( `${ urls.affiliates }` );
		await page
			.getByRole( 'button', { name: /Add affiliate/ } )
			.first()
			.click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByRole( 'combobox' ).first().click();
		await page.getByPlaceholder( 'Search users…' ).fill( user.login );
		await page
			.getByRole( 'option', { name: new RegExp( user.login ) } )
			.first()
			.click();
		await dialog.getByText( /welcome email/i ).click();
		await dialog.getByRole( 'button', { name: /Add affiliate/ } ).click();
		await expect.poll( () => mailsTo( user.email ).length ).toBe( 1 );
	} );
} );

test.describe( 'Switch to Manual on the payout preview [PAY-27] @money', () => {
	withSettings( { hold_days: 0, minimum_amount: 0, method: 'manual' } );

	let api: Api;
	let aff: ReturnType< typeof createAffiliate >;
	let batchKey = '';

	test.beforeAll( async () => {
		api = await Api.as();
		php( `update_option( 'pw_bank_on', 1, false ); return true;` );
		aff = createAffiliate();
		php(
			`flyaffiliate()->affiliate->update( ${ aff.id }, [ 'payout_method' => 'pwbank' ] ); return true;`
		);
		const made = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 7.5,
			source: 'manual',
			status: 'unpaid',
		} );
		expect( made.status ).toBe( 201 );
	} );

	test.afterAll( async () => {
		if ( batchKey ) {
			await api.del( `/payouts/batch/${ batchKey }` );
		}
		php( `delete_option( 'pw_bank_on' ); return true;` );
		await api.dispose();
	} );

	test( 'an affiliate their method cannot pay is warned, switched to Manual, and paid by hand', async ( {
		page,
	} ) => {
		const rest = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( rest.body.rows[ 0 ] ).toMatchObject( {
			method: 'pwbank',
			warning: 'PW Bank account missing.',
		} );

		await page.goto( urls.newPayout );
		await page.locator( '#flyaffiliate-payout-minimum' ).fill( '0' );
		await page.getByTestId( 'flyaffiliate-payout-preview' ).click();
		const row = page.getByTestId( `flyaffiliate-payout-row-${ aff.id }` );
		await expect( row.getByRole( 'alert' ) ).toContainText(
			'PW Bank account missing.'
		);

		await row.getByRole( 'button', { name: 'Switch to Manual' } ).click();
		await expect( row.getByRole( 'alert' ) ).toHaveCount( 0 );
		await expect(
			page
				.getByTestId( 'flyaffiliate-payout-group-manual' )
				.or( page.getByTestId( 'flyaffiliate-payout-group-pwbank' ) )
				.first()
		).toBeVisible();

		// Pay this affiliate only: untick everyone, tick this row, and check
		// the counter says so before paying.
		const counter = page.getByText( /\d+ of \d+ selected/ );
		// Partly ticked (rows with warnings start unticked): a click ticks all,
		// the next unticks all.
		for (
			let i = 0;
			i < 3 && ! /^0 of /.test( await counter.innerText() );
			i++
		) {
			await page.getByTestId( 'flyaffiliate-payout-select-all' ).click();
		}
		await expect( counter ).toHaveText( /^0 of / );
		await row.getByRole( 'checkbox' ).click();
		await expect( counter ).toHaveText( /^1 of / );
		await page.getByTestId( 'flyaffiliate-payout-confirm' ).click();
		await expect( page ).toHaveURL( /#\/payouts\/batch\// );
		batchKey = decodeURIComponent(
			page.url().split( '#/payouts/batch/' )[ 1 ]
		);

		// One payment, to this affiliate, paid by hand, for the whole 7.50.
		const batch = ( await api.get( `/payouts/batch/${ batchKey }` ) ).body;
		expect( batch ).toMatchObject( {
			affiliates: 1,
			total: 7.5,
			methods: [ { id: 'manual' } ],
		} );
		expect( batch.counts.manual_unpaid ).toBe( 1 );
	} );
} );
