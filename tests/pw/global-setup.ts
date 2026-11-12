/**
 * Logs in as the wp-env administrator once and stores the session for every
 * spec, then seeds sample data so the lists have rows to work with.
 */
import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync, readFileSync } from 'fs';
import { join } from 'path';
import { ADMIN, logIn } from './utils/site';
import { lit, php, wp } from './utils/wp';

async function globalSetup( config: FullConfig ): Promise< void > {
	const baseURL = config.projects[ 0 ].use.baseURL ?? 'http://localhost:8888';

	if ( process.env.FLYAFFILIATE_SKIP_SEED !== '1' ) {
		// The seeder refuses to run outside a development environment; wp()
		// passes WP_ENVIRONMENT_TYPE=development to the CLI container.
		process.stdout.write(
			wp( [
				'flyaffiliate',
				'seed',
				'--affiliates=4',
				'--commissions=20',
				'--visits=30',
				'--yes',
			] )
		);
	}

	// Test helpers (fixtures/pw-helpers.php): the PHP error log the last spec
	// reads (zz-php-log.spec.ts), a mail catcher, a payout method that cannot
	// pay. The log starts empty, so it holds only this run.
	php( `
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/pw-helpers.php', ${ lit(
			readFileSync(
				join( __dirname, 'fixtures', 'pw-helpers.php' ),
				'utf8'
			)
		) } );
		file_put_contents( WP_CONTENT_DIR . '/pw-php.log', '' );
		delete_option( 'pw_mail_log' );
		return true;
	` );

	mkdirSync( join( __dirname, '.auth' ), { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );

	await logIn( page, ADMIN.username, ADMIN.password );
	await page
		.context()
		.storageState( { path: join( __dirname, '.auth', 'admin.json' ) } );
	await browser.close();
}

export default globalSetup;
