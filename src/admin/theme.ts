/**
 * FlyAffiliate's plugin-ui theme tokens.
 *
 * The brand colour is the blue of the logo (the WordPress.org icon); the
 * frontend stylesheet and the setup wizard use the same value. Everything
 * else inherits plugin-ui's neutral palette so the app sits quietly inside
 * wp-admin.
 */
import type { ThemeTokens } from '@wedevs/plugin-ui';

export const BRAND = '#0043da';

export const theme: ThemeTokens = {
	primary: BRAND,
	primaryForeground: '#ffffff',
	// The settings sidebar's hover: plugin-ui paints a sub-item with
	// `sidebar-accent` on hover, so the brand tint (8% blue on white) goes in
	// as the token rather than as a rule fighting the utility.
	sidebarAccent: '#ebf0fc',
	sidebarAccentForeground: BRAND,
	ring: '#668ee9',
	// plugin-ui ships no success token; DataViews' confirm buttons for
	// positive actions (Activate, Mark unpaid) read this one.
	success: '#16a34a',
	successForeground: '#ffffff',
	radius: '0.5rem',
};
