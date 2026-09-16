/**
 * The affiliates list: tabs, search, the add dialog and row actions.
 */
import { expect, test } from '@playwright/test';
import { ListPage } from '../pages/listPage';
import {
	openRowActions,
	rowActionItem,
	waitForListReady,
} from '../utils/dataViews';
import { urls } from '../utils/site';

test.describe( 'Affiliates', () => {
	let list: ListPage;

	test.beforeEach( async ( { page } ) => {
		list = new ListPage( page );
		await list.open( urls.affiliates, 'Affiliates' );
	} );

	test( 'shows rows with a status badge and the affiliate id', async () => {
		await expect( list.rows.first() ).toBeVisible();
		await expect(
			list.rows.first().locator( '[data-status]' )
		).toBeVisible();
		// The id column the prototype has; the referral link moved to the
		// affiliate's own page and the row menu.
		await expect( list.rows.first() ).toContainText( /#\d+/ );
	} );

	test( 'filters by status tab', async () => {
		const active = await list.tabCount( 'Active' );
		test.skip( active === 0, 'needs at least one active affiliate' );

		await list.selectTab( 'Active' );

		for ( const badge of await list.rows
			.locator( '[data-status]' )
			.all() ) {
			await expect( badge ).toHaveAttribute( 'data-status', 'active' );
		}
	} );

	test( 'searches by name', async () => {
		const name = (
			await list.rows.first().getByRole( 'link' ).first().innerText()
		).trim();

		await list.search( name );

		await expect( list.rows.first() ).toContainText( name );

		// A term nobody matches empties the list rather than ignoring the box.
		await list.search( 'zz-nobody-has-this-name-zz' );

		await expect( list.rows ).toHaveCount( 0 );
		await expect(
			list.app.getByText( 'No affiliates found' )
		).toBeVisible();
	} );

	test( 'opens the add dialog and requires a user', async ( { page } ) => {
		await page.getByTestId( 'flyaffiliate-add-affiliate' ).click();

		const form = page.getByTestId( 'flyaffiliate-affiliate-form' );
		await expect( form ).toBeVisible();
		await form.getByRole( 'button', { name: 'Add affiliate' } ).click();

		await list.expectToast( /choose the user/i );
		await page.keyboard.press( 'Escape' );
		await expect( form ).toBeHidden();
	} );

	test( 'changes a status from the row actions and updates the tab counts', async ( {
		page,
	} ) => {
		const pendingBefore = await list.tabCount( 'Pending' );
		const activeBefore = await list.tabCount( 'Active' );

		await list.selectTab( 'Active' );
		test.skip( activeBefore === 0, 'needs at least one active affiliate' );

		await openRowActions( list.rows.first() );
		await rowActionItem( page, 'Set pending' ).click();
		await list.expectToast( /updated/ );
		await waitForListReady( list.app );

		await expect
			.poll( () => list.tabCount( 'Pending' ) )
			.toBe( pendingBefore + 1 );

		// Undo: the affiliate is now first on the Pending tab.
		await list.selectTab( 'Pending' );
		await openRowActions( list.rows.first() );
		await rowActionItem( page, 'Activate' ).click();
		await list.confirmDialog();
		await list.expectToast( /updated/ );
		await expect
			.poll( () => list.tabCount( 'Active' ) )
			.toBe( activeBefore );
	} );

	test( "lists the prototype's columns", async () => {
		for ( const header of [
			'Name',
			'Affiliate ID',
			'Paid Earnings',
			'Unpaid Earnings',
			'Paid Commissions',
			'Unpaid Commissions',
			'Status',
		] ) {
			await expect(
				list.app.getByRole( 'columnheader', {
					name: header,
					exact: true,
				} )
			).toBeVisible();
		}
	} );

	test( 'opens an affiliate and shows totals and their ledger', async ( {
		page,
	} ) => {
		await list.rows.first().getByRole( 'link' ).first().click();

		await expect( page ).toHaveURL( /#\/affiliates\/\d+/ );
		await expect( list.app.getByText( 'Referral link' ) ).toBeVisible();
		// The unpaid tile carries its explanation behind the info icon.
		await expect(
			list.app.getByRole( 'button', {
				name: 'Approved commissions, ready for the next payout.',
			} )
		).toBeVisible();
		await expect(
			list.app.getByRole( 'tab', { name: 'Commissions' } )
		).toBeVisible();
	} );
} );
