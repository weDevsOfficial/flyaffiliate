/**
 * Playwright configuration for FlyAffiliate's end-to-end suite.
 *
 * Runs against the wp-env development site (`npm run env:start`), where the
 * plugin is active alongside WooCommerce. Mirrors the layout of Dokan's
 * `tests/pw`: page objects under `pages/`, helpers under `utils/`,
 * specs under `specs/`.
 */
import { defineConfig, devices } from '@playwright/test';
import { join } from 'path';

const { CI, BASE_URL, HEADLESS } = process.env;

export default defineConfig( {
	testDir: './specs',
	outputDir: './test-results',
	globalSetup: './global-setup.ts',
	timeout: 60 * 1000,
	expect: { timeout: 15 * 1000 },
	fullyParallel: false,
	forbidOnly: !! CI,
	retries: CI ? 2 : 0,
	workers: 1,
	reporter: CI
		? [ [ 'github' ], [ 'list' ] ]
		: [
				[ 'list' ],
				[ 'html', { open: 'never', outputFolder: './report' } ],
		  ],
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: BASE_URL ?? 'http://localhost:8888',
		// Resolved from this file: Playwright resolves a bare string against the cwd.
		storageState: join( __dirname, '.auth', 'admin.json' ),
		headless: HEADLESS !== 'false',
		actionTimeout: 15 * 1000,
		navigationTimeout: 30 * 1000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		viewport: { width: 1440, height: 900 },
	},
} );
