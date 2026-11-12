/**
 * The admin's records through the REST API the admin app uses: affiliates
 * and hand-entered commissions, their rules and their refusals.
 *
 * ADR-0011 (amended: any unpaid amount is editable), ADR-0012, ADR-0013
 * (a WooCommerce reference must exist while WooCommerce can answer),
 * ADR-0015 (deleting an affiliate deletes their saved links), CONTEXT.md
 * (any status to any status by hand).
 * Scenario ids: references/scenarios.md §4, §6 (COM-*, AFF-*).
 */
import { expect, test } from '@playwright/test';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	affiliateVisits,
	createAffiliate,
	createOrder,
	createProduct,
	createUser,
	orderCommissions,
	setOrderStatus,
} from '../utils/fixtures';
import { php } from '../utils/wp';

let api: Api;

test.beforeAll( async () => {
	api = await Api.as();
} );

test.afterAll( async () => api.dispose() );

test.describe( 'Affiliates', () => {
	test( '[AFF-02] an admin makes an existing user an affiliate; the role follows', async () => {
		const user = createUser();
		const res = await api.post( '/affiliates', {
			user_id: user.userId,
			status: 'active',
			payment_email: user.email,
		} );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 201 );
		expect( res.body.status ).toBe( 'active' );
		expect( res.body.referral_url ).toContain(
			`affiliate=${ res.body.id }`
		);
		expect(
			php(
				`return in_array( 'flyaffiliate_affiliate', get_userdata( ${ user.userId } )->roles, true );`
			)
		).toBe( true );
	} );

	test( '[AFF-03] the same user twice is refused', async () => {
		const aff = createAffiliate();
		const res = await api.post( '/affiliates', {
			user_id: aff.userId,
			status: 'active',
		} );
		expect( res.status ).toBe( 409 );
	} );

	test( '[AFF-02] a user that does not exist is refused', async () => {
		expect(
			( await api.post( '/affiliates', { user_id: 99999999 } ) ).status
		).toBe( 400 );
	} );

	test( '[AFF-07] deleting an affiliate removes their saved links, keeps their money and visits', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const commission = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 3,
			source: 'manual',
			status: 'unpaid',
		} );
		php(
			`flyaffiliate()->referral_link->create( ${ aff.id }, home_url( '/?p=1' ) ); return true;`
		);
		const guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		await ( await guest.newPage() ).goto( `/?affiliate=${ aff.id }` );
		await guest.close();

		const res = await api.del( `/affiliates/${ aff.id }`, { force: true } );
		expect( res.status, JSON.stringify( res.body ) ).toBe( 200 );

		const links = php< number >(
			`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_referral_links WHERE affiliate_id = %d", ${ aff.id } ) );`
		);
		expect( links ).toBe( 0 );
		expect( affiliateVisits( aff.id ) ).toHaveLength( 1 );
		const kept = php< number >(
			`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_commissions WHERE id = %d", ${ commission.body.id } ) );`
		);
		expect( kept ).toBe( 1 );
	} );
} );

test.describe( 'Commissions by hand', () => {
	test( '[COM-05] a WooCommerce-origin commission needs an order that exists', async () => {
		const aff = createAffiliate();
		const order = createOrder( {
			items: [ { productId: createProduct( 10 ) } ],
		} );

		expect(
			(
				await api.post( '/commissions', {
					affiliate_id: aff.id,
					amount: 1,
					source: 'woocommerce',
					order_id: 987654321,
				} )
			).status
		).toBe( 400 );
		const ok = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 1,
			source: 'woocommerce',
			order_id: order,
		} );
		expect( ok.status ).toBe( 201 );
	} );

	test( '[COM-06] a pending commission added by hand stays pending', async () => {
		const aff = createAffiliate();
		const res = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 2,
			source: 'manual',
			status: 'pending',
		} );
		expect( res.body.status ).toBe( 'pending' );
		expect(
			( await api.get( `/commissions/${ res.body.id }` ) ).body.status
		).toBe( 'pending' );
	} );

	test( '[COM-03] with no status given, a hand-entered commission is unpaid (owed)', async () => {
		const aff = createAffiliate();
		const res = await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 2,
			source: 'manual',
		} );
		expect( res.body.status ).toBe( 'unpaid' );
	} );

	test( '[COM-07] invalid input is refused and nothing is stored', async () => {
		const aff = createAffiliate();
		const count = () =>
			php< number >(
				`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_commissions WHERE affiliate_id = %d", ${ aff.id } ) );`
			);

		for ( const body of [
			{ affiliate_id: 0, amount: 5 },
			{ affiliate_id: 99999999, amount: 5 },
			{ affiliate_id: aff.id, amount: 0 },
			{ affiliate_id: aff.id, amount: -3 },
			{ affiliate_id: aff.id, amount: 0.004 },
			{ affiliate_id: aff.id, amount: 5, status: 'approved' },
			{ affiliate_id: aff.id, amount: 5, source: 'nowhere' },
		] ) {
			const res = await api.post( '/commissions', {
				source: 'manual',
				...body,
			} );
			expect( res.status, JSON.stringify( body ) ).toBe( 400 );
		}
		expect( count() ).toBe( 0 );
	} );

	test( '[COM-09] the amount of an order commission is editable and sticks', async () => {
		const aff = createAffiliate();
		const order = createOrder( {
			affiliateId: aff.id,
			items: [ { productId: createProduct( 100 ) } ],
			paymentMethod: 'bacs',
		} );
		const [ row ] = orderCommissions( order );

		const res = await api.put( `/commissions/${ row.id }`, {
			amount: 7.77,
		} );
		expect( res.status ).toBe( 200 );

		// The order moving on does not recalculate it.
		setOrderStatus( order, 'completed' );
		const [ after ] = orderCommissions( order );
		expect( after.status ).toBe( 'unpaid' );
		expect( cents( after.amount ) ).toBe( 777 );

		// Its reference is fixed to the order item.
		expect(
			(
				await api.put( `/commissions/${ row.id }`, {
					order_id: order + 1,
				} )
			).status
		).toBe( 409 );
	} );

	test( '[COM-10] an admin may move a commission between any two statuses', async () => {
		const aff = createAffiliate();
		const id = (
			await api.post( '/commissions', {
				affiliate_id: aff.id,
				amount: 1,
				source: 'manual',
				status: 'pending',
			} )
		).body.id;
		const path = [
			'unpaid',
			'rejected',
			'pending',
			'rejected',
			'unpaid',
			'paid',
			'unpaid',
			'pending',
			'paid',
			'rejected',
			'paid',
			'pending',
		];

		for ( const status of path ) {
			const res = await api.put( `/commissions/${ id }`, { status } );
			expect( res.status, `→ ${ status }` ).toBe( 200 );
			expect( res.body.status ).toBe( status );
		}
	} );
} );
