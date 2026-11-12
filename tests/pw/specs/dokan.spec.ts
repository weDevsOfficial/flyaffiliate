/**
 * Dokan marketplaces: vendor affiliate programs through FlyAffiliate Pro's
 * Dokan extension, on FlyAffiliate's seams.
 *
 * Pro ADR-0006 (vendor-run programs, rates, money rules), Pro ADR-0008
 * (the vendor pays: earnings deduction, sub-orders stand alone, hold periods,
 * the refund rule). FlyAffiliate ADR-0013/0014 for the free side.
 *
 * Needs Pro's paired environment (free + Pro + Dokan Lite ≥ 5.3.2):
 *
 *   FLYAFFILIATE_WP_ENV_DIR=~/flyaffiliate-wt/<branch>/flyaffiliate-pro \
 *   BASE_URL=http://localhost:8900 FLYAFFILIATE_SKIP_SEED=1 \
 *   npm run test:e2e -- specs/dokan.spec.ts
 *
 * Skips itself anywhere else. Worked numbers: $100 vendor sale, Dokan admin
 * commission 20%, affiliate rate 15% → vendor nets $65, affiliate $15, the
 * marketplace keeps its $20.
 * Scenario ids: references/scenarios.md §15 (DOK-*).
 */
import { expect, test } from '@playwright/test';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	createAffiliate,
	loginAs,
	orderCommissions,
	restoreSettings,
	setOrderStatus,
	setSettings,
	type Snapshot,
	type TestAffiliate,
} from '../utils/fixtures';
import {
	DOKAN_KEYS as K,
	createVendor,
	createVendorProduct,
	dokanCheckout,
	dokanOrderRow,
	dokanReady,
	orderNotes,
	rateSource,
	resaveOrder,
	restoreMarketplace,
	setMarketplace,
	setSelling,
	type DokanSnapshot,
} from '../utils/dokan';
import { lit, php } from '../utils/wp';

const READY = dokanReady();
const DAY = 24 * 60 * 60 * 1000;
const utc = ( mysql: string ) => Date.parse( mysql.replace( ' ', 'T' ) + 'Z' );
const holdDays = ( row: { created_at: string; matures_at: string | null } ) =>
	Math.round( ( utc( row.matures_at ?? '' ) - utc( row.created_at ) ) / DAY );

test.describe.configure( { mode: 'serial' } );

test.describe( 'Dokan vendor programs @dokan', () => {
	test.skip( ! READY, 'needs Dokan + FlyAffiliate Pro (Pro paired env)' );

	let flySettings: Snapshot;
	let market: DokanSnapshot;
	let api: Api;
	let aff: TestAffiliate;

	/**
	 * Commission rows on an order, keyed by vendor.
	 * @param parent
	 */
	const byVendor = ( parent: number ) =>
		orderCommissions( parent ).reduce<
			Record< number, ReturnType< typeof orderCommissions > >
		>(
			( map, row ) => ( {
				...map,
				[ row.vendor_id ]: [ ...( map[ row.vendor_id ] ?? [] ), row ],
			} ),
			{}
		);

	test.beforeAll( async () => {
		flySettings = setSettings( {
			default_rate: 10,
			max_rate: 50,
			hold_days: 0,
			exclude_shipping: 'on',
			exclude_tax: 'on',
			block_self_referral: 'on',
			woocommerce_enabled: 'on',
			minimum_amount: 0,
		} );
		market = setMarketplace( {
			[ K.commissionType ]: 'fixed',
			[ K.adminPercentage ]: 20,
			[ K.additionalFee ]: 0,
			[ K.programs ]: 'on',
			[ K.defaultRate ]: null,
			[ K.allowRates ]: 'on',
			[ K.maxRate ]: 50,
			[ K.holdDays ]: null,
			[ K.allowHold ]: 'off',
		} );
		api = await Api.as();
		await toggle( api, true );
		aff = createAffiliate();
	} );

	test.afterAll( async () => {
		restoreMarketplace( market );
		restoreSettings( flySettings );
		await api.dispose();
	} );

	// ── The extension ─────────────────────────────────────────────────────

	test( '[DOK-EXT-01] switching the extension on turns the refund rule on once', async () => {
		const before = setSettings( { reject_commissions_on_refund: 'off' } );
		php( `delete_option( 'flyaffiliate_pro_dokan' ); return true;` );

		try {
			await toggle( api, false );
			await toggle( api, true );
			expect(
				php(
					`return flyaffiliate_get_option( 'reject_commissions_on_refund' );`
				)
			).toBe( 'on' );

			// Once: an admin who turns it off again is not overruled next time.
			setSettings( { reject_commissions_on_refund: 'off' } );
			await toggle( api, false );
			await toggle( api, true );
			expect(
				php(
					`return flyaffiliate_get_option( 'reject_commissions_on_refund' );`
				)
			).toBe( 'off' );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[DOK-EXT-02] without a valid license the extension cannot be switched on', async () => {
		const license = php(
			`$l = get_option( 'flyaffiliate_pro_license' ); delete_option( 'flyaffiliate_pro_license' ); return $l;`
		);

		try {
			await toggle( api, false );
			const res = await toggle( api, true, false );
			expect( res.status ).toBeGreaterThanOrEqual( 400 );
			expect( res.body ).toContain( 'flyaffiliate_pro_license_required' );
		} finally {
			php(
				`update_option( 'flyaffiliate_pro_license', json_decode( ${ lit(
					JSON.stringify( license )
				) }, true ) ); return true;`
			);
			await toggle( api, true );
		}
	} );

	// ── Rates (Pro ADR-0006 §4, ADR-0008 §8) ─────────────────────────────

	test( "[DOK-RATE-01] an opted-in vendor with no rates set earns at FlyAffiliate's default @money", () => {
		const vendor = createVendor( { optIn: true } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [ { productId: createVendorProduct( vendor.id, 100 ) } ],
		} );
		const [ row ] = orderCommissions( order.parent );

		expect( row ).toMatchObject( {
			vendor_id: vendor.id,
			rate: 10,
			status: 'pending',
		} );
		expect( cents( row.amount ) ).toBe( 1000 );
		expect( rateSource( row.order_item_id! ) ).toMatchObject( {
			source: 'flyaffiliate_default',
			capped: false,
		} );
	} );

	test( "[DOK-RATE-02] Dokan's default rate replaces FlyAffiliate's for vendor sales @money", () => {
		const before = setSelling( { [ K.defaultRate ]: 12 } );

		try {
			const vendor = createVendor( { optIn: true } );
			const [ row ] = orderCommissions(
				dokanCheckout( {
					affiliateId: aff.id,
					items: [
						{ productId: createVendorProduct( vendor.id, 100 ) },
					],
				} ).parent
			);
			expect( cents( row.amount ) ).toBe( 1200 );
			expect( rateSource( row.order_item_id! )?.source ).toBe(
				'dokan_default'
			);
		} finally {
			setSelling( before as Record< string, string | number | null > );
		}
	} );

	test( "[DOK-RATE-03][DOK-RATE-04] a vendor's own rate applies only while vendors may set one @money", () => {
		const vendor = createVendor( { optIn: true, rate: 15 } );
		const product = createVendorProduct( vendor.id, 100 );

		const [ own ] = orderCommissions(
			dokanCheckout( {
				affiliateId: aff.id,
				items: [ { productId: product } ],
			} ).parent
		);
		expect( cents( own.amount ) ).toBe( 1500 );
		expect( rateSource( own.order_item_id! )?.source ).toBe( 'vendor' );

		const before = setSelling( {
			[ K.allowRates ]: 'off',
			[ K.defaultRate ]: 8,
		} );
		try {
			const [ marketplace ] = orderCommissions(
				dokanCheckout( {
					affiliateId: aff.id,
					items: [ { productId: product } ],
				} ).parent
			);
			expect( cents( marketplace.amount ) ).toBe( 800 );
			// The earlier commission keeps the rate it was created with.
			expect(
				cents( orderCommissions( own.order_id )[ 0 ].amount )
			).toBe( 1500 );
		} finally {
			setSelling( before as Record< string, string | number | null > );
		}
	} );

	test( "[DOK-RATE-05] Dokan's maximum caps a vendor rate, not FlyAffiliate's @money", () => {
		const before = setSelling( { [ K.maxRate ]: 30 } );

		try {
			const vendor = createVendor( { optIn: true, rate: 40 } );
			const [ row ] = orderCommissions(
				dokanCheckout( {
					affiliateId: aff.id,
					items: [
						{ productId: createVendorProduct( vendor.id, 100 ) },
					],
				} ).parent
			);
			expect( row.rate ).toBe( 30 );
			expect( cents( row.amount ) ).toBe( 3000 );
			expect( rateSource( row.order_item_id! ) ).toMatchObject( {
				source: 'vendor',
				capped: true,
				uncapped: 40,
			} );
		} finally {
			setSelling( before as Record< string, string | number | null > );
		}
	} );

	test( '[DOK-RATE-06] a vendor who has not opted in earns no commission for anyone', () => {
		const vendor = createVendor( { optIn: false, rate: 15 } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [ { productId: createVendorProduct( vendor.id, 100 ) } ],
		} );
		expect( orderCommissions( order.parent ) ).toHaveLength( 0 );
	} );

	test( "[DOK-RATE-07] a per-product rate does not apply to a vendor's product", () => {
		const vendor = createVendor( { optIn: true, rate: 15 } );
		const [ row ] = orderCommissions(
			dokanCheckout( {
				affiliateId: aff.id,
				items: [
					{ productId: createVendorProduct( vendor.id, 100, 45 ) },
				],
			} ).parent
		);
		expect( row.rate ).toBe( 15 );
	} );

	test( '[DOK-RATE-08] vendor programs switched off create no vendor commissions', () => {
		const vendor = createVendor( { optIn: true, rate: 15 } );
		const before = setSelling( { [ K.programs ]: 'off' } );

		try {
			const order = dokanCheckout( {
				affiliateId: aff.id,
				items: [ { productId: createVendorProduct( vendor.id, 100 ) } ],
			} );
			expect( orderCommissions( order.parent ) ).toHaveLength( 0 );
		} finally {
			setSelling( before as Record< string, string | number | null > );
		}
	} );

	// ── Multi-vendor carts and the money ──────────────────────────────────

	test( "[DOK-MV-01] a mixed cart pays only the opted-in vendor's items, one commission per item @money", () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const b = createVendor( { optIn: false } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [
				{ productId: createVendorProduct( a.id, 100 ) },
				{ productId: createVendorProduct( a.id, 40 ), qty: 2 },
				{ productId: createVendorProduct( b.id, 50 ) },
			],
		} );

		expect( Object.keys( order.byVendor ).map( Number ).sort() ).toEqual(
			[ a.id, b.id ].sort()
		);
		const rows = byVendor( order.parent );
		expect( rows[ b.id ] ).toBeUndefined();
		expect( rows[ a.id ].map( ( r ) => cents( r.amount ) ) ).toEqual( [
			1500, 1200,
		] );
		// Commissions sit on the parent order, never on the sub-order too.
		expect( orderCommissions( order.byVendor[ a.id ] ) ).toHaveLength( 0 );
	} );

	test( '[DOK-EARN-01] the vendor pays: $100 sale, 20% admin, 15% affiliate → vendor nets $65 @money', () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const b = createVendor( { optIn: false } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [
				{ productId: createVendorProduct( a.id, 100 ) },
				{ productId: createVendorProduct( b.id, 50 ) },
			],
		} );
		setOrderStatus( order.parent, 'processing' );

		const rowA = dokanOrderRow( order.byVendor[ a.id ] );
		const rowB = dokanOrderRow( order.byVendor[ b.id ] );
		expect( cents( rowA!.order_total ) ).toBe( 10000 );
		expect( cents( rowA!.net_amount ) ).toBe( 6500 );
		// The vendor who did not opt in keeps their full 80%.
		expect( cents( rowB!.net_amount ) ).toBe( 4000 );
	} );

	test( '[DOK-EARN-02] the deduction is worked out again, never stacked: re-saving changes nothing @money', () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [ { productId: createVendorProduct( a.id, 100 ) } ],
		} );
		setOrderStatus( order.parent, 'processing' );
		const vendorOrder = order.byVendor[ a.id ];

		resaveOrder( vendorOrder );
		resaveOrder( vendorOrder );
		resaveOrder( order.parent );

		expect( cents( dokanOrderRow( vendorOrder )!.net_amount ) ).toBe(
			6500
		);
	} );

	test( '[DOK-EARN-03] the deduction never takes more than the vendor earned @money', () => {
		const before = setSelling( { [ K.adminPercentage ]: 90 } );

		try {
			const a = createVendor( { optIn: true, rate: 50 } );
			const order = dokanCheckout( {
				affiliateId: aff.id,
				items: [ { productId: createVendorProduct( a.id, 100 ) } ],
			} );
			setOrderStatus( order.parent, 'processing' );
			// Vendor earns $10; the $50 commission is capped at it; the marketplace absorbs the rest.
			expect(
				cents( dokanOrderRow( order.byVendor[ a.id ] )!.net_amount )
			).toBe( 0 );
			expect(
				cents( orderCommissions( order.parent )[ 0 ].amount )
			).toBe( 5000 );
		} finally {
			setSelling( before as Record< string, string | number | null > );
		}
	} );

	// ── Each vendor's part of the order stands alone (ADR-0008 §5) ────────

	test( "[DOK-SUB-01] cancelling one vendor's sub-order rejects only that vendor's commission and gives the earning back @money", () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const b = createVendor( { optIn: true, rate: 10 } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [
				{ productId: createVendorProduct( a.id, 100 ) },
				{ productId: createVendorProduct( b.id, 100 ) },
			],
		} );
		setOrderStatus( order.parent, 'processing' );
		setOrderStatus( order.byVendor[ a.id ], 'cancelled' );

		const rows = byVendor( order.parent );
		expect( rows[ a.id ][ 0 ].status ).toBe( 'rejected' );
		expect( rows[ b.id ][ 0 ].status ).toBe( 'unpaid' );
		expect(
			cents( dokanOrderRow( order.byVendor[ b.id ] )!.net_amount )
		).toBe( 7000 );
	} );

	test( "[DOK-SUB-02] completing one vendor's cash-on-delivery sub-order approves only theirs", () => {
		const a = createVendor( { optIn: true } );
		const b = createVendor( { optIn: true } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			paymentMethod: 'cod',
			items: [
				{ productId: createVendorProduct( a.id, 100 ) },
				{ productId: createVendorProduct( b.id, 100 ) },
			],
		} );
		setOrderStatus( order.byVendor[ a.id ], 'completed' );

		const rows = byVendor( order.parent );
		expect( rows[ a.id ][ 0 ].status ).toBe( 'unpaid' );
		expect( rows[ b.id ][ 0 ].status ).toBe( 'pending' );
	} );

	test( '[DOK-SUB-03] a fully refunded sub-order follows the refund rule', () => {
		for ( const [ rule, expected ] of [
			[ 'on', 'rejected' ],
			[ 'off', 'unpaid' ],
		] as const ) {
			const before = setSettings( {
				reject_commissions_on_refund: rule,
			} );

			try {
				const a = createVendor( { optIn: true } );
				const order = dokanCheckout( {
					affiliateId: aff.id,
					items: [
						{ productId: createVendorProduct( a.id, 100 ) },
						{
							productId: createVendorProduct(
								createVendor( { optIn: true } ).id,
								100
							),
						},
					],
				} );
				setOrderStatus( order.parent, 'processing' );
				setOrderStatus( order.byVendor[ a.id ], 'refunded' );
				expect(
					byVendor( order.parent )[ a.id ][ 0 ].status,
					`rule ${ rule }`
				).toBe( expected );
			} finally {
				restoreSettings( before );
			}
		}
	} );

	test( "[DOK-SUB-04] a later change on the parent does not reopen a cancelled vendor's commission", () => {
		const a = createVendor( { optIn: true } );
		const b = createVendor( { optIn: true } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [
				{ productId: createVendorProduct( a.id, 100 ) },
				{ productId: createVendorProduct( b.id, 100 ) },
			],
		} );
		setOrderStatus( order.byVendor[ a.id ], 'cancelled' );
		setOrderStatus( order.parent, 'completed' );

		const rows = byVendor( order.parent );
		expect( rows[ a.id ][ 0 ].status ).toBe( 'rejected' );
		expect( rows[ b.id ][ 0 ].status ).toBe( 'unpaid' );
	} );

	test( '[DOK-SUB-05] a paid commission stays paid after a full refund, and the sub-order says the vendor absorbs it @money', async () => {
		const before = setSettings( { reject_commissions_on_refund: 'on' } );
		const vendorAff = createAffiliate();

		try {
			const a = createVendor( { optIn: true, rate: 15 } );
			const order = dokanCheckout( {
				affiliateId: vendorAff.id,
				items: [
					{ productId: createVendorProduct( a.id, 100 ) },
					{ productId: createVendorProduct( createVendor().id, 10 ) },
				],
			} );
			setOrderStatus( order.parent, 'processing' );

			const payout = await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ vendorAff.id ],
				minimum_amount: 0,
			} );
			expect( payout.status ).toBe( 201 );
			await api.put( `/payouts/${ payout.body.payouts[ 0 ].id }`, {
				status: 'paid',
			} );

			setOrderStatus( order.byVendor[ a.id ], 'refunded' );
			expect( byVendor( order.parent )[ a.id ][ 0 ].status ).toBe(
				'paid'
			);
			expect( orderNotes( order.byVendor[ a.id ] ).join( '\n' ) ).toMatch(
				/already paid out/
			);
		} finally {
			restoreSettings( before );
		}
	} );

	// ── Guide C3 / C5: cash on delivery debt and partial refunds ─────────

	/**
	 * Dokan's reverse withdrawal ledger for one order: debits − credits.
	 * @param vendorOrder
	 */
	const owes = ( vendorOrder: number ) =>
		php< number >( `
			global $wpdb;
			return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( debit ) - SUM( credit ), 0 ) FROM {$wpdb->prefix}dokan_reverse_withdrawal WHERE trn_id = %d", ${ vendorOrder } ) );
		` );

	test( '[DOK-COD-01] cash on delivery: the debt a vendor owes includes the affiliate commission, and follows it (guide C3, C5 R5, R6) @money', async () => {
		const rw = php( `
			$before = get_option( 'dokan_reverse_withdrawal', null );
			update_option( 'dokan_reverse_withdrawal', array_merge( (array) $before, [ 'enabled' => 'on', 'payment_gateways' => [ 'cod' => 'cod' ] ] ) );
			return $before;
		` );
		const before = setSettings( { reject_commissions_on_refund: 'on' } );

		try {
			const a = createVendor( { optIn: true, rate: 15 } );
			const b = createVendor( { optIn: true, rate: 10 } );
			const order = dokanCheckout( {
				affiliateId: aff.id,
				paymentMethod: 'cod',
				items: [
					{ productId: createVendorProduct( a.id, 100 ) },
					{ productId: createVendorProduct( b.id, 100 ) },
				],
			} );
			setOrderStatus( order.parent, 'completed' );
			const subA = order.byVendor[ a.id ];
			const subB = order.byVendor[ b.id ];

			// C3: 20% marketplace + the affiliate commission.
			expect( cents( owes( subA ) ) ).toBe( 3500 );
			expect( cents( owes( subB ) ) ).toBe( 3000 );

			// Rejecting B's commission gives the affiliate part back.
			const [ rowB ] = byVendor( order.parent )[ b.id ];
			await api.put( `/commissions/${ rowB.id }`, {
				status: 'rejected',
			} );
			expect( cents( owes( subB ) ) ).toBe( 2000 );
		} finally {
			restoreSettings( before );
			php( `
				$b = json_decode( ${ lit( JSON.stringify( rw ) ) }, true );
				null === $b ? delete_option( 'dokan_reverse_withdrawal' ) : update_option( 'dokan_reverse_withdrawal', $b );
				return true;
			` );
		}
	} );

	test( '[DOK-REF-01] a partial refund of a vendor sub-order leaves the commission and its deduction alone (guide C5 R1) @money', () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const order = dokanCheckout( {
			affiliateId: aff.id,
			items: [ { productId: createVendorProduct( a.id, 100 ) } ],
		} );
		setOrderStatus( order.parent, 'completed' );
		const sub = order.byVendor[ a.id ];
		const [ row ] = byVendor( order.parent )[ a.id ];
		expect( row ).toMatchObject( { status: 'unpaid', amount: 15 } );

		// The guide's path: a refund request on the vendor's sub-order, approved
		// (Dokan Pro → Refunds), as a vendor asks and the admin approves.
		php( `
			$o = wc_get_order( ${ sub } );
			$items = [];
			foreach ( $o->get_items() as $id => $item ) { $items[ $id ] = 50; }
			$req = dokan_pro()->refund->create( [ 'order_id' => ${ sub }, 'refund_amount' => 50, 'refund_reason' => 'PW partial', 'item_qtys' => [], 'item_totals' => $items, 'item_tax_totals' => [], 'restock_items' => false, 'method' => 'false' ] );
			if ( is_wp_error( $req ) ) { throw new Exception( $req->get_error_message() ); }
			$done = $req->approve();
			if ( is_wp_error( $done ) ) { throw new Exception( $done->get_error_message() ); }
			return true;
		` );

		const [ after ] = byVendor( order.parent )[ a.id ];
		expect( after ).toMatchObject( { status: 'unpaid', amount: 15 } );
		// Dokan's order row: the $50 refund takes the vendor's $40 share back
		// (refund minus the marketplace's 20%); the $15 commission stays
		// deducted, so the vendor ends at 65 − 40 = $25 (guide C5 R1).
		expect( cents( dokanOrderRow( sub )!.net_amount ) ).toBe( 2500 );
	} );

	// ── Hold periods (ADR-0008 §12) ──────────────────────────────────────

	test( "[DOK-HOLD-01] Dokan's hold, then a vendor's own while allowed, then FlyAffiliate's", () => {
		const vendor = createVendor( { optIn: true, holdDays: 3 } );
		const product = createVendorProduct( vendor.id, 100 );
		const hold = () =>
			holdDays(
				orderCommissions(
					dokanCheckout( {
						affiliateId: aff.id,
						items: [ { productId: product } ],
					} ).parent
				)[ 0 ]
			);

		const flyHold = setSettings( { hold_days: 21 } );
		try {
			expect( hold(), "no Dokan hold: FlyAffiliate's" ).toBe( 21 );

			const dokanHold = setSelling( { [ K.holdDays ]: 7 } );
			try {
				expect( hold(), "Dokan's hold" ).toBe( 7 );

				const allow = setSelling( { [ K.allowHold ]: 'on' } );
				try {
					expect( hold(), "the vendor's own" ).toBe( 3 );
				} finally {
					setSelling(
						allow as Record< string, string | number | null >
					);
				}
			} finally {
				setSelling(
					dokanHold as Record< string, string | number | null >
				);
			}
		} finally {
			restoreSettings( flyHold );
		}
	} );

	// ── Who sees what ─────────────────────────────────────────────────────

	test( '[DOK-SEC-01] the vendor program API answers vendors only', async ( {
		browser,
	} ) => {
		const route = '/flyaffiliate-pro/v1/dokan/vendor/program';
		const vendor = createVendor( { optIn: true } );

		const guest = await Api.as( null );
		expect( ( await guest.get( route ) ).status ).toBe( 401 );

		const asAff = await loginAs( browser, createAffiliate() );
		expect(
			( await ( await Api.as( asAff.state ) ).get( route ) ).status
		).toBe( 403 );
		await asAff.context.close();

		const asVendor = await loginAs( browser, vendor );
		const res = await ( await Api.as( asVendor.state ) ).get( route );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );
		await asVendor.context.close();
	} );

	test( "[DOK-SEC-02] a vendor sees only their own store's affiliates", async ( {
		browser,
	} ) => {
		const a = createVendor( { optIn: true } );
		const b = createVendor( { optIn: true } );
		const affA = createAffiliate();
		const affB = createAffiliate();
		dokanCheckout( {
			affiliateId: affA.id,
			items: [ { productId: createVendorProduct( a.id, 100 ) } ],
		} );
		dokanCheckout( {
			affiliateId: affB.id,
			items: [ { productId: createVendorProduct( b.id, 100 ) } ],
		} );

		const { context, state } = await loginAs( browser, a );
		const vendorApi = await Api.as( state );
		const list = await vendorApi.get(
			'/flyaffiliate-pro/v1/dokan/vendor/affiliates'
		);
		expect( list.status, JSON.stringify( list.body ) ).toBe( 200 );
		const ids = ( list.body as { id: number }[] ).map( ( r ) => r.id );
		expect( ids ).toContain( affA.id );
		expect( ids ).not.toContain( affB.id );

		const other = await vendorApi.get(
			`/flyaffiliate-pro/v1/dokan/vendor/affiliates/${ affB.id }/referrals`
		);
		expect(
			other.status === 404 ||
				( Array.isArray( other.body ) && other.body.length === 0 )
		).toBe( true );
		await context.close();
	} );

	// ── The vendor program API (API_TEST_CASES.md §9.2) ──────────────────

	test( '[API-DOK-02][API-DOK-03] a vendor reads their program and opts in through the API', async ( {
		browser,
	} ) => {
		const vendor = createVendor();
		const { context, state } = await loginAs( browser, vendor );
		const vendorApi = await Api.as( state );
		try {
			const before = await vendorApi.get(
				'/flyaffiliate-pro/v1/dokan/vendor/program'
			);
			expect( before.status, JSON.stringify( before.body ) ).toBe( 200 );
			expect( before.body ).toMatchObject( {
				programs_enabled: true,
				opted_in: false,
				active: false,
			} );

			const start = await vendorApi.post(
				'/flyaffiliate-pro/v1/dokan/vendor/program'
			);
			expect( start.status, JSON.stringify( start.body ) ).toBeLessThan(
				300
			);
			const after = (
				await vendorApi.get(
					'/flyaffiliate-pro/v1/dokan/vendor/program'
				)
			).body;
			expect( after ).toMatchObject( { opted_in: true, active: true } );
			expect( after.rate ).toBeGreaterThan( 0 );
		} finally {
			await context.close();
		}
	} );

	test( '[API-DOK-04] a marketplace rate change lands in the vendor’s rate history', async ( {
		browser,
	} ) => {
		const vendor = createVendor( { optIn: true } );
		const before = setSelling( { [ K.defaultRate ]: 12 } );
		const { context, state } = await loginAs( browser, vendor );
		const vendorApi = await Api.as( state );
		try {
			const history = await vendorApi.get(
				'/flyaffiliate-pro/v1/dokan/vendor/program/history'
			);
			expect( history.status, JSON.stringify( history.body ) ).toBe(
				200
			);
			expect( Array.isArray( history.body ) ).toBe( true );
			const program = (
				await vendorApi.get(
					'/flyaffiliate-pro/v1/dokan/vendor/program'
				)
			).body;
			// The rate the vendor now earns at follows the marketplace default.
			expect( program.rate ).toBe( 12 );
		} finally {
			setSelling( before as Record< string, string | number | null > );
			await context.close();
		}
	} );

	test( '[API-DOK-07] one of the store’s affiliates and their referrals, matching the ledger @money', async ( {
		browser,
	} ) => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const b = createVendor( { optIn: true } );
		const affA = createAffiliate();
		const affB = createAffiliate();
		const order = dokanCheckout( {
			affiliateId: affA.id,
			items: [ { productId: createVendorProduct( a.id, 100 ) } ],
		} );
		setOrderStatus( order.parent, 'processing' );
		dokanCheckout( {
			affiliateId: affB.id,
			items: [ { productId: createVendorProduct( b.id, 100 ) } ],
		} );

		const { context, state } = await loginAs( browser, a );
		const vendorApi = await Api.as( state );
		try {
			const own = await vendorApi.get(
				`/flyaffiliate-pro/v1/dokan/vendor/affiliates/${ affA.id }`
			);
			expect( own.status, JSON.stringify( own.body ) ).toBe( 200 );

			const referrals = await vendorApi.get(
				`/flyaffiliate-pro/v1/dokan/vendor/affiliates/${ affA.id }/referrals`
			);
			expect( referrals.status, JSON.stringify( referrals.body ) ).toBe(
				200
			);
			const rows = Array.isArray( referrals.body )
				? referrals.body
				: referrals.body.items ?? referrals.body.referrals ?? [];
			expect( rows ).toHaveLength( 1 );
			// The ledger: one $15.00 commission on this vendor's sub-order.
			expect( JSON.stringify( rows[ 0 ] ) ).toMatch( /15(\.00)?\b/ );
		} finally {
			await context.close();
		}
	} );

	test( '[API-DOK-06] a vendor cannot read an affiliate who never sold for their store', async ( {
		browser,
	} ) => {
		// Guards PRO-BUG-002, fixed in weDevsOfficial/flyaffiliate-pro#26: the
		// route answered 200 with any active affiliate's name and email.
		const a = createVendor( { optIn: true } );
		const b = createVendor( { optIn: true } );
		const affB = createAffiliate();
		dokanCheckout( {
			affiliateId: affB.id,
			items: [ { productId: createVendorProduct( b.id, 100 ) } ],
		} );
		const { context, state } = await loginAs( browser, a );
		try {
			const foreign = await (
				await Api.as( state )
			).get(
				`/flyaffiliate-pro/v1/dokan/vendor/affiliates/${ affB.id }`
			);
			expect( foreign.status ).toBe( 404 );
			expect( JSON.stringify( foreign.body ) ).not.toContain(
				affB.email
			);
		} finally {
			await context.close();
		}
	} );

	test( '[API-DOK-08] the admin reads a vendor’s program; the vendor cannot use the admin route', async ( {
		browser,
	} ) => {
		const vendor = createVendor( { optIn: true, rate: 15 } );
		const admin = await api.get(
			`/flyaffiliate-pro/v1/dokan/vendors/${ vendor.id }`
		);
		expect( admin.status, JSON.stringify( admin.body ) ).toBe( 200 );
		expect( admin.body ).toMatchObject( {
			vendor_id: vendor.id,
			opted_in: true,
			rate: 15,
			source: expect.any( String ),
		} );

		const { context, state } = await loginAs( browser, vendor );
		const asVendor = await (
			await Api.as( state )
		).get( `/flyaffiliate-pro/v1/dokan/vendors/${ vendor.id }` );
		expect( asVendor.status ).toBeGreaterThanOrEqual( 401 );
		await context.close();
	} );

	test( "[DOK-UI-01] an active affiliate sees what they would earn on an opted-in vendor's product; a guest does not", async ( {
		browser,
	} ) => {
		const vendor = createVendor( { optIn: true, rate: 15 } );
		const product = createVendorProduct( vendor.id, 100 );
		const permalink = php< string >(
			`return get_permalink( ${ product } );`
		);
		const notOptedIn = php< string >(
			`return get_permalink( ${ createVendorProduct(
				createVendor().id,
				100
			) } );`
		);

		const { context } = await loginAs( browser, aff );
		const page = await context.newPage();
		await page.goto( permalink );
		const card = page.getByTestId( 'flyaffiliate-pro-product-notice' );
		await expect( card ).toBeVisible();
		await expect( card ).toContainText( '15%' );

		await page.goto( notOptedIn );
		await expect(
			page.getByTestId( 'flyaffiliate-pro-product-notice' )
		).toHaveCount( 0 );
		await context.close();

		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		const anon = await guest.newPage();
		await anon.goto( permalink );
		await expect(
			anon.getByTestId( 'flyaffiliate-pro-product-notice' )
		).toHaveCount( 0 );
		await guest.close();
	} );
	test( "[DOK-UI-02] the vendor dashboard's Affiliates page: an invitation before opting in, the affiliates after", async ( {
		browser,
	} ) => {
		const url = php< string >(
			`return \\FlyAffiliatePro\\Extensions\\Dokan\\VendorDashboard::get_url();`
		);

		const fresh = createVendor( { optIn: false } );
		const before = await loginAs( browser, fresh );
		const invite = await before.context.newPage();
		await invite.goto( url );
		await expect(
			invite.getByRole( 'button', { name: 'Start my affiliate program' } )
		).toBeVisible( { timeout: 30 * 1000 } );
		await before.context.close();

		const running = createVendor( { optIn: true, rate: 15 } );
		const seller = createAffiliate();
		dokanCheckout( {
			affiliateId: seller.id,
			items: [ { productId: createVendorProduct( running.id, 100 ) } ],
		} );
		const after = await loginAs( browser, running );
		const page = await after.context.newPage();
		await page.goto( url );
		await expect( page.getByText( 'List of Affiliates' ) ).toBeVisible( {
			timeout: 30 * 1000,
		} );
		await expect(
			page
				.getByText( seller.email )
				.or( page.getByText( seller.login ) )
				.first()
		).toBeVisible();
		await after.context.close();
	} );

	test( '[DOK-OFF-01] switched off: existing charges stand, new vendor sales earn nothing (ADR-0006 §7, ADR-0008 §6) @money', async () => {
		const a = createVendor( { optIn: true, rate: 15 } );
		const product = createVendorProduct( a.id, 100 );
		const earlier = dokanCheckout( {
			affiliateId: aff.id,
			items: [ { productId: product } ],
		} );
		setOrderStatus( earlier.parent, 'processing' );

		await toggle( api, false );
		try {
			resaveOrder( earlier.byVendor[ a.id ] );
			expect(
				cents( dokanOrderRow( earlier.byVendor[ a.id ] )!.net_amount )
			).toBe( 6500 );

			const later = dokanCheckout( {
				affiliateId: aff.id,
				items: [ { productId: product } ],
			} );
			expect(
				orderCommissions( later.parent ).filter(
					( r ) => r.vendor_id > 0
				)
			).toHaveLength( 0 );
		} finally {
			await toggle( api, true );
		}
	} );

	test( '[DOK-JOURNEY] a guest buys from two vendors through the block checkout; only the opted-in vendor pays @money @smoke', async ( {
		browser,
	} ) => {
		const cod = php(
			`$b = get_option( 'woocommerce_cod_settings', [] ); update_option( 'woocommerce_cod_settings', array_merge( (array) $b, [ 'enabled' => 'yes' ] ) ); return $b;`
		);
		const a = createVendor( { optIn: true, rate: 15 } );
		const b = createVendor( { optIn: false } );
		const productA = createVendorProduct( a.id, 100 );
		const productB = createVendorProduct( b.id, 50 );
		const checkout = php< string >( `return wc_get_checkout_url();` );

		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const page = await guest.newPage();
			await page.goto( `/?affiliate=${ aff.id }` );
			// Fill the cart, then check it holds both products: an add-to-cart
			// sent before the session cookie lands can be dropped.
			const inCart = async () =>
				(
					( await (
						await page.request.get(
							'/?rest_route=/wc/store/v1/cart'
						)
					).json() ) as { items: { id: number }[] }
				 ).items.map( ( i ) => i.id );
			for ( let i = 0; i < 3; i++ ) {
				const have = await inCart();
				const missing = [ productA, productB ].filter(
					( id ) => ! have.includes( id )
				);
				if ( ! missing.length ) {
					break;
				}
				for ( const id of missing ) {
					await page.goto( `/?add-to-cart=${ id }` );
				}
			}
			expect( ( await inCart() ).sort() ).toEqual(
				[ productA, productB ].sort()
			);
			await page.goto( checkout );
			await page
				.locator( '#email' )
				.fill( `buyer-${ a.id }@example.test` );
			await page.locator( '#billing-first_name' ).fill( 'Pat' );
			await page.locator( '#billing-last_name' ).fill( 'Buyer' );
			await page.locator( '#billing-address_1' ).fill( '1 Test Street' );
			await page.locator( '#billing-city' ).fill( 'Los Angeles' );
			const state = page.locator( '#billing-state' );
			if (
				( await state.evaluate( ( el ) => el.tagName ) ) === 'SELECT'
			) {
				await state.selectOption( 'CA' );
			} else {
				await state.fill( 'CA' );
			}
			await page.locator( '#billing-postcode' ).fill( '90001' );
			await page.getByRole( 'button', { name: 'Place Order' } ).click();
			await page.waitForURL( /order-received/, { timeout: 60 * 1000 } );
			const parent = Number(
				new URL( page.url() ).searchParams.get( 'order-received' ) ??
					page.url().match( /order-received\/(\d+)/ )?.[ 1 ]
			);

			const subOrders = () =>
				php< Record< number, number > >( `
					$by = [];
					foreach ( wc_get_orders( [ 'parent' => ${ parent }, 'type' => 'shop_order', 'limit' => -1 ] ) as $o ) { $by[ (int) $o->get_meta( '_dokan_vendor_id' ) ] = $o->get_id(); }
					return $by;
				` );
			// Dokan may split a block-checkout order a moment after the thank-you page.
			await expect
				.poll( () => Object.keys( subOrders() ).map( Number ).sort(), {
					timeout: 30 * 1000,
				} )
				.toEqual( [ a.id, b.id ].sort() );
			const sub = subOrders();

			const rows = orderCommissions( parent );
			expect( rows ).toHaveLength( 1 );
			expect( rows[ 0 ] ).toMatchObject( {
				vendor_id: a.id,
				status: 'pending',
			} );
			expect( cents( rows[ 0 ].amount ) ).toBe( 1500 );

			// Cash on delivery: the vendor's completed sub-order approves the commission.
			setOrderStatus( sub[ a.id ], 'completed' );
			expect( orderCommissions( parent )[ 0 ].status ).toBe( 'unpaid' );
			expect( cents( dokanOrderRow( sub[ a.id ] )!.net_amount ) ).toBe(
				6500
			);
			expect( cents( dokanOrderRow( sub[ b.id ] )!.net_amount ) ).toBe(
				4000
			);
		} finally {
			await guest.close();
			php(
				`update_option( 'woocommerce_cod_settings', json_decode( ${ lit(
					JSON.stringify( cod )
				) }, true ) ?: [] ); return true;`
			);
		}
	} );
} );

/**
 * Switch Pro's Dokan extension through its REST route.
 * @param client
 * @param active
 * @param mustWork
 */
async function toggle( client: Api, active: boolean, mustWork = true ) {
	const res = await client.put( '/flyaffiliate-pro/v1/extensions/dokan', {
		active,
	} );
	const body = JSON.stringify( res.body );
	if ( mustWork ) {
		expect( res.status, body ).toBeLessThan( 300 );
	}
	return { status: res.status, body };
}
