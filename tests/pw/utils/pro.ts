/**
 * Fixtures for the FlyAffiliate Pro specs: the license, the Extensions
 * switch, and a PayPal sandbox stand-in so automatic payouts run end to end
 * without the network.
 *
 * Pro runs only in its paired environment (free + Pro, Pro's
 * `flyaffiliate-pro-wp-env-worktrees` skill), selected with
 * `FLYAFFILIATE_WP_ENV_DIR=<pro worktree>` and `BASE_URL`; see SKILL.md "Pro".
 */
import { readFileSync } from 'fs';
import { join } from 'path';
import type { Api } from './api';
import { lit, php, wp } from './wp';

/** Whether this site runs FlyAffiliate Pro. */
export function proReady(): boolean {
	try {
		return php< boolean >(
			`return function_exists( 'flyaffiliate_pro' );`
		);
	} catch {
		return false;
	}
}

export type ProSnapshot = {
	license: unknown;
	extensions: unknown;
	paypal: unknown;
	accounts: unknown;
};

/**
 * A local test license (Pro's QA skill allows it on a local site; it is the
 * one gate extensions load behind, Pro ADR-0003) and a snapshot of every
 * option the Pro specs touch, for `restorePro()`.
 */
export function preparePro(): ProSnapshot {
	const snapshot = php< ProSnapshot >( `
		$before = [
			'license' => get_option( 'flyaffiliate_pro_license', null ),
			'extensions' => get_option( 'flyaffiliate_pro_active_extensions', null ),
			'paypal' => get_option( 'flyaffiliate_pro_paypal', null ),
			'accounts' => get_option( 'flyaffiliate_pro_payout_accounts', null ),
		];
		update_option( 'flyaffiliate_pro_license', [ 'key' => 'pw-local-test-license-0123456789', 'status' => 'activate', 'title' => 'Playwright' ] );
		// Pretty permalinks with their .htaccess (Pro's admin calls /wp-json/, Dokan's dashboard
		// needs them): an environment prerequisite, so not restored. wp-env's --update drops the file.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		return $before;
	` );
	// .htaccess too: inside \`wp eval\` flush_rewrite_rules( true ) cannot write it
	// (save_mod_rewrite_rules() lives in wp-admin), \`wp rewrite flush --hard\` can.
	wp( [ 'rewrite', 'flush', '--hard' ] );
	return snapshot;
}

export function restorePro( snapshot: ProSnapshot ): void {
	php( `
		$s = json_decode( ${ lit( JSON.stringify( snapshot ) ) }, true );
		foreach ( [ 'license' => 'flyaffiliate_pro_license', 'extensions' => 'flyaffiliate_pro_active_extensions', 'paypal' => 'flyaffiliate_pro_paypal', 'accounts' => 'flyaffiliate_pro_payout_accounts' ] as $key => $option ) {
			null === $s[ $key ] ? delete_option( $option ) : update_option( $option, $s[ $key ] );
		}
		return true;
	` );
}

/** Remove the license; returns what was stored, for `setLicense()`. */
export function dropLicense(): unknown {
	return php(
		`$l = get_option( 'flyaffiliate_pro_license', null ); delete_option( 'flyaffiliate_pro_license' ); return $l;`
	);
}

export function setLicense( license: unknown ): void {
	php( `
		$l = json_decode( ${ lit( JSON.stringify( license ) ) }, true );
		null === $l ? delete_option( 'flyaffiliate_pro_license' ) : update_option( 'flyaffiliate_pro_license', $l );
		return true;
	` );
}

/**
 * Switch an extension through Pro's own route (`PUT /extensions/{id}`), as
 * the card's switch does.
 *
 * @param {Api}     api    An admin client.
 * @param {string}  id     Extension id: dokan, paypal.
 * @param {boolean} active On or off.
 * @return {Promise<{status: number, body: any}>} The reply.
 */
export async function switchExtension(
	api: Api,
	id: string,
	active: boolean
): Promise< { status: number; body: any } > {
	const res = await api.put( `/flyaffiliate-pro/v1/extensions/${ id }`, {
		active,
	} );
	return { status: res.status, body: res.body };
}

// ── PayPal sandbox stand-in (tests/pw/fixtures/paypal-mock.php) ──────────

export type PayPalMode = {
	token?: 'ok' | 'invalid';
	send?: 'ok' | 'reject' | 'down';
	item_status?: string;
	verify?: 'SUCCESS' | 'FAILURE';
};

export type PayPalCall = {
	method: string;
	path: string;
	body: any;
	headers: Record< string, string >;
};

const MOCK = readFileSync(
	join( __dirname, '..', 'fixtures', 'paypal-mock.php' ),
	'utf8'
);

/**
 * Install the stand-in as an mu-plugin, with a clean log.
 * @param mode
 */
export function installPayPalMock( mode: PayPalMode = {} ): void {
	php( `
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/pw-paypal-mock.php', ${ lit( MOCK ) } );
		update_option( 'pw_paypal_mock', json_decode( ${ lit(
			JSON.stringify( mode )
		) }, true ), false );
		delete_option( 'pw_paypal_log' );
		delete_option( 'pw_paypal_batches' );
		return true;
	` );
}

export function removePayPalMock(): void {
	php( `
		@unlink( WPMU_PLUGIN_DIR . '/pw-paypal-mock.php' );
		foreach ( [ 'pw_paypal_mock', 'pw_paypal_log', 'pw_paypal_batches' ] as $o ) { delete_option( $o ); }
		return true;
	` );
}

/**
 * Change what the stand-in answers from now on.
 * @param mode
 */
export function setPayPalMode( mode: PayPalMode ): void {
	php( `
		update_option( 'pw_paypal_mock', array_merge( (array) get_option( 'pw_paypal_mock', [] ), json_decode( ${ lit(
			JSON.stringify( mode )
		) }, true ) ), false );
		return true;
	` );
}

/** Every request Pro sent to "PayPal", oldest first. */
export function payPalLog(): PayPalCall[] {
	return php(
		`return array_values( (array) get_option( 'pw_paypal_log', [] ) );`
	);
}

/** The payout batches Pro sent (POST /v1/payments/payouts). */
export const payoutRequests = () =>
	payPalLog().filter(
		( c ) => 'POST' === c.method && '/v1/payments/payouts' === c.path
	);

/**
 * Connect the site's own PayPal app (sandbox) through Pro's route, as the
 * PayPal page's Connect does. Any keys work against the stand-in.
 *
 * @param {Api} api An admin client.
 * @return {Promise<{status: number, body: any}>} The reply.
 */
export async function connectPayPal(
	api: Api
): Promise< { status: number; body: any } > {
	const res = await api.post( '/flyaffiliate-pro/v1/paypal/connection', {
		environment: 'sandbox',
		client_id: 'pw-client-id',
		client_secret: 'pw-client-secret',
	} );
	return { status: res.status, body: res.body };
}

/**
 * An affiliate is paid by PayPal, to this email.
 * @param affiliateId
 * @param email
 */
export function payAffiliateByPayPal(
	affiliateId: number,
	email: string
): void {
	php( `
		$affiliate = flyaffiliate()->affiliate->get( ${ affiliateId } );
		flyaffiliate()->affiliate->update( ${ affiliateId }, [ 'payout_method' => 'paypal' ] );
		update_user_meta( (int) $affiliate->get( 'user_id' ), '_flyaffiliate_payout_details_paypal', [ 'email' => ${ lit(
			email
		) } ] );
		return true;
	` );
}

/**
 * Pro's transfer rows for a payment, newest first.
 * @param paymentId
 */
export function transfersOf(
	paymentId: number
): Array< Record< string, string > > {
	return php( `
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}flyaffiliate_payout_transfers WHERE payout_id = %d ORDER BY id DESC", ${ paymentId } ), ARRAY_A );
	` );
}
