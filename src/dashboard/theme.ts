/**
 * The affiliate dashboard's plugin-ui theme tokens.
 *
 * No brand colour on the frontend: the dashboard sits inside whatever theme
 * the site runs, so it keeps plugin-ui's neutral palette — a near-black
 * primary, grey rings and muted greys — which reads as part of any theme.
 * The admin app carries the brand teal in `src/admin/theme.ts`.
 */
import type { ThemeTokens } from '@wedevs/plugin-ui';

export const theme: ThemeTokens = {
	// plugin-ui ships no success token; DataViews' confirm buttons for
	// positive actions read this one.
	success: '#16a34a',
	successForeground: '#ffffff',
	radius: '0.5rem',
};
