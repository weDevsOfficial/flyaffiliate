/**
 * Turns Playwright's JSON results (tests/pw/results.json, written on CI) into a
 * Markdown test report for the GitHub Actions run summary:
 *
 *   node tests/pw/ci-summary.mjs "E2E · WP latest" >> "$GITHUB_STEP_SUMMARY"
 *
 * Totals, every failed and flaky test with its first error line, the tests
 * pinned to a known bug (test.fail()), and a per-spec table.
 */
import { existsSync, readFileSync } from 'fs';
import { join } from 'path';

const title = process.argv[ 2 ] || 'E2E';
const file = join( import.meta.dirname, 'results.json' );

if ( ! existsSync( file ) ) {
	process.stdout.write(
		`## ${ title }\n\nNo test results: the suite did not run.\n`
	);
	process.exit( 0 );
}

const report = JSON.parse( readFileSync( file, 'utf8' ) );
// eslint-disable-next-line no-control-regex
const plain = ( text = '' ) => text.replace( /\u001b\[[0-9;]*m/g, '' );
const cell = ( text ) =>
	plain( text ).replace( /\|/g, '\\|' ).replace( /\s+/g, ' ' ).trim();

const rows = [];
( function walk( suites, parent ) {
	for ( const suite of suites || [] ) {
		const spec = suite.file || parent;
		for ( const s of suite.specs || [] ) {
			for ( const t of s.tests || [] ) {
				const last = t.results?.[ t.results.length - 1 ] || {};
				rows.push( {
					spec,
					title: s.title,
					line: s.line,
					status: t.status, // expected | unexpected | flaky | skipped
					pinned:
						'failed' === t.expectedStatus &&
						'expected' === t.status,
					error:
						last.error?.message ||
						last.errors?.[ 0 ]?.message ||
						'',
				} );
			}
		}
		walk( suite.suites, spec );
	}
} )( report.suites );

const count = ( status ) => rows.filter( ( r ) => r.status === status ).length;
const failed = rows.filter( ( r ) => 'unexpected' === r.status );
const flaky = rows.filter( ( r ) => 'flaky' === r.status );
const pinned = rows.filter( ( r ) => r.pinned );
const minutes = ( ( report.stats?.duration || 0 ) / 60000 ).toFixed( 1 );
const ok = 0 === failed.length;

const out = [];
out.push( `## ${ ok ? '✅' : '❌' } ${ title }` );
out.push( '' );
out.push( '| Passed | Failed | Flaky | Skipped | Known bugs pinned | Time |' );
out.push( '|---|---|---|---|---|---|' );
out.push(
	`| ${ count( 'expected' ) - pinned.length } | ${ failed.length } | ${
		flaky.length
	} | ${ count( 'skipped' ) } | ${ pinned.length } | ${ minutes } min |`
);
out.push( '' );

if ( failed.length ) {
	out.push( '### Failed' );
	out.push( '' );
	out.push( '| Test | Error |' );
	out.push( '|---|---|' );
	for ( const r of failed ) {
		const first =
			plain( r.error )
				.split( '\n' )
				.find( ( l ) => l.trim() ) || '';
		out.push(
			`| \`${ r.spec }:${ r.line }\` ${ cell( r.title ) } | ${ cell(
				first
			).slice( 0, 200 ) } |`
		);
	}
	out.push( '' );
}

if ( flaky.length ) {
	out.push( '### Flaky (passed on retry)' );
	out.push( '' );
	for ( const r of flaky ) {
		out.push( `- \`${ r.spec }:${ r.line }\` ${ cell( r.title ) }` );
	}
	out.push( '' );
}

if ( pinned.length ) {
	out.push(
		'<details><summary>Known bugs still reproducing (test.fail())</summary>'
	);
	out.push( '' );
	for ( const r of pinned ) {
		out.push( `- \`${ r.spec }:${ r.line }\` ${ cell( r.title ) }` );
	}
	out.push( '' );
	out.push( '</details>' );
	out.push( '' );
}

const specs = [ ...new Set( rows.map( ( r ) => r.spec ) ) ].sort();
out.push( '<details><summary>By spec</summary>' );
out.push( '' );
out.push( '| Spec | Passed | Failed | Flaky | Skipped |' );
out.push( '|---|---|---|---|---|' );
for ( const spec of specs ) {
	const mine = rows.filter( ( r ) => r.spec === spec );
	const n = ( status ) => mine.filter( ( r ) => r.status === status ).length;
	out.push(
		`| ${ spec } | ${ n( 'expected' ) } | ${ n( 'unexpected' ) } | ${ n(
			'flaky'
		) } | ${ n( 'skipped' ) } |`
	);
}
out.push( '' );
out.push( '</details>' );
out.push( '' );
out.push(
	'Full HTML report, traces and screenshots: the run’s **Artifacts**.'
);
out.push( '' );

process.stdout.write( out.join( '\n' ) + '\n' );
