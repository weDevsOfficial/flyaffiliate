/**
 * Visits: the list and its result tabs.
 */
import { expect, test } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import { urls } from '../utils/site';

test.describe( 'Visits', () => {
	test( 'lists visits and filters converted ones', async ( { page } ) => {
		const list = new ListPage( page );
		await list.open( urls.visits, 'Visits' );

		await expect( list.rows.first() ).toBeVisible();

		const converted = await list.tabCount( 'Converted' );
		test.skip( converted === 0, 'needs a converted visit' );

		await list.selectTab( 'Converted' );

		for ( const badge of await list.rows
			.locator( '[data-status]' )
			.all() ) {
			await expect( badge ).toHaveAttribute( 'data-status', 'converted' );
		}
	} );
} );
