/**
 * Payouts: preview, create, mark the batch paid — SliceWP's two steps.
 */
import { expect, test } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import { urls } from '../utils/site';
import { lit, php } from '../utils/wp';

/**
 * Commissions locked to the payouts of one batch.
 *
 * @param {string} batchKey The batch key from the batch URL.
 * @return {number} The count.
 */
const inBatch = ( batchKey: string ): number =>
	php< number >(
		`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_commissions c JOIN {$wpdb->prefix}flyaffiliate_payouts p ON p.id = c.payout_id WHERE p.batch_key = %s", ${ lit(
			batchKey
		) } ) );`
	);

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

		// An unpaid commission still inside its hold period is left out, and
		// the preview says how many.
		const heldNote = page.getByTestId( 'flyaffiliate-payout-held' );
		const held =
			( await heldNote.count() ) > 0
				? Number( await heldNote.getAttribute( 'data-count' ) )
				: 0;

		await page.getByTestId( 'flyaffiliate-payout-confirm' ).click();
		await list.expectToast( /Payout created/ );
		await expect( page ).toHaveURL( /#\/payouts\/batch\// );
		const batchUrl = page.url();
		const batchKey = decodeURIComponent(
			batchUrl.split( '#/payouts/batch/' )[ 1 ]
		);
		const locked = inBatch( batchKey );
		expect( locked ).toBeGreaterThan( 0 );
		// Held commissions never join the batch (ADR-0014).
		expect( locked ).toBeLessThanOrEqual( unpaid - held );
		// The heading carries the batch's status badge beside the title.
		await expect( list.heading ).toContainText( 'Playwright payout' );
		await expect(
			page.getByTestId( 'flyaffiliate-batch-csv' )
		).toHaveAttribute( 'href', /flyaffiliate_payout_csv/ );

		// Creating the payout pays nothing yet: the commissions stay unpaid.
		await list.open( urls.commissions, 'Commissions' );
		await expect.poll( () => list.tabCount( 'Unpaid' ) ).toBe( unpaid );

		// Marking the whole payout paid is what pays them. (`open()` passes
		// through about:blank, so going back would land there, not here.)
		await list.goto( batchUrl );
		await expect( list.heading ).toContainText( 'Playwright payout' );
		await page.getByTestId( 'flyaffiliate-batch-pay' ).click();
		await page.getByTestId( 'flyaffiliate-confirm' ).click();
		await list.expectToast( /marked paid/ );
		await expect(
			page.getByTestId( 'flyaffiliate-batch-pay' )
		).toHaveCount( 0 );

		// Exactly the batch's commissions are paid. Held ones, and ones locked
		// to other open batches, stay unpaid.
		await list.open( urls.commissions, 'Commissions' );
		await expect
			.poll( () => list.tabCount( 'Unpaid' ) )
			.toBe( unpaid - locked );
	} );

	test( 'lists recorded payouts', async ( { page } ) => {
		const list = new ListPage( page );
		await list.open( urls.payouts, 'Payouts' );
		await expect(
			page.getByTestId( 'flyaffiliate-new-payout' )
		).toBeVisible();
	} );
} );
