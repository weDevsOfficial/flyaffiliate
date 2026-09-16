/**
 * A DataViews list screen: affiliates, commissions, visits or payouts.
 */
import { expect, type Locator } from '@playwright/test';
import { AdminPage } from './adminPage';
import { DATA_ROW, statusTab, waitForListReady } from '../utils/dataViews';

export class ListPage extends AdminPage {
	async open( url: string, heading: string ): Promise< void > {
		await this.goto( url );
		await expect( this.heading ).toHaveText( heading );
		await waitForListReady( this.app );
	}

	get rows(): Locator {
		return this.app.locator( DATA_ROW );
	}

	tab( label: string ): Locator {
		return statusTab( this.app, label );
	}

	async selectTab( label: string ): Promise< void > {
		await this.tab( label ).click();
		await waitForListReady( this.app );
	}

	async search( text: string ): Promise< void > {
		const box = this.app.getByPlaceholder( /search/i ).first();
		await box.fill( text );
		await box.press( 'Enter' );
		await waitForListReady( this.app );
	}

	/**
	 * The count shown on a tab, e.g. 17 from "All (17)".
	 *
	 * @param {string} label Tab label.
	 * @return {Promise<number>} The count.
	 */
	async tabCount( label: string ): Promise< number > {
		const text = await this.tab( label ).innerText();
		return Number( ( text.match( /\((\d+)\)/ ) ?? [ , '0' ] )[ 1 ] );
	}
}
