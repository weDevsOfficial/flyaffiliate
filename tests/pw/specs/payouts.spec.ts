/**
 * Payouts: preview, create, mark the batch paid — SliceWP's two steps.
 */
import { expect, test } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import { urls } from '../utils/site';

test.describe( 'Payouts', () => {
	test( 'previews who is owed what, creates the payout and marks it paid', async ( {
		page,
	} ) => {
		const list = new ListPage( page );
		await list.open( urls.commissions, 'Commissions' );
		const unpaid = await list.tabCount( 'Unpaid' );
		test.skip( unpaid === 0, 'needs unpaid commissions to pay out' );

		await list.goto( urls.newPayout );
		await expect( list.heading ).toHaveText( 'New payout' );

		await page.locator( '#flyaffiliate-payout-minimum' ).fill( '0' );
		await page
			.locator( '#flyaffiliate-payout-note' )
			.fill( 'Playwright payout' );
		await page.getByTestId( 'flyaffiliate-payout-preview' ).click();

		const table = page.getByTestId( 'flyaffiliate-payout-preview-table' );
		await expect( table ).toBeVisible();
		await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();

		await page.getByTestId( 'flyaffiliate-payout-confirm' ).click();
		await list.expectToast( /Payout created/ );
		await expect( page ).toHaveURL( /#\/payouts\/batch\// );
		const batchUrl = page.url();
		await expect( list.heading ).toHaveText( 'Playwright payout' );
		await expect(
			page.getByTestId( 'flyaffiliate-batch-csv' )
		).toHaveAttribute( 'href', /flyaffiliate_payout_csv/ );

		// Creating the payout pays nothing yet: the commissions stay unpaid.
		await list.open( urls.commissions, 'Commissions' );
		await expect.poll( () => list.tabCount( 'Unpaid' ) ).toBe( unpaid );

		// Marking the whole payout paid is what pays them. (`open()` passes
		// through about:blank, so going back would land there, not here.)
		await list.goto( batchUrl );
		await expect( list.heading ).toHaveText( 'Playwright payout' );
		await page.getByTestId( 'flyaffiliate-batch-pay' ).click();
		await page.getByTestId( 'flyaffiliate-confirm' ).click();
		await list.expectToast( /marked paid/ );
		await expect(
			page.getByTestId( 'flyaffiliate-batch-pay' )
		).toHaveCount( 0 );

		await list.open( urls.commissions, 'Commissions' );
		await expect.poll( () => list.tabCount( 'Unpaid' ) ).toBe( 0 );
	} );

	test( 'lists recorded payouts', async ( { page } ) => {
		const list = new ListPage( page );
		await list.open( urls.payouts, 'Payouts' );
		await expect(
			page.getByTestId( 'flyaffiliate-new-payout' )
		).toBeVisible();
	} );
} );
