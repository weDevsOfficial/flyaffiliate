/**
 * What paid a commission: a commission an admin records as paid by hand must
 * not look like one a payout paid, and the admin is told, before saving one as
 * Paid, that an affiliate an automatic method pays receives nothing from it.
 *
 * FlyAffiliate Pro issue #23 (stage 1, flyaffiliate PR #23). Marking paid by
 * hand stays allowed (CONTEXT.md "Commission statuses", ADR-0012, money
 * rule 7); everything here is a read or a notice, so no money moves.
 *
 * - `paid_by` on the commission responses (admin and `me`): the method of the
 *   paid payment holding it, or null for one recorded as paid by hand.
 * - `effective_payout_method` on the affiliate response (ADR-0016 resolution).
 * - The info icon beside the Paid pill (admin list, affiliate dashboard) and
 *   the "Payout method" notice on the commission form.
 *
 * The automatic method is the helper's "PW Bank" (fixtures/pw-helpers.php)
 * with `pw_bank_automatic` set, so this runs without FlyAffiliate Pro.
 */
import { expect, test, type Page } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api } from '../utils/api';
import { openRowActions, rowActionItem } from '../utils/dataViews';
import {
	createAffiliate,
	loginAs,
	pageUrls,
	withSettings,
} from '../utils/fixtures';
import { urls } from '../utils/site';
import { php } from '../utils/wp';

const HAND_PAID = 'Recorded as paid outside a payout.';
const NOTICE = 'flyaffiliate-commission-paid-by-hand';

let api: Api;

withSettings( { hold_days: 0, minimum_amount: 0, method: 'manual' } );

test.beforeAll( async () => {
	api = await Api.as();
} );

test.afterAll( async () => {
	php(
		`delete_option( 'pw_bank_on' ); delete_option( 'pw_bank_automatic' ); return true;`
	);
	await api.dispose();
} );

/**
 * A manual commission for an affiliate.
 * @param affiliateId
 * @param status
 */
async function commission(
	affiliateId: number,
	status: 'paid' | 'unpaid'
): Promise< number > {
	const res = await api.post( '/commissions', {
		affiliate_id: affiliateId,
		amount: 4,
		source: 'manual',
		status,
	} );
	expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
	return res.body.id;
}

/**
 * Pay an affiliate's unpaid commissions through a Manual payout.
 * @param affiliateId
 */
async function payByPayout( affiliateId: number ): Promise< number > {
	const made = await api.post( '/payouts', {
		mode: 'selected',
		affiliate_ids: [ affiliateId ],
		minimum_amount: 0,
	} );
	expect( made.status, JSON.stringify( made.body ) ).toBe( 201 );
	const payment = made.body.payouts[ 0 ].id;
	expect(
		( await api.put( `/payouts/${ payment }`, { status: 'paid' } ) ).status
	).toBe( 200 );
	return payment;
}

const getCommission = async ( id: number ) =>
	( await api.get( `/commissions/${ id }` ) ).body;

/**
 * The affiliate's payout method, chosen by hand (stored as their choice).
 * @param affiliateId
 * @param method
 */
function choose( affiliateId: number, method: string ): void {
	php(
		`flyaffiliate()->affiliate->update( ${ affiliateId }, [ 'payout_method' => '${ method }' ] ); return true;`
	);
}

/**
 * The PW Bank method: registered or not, automatic or not.
 * @param on
 * @param automatic
 */
function pwBank( on: boolean, automatic = false ): void {
	php( `
		${
			on
				? "update_option( 'pw_bank_on', 1, false );"
				: "delete_option( 'pw_bank_on' );"
		}
		${
			automatic
				? "update_option( 'pw_bank_automatic', 1, false );"
				: "delete_option( 'pw_bank_automatic' );"
		}
		return true;
	` );
}

/**
 * A REST request's route, whatever the permalinks: with plain permalinks
 * WordPress sends `?rest_route=%2Fflyaffiliate%2Fv1%2F…` (slashes encoded),
 * with pretty ones `/wp-json/flyaffiliate/v1/…`. CI's fresh wp-env has plain.
 * @param url
 */
const restPath = ( url: string ): string => decodeURIComponent( url );

/**
 * The commission form, on a new commission, with an affiliate picked.
 * @param page
 * @param affiliateId
 * @param login
 */
async function newCommissionFor(
	page: Page,
	affiliateId: number,
	login: string
) {
	await new AdminPage( page ).goto( `${ urls.app }#/commissions/new` );
	const form = page.getByTestId( 'flyaffiliate-commission-form' );
	await expect( form ).toBeVisible();
	await form.getByRole( 'combobox' ).first().click();
	await page.keyboard.type( login );
	// The form reads the affiliate's payout method after the pick: wait for it,
	// so an absent notice means "not shown", not "not loaded yet".
	const loaded = page.waitForResponse(
		( r ) =>
			restPath( r.url() ).includes( `/affiliates/${ affiliateId }` ) &&
			r.ok()
	);
	await page
		.getByRole( 'option', {
			name: new RegExp( `\\(#${ affiliateId }\\)` ),
		} )
		.click();
	await loaded;
	return form;
}

/**
 * Pick a status on the commission form.
 * @param page
 * @param name
 */
async function setStatus( page: Page, name: string ): Promise< void > {
	await page.locator( '#flyaffiliate-commission-status' ).click();
	await page.getByRole( 'option', { name, exact: true } ).click();
}

/**
 * A commission's row in a DataViews list, found by its edit link.
 * @param page
 * @param id
 */
const rowOf = ( page: Page, id: number ) =>
	// Its own edit link: other cells link "#<n>" too (the order reference),
	// and on a fresh database those numbers meet commission IDs.
	page.getByRole( 'row' ).filter( {
		has: page.locator( `a[href$="/commissions/${ id }/edit"]` ),
	} );

test.describe( 'paid_by on the commission responses [COM-18] @money', () => {
	test( 'a commission recorded paid by hand has no payment and paid_by null', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 'paid' );

		const row = await getCommission( id );
		expect( row.status ).toBe( 'paid' );
		expect( row.payout_id ).toBeNull();
		expect( row ).toHaveProperty( 'paid_by', null );
	} );

	test( 'a commission a payout paid names the payment method', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 'unpaid' );
		const payment = await payByPayout( aff.id );

		const row = await getCommission( id );
		expect( row.status ).toBe( 'paid' );
		expect( row.payout_id ).toBe( payment );
		expect( row.paid_by ).toEqual( {
			method: 'manual',
			method_label: 'Manual',
		} );

		// Rule 7: marking the payment paid again changes nothing.
		expect(
			( await api.put( `/payouts/${ payment }`, { status: 'paid' } ) )
				.status
		).toBe( 200 );
		expect( ( await getCommission( id ) ).paid_by ).toEqual( row.paid_by );
	} );

	test( 'a commission in a payment not yet paid is not paid by it', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 'unpaid' );
		const made = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( made.status ).toBe( 201 );

		const row = await getCommission( id );
		expect( row.status ).toBe( 'unpaid' );
		expect( row.paid_by ?? null ).toBeNull();

		await api.del( `/payouts/batch/${ made.body.batch_key }` );
	} );

	test( "the affiliate's own route says the same", async ( { browser } ) => {
		const aff = createAffiliate();
		const byHand = await commission( aff.id, 'paid' );
		const viaPayout = await commission( aff.id, 'unpaid' );
		await payByPayout( aff.id );

		const { context, state } = await loginAs( browser, aff );
		const me = await Api.as( state );
		try {
			const rows: { id: number; paid_by: unknown }[] = (
				await me.get( '/me/commissions' )
			).body;
			const by = ( id: number ) => rows.find( ( r ) => r.id === id );
			expect( by( byHand ) ).toHaveProperty( 'paid_by', null );
			expect( by( viaPayout )?.paid_by ).toEqual( {
				method: 'manual',
				method_label: 'Manual',
			} );
		} finally {
			await me.dispose();
			await context.close();
		}
	} );
} );

test.describe( 'effective_payout_method on the affiliate response [COM-20]', () => {
	test( 'an affiliate with no choice gets the default method', async () => {
		const aff = createAffiliate();
		expect(
			( await api.get( `/affiliates/${ aff.id }` ) ).body
				.effective_payout_method
		).toBe( 'manual' );
	} );

	test( "an affiliate's own choice wins, and falls back to Manual once its method is gone", async () => {
		const aff = createAffiliate();
		pwBank( true );
		choose( aff.id, 'pwbank' );
		expect(
			( await api.get( `/affiliates/${ aff.id }` ) ).body
				.effective_payout_method
		).toBe( 'pwbank' );

		pwBank( false );
		expect(
			( await api.get( `/affiliates/${ aff.id }` ) ).body
				.effective_payout_method
		).toBe( 'manual' );
	} );
} );

test.describe( 'Paid by hand on the admin screens [COM-19][COM-21]', () => {
	test( 'the Paid pill of a hand-paid commission carries the info icon; a payout-paid one does not', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		const byHand = await commission( aff.id, 'paid' );
		const viaPayout = await commission( aff.id, 'unpaid' );
		await payByPayout( aff.id );

		await new AdminPage( page ).goto( urls.commissions );
		await expect(
			rowOf( page, byHand ).getByRole( 'button', { name: HAND_PAID } )
		).toBeVisible();
		await expect( rowOf( page, viaPayout ) ).toBeVisible();
		await expect(
			rowOf( page, viaPayout ).getByRole( 'button', { name: HAND_PAID } )
		).toHaveCount( 0 );
	} );

	test( 'Paid for an affiliate an automatic method pays shows the Payout method notice', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		pwBank( true, true );
		choose( aff.id, 'pwbank' );

		await newCommissionFor( page, aff.id, aff.login );
		const notice = page.getByTestId( NOTICE );
		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText(
			`${ aff.login } is paid through PW Bank.`
		);
		await expect( notice ).toContainText(
			'Saving as Paid sends nothing through PW Bank. It records money you already sent outside FlyAffiliate.'
		);
		await expect( notice ).toContainText(
			'To pay through PW Bank, save it as Unpaid and create a payout.'
		);

		const link = page.getByTestId( 'flyaffiliate-commission-open-payouts' );
		await expect( link ).toHaveAttribute( 'href', /#\/payouts/ );
		await expect( link ).toHaveAttribute( 'target', '_blank' );

		await setStatus( page, 'Unpaid' );
		await expect( notice ).toHaveCount( 0 );
		await setStatus( page, 'Paid' );
		await expect( notice ).toBeVisible();
	} );

	test( 'no notice for an affiliate paid by Manual', async ( { page } ) => {
		const aff = createAffiliate();
		pwBank( true, true );

		const form = await newCommissionFor( page, aff.id, aff.login );
		await expect(
			form.locator( '#flyaffiliate-commission-status' )
		).toContainText( 'Paid' );
		await expect( page.getByTestId( NOTICE ) ).toHaveCount( 0 );
	} );

	test( 'no notice when the chosen method is not automatic', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		pwBank( true, false );
		choose( aff.id, 'pwbank' );

		await newCommissionFor( page, aff.id, aff.login );
		await expect( page.getByTestId( NOTICE ) ).toHaveCount( 0 );
	} );

	test( 'no notice on a commission that is paid already', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		pwBank( true, true );
		choose( aff.id, 'pwbank' );
		const id = await commission( aff.id, 'paid' );

		const loaded = page.waitForResponse(
			( r ) =>
				restPath( r.url() ).includes( `/affiliates/${ aff.id }` ) &&
				r.ok()
		);
		await new AdminPage( page ).goto(
			`${ urls.app }#/commissions/${ id }/edit`
		);
		await expect(
			page.getByTestId( 'flyaffiliate-commission-form' )
		).toBeVisible();
		await loaded;
		await expect( page.getByTestId( NOTICE ) ).toHaveCount( 0 );
	} );

	test( 'the notice does not block: saving as Paid records a hand-paid commission @money', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		pwBank( true, true );
		choose( aff.id, 'pwbank' );

		const form = await newCommissionFor( page, aff.id, aff.login );
		await form.locator( '#flyaffiliate-commission-amount' ).fill( '6.25' );
		await form.locator( '#flyaffiliate-commission-source' ).click();
		await page.getByRole( 'option', { name: 'Manual' } ).click();
		await expect( page.getByTestId( NOTICE ) ).toBeVisible();

		const saved = page.waitForResponse(
			( r ) =>
				/flyaffiliate\/v1\/commissions(\?|&|$)/.test(
					restPath( r.url() )
				) && r.request().method() === 'POST'
		);
		await page.getByRole( 'button', { name: 'Add commission' } ).click();
		const res = await saved;
		expect( res.status() ).toBe( 201 );
		const body = await res.json();

		const row = await getCommission( body.id );
		expect( row.status ).toBe( 'paid' );
		expect( row.payout_id ).toBeNull();
		expect( row ).toHaveProperty( 'paid_by', null );
		expect(
			php< number >(
				`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_payouts WHERE affiliate_id = %d", ${ aff.id } ) );`
			)
		).toBe( 0 );
	} );
} );

test.describe( 'Paid by hand on the affiliate dashboard [DSH-16]', () => {
	test( 'the affiliate sees which commission was recorded by hand and what paid the others', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		await commission( aff.id, 'paid' );
		await commission( aff.id, 'unpaid' );
		await payByPayout( aff.id );

		const { context } = await loginAs( browser, aff );
		const page = await context.newPage();
		try {
			// Plain permalinks give `/?page_id=N`: add the tab, don't append "?".
			const url = new URL( pageUrls().dashboard );
			url.searchParams.set( 'tab', 'commissions' );
			await page.goto( url.toString() );
			const hand = page.getByRole( 'row' ).filter( {
				has: page.getByRole( 'button', { name: HAND_PAID } ),
			} );
			await expect( hand ).toHaveCount( 1 );

			// The payout-paid one: no icon in the list, the method in its details.
			const rows = page.getByRole( 'row' ).filter( { hasText: '$4.00' } );
			await expect( rows ).toHaveCount( 2 );
			const other = rows.filter( {
				hasNot: page.getByRole( 'button', { name: HAND_PAID } ),
			} );
			await openRowActions( other );
			await rowActionItem( page, 'Details' ).click();
			const details = page.getByTestId(
				'flyaffiliate-commission-details'
			);
			await expect( details ).toBeVisible();
			await expect(
				details.getByRole( 'button', {
					name: 'Paid in a Manual payout.',
				} )
			).toBeVisible();
			await expect(
				details.getByRole( 'button', { name: HAND_PAID } )
			).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );
} );
