/**
 * WP-CLI inside wp-env, for the state no screen or REST route sets up:
 * products, orders, order statuses, settings, users.
 *
 * PHP is shipped base64-encoded so no shell quoting ever touches it, and its
 * output is fenced so wp-env's own log lines never reach the caller.
 */
import { execFileSync } from 'child_process';
import { join } from 'path';

const ROOT = join( __dirname, '..', '..', '..' );
const OPEN = '<<<PW';
const CLOSE = 'PW>>>';

let container: string | null | undefined;

/**
 * This checkout's wp-env cli container, for a direct `docker exec` (about a
 * third of `wp-env run`'s start-up time). wp-env names containers after the
 * hash its install path ends in, so a worktree finds its own.
 *
 * `FLYAFFILIATE_WP_ENV_DIR` points at another wp-env checkout instead — the
 * Pro worktree whose paired environment runs the Dokan specs.
 */
function cliContainer(): string | null {
	if ( container !== undefined ) {
		return container;
	}

	try {
		const path = execFileSync( 'npx', [ 'wp-env', 'install-path' ], {
			cwd: process.env.FLYAFFILIATE_WP_ENV_DIR || ROOT,
			encoding: 'utf8',
		} ).trim();
		const name = `${ path.split( /[\\/]/ ).pop() }-cli-1`;
		execFileSync( 'docker', [ 'inspect', name ], { stdio: 'ignore' } );
		container = name;
	} catch {
		container = null;
	}

	return container;
}

/**
 * Run a WP-CLI command in the cli container.
 *
 * @param {string[]} args Arguments after `wp`.
 * @return {string} Raw output.
 */
export function wp( args: string[] ): string {
	const name = cliContainer();
	const [ command, argv ] = name
		? [
				'docker',
				[
					'exec',
					'-e',
					'WP_ENVIRONMENT_TYPE=development',
					name,
					'wp',
					...args,
				],
		  ]
		: [ 'npx', [ 'wp-env', 'run', 'cli', '--', 'wp', ...args ] ];

	return execFileSync( command, argv, {
		cwd: process.env.FLYAFFILIATE_WP_ENV_DIR || ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { ...process.env, WP_ENVIRONMENT_TYPE: 'development' },
	} );
}

/**
 * Evaluate PHP with WordPress loaded and return what it `return`s, JSON-decoded.
 *
 * @param {string} body Body of a closure; end it with `return …;`.
 * @return {unknown} The decoded value.
 */
export function php< T = unknown >( body: string ): T {
	const code = `$fn = function () { ${ body } }; echo '${ OPEN }' . wp_json_encode( $fn() ) . '${ CLOSE }';`;
	const encoded = Buffer.from( code ).toString( 'base64' );
	const out = wp( [ 'eval', `eval( base64_decode( '${ encoded }' ) );` ] );
	const start = out.indexOf( OPEN );
	const end = out.indexOf( CLOSE, start );

	if ( start < 0 || end < 0 ) {
		throw new Error( `PHP returned nothing:\n${ out }` );
	}

	return JSON.parse( out.slice( start + OPEN.length, end ) ) as T;
}

/**
 * A PHP literal for a JS value: numbers as-is, strings single-quoted.
 *
 * @param {string|number} value The value.
 * @return {string} PHP source.
 */
export const lit = ( value: string | number ): string =>
	typeof value === 'number'
		? String( value )
		: `'${ value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
