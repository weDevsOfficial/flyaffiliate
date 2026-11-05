/**
 * The site under test.
 */
export const ADMIN = {
	username: process.env.FLYAFFILIATE_ADMIN_USER ?? 'admin',
	password: process.env.FLYAFFILIATE_ADMIN_PASS ?? 'password',
};

const app = '/wp-admin/admin.php?page=flyaffiliate';

export const urls = {
	login: '/wp-login.php',
	app,
	dashboard: `${ app }#/`,
	affiliates: `${ app }#/affiliates`,
	commissions: `${ app }#/commissions`,
	visits: `${ app }#/visits`,
	payouts: `${ app }#/payouts`,
	newPayout: `${ app }#/payouts/new`,
	settings: `${ app }#/settings`,
	setup: `${ app }#/setup`,
	plugins: '/wp-admin/plugins.php',
};

/**
 * Log in through wp-login.php and wait for the auth cookie.
 *
 * The cookies come with the login POST's redirect, so where it redirects to
 * (wp-admin, WooCommerce's My account) and how slowly that page loads is
 * beside the point. A submit that never leaves the page — the form clicked
 * before its scripts settled — is retried.
 *
 * @param {import('@playwright/test').Page} page     The page to log in with.
 * @param {string}                          login    User login.
 * @param {string}                          password Password.
 */
export async function logIn(
	page: import('@playwright/test').Page,
	login: string,
	password: string
): Promise< void > {
	for ( let attempt = 1; attempt <= 3; attempt++ ) {
		await page.goto( urls.login, { waitUntil: 'networkidle' } );
		await page.fill( '#user_login', login );
		await page.fill( '#user_pass', password );

		const posted = page
			.waitForResponse(
				( r ) =>
					r.url().includes( 'wp-login.php' ) &&
					r.request().method() === 'POST',
				{ timeout: 15 * 1000 }
			)
			.catch( () => null );
		await page.click( '#wp-submit' );
		await posted;

		const cookies = await page.context().cookies();
		if (
			cookies.some( ( c ) => c.name.startsWith( 'wordpress_logged_in_' ) )
		) {
			return;
		}
	}

	throw new Error( `Could not log in as ${ login }.` );
}
