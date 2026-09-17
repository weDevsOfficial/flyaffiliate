/**
 * Routes and the shell around every page.
 */
import { applyFilters } from '@wordpress/hooks';
import {
	createHashRouter,
	Navigate,
	RouterProvider,
	type RouteObject,
} from 'react-router-dom';
import { SlotFillProvider } from '@wordpress/components';
import { ThemeProvider, Toaster } from '@wedevs/plugin-ui';
import Layout from './components/Layout';
import AffiliatesPage from './pages/affiliates';
import AffiliatePage from './pages/affiliates/single';
import CommissionsPage from './pages/commissions';
import CommissionFormPage from './pages/commissions/form';
import VisitsPage from './pages/visits';
import DashboardPage from './pages/dashboard';
import PayoutsPage from './pages/payouts';
import PayoutBatchPage from './pages/payouts/batch';
import PaymentsPage from './pages/payouts/payments';
import PaymentPage from './pages/payouts/payment';
import NewPayoutPage from './pages/payouts/new';
import SettingsPage from './pages/settings';
import SetupPage from './pages/setup';
import NotFound from './components/NotFound';
import { theme } from './theme';

export type AdminRoute = {
	id: string;
	path: string;
	element: React.ReactNode;
};

/**
 * The routes, after extensions had their say.
 *
 * @return {AdminRoute[]} The routes.
 */
function getRoutes(): AdminRoute[] {
	const routes: AdminRoute[] = [
		{ id: 'dashboard', path: '/', element: <DashboardPage /> },
		{
			id: 'dashboard-route',
			path: '/dashboard',
			element: <Navigate to="/" replace />,
		},
		{ id: 'affiliates', path: '/affiliates', element: <AffiliatesPage /> },
		{
			id: 'affiliate',
			path: '/affiliates/:id',
			element: <AffiliatePage />,
		},
		{
			id: 'commissions',
			path: '/commissions',
			element: <CommissionsPage />,
		},
		{
			id: 'commission-new',
			path: '/commissions/new',
			element: <CommissionFormPage />,
		},
		{
			id: 'commission-edit',
			path: '/commissions/:id/edit',
			element: <CommissionFormPage />,
		},
		{ id: 'visits', path: '/visits', element: <VisitsPage /> },
		{ id: 'payouts', path: '/payouts', element: <PayoutsPage /> },
		{ id: 'payout-new', path: '/payouts/new', element: <NewPayoutPage /> },
		{
			id: 'payments',
			path: '/payouts/payments',
			element: <PaymentsPage />,
		},
		{
			id: 'payment',
			path: '/payouts/payment/:id',
			element: <PaymentPage />,
		},
		{
			id: 'payout-batch',
			path: '/payouts/batch/:batchKey',
			element: <PayoutBatchPage />,
		},
		{ id: 'settings', path: '/settings', element: <SettingsPage /> },
		{ id: 'setup', path: '/setup', element: <SetupPage /> },
	];

	/**
	 * Filters the admin app's routes.
	 *
	 * @param {AdminRoute[]} routes The routes.
	 */
	const filtered = applyFilters(
		'flyaffiliate_admin_routes',
		routes
	) as AdminRoute[];

	return [
		...filtered,
		{ id: 'not-found', path: '*', element: <NotFound /> },
	];
}

const router = createHashRouter(
	getRoutes().map(
		( route ): RouteObject => ( {
			path: route.path,
			element: <Layout>{ route.element }</Layout>,
		} )
	)
);

export default function App() {
	return (
		<SlotFillProvider>
			<ThemeProvider pluginId="flyaffiliate" tokens={ theme }>
				<RouterProvider router={ router } />
				<Toaster richColors position="bottom-right" />
			</ThemeProvider>
		</SlotFillProvider>
	);
}
