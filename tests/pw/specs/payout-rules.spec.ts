/**
 * Payout rules: what a preview takes, what a payment holds, what marking
 * paid and unpaid moves, and every way out of a payment.
 *
 * ADR-0006, ADR-0012 (two-step payouts), ADR-0014 (the hold gates payouts),
 * ADR-0016 (nothing attached twice), CONTEXT.md money rules 6–7.
 * Every affiliate here is created by the test and every payout is narrowed
 * to it (`mode: selected`), so seeded rows never change a figure.
 * Scenario ids: references/scenarios.md §4–5 (PAY-*, COM-*).
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { Api, cents } from '../utils/api';
import {
	createAffiliate,
	restoreSettings,
	setSettings,
	withSettings,
} from '../utils/fixtures';
import { urls } from '../utils/site';

let api: Api;

/**
 * A manual commission, unpaid unless told otherwise.
 * @param affiliateId
 * @param amount
 * @param extra
 */
async function commission(
	affiliateId: number,
	amount: number,
	extra: Record< string, unknown > = {}
): Promise< number > {
	const res = await api.post( '/commissions', {
		affiliate_id: affiliateId,
		amount,
		source: 'manual',
		status: 'unpaid',
		...extra,
	} );
	expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
	return res.body.id;
}

const preview = ( ids: number[], extra: Record< string, unknown > = {} ) =>
	api.post( '/payouts/preview', {
		mode: 'selected',
		affiliate_ids: ids,
		minimum_amount: 0,
		...extra,
	} );

const createPayout = ( ids: number[], extra: Record< string, unknown > = {} ) =>
	api.post( '/payouts', {
		mode: 'selected',
		affiliate_ids: ids,
		minimum_amount: 0,
		...extra,
	} );

const getCommission = async ( id: number ) =>
	( await api.get( `/commissions/${ id }` ) ).body;
const getPayment = async ( id: number ) =>
	( await api.get( `/payouts/${ id }` ) ).body;

withSettings( {
	hold_days: 0,
	minimum_amount: 0,
	method: 'manual',
} );

test.beforeAll( async () => {
	api = await Api.as();
} );

test.afterAll( async () => {
	await api.dispose();
} );

test.describe( 'Payout preview @money', () => {
	test( '[PAY-02] an unpaid commission inside its hold is held, not paid', async () => {
		const aff = createAffiliate();
		const before = setSettings( { hold_days: 30 } );

		try {
			await commission( aff.id, 12.34 );
		} finally {
			restoreSettings( before );
		}

		const res = await preview( [ aff.id ] );
		expect( res.body.count ).toBe( 0 );
		expect( res.body.held.count ).toBe( 1 );
		expect( cents( res.body.held.amount ) ).toBe( 1234 );

		const create = await createPayout( [ aff.id ] );
		expect( create.status ).toBe( 400 );
		expect( create.body.code ).toBe( 'flyaffiliate_empty_payout' );
	} );

	test( '[PAY-03] only unpaid commissions outside any payment are offered', async () => {
		const aff = createAffiliate();
		const unpaid = await commission( aff.id, 7 );
		await commission( aff.id, 1, { status: 'pending' } );
		await commission( aff.id, 2, { status: 'rejected' } );
		await commission( aff.id, 3, { status: 'paid' } );

		const res = await preview( [ aff.id ] );
		expect( res.body.rows ).toHaveLength( 1 );
		expect( res.body.rows[ 0 ].commissions ).toEqual( [ unpaid ] );
		expect( cents( res.body.rows[ 0 ].amount ) ).toBe( 700 );
	} );

	test( '[PAY-04] affiliates under the minimum are left out', async () => {
		const small = createAffiliate();
		const big = createAffiliate();
		await commission( small.id, 3 );
		await commission( big.id, 30 );

		const res = await preview( [ small.id, big.id ], {
			minimum_amount: 5,
		} );
		expect(
			res.body.rows.map(
				( r: { affiliate_id: number } ) => r.affiliate_id
			)
		).toEqual( [ big.id ] );
	} );

	test( '[PAY-05] the date range narrows by when the commission was earned', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 4, { created_at: '2025-01-10 12:00:00' } );
		const inRange = await commission( aff.id, 5, {
			created_at: '2025-02-10 12:00:00',
		} );

		const res = await preview( [ aff.id ], {
			period_start: '2025-02-01',
			period_end: '2025-02-28',
		} );
		expect( res.body.rows[ 0 ].commissions ).toEqual( [ inRange ] );
	} );

	test( '[PAY-06] one payment per affiliate, summed in cents', async () => {
		const aff = createAffiliate();
		for ( const amount of [ 0.1, 0.2, 0.05, 1.15 ] ) {
			await commission( aff.id, amount );
		}

		const res = await createPayout( [ aff.id ], { note: 'PW PAY-06' } );
		expect( res.status ).toBe( 201 );
		expect( res.body.payouts ).toHaveLength( 1 );
		expect( cents( res.body.payouts[ 0 ].amount ) ).toBe( 150 );
		expect( res.body.payouts[ 0 ].status ).toBe( 'unpaid' );
		expect( res.body.payouts[ 0 ].commissions ).toHaveLength( 4 );

		await api.del( `/payouts/batch/${ res.body.batch_key }` );
	} );
} );

test.describe( 'Creating a payout @money', () => {
	test( '[PAY-07] amounts that changed since the preview refuse the payout', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 10 );
		const shown = ( await preview( [ aff.id ] ) ).body.rows[ 0 ].amount;

		await api.put( `/commissions/${ id }`, { amount: 11 } );

		const res = await createPayout( [ aff.id ], {
			expected: { [ aff.id ]: shown },
		} );
		expect( res.status ).toBe( 409 );
		expect( res.body.code ).toBe( 'flyaffiliate_payout_changed' );
		expect( ( await getCommission( id ) ).payout_id ).toBeNull();
	} );

	test( '[PAY-08] two payouts recorded at once never both take a commission', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 9 );
		const second = await Api.as();

		try {
			const [ a, b ] = await Promise.all( [
				createPayout( [ aff.id ] ),
				second.post( '/payouts', {
					mode: 'selected',
					affiliate_ids: [ aff.id ],
					minimum_amount: 0,
				} ),
			] );
			const won = [ a, b ].filter( ( r ) => r.status === 201 );
			expect( won ).toHaveLength( 1 );
			expect( ( await getCommission( id ) ).payout_id ).toBe(
				won[ 0 ].body.payouts[ 0 ].id
			);

			await api.del( `/payouts/batch/${ won[ 0 ].body.batch_key }` );
		} finally {
			await second.dispose();
		}
	} );

	test( '[PAY-09] a commission waiting in an unpaid payment is not offered again', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 8 );
		const first = await createPayout( [ aff.id ] );

		expect( ( await preview( [ aff.id ] ) ).body.rows ).toHaveLength( 0 );
		expect( ( await createPayout( [ aff.id ] ) ).status ).toBe( 400 );

		await api.del( `/payouts/batch/${ first.body.batch_key }` );
	} );

	test( '[PAY-01] creating the payout pays nothing yet: commissions stay unpaid, attached', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 6 );
		const res = await createPayout( [ aff.id ] );

		const row = await getCommission( id );
		expect( row.status ).toBe( 'unpaid' );
		expect( row.payout_id ).toBe( res.body.payouts[ 0 ].id );

		await api.del( `/payouts/batch/${ res.body.batch_key }` );
	} );
} );

test.describe( 'A payment @money', () => {
	test( '[PAY-10][PAY-11][PAY-12] marking paid pays its commissions once; unpaid returns them', async () => {
		const aff = createAffiliate();
		const ids = [
			await commission( aff.id, 2.5 ),
			await commission( aff.id, 2.5 ),
		];
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		const paid = await api.put( `/payouts/${ payment.id }`, {
			status: 'paid',
		} );
		expect( paid.status ).toBe( 200 );
		expect( paid.body.status ).toBe( 'paid' );
		expect( paid.body.paid_at ).toBeTruthy();
		for ( const id of ids ) {
			expect( ( await getCommission( id ) ).status ).toBe( 'paid' );
		}

		// Again: nothing moves, nothing doubles.
		const again = await api.put( `/payouts/${ payment.id }`, {
			status: 'paid',
		} );
		expect( again.status ).toBe( 200 );
		expect( again.body.paid_at ).toBe( paid.body.paid_at );
		expect( cents( again.body.amount ) ).toBe( 500 );

		const back = await api.put( `/payouts/${ payment.id }`, {
			status: 'unpaid',
		} );
		expect( back.body.status ).toBe( 'unpaid' );
		for ( const id of ids ) {
			const row = await getCommission( id );
			expect( row.status ).toBe( 'unpaid' );
			expect( row.payout_id ).toBe( payment.id );
		}

		await api.del( `/payouts/${ payment.id }` );
	} );

	test( '[ADR-0012] marking paid leaves a commission rejected meanwhile rejected', async () => {
		const aff = createAffiliate();
		const keep = await commission( aff.id, 4 );
		const drop = await commission( aff.id, 4 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		expect(
			(
				await api.put( `/commissions/${ drop }`, {
					status: 'rejected',
				} )
			).status
		).toBe( 200 );
		await api.put( `/payouts/${ payment.id }`, { status: 'paid' } );

		expect( ( await getCommission( keep ) ).status ).toBe( 'paid' );
		expect( ( await getCommission( drop ) ).status ).toBe( 'rejected' );
	} );

	test( '[COM-11] editing a commission in an unpaid payment re-sums the payment', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 10 );
		await commission( aff.id, 5 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];
		expect( cents( payment.amount ) ).toBe( 1500 );

		expect(
			( await api.put( `/commissions/${ id }`, { amount: 12.5 } ) ).status
		).toBe( 200 );
		expect( cents( ( await getPayment( payment.id ) ).amount ) ).toBe(
			1750
		);

		await api.del( `/payouts/${ payment.id }` );
	} );

	test( '[COM-12] editing a commission after payment keeps what the payment paid', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 10 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];
		await api.put( `/payouts/${ payment.id }`, { status: 'paid' } );

		await api.put( `/commissions/${ id }`, { amount: 3 } );
		expect( cents( ( await getCommission( id ) ).amount ) ).toBe( 300 );
		expect( cents( ( await getPayment( payment.id ) ).amount ) ).toBe(
			1000
		);
	} );

	test( '[COM-13] a commission inside a payment cannot be deleted', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 4 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		const res = await api.del( `/commissions/${ id }` );
		expect( res.status ).toBeGreaterThanOrEqual( 400 );
		expect( ( await api.get( `/commissions/${ id }` ) ).status ).toBe(
			200
		);

		await api.del( `/payouts/${ payment.id }` );
		// Freed, it can go.
		expect( ( await api.del( `/commissions/${ id }` ) ).status ).toBe(
			200
		);
	} );

	test( '[COM-14] a commission recorded paid by hand is held by nothing', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 4, { status: 'paid' } );

		expect(
			( await api.put( `/commissions/${ id }`, { status: 'unpaid' } ) )
				.status
		).toBe( 200 );
		expect( ( await api.del( `/commissions/${ id }` ) ).status ).toBe(
			200
		);
	} );

	test( '[PAY-13][PAY-14] a commission comes out of an unpaid payment, not a paid one', async () => {
		const aff = createAffiliate();
		const out = await commission( aff.id, 4 );
		const stays = await commission( aff.id, 6 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		const removed = await api.del(
			`/payouts/${ payment.id }/commissions/${ out }`
		);
		expect( removed.status ).toBe( 200 );
		expect( cents( removed.body.amount ) ).toBe( 600 );
		expect( ( await getCommission( out ) ).payout_id ).toBeNull();
		expect( ( await getCommission( out ) ).status ).toBe( 'unpaid' );

		await api.put( `/payouts/${ payment.id }`, { status: 'paid' } );
		const refused = await api.del(
			`/payouts/${ payment.id }/commissions/${ stays }`
		);
		expect( refused.status ).toBe( 409 );
		expect( ( await getCommission( stays ) ).payout_id ).toBe( payment.id );
	} );

	test( '[PAY-15] deleting an unpaid payment frees its commissions for the next payout', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 4 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		expect( ( await api.del( `/payouts/${ payment.id }` ) ).status ).toBe(
			200
		);
		expect( ( await api.get( `/payouts/${ payment.id }` ) ).status ).toBe(
			404
		);
		expect( ( await getCommission( id ) ).payout_id ).toBeNull();
		expect(
			( await preview( [ aff.id ] ) ).body.rows[ 0 ].commissions
		).toEqual( [ id ] );

		// A paid payment stays.
		const paid = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];
		await api.put( `/payouts/${ paid.id }`, { status: 'paid' } );
		expect( ( await api.del( `/payouts/${ paid.id }` ) ).status ).toBe(
			409
		);
	} );

	test( '[PAY-16][PAY-17][PAY-20] a payout: status from its payments, batch pay, batch delete', async () => {
		const a = createAffiliate();
		const b = createAffiliate();
		await commission( a.id, 5 );
		await commission( b.id, 7 );
		const created = await createPayout( [ a.id, b.id ], {
			note: 'PW batch',
		} );
		const key = created.body.batch_key;

		const summary = ( await api.get( `/payouts/batch/${ key }` ) ).body;
		expect( summary.status ).toBe( 'unpaid' );
		expect( summary.affiliates ).toBe( 2 );
		expect( cents( summary.total ) ).toBe( 1200 );

		// One paid payment makes the whole batch undeletable.
		await api.put( `/payouts/${ created.body.payouts[ 0 ].id }`, {
			status: 'paid',
		} );
		expect( ( await api.del( `/payouts/batch/${ key }` ) ).status ).toBe(
			409
		);

		expect(
			( await api.post( `/payouts/batch/${ key }/pay` ) ).body.paid
		).toBe( 1 );
		expect(
			( await api.post( `/payouts/batch/${ key }/pay` ) ).body.paid
		).toBe( 0 );
		expect(
			( await api.get( `/payouts/batch/${ key }` ) ).body.status
		).toBe( 'paid' );

		// An all-unpaid batch deletes, payments and all.
		const c = createAffiliate();
		await commission( c.id, 3 );
		const loose = await createPayout( [ c.id ] );
		expect(
			( await api.del( `/payouts/batch/${ loose.body.batch_key }` ) )
				.status
		).toBe( 200 );
		expect(
			( await api.get( `/payouts/batch/${ loose.body.batch_key }` ) )
				.status
		).toBe( 404 );
	} );

	test( '[PAY-21] with only Manual registered every payment is manual', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 5 );
		const res = await preview( [ aff.id ] );
		expect(
			res.body.groups.map( ( g: { method: string } ) => g.method )
		).toEqual( [ 'manual' ] );
		expect( res.body.rows[ 0 ] ).toMatchObject( {
			method: 'manual',
			automatic: false,
			payment_email: aff.email,
		} );
	} );
} );

test.describe( 'Payout screens', () => {
	test( '[PAY-18] the CSV lists every payment, and only with the nonce', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		await commission( aff.id, 4.2 );
		const created = await createPayout( [ aff.id ], { note: 'PW CSV' } );

		const admin = new AdminPage( page );
		await admin.goto(
			`${ urls.app }#/payouts/batch/${ created.body.batch_key }`
		);
		const href = await page
			.getByTestId( 'flyaffiliate-batch-csv' )
			.getAttribute( 'href' );
		expect( href ).toContain( '_wpnonce=' );

		const csv = await page.request.get( href! );
		expect( csv.status() ).toBe( 200 );
		const text = await csv.text();
		expect( text ).toContain( 'Payment email' );
		expect( text ).toContain( aff.email );
		expect( text ).toContain( '4.20' );

		const forged = await page.request.get(
			href!.replace( /_wpnonce=[^&]+/, '_wpnonce=bad' )
		);
		expect( forged.status() ).toBeGreaterThanOrEqual( 400 );

		await api.del( `/payouts/batch/${ created.body.batch_key }` );
	} );

	test( '[PAY-10] a payment is marked paid from its own page', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 3.3 );
		const payment = ( await createPayout( [ aff.id ] ) ).body.payouts[ 0 ];

		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/payouts/payment/${ payment.id }` );
		await page.getByTestId( 'flyaffiliate-payment-pay' ).click();
		const confirm = page.getByTestId( 'flyaffiliate-confirm' );
		if ( await confirm.isVisible().catch( () => false ) ) {
			await confirm.click();
		}
		await admin.expectToast( /paid/i );

		await expect
			.poll( async () => ( await getPayment( payment.id ) ).status )
			.toBe( 'paid' );
		expect( ( await getCommission( id ) ).status ).toBe( 'paid' );
	} );
} );
