/**
 * Negative paths through the admin screens: forms that must refuse, links to
 * records that do not exist, confirmations that are cancelled, and a server
 * that fails mid-save. Each refusal must leave the data as it was.
 *
 * ADR-0010 (the admin app), ADR-0012 (payout screens).
 * Known product bugs are pinned with `test.fail()` (tests/pw/PRODUCT_BUGS.md).
 */
import { expect, test } from '@playwright/test';
import { AdminPage } from '../pages/adminPage';
import { SettingsPage } from '../pages/settingsPage';
import { Api } from '../utils/api';
import {
	createAffiliate,
	storedSettings,
	withSettings,
} from '../utils/fixtures';
import { urls } from '../utils/site';

let api: Api;

test.beforeAll( async () => {
	api = await Api.as();
} );

/** How many commissions exist, from the REST total. */
async function commissionTotal(): Promise< number > {
	return Number(
		( await api.get( '/commissions', { per_page: 1 } ) ).headers[
			'x-wp-total'
		]
	);
}

test.describe( 'The commission form refuses', () => {
	test( 'no affiliate, then no amount, then a zero or negative amount — nothing is saved', async ( {
		page,
	} ) => {
		const before = await commissionTotal();
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/commissions/new` );
		const form = page.getByTestId( 'flyaffiliate-commission-form' );
		await expect( form ).toBeVisible();
		const submit = page.getByRole( 'button', { name: 'Add commission' } );

		const amount = form.locator( '#flyaffiliate-commission-amount' );
		// Refused either by the browser (a required or out-of-range field) or
		// by the form's own toast; never sent.
		const refused = async ( toast: string ) => {
			const invalid = await amount.evaluate(
				( el ) => ! ( el as HTMLInputElement ).checkValidity()
			);
			if ( ! invalid ) {
				await admin.expectToast( toast );
			}
		};

		await amount.fill( '3' );
		await submit.click();
		await admin.expectToast(
			'Choose the affiliate who earns this commission.'
		);

		await form.getByRole( 'combobox' ).first().click();
		await page.getByRole( 'option' ).first().click();

		for ( const value of [ '', '0', '-5' ] ) {
			await amount.fill( value );
			await submit.click();
			await refused( 'Enter an amount of more than zero.' );
		}

		await expect( page ).toHaveURL( /#\/commissions\/new/ );
		expect( await commissionTotal() ).toBe( before );
	} );

	test( 'a server error keeps the form open and saves nothing', async ( {
		page,
	} ) => {
		const before = await commissionTotal();
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/commissions/new` );
		const form = page.getByTestId( 'flyaffiliate-commission-form' );
		await form.getByRole( 'combobox' ).first().click();
		await page.getByRole( 'option' ).first().click();
		await form.locator( '#flyaffiliate-commission-amount' ).fill( '3' );
		await form.locator( '#flyaffiliate-commission-source' ).click();
		await page.getByRole( 'option', { name: 'Manual' } ).click();

		await page.route(
			/flyaffiliate%2Fv1%2Fcommissions|flyaffiliate\/v1\/commissions/,
			( route ) =>
				route.request().method() === 'POST'
					? route.fulfill( {
							status: 500,
							contentType: 'application/json',
							body: JSON.stringify( {
								code: 'boom',
								message: 'Server exploded.',
							} ),
					  } )
					: route.continue()
		);
		await page.getByRole( 'button', { name: 'Add commission' } ).click();

		await expect(
			page.locator( '[data-sonner-toast]' ).first()
		).toBeVisible();
		await expect( page ).toHaveURL( /#\/commissions\/new/ );
		await expect(
			form.locator( '#flyaffiliate-commission-amount' )
		).toHaveValue( '3' );
		expect( await commissionTotal() ).toBe( before );
	} );
} );

test.describe( 'Links to records that do not exist', () => {
	test( 'an unknown affiliate, commission or payout says so; no form, no crash', async ( {
		page,
	} ) => {
		const errors: string[] = [];
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		const admin = new AdminPage( page );

		for ( const [ route, text ] of [
			[ '#/affiliates/99999999', /No affiliate with that ID/ ],
			[ '#/commissions/99999999/edit', /No commission with that ID/ ],
			[
				'#/payouts/batch/nope',
				/the payout can.t be found|cannot be found/,
			],
		] as Array< [ string, RegExp ] > ) {
			await admin.goto( urls.app + route );
			await expect(
				admin.app.getByText( text ).first(),
				route
			).toBeVisible();
		}
		await expect(
			page.getByTestId( 'flyaffiliate-commission-form' )
		).toHaveCount( 0 );
		expect( errors ).toEqual( [] );
	} );

	test( 'an unknown payment says so instead of showing an empty one', async ( {
		page,
	} ) => {
		// BUG-010: #/payouts/payment/99999999 renders "Payment #99999999 — No
		// commissions in this payment" with a Delete button.
		// Fixed by PR #22 (was pinned with test.fail()).
		const admin = new AdminPage( page );
		await admin.goto( `${ urls.app }#/payouts/payment/99999999` );
		await expect(
			admin.app
				.getByText(
					/No payment with that ID|the payment can.t be found/
				)
				.first()
		).toBeVisible( { timeout: 5000 } );
		await expect(
			page.getByTestId( 'flyaffiliate-payment-delete' )
		).toHaveCount( 0 );
	} );
} );

test.describe( 'The new-payout screen refuses', () => {
	withSettings( { hold_days: 0 } );

	test( 'a refused preview leaves no older preview to pay', async ( {
		page,
	} ) => {
		// BUG-011 (tests/pw/PRODUCT_BUGS.md): the screen previews everyone on
		// load; "Only the affiliates I choose" with nobody chosen is refused by
		// a toast, but that preview and its Pay button stay. Remove
		// `test.fail()` once fixed. It is declared after the precondition, so a
		// missing first preview fails for real instead of passing as "the bug".
		// Someone owed more than the default minimum, so the screen's first
		// preview has a Pay button to leave behind.
		const owed = await api.post( '/commissions', {
			affiliate_id: createAffiliate().id,
			amount: 75,
			source: 'manual',
			status: 'unpaid',
		} );
		expect( owed.status ).toBe( 201 );
		const admin = new AdminPage( page );
		await admin.goto( urls.newPayout );
		await expect(
			page.getByTestId( 'flyaffiliate-payout-confirm' ),
			'precondition: the first preview can be paid'
		).toBeVisible();
		// Fixed by PR #22 (was pinned with test.fail()).
		await page.getByText( 'Only the affiliates I choose' ).click();
		await page.getByTestId( 'flyaffiliate-payout-preview' ).click();

		await admin.expectToast( 'Choose at least one affiliate.' );
		await expect(
			page.getByTestId( 'flyaffiliate-payout-confirm' )
		).toHaveCount( 0 );
	} );

	test( 'a minimum nobody reaches: nobody to pay, nothing to confirm', async ( {
		page,
	} ) => {
		const batchesBefore = Number(
			( await api.get( '/payouts/batches', { per_page: 1 } ) ).headers[
				'x-wp-total'
			]
		);
		const admin = new AdminPage( page );
		await admin.goto( urls.newPayout );
		await page.locator( '#flyaffiliate-payout-minimum' ).fill( '99999999' );
		await page.getByTestId( 'flyaffiliate-payout-preview' ).click();

		await expect( page.getByText( /Nobody to pay/ ).first() ).toBeVisible();
		await expect(
			page.getByTestId( 'flyaffiliate-payout-confirm' )
		).toHaveCount( 0 );
		expect(
			Number(
				( await api.get( '/payouts/batches', { per_page: 1 } ) )
					.headers[ 'x-wp-total' ]
			)
		).toBe( batchesBefore );
	} );
} );

test.describe( 'Cancelling a confirmation changes nothing', () => {
	withSettings( { hold_days: 0, minimum_amount: 0 } );

	test( 'cancel "Delete payout" and "Mark all as paid"', async ( {
		page,
	} ) => {
		const aff = createAffiliate();
		await api.post( '/commissions', {
			affiliate_id: aff.id,
			amount: 4,
			source: 'manual',
			status: 'unpaid',
			created_at: '2020-01-01 00:00:00',
		} );
		const created = (
			await api.post( '/payouts', {
				mode: 'selected',
				affiliate_ids: [ aff.id ],
			} )
		).body;

		const admin = new AdminPage( page );
		await admin.goto(
			`${ urls.app }#/payouts/batch/${ created.batch_key }`
		);

		for ( const action of [
			'flyaffiliate-batch-delete',
			'flyaffiliate-batch-pay',
		] ) {
			await page.getByTestId( action ).click();
			const dialog = page
				.getByRole( 'alertdialog' )
				.or( page.getByRole( 'dialog' ) )
				.first();
			await expect( dialog ).toBeVisible();
			await dialog.getByRole( 'button', { name: /cancel/i } ).click();
			await expect( dialog ).toBeHidden();
		}

		const summary = (
			await api.get( `/payouts/batch/${ created.batch_key }` )
		).body;
		expect( summary.status ).toBe( 'unpaid' );
		expect( summary.paid ).toBe( 0 );

		await api.del( `/payouts/batch/${ created.batch_key }` );
	} );
} );

test.describe( 'The settings screen refuses', () => {
	test( 'an empty referral variable shows its message and nothing is saved', async ( {
		page,
	} ) => {
		const before = storedSettings().referral_variable;
		const settings = new SettingsPage( page );
		await settings.open();
		await settings.navigate( 'general', 'tracking' );
		await settings.fill( 'referral_variable', '' );

		if ( await settings.saveButton.isEnabled() ) {
			await settings.saveButton.click();
		}
		await expect(
			page.getByText( 'The referral variable cannot be empty.' ).first()
		).toBeVisible();
		expect( storedSettings().referral_variable ).toBe( before );
	} );

	test( 'negative days are refused before saving', async ( { page } ) => {
		const before = storedSettings();
		const settings = new SettingsPage( page );
		await settings.open();
		await settings.navigate( 'commission', 'rules' );
		await settings.fill( 'hold_days', '-3' );

		if ( await settings.saveButton.isEnabled() ) {
			await settings.saveButton.click();
		}
		await expect
			.poll( () => storedSettings().hold_days )
			.toBe( before.hold_days );
	} );
} );
