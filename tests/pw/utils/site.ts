/**
 * The site under test.
 */
export const ADMIN = {
	username: process.env.FLYAFFILIATE_ADMIN_USER ?? 'admin',
	password: process.env.FLYAFFILIATE_ADMIN_PASS ?? 'password',
};

const app = '/wp-admin/admin.php?page=flyaffiliate';

export const urls = {
	login: '/wp-login.php',
	app,
	dashboard: `${ app }#/`,
	affiliates: `${ app }#/affiliates`,
	commissions: `${ app }#/commissions`,
	visits: `${ app }#/visits`,
	payouts: `${ app }#/payouts`,
	newPayout: `${ app }#/payouts/new`,
	settings: `${ app }#/settings`,
	setup: `${ app }#/setup`,
	plugins: '/wp-admin/plugins.php',
};
