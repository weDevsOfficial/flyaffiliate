/**
 * Dashboard: the tiles, the chart card, the two DataViews lists and the
 * sidebar, over a date range picked from presets or the calendar.
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { urls } from '../utils/site';
import { SKELETON } from '../utils/dataViews';

test.describe( 'Dashboard', () => {
	test( 'shows every section with its figures and lists', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.dashboard );
		await expect( admin.heading ).toHaveText( 'Dashboard' );

		await expect( admin.app.locator( SKELETON ).first() ).toBeHidden( {
			timeout: 20 * 1000,
		} );

		for ( const title of [
			'Earnings & payouts',
			'Performance & conversions',
			'Visits per day',
			'Highest converting products',
			'Recent visits',
			'Most valuable affiliates',
			'Recent commissions',
		] ) {
			await expect(
				admin.app.getByRole( 'heading', { level: 3, name: title } )
			).toBeVisible();
		}

		// Eight tiles, each with its info icon beside the label.
		for ( const label of [
			'Referral revenue',
			'Net revenue',
			'Total paid',
			'Total unpaid',
			'Commissions',
			'Visits',
			'Conversion rate',
			'Affiliates',
		] ) {
			const tile = admin.app
				.locator( '[data-slot="card"]' )
				.filter( { hasText: new RegExp( `^${ label }` ) } )
				.first();
			await expect( tile ).toBeVisible();
			await expect( tile.getByRole( 'button' ) ).toBeVisible();
		}

		// The two lists are DataViews: a table, or the list's own empty state.
		for ( const id of [
			'flyaffiliate-dashboard-products',
			'flyaffiliate-dashboard-visits',
		] ) {
			await expect(
				page
					.getByTestId( id )
					.locator( 'table, .dataviews-no-results' )
					.first()
			).toBeVisible();
		}
		await expect(
			page.getByTestId( 'flyaffiliate-dashboard-chart' )
		).toBeVisible();
	} );

	test( 'the range comes from a preset or the calendar', async ( {
		page,
	} ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.dashboard );
		await expect( admin.heading ).toHaveText( 'Dashboard' );

		const select = page.getByTestId( 'flyaffiliate-dashboard-range' );
		await expect( select ).toContainText( 'Last 30 days' );

		// Plain permalinks encode the route, so match on the decoded URL.
		const requests: string[] = [];
		page.on( 'request', ( request ) => {
			const url = decodeURIComponent( request.url() );
			if ( url.includes( '/flyaffiliate/v1/dashboard' ) ) {
				requests.push( url );
			}
		} );

		await select.click();
		await page.getByRole( 'option', { name: 'All time' } ).click();
		await expect( select ).toContainText( 'All time' );
		await expect
			.poll( () => requests.some( ( url ) => ! /after=/.test( url ) ) )
			.toBe( true );

		// The calendar picks a custom range, and the select shows it.
		await page.getByTestId( 'flyaffiliate-dashboard-calendar' ).click();
		const calendar = page.getByRole( 'dialog' );
		// The day buttons are named "Tuesday, September 1, 2026"; the first
		// match is the 1st of the first month shown.
		await calendar
			.getByRole( 'button', { name: /^\w+, \w+ 1, \d{4}$/ } )
			.first()
			.click();
		await calendar
			.getByRole( 'button', { name: /^\w+, \w+ 2, \d{4}$/ } )
			.first()
			.click();
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( select ).not.toContainText( 'All time' );
		await expect
			.poll( () =>
				requests.some( ( url ) => /after=\d{4}-\d{2}-01/.test( url ) )
			)
			.toBe( true );
	} );
} );
