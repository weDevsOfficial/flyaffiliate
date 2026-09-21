/**
 * FlyAffiliate's plugin-ui theme tokens.
 *
 * The brand colour is the teal of the WordPress.org icon; the
 * frontend stylesheet and the setup wizard use the same value. Everything
 * else inherits plugin-ui's neutral palette so the app sits quietly inside
 * wp-admin.
 */
import type { ThemeTokens } from '@wedevs/plugin-ui';

export const BRAND = '#0d7377';

export const theme: ThemeTokens = {
	primary: BRAND,
	primaryForeground: '#ffffff',
	// The settings sidebar's hover: plugin-ui paints a sub-item with
	// `sidebar-accent` on hover, so the brand tint (8% teal on white) goes in
	// as the token rather than as a rule fighting the utility.
	sidebarAccent: '#ecf4f4',
	sidebarAccentForeground: BRAND,
	ring: '#5fb3ae',
	// plugin-ui ships no success token; DataViews' confirm buttons for
	// positive actions (Activate, Mark unpaid) read this one.
	success: '#16a34a',
	successForeground: '#ffffff',
	radius: '0.5rem',
};
