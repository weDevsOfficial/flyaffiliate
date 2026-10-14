/**
 * The "Affiliate Commission" box on WooCommerce → Analytics → Overview opens
 * FlyAffiliate's Commissions screen.
 *
 * WooCommerce links each box to a page of its own admin app, so the box
 * links to `/flyaffiliate/commissions`, a page this adds to that app, which
 * hands over to FlyAffiliate's screen. A link opened in a new tab lands on
 * the same page and goes the same way.
 */
import { addFilter } from '@wordpress/hooks';
import { useEffect } from '@wordpress/element';

type AnalyticsGlobals = {
	/** The page's path inside WooCommerce's admin app. */
	path: string;
	/** FlyAffiliate's Commissions screen. */
	commissionsUrl: string;
	/** The page's title, for WooCommerce's breadcrumbs. */
	title: string;
};

declare global {
	interface Window {
		flyaffiliateAnalytics?: AnalyticsGlobals;
	}
}

type AdminPage = {
	container: () => null;
	path: string;
	breadcrumbs: string[];
	capability: string;
};

const globals = window.flyaffiliateAnalytics;

/**
 * Leave WooCommerce's app for FlyAffiliate's Commissions screen.
 *
 * @return {null} Nothing: the browser is on its way.
 */
function GoToCommissions(): null {
	useEffect( () => {
		if ( globals ) {
			window.location.replace( globals.commissionsUrl );
		}
	}, [] );

	return null;
}

if ( globals ) {
	addFilter(
		'woocommerce_admin_pages_list',
		'flyaffiliate/analytics-commissions',
		( pages: AdminPage[] ): AdminPage[] => [
			...pages,
			{
				container: GoToCommissions,
				path: globals.path,
				breadcrumbs: [ globals.title ],
				capability: 'view_woocommerce_reports',
			},
		]
	);
}
