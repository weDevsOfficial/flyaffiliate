/**
 * The admin shell: submenu entries, hash routes and the menu highlight.
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { urls } from '../utils/site';

test.describe( 'Navigation', () => {
	test( 'every submenu entry opens its route in the same document', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.affiliates );

		for ( const [ label, route ] of [
			[ 'Commissions', 'commissions' ],
			[ 'Visits', 'visits' ],
			[ 'Payouts', 'payouts' ],
			[ 'Settings', 'settings' ],
			[ 'Affiliates', 'affiliates' ],
		] ) {
			await admin.clickSubmenu( label );
			await expect( page ).toHaveURL( new RegExp( `#/${ route }` ) );
			await expect( admin.heading ).toHaveText( label );
			await expect(
				page.locator( '#toplevel_page_flyaffiliate li.current' )
			).toContainText( label );
		}
	} );

	test( 'an unknown route shows the not-found page', async ( { page } ) => {
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/nowhere` );
		await expect( admin.heading ).toHaveText(
			'Sorry, that page cannot be found'
		);
	} );
} );
