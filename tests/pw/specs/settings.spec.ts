/**
 * The settings screen: navigation, saving, validation and URL sync.
 */
import { expect, test } from '@playwright/test';
import { SettingsPage } from '../pages/settingsPage';
import { urls } from '../utils/site';

test.describe( 'Settings', () => {
	let settings: SettingsPage;

	test.beforeEach( async ( { page } ) => {
		settings = new SettingsPage( page );
		await settings.open();
	} );

	test( 'lists every page in the sidebar', async () => {
		for ( const id of [
			'general',
			'commission',
			'payout',
			'integrations',
			'email',
		] ) {
			await expect( settings.menuItem( id ) ).toBeVisible();
		}
	} );

	test( 'saves a subpage and keeps the value after a reload', async ( {
		page,
	} ) => {
		await settings.navigate( 'commission', 'rules' );
		await settings.fill( 'hold_days', '45' );
		await settings.save();

		await settings.open();
		await settings.navigate( 'commission', 'rules' );
		await expect(
			settings.field( 'hold_days' ).locator( 'input' ).first()
		).toHaveValue( '45' );

		// Put it back so other runs start from the default.
		await settings.fill( 'hold_days', '30' );
		await settings.save();
		await expect(
			page.locator( '[data-sonner-toast]' ).first()
		).toBeVisible();
	} );

	test( 'refuses a default rate above the maximum rate', async () => {
		await settings.navigate( 'commission', 'rates' );
		await settings.fill( 'max_rate', '20' );
		await settings.fill( 'default_rate', '80' );

		await expect( settings.saveButton ).toBeEnabled();
		await settings.saveButton.click();

		await settings.expectToast( /default rate cannot be higher/i );

		// Nothing was stored: reload and the old values are back.
		await settings.open();
		await settings.navigate( 'commission', 'rates' );
		await expect(
			settings.field( 'default_rate' ).locator( 'input' ).first()
		).not.toHaveValue( '80' );
	} );

	test( 'flags an out-of-range percentage before saving', async () => {
		await settings.navigate( 'commission', 'rates' );
		await settings.fill( 'max_rate', '150' );

		await expect( settings.field( 'max_rate' ) ).toContainText(
			/between 0 and 100/i
		);
		await expect( settings.saveButton ).toBeDisabled();
	} );

	test( 'mirrors the active subpage into the URL and restores it', async ( {
		page,
	} ) => {
		await settings.navigate( 'general', 'tracking' );
		await expect( page ).toHaveURL( /subpage_id=tracking/ );

		await settings.goto(
			`${ urls.settings }?page_id=general&subpage_id=data`
		);
		await expect(
			settings.field( 'data_clear_on_uninstall' )
		).toBeVisible();
	} );

	test( 'stays on the subpage picked after a read-only one', async ( {
		page,
	} ) => {
		// The affiliate area hides the save button, and with it the component
		// that mirrors the subpage into the URL. Leaving it used to restore the
		// subpage the URL still named, so the click landed one page behind.
		await settings.navigate( 'general', 'tracking' );
		await expect( page ).toHaveURL( /subpage_id=tracking/ );

		await settings.menuItem( 'affiliate_area' ).click();
		await expect( settings.field( 'dashboard_shortcode' ) ).toBeVisible();

		await settings.menuItem( 'data' ).click();
		await expect(
			settings.field( 'data_clear_on_uninstall' )
		).toBeVisible();
		await expect( page ).toHaveURL( /subpage_id=data/ );

		await settings.menuItem( 'currency_display' ).click();
		await expect( settings.field( 'currency' ) ).toBeVisible();
		await expect( page ).toHaveURL( /subpage_id=currency_display/ );
	} );

	test( 'switches store on and off', async () => {
		await settings.navigate( 'email', 'email_notifications' );
		await settings.setSwitch( 'activation_email_enabled', false );
		await settings.save();

		await settings.open();
		await settings.navigate( 'email', 'email_notifications' );
		await expect(
			settings.field( 'activation_email_enabled' ).getByRole( 'switch' )
		).toHaveAttribute( 'aria-checked', 'false' );

		await settings.setSwitch( 'activation_email_enabled', true );
		await settings.save();
	} );

	test( 'the Plugins screen links straight to the settings route', async ( {
		page,
	} ) => {
		await page.goto( urls.plugins );
		const link = page
			.locator( 'tr[data-slug="flyaffiliate"] .row-actions a' )
			.filter( { hasText: 'Settings' } );
		await expect( link ).toHaveAttribute(
			'href',
			/page=flyaffiliate#\/settings$/
		);
	} );
} );
