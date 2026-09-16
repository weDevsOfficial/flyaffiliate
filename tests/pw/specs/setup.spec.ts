/**
 * The setup wizard: every step saves, the last step links the pages, and
 * skipping lands on the Dashboard without finishing the wizard.
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { SettingsPage } from '../pages/settingsPage';
import { urls } from '../utils/site';

test.describe( 'Setup wizard', () => {
	test( 'walks every step and saves what was entered', async ( { page } ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.setup );

		const wizard = page.getByTestId( 'flyaffiliate-setup' );
		await expect( wizard ).toBeVisible();
		await expect(
			page.getByTestId( 'flyaffiliate-setup-step-commissions' )
		).toHaveAttribute( 'data-state', 'active' );

		// max_rate is not asserted by any other spec, so it is safe to change.
		const maxRate = page.locator( '#flyaffiliate-setup-max_rate' );
		await expect( maxRate ).toBeEnabled();
		await maxRate.fill( '60' );
		await page.getByTestId( 'flyaffiliate-setup-continue' ).click();

		await expect(
			page.getByTestId( 'flyaffiliate-setup-step-tracking' )
		).toHaveAttribute( 'data-state', 'active' );
		await expect(
			page.locator( '#flyaffiliate-setup-cookie_duration' )
		).toBeVisible();
		await page.getByTestId( 'flyaffiliate-setup-continue' ).click();

		await expect(
			page.getByTestId( 'flyaffiliate-setup-step-email' )
		).toHaveAttribute( 'data-state', 'active' );
		await page.getByTestId( 'flyaffiliate-setup-continue' ).click();

		await expect(
			page.getByTestId( 'flyaffiliate-setup-step-done' )
		).toHaveAttribute( 'data-state', 'active' );
		await expect(
			wizard.getByText( '[flyaffiliate_register]' )
		).toBeVisible();
		await expect(
			wizard.getByText( '[flyaffiliate_dashboard]' )
		).toBeVisible();

		const settings = new SettingsPage( page );
		await settings.open();
		await settings.navigate( 'commission', 'rates' );
		await expect(
			settings.field( 'max_rate' ).locator( 'input' ).first()
		).toHaveValue( '60' );
	} );

	test( 'rejects a value the schema refuses', async ( { page } ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.setup );

		const defaultRate = page.locator( '#flyaffiliate-setup-default_rate' );
		await expect( defaultRate ).toBeEnabled();
		await defaultRate.fill( '250' );
		await page.getByTestId( 'flyaffiliate-setup-continue' ).click();

		await expect( defaultRate ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect(
			page.getByTestId( 'flyaffiliate-setup-step-commissions' )
		).toHaveAttribute( 'data-state', 'active' );
	} );

	test( 'skipping lands on the Dashboard', async ( { page } ) => {
		const admin = new AdminPage( page );
		await admin.goto( urls.setup );

		await page.getByTestId( 'flyaffiliate-setup-skip' ).click();
		await expect( page ).toHaveURL( /#\/$/ );
	} );

	test( 'the old page slug redirects into the app', async ( { page } ) => {
		await page.goto( urls.legacySetup );
		await expect( page ).toHaveURL( /page=flyaffiliate#\/setup/ );
		await expect( page.getByTestId( 'flyaffiliate-setup' ) ).toBeVisible();
	} );
} );
