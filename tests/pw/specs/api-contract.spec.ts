/**
 * REST API contract: the cases tests/pw/API_TEST_CASES.md listed as not yet
 * automated for FlyAffiliate itself — the REST conventions every route shares,
 * and the corners of the affiliates, commissions, payouts, referral-links and
 * dashboard routes the other specs do not reach.
 *
 * Case ids: API_TEST_CASES.md (API-X-*, API-AFF-*, API-COM-*, API-PAY-*,
 * API-DSH-*, API-REF-*).
 */
import { expect, test } from '@playwright/test';
import { Api, cents } from '../utils/api';
import {
	createAffiliate,
	createUser,
	loginAs,
	withSettings,
} from '../utils/fixtures';
import { php } from '../utils/wp';

let api: Api;

test.beforeAll( async () => {
	api = await Api.as();
} );

/**
 * A commission added by hand, through the API.
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

test.describe( 'REST conventions [API-X]', () => {
	test( '[API-X-17] the namespace index lists the routes', async () => {
		const res = await api.get( '/flyaffiliate/v1/' );
		expect( res.status ).toBe( 200 );
		expect( res.body.namespace ).toBe( 'flyaffiliate/v1' );
		for ( const route of [
			'/flyaffiliate/v1/affiliates',
			'/flyaffiliate/v1/commissions',
			'/flyaffiliate/v1/payouts',
			'/flyaffiliate/v1/me',
		] ) {
			expect( Object.keys( res.body.routes ), route ).toContain( route );
		}
	} );

	test( '[API-X-08] a non-numeric id matches no route', async () => {
		for ( const path of [
			'/affiliates/abc',
			'/commissions/1x',
			'/payouts/-',
		] ) {
			const res = await api.get( path );
			expect( res.status, path ).toBe( 404 );
			expect( res.body.code, path ).toBe( 'rest_no_route' );
		}
	} );

	test( '[API-X-10] lists carry their totals, and a page past the end is empty', async () => {
		const aff = createAffiliate();
		for ( const amount of [ 1, 2, 3 ] ) {
			await commission( aff.id, amount );
		}

		const first = await api.get( '/commissions', {
			affiliate_id: aff.id,
			per_page: 2,
			page: 1,
		} );
		expect( first.status ).toBe( 200 );
		expect( first.headers[ 'x-wp-total' ] ).toBe( '3' );
		expect( first.headers[ 'x-wp-totalpages' ] ).toBe( '2' );
		expect( first.body ).toHaveLength( 2 );

		const second = await api.get( '/commissions', {
			affiliate_id: aff.id,
			per_page: 2,
			page: 2,
		} );
		expect( second.body ).toHaveLength( 1 );

		const past = await api.get( '/commissions', {
			affiliate_id: aff.id,
			per_page: 2,
			page: 9,
		} );
		// WordPress answers an empty page, or 400 rest_invalid_page_number.
		expect( [ 200, 400 ] ).toContain( past.status );
		if ( 200 === past.status ) {
			expect( past.body ).toEqual( [] );
		}
	} );

	test( '[API-X-12] context=edit is open to the administrator', async () => {
		const aff = createAffiliate();
		const res = await api.get( `/affiliates/${ aff.id }`, {
			context: 'edit',
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( res.body.id ).toBe( aff.id );
	} );

	test( '[API-X-13] items link to themselves, and OPTIONS describes the schema', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 4.2 );
		const item = ( await api.get( `/commissions/${ id }` ) ).body;
		expect( item._links.self[ 0 ].href ).toMatch(
			new RegExp( `commissions(/|%2F)${ id }` )
		);
		expect( item._links.collection[ 0 ].href ).toMatch( /commissions/ );

		const options = await api.call( 'OPTIONS', '/commissions' );
		expect( options.status ).toBe( 200 );
		const props = Object.keys( options.body.schema.properties );
		for ( const field of [ 'id', 'affiliate_id', 'amount', 'status' ] ) {
			expect( props, field ).toContain( field );
		}
	} );

	test( '[API-X-16] a malformed JSON body is refused and stores nothing', async () => {
		const aff = createAffiliate();
		const before = (
			await api.get( '/commissions', { affiliate_id: aff.id } )
		).headers[ 'x-wp-total' ];
		const res = await api.call(
			'POST',
			'/commissions',
			`{"affiliate_id": ${ aff.id }, "amount": 5,`
		);
		expect( res.status ).toBe( 400 );
		expect( res.body.code ).toBe( 'rest_invalid_json' );
		expect(
			( await api.get( '/commissions', { affiliate_id: aff.id } ) )
				.headers[ 'x-wp-total' ]
		).toBe( before );
	} );
} );

test.describe( 'Affiliates API corners [API-AFF]', () => {
	test( '[API-AFF-04] user_id narrows the list to that user', async () => {
		const aff = createAffiliate();
		createAffiliate();
		const res = await api.get( '/affiliates', { user_id: aff.userId } );
		expect( res.status ).toBe( 200 );
		expect( res.body.map( ( a: { id: number } ) => a.id ) ).toEqual( [
			aff.id,
		] );
	} );

	test( '[API-AFF-10] an empty payment email means the account email', async () => {
		const user = createUser();
		const res = await api.post( '/affiliates', {
			user_id: user.userId,
			status: 'active',
			payment_email: '',
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
		expect( res.body.payment_email ).toBe( user.email );
	} );

	test( '[API-AFF-11] an unknown payout method is refused on create, nothing stored', async () => {
		const user = createUser();
		const res = await api.post( '/affiliates', {
			user_id: user.userId,
			status: 'active',
			payout_method: 'no-such-method',
		} );
		expect( res.status ).toBe( 400 );
		expect( res.body.code ).toBe( 'flyaffiliate_invalid_payout_method' );
		expect(
			( await api.get( '/affiliates', { user_id: user.userId } ) ).body
		).toEqual( [] );
	} );

	test( '[API-AFF-15] an affiliate cannot be moved to another user', async () => {
		const aff = createAffiliate();
		const other = createUser();
		await api.put( `/affiliates/${ aff.id }`, { user_id: other.userId } );
		const now = ( await api.get( `/affiliates/${ aff.id }` ) ).body;
		expect( now.user_id ).toBe( aff.userId );
		expect(
			( await api.get( '/affiliates', { user_id: other.userId } ) ).body
		).toEqual( [] );
	} );

	test( '[API-AFF-17] deleting an affiliate removes the Affiliate role, keeps the WordPress user', async () => {
		const aff = createAffiliate();
		const roles = () =>
			php< string[] | null >(
				`clean_user_cache( ${ aff.userId } ); $u = get_userdata( ${ aff.userId } ); return $u ? array_values( $u->roles ) : null;`
			);
		expect( roles() ).toContain( 'flyaffiliate_affiliate' );

		const res = await api.del( `/affiliates/${ aff.id }`, { force: true } );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		const after = roles();
		expect( after, 'the WordPress user still exists' ).not.toBeNull();
		expect( after ).not.toContain( 'flyaffiliate_affiliate' );
	} );
} );

test.describe( 'Commissions API corners [API-COM]', () => {
	test( '[API-COM-17] a commission outside any payment can be deleted', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 3.33 );
		const res = await api.del( `/commissions/${ id }`, { force: true } );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( ( await api.get( `/commissions/${ id }` ) ).status ).toBe(
			404
		);
	} );
} );

test.describe( 'Payouts API corners [API-PAY] @money', () => {
	withSettings( { hold_days: 0, minimum_amount: 0, method: 'manual' } );

	test( '[API-PAY-12] creating a payout with nothing to pay is refused', async () => {
		const aff = createAffiliate();
		const res = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
		} );
		expect( res.status ).toBe( 400 );
		expect( res.body.code ).toBe( 'flyaffiliate_empty_payout' );
	} );

	test( '[API-PAY-13][API-PAY-14] payments and batches list, filtered', async () => {
		const a = createAffiliate();
		const b = createAffiliate();
		await commission( a.id, 7 );
		await commission( b.id, 3 );
		const created = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ a.id, b.id ],
			minimum_amount: 0,
		} );
		expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );
		const key = created.body.batch_key;

		try {
			const inBatch = await api.get( '/payouts', { batch_key: key } );
			expect( inBatch.status ).toBe( 200 );
			expect(
				inBatch.body
					.map( ( p: { affiliate_id: number } ) => p.affiliate_id )
					.sort()
			).toEqual( [ a.id, b.id ].sort() );

			const ofA = await api.get( '/payouts', {
				batch_key: key,
				affiliate_id: a.id,
			} );
			expect( ofA.body ).toHaveLength( 1 );
			expect( cents( ofA.body[ 0 ].amount ) ).toBe( 700 );

			const paidOnes = await api.get( '/payouts', {
				batch_key: key,
				status: 'paid',
			} );
			expect( paidOnes.body ).toEqual( [] );

			const batches = await api.get( '/payouts/batches', {
				per_page: 50,
			} );
			expect( batches.status ).toBe( 200 );
			expect( Number( batches.headers[ 'x-wp-total' ] ) ).toBeGreaterThan(
				0
			);
			const mine = batches.body.find(
				( row: { batch_key: string } ) => row.batch_key === key
			);
			expect( mine ).toMatchObject( {
				affiliates: 2,
				status: 'unpaid',
			} );
			expect( cents( mine.total ) ).toBe( 1000 );
		} finally {
			await api.del( `/payouts/batch/${ key }` );
		}
	} );
} );

test.describe( 'Dashboard API [API-DSH] @money', () => {
	test( '[API-DSH-01] every earnings card adds up from the commissions in the range', async () => {
		// A range no other data uses: commissions dated February 2001.
		const aff = createAffiliate();
		const day = '2001-02-10T10:00:00';
		const ids: number[] = [];
		const rows: Array< [ number, number, string ] > = [
			[ 100, 10, 'paid' ],
			[ 50, 5, 'unpaid' ],
			[ 30, 3, 'pending' ],
			[ 70, 7, 'rejected' ],
		];
		try {
			for ( const [ base, amount, status ] of rows ) {
				ids.push(
					await commission( aff.id, amount, {
						base_amount: base,
						status,
						created_at: day,
					} )
				);
			}

			const res = await api.get( '/dashboard', {
				after: '2001-02-01',
				before: '2001-02-28',
			} );
			expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
			const { earnings, performance } = res.body;
			// Rejected commissions are left out of every card.
			expect( cents( earnings.referral_revenue ) ).toBe( 18000 );
			expect( cents( earnings.commissions ) ).toBe( 1800 );
			expect( cents( earnings.net_revenue ) ).toBe( 16200 );
			expect( cents( earnings.paid ) ).toBe( 1000 );
			expect( cents( earnings.unpaid ) ).toBe( 500 );
			expect( cents( earnings.pending ) ).toBe( 300 );
			expect( performance.commissions ).toBe( 3 );
		} finally {
			for ( const id of ids ) {
				await api.del( `/commissions/${ id }`, { force: true } );
			}
		}
	} );

	test( '[API-DSH-03] the pending-affiliates notice can be dismissed', async () => {
		const res = await api.post( '/dashboard/pending-notice' );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( res.body.pending_notice ).toBe( false );
	} );
} );

test.describe( 'Referral links, admin list [API-REF]', () => {
	test( '[API-REF-01] an affiliate’s saved links, with their visit counts', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const { context, state } = await loginAs( browser, aff );
		const me = await Api.as( state );
		const saved = await me.post( '/me/referral-links', {
			url: `${ process.env.BASE_URL ?? 'http://localhost:8888' }/?p=1`,
		} );
		expect( [ 200, 201 ] ).toContain( saved.status );
		await context.close();

		const res = await api.get( '/referral-links', {
			affiliate_id: aff.id,
		} );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toHaveLength( 1 );
		expect( res.body[ 0 ] ).toMatchObject( {
			affiliate_id: aff.id,
			visits: 0,
			conversions: 0,
		} );
		expect( res.body[ 0 ].referral_url ).toMatch(
			new RegExp( `[?&]affiliate=${ aff.id }\\b` )
		);
	} );
} );
