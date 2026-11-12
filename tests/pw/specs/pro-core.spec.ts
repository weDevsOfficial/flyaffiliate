/**
 * FlyAffiliate Pro: how it boots on FlyAffiliate, the Extensions registry
 * and its one gate (the license), and who may reach Pro's routes.
 *
 * Pro ADR-0001 (bootstrap on FlyAffiliate), ADR-0002 (extensions vs
 * features), ADR-0003 (licensing: the license is the one gate), ADR-0004
 * (what an extension declares), ADR-0005 (the Extensions page).
 *
 * Needs Pro's paired environment; skips itself anywhere else:
 *
 *   FLYAFFILIATE_WP_ENV_DIR=~/flyaffiliate-wt/<branch>/flyaffiliate-pro \
 *   BASE_URL=http://localhost:8900 FLYAFFILIATE_SKIP_SEED=1 \
 *   npm run test:e2e -- specs/pro-core.spec.ts
 *
 * Scenario ids: references/scenarios.md §16 (PRO-*).
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api } from '../utils/api';
import { createAffiliate, createUser, loginAs } from '../utils/fixtures';
import {
	dropLicense,
	preparePro,
	proReady,
	restorePro,
	setLicense,
	switchExtension,
	type ProSnapshot,
} from '../utils/pro';
import { urls } from '../utils/site';
import { php, wp } from '../utils/wp';

const READY = proReady();

test.describe.configure( { mode: 'serial' } );

test.describe( 'FlyAffiliate Pro core @pro', () => {
	test.skip( ! READY, 'needs FlyAffiliate Pro (Pro paired env)' );

	let snapshot: ProSnapshot;
	let api: Api;

	test.beforeAll( async () => {
		snapshot = preparePro();
		api = await Api.as();
	} );

	test.afterAll( () => restorePro( snapshot ) );

	// ── Boot (ADR-0001) ───────────────────────────────────────────────────

	test( '[PRO-BOOT-01] Pro boots on FlyAffiliate: FlyAffiliate knows Pro is there, and the admin app carries Pro', async ( {
		page,
	} ) => {
		expect( php( `return flyaffiliate_is_pro_active();` ) ).toBe( true );
		expect(
			php( `return did_action( 'flyaffiliate_pro_loaded' ) > 0;` )
		).toBe( true );

		const admin = new AdminPage( page );
		await admin.goto( urls.dashboard );
		const pro = await page.evaluate(
			() =>
				(
					window as unknown as {
						flyaffiliate?: { pro?: { version?: string } };
					}
				 ).flyaffiliate?.pro
		);
		expect( pro?.version ).toBeTruthy();
	} );

	test( '[PRO-BOOT-02] without FlyAffiliate, Pro shows one notice and does nothing else', async ( {
		page,
	} ) => {
		wp( [ 'plugin', 'deactivate', 'flyaffiliate' ] );

		try {
			const res = await page.goto( urls.plugins );
			expect( res?.status() ).toBe( 200 );
			await expect(
				page.getByText(
					'FlyAffiliate Pro needs the FlyAffiliate plugin to be installed and active.'
				)
			).toHaveCount( 1 );
			// Nothing of Pro's registered: its REST namespace is not there.
			const route = await (
				await Api.as()
			).get( '/flyaffiliate-pro/v1/extensions' );
			expect( route.status ).toBe( 404 );
		} finally {
			wp( [ 'plugin', 'activate', 'flyaffiliate' ] );
		}

		expect( php( `return flyaffiliate_is_pro_active();` ) ).toBe( true );
	} );

	// ── The registry (ADR-0002, ADR-0004) ────────────────────────────────

	test( '[PRO-EXT-01] the registry lists every built-in extension, each with its category and state', async () => {
		const res = await api.get( '/flyaffiliate-pro/v1/extensions' );
		expect( res.status ).toBe( 200 );

		const byId = Object.fromEntries(
			( res.body as { id: string }[] ).map( ( e ) => [ e.id, e ] )
		) as unknown as Record<
			string,
			{
				category: string;
				active: boolean;
				running: boolean;
				requirements: string[];
				available: boolean;
			}
		>;
		// Dokan's extension is available only with Dokan running; without it the
		// registry says what is missing (a free + Pro site without Dokan).
		const dokan = php< boolean >( `return function_exists( 'dokan' );` );
		expect( byId.dokan ).toMatchObject( {
			category: 'integration',
			available: dokan,
		} );
		expect( byId.dokan.requirements ).toEqual(
			dokan ? [] : [ 'Dokan plugin is required for this extension.' ]
		);
		expect( byId.paypal ).toMatchObject( {
			category: 'payout_method',
			requirements: [],
			available: true,
		} );
	} );

	test( '[PRO-EXT-02] switching on loads an extension at once; switching off unloads its routes', async () => {
		expect( ( await switchExtension( api, 'paypal', true ) ).status ).toBe(
			200
		);
		const on = ( await api.get( '/flyaffiliate-pro/v1/extensions/paypal' ) )
			.body;
		expect( on ).toMatchObject( { active: true, running: true } );
		expect(
			( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) ).status
		).toBe( 200 );

		expect( ( await switchExtension( api, 'paypal', false ) ).status ).toBe(
			200
		);
		expect(
			( await api.get( '/flyaffiliate-pro/v1/extensions/paypal' ) ).body
		).toMatchObject( { active: false } );
		expect(
			( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) ).status
		).toBe( 404 );
		expect(
			php(
				`return in_array( 'paypal', (array) get_option( 'flyaffiliate_pro_active_extensions', [] ), true );`
			)
		).toBe( false );
	} );

	test( '[PRO-EXT-03] an unknown extension id is refused', async () => {
		const res = await switchExtension( api, 'no-such-extension', true );
		expect( res.status ).toBeGreaterThanOrEqual( 400 );
		expect(
			php(
				`return in_array( 'no-such-extension', (array) get_option( 'flyaffiliate_pro_active_extensions', [] ), true );`
			)
		).toBe( false );
	} );

	test( '[PRO-EXT-04] an extension whose platform is missing cannot be switched on', async () => {
		await switchExtension( api, 'dokan', false );
		// On a site without Dokan the platform is already missing.
		const hadDokan = php< boolean >( `return function_exists( 'dokan' );` );
		if ( hadDokan ) {
			wp( [ 'plugin', 'deactivate', 'dokan-pro', 'dokan-lite' ] );
		}

		try {
			const dokan = (
				await api.get( '/flyaffiliate-pro/v1/extensions/dokan' )
			).body;
			expect( dokan.available ).toBe( false );
			expect( dokan.requirements.length ).toBeGreaterThan( 0 );

			const res = await switchExtension( api, 'dokan', true );
			expect( res.status ).toBeGreaterThanOrEqual( 400 );
		} finally {
			if ( hadDokan ) {
				wp( [ 'plugin', 'activate', 'dokan-lite', 'dokan-pro' ] );
			}
		}

		expect(
			( await api.get( '/flyaffiliate-pro/v1/extensions/dokan' ) ).body
				.available
		).toBe( hadDokan );
	} );

	// ── The license is the one gate (ADR-0003) ────────────────────────────

	test( '[PRO-LIC-01] without a valid license nothing switches on, and a switched-on extension stops running', async () => {
		await switchExtension( api, 'paypal', true );
		const license = dropLicense();

		try {
			const listed = (
				await api.get( '/flyaffiliate-pro/v1/extensions/paypal' )
			).body;
			expect( listed ).toMatchObject( { active: true, running: false } );
			expect(
				( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) )
					.status
			).toBe( 404 );

			await switchExtension( api, 'paypal', false );
			const refused = await switchExtension( api, 'paypal', true );
			expect( refused.status ).toBeGreaterThanOrEqual( 400 );
			expect( JSON.stringify( refused.body ) ).toContain(
				'flyaffiliate_pro_license_required'
			);
		} finally {
			setLicense( license );
			await switchExtension( api, 'paypal', false );
		}
	} );

	test( '[PRO-LIC-02] the license status masks the key; activation needs a key, deactivation needs one stored', async () => {
		const status = await api.get( '/flyaffiliate-pro/v1/license' );
		expect( status.status ).toBe( 200 );
		expect( status.body.is_valid ).toBe( true );
		expect( status.body.key ).toMatch( /^pw-local-test-lice\*+$/ );
		expect( JSON.stringify( status.body ) ).not.toContain( '0123456789' );

		const empty = await api.post( '/flyaffiliate-pro/v1/license/activate', {
			key: '',
		} );
		expect( empty.status ).toBe( 400 );

		const license = dropLicense();
		try {
			const none = await api.post(
				'/flyaffiliate-pro/v1/license/deactivate',
				{}
			);
			expect( none.status ).toBe( 400 );
			expect( JSON.stringify( none.body ) ).toContain(
				'flyaffiliate_pro_license_key_missing'
			);
		} finally {
			setLicense( license );
		}
	} );

	// ── Who may reach Pro's routes ────────────────────────────────────────

	test( '[PRO-SEC-01] the extensions and license routes answer administrators only', async ( {
		browser,
	} ) => {
		const routes = [
			'/flyaffiliate-pro/v1/extensions',
			'/flyaffiliate-pro/v1/license',
		];

		const guest = await Api.as( null );
		for ( const route of routes ) {
			expect(
				( await guest.get( route ) ).status,
				`guest ${ route }`
			).toBe( 401 );
		}
		expect(
			(
				await guest.put( '/flyaffiliate-pro/v1/extensions/paypal', {
					active: true,
				} )
			).status
		).toBe( 401 );

		for ( const who of [
			createAffiliate(),
			createUser( 'shop_manager' ),
		] ) {
			const { context, state } = await loginAs( browser, who );
			const client = await Api.as( state );
			for ( const route of routes ) {
				expect(
					( await client.get( route ) ).status,
					`${ who.login } ${ route }`
				).toBe( 403 );
			}
			expect(
				(
					await client.put(
						'/flyaffiliate-pro/v1/extensions/paypal',
						{ active: true }
					)
				).status
			).toBe( 403 );
			await context.close();
		}

		expect(
			( await api.get( '/flyaffiliate-pro/v1/extensions/paypal' ) ).body
				.active
		).toBe( false );
	} );

	// ── The screens (ADR-0005) ────────────────────────────────────────────

	test( "[PRO-UI-01] the Extensions page lists Pro's cards, and a card's switch saves at once", async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/extensions` );

		const card = page
			.locator( '[data-slot="card"]' )
			.filter( { hasText: 'PayPal Payouts' } )
			.first();
		await expect( card ).toBeVisible();
		await expect(
			page
				.locator( '[data-slot="card"]' )
				.filter( { hasText: 'Dokan' } )
				.first()
		).toBeVisible();

		// apiFetch sends a PUT as POST + X-HTTP-Method-Override.
		const saved = ( active: boolean ) =>
			page.waitForResponse(
				( r ) =>
					r.request().method() !== 'GET' &&
					decodeURIComponent( r.url() ).includes(
						'flyaffiliate-pro/v1/extensions/paypal'
					) &&
					( r.request().postData() ?? '' ).includes(
						String( active )
					)
			);

		await Promise.all( [
			saved( true ),
			card.getByRole( 'switch' ).click(),
		] );
		expect(
			( await api.get( '/flyaffiliate-pro/v1/extensions/paypal' ) ).body
				.active
		).toBe( true );

		await Promise.all( [
			saved( false ),
			card.getByRole( 'switch' ).click(),
		] );
		expect(
			( await api.get( '/flyaffiliate-pro/v1/extensions/paypal' ) ).body
				.active
		).toBe( false );
	} );

	test( '[PRO-UI-02] without a license the page offers Upgrade, and a switch asks for the license instead', async ( {
		page,
	} ) => {
		const license = dropLicense();

		try {
			const admin = new AdminPage( page );
			await admin.goto( `${ urls.app }#/extensions` );
			await expect(
				page.getByTestId( 'flyaffiliate-pro-extensions-upgrade' )
			).toBeVisible();

			const stored = () =>
				php(
					`return array_values( (array) get_option( 'flyaffiliate_pro_active_extensions', [] ) );`
				);
			const before = stored();
			await page
				.locator( '[data-slot="card"]' )
				.filter( { hasText: 'PayPal Payouts' } )
				.first()
				.getByRole( 'switch' )
				.click();
			await expect(
				page.getByTestId( 'flyaffiliate-pro-extensions-license-dialog' )
			).toBeVisible();
			// The dialog asks for the license; nothing was switched.
			expect( stored() ).toEqual( before );
		} finally {
			setLicense( license );
		}
	} );

	test( '[PRO-UI-03] the License page shows the masked key', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/license` );
		const panel = page.getByTestId( 'flyaffiliate-pro-license-panel' );
		await expect( panel ).toBeVisible();
		await expect( panel ).toContainText( 'Active' );
		// The key is in a read-only field, masked after its first 18 characters.
		await expect(
			page.locator( 'input[value^="pw-local-test-lice"]' )
		).toHaveValue( /^pw-local-test-lice\*+$/ );
	} );
} );
