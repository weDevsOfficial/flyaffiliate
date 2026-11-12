/**
 * Affiliate signup and the one-time activation link.
 *
 * ADR-0009 §1 (the activation link is the only email), ADR-0013 (open
 * registration switch, honeypot).
 * Scenario ids: references/scenarios.md §7 (REG-*).
 */
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { BASE_URL } from '../utils/api';
import {
	createAffiliate,
	createUser,
	loginAs,
	pageUrls,
	restoreSettings,
	setAffiliateStatus,
	setSettings,
	withSettings,
	stamp,
} from '../utils/fixtures';
import { lit, php } from '../utils/wp';

/**
 * The affiliate row for an email, or null.
 * @param {string} email The address.
 */
function affiliateByEmail(
	email: string
): { id: number; status: string } | null {
	return php( `
		$user = get_user_by( 'email', ${ lit( email ) } );
		if ( ! $user ) { return null; }
		$a = flyaffiliate()->affiliate->get_by_user( $user->ID );
		return $a ? [ 'id' => $a->get_id(), 'status' => $a->get( 'status' ) ] : null;
	` );
}

/**
 * Register through the service and catch the activation link from the email.
 * @param {string} email The address.
 */
function registerWithLink( email: string ): { id: number; url: string } {
	return php( `
		$url = '';
		add_filter( 'flyaffiliate_activation_email', function ( $mail ) use ( &$url ) {
			preg_match( '#https?://\\S+#', $mail['message'], $m );
			$url = $m[0] ?? '';
			return $mail;
		} );
		$a = flyaffiliate()->registration->register( ${ lit( email ) }, '' );
		if ( is_wp_error( $a ) ) { throw new Exception( $a->get_error_message() ); }
		return [ 'id' => $a->get_id(), 'url' => $url ];
	` );
}

const newEmail = () => `pw-signup-${ stamp() }@example.test`;

test.describe( 'Affiliate registration', () => {
	let register: string;
	let guest: BrowserContext;
	let page: Page;

	withSettings( {
		registration_enabled: 'on',
		activation_email_enabled: 'on',
	} );

	test.beforeAll( () => {
		register = pageUrls().register;
	} );

	test.beforeEach( async ( { browser } ) => {
		guest = await browser.newContext( {
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		} );
		page = await guest.newPage();
	} );

	test.afterEach( async () => guest.close() );

	const submit = async ( email: string, promo = 'My blog' ) => {
		await page.goto( register );
		await page.locator( '#flyaffiliate-register-email' ).fill( email );
		await page.locator( '#flyaffiliate-register-promo' ).fill( promo );
		await page
			.getByRole( 'button', { name: 'Join the affiliate programme' } )
			.click();
		await page.waitForLoadState( 'domcontentloaded' );
	};

	test( '[REG-01] a guest signs up and waits, pending, for the activation link', async () => {
		const email = newEmail();
		await submit( email );

		await expect( page ).toHaveURL( /flyaffiliate_registered=pending/ );
		await expect( page.getByText( 'Check your email' ) ).toBeVisible();
		expect( affiliateByEmail( email ) ).toMatchObject( {
			status: 'pending',
		} );
	} );

	test( '[REG-02] the activation link opens the account once', async () => {
		const { id, url } = registerWithLink( newEmail() );
		expect( url ).toContain( 'flyaffiliate_activate=' );

		await page.goto( url );
		await expect( page ).toHaveURL( /flyaffiliate_activated=1/ );
		expect(
			php(
				`return flyaffiliate()->affiliate->get( ${ id } )->get( 'status' );`
			)
		).toBe( 'active' );

		await page.goto( url );
		await expect( page ).toHaveURL( /flyaffiliate_error=invalid_key/ );
	} );

	test( '[REG-03] a tampered key is refused and changes nothing', async () => {
		const { id, url } = registerWithLink( newEmail() );

		await page.goto(
			url.replace(
				/flyaffiliate_activate=([^&]+)/,
				'flyaffiliate_activate=$1x'
			)
		);
		await expect( page ).toHaveURL( /flyaffiliate_error=invalid_key/ );
		expect(
			php(
				`return flyaffiliate()->affiliate->get( ${ id } )->get( 'status' );`
			)
		).toBe( 'pending' );
	} );

	test( '[REG-03] a link cannot reopen an account the owner made inactive', async () => {
		const { id, url } = registerWithLink( newEmail() );
		setAffiliateStatus( id, 'inactive' );

		await page.goto( url );
		await expect( page ).toHaveURL(
			/flyaffiliate_error=account_not_active/
		);
		expect(
			php(
				`return flyaffiliate()->affiliate->get( ${ id } )->get( 'status' );`
			)
		).toBe( 'inactive' );
	} );

	test( '[REG-04] without the activation email, signups wait for the site owner', async () => {
		const before = setSettings( { activation_email_enabled: 'off' } );

		try {
			const email = newEmail();
			await submit( email );
			await expect( page ).toHaveURL( /flyaffiliate_registered=review/ );
			await expect(
				page.getByText( 'Thanks for signing up' )
			).toBeVisible();
			expect( affiliateByEmail( email ) ).toMatchObject( {
				status: 'pending',
			} );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[REG-05] a bot filling the hidden field creates nothing', async () => {
		const email = newEmail();
		await page.goto( register );
		await page.locator( '#flyaffiliate-register-email' ).fill( email );
		await page
			.locator( '#flyaffiliate-register-website' )
			.evaluate( ( el: HTMLInputElement ) => {
				el.value = 'http://spam.example';
			} );
		await page
			.getByRole( 'button', { name: 'Join the affiliate programme' } )
			.click();

		await expect( page ).toHaveURL( /flyaffiliate_error=spam/ );
		expect( affiliateByEmail( email ) ).toBeNull();
	} );

	test( '[REG-06] closed registration shows no form and refuses a post', async () => {
		const before = setSettings( { registration_enabled: 'off' } );

		try {
			await page.goto( register );
			await expect(
				page.getByText( 'Registration is closed' )
			).toBeVisible();
			await expect(
				page.locator( '#flyaffiliate-register-email' )
			).toHaveCount( 0 );

			const email = newEmail();
			expect(
				php(
					`$r = flyaffiliate()->registration->register( ${ lit(
						email
					) } ); return is_wp_error( $r ) ? $r->get_error_code() : 'created';`
				)
			).toBe( 'registration_closed' );
		} finally {
			restoreSettings( before );
		}
	} );

	test( '[REG-07] an address already registered is refused', async () => {
		const aff = createAffiliate();
		await submit( aff.email );
		await expect( page ).toHaveURL(
			/flyaffiliate_error=already_registered/
		);
	} );

	test( '[REG-10] an email that already has a WordPress account makes that user a pending affiliate, no second user (guide A3.4)', async () => {
		const user = createUser();
		const users = () =>
			php< number >(
				`return count( get_users( [ 'search' => ${ lit(
					user.email
				) }, 'search_columns' => [ 'user_email' ], 'fields' => 'ID' ] ) );`
			);
		await submit( user.email );
		await expect( page ).not.toHaveURL( /flyaffiliate_error=/ );
		expect( users() ).toBe( 1 );
		expect( affiliateByEmail( user.email ) ).toMatchObject( {
			status: 'pending',
		} );
		expect(
			php< number >(
				`return (int) flyaffiliate()->affiliate->get_by_user( ${ user.userId } )?->get_id();`
			)
		).toBeGreaterThan( 0 );
	} );

	test( '[REG-09] an invalid address is refused', async () => {
		await page.goto( register );
		const input = page.locator( '#flyaffiliate-register-email' );
		// Past the browser's own check, to reach the server's.
		await input.evaluate( ( el: HTMLInputElement ) =>
			el.setAttribute( 'type', 'text' )
		);
		await input.fill( 'not-an-email' );
		await page
			.getByRole( 'button', { name: 'Join the affiliate programme' } )
			.click();
		await expect( page ).toHaveURL( /flyaffiliate_error=invalid_email/ );
	} );

	test( '[REG-09] a post without the form nonce is refused', async () => {
		const email = newEmail();
		const res = await page.request.post(
			`${ BASE_URL }/wp-admin/admin-post.php`,
			{
				form: { action: 'flyaffiliate_register', email },
				maxRedirects: 0,
			}
		);
		expect( res.status() ).toBeGreaterThanOrEqual( 400 );
		expect( affiliateByEmail( email ) ).toBeNull();
	} );

	test( '[REG-08] a logged-in member joins with their own account', async ( {
		browser,
	} ) => {
		const user = createUser();
		const { context } = await loginAs( browser, user );

		try {
			const own = await context.newPage();
			await own.goto( register );
			const input = own.locator( '#flyaffiliate-register-email' );
			await expect( input ).toHaveValue( user.email );
			await expect( input ).toHaveAttribute( 'readonly', '' );

			// Even a changed address is ignored: the account's own is used.
			await input.evaluate( ( el: HTMLInputElement ) => {
				el.removeAttribute( 'readonly' );
				el.value = 'someone-else@example.test';
			} );
			await own
				.getByRole( 'button', { name: 'Join the affiliate programme' } )
				.click();
			await own.waitForURL( /flyaffiliate_registered=/ );

			expect( affiliateByEmail( user.email ) ).not.toBeNull();
			expect(
				affiliateByEmail( 'someone-else@example.test' )
			).toBeNull();
		} finally {
			await context.close();
		}
	} );
} );
