/**
 * The release zip, as a store owner gets it: built by bin/build-zip.php,
 * uploaded through Plugins → Add New → Upload on wp-env's *tests* site
 * (port 8889, where Plugin Check runs too), activated, and its admin opened.
 * It must ship no sources or dev files and load every asset it asks for.
 *
 * Scenario id: references/scenarios.md STD-10.
 */
import { expect, test } from '@playwright/test';
import { execFileSync } from 'child_process';
import { readdirSync } from 'fs';
import { join } from 'path';
import { logIn } from '../utils/site';

const ROOT = join( __dirname, '..', '..', '..' );
const TESTS_SITE =
	process.env.FLYAFFILIATE_TESTS_URL ?? 'http://localhost:8889';

test.describe( 'Release zip [STD-10]', () => {
	test.skip(
		!! process.env.FLYAFFILIATE_WP_ENV_DIR,
		'runs against the free checkout’s own wp-env tests site'
	);
	test.use( {
		baseURL: TESTS_SITE,
		storageState: { cookies: [], origins: [] },
	} );
	test.setTimeout( 5 * 60 * 1000 );

	let zip: string;

	test.beforeAll( () => {
		execFileSync( 'php', [ 'bin/build-zip.php' ], {
			cwd: ROOT,
			stdio: 'ignore',
		} );
		const name = readdirSync( join( ROOT, 'build' ) )
			.filter( ( f ) => /^flyaffiliate-v.+\.zip$/.test( f ) )
			.sort()
			.pop();
		expect( name, 'a zip in build/' ).toBeTruthy();
		zip = join( ROOT, 'build', name as string );
	} );

	test( 'ships the plugin and nothing from development', () => {
		const entries = execFileSync( 'unzip', [ '-Z1', zip ], {
			encoding: 'utf8',
		} )
			.split( '\n' )
			.filter( Boolean );

		expect(
			entries.every( ( e ) => e.startsWith( 'flyaffiliate/' ) )
		).toBe( true );
		for ( const need of [
			'flyaffiliate/flyaffiliate.php',
			'flyaffiliate/uninstall.php',
			'flyaffiliate/readme.txt',
			'flyaffiliate/vendor/autoload.php',
			'flyaffiliate/assets/js/admin.js',
			'flyaffiliate/assets/js/dashboard.js',
		] ) {
			expect( entries, need ).toContain( need );
		}
		// composer.json/.lock and package.json ship on purpose (.distignore):
		// Plugin Check wants the manifest beside vendor/.
		const dev = entries.filter( ( e ) =>
			/^flyaffiliate\/(src|tests|node_modules|docs|bin|\.claude|\.github|\.wordpress-org)\/|^flyaffiliate\/(CLAUDE\.md|CONTEXT\.md|package-lock\.json|phpunit|phpcs|\.wp-env|tsconfig|playwright)/.test(
				e
			)
		);
		expect( dev ).toEqual( [] );
	} );

	test( 'uploads, activates and opens its admin with every asset loading', async ( {
		page,
	} ) => {
		await logIn( page, 'admin', 'password' );
		await page.goto( '/wp-admin/plugin-install.php?tab=upload' );
		await page.locator( '#pluginzip' ).setInputFiles( zip );
		await page.locator( '#install-plugin-submit' ).click();
		// Plugin Check leaves its staged copy on this site: WordPress then asks
		// whether to replace it, as it would for an owner updating by upload.
		const replace = page.getByRole( 'link', {
			name: /Replace current with uploaded/,
		} );
		const done = page.getByText(
			/Plugin (installed|updated) successfully/
		);
		await expect( replace.or( done ).first() ).toBeVisible( {
			timeout: 120 * 1000,
		} );
		if ( await replace.isVisible() ) {
			await replace.click();
			await expect( done ).toBeVisible( { timeout: 120 * 1000 } );
		}
		const activate = page.getByRole( 'link', { name: 'Activate Plugin' } );
		if ( await activate.isVisible() ) {
			await activate.click();
			await expect( page ).toHaveURL( /plugins\.php|page=flyaffiliate/ );
		}

		const broken: string[] = [];
		const errors: string[] = [];
		page.on( 'response', ( r ) => {
			if ( r.status() >= 400 && /flyaffiliate/.test( r.url() ) ) {
				broken.push( `${ r.status() } ${ r.url() }` );
			}
		} );
		page.on( 'console', ( m ) => {
			if ( 'error' === m.type() ) {
				errors.push( m.text() );
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=flyaffiliate' );
		const app = page.locator( '#flyaffiliate-admin-app' );
		await expect( app ).toBeVisible();
		await expect( app.getByRole( 'heading' ).first() ).toBeVisible();
		await page.waitForLoadState( 'networkidle' );

		expect( broken ).toEqual( [] );
		expect( errors ).toEqual( [] );
	} );
} );
