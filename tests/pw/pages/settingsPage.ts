/**
 * The settings screen rendered by plugin-ui's <Settings>.
 */
import { expect, type Locator } from '@playwright/test';
import { AdminPage } from './adminPage';
import { urls } from '../utils/site';

export class SettingsPage extends AdminPage {
	async open(): Promise< void > {
		await this.goto( urls.settings );
		await expect( this.heading ).toHaveText( 'Settings' );
		await expect(
			this.page
				.locator(
					'[data-testid="settings-menu-general"], [data-testid^="settings-menu-"]'
				)
				.first()
		).toBeVisible();
	}

	/**
	 * A sidebar entry (page or subpage).
	 * @param id
	 */
	menuItem( id: string ): Locator {
		return this.page.locator( `[data-testid="settings-menu-${ id }"]` );
	}

	/**
	 * A field's wrapper.
	 * @param id
	 */
	field( id: string ): Locator {
		return this.page.locator( `[data-testid="settings-field-${ id }"]` );
	}

	/** The save button of the active scope. */
	get saveButton(): Locator {
		return this.page.locator(
			'[data-testid="flyaffiliate-settings-save"]'
		);
	}

	/**
	 * Open a page and, optionally, one of its subpages.
	 *
	 * @param {string} pageId    The page id.
	 * @param {string} subpageId The subpage id.
	 */
	async navigate( pageId: string, subpageId?: string ): Promise< void > {
		await this.menuItem( pageId ).click();

		if ( subpageId ) {
			await this.menuItem( subpageId ).click();
		}
	}

	/**
	 * Type into a text or number field.
	 *
	 * @param {string} id    Field id.
	 * @param {string} value New value.
	 */
	async fill( id: string, value: string ): Promise< void > {
		const input = this.field( id ).locator( 'input' ).first();
		await input.fill( value );
	}

	/**
	 * Flip a switch to a state.
	 *
	 * @param {string}  id Field id.
	 * @param {boolean} on Desired state.
	 */
	async setSwitch( id: string, on: boolean ): Promise< void > {
		const control = this.field( id ).getByRole( 'switch' );
		const checked =
			( await control.getAttribute( 'aria-checked' ) ) === 'true';

		if ( checked !== on ) {
			await control.click();
		}
	}

	async save(): Promise< void > {
		await expect( this.saveButton ).toBeEnabled();
		await this.saveButton.click();
		await this.expectToast( 'Settings saved.' );
		await expect( this.saveButton ).toBeDisabled();
	}
}
