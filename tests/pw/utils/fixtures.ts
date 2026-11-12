/**
 * Factories for the data a spec owns: affiliates, products, orders,
 * settings. Each returns ids, so assertions can be exact instead of
 * counting global rows the seeder or another spec also touches.
 */
import { test, type Browser, type BrowserContext } from '@playwright/test';
import { join } from 'path';
import { BASE_URL } from './api';
import { logIn } from './site';
import { lit, php } from './wp';

let counter = 0;

/** A value unique to this run and call. */
export const stamp = (): string =>
	`${ Date.now().toString( 36 ) }${ ++counter }`;

// ── Settings ──────────────────────────────────────────────────────────────

export type SettingValue = string | number;
export type Snapshot = Record< string, SettingValue | null >;

/**
 * Write settings straight into `flyaffiliate_settings` (ADR-0010: one flat
 * option; switches are 'on'/'off'). Returns what was there, for restore.
 *
 * @param {Record<string,SettingValue>} values Field id → value.
 */
export function setSettings(
	values: Record< string, SettingValue >
): Snapshot {
	return php< Snapshot >( `
		$values = json_decode( ${ lit( JSON.stringify( values ) ) }, true );
		$stored = (array) get_option( 'flyaffiliate_settings', [] );
		$before = [];
		foreach ( $values as $key => $value ) {
			$before[ $key ] = array_key_exists( $key, $stored ) ? $stored[ $key ] : null;
			$stored[ $key ] = $value;
		}
		update_option( 'flyaffiliate_settings', $stored );
		return $before;
	` );
}

/**
 * Put back what `setSettings()` replaced; null removes the key.
 * @param {Snapshot} snapshot What `setSettings()` returned.
 */
export function restoreSettings( snapshot: Snapshot ): void {
	php( `
		$values = json_decode( ${ lit( JSON.stringify( snapshot ) ) }, true );
		$stored = (array) get_option( 'flyaffiliate_settings', [] );
		foreach ( $values as $key => $value ) {
			if ( null === $value ) { unset( $stored[ $key ] ); } else { $stored[ $key ] = $value; }
		}
		update_option( 'flyaffiliate_settings', $stored );
		return true;
	` );
}

/**
 * The settings every spec starts from: the installer's defaults. Global setup
 * writes them before each run, and `withSettings()` puts its keys back to these
 * values — never to a snapshot, which a worker restart (Playwright re-runs
 * `beforeAll` after a failure) would capture already changed.
 */
export const BASELINE: Record< string, SettingValue > = {
	referral_variable: 'affiliate',
	cookie_duration: 30,
	currency: 'USD',
	currency_position: 'left',
	currency_thousands_separator: ',',
	currency_decimal_separator: '.',
	registration_enabled: 'on',
	data_clear_on_uninstall: 'off',
	default_rate: 10,
	max_rate: 50,
	rate_type: 'percentage',
	hold_days: 30,
	exclude_shipping: 'on',
	exclude_tax: 'on',
	block_self_referral: 'on',
	reject_commissions_on_refund: 'off',
	minimum_amount: 50,
	method: 'manual',
	activation_email_enabled: 'on',
	woocommerce_enabled: 'on',
};

/** Write the whole baseline (global setup). */
export function applyBaseline(): void {
	setSettings( BASELINE );
}

/**
 * Settings for a describe block: set before its tests, back to the baseline
 * after them. For a change inside one test, use `setSettings()` and
 * `restoreSettings()` in a `try/finally`.
 *
 * @param {Record<string,SettingValue>} values Field id → value.
 */
export function withSettings( values: Record< string, SettingValue > ): void {
	test.beforeAll( () => {
		setSettings( values );
	} );
	test.afterAll( () => {
		restoreSettings(
			Object.fromEntries(
				Object.keys( values ).map( ( key ) => [
					key,
					BASELINE[ key ] ?? null,
				] )
			)
		);
	} );
}

// ── Affiliates ────────────────────────────────────────────────────────────

export type TestAffiliate = {
	id: number;
	userId: number;
	login: string;
	email: string;
	password: string;
};

export const PASSWORD = 'pw-Password-1';

/**
 * A WordPress user with an affiliate row.
 *
 * @param {string} status pending|active|inactive|suspended.
 */
export function createAffiliate( status = 'active' ): TestAffiliate {
	const login = `pw-aff-${ stamp() }`;
	const email = `${ login }@example.test`;

	return php< TestAffiliate >( `
		$user_id = wp_create_user( ${ lit( login ) }, ${ lit( PASSWORD ) }, ${ lit(
			email
		) } );
		if ( is_wp_error( $user_id ) ) { throw new Exception( $user_id->get_error_message() ); }
		$affiliate = flyaffiliate()->affiliate->create( [
			'user_id' => $user_id,
			'status' => ${ lit( status ) },
			'payment_email' => ${ lit( email ) },
		] );
		if ( is_wp_error( $affiliate ) ) { throw new Exception( $affiliate->get_error_message() ); }
		return [ 'id' => $affiliate->get_id(), 'userId' => $user_id, 'login' => ${ lit(
			login
		) }, 'email' => ${ lit( email ) }, 'password' => ${ lit( PASSWORD ) } ];
	` );
}

/**
 * A plain WordPress user with a role, no affiliate row.
 * @param {string} role A WordPress role.
 */
export function createUser( role = 'subscriber' ): Omit< TestAffiliate, 'id' > {
	const login = `pw-user-${ stamp() }`;
	const email = `${ login }@example.test`;

	return php( `
		$user_id = wp_insert_user( [ 'user_login' => ${ lit(
			login
		) }, 'user_pass' => ${ lit( PASSWORD ) }, 'user_email' => ${ lit(
			email
		) }, 'role' => ${ lit( role ) } ] );
		if ( is_wp_error( $user_id ) ) { throw new Exception( $user_id->get_error_message() ); }
		return [ 'userId' => $user_id, 'login' => ${ lit( login ) }, 'email' => ${ lit(
			email
		) }, 'password' => ${ lit( PASSWORD ) } ];
	` );
}

export function setAffiliateStatus( id: number, status: string ): void {
	php(
		`flyaffiliate()->affiliate->update( ${ id }, [ 'status' => ${ lit(
			status
		) } ] ); return true;`
	);
}

/**
 * Log a user in through wp-login.php and keep the session.
 *
 * @param {Browser} browser       The test browser.
 * @param {Object}  user          Login and password.
 * @param           user.login
 * @param           user.password
 * @return The context and the path of its saved storage state (for `Api.as`).
 */
export async function loginAs(
	browser: Browser,
	user: { login: string; password: string }
): Promise< { context: BrowserContext; state: string } > {
	const context = await browser.newContext( {
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
	} );
	const page = await context.newPage();
	await logIn( page, user.login, user.password );
	await page.close();

	const state = join( __dirname, '..', '.auth', `${ user.login }.json` );
	await context.storageState( { path: state } );

	return { context, state };
}

// ── WooCommerce ───────────────────────────────────────────────────────────

/**
 * A published simple product.
 *
 * @param {number} price Regular price.
 * @param {number} rate  Optional product rate (`_flyaffiliate_rate`).
 */
export function createProduct( price: number, rate?: number ): number {
	return php< number >( `
		$product = new WC_Product_Simple();
		$product->set_name( ${ lit( `PW product ${ stamp() }` ) } );
		$product->set_regular_price( ${ lit( String( price ) ) } );
		$product->set_status( 'publish' );
		$id = $product->save();
		${
			rate === undefined
				? ''
				: `update_post_meta( $id, '_flyaffiliate_rate', ${ rate } );`
		}
		return $id;
	` );
}

/**
 * A percent coupon.
 * @param {number} percent The discount.
 */
export function createCoupon( percent: number ): string {
	const code = `pw${ stamp() }`;
	php( `
		$coupon = new WC_Coupon();
		$coupon->set_code( ${ lit( code ) } );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( ${ percent } );
		$coupon->save();
		return true;
	` );
	return code;
}

export type OrderInput = {
	/** Attribute to this affiliate, as if their cookie came with the checkout. */
	affiliateId?: number;
	visitId?: number;
	/** A raw cookie value instead (to test tampering). */
	cookie?: string;
	items: { productId: number; qty?: number }[];
	shipping?: number;
	coupon?: string;
	paymentMethod?: string;
	billingEmail?: string;
	customerId?: number;
	/** Status to create the order in before checkout attribution runs. */
	status?: string;
};

/**
 * Create an order and run checkout attribution on it.
 *
 * Fires `woocommerce_checkout_order_created` — the same hook a classic
 * checkout fires — with the signed `flyaffiliate_ref` cookie in `$_COOKIE`,
 * which is exactly what `OrderAttribution::attribute()` reads. The browser
 * journey in money-journey.spec.ts covers the real block checkout.
 *
 * @param {OrderInput} input What to put in the order.
 * @return {number} The order id.
 */
export function createOrder( input: OrderInput ): number {
	const items = input.items
		.map(
			( item ) =>
				`$order->add_product( wc_get_product( ${ item.productId } ), ${
					item.qty ?? 1
				} );`
		)
		.join( '\n' );
	let cookie = `unset( $_COOKIE['flyaffiliate_ref'] );`;
	if ( input.cookie !== undefined ) {
		cookie = `$_COOKIE['flyaffiliate_ref'] = ${ lit( input.cookie ) };`;
	} else if ( input.affiliateId ) {
		cookie = `$_COOKIE['flyaffiliate_ref'] = \\FlyAffiliate\\Tracking\\Tracker::sign( ${
			input.affiliateId
		}, ${ input.visitId ?? 0 } );`;
	}

	return php< number >( `
		$order = wc_create_order( [ 'customer_id' => ${ input.customerId ?? 0 } ] );
		${ items }
		${
			input.shipping
				? `$ship = new WC_Order_Item_Shipping(); $ship->set_method_title( 'Flat rate' ); $ship->set_method_id( 'flat_rate' ); $ship->set_total( ${ lit(
						String( input.shipping )
				  ) } ); $order->add_item( $ship );`
				: ''
		}
		$order->set_billing_email( ${ lit(
			input.billingEmail ?? `buyer-${ stamp() }@example.test`
		) } );
		$order->set_payment_method( ${ lit( input.paymentMethod ?? 'bacs' ) } );
		${ input.coupon ? `$order->apply_coupon( ${ lit( input.coupon ) } );` : '' }
		$order->calculate_totals();
		${ input.status ? `$order->set_status( ${ lit( input.status ) } );` : '' }
		$order->save();
		${ cookie }
		do_action( 'woocommerce_checkout_order_created', $order );
		return $order->get_id();
	` );
}

/**
 * Fire the checkout hook again for an existing order (idempotency checks).
 * @param orderId
 * @param affiliateId
 */
export function reattribute( orderId: number, affiliateId: number ): void {
	php( `
		$_COOKIE['flyaffiliate_ref'] = \\FlyAffiliate\\Tracking\\Tracker::sign( ${ affiliateId }, 0 );
		do_action( 'woocommerce_checkout_order_created', wc_get_order( ${ orderId } ) );
		return true;
	` );
}

/**
 * Move an order to a status through WooCommerce, firing its hooks.
 * @param orderId
 * @param status
 */
export function setOrderStatus( orderId: number, status: string ): void {
	php(
		`wc_get_order( ${ orderId } )->update_status( ${ lit(
			status
		) } ); return true;`
	);
}

/**
 * Fire `woocommerce_order_status_changed` again without a real change.
 * @param orderId
 * @param from
 * @param to
 */
export function refireStatus(
	orderId: number,
	from: string,
	to: string
): void {
	php(
		`do_action( 'woocommerce_order_status_changed', ${ orderId }, ${ lit(
			from
		) }, ${ lit( to ) }, wc_get_order( ${ orderId } ) ); return true;`
	);
}

export function trashOrder( orderId: number ): void {
	php( `wc_get_order( ${ orderId } )->delete( false ); return true;` );
}

export function orderMeta( orderId: number, key: string ): string {
	return php< string >(
		`return (string) wc_get_order( ${ orderId } )->get_meta( ${ lit(
			key
		) } );`
	);
}

// ── Ledger reads ──────────────────────────────────────────────────────────

export type Row = {
	id: number;
	affiliate_id: number;
	order_id: number;
	order_item_id: number | null;
	status: string;
	amount: number;
	base_amount: number;
	rate: number;
	rate_type: string;
	vendor_id: number;
	source: string;
	payout_id: number;
	created_at: string;
	matures_at: string | null;
};

/**
 * Every commission row on an order, straight from the table.
 * @param orderId
 */
export function orderCommissions( orderId: number ): Row[] {
	// Manual rows are left out: `wp flyaffiliate seed` (global-setup) gives its
	// manual commissions random order ids, which collide with orders the specs
	// create (BUG-012). A spec reads a manual commission by its own id.
	return php< Row[] >( `
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}flyaffiliate_commissions WHERE order_id = %d AND source <> 'manual' ORDER BY id ASC", ${ orderId } ), ARRAY_A );
		return array_map( function ( $r ) {
			foreach ( [ 'id', 'affiliate_id', 'order_id', 'vendor_id', 'payout_id' ] as $k ) { $r[ $k ] = (int) $r[ $k ]; }
			foreach ( [ 'amount', 'base_amount', 'rate' ] as $k ) { $r[ $k ] = (float) $r[ $k ]; }
			$r['order_item_id'] = null === $r['order_item_id'] ? null : (int) $r['order_item_id'];
			return $r;
		}, $rows );
	` );
}

/**
 * One visit row, straight from the table.
 * @param id
 */
export function visitRow( id: number ): Record< string, string > | null {
	return php( `
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}flyaffiliate_visits WHERE id = %d", ${ id } ), ARRAY_A );
	` );
}

/**
 * The newest visits of an affiliate.
 * @param affiliateId
 */
export function affiliateVisits(
	affiliateId: number
): Record< string, string >[] {
	return php( `
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}flyaffiliate_visits WHERE affiliate_id = %d ORDER BY id DESC", ${ affiliateId } ), ARRAY_A );
	` );
}

/**
 * A signed attribution cookie value, as the tracker writes it.
 * @param affiliateId
 * @param visitId
 */
export function signedCookie( affiliateId: number, visitId: number ): string {
	return php< string >(
		`return \\FlyAffiliate\\Tracking\\Tracker::sign( ${ affiliateId }, ${ visitId } );`
	);
}

/** The affiliate dashboard and register pages. */
export function pageUrls(): { dashboard: string; register: string } {
	return php(
		`return [ 'dashboard' => flyaffiliate_get_page_url( 'affiliate_dashboard' ), 'register' => flyaffiliate_get_page_url( 'affiliate_register' ) ];`
	);
}

/**
 * Rows in one of FlyAffiliate's tables, for "a refusal wrote nothing" checks.
 *
 * @param {string} table  Table name without the prefix, e.g. `commissions`.
 * @param {string} column Column to match.
 * @param {number} value  Value it must equal.
 * @return {number} The count.
 */
export function countRows(
	table: string,
	column: string,
	value: number
): number {
	if ( ! /^[a-z_]+$/.test( table + column ) ) {
		throw new Error( 'countRows: table and column are identifiers' );
	}

	return php< number >(
		`global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}flyaffiliate_${ table } WHERE ${ column } = %d", ${ value } ) );`
	);
}

/** The stored settings option as it is right now. */
export function storedSettings(): Record< string, SettingValue > {
	return php( `return (array) get_option( 'flyaffiliate_settings', [] );` );
}
