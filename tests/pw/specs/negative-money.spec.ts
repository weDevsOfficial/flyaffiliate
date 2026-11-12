/**
 * Negative and boundary paths of the money loop: orders that must earn
 * nothing, rates at and past their limits, status changes that must not move
 * a commission, and payout boundaries.
 *
 * CONTEXT.md money rules 1–9; ADR-0005, ADR-0012, ADR-0014.
 */
import { expect, test } from '@playwright/test';
import { Api, cents } from '../utils/api';
import {
	createAffiliate,
	createCoupon,
	createOrder,
	createProduct,
	orderCommissions,
	orderMeta,
	refireStatus,
	restoreSettings,
	setAffiliateStatus,
	setOrderStatus,
	setSettings,
	withSettings,
} from '../utils/fixtures';
import { php } from '../utils/wp';

let api: Api;

test.beforeAll( async () => {
	api = await Api.as();
} );

/**
 * A product with a raw `_flyaffiliate_rate` meta value, or none.
 * @param price
 * @param meta
 */
function productWithRate( price: number, meta?: string ): number {
	const id = createProduct( price );

	if ( meta !== undefined ) {
		php(
			`update_post_meta( ${ id }, '_flyaffiliate_rate', ${ JSON.stringify(
				meta
			) } ); return true;`
		);
	}

	return id;
}

test.describe( 'Orders that must earn nothing @money', () => {
	withSettings( {
		default_rate: 10,
		max_rate: 50,
		woocommerce_enabled: 'on',
		block_self_referral: 'on',
	} );

	test( 'a free product, and a 100% coupon, create no commission', () => {
		const aff = createAffiliate();

		expect(
			orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [ { productId: createProduct( 0 ) } ],
				} )
			)
		).toHaveLength( 0 );
		expect(
			orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [ { productId: createProduct( 20 ) } ],
					coupon: createCoupon( 100 ),
				} )
			)
		).toHaveLength( 0 );
	} );

	test( 'a product rate of 0, or a negative one, earns nothing (not the default)', () => {
		const aff = createAffiliate();

		for ( const meta of [ '0', '-5' ] ) {
			const order = createOrder( {
				affiliateId: aff.id,
				items: [ { productId: productWithRate( 100, meta ) } ],
			} );
			expect( orderCommissions( order ), meta ).toHaveLength( 0 );
		}
	} );

	test( 'a non-numeric or empty product rate falls back to the default', () => {
		const aff = createAffiliate();

		for ( const meta of [ 'abc', '' ] ) {
			const [ row ] = orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [ { productId: productWithRate( 100, meta ) } ],
				} )
			);
			expect( row.rate, meta ).toBe( 10 );
		}
	} );

	test( 'a default rate of 0 (product without a rate), or a maximum of 0, earns nothing', () => {
		const aff = createAffiliate();

		// A product's own rate wins over the default (RateResolver), so the
		// default-0 case uses a product without one; the maximum caps both.
		for ( const [ values, productId ] of [
			[ { default_rate: 0 }, createProduct( 100 ) ],
			[ { max_rate: 0 }, productWithRate( 100, '15' ) ],
		] as Array< [ Record< string, number >, number ] > ) {
			const snapshot = setSettings( values );

			try {
				const order = createOrder( {
					affiliateId: aff.id,
					items: [ { productId } ],
				} );
				expect(
					orderCommissions( order ),
					JSON.stringify( values )
				).toHaveLength( 0 );
			} finally {
				restoreSettings( snapshot );
			}
		}
	} );

	test( 'an affiliate deleted or suspended after the click earns nothing', () => {
		const product = createProduct( 10 );

		const deleted = createAffiliate();
		php(
			`flyaffiliate()->affiliate->delete( ${ deleted.id } ); return true;`
		);
		expect(
			orderCommissions(
				createOrder( {
					affiliateId: deleted.id,
					items: [ { productId: product } ],
				} )
			)
		).toHaveLength( 0 );

		for ( const status of [ 'pending', 'inactive', 'suspended' ] ) {
			const aff = createAffiliate();
			setAffiliateStatus( aff.id, status );
			const order = createOrder( {
				affiliateId: aff.id,
				items: [ { productId: product } ],
			} );
			expect( orderCommissions( order ), status ).toHaveLength( 0 );
			expect(
				orderMeta( order, '_flyaffiliate_affiliate_id' ),
				status
			).toBe( '' );
		}
	} );

	test( 'a cookie with a forged signature, wrong shape or no affiliate earns nothing', () => {
		const aff = createAffiliate();
		const product = createProduct( 10 );

		for ( const cookie of [
			`${ aff.id }|0|00000000000000000000000000000000`,
			`${ aff.id }|0`,
			`${ aff.id }`,
			'0|0|x',
			'',
			'a|b|c',
			`${ aff.id }|0|${ 'f'.repeat( 32 ) }|extra`,
		] ) {
			expect(
				orderCommissions(
					createOrder( { cookie, items: [ { productId: product } ] } )
				),
				cookie
			).toHaveLength( 0 );
		}
	} );
} );

test.describe( 'Rates and rounding at their limits @money', () => {
	withSettings( {
		default_rate: 10,
		max_rate: 50,
		woocommerce_enabled: 'on',
	} );

	test( 'a rate at exactly the maximum is kept; above it is clamped', () => {
		const aff = createAffiliate();
		const at = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: productWithRate( 100, '50' ) } ],
			} )
		)[ 0 ];
		const over = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: productWithRate( 100, '100' ) } ],
			} )
		)[ 0 ];

		expect( [ at.rate, cents( at.amount ) ] ).toEqual( [ 50, 5000 ] );
		expect( [ over.rate, cents( over.amount ) ] ).toEqual( [ 50, 5000 ] );
	} );

	test( 'amounts are rounded to the cent, per item (no float drift)', () => {
		const aff = createAffiliate();

		const tiny = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: createProduct( 0.33 ) } ],
			} )
		)[ 0 ];
		expect( cents( tiny.amount ) ).toBe( 3 );

		const thirds = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: createProduct( 33.33 ), qty: 3 } ],
			} )
		)[ 0 ];
		expect( cents( thirds.base_amount ) ).toBe( 9999 );
		expect( cents( thirds.amount ) ).toBe( 1000 );

		const fraction = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: productWithRate( 100, '12.345' ) } ],
			} )
		)[ 0 ];
		expect( cents( fraction.amount ) ).toBe( 1235 );
	} );
} );

test.describe( 'Status changes that must not move a commission @money', () => {
	withSettings( {
		hold_days: 0,
		minimum_amount: 0,
		reject_commissions_on_refund: 'on',
		woocommerce_enabled: 'on',
	} );

	test( 'an unpaid status that is not "paid" leaves a pending commission pending', () => {
		const aff = createAffiliate();
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: createProduct( 10 ) } ],
			paymentMethod: 'bacs',
		} );

		for ( const status of [ 'on-hold', 'pending' ] ) {
			setOrderStatus( order, status );
			expect( orderCommissions( order )[ 0 ].status, status ).toBe(
				'pending'
			);
		}
	} );

	test( 'an order with no referral changes status without touching any commission', () => {
		const order = createOrder( {
			items: [ { productId: createProduct( 10 ) } ],
		} );

		for ( const status of [
			'processing',
			'cancelled',
			'refunded',
			'failed',
		] ) {
			setOrderStatus( order, status );
		}
		expect( orderCommissions( order ) ).toHaveLength( 0 );
	} );

	test( 'a commission paid through a payment stays paid whatever the order does', async () => {
		test.slow(); // a dozen WP-CLI round trips
		const aff = createAffiliate();
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: createProduct( 10 ) } ],
			paymentMethod: 'bacs',
			status: 'processing',
		} );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'unpaid' );
		const created = await api.post( '/payouts', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
		} );
		expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );
		const payment = created.body.payouts[ 0 ];
		expect(
			( await api.put( `/payouts/${ payment.id }`, { status: 'paid' } ) )
				.status
		).toBe( 200 );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'paid' );

		for ( const status of [
			'failed',
			'cancelled',
			'refunded',
			'on-hold',
			'processing',
		] ) {
			setOrderStatus( order, status );
			expect( orderCommissions( order )[ 0 ].status, status ).toBe(
				'paid'
			);
		}
		expect(
			cents( ( await api.get( `/payouts/${ payment.id }` ) ).body.amount )
		).toBe( 100 );
	} );

	test( 'a rejecting status fired twice rejects once, and a refund of a rejected order changes nothing', () => {
		const aff = createAffiliate();
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: createProduct( 10 ) } ],
		} );

		setOrderStatus( order, 'cancelled' );
		const once = orderCommissions( order );
		refireStatus( order, 'pending', 'cancelled' );
		refireStatus( order, 'cancelled', 'refunded' );

		expect( orderCommissions( order ) ).toEqual( once );
		expect( once[ 0 ].status ).toBe( 'rejected' );
	} );
} );

test.describe( 'Payout boundaries and refusals @money', () => {
	withSettings( { hold_days: 0, minimum_amount: 0 } );

	async function commission(
		affiliateId: number,
		amount: number,
		extra = {}
	): Promise< number > {
		const res = await api.post( '/commissions', {
			affiliate_id: affiliateId,
			amount,
			source: 'manual',
			status: 'unpaid',
			created_at: '2020-01-01 00:00:00',
			...extra,
		} );
		expect( res.status ).toBe( 201 );
		return res.body.id;
	}

	test( 'the minimum is inclusive: exactly the minimum is paid, a cent less is not', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 50 );

		const at = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 50,
		} );
		const above = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
			minimum_amount: 50.01,
		} );

		expect( at.body.rows ).toHaveLength( 1 );
		expect( above.body.rows ).toHaveLength( 0 );
	} );

	test( 'a commission earned in the future is held, not paid', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 4, { created_at: '2099-01-01 00:00:00' } );

		const res = await api.post( '/payouts/preview', {
			mode: 'selected',
			affiliate_ids: [ aff.id ],
		} );
		expect( res.body.rows ).toHaveLength( 0 );
		expect( res.body.held.count ).toBe( 1 );
	} );

	test( 'rejecting a commission inside an unpaid payment takes it out of the sum', async () => {
		const aff = createAffiliate();
		await commission( aff.id, 10 );
		const drop = await commission( aff.id, 5 );
		const payment = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body.payouts[ 0 ];
		expect( cents( payment.amount ) ).toBe( 1500 );

		await api.put( `/commissions/${ drop }`, { status: 'rejected' } );
		expect(
			cents( ( await api.get( `/payouts/${ payment.id }` ) ).body.amount )
		).toBe( 1000 );

		await api.del( `/payouts/${ payment.id }` );
	} );

	test( 'a paid payment: its commissions cannot be deleted, and its amount never moves', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 10 );
		const payment = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body.payouts[ 0 ];
		await api.put( `/payouts/${ payment.id }`, { status: 'paid' } );

		const del = await api.del( `/commissions/${ id }` );
		expect( del.status ).toBe( 409 );
		expect( del.body.code ).toBe( 'flyaffiliate_commission_in_payout' );

		await api.put( `/commissions/${ id }`, { status: 'rejected' } );
		await api.put( `/commissions/${ id }`, { amount: 1 } );
		expect(
			cents( ( await api.get( `/payouts/${ payment.id }` ) ).body.amount )
		).toBe( 1000 );
		expect( ( await api.del( `/payouts/${ payment.id }` ) ).status ).toBe(
			409
		);
	} );

	test( 'deleting an affiliate keeps their payment and commissions as records (ADR-0015)', async () => {
		const aff = createAffiliate();
		const id = await commission( aff.id, 7 );
		const payment = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body.payouts[ 0 ];

		expect(
			( await api.del( `/affiliates/${ aff.id }`, { force: true } ) )
				.status
		).toBe( 200 );
		expect( ( await api.get( `/payouts/${ payment.id }` ) ).status ).toBe(
			200
		);
		expect( ( await api.get( `/commissions/${ id }` ) ).status ).toBe(
			200
		);
		expect( ( await api.get( `/affiliates/${ aff.id }` ) ).status ).toBe(
			404
		);
	} );
} );
