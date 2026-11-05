/**
 * Checkout attribution: one commission per order item, rate hierarchy and
 * clamp, base amount, initial status and maturity date.
 *
 * CONTEXT.md money rules 1–4, 6–9; ADR-0005, ADR-0009 §3, ADR-0014.
 * Scenario ids: references/scenarios.md §2 (WC-*).
 */
import { expect, test } from '@playwright/test';
import { cents } from '../utils/api';
import {
	createAffiliate,
	createCoupon,
	createOrder,
	createProduct,
	orderCommissions,
	orderMeta,
	reattribute,
	restoreSettings,
	setAffiliateStatus,
	setOrderStatus,
	setSettings,
	withSettings,
	type TestAffiliate,
} from '../utils/fixtures';

const DAY = 24 * 60 * 60 * 1000;
const utc = ( mysql: string ) => Date.parse( mysql.replace( ' ', 'T' ) + 'Z' );
const holdDays = ( row: { created_at: string; matures_at: string | null } ) =>
	Math.round( ( utc( row.matures_at ?? '' ) - utc( row.created_at ) ) / DAY );

test.describe( 'Checkout attribution @money', () => {
	let aff: TestAffiliate;
	let plain: number; // $100, no product rate → default 10%.
	let rated: number; // $100, product rate 15%.
	let greedy: number; // $100, product rate 80% → clamped.

	withSettings( {
		default_rate: 10,
		max_rate: 50,
		hold_days: 30,
		exclude_shipping: 'on',
		exclude_tax: 'on',
		block_self_referral: 'on',
		woocommerce_enabled: 'on',
	} );

	test.beforeAll( () => {
		aff = createAffiliate();
		plain = createProduct( 100 );
		rated = createProduct( 100, 15 );
		greedy = createProduct( 100, 80 );
	} );

	test( '[WC-01] a referred order gets one pending commission per item at the default rate', () => {
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: plain } ],
		} );
		const rows = orderCommissions( order );

		expect( rows ).toHaveLength( 1 );
		const [ row ] = rows;
		expect( row ).toMatchObject( {
			affiliate_id: aff.id,
			status: 'pending',
			source: 'woocommerce',
			rate_type: 'percentage',
			vendor_id: 0,
			payout_id: 0,
		} );
		expect( row.order_item_id ).toBeGreaterThan( 0 );
		expect( cents( row.base_amount ) ).toBe( 10000 );
		expect( cents( row.amount ) ).toBe( 1000 );
		expect(
			Number( orderMeta( order, '_flyaffiliate_affiliate_id' ) )
		).toBe( aff.id );
	} );

	test( '[WC-03][WC-04] every line item is its own commission, product rate before default', () => {
		const order = createOrder( {
			affiliateId: aff.id,
			items: [
				{ productId: plain, qty: 2 },
				{ productId: rated, qty: 1 },
			],
		} );
		const rows = orderCommissions( order );

		expect( rows.map( ( r ) => cents( r.amount ) ) ).toEqual( [
			2000, 1500,
		] );
		expect( rows.map( ( r ) => r.rate ) ).toEqual( [ 10, 15 ] );
		expect( new Set( rows.map( ( r ) => r.order_item_id ) ).size ).toBe(
			2
		);
	} );

	test( '[WC-05] a product rate above the maximum is clamped', () => {
		const rows = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: greedy } ],
			} )
		);

		expect( rows[ 0 ].rate ).toBe( 50 );
		expect( cents( rows[ 0 ].amount ) ).toBe( 5000 );
	} );

	test( '[WC-06] lowering the maximum clamps the next orders at calculation time', () => {
		const before = setSettings( { max_rate: 12 } );

		try {
			const rows = orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [
						{ productId: rated },
						{ productId: greedy },
						{ productId: plain },
					],
				} )
			);
			expect( rows.map( ( r ) => r.rate ) ).toEqual( [ 12, 12, 10 ] );
			expect( rows.map( ( r ) => cents( r.amount ) ) ).toEqual( [
				1200, 1200, 1000,
			] );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[WC-07] shipping earns nothing while excluded, and its own commission when not', () => {
		const excluded = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: plain } ],
				shipping: 10,
			} )
		);
		expect( excluded ).toHaveLength( 1 );

		const before = setSettings( { exclude_shipping: 'off' } );

		try {
			const included = orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [ { productId: plain } ],
					shipping: 10,
				} )
			);
			expect(
				included
					.map( ( r ) => cents( r.amount ) )
					.sort( ( a, b ) => a - b )
			).toEqual( [ 100, 1000 ] );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[WC-09] the base amount is the discounted line total', () => {
		const coupon = createCoupon( 10 );
		const [ row ] = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: plain } ],
				coupon,
			} )
		);

		expect( cents( row.base_amount ) ).toBe( 9000 );
		expect( cents( row.amount ) ).toBe( 900 );
	} );

	test( '[WC-11] an order already paid at checkout makes the commission unpaid at once, whatever the hold', () => {
		const [ row ] = orderCommissions(
			createOrder( {
				affiliateId: aff.id,
				items: [ { productId: plain } ],
				paymentMethod: 'bacs',
				status: 'processing',
			} )
		);

		expect( row.status ).toBe( 'unpaid' );
		expect( holdDays( row ) ).toBe( 30 );
	} );

	test( '[WC-12] cash on delivery waits for completed, not processing', () => {
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: plain } ],
			paymentMethod: 'cod',
		} );

		setOrderStatus( order, 'processing' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'pending' );

		setOrderStatus( order, 'completed' );
		expect( orderCommissions( order )[ 0 ].status ).toBe( 'unpaid' );
	} );

	test( '[WC-13][WC-14] matures_at is fixed at creation; a new hold applies to new orders only', () => {
		const first = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: plain } ],
		} );
		const [ before ] = orderCommissions( first );
		expect( holdDays( before ) ).toBe( 30 );

		const restore = setSettings( { hold_days: 0 } );

		try {
			const [ after ] = orderCommissions( first );
			expect( after.matures_at ).toBe( before.matures_at );

			const [ fresh ] = orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					items: [ { productId: plain } ],
				} )
			);
			expect( holdDays( fresh ) ).toBe( 0 );
		} finally {
			restoreSettings( restore );
		}
	} );

	test( '[WC-15] an order without a referral cookie earns nothing', () => {
		expect(
			orderCommissions(
				createOrder( { items: [ { productId: plain } ] } )
			)
		).toHaveLength( 0 );
	} );

	test( '[WC-15] a forged cookie is ignored', () => {
		const order = createOrder( {
			cookie: `${ aff.id }|0|00000000000000000000000000000000`,
			items: [ { productId: plain } ],
		} );

		expect( orderCommissions( order ) ).toHaveLength( 0 );
		expect( orderMeta( order, '_flyaffiliate_affiliate_id' ) ).toBe( '' );
	} );

	test( '[WC-16] the WooCommerce integration switched off attributes nothing', () => {
		const before = setSettings( { woocommerce_enabled: 'off' } );

		try {
			expect(
				orderCommissions(
					createOrder( {
						affiliateId: aff.id,
						items: [ { productId: plain } ],
					} )
				)
			).toHaveLength( 0 );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[WC-17] the checkout hook firing again changes nothing, even with another cookie', () => {
		const other = createAffiliate();
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: plain }, { productId: rated } ],
		} );
		const first = orderCommissions( order );

		reattribute( order, aff.id );
		reattribute( order, other.id );

		expect( orderCommissions( order ) ).toEqual( first );
		expect(
			Number( orderMeta( order, '_flyaffiliate_affiliate_id' ) )
		).toBe( aff.id );
	} );

	test( '[TRK-03] a cookie for an affiliate who is no longer active earns nothing', () => {
		const gone = createAffiliate();
		setAffiliateStatus( gone.id, 'suspended' );

		expect(
			orderCommissions(
				createOrder( {
					affiliateId: gone.id,
					items: [ { productId: plain } ],
				} )
			)
		).toHaveLength( 0 );
	} );

	test( '[TRK-10] self-referral is blocked by account and by billing email', () => {
		expect(
			orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					customerId: aff.userId,
					items: [ { productId: plain } ],
				} )
			)
		).toHaveLength( 0 );
		expect(
			orderCommissions(
				createOrder( {
					affiliateId: aff.id,
					billingEmail: aff.email.toUpperCase(),
					items: [ { productId: plain } ],
				} )
			)
		).toHaveLength( 0 );
	} );

	test( '[TRK-11] with self-referral allowed, the affiliate earns on their own order', () => {
		const before = setSettings( { block_self_referral: 'off' } );

		try {
			expect(
				orderCommissions(
					createOrder( {
						affiliateId: aff.id,
						customerId: aff.userId,
						items: [ { productId: plain } ],
					} )
				)
			).toHaveLength( 1 );
		} finally {
			restoreSettings( before );
		}
	} );
} );
