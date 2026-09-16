/**
 * Logs in as the wp-env administrator once and stores the session for every
 * spec, then seeds sample data so the lists have rows to work with.
 */
import { chromium, type FullConfig } from '@playwright/test';
import { execSync } from 'child_process';
import { mkdirSync } from 'fs';
import { join } from 'path';
import { ADMIN, urls } from './utils/site';

async function globalSetup( config: FullConfig ): Promise< void > {
	const baseURL = config.projects[ 0 ].use.baseURL ?? 'http://localhost:8888';

	if ( process.env.FLYAFFILIATE_SKIP_SEED !== '1' ) {
		// The seeder refuses to run outside a development environment; wp-env's
		// CLI container does not set the type, so it is passed explicitly.
		execSync(
			'npx wp-env run cli -- bash -c "WP_ENVIRONMENT_TYPE=development wp flyaffiliate seed --affiliates=4 --commissions=20 --visits=30 --yes"',
			{ stdio: 'inherit', cwd: join( __dirname, '..', '..' ) }
		);
	}

	mkdirSync( join( __dirname, '.auth' ), { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );

	await page.goto( urls.login );
	await page.fill( '#user_login', ADMIN.username );
	await page.fill( '#user_pass', ADMIN.password );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page
		.context()
		.storageState( { path: join( __dirname, '.auth', 'admin.json' ) } );
	await browser.close();
}

export default globalSetup;
