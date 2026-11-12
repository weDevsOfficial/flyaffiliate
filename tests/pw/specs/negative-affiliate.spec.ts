/**
 * Negative paths an affiliate or a visitor can reach: the self-scoped `me`
 * routes, abusive referral links, and the dashboard's link generator.
 *
 * ADR-0005 (tracking), ADR-0008 (self-scoped routes), ADR-0015 (saved links).
 * Known product bugs are pinned with `test.fail()` (tests/pw/PRODUCT_BUGS.md).
 */
import { expect, test, type Browser } from '@playwright/test';
import { Api, BASE_URL } from '../utils/api';
import {
	affiliateVisits,
	countRows,
	createAffiliate,
	loginAs,
	pageUrls,
	withSettings,
	type TestAffiliate,
} from '../utils/fixtures';
import { php } from '../utils/wp';

/**
 * A logged-in affiliate's REST client.
 * @param browser
 * @param aff
 */
async function meFor( browser: Browser, aff: TestAffiliate ): Promise< Api > {
	const { context, state } = await loginAs( browser, aff );
	await context.close();
	return Api.as( state );
}

test.describe( 'The me routes refuse bad input', () => {
	let aff: TestAffiliate;
	let me: Api;

	test.beforeAll( async ( { browser } ) => {
		aff = createAffiliate();
		me = await meFor( browser, aff );
	} );

	test( 'an unknown payout method is refused and nothing changes', async () => {
		const before = ( await me.get( '/me' ) ).body;
		const res = await me.put( '/me', { payout_method: 'bogus' } );
		expect( res.status ).toBe( 400 );
		expect( res.body.code ).toBe( 'flyaffiliate_invalid_payout_method' );
		expect( ( await me.get( '/me' ) ).body.payout_method ).toBe(
			before.payout_method
		);
	} );

	test( 'an invalid payment email is refused, not silently blanked', async () => {
		// BUG-009: /me answers 200 and stores '' (the admin route refuses with 400).
		// Fixed by PR #22 (was pinned with test.fail()).
		const res = await me.put( '/me', { payment_email: 'not-an-email' } );
		expect( res.status ).toBe( 400 );
		expect( ( await me.get( '/me' ) ).body.payment_email ).toBe(
			aff.email
		);
	} );

	test( 'fields an affiliate may not change are ignored: status, user, id', async () => {
		await me.put( '/me', { status: 'suspended', user_id: 1, id: 1 } );
		const row = php< { status: string; user_id: string } >(
			`$a = flyaffiliate()->affiliate->get( ${ aff.id } ); return [ 'status' => $a->get( 'status' ), 'user_id' => (string) $a->get( 'user_id' ) ];`
		);
		expect( row ).toEqual( {
			status: 'active',
			user_id: String( aff.userId ),
		} );
	} );

	test( 'a referral link must be a page of this site', async () => {
		for ( const url of [
			undefined,
			'',
			'javascript:alert(1)',
			'//evil.example/x',
			'https://evil.example/',
			`${ BASE_URL.replace( 'localhost', 'localhost.evil.com' ) }/`,
			'data:text/html,hi',
		] ) {
			const res = await me.post(
				'/me/referral-links',
				url === undefined ? {} : { url }
			);
			expect( res.status, String( url ) ).toBe( 400 );
		}
		expect( countRows( 'referral_links', 'affiliate_id', aff.id ) ).toBe(
			0
		);
	} );

	test( 'unknown ids answer 404; bad paging and dates 400', async () => {
		expect( ( await me.del( '/me/referral-links/99999999' ) ).status ).toBe(
			404
		);
		expect( ( await me.get( '/me/commissions/99999999' ) ).status ).toBe(
			404
		);
		expect(
			( await me.get( '/me/commissions', { per_page: 1000 } ) ).status
		).toBe( 400 );
		expect( ( await me.get( '/me', { after: 'garbage' } ) ).status ).toBe(
			400
		);
	} );

	test( 'without the REST nonce the session counts as logged out', async () => {
		expect( ( await me.withoutNonce().get( '/me' ) ).status ).toBe( 401 );
		expect(
			( await me.withoutNonce().put( '/me', { promo_method: 'x' } ) )
				.status
		).toBe( 401 );
	} );
} );

test.describe( 'Who may use the me routes', () => {
	const ROUTES: [ 'GET' | 'POST' | 'PUT', string, unknown? ][] = [
		[ 'GET', '/me' ],
		[ 'PUT', '/me', { promo_method: 'x' } ],
		[ 'GET', '/me/commissions' ],
		[ 'GET', '/me/visits' ],
		[ 'GET', '/me/payouts' ],
		[ 'GET', '/me/referral-links' ],
		[ 'POST', '/me/referral-links', { url: `${ BASE_URL }/` } ],
	];

	async function expectAll( api: Api, status: number, who: string ) {
		for ( const [ method, path, body ] of ROUTES ) {
			const res = await api.call( method, path, body );
			expect( res.status, `${ who } ${ method } ${ path }` ).toBe(
				status
			);
		}
	}

	test( 'a guest gets 401 everywhere', async () => {
		await expectAll( await Api.as( null ), 401, 'guest' );
	} );

	test( 'pending, inactive and suspended affiliates get 403 everywhere', async ( {
		browser,
	} ) => {
		for ( const status of [ 'pending', 'inactive', 'suspended' ] ) {
			const aff = createAffiliate( status );
			await expectAll( await meFor( browser, aff ), 403, status );
			expect(
				countRows( 'referral_links', 'affiliate_id', aff.id )
			).toBe( 0 );
		}
	} );

	test( 'an administrator who is not an affiliate gets 403', async () => {
		await expectAll( await Api.as(), 403, 'admin' );
	} );
} );

test.describe( 'Abusive referral links', () => {
	withSettings( {
		referral_variable: 'affiliate',
		block_self_referral: 'on',
	} );

	async function guestVisit(
		browser: Browser,
		path: string,
		cookies: { name: string; value: string }[] = []
	) {
		const context = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		if ( cookies.length ) {
			await context.addCookies(
				cookies.map( ( c ) => ( { ...c, url: BASE_URL } ) )
			);
		}
		const response = await ( await context.newPage() ).goto( path );
		const cookie = ( await context.cookies() ).find(
			( c ) => c.name === 'flyaffiliate_ref'
		);
		await context.close();
		return { status: response?.status(), cookie };
	}

	test( 'an array in the referral variable credits nobody', async ( {
		browser,
	} ) => {
		// BUG-002: absint() turns ?affiliate[]=… into 1, so affiliate #1 gets the
		// visit and the cookie.
		// Fixed by PR #22 (was pinned with test.fail()).
		const aff = createAffiliate();
		const { status, cookie } = await guestVisit(
			browser,
			`/?affiliate[]=${ aff.id }`
		);
		expect( status ).toBe( 200 );
		expect( cookie ).toBeUndefined();
	} );

	test( 'a link to a deleted affiliate sets nothing', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		php( `flyaffiliate()->affiliate->delete( ${ aff.id } ); return true;` );
		const { status, cookie } = await guestVisit(
			browser,
			`/?affiliate=${ aff.id }`
		);
		expect( status ).toBe( 200 );
		expect( cookie ).toBeUndefined();
		expect( affiliateVisits( aff.id ) ).toHaveLength( 0 );
	} );

	test( 'a forged cookie is replaced by a real visit on the next click', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const forged = `${ aff.id }|1|${ 'd'.repeat( 32 ) }`;
		const { cookie } = await guestVisit(
			browser,
			`/?affiliate=${ aff.id }`,
			[ { name: 'flyaffiliate_ref', value: forged } ]
		);

		expect( affiliateVisits( aff.id ) ).toHaveLength( 1 );
		expect( decodeURIComponent( cookie!.value ) ).not.toBe( forged );
	} );

	test( 'a forged cookie alone, with no click, records nothing', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		await guestVisit( browser, '/', [
			{
				name: 'flyaffiliate_ref',
				value: `${ aff.id }|1|${ 'e'.repeat( 32 ) }`,
			},
		] );
		expect( affiliateVisits( aff.id ) ).toHaveLength( 0 );
	} );

	test( 'a huge or repeated referral value is ignored without errors', async ( {
		browser,
	} ) => {
		for ( const path of [
			'/?affiliate=99999999999999999999999',
			'/?affiliate=' + '9'.repeat( 2000 ),
			'/?affiliate=&affiliate=',
		] ) {
			const { status, cookie } = await guestVisit( browser, path );
			expect( status, path ).toBe( 200 );
			expect( cookie, path ).toBeUndefined();
		}
	} );
} );

test.describe( 'Reserved WordPress query variables', () => {
	withSettings( { referral_variable: 'p' } );

	test( 'with the referral variable set to "p", an ordinary post link credits no one', async ( {
		browser,
	} ) => {
		// BUG-004 residual: a site that stored `p` before saves refused it keeps
		// it after updating (written here straight into the option). The
		// tracker ignores a stored reserved name and falls back to `affiliate`
		// (PR #22, 2ff7a8f), so /?p=<post> credits no one. Was test.fail().
		const postId = php< number >(
			`return (int) get_posts( [ 'post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids' ] )[0];`
		);
		const context = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		await ( await context.newPage() ).goto( `/?p=${ postId }` );
		const cookie = ( await context.cookies() ).find(
			( c ) => c.name === 'flyaffiliate_ref'
		);
		await context.close();
		expect( cookie ).toBeUndefined();
	} );
} );

test.describe( 'The dashboard link generator refuses bad links', () => {
	test( 'empty, foreign and malformed input each get their message and save nothing', async ( {
		browser,
	} ) => {
		const aff = createAffiliate();
		const { context } = await loginAs( browser, aff );
		const page = await context.newPage();
		await page.goto( pageUrls().dashboard );
		const input = page.locator( '#flyaffiliate-generate-link-input' );
		const generate = page.getByRole( 'button', {
			name: 'Generate',
			exact: true,
		} );

		for ( const [ value, message ] of [
			[ '', 'Paste a link from this site first.' ],
			[
				'https://evil.example/page',
				'Only links to pages of this website can earn a commission.',
			],
			[
				'javascript:alert(1)',
				/not a valid link|Only links to pages of this website/,
			],
			[
				'http://exa mple',
				/not a valid link|Only links to pages of this website/,
			],
		] as const ) {
			await input.fill( value );
			await generate.click();
			await expect(
				page.getByText( message ).first(),
				String( value )
			).toBeVisible();
		}

		expect( countRows( 'referral_links', 'affiliate_id', aff.id ) ).toBe(
			0
		);
		await context.close();
	} );
} );
