/**
 * Helpers for @wedevs/plugin-ui DataViews lists, after Dokan's tests/pw/utils/dataViews.ts.
 */
import { expect, type Locator, type Page } from '@playwright/test';

/** A loading placeholder rendered while `isLoading` is true. */
export const SKELETON = '[data-slot="skeleton"]';

/** A settled body row: no skeleton inside it. */
export const DATA_ROW = 'table tbody tr:not(:has([data-slot="skeleton"]))';

/**
 * Wait until the list has finished loading: skeletons gone, and either rows
 * or the empty state visible.
 *
 * @param {Page|Locator} scope Where to look.
 */
export async function waitForListReady(
	scope: Page | Locator
): Promise< void > {
	await expect( scope.locator( SKELETON ).first() ).toBeHidden( {
		timeout: 20 * 1000,
	} );
	await expect
		.poll(
			async () =>
				( await scope.locator( DATA_ROW ).count() ) > 0 ||
				( await scope.locator( '.dataviews-no-results' ).count() ) > 0,
			{ timeout: 20 * 1000 }
		)
		.toBe( true );
}

/**
 * The status tab with a given label, e.g. "Pending".
 *
 * @param {Page|Locator} scope Where to look.
 * @param {string}       label The tab label, without its count.
 * @return {Locator} The tab.
 */
export function statusTab( scope: Page | Locator, label: string ): Locator {
	return scope.getByRole( 'tab', {
		name: new RegExp( `^${ label }\\s*\\(\\d+\\)$` ),
	} );
}

/**
 * Open a row's actions menu.
 *
 * @param {Locator} row The row.
 */
export async function openRowActions( row: Locator ): Promise< void > {
	await row.getByRole( 'button', { name: /actions/i } ).click();
}

/**
 * A menu item in the actions menu that is currently open.
 *
 * The admin bar is also a menu; scoping to the last open menu keeps the
 * DataViews portal's items apart from it.
 *
 * @param {Page}   page The page.
 * @param {string} name The item's label.
 * @return {Locator} The item.
 */
export function rowActionItem( page: Page, name: string ): Locator {
	return page
		.getByRole( 'menu' )
		.last()
		.getByRole( 'menuitem', { name, exact: true } );
}
