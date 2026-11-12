/**
 * PayPal Payouts, FlyAffiliate Pro's automatic payout method, end to end:
 * connecting the site's app, sending a payout, what a payment in flight
 * refuses, settling, failing, retrying, webhooks, and the affiliate's view.
 *
 * FlyAffiliate ADR-0012 (two-step payouts) and ADR-0016 (payout methods are
 * a registry; a payment in flight is locked); Pro ADR-0002 (the payout
 * framework is a feature, PayPal an extension), ADR-0004 (teardown, page
 * data) and ADR-0009 (sources, transfers stamped with the account that
 * sent them, Pro's own webhook, vendor recipients).
 *
 * PayPal is played by tests/pw/fixtures/paypal-mock.php, an mu-plugin that
 * answers Pro's sandbox requests through `pre_http_request` and logs them,
 * so Pro's real code runs with no network. Needs Pro's paired environment:
 *
 *   FLYAFFILIATE_WP_ENV_DIR=~/flyaffiliate-wt/<branch>/flyaffiliate-pro \
 *   BASE_URL=http://localhost:8900 FLYAFFILIATE_SKIP_SEED=1 \
 *   npm run test:e2e -- specs/pro-paypal.spec.ts
 *
 * Scenario ids: references/scenarios.md §17 (PP-*).
 */
import { expect, test } from '@playwright/test';
import { Api, cents } from '../utils/api';
import {
	createAffiliate,
	loginAs,
	pageUrls,
	restoreSettings,
	setSettings,
	type Snapshot,
	type TestAffiliate,
} from '../utils/fixtures';
import {
	connectPayPal,
	installPayPalMock,
	payAffiliateByPayPal,
	payoutRequests,
	payPalLog,
	preparePro,
	proReady,
	removePayPalMock,
	restorePro,
	setPayPalMode,
	switchExtension,
	transfersOf,
	type ProSnapshot,
} from '../utils/pro';
import { lit, php } from '../utils/wp';

const READY = proReady();

test.describe.configure( { mode: 'serial' } );

test.describe( 'PayPal Payouts @pro @money', () => {
	test.skip( ! READY, 'needs FlyAffiliate Pro (Pro paired env)' );

	let pro: ProSnapshot;
	let fly: Snapshot;
	let api: Api;

	/**
	 * An affiliate paid by PayPal, owed one payable commission.
	 * @param amount
	 * @param email
	 */
	async function owed(
		amount: number,
		email?: string
	): Promise< { aff: TestAffiliate; commission: number } > {
		const aff = createAffiliate();
		payAffiliateByPayPal(
			aff.id,
			email ?? `pp-${ aff.login }@example.test`
		);
		const res = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount,
			source: 'manual',
			status: 'unpaid',
		} );
		expect( res.status ).toBe( 201 );
		return { aff, commission: res.body.id };
	}

	const payout = ( ids: number[], extra: Record< string, unknown > = {} ) =>
		api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: ids,
			minimum_amount: 0,
			...extra,
		} );
	const payment = async ( id: number ) =>
		( await api.get( `/payouts/${ id }` ) ).body;
	const commission = async ( id: number ) =>
		( await api.get( `/commissions/${ id }` ) ).body;
	const refresh = ( key: string ) =>
		api.post( `/payouts/batch/${ key }/refresh` );
	/**
	 * The PayPal batches Pro sent for one payment (any attempt), so other tests' traffic never counts.
	 * @param paymentId
	 */
	const sentFor = ( paymentId: number ) => {
		const ids = new Set(
			transfersOf( paymentId ).map( ( t ) => t.sender_batch_id )
		);
		return payoutRequests().filter( ( r ) =>
			ids.has( r.body.sender_batch_header.sender_batch_id )
		);
	};

	test.beforeAll( async () => {
		pro = preparePro();
		fly = setSettings( {
			hold_days: 0,
			minimum_amount: 0,
			method: 'manual',
		} );
		installPayPalMock();
		// Start disconnected: no own app, no borrowed account (restored afterwards).
		php(
			`delete_option( 'flyaffiliate_pro_paypal' ); delete_option( 'flyaffiliate_pro_payout_accounts' ); return true;`
		);
		api = await Api.as();
		expect( ( await switchExtension( api, 'paypal', true ) ).status ).toBe(
			200
		);
	} );

	test.afterAll( async () => {
		await switchExtension( api, 'paypal', false );
		removePayPalMock();
		restoreSettings( fly );
		restorePro( pro );
	} );

	// ── Connecting the site's own app (ADR-0009 §1–2) ─────────────────────

	test( '[PP-CONN-01] keys PayPal refuses are not saved as a connection', async () => {
		setPayPalMode( { token: 'invalid' } );

		try {
			const res = await connectPayPal( api );
			expect( res.status ).toBeGreaterThanOrEqual( 400 );
			expect(
				( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) )
					.body.connected
			).toBe( false );
		} finally {
			setPayPalMode( { token: 'ok' } );
		}
	} );

	test( '[PP-CONN-02] connecting reads the account, checks Payouts, and says why there is no webhook over HTTP', async () => {
		const res = await connectPayPal( api );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( res.body ).toMatchObject( {
			environment: 'sandbox',
			connected: true,
			account: {
				email: 'pw-business@example.test',
				merchant_id: 'PWPAYER1',
			},
			checks: { payouts: true, token: true, webhook: false },
		} );
		// The secret is never sent back, and the id only masked.
		expect( JSON.stringify( res.body ) ).not.toContain(
			'pw-client-secret'
		);
		expect( res.body.client_id ).not.toBe( 'pw-client-id' );

		const card = (
			await api.get( '/flyaffiliate-pro/v1/extensions/paypal' )
		).body;
		expect( card.connection ).toBeTruthy();
	} );

	// ── Sending (FlyAffiliate ADR-0016) ───────────────────────────────────

	test( '[PP-PAY-01] the preview groups payments by method and names each recipient', async () => {
		const byPayPal = await owed( 12.34, 'pp-recipient@example.test' );
		const byHand = createAffiliate();
		await api.post( '/commissions', {
			affiliate_id: byHand.id,
			amount: 5,
			source: 'manual',
			status: 'unpaid',
		} );

		const preview = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ byPayPal.aff.id, byHand.id ],
			minimum_amount: 0,
		} );
		const rows = Object.fromEntries(
			( preview.body.rows as { affiliate_id: number }[] ).map( ( r ) => [
				r.affiliate_id,
				r,
			] )
		) as Record< number, any >;
		expect( rows[ byPayPal.aff.id ] ).toMatchObject( {
			method: 'paypal',
			automatic: true,
			recipient: 'pp-recipient@example.test',
		} );
		expect( rows[ byHand.id ] ).toMatchObject( {
			method: 'manual',
			automatic: false,
		} );
		expect(
			( preview.body.groups as { method: string }[] )
				.map( ( g ) => g.method )
				.sort()
		).toEqual( [ 'manual', 'paypal' ] );
	} );

	test( '[PP-PAY-02] creating the payout sends one PayPal batch with the exact amount, and locks the payment', async () => {
		const { aff, commission: id } = await owed(
			12.34,
			'pp-exact@example.test'
		);

		const created = await payout( [ aff.id ] );
		expect( created.status ).toBe( 201 );
		const sent = created.body.payouts[ 0 ];

		const batches = sentFor( sent.id );
		expect( batches ).toHaveLength( 1 );
		expect( batches[ 0 ].body.items ).toEqual( [
			expect.objectContaining( {
				receiver: 'pp-exact@example.test',
				amount: { value: '12.34', currency: 'USD' },
			} ),
		] );
		// Idempotent at PayPal: the request carries its own id.
		expect( batches[ 0 ].headers[ 'PayPal-Request-Id' ] ).toBe(
			batches[ 0 ].body.sender_batch_header.sender_batch_id
		);

		expect( sent ).toMatchObject( {
			method: 'paypal',
			status: 'unpaid',
			locked: true,
			transfer: { status: 'processing' },
		} );
		expect( transfersOf( sent.id )[ 0 ] ).toMatchObject( {
			source: 'own',
			environment: 'sandbox',
			amount_cents: '1234',
		} );
		// Sent is not paid: the commission waits for PayPal.
		expect( ( await commission( id ) ).status ).toBe( 'unpaid' );
	} );

	test( '[PP-PAY-03] a payment in flight refuses every hand change', async () => {
		const { aff, commission: id } = await owed( 8 );
		const sent = ( await payout( [ aff.id ] ) ).body.payouts[ 0 ];
		expect( sent.locked ).toBe( true );

		expect(
			( await api.put( `/payouts/${ sent.id }`, { status: 'paid' } ) )
				.status
		).toBeGreaterThanOrEqual( 400 );
		expect(
			( await api.del( `/payouts/${ sent.id }` ) ).status
		).toBeGreaterThanOrEqual( 400 );
		expect(
			( await api.del( `/payouts/${ sent.id }/commissions/${ id }` ) )
				.status
		).toBeGreaterThanOrEqual( 400 );
		expect(
			( await api.put( `/commissions/${ id }`, { amount: 1 } ) ).status
		).toBeGreaterThanOrEqual( 400 );
		expect(
			( await api.del( `/payouts/batch/${ sent.batch_key }` ) ).status
		).toBeGreaterThanOrEqual( 400 );

		const after = await payment( sent.id );
		expect( after ).toMatchObject( { status: 'unpaid', locked: true } );
		expect( cents( after.amount ) ).toBe( 800 );
	} );

	test( '[PP-PAY-04] PayPal reporting success pays the payment and its commissions, once', async () => {
		setPayPalMode( { item_status: 'SUCCESS' } );
		const { aff, commission: id } = await owed( 9.99 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const sentBefore = sentFor( created.payouts[ 0 ].id ).length;

		const summary = await refresh( created.batch_key );
		expect( summary.body.status ).toBe( 'paid' );
		const paid = await payment( created.payouts[ 0 ].id );
		expect( paid ).toMatchObject( {
			status: 'paid',
			method: 'paypal',
			transfer: { status: 'paid' },
		} );
		expect( paid.paid_at ).toBeTruthy();
		expect( ( await commission( id ) ).status ).toBe( 'paid' );

		// Checking again changes nothing and sends nothing.
		await refresh( created.batch_key );
		expect( ( await payment( created.payouts[ 0 ].id ) ).paid_at ).toBe(
			paid.paid_at
		);
		expect( sentFor( created.payouts[ 0 ].id ).length ).toBe( sentBefore );
	} );

	test( '[PP-PAY-05] a failed transfer frees the payment; retry sends a new attempt, which then settles', async () => {
		setPayPalMode( { item_status: 'FAILED' } );
		const { aff, commission: id } = await owed(
			7.5,
			'pp-unregistered@example.test'
		);
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;

		const summary = await refresh( created.batch_key );
		expect( summary.body.status ).toBe( 'needs_attention' );
		const failed = await payment( paymentId );
		expect( failed ).toMatchObject( {
			status: 'unpaid',
			locked: false,
			can_retry: true,
			transfer: { status: 'failed' },
		} );
		expect( ( await commission( id ) ).status ).toBe( 'unpaid' );

		// PayPal still reports the first attempt failed, so Pro sends again.
		const sentBefore = sentFor( paymentId ).length;
		const retried = await api.post( `/payouts/${ paymentId }/retry` );
		expect( retried.status, JSON.stringify( retried.body ) ).toBe( 200 );
		expect( sentFor( paymentId ).length ).toBe( sentBefore + 1 );
		expect( transfersOf( paymentId ) ).toHaveLength( 2 );
		expect( ( await payment( paymentId ) ).locked ).toBe( true );

		// A second retry while that attempt is in flight is refused.
		expect(
			( await api.post( `/payouts/${ paymentId }/retry` ) ).status
		).toBeGreaterThanOrEqual( 400 );

		setPayPalMode( { item_status: 'SUCCESS' } );
		await refresh( created.batch_key );
		expect( ( await payment( paymentId ) ).status ).toBe( 'paid' );
		expect( ( await commission( id ) ).status ).toBe( 'paid' );
	} );

	test( '[PP-PAY-05b] an attempt PayPal reports paid after it failed is flagged for a person, never paid twice', async () => {
		setPayPalMode( { item_status: 'FAILED' } );
		const { aff, commission: id } = await owed( 7.25 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;
		await refresh( created.batch_key );

		setPayPalMode( { item_status: 'SUCCESS' } );
		const sentBefore = sentFor( paymentId ).length;
		const retried = await api.post( `/payouts/${ paymentId }/retry` );
		expect( retried.status ).toBe( 409 );
		expect( retried.body.code ).toBe(
			'flyaffiliate_pro_transfer_not_failed'
		);
		expect( sentFor( paymentId ).length ).toBe( sentBefore );

		const flagged = await payment( paymentId );
		expect( flagged.status ).toBe( 'unpaid' );
		expect( flagged.transfer.message ).toMatch( /Needs attention/ );
		expect( ( await commission( id ) ).status ).toBe( 'unpaid' );
	} );

	test( '[PP-PAY-06] after a failure the admin may pay by hand; it is then recorded as Manual', async () => {
		setPayPalMode( { item_status: 'FAILED' } );
		const { aff, commission: id } = await owed( 6 );
		const created = ( await payout( [ aff.id ] ) ).body;
		await refresh( created.batch_key );

		const res = await api.put( `/payouts/${ created.payouts[ 0 ].id }`, {
			status: 'paid',
		} );
		expect( res.status ).toBe( 200 );
		expect( res.body ).toMatchObject( {
			status: 'paid',
			method: 'manual',
		} );
		expect( ( await commission( id ) ).status ).toBe( 'paid' );
		setPayPalMode( { item_status: 'SUCCESS' } );
	} );

	test( "[PP-PAY-07] PayPal refusing the batch fails the transfer with PayPal's reason; nothing is locked", async () => {
		setPayPalMode( { send: 'reject' } );

		try {
			const { aff, commission: id } = await owed( 4 );
			const sent = ( await payout( [ aff.id ] ) ).body.payouts[ 0 ];
			const state = await payment( sent.id );
			expect( state.transfer.status ).toBe( 'failed' );
			expect( state.locked ).toBe( false );
			expect( transfersOf( sent.id )[ 0 ].error_message ).toMatch(
				/sufficient funds/i
			);
			expect( ( await commission( id ) ).status ).toBe( 'unpaid' );
		} finally {
			setPayPalMode( { send: 'ok' } );
		}
	} );

	test( '[PP-PAY-08] PayPal unreachable while sending is not a failure: the payment stays locked, and is never paid twice', async () => {
		setPayPalMode( { send: 'down' } );
		const { aff } = await owed( 3.33 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;

		try {
			const state = await payment( paymentId );
			expect( state.transfer.status ).not.toBe( 'failed' );
			expect( state.locked ).toBe( true );
			expect(
				(
					await api.put( `/payouts/${ paymentId }`, {
						status: 'paid',
					} )
				).status
			).toBeGreaterThanOrEqual( 400 );
		} finally {
			setPayPalMode( { send: 'ok', item_status: 'SUCCESS' } );
		}

		// Back up. Pro re-sends an unanswered batch after a 2-minute grace
		// (RESEND_GRACE), under the same ids, so PayPal cannot pay it twice.
		// The grace is simulated by moving the send back in time.
		php(
			`global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}flyaffiliate_payout_transfers SET sent_at = %s, next_check_at = %s WHERE payout_id = %d", gmdate( 'Y-m-d H:i:s', time() - 300 ), gmdate( 'Y-m-d H:i:s', time() - 300 ), ${ paymentId } ) ); return true;`
		);
		await refresh( created.batch_key );
		await refresh( created.batch_key );

		const sent = sentFor( paymentId );
		expect( sent.length ).toBe( 2 );
		expect(
			new Set(
				sent.map( ( r ) => r.body.sender_batch_header.sender_batch_id )
			).size
		).toBe( 1 );
		expect(
			new Set( sent.map( ( r ) => r.headers[ 'PayPal-Request-Id' ] ) )
				.size
		).toBe( 1 );
		expect( ( await payment( paymentId ) ).status ).toBe( 'paid' );
	} );

	test( '[PP-PAY-09] "Switch to Manual" on the preview pays that affiliate by hand: nothing goes to PayPal', async () => {
		const { aff } = await owed( 2.5 );

		const created = await payout( [ aff.id ], {
			methods: { [ aff.id ]: 'manual' },
		} );
		expect( created.status ).toBe( 201 );
		expect( created.body.payouts[ 0 ] ).toMatchObject( {
			method: 'manual',
			locked: false,
		} );
		expect( transfersOf( created.body.payouts[ 0 ].id ) ).toHaveLength( 0 );
	} );

	test( '[PP-PAY-11] a paid payment PayPal later reports reversed goes back to unpaid, flagged, and is not paid twice (guide B3.5, C6)', async () => {
		setPayPalMode( { item_status: 'SUCCESS' } );
		const { aff, commission: id } = await owed( 6 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;
		await refresh( created.batch_key );
		expect( ( await payment( paymentId ) ).status ).toBe( 'paid' );
		expect( ( await commission( id ) ).status ).toBe( 'paid' );

		// The recipient refunds it, or PayPal reverses it: PayPal says so in a
		// signed webhook (the scheduled check only follows unpaid payments), and
		// Pro confirms it with PayPal before undoing anything.
		setPayPalMode( { item_status: 'REVERSED', verify: 'SUCCESS' } );
		setWebhookId( 'WH-PW' );
		const sentBefore = sentFor( paymentId ).length;
		const event = itemEventFor( paymentId );
		event.event_type = 'PAYMENT.PAYOUTS-ITEM.REVERSED';
		event.resource.transaction_status = 'REVERSED';
		const hook = await postWebhook( event, signatureHeaders() );
		expect( hook.status, JSON.stringify( hook.body ) ).toBeLessThan( 300 );
		const summary = await api.get( '/payouts', {
			batch_key: created.batch_key,
		} );
		const back = await payment( paymentId );
		expect( back.status, JSON.stringify( back ) ).toBe( 'unpaid' );
		// Stored as a failed attempt carrying PayPal's reason, so a person sees
		// it needs attention before paying again (guide B3.5 "flagged").
		expect( back.transfer?.status ).toBe( 'failed' );
		expect( back.transfer?.message ).toMatch( /Reversed by PayPal/ );
		expect( summary.status ).toBe( 200 );
		expect( ( await commission( id ) ).status ).toBe( 'unpaid' );
		// Nothing is sent again by itself: a person checks PayPal first.
		expect( sentFor( paymentId ).length ).toBe( sentBefore );
		setPayPalMode( { item_status: 'SUCCESS', verify: 'FAILURE' } );
	} );

	test( '[PP-PAY-12] a currency PayPal does not pay out in fails before sending (guide B3.6.7)', async () => {
		const before = setSettings( { currency: 'BDT' } );

		try {
			const { aff } = await owed( 5 );
			const created = await payout( [ aff.id ] );
			const paymentId = created.body.payouts?.[ 0 ]?.id;
			expect( paymentId, JSON.stringify( created.body ) ).toBeTruthy();
			expect( sentFor( paymentId ) ).toHaveLength( 0 );
			const failed = await payment( paymentId );
			expect( failed.locked ).toBe( false );
			expect( JSON.stringify( failed ) ).toMatch(
				/does not pay out in BDT/
			);
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[API-PP-04] re-checking the connection answers its state', async () => {
		const res = await api.post(
			'/flyaffiliate-pro/v1/paypal/connection/verify'
		);
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		expect( res.body ).toMatchObject( {
			connected: true,
			environment: 'sandbox',
			checks: { payouts: true, token: true },
		} );
	} );

	test( '[API-PP-07] the environment is locked while connected; the sending source is a setting', async () => {
		const live = await api.put( '/flyaffiliate-pro/v1/paypal/settings', {
			environment: 'live',
		} );
		expect(
			live.status,
			JSON.stringify( live.body )
		).toBeGreaterThanOrEqual( 400 );
		const state = (
			await api.get( '/flyaffiliate-pro/v1/paypal/connection' )
		).body;
		expect( state.environment ).toBe( 'sandbox' );

		const own = await api.put( '/flyaffiliate-pro/v1/paypal/settings', {
			source: 'own',
		} );
		expect( own.status, JSON.stringify( own.body ) ).toBe( 200 );
	} );

	test( '[API-PP-06] an unknown borrowed source is refused (Dokan PayPal Marketplace itself: manual)', async () => {
		const res = await api.post(
			'/flyaffiliate-pro/v1/paypal/connection/source',
			{ source: 'no-such-source' }
		);
		expect( res.status, JSON.stringify( res.body ) ).toBeGreaterThanOrEqual(
			400
		);
		expect(
			( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) ).body
				.connected
		).toBe( true );
	} );

	test( '[API-PP-11] the same signed notification delivered twice is applied once', async () => {
		setPayPalMode( { item_status: 'PENDING', verify: 'SUCCESS' } );
		setWebhookId( 'WH-PW' );
		const { aff, commission: id } = await owed( 3.3 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;
		const event = itemEventFor( paymentId );

		const first = await postWebhook( event, signatureHeaders() );
		expect( first.status, JSON.stringify( first.body ) ).toBeLessThan(
			300
		);
		const paid = await payment( paymentId );
		expect( paid.status ).toBe( 'paid' );

		const again = await postWebhook( event, signatureHeaders() );
		expect( again.status, JSON.stringify( again.body ) ).toBeLessThan(
			300
		);
		const still = await payment( paymentId );
		expect( still.status ).toBe( 'paid' );
		expect( still.paid_at ).toBe( paid.paid_at );
		expect( ( await commission( id ) ).status ).toBe( 'paid' );
		expect( transfersOf( paymentId ) ).toHaveLength( 1 );
		setPayPalMode( { item_status: 'SUCCESS', verify: 'FAILURE' } );
	} );

	test( '[API-PP-12] ONHOLD waits, locked; BLOCKED needs a person and offers no retry (guide B3.5)', async () => {
		setPayPalMode( { item_status: 'ONHOLD' } );
		const held = await owed( 2 );
		const heldBatch = ( await payout( [ held.aff.id ] ) ).body;
		await refresh( heldBatch.batch_key );
		const onHold = await payment( heldBatch.payouts[ 0 ].id );
		expect( onHold, JSON.stringify( onHold ) ).toMatchObject( {
			status: 'unpaid',
			locked: true,
			transfer: { status: 'pending' },
		} );
		expect( onHold.transfer.message ).toMatch( /hold/i );

		setPayPalMode( { item_status: 'BLOCKED' } );
		const blocked = await owed( 2 );
		const blockedBatch = ( await payout( [ blocked.aff.id ] ) ).body;
		await refresh( blockedBatch.batch_key );
		const stopped = await payment( blockedBatch.payouts[ 0 ].id );
		expect( stopped, JSON.stringify( stopped ) ).toMatchObject( {
			status: 'unpaid',
			can_retry: false,
		} );
		// "Needs attention." then PayPal's own reason when it gives one
		// (the stand-in sends one), else "Blocked by PayPal.".
		expect( stopped.transfer.message ).toMatch( /needs attention/i );
		expect( ( await commission( blocked.commission ) ).status ).toBe(
			'unpaid'
		);
		setPayPalMode( { item_status: 'SUCCESS' } );
	} );

	test( '[PP-PAY-10] switching PayPal off with a payment in flight leaves it locked until PayPal is back', async () => {
		setPayPalMode( { item_status: 'PENDING' } );
		const { aff } = await owed( 1.75 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;

		await switchExtension( api, 'paypal', false );
		try {
			const state = await payment( paymentId );
			expect( state.locked ).toBe( true );
			expect(
				(
					await api.put( `/payouts/${ paymentId }`, {
						status: 'paid',
					} )
				).status
			).toBeGreaterThanOrEqual( 400 );
			expect(
				( await api.get( `/payouts/batch/${ created.batch_key }` ) )
					.body.status
			).toBe( 'needs_attention' );
		} finally {
			await switchExtension( api, 'paypal', true );
			setPayPalMode( { item_status: 'SUCCESS' } );
		}

		await refresh( created.batch_key );
		expect( ( await payment( paymentId ) ).status ).toBe( 'paid' );
	} );

	test( '[PP-CONN-03] the app cannot be disconnected while payments it sent are on their way', async () => {
		setPayPalMode( { item_status: 'PENDING' } );
		const { aff } = await owed( 1.25 );
		const created = ( await payout( [ aff.id ] ) ).body;

		try {
			const refused = await api.del(
				'/flyaffiliate-pro/v1/paypal/connection'
			);
			expect( refused.status ).toBeGreaterThanOrEqual( 400 );
			expect(
				( await api.get( '/flyaffiliate-pro/v1/paypal/connection' ) )
					.body.connected
			).toBe( true );
		} finally {
			setPayPalMode( { item_status: 'SUCCESS' } );
			await refresh( created.batch_key );
		}
	} );

	// ── Webhooks (ADR-0009 §4) ────────────────────────────────────────────

	test( '[PP-HOOK-01] a notification PayPal did not sign is refused and settles nothing', async () => {
		setPayPalMode( { item_status: 'PENDING', verify: 'FAILURE' } );
		const { aff } = await owed( 1.1 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;
		const event = itemEventFor( paymentId );

		const missing = await postWebhook( event, {} );
		expect( missing.status ).toBeGreaterThanOrEqual( 400 );

		setWebhookId( 'WH-PW' );
		const forged = await postWebhook( event, signatureHeaders() );
		expect( forged.status ).toBeGreaterThanOrEqual( 400 );
		expect( ( await payment( paymentId ) ).status ).toBe( 'unpaid' );

		setPayPalMode( { item_status: 'SUCCESS' } );
		await refresh( created.batch_key );
	} );

	test( '[PP-HOOK-02] a signed item notification settles the payment without waiting for the scheduled check', async () => {
		setPayPalMode( { item_status: 'PENDING', verify: 'SUCCESS' } );
		setWebhookId( 'WH-PW' );
		const { aff, commission: id } = await owed( 2.2 );
		const created = ( await payout( [ aff.id ] ) ).body;
		const paymentId = created.payouts[ 0 ].id;
		const res = await postWebhook(
			itemEventFor( paymentId ),
			signatureHeaders()
		);
		expect( res.status, JSON.stringify( res.body ) ).toBeLessThan( 300 );
		expect( ( await payment( paymentId ) ).status ).toBe( 'paid' );
		expect( ( await commission( id ) ).status ).toBe( 'paid' );
		setPayPalMode( { item_status: 'SUCCESS', verify: 'FAILURE' } );
	} );

	// ── The affiliate's side ──────────────────────────────────────────────

	test( '[PP-AFF-01] the affiliate picks PayPal and their email on their dashboard', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const { context, state } = await loginAs( browser, aff );
		const me = await Api.as( state );

		const saved = await me.put( '/me', {
			payout_method: 'paypal',
			payout_details: { paypal: { email: 'me-paypal@example.test' } },
		} );
		expect( saved.status, JSON.stringify( saved.body ) ).toBe( 200 );
		expect(
			php(
				`return flyaffiliate()->affiliate->get( ${ aff.id } )->get( 'payout_method' );`
			)
		).toBe( 'paypal' );

		const page = await context.newPage();
		await page.goto(
			`${ pageUrls().dashboard }${
				pageUrls().dashboard.includes( '?' ) ? '&' : '?'
			}tab=settings`
		);
		await expect(
			page.getByTestId( 'flyaffiliate-payout-method' )
		).toBeVisible();
		await expect(
			page.getByTestId( 'flyaffiliate-payout-method' )
		).toContainText( 'PayPal' );
		await context.close();
	} );

	test( "[PP-AFF-02] the affiliate sees a payment's transfer state, never PayPal's failure message", async ( {
		browser,
	} ) => {
		setPayPalMode( { item_status: 'FAILED' } );
		const { aff } = await owed( 3 );
		const created = ( await payout( [ aff.id ] ) ).body;
		await refresh( created.batch_key );

		const { context, state } = await loginAs( browser, aff );
		const mine = ( await ( await Api.as( state ) ).get( '/me/payouts' ) )
			.body as Array< Record< string, any > >;
		const row = mine.find( ( p ) => p.id === created.payouts[ 0 ].id );
		expect( row ).toBeTruthy();
		expect( JSON.stringify( row ) ).not.toMatch(
			/unregistered|RECEIVER_UNREGISTERED/i
		);
		await context.close();
		setPayPalMode( { item_status: 'SUCCESS' } );
	} );

	test( '[PP-LOG-01] Pro never sent the client secret anywhere but the token request', () => {
		for ( const call of payPalLog() ) {
			expect( JSON.stringify( call ) ).not.toContain(
				'pw-client-secret'
			);
		}
	} );

	// ── Vendor recipients (ADR-0009 §5) ──────────────────────────────────

	test( "[PP-VEN-01] a vendor who is also an affiliate is paid to their store's PayPal withdraw email, while vendors may use it", async () => {
		test.skip(
			! php< boolean >(
				`return function_exists( 'dokan_withdraw_get_active_methods' );`
			),
			'needs Dokan'
		);

		const withdraw = php( `return get_option( 'dokan_withdraw', null );` );
		const vendor = php< { user: number; affiliate: number } >( `
			$user = wp_insert_user( [ 'user_login' => 'pw-vend-aff-' . wp_generate_password( 6, false ), 'user_pass' => 'pw-Password-1', 'user_email' => 'pw-vend-aff-' . wp_generate_password( 6, false ) . '@example.test', 'role' => 'seller' ] );
			update_user_meta( $user, 'dokan_enable_selling', 'yes' );
			update_user_meta( $user, 'dokan_profile_settings', [ 'store_name' => 'PW Paying Store', 'payment' => [ 'paypal' => [ 'email' => 'store-paypal@example.test' ] ] ] );
			$affiliate = flyaffiliate()->affiliate->create( [ 'user_id' => $user, 'status' => 'active' ] );
			return [ 'user' => $user, 'affiliate' => $affiliate->get_id() ];
		` );
		payAffiliateByPayPal(
			vendor.affiliate,
			'typed-by-vendor@example.test'
		);
		await api.post( '/commissions', {
			affiliate_id: vendor.affiliate,
			amount: 4.4,
			source: 'manual',
			status: 'unpaid',
		} );
		const recipient = async () =>
			(
				await api.post( '/payouts/preview', {
					mode: 'selected',
					affiliate_ids: [ vendor.affiliate ],
					minimum_amount: 0,
				} )
			).body.rows[ 0 ].recipient as string;

		const setPayPalWithdraw = ( on: boolean ) =>
			php( `
				$w = (array) get_option( 'dokan_withdraw', [] );
				$methods = (array) ( $w['withdraw_methods'] ?? [] );
				$methods['paypal'] = ${ on ? "'paypal'" : "''" };
				$w['withdraw_methods'] = $methods;
				update_option( 'dokan_withdraw', $w );
				return true;
			` );

		try {
			setPayPalWithdraw( true );
			expect( await recipient() ).toContain(
				'store-paypal@example.test'
			);

			// Vendors may no longer use PayPal as a withdraw method: their typed email again.
			setPayPalWithdraw( false );
			expect( await recipient() ).toContain(
				'typed-by-vendor@example.test'
			);
		} finally {
			php( `
				$w = json_decode( ${ lit( JSON.stringify( withdraw ) ) }, true );
				null === $w ? delete_option( 'dokan_withdraw' ) : update_option( 'dokan_withdraw', $w );
				return true;
			` );
		}
	} );

	// ── helpers ───────────────────────────────────────────────────────────

	function signatureHeaders(): Record< string, string > {
		return {
			'PAYPAL-AUTH-ALGO': 'SHA256withRSA',
			'PAYPAL-CERT-URL':
				'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-PW',
			'PAYPAL-TRANSMISSION-ID': `pw-${ Date.now() }`,
			'PAYPAL-TRANSMISSION-SIG': 'pw-signature',
			'PAYPAL-TRANSMISSION-TIME': new Date().toISOString(),
		};
	}

	/**
	 * Pro registers no webhook over HTTP; give the own app one, as an HTTPS site would have.
	 * @param id
	 */
	function setWebhookId( id: string ): void {
		php( `
			$method = flyaffiliate_get_container()->get( 'FlyAffiliatePro\\\\Extensions\\\\PayPal\\\\Method' );
			$method->get_source( 'own' )->save_state( 'sandbox', [ 'webhook_id' => ${ lit(
				id
			) } ] );
			return true;
		` );
	}

	/**
	 * PayPal's "item succeeded" notification for a payment's transfer, built from the batch Pro sent.
	 * @param paymentId
	 */
	function itemEventFor( paymentId: number ) {
		const transfer = transfersOf( paymentId )[ 0 ];
		const sent = payoutRequests().find(
			( r ) =>
				r.body.sender_batch_header.sender_batch_id ===
				transfer.sender_batch_id
		)!;
		const item = sent.body.items[ 0 ];
		return {
			id: `WH-EVT-${ paymentId }-${ Date.now() }`,
			event_type: 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
			resource: {
				payout_item_id: `PWITEM-${ item.sender_item_id }`,
				payout_batch_id: transfer.provider_batch_id,
				transaction_status: 'SUCCESS',
				transaction_id: `PWTXN-${ item.sender_item_id }`,
				payout_item: item,
			},
		};
	}

	async function postWebhook(
		event: unknown,
		headers: Record< string, string >
	) {
		const send = () =>
			fetch(
				`${
					process.env.BASE_URL ?? 'http://localhost:8888'
				}/?rest_route=/flyaffiliate-pro/v1/paypal/webhook`,
				{
					method: 'POST',
					headers: { 'content-type': 'application/json', ...headers },
					body: JSON.stringify( event ),
				}
			);
		let res: Response;
		try {
			res = await send();
		} catch ( error ) {
			// A kept-alive socket Apache already closed ("other side closed"):
			// the request never reached WordPress, so sending it again is safe
			// (as in utils/api.ts).
			if (
				( error as { cause?: { code?: string } } ).cause?.code !==
				'UND_ERR_SOCKET'
			) {
				throw error;
			}
			res = await send();
		}
		const text = await res.text();
		let body: unknown = text;
		try {
			body = JSON.parse( text );
		} catch {}
		return { status: res.status, body };
	}
} );
