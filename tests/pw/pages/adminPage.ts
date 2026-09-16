/**
 * The admin app shell: navigation and the pieces every page shares.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { urls } from '../utils/site';

export class AdminPage {
	protected readonly page: Page;

	constructor( page: Page ) {
		this.page = page;
	}

	/** The React mount point. */
	get app(): Locator {
		return this.page.locator( '#flyaffiliate-admin-app' );
	}

	/** The page heading. */
	get heading(): Locator {
		return this.app.getByRole( 'heading', { level: 1 } );
	}

	/**
	 * Load a hash route with a full document load.
	 *
	 * Two hash URLs on one document only trigger a hash change, and the app's
	 * URL sync would rewrite the new query before a reload could pick it up;
	 * leaving the document first guarantees a fresh load of exactly this URL.
	 *
	 * @param {string} url The route URL.
	 */
	async goto( url: string ): Promise< void > {
		await this.page.goto( 'about:blank' );
		await this.page.goto( url, { waitUntil: 'domcontentloaded' } );
		await expect( this.app ).toBeVisible();
	}

	/**
	 * Follow a submenu entry.
	 *
	 * @param {string} label The entry's label.
	 */
	async clickSubmenu( label: string ): Promise< void > {
		await this.page
			.locator( '#toplevel_page_flyaffiliate ul.wp-submenu' )
			.getByRole( 'link', { name: label, exact: true } )
			.click();
	}

	/**
	 * The most recent toast.
	 *
	 * @param {string|RegExp} text What it should say.
	 */
	async expectToast( text: string | RegExp ): Promise< void > {
		await expect(
			this.page
				.locator( '[data-sonner-toast]' )
				.filter( { hasText: text } )
				.first()
		).toBeVisible();
	}

	/**
	 * Confirm a plugin-ui confirmation dialog.
	 */
	async confirmDialog(): Promise< void > {
		const dialog = this.page.getByRole( 'alertdialog' );
		await expect( dialog ).toBeVisible();
		await dialog.getByRole( 'button' ).last().click();
	}

	static get urls() {
		return urls;
	}
}
