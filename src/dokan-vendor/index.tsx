/**
 * The vendor dashboard's Affiliates page, registered as a route of Dokan's own
 * React dashboard.
 *
 * The filter is added as the script loads, before Dokan reads its routes. The
 * page gets the router through its props; nothing here imports a router.
 */
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import AffiliatesPage from './AffiliatesPage';

type Route = {
	id: string;
	title: string;
	element: JSX.Element;
	path: string;
	exact: boolean;
	order: number;
	parent: string;
	capabilities: string[];
};

// Match VendorDashboard::ROUTE and VendorDashboard::CAPABILITY.
const route = 'flyaffiliate';
const capability = 'dokan_view_overview_menu';

addFilter(
	'dokan-dashboard-routes',
	'flyaffiliate/vendor-affiliates',
	( routes: Route[] = [] ) => {
		routes.push( {
			id: 'flyaffiliate-affiliates',
			title: __( 'Affiliates', 'flyaffiliate' ),
			element: <AffiliatesPage />,
			path: route,
			exact: true,
			order: 10,
			parent: '',
			capabilities: [ capability ],
		} );

		return routes;
	}
);
