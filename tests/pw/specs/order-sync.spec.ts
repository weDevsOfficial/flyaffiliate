/**
 * The commission status follows its order; a payment holds what it counts.
 *
 * ADR-0014 (status follows the order), ADR-0011 (refund switch), ADR-0012
 * (a payment locks its commissions), CONTEXT.md money rule 7.
 * Scenario ids: references/scenarios.md §3 (SYN-*).
 */
import { expect, test } from '@playwright/test';
import { Api, cents } from '../utils/api';
import {
	createAffiliate,
	createOrder,
	createProduct,
	orderCommissions,
	refireStatus,
	restoreSettings,
	setOrderStatus,
	setSettings,
	withSettings,
	trashOrder,
	type TestAffiliate,
} from '../utils/fixtures';
import { php } from '../utils/wp';

test.describe( 'Order status sync @money', () => {
	let api: Api;
	let product: number;

	const statuses = ( order: number ) =>
		orderCommissions( order ).map( ( r ) => r.status );
	const referred = ( aff: TestAffiliate, extra = {} ) =>
		createOrder( {
			affiliateId: aff.id,
			items: [ { productId: product } ],
			paymentMethod: 'bacs',
			...extra,
		} );

	withSettings( {
		default_rate: 10,
		max_rate: 50,
		hold_days: 0,
		reject_commissions_on_refund: 'off',
		woocommerce_enabled: 'on',
		block_self_referral: 'on',
	} );

	test.beforeAll( async () => {
		product = createProduct( 100 );
		api = await Api.as();
	} );

	test.afterAll( async () => {
		await api.dispose();
	} );

	test( '[SYN-01] a paid order approves its pending commission; completing it keeps it unpaid', () => {
		const order = referred( createAffiliate() );
		expect( statuses( order ) ).toEqual( [ 'pending' ] );

		setOrderStatus( order, 'processing' );
		expect( statuses( order ) ).toEqual( [ 'unpaid' ] );

		setOrderStatus( order, 'completed' );
		expect( statuses( order ) ).toEqual( [ 'unpaid' ] );
	} );

	test( '[SYN-02] failed, cancelled and trashed orders reject pending and unpaid commissions', () => {
		const aff = createAffiliate();

		const failed = referred( aff );
		setOrderStatus( failed, 'failed' );
		expect( statuses( failed ) ).toEqual( [ 'rejected' ] );

		const cancelled = referred( aff, { status: 'processing' } );
		expect( statuses( cancelled ) ).toEqual( [ 'unpaid' ] );
		setOrderStatus( cancelled, 'cancelled' );
		expect( statuses( cancelled ) ).toEqual( [ 'rejected' ] );

		const trashed = referred( aff );
		trashOrder( trashed );
		expect( statuses( trashed ) ).toEqual( [ 'rejected' ] );
	} );

	test( '[SYN-03] a refund leaves the commission alone while the switch is off (default)', () => {
		const order = referred( createAffiliate(), { status: 'processing' } );
		setOrderStatus( order, 'refunded' );
		expect( statuses( order ) ).toEqual( [ 'unpaid' ] );
	} );

	test( '[SYN-04][SYN-05] with the switch on a refund rejects; paying again approves', () => {
		const before = setSettings( { reject_commissions_on_refund: 'on' } );

		try {
			const order = referred( createAffiliate(), {
				status: 'processing',
			} );
			setOrderStatus( order, 'refunded' );
			expect( statuses( order ) ).toEqual( [ 'rejected' ] );

			setOrderStatus( order, 'completed' );
			expect( statuses( order ) ).toEqual( [ 'unpaid' ] );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[SYN-06] leaving failed for an unpaid status restores pending', () => {
		const order = referred( createAffiliate() );
		setOrderStatus( order, 'failed' );
		expect( statuses( order ) ).toEqual( [ 'rejected' ] );

		setOrderStatus( order, 'on-hold' );
		expect( statuses( order ) ).toEqual( [ 'pending' ] );
	} );

	test( '[SYN-08] the status hook firing twice changes nothing', () => {
		const order = referred( createAffiliate() );
		setOrderStatus( order, 'completed' );
		const once = orderCommissions( order );

		refireStatus( order, 'processing', 'completed' );
		refireStatus( order, 'processing', 'completed' );

		expect( orderCommissions( order ) ).toEqual( once );
	} );

	test( '[SYN-09] a commission inside an unpaid payment does not follow a cancelled order', async () => {
		const aff = createAffiliate();
		const order = referred( aff, { status: 'processing' } );

		const created = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 0,
			note: 'PW SYN-09',
		} );
		expect( created.status ).toBe( 201 );
		const payment = created.body.payouts[ 0 ];

		try {
			setOrderStatus( order, 'cancelled' );
			const [ row ] = orderCommissions( order );
			expect( row.status ).toBe( 'unpaid' );
			expect( row.payout_id ).toBe( payment.id );
		} finally {
			expect(
				( await api.del( `/payouts/${ payment.id }` ) ).status
			).toBe( 200 );
		}

		// Freed from the payment, it is an ordinary commission again.
		expect( orderCommissions( order )[ 0 ].payout_id ).toBe( 0 );
	} );

	test( '[SYN-10] a paid commission is never reversed by a refund', async () => {
		const before = setSettings( { reject_commissions_on_refund: 'on' } );
		const aff = createAffiliate();
		const order = referred( aff, { status: 'processing' } );

		try {
			const created = await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
				minimum_amount: 0,
			} );
			const payment = created.body.payouts[ 0 ];
			expect(
				(
					await api.put( `/payouts/${ payment.id }`, {
						status: 'paid',
					} )
				).status
			).toBe( 200 );
			expect( statuses( order ) ).toEqual( [ 'paid' ] );

			setOrderStatus( order, 'refunded' );
			expect( statuses( order ) ).toEqual( [ 'paid' ] );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[SYN-11] a manual commission that names the order is never moved by it', async () => {
		const aff = createAffiliate();
		const order = createOrder( { items: [ { productId: product } ] } );
		const res = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 5,
			order_id: order,
			source: 'manual',
			status: 'pending',
		} );
		expect( res.status ).toBe( 201 );

		setOrderStatus( order, 'completed' );
		expect(
			( await api.get( `/commissions/${ res.body.id }` ) ).body.status
		).toBe( 'pending' );

		setOrderStatus( order, 'cancelled' );
		expect(
			( await api.get( `/commissions/${ res.body.id }` ) ).body.status
		).toBe( 'pending' );
	} );

	test( '[SYN-12] a WooCommerce-origin commission added by hand follows its order', async () => {
		const aff = createAffiliate();
		const order = createOrder( { items: [ { productId: product } ] } );
		const res = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 5,
			order_id: order,
			source: 'woocommerce',
			status: 'pending',
		} );
		expect( res.status ).toBe( 201 );

		setOrderStatus( order, 'processing' );
		expect(
			( await api.get( `/commissions/${ res.body.id }` ) ).body.status
		).toBe( 'unpaid' );
	} );

	test( '[SYN-13] a partial refund changes neither status nor amount', () => {
		const order = referred( createAffiliate(), { status: 'processing' } );
		const [ before ] = orderCommissions( order );

		php(
			`wc_create_refund( [ 'order_id' => ${ order }, 'amount' => '40' ] ); return true;`
		);

		const [ after ] = orderCommissions( order );
		expect( after.status ).toBe( 'unpaid' );
		expect( cents( after.amount ) ).toBe( cents( before.amount ) );
	} );
} );
