/**
 * Fixtures for the Dokan specs: the marketplace, vendors, their programs,
 * multi-vendor orders and Dokan's own ledger.
 *
 * These run against FlyAffiliate Pro's paired environment (free + Pro +
 * Dokan Lite, Pro's `flyaffiliate-pro-wp-env-worktrees` skill), selected with
 * `FLYAFFILIATE_WP_ENV_DIR=<pro worktree>` and `BASE_URL`. Pro owns the Dokan
 * extension (Pro ADR-0006, ADR-0008); FlyAffiliate only provides the seams.
 */
import { lit, php } from './wp';
import { PASSWORD, stamp } from './fixtures';

/** Pro's option keys in Dokan's `dokan_selling` section (Pro `Settings`). */
export const DOKAN_KEYS = {
	programs: 'flyaffiliate_pro_vendor_programs',
	defaultRate: 'flyaffiliate_pro_vendor_default_rate',
	allowRates: 'flyaffiliate_pro_vendor_allow_rates',
	maxRate: 'flyaffiliate_pro_vendor_max_rate',
	holdDays: 'flyaffiliate_pro_vendor_hold_days',
	allowHold: 'flyaffiliate_pro_vendor_allow_hold',
	// Dokan's own commission.
	commissionType: 'commission_type',
	adminPercentage: 'admin_percentage',
	additionalFee: 'additional_fee',
} as const;

const PROGRAM = '\\FlyAffiliatePro\\Extensions\\Dokan\\VendorProgram';

/** Whether this site runs Dokan and FlyAffiliate Pro (the specs switch the extension on). */
export function dokanReady(): boolean {
	try {
		return php< boolean >(
			`return function_exists( 'dokan' ) && function_exists( 'flyaffiliate_pro' );`
		);
	} catch {
		return false;
	}
}

export type DokanSnapshot = {
	selling: Record< string, unknown >;
	license: unknown;
	extensions: unknown;
	comingSoon: unknown;
};

/**
 * Prepare the marketplace: a local test license (Pro's QA skill allows it on
 * a local site), the Dokan extension switched on through Pro's REST route by
 * the caller, Dokan's commission and Pro's program settings. Returns what was
 * there, for `restoreMarketplace()`.
 *
 * @param {Record<string, string|number|null>} selling `dokan_selling` keys; null empties one.
 */
export function setMarketplace(
	selling: Record< string, string | number | null >
): DokanSnapshot {
	return php< DokanSnapshot >( `
		$before = [
			'selling' => (array) get_option( 'dokan_selling', [] ),
			'license' => get_option( 'flyaffiliate_pro_license', null ),
			'extensions' => get_option( 'flyaffiliate_pro_active_extensions', null ),
			'comingSoon' => get_option( 'woocommerce_coming_soon', null ),
		];
		// A fresh WooCommerce is in Coming soon mode: shoppers would see a placeholder, not products.
		update_option( 'woocommerce_coming_soon', 'no' );
		// Dokan's vendor dashboard needs pretty permalinks, as every real marketplace has.
		// An environment prerequisite, so it is not restored.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
			flush_rewrite_rules( true );
		}
		$selling = $before['selling'];
		// Empty, not removed: Dokan 5.3 mirrors dokan_selling and dokan_admin_settings
		// both ways, and a removed key comes back from the other store.
		foreach ( json_decode( ${ lit(
			JSON.stringify( selling )
		) }, true ) as $key => $value ) {
			$selling[ $key ] = null === $value ? '' : $value;
		}
		update_option( 'dokan_selling', $selling );
		if ( ! flyaffiliate_pro()->license->is_valid() ) {
			update_option( 'flyaffiliate_pro_license', [ 'key' => 'pw-local-test-license', 'status' => 'activate', 'title' => 'Playwright' ] );
		}
		return $before;
	` );
}

/**
 * Change `dokan_selling` keys only; returns the previous values of those keys.
 * @param selling
 */
export function setSelling(
	selling: Record< string, string | number | null >
): Record< string, unknown > {
	return php( `
		$stored = (array) get_option( 'dokan_selling', [] );
		$before = [];
		foreach ( json_decode( ${ lit(
			JSON.stringify( selling )
		) }, true ) as $key => $value ) {
			$before[ $key ] = $stored[ $key ] ?? null;
			$stored[ $key ] = null === $value ? '' : $value; // Empty, not removed: see setMarketplace().
		}
		update_option( 'dokan_selling', $stored );
		return $before;
	` );
}

export function restoreMarketplace( snapshot: DokanSnapshot ): void {
	php( `
		$s = json_decode( ${ lit( JSON.stringify( snapshot ) ) }, true );
		update_option( 'dokan_selling', $s['selling'] ?: [] );
		null === $s['license'] ? delete_option( 'flyaffiliate_pro_license' ) : update_option( 'flyaffiliate_pro_license', $s['license'] );
		null === $s['extensions'] ? delete_option( 'flyaffiliate_pro_active_extensions' ) : update_option( 'flyaffiliate_pro_active_extensions', $s['extensions'] );
		null === $s['comingSoon'] ? delete_option( 'woocommerce_coming_soon' ) : update_option( 'woocommerce_coming_soon', $s['comingSoon'] );
		return true;
	` );
}

export type Vendor = {
	id: number;
	login: string;
	email: string;
	password: string;
	store: string;
};

/**
 * A Dokan vendor with selling enabled, optionally running an affiliate
 * program through Pro's own `VendorProgram::save()` / `save_hold()`.
 *
 * @param {Object}  options          The vendor's program.
 * @param {boolean} options.optIn    Runs a program.
 * @param {number}  options.rate     Their own rate, if any.
 * @param {number}  options.holdDays Their own hold, if any.
 */
export function createVendor(
	options: { optIn?: boolean; rate?: number; holdDays?: number } = {}
): Vendor {
	const login = `pw-vendor-${ stamp() }`;
	const email = `${ login }@example.test`;
	const store = `PW Store ${ login.slice( -6 ) }`;

	return php< Vendor >( `
		$id = wp_insert_user( [ 'user_login' => ${ lit(
			login
		) }, 'user_pass' => ${ lit( PASSWORD ) }, 'user_email' => ${ lit(
			email
		) }, 'role' => 'seller' ] );
		if ( is_wp_error( $id ) ) { throw new Exception( $id->get_error_message() ); }
		update_user_meta( $id, 'dokan_enable_selling', 'yes' );
		update_user_meta( $id, 'dokan_store_name', ${ lit( store ) } );
		update_user_meta( $id, 'dokan_profile_settings', [ 'store_name' => ${ lit(
			store
		) } ] );
		$program = flyaffiliate_get_container()->get( ${ lit( PROGRAM.slice( 1 ) ) } );
		$program->save( $id, ${ options.optIn ? 'true' : 'false' }, ${
			options.rate === undefined ? 'null' : options.rate
		} );
		${
			options.holdDays === undefined
				? ''
				: `$program->save_hold( $id, ${ options.holdDays } );`
		}
		return [ 'id' => $id, 'login' => ${ lit( login ) }, 'email' => ${ lit(
			email
		) }, 'password' => ${ lit( PASSWORD ) }, 'store' => ${ lit( store ) } ];
	` );
}

/**
 * A published product sold by a vendor.
 * @param vendorId
 * @param price
 * @param rate
 */
export function createVendorProduct(
	vendorId: number,
	price: number,
	rate?: number
): number {
	return php< number >( `
		$product = new WC_Product_Simple();
		$product->set_name( ${ lit( `PW vendor product ${ stamp() }` ) } );
		$product->set_regular_price( ${ lit( String( price ) ) } );
		$product->set_virtual( true );
		$product->set_status( 'publish' );
		$id = $product->save();
		wp_update_post( [ 'ID' => $id, 'post_author' => ${ vendorId } ] );
		${
			rate === undefined
				? ''
				: `update_post_meta( $id, '_flyaffiliate_rate', ${ rate } );`
		}
		return $id;
	` );
}

export type DokanOrder = {
	parent: number;
	/** Vendor id → that vendor's order (a sub-order, or the order itself when one vendor). */
	byVendor: Record< number, number >;
};

/**
 * An order placed the way a checkout places it, so Dokan splits it.
 *
 * Fires `woocommerce_checkout_order_created` (FlyAffiliate's attribution),
 * then `woocommerce_checkout_update_order_meta`, where Pro attributes at 5,
 * Dokan splits at 10 and syncs `dokan_orders` at 20 — the classic checkout's
 * order. The signed `flyaffiliate_ref` cookie is in `$_COOKIE`.
 *
 * @param {Object} input               The order.
 * @param          input.affiliateId
 * @param          input.items
 * @param          input.paymentMethod
 * @param          input.billingEmail
 */
export function dokanCheckout( input: {
	affiliateId?: number;
	items: { productId: number; qty?: number }[];
	paymentMethod?: string;
	billingEmail?: string;
} ): DokanOrder {
	const items = input.items
		.map(
			( i ) =>
				`$order->add_product( wc_get_product( ${ i.productId } ), ${
					i.qty ?? 1
				} );`
		)
		.join( '\n' );

	return php< DokanOrder >( `
		$order = wc_create_order( [ 'customer_id' => 0 ] );
		${ items }
		$order->set_billing_email( ${ lit(
			input.billingEmail ?? `buyer-${ stamp() }@example.test`
		) } );
		$order->set_payment_method( ${ lit( input.paymentMethod ?? 'bacs' ) } );
		$order->calculate_totals();
		$order->save();
		${
			input.affiliateId
				? `$_COOKIE['flyaffiliate_ref'] = \\FlyAffiliate\\Tracking\\Tracker::sign( ${ input.affiliateId }, 0 );`
				: `unset( $_COOKIE['flyaffiliate_ref'] );`
		}
		do_action( 'woocommerce_checkout_order_created', $order );
		do_action( 'woocommerce_checkout_update_order_meta', $order->get_id(), [] );
		$parent = wc_get_order( $order->get_id() );
		$by = [];
		$children = wc_get_orders( [ 'parent' => $parent->get_id(), 'type' => 'shop_order', 'limit' => -1 ] );
		foreach ( $children as $child ) { $by[ (int) $child->get_meta( '_dokan_vendor_id' ) ] = $child->get_id(); }
		if ( ! $children && $parent->get_meta( '_dokan_vendor_id' ) ) { $by[ (int) $parent->get_meta( '_dokan_vendor_id' ) ] = $parent->get_id(); }
		return [ 'parent' => $parent->get_id(), 'byVendor' => $by ];
	` );
}

/**
 * Dokan's own row for a vendor order: what the vendor earns from it.
 * @param orderId
 */
export function dokanOrderRow(
	orderId: number
): { order_total: number; net_amount: number; order_status: string } | null {
	return php( `
		// Dokan caches the row; ask the table.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_total, net_amount, order_status FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d", ${ orderId } ), ARRAY_A );
		return $row ? [ 'order_total' => (float) $row['order_total'], 'net_amount' => (float) $row['net_amount'], 'order_status' => $row['order_status'] ] : null;
	` );
}

/**
 * What Dokan's calculation says now (the live figure gateways read).
 * @param orderId
 */
export function dokanEarning( orderId: number ): {
	vendor: number;
	admin: number;
} {
	return php( `
		$order = wc_get_order( ${ orderId } );
		$calc = dokan()->commission->get_commission( [ 'order' => $order ] );
		if ( ! $calc ) {
			$calc = new \\WeDevs\\Dokan\\Commission\\OrderCommission( $order );
			$calc->calculate();
		}
		return [ 'vendor' => (float) $calc->get_vendor_net_earning(), 'admin' => (float) $calc->get_admin_net_commission() ];
	` );
}

/**
 * Re-save an order the way an admin's Update button does (Dokan re-syncs).
 * @param orderId
 */
export function resaveOrder( orderId: number ): void {
	php(
		`$o = wc_get_order( ${ orderId } ); $o->save(); do_action( 'woocommerce_update_order', $o->get_id(), $o ); return true;`
	);
}

/**
 * Notes on an order, newest first.
 * @param orderId
 */
export function orderNotes( orderId: number ): string[] {
	return php(
		`return array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => ${ orderId } ] ) );`
	);
}

/**
 * An order item's recorded rate source (Pro `VendorRates::SOURCE_META`).
 * @param itemId
 */
export function rateSource(
	itemId: number
): { source: string; capped: boolean; uncapped: number } | null {
	return php( `
		$s = wc_get_order_item_meta( ${ itemId }, '_flyaffiliate_pro_rate_source', true );
		return is_array( $s ) ? $s : null;
	` );
}
