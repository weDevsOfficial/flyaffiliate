/**
 * The commissions ledger: tabs, the manual-commission dialog, status actions.
 */
import { expect, test } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import {
	openRowActions,
	rowActionItem,
	waitForListReady,
} from '../utils/dataViews';
import { urls } from '../utils/site';

test.describe( 'Commissions', () => {
	let list: ListPage;

	test.beforeEach( async ( { page } ) => {
		list = new ListPage( page );
		await list.open( urls.commissions, 'Commissions' );
	} );

	test( 'shows amounts, statuses and the affiliate for every row', async () => {
		const row = list.rows.first();
		await expect( row ).toBeVisible();
		await expect( row.locator( '[data-status]' ) ).toBeVisible();
		// The ID links to the commission's page; the affiliate to theirs.
		await expect(
			row.locator( 'a[href*="#/commissions/"]' ).first()
		).toHaveAttribute( 'href', /#\/commissions\/\d+\/edit/ );
		await expect(
			row.locator( 'a[href*="#/affiliates/"]' ).first()
		).toHaveAttribute( 'href', /#\/affiliates\/\d+/ );
	} );

	test( 'adds a commission on its own page and approves it', async ( {
		page,
	} ) => {
		const pendingBefore = await list.tabCount( 'Pending' );

		await page.getByTestId( 'flyaffiliate-add-commission' ).click();
		await expect( page ).toHaveURL( /#\/commissions\/new/ );

		const form = page.getByTestId( 'flyaffiliate-commission-form' );
		await expect( form ).toBeVisible();

		// The affiliate picker is a searchable select; take the first option.
		await form.getByRole( 'combobox' ).first().click();
		await page.getByRole( 'option' ).first().click();
		await form.locator( '#flyaffiliate-commission-amount' ).fill( '12.5' );
		await form.locator( '#flyaffiliate-commission-order' ).fill( '1400' );

		// The form starts on Unpaid; this one goes through the hold period.
		await form.locator( '#flyaffiliate-commission-status' ).click();
		await page.getByRole( 'option', { name: 'Pending' } ).click();
		await form.getByRole( 'button', { name: 'Add commission' } ).click();

		await list.expectToast( 'Commission added.' );
		await expect( page ).toHaveURL( /#\/commissions$/ );
		await waitForListReady( list.app );
		await expect
			.poll( () => list.tabCount( 'Pending' ) )
			.toBe( pendingBefore + 1 );

		await list.selectTab( 'Pending' );
		await openRowActions( list.rows.first() );
		await rowActionItem( page, 'Mark unpaid' ).click();
		await list.expectToast( /updated/ );
		await waitForListReady( list.app );
		await expect
			.poll( () => list.tabCount( 'Pending' ) )
			.toBe( pendingBefore );
	} );

	test( 'opens a commission from its ID and edits the amount', async ( {
		page,
	} ) => {
		await list.selectTab( 'Pending' );
		await waitForListReady( list.app );
		const row = list.rows.first();

		await row.locator( 'a[href*="#/commissions/"]' ).first().click();
		await expect( page ).toHaveURL( /#\/commissions\/\d+\/edit/ );

		const form = page.getByTestId( 'flyaffiliate-commission-form' );
		await expect( form ).toBeVisible();
		// SliceWP's locks: the ID, the affiliate, the origin and the date are fixed.
		await expect(
			form.locator( '#flyaffiliate-commission-affiliate' )
		).toBeDisabled();
		await expect(
			form.locator( '#flyaffiliate-commission-source' )
		).toBeDisabled();
		await expect(
			form.locator( '#flyaffiliate-commission-date' )
		).toBeDisabled();

		await form.locator( '#flyaffiliate-commission-amount' ).fill( '3.21' );
		await form.getByRole( 'button', { name: 'Save' } ).click();

		await list.expectToast( 'Commission updated.' );
		await expect( page ).toHaveURL( /#\/commissions$/ );
		await list.selectTab( 'Pending' );
		await waitForListReady( list.app );
		await expect( list.rows.first() ).toContainText( '3.21' );
	} );

	test( 'selecting rows shows the bulk toolbar', async () => {
		await list.selectTab( 'Pending' );
		await waitForListReady( list.app );

		const boxes = list.app.locator(
			'table tbody input[type=checkbox]:not([aria-disabled="true"])'
		);
		test.skip( ( await boxes.count() ) < 2, 'needs two selectable rows' );

		await boxes.nth( 0 ).click( { force: true } );
		await boxes.nth( 1 ).click( { force: true } );

		// plugin-ui only renders the toolbar for a controlled selection; a
		// list that forgets `selection` / `onChangeSelection` shows nothing.
		const toolbar = list.app.locator(
			'.dataviews-bulk-actions-footer__container'
		);
		await expect( toolbar ).toBeVisible();
		await expect( toolbar ).toContainText( /2 items selected/i );
		await expect(
			toolbar.getByRole( 'button', { name: 'Delete' } )
		).toBeVisible();

		await toolbar.getByRole( 'button', { name: /cancel|clear/i } ).click();
		await expect( toolbar ).toHaveCount( 0 );
	} );

	test( 'never offers to delete a paid commission', async ( { page } ) => {
		const paid = await list.tabCount( 'Paid' );
		test.skip( paid === 0, 'needs a paid commission' );

		await list.selectTab( 'Paid' );

		// A paid commission is terminal: with no eligible action left,
		// DataViews renders no actions button at all. If one is there, it must
		// not offer Delete.
		const actions = list.rows
			.first()
			.getByRole( 'button', { name: /actions/i } );

		if ( ( await actions.count() ) === 0 ) {
			await expect( actions ).toHaveCount( 0 );
			return;
		}

		await actions.click();
		await expect( rowActionItem( page, 'Delete' ) ).toHaveCount( 0 );
		await page.keyboard.press( 'Escape' );
	} );
} );
