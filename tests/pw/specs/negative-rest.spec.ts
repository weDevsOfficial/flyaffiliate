/**
 * Negative paths of the admin REST API: every malformed, unknown or
 * out-of-range input is refused with the right status, and a refusal
 * writes nothing.
 *
 * ADR-0010 (one save path; a failed validation stores nothing), ADR-0012
 * (payments and their refusals), ADR-0008 (admin routes).
 * Known product bugs are pinned with `test.fail()` and listed in
 * tests/pw/PRODUCT_BUGS.md.
 */
import { expect, test } from '@playwright/test';
import { Api } from '../utils/api';
import {
	countRows,
	createAffiliate,
	createUser,
	restoreSettings,
	setSettings,
	storedSettings,
	withSettings,
} from '../utils/fixtures';

let api: Api;

test.beforeAll( async () => {
	api = await Api.as();
} );

/**
 * A manual, payable commission of the given affiliate.
 * @param affiliateId
 * @param amount
 */
async function commission(
	affiliateId: number,
	amount = 5
): Promise< number > {
	const res = await api.post( '/commissions', {
		affiliate_id: affiliateId,
		amount,
		source: 'manual',
		status: 'unpaid',
		created_at: '2020-01-01 00:00:00',
	} );
	expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
	return res.body.id;
}

test.describe( 'Affiliates API refuses bad input', () => {
	test( 'create: bad status, bad email, bad or missing user — nothing stored', async () => {
		const user = createUser();

		for ( const [ body, code ] of [
			[ { user_id: user.userId, status: 'bogus' }, 'rest_invalid_param' ],
			[
				{ user_id: user.userId, payment_email: 'not-an-email' },
				'rest_invalid_param',
			],
			[ { user_id: 'abc' }, 'rest_invalid_param' ],
			[ {}, 'rest_missing_callback_param' ],
		] as const ) {
			const res = await api.post( '/affiliates', body );
			expect( res.status, JSON.stringify( body ) ).toBe( 400 );
			expect( res.body.code ).toBe( code );
		}
		expect( countRows( 'affiliates', 'user_id', user.userId ) ).toBe( 0 );
	} );

	test( 'create: a user who does not exist, or is already an affiliate', async () => {
		expect(
			( await api.post( '/affiliates', { user_id: 99999999 } ) ).status
		).toBe( 400 );

		const aff = createAffiliate();
		expect(
			( await api.post( '/affiliates', { user_id: aff.userId } ) ).status
		).toBe( 409 );
		expect( countRows( 'affiliates', 'user_id', aff.userId ) ).toBe( 1 );
	} );

	test( 'update: bad status or email is refused and the row is unchanged', async () => {
		const aff = createAffiliate();
		const before = ( await api.get( `/affiliates/${ aff.id }` ) ).body;

		expect(
			( await api.put( `/affiliates/${ aff.id }`, { status: 'bogus' } ) )
				.status
		).toBe( 400 );
		expect(
			(
				await api.put( `/affiliates/${ aff.id }`, {
					payment_email: 'x@',
				} )
			).status
		).toBe( 400 );

		const after = ( await api.get( `/affiliates/${ aff.id }` ) ).body;
		expect( after.status ).toBe( before.status );
		expect( after.payment_email ).toBe( before.payment_email );
	} );

	test( 'unknown affiliate: get, update, delete answer 404', async () => {
		expect( ( await api.get( '/affiliates/99999999' ) ).status ).toBe(
			404
		);
		expect(
			( await api.put( '/affiliates/99999999', { status: 'active' } ) )
				.status
		).toBe( 404 );
		expect(
			( await api.del( '/affiliates/99999999', { force: true } ) ).status
		).toBe( 404 );
	} );

	test( 'list: out-of-range paging, unknown order and status are refused', async () => {
		for ( const query of [
			{ per_page: 1000 },
			{ per_page: 0 },
			{ page: 0 },
			{ orderby: 'id;DROP TABLE wp_users' },
			{ order: 'sideways' },
			{ status: 'bogus' },
		] ) {
			expect(
				( await api.get( '/affiliates', query ) ).status,
				JSON.stringify( query )
			).toBe( 400 );
		}
	} );

	test( 'list: SQL in the search box is a literal search, not SQL', async () => {
		const res = await api.get( '/affiliates', { search: "' OR 1=1 --" } );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toEqual( [] );
	} );
} );

test.describe( 'Commissions API refuses bad input', () => {
	test( 'create: every invalid field is refused, nothing stored', async () => {
		const aff = createAffiliate();

		for ( const body of [
			{ affiliate_id: 0, amount: 5 },
			{ affiliate_id: 99999999, amount: 5 },
			{ affiliate_id: aff.id },
			{ affiliate_id: aff.id, amount: 0 },
			{ affiliate_id: aff.id, amount: -3 },
			{ affiliate_id: aff.id, amount: 0.004 },
			{ affiliate_id: aff.id, amount: 'abc' },
			{ affiliate_id: aff.id, amount: 5, status: 'approved' },
			{ affiliate_id: aff.id, amount: 5, type: 'bogus' },
			{ affiliate_id: aff.id, amount: 5, source: 'nowhere' },
			{ affiliate_id: aff.id, amount: 5, created_at: 'not a date' },
			{
				affiliate_id: aff.id,
				amount: 5,
				source: 'woocommerce',
				order_id: 987654321,
			},
		] ) {
			const res = await api.post( '/commissions', {
				source: 'manual',
				...body,
			} );
			expect( res.status, JSON.stringify( body ) ).toBe( 400 );
		}
		expect( countRows( 'commissions', 'affiliate_id', aff.id ) ).toBe( 0 );
	} );

	test( 'update: invalid amount, status, type are refused; the row is unchanged', async () => {
		const id = await commission( createAffiliate().id, 5 );

		for ( const body of [
			{ amount: 0 },
			{ amount: -1 },
			{ amount: 'abc' },
			{ status: 'bogus' },
			{ type: 'bogus' },
		] ) {
			expect(
				( await api.put( `/commissions/${ id }`, body ) ).status,
				JSON.stringify( body )
			).toBe( 400 );
		}

		const row = ( await api.get( `/commissions/${ id }` ) ).body;
		expect( row ).toMatchObject( {
			amount: 5,
			status: 'unpaid',
			type: 'sale',
		} );
	} );

	test( 'unknown commission: get, update, delete answer 404', async () => {
		expect( ( await api.get( '/commissions/99999999' ) ).status ).toBe(
			404
		);
		expect(
			( await api.put( '/commissions/99999999', { amount: 3 } ) ).status
		).toBe( 404 );
		expect( ( await api.del( '/commissions/99999999' ) ).status ).toBe(
			404
		);
	} );

	test( 'list: bad status, paging and dates are refused', async () => {
		for ( const query of [
			{ status: 'bogus' },
			{ per_page: -1 },
			{ after: 'garbage' },
			{ before: '31/12/2025' },
		] ) {
			expect(
				( await api.get( '/commissions', query ) ).status,
				JSON.stringify( query )
			).toBe( 400 );
		}
	} );
} );

test.describe( 'Read-only and missing routes', () => {
	test( 'visits cannot be deleted; an unknown visit is 404', async () => {
		expect( ( await api.get( '/visits/99999999' ) ).status ).toBe( 404 );
		expect( ( await api.del( '/visits/1' ) ).status ).toBe( 404 );
	} );

	test( 'the admin referral-links list is read-only (ADR-0015)', async () => {
		expect(
			(
				await api.post( '/referral-links', {
					url: 'http://localhost:8888/',
				} )
			).status
		).toBe( 404 );
		expect( ( await api.del( '/referral-links/1' ) ).status ).toBe( 404 );
	} );

	test( 'the dashboard refuses a malformed date range', async () => {
		expect(
			( await api.get( '/dashboard', { after: 'garbage' } ) ).status
		).toBe( 400 );
	} );
} );

test.describe( 'Payouts API refuses bad input @money', () => {
	withSettings( { hold_days: 0, minimum_amount: 0 } );

	test( 'preview: bad mode, minimum, date format and ids are refused', async () => {
		for ( const body of [
			{ mode: 'bogus' },
			{ minimum_amount: -1 },
			{ period_start: '01/02/2025' },
			{ period_end: 'tomorrow' },
			{ mode: 'selected', affiliate_ids: [ 'x' ] },
		] ) {
			expect(
				( await api.post( '/payouts/preview', body ) ).status,
				JSON.stringify( body )
			).toBe( 400 );
		}
	} );

	test( 'preview: an impossible calendar date is refused', async () => {
		// BUG-008: the pattern accepts 2025-13-45 and the preview runs with it.
		// Fixed by PR #22 (was pinned with test.fail()).
		expect(
			(
				await api.post( '/payouts/preview', {
					period_start: '2025-13-45',
				} )
			).status
		).toBe( 400 );
	} );

	test( 'preview: a range that ends before it starts pays nobody', async () => {
		const aff = createAffiliate();
		await commission( aff.id );
		const res = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			period_start: '2025-12-31',
			period_end: '2025-01-01',
		} );
		expect( res.status ).toBe( 200 );
		expect( res.body.rows ).toEqual( [] );
	} );

	test( 'preview: "selected" with nobody chosen pays nobody; "except" leaves them out', async () => {
		const aff = createAffiliate();
		await commission( aff.id );

		const none = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [],
		} );
		expect( none.body.rows ).toEqual( [] );

		const except = await api.post( '/payouts/preview', {
			mode: 'except',
			affiliate_ids: [ aff.id ],
		} );
		expect(
			except.body.rows.some(
				( r: { affiliate_id: number } ) => r.affiliate_id === aff.id
			)
		).toBe( false );

		const created = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [],
		} );
		expect( created.status ).toBe( 400 );
		expect( created.body.code ).toBe( 'flyaffiliate_empty_payout' );
	} );

	test( 'preview: an unknown method override falls back to Manual', async () => {
		const aff = createAffiliate();
		await commission( aff.id );
		const res = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			methods: { [ aff.id ]: 'bogus' },
		} );
		expect( res.body.rows[ 0 ].method ).toBe( 'manual' );
	} );

	test( 'unknown payment and batch: every action is 404', async () => {
		expect( ( await api.get( '/payouts/99999999' ) ).status ).toBe( 404 );
		expect(
			( await api.put( '/payouts/99999999', { status: 'paid' } ) ).status
		).toBe( 404 );
		expect( ( await api.del( '/payouts/99999999' ) ).status ).toBe( 404 );
		expect( ( await api.post( '/payouts/99999999/retry' ) ).status ).toBe(
			404
		);
		expect( ( await api.get( '/payouts/batch/nope' ) ).status ).toBe( 404 );
		expect( ( await api.del( '/payouts/batch/nope' ) ).status ).toBe( 404 );
		expect(
			( await api.post( '/payouts/batch/nope/refresh' ) ).status
		).toBe( 404 );
		expect( ( await api.get( '/payouts/batch/..%2F..%2Fx' ) ).status ).toBe(
			404
		);
	} );

	test( 'paying an unknown batch is 404, like every other batch action', async () => {
		// BUG-007: answers 200 { paid: 0 }.
		// Fixed by PR #22 (was pinned with test.fail()).
		expect( ( await api.post( '/payouts/batch/nope/pay' ) ).status ).toBe(
			404
		);
	} );

	test( 'a payment: bad or missing status, retry of a manual payment, foreign commission', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id );
		const payment = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body.payouts[ 0 ];

		expect(
			( await api.put( `/payouts/${ payment.id }`, { status: 'bogus' } ) )
				.status
		).toBe( 400 );
		expect(
			( await api.put( `/payouts/${ payment.id }`, {} ) ).status
		).toBe( 400 );
		expect(
			( await api.post( `/payouts/${ payment.id }/retry` ) ).body.code
		).toBe( 'flyaffiliate_payout_not_retryable' );
		expect(
			( await api.del( `/payouts/${ payment.id }/commissions/99999999` ) )
				.status
		).toBe( 404 );

		const other = await commission( createAffiliate().id );
		expect(
			(
				await api.del(
					`/payouts/${ payment.id }/commissions/${ other }`
				)
			).status
		).toBe( 404 );

		// Marking an unpaid payment unpaid changes nothing.
		const same = await api.put( `/payouts/${ payment.id }`, {
			status: 'unpaid',
		} );
		expect( same.status ).toBe( 200 );
		expect( same.body.commissions ).toEqual( [ id ] );

		await api.del( `/payouts/${ payment.id }` );
	} );

	test( 'taking the last commission out does not leave an empty payment', async () => {
		// BUG-006: the payment stays, unpaid, for 0.00, and can be marked paid.
		// Fixed by PR #22 (was pinned with test.fail()).
		const aff = createAffiliate();
		const id = await commission( aff.id );
		const payment = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body.payouts[ 0 ];

		const res = await api.del(
			`/payouts/${ payment.id }/commissions/${ id }`
		);
		const gone =
			( await api.get( `/payouts/${ payment.id }` ) ).status === 404;
		expect( gone || res.status === 409 ).toBe( true );
	} );
} );

test.describe( 'Settings API refuses bad input', () => {
	test( 'unknown scope 404, malformed values 400 — option untouched', async () => {
		const before = storedSettings();

		expect(
			( await api.put( '/settings/nope', { values: { hold_days: 1 } } ) )
				.status
		).toBe( 404 );
		expect(
			( await api.put( '/settings/rules', { values: 'x' } ) ).status
		).toBe( 400 );
		expect( ( await api.put( '/settings/rules', {} ) ).status ).toBe( 400 );
		expect(
			(
				await api.put( '/settings/rules', {
					values: { hold_days: 'abc' },
				} )
			).status
		).toBe( 400 );

		expect( storedSettings() ).toEqual( before );
	} );

	test( 'a field from another scope, or an unknown field, is not written', async () => {
		const before = storedSettings();

		await api.put( '/settings/rules', {
			values: { referral_variable: 'zzz', not_a_field: 1 },
		} );

		const after = storedSettings();
		expect( after.referral_variable ).toBe( before.referral_variable );
		expect( after ).not.toHaveProperty( 'not_a_field' );
	} );

	test( 'an unknown choice for a select keeps the stored value', async () => {
		const before = storedSettings();

		await api.put( '/settings/rates', { values: { rate_type: 'bogus' } } );
		await api.put( '/settings/payout_rules', {
			values: { method: 'bogus' },
		} );
		await api.put( '/settings/currency_display', {
			values: { currency: 'XXX' },
		} );

		const after = storedSettings();
		expect( after.rate_type ).toBe( before.rate_type );
		expect( after.method ).toBe( before.method );
		expect( after.currency ).toBe( before.currency );
	} );

	test( 'an invalid switch value is refused, not read as "off"', async () => {
		// BUG-005: 'maybe' is stored as 'off', silently disabling the switch.
		// Fixed by PR #22 (was pinned with test.fail()).
		const snapshot = setSettings( { block_self_referral: 'on' } );

		try {
			const res = await api.put( '/settings/rules', {
				values: { block_self_referral: 'maybe' },
			} );
			expect( res.status ).toBe( 400 );
			expect( storedSettings().block_self_referral ).toBe( 'on' );
		} finally {
			restoreSettings( snapshot );
		}
	} );

	test( 'markup in the referral variable is stripped to a safe key', async () => {
		const snapshot = setSettings( { referral_variable: 'affiliate' } );

		try {
			await api.put( '/settings/tracking', {
				values: { referral_variable: '<b>Ref</b>' },
			} );
			expect( storedSettings().referral_variable ).toMatch(
				/^[a-z0-9_-]+$/
			);
		} finally {
			restoreSettings( snapshot );
		}
	} );

	test( 'a WordPress query variable cannot become the referral variable', async () => {
		// BUG-004: `p`, `s`, `page_id`… are accepted; with `p`, every plain post
		// link (/?p=1) credits affiliate #1.
		// Fixed by PR #22 (was pinned with test.fail()).
		const snapshot = setSettings( { referral_variable: 'affiliate' } );

		try {
			for ( const reserved of [ 'p', 's', 'page_id', 'name', 'cat' ] ) {
				const res = await api.put( '/settings/tracking', {
					values: { referral_variable: reserved },
				} );
				expect( res.status, reserved ).toBe( 400 );
			}
		} finally {
			restoreSettings( snapshot );
		}
	} );
} );
