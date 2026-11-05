/**
 * The admin shell around the app: other plugins' notices, the admin
 * capability filter, and the settings save path refusing bad values.
 *
 * ADR-0017 (other plugins' notices hidden on FlyAffiliate's screen only),
 * ADR-0008 (the `flyaffiliate_admin_capability` filter), ADR-0010 (one save
 * path; a failed validation stores nothing).
 * A temporary mu-plugin plays "another plugin"; it is removed afterwards.
 * Scenario ids: references/scenarios.md §9–11 (SHL-*, SEC-03, SET-*).
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api } from '../utils/api';
import { createUser, loginAs } from '../utils/fixtures';
import { urls } from '../utils/site';
import { lit, php } from '../utils/wp';

const MU = `<?php
// Written by FlyAffiliate's Playwright suite (admin-shell.spec.ts); safe to delete.
add_action( 'admin_notices', function () {
	echo '<div class="notice notice-warning pw-foreign-notice"><p>PW foreign notice</p></div>';
} );
if ( get_option( 'pw_flyaffiliate_cap_filter' ) ) {
	add_filter( 'flyaffiliate_admin_capability', function () { return 'manage_woocommerce'; } );
}
`;

test.describe( 'Admin shell', () => {
	test.beforeAll( () => {
		php( `
			wp_mkdir_p( WPMU_PLUGIN_DIR );
			file_put_contents( WPMU_PLUGIN_DIR . '/pw-flyaffiliate-fixture.php', ${ lit(
				MU
			) } );
			return true;
		` );
	} );

	test.afterAll( () => {
		php( `
			@unlink( WPMU_PLUGIN_DIR . '/pw-flyaffiliate-fixture.php' );
			delete_option( 'pw_flyaffiliate_cap_filter' );
			return true;
		` );
	} );

	test( "[SHL-05] another plugin's notice is hidden on FlyAffiliate's screen only", async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.affiliates );
		await expect( page.locator( '.pw-foreign-notice' ) ).toHaveCount( 1 );
		await expect( page.locator( '.pw-foreign-notice' ) ).toBeHidden();

		await page.goto( urls.plugins );
		await expect( page.locator( '.pw-foreign-notice' ) ).toBeVisible();
	} );

	test( '[SHL-07] the screen carries exactly one .wp-header-end', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.dashboard );
		await expect( page.locator( '.wp-header-end' ) ).toHaveCount( 1 );
	} );

	test( '[SEC-03] the capability filter lets shop managers in', async ( {
		browser,
	} ) => {
		const manager = createUser( 'shop_manager' );
		const { context, state } = await loginAs( browser, manager );
		const api = await Api.as( state );

		try {
			expect( ( await api.get( '/affiliates' ) ).status ).toBe( 403 );

			php(
				`update_option( 'pw_flyaffiliate_cap_filter', 1 ); return true;`
			);
			expect( ( await api.get( '/affiliates' ) ).status ).toBe( 200 );

			const page = await context.newPage();
			await page.goto( urls.affiliates, {
				waitUntil: 'domcontentloaded',
			} );
			await expect(
				page.locator( '#flyaffiliate-admin-app' )
			).toBeVisible();
		} finally {
			php(
				`delete_option( 'pw_flyaffiliate_cap_filter' ); return true;`
			);
			await api.dispose();
			await context.close();
		}
	} );
} );

test.describe( 'Settings save path', () => {
	let api: Api;

	test.beforeAll( async () => {
		api = await Api.as();
	} );

	test.afterAll( async () => api.dispose() );

	const stored = ( id: string ) =>
		php(
			`$s = (array) get_option( 'flyaffiliate_settings', [] ); return $s[ ${ lit(
				id
			) } ] ?? null;`
		);

	for ( const [ scope, id, bad ] of [
		[ 'tracking', 'referral_variable', '' ],
		[ 'tracking', 'cookie_duration', -1 ],
		[ 'rules', 'hold_days', -5 ],
		[ 'rates', 'max_rate', 150 ],
		[ 'payout_rules', 'minimum_amount', -10 ],
	] as const ) {
		test( `[SET-04][SET-05] ${ id } = ${ JSON.stringify(
			bad
		) } is refused and nothing is stored`, async () => {
			const before = stored( id );
			const res = await api.put( `/settings/${ scope }`, {
				values: { [ id ]: bad },
			} );

			expect( res.status, JSON.stringify( res.body ) ).toBe( 400 );
			expect( stored( id ) ).toEqual( before );
		} );
	}

	test( '[SET-02][SET-07] a valid save persists, and switches store on/off', async () => {
		const before = stored( 'block_self_referral' );
		const res = await api.put( '/settings/rules', {
			values: { block_self_referral: 'off' },
		} );
		expect( res.status ).toBe( 200 );
		expect( stored( 'block_self_referral' ) ).toBe( 'off' );

		await api.put( '/settings/rules', {
			values: { block_self_referral: before ?? 'on' },
		} );
	} );
} );
